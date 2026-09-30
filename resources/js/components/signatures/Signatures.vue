<script setup lang="ts">
import PasteIcon from '@/components/icons/PasteIcon.vue';
import PlusIcon from '@/components/icons/PlusIcon.vue';
import TrashIcon from '@/components/icons/TrashIcon.vue';
import PasteSignatureWarningDialog from '@/components/signatures/PasteSignatureWarningDialog.vue';
import ReturnHoleDialog from '@/components/signatures/ReturnHoleDialog.vue';
import Signature from '@/components/signatures/Signature.vue';
import SignaturesEmptyState from '@/components/signatures/SignaturesEmptyState.vue';
import MapPanel from '@/components/ui/map-panel/MapPanel.vue';
import MapPanelContent from '@/components/ui/map-panel/MapPanelContent.vue';
import MapPanelHeader from '@/components/ui/map-panel/MapPanelHeader.vue';
import MapPanelHeaderActionButton from '@/components/ui/map-panel/MapPanelHeaderActionButton.vue';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useCombat } from '@/composables/combat/useCombat';
import { recentJump } from '@/composables/signatures/recentJump';
import { usePasteSignatures } from '@/composables/signatures/usePasteSignatures';
import { useSignatures } from '@/composables/signatures/useSignatures';
import { useSortableSignatures } from '@/composables/signatures/useSortedSignatures';
import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { useShowMap } from '@/composables/useShowMap';
import usePermission from '@/composables/usePermission';
import { signatureCategories } from '@/const/signatures';
import { suggestAlias } from '@/lib/alias';
import { isWormholeSignature, planAliasesForSystem } from '@/lib/aliasPlan';
import { formatBookmarkName, visibleBookmarkName } from '@/lib/bookmark';
import { chainAliases } from '@/lib/combat';
import { AUTO_LINK_WINDOW_MS, decideReturnHole, orderOpenConnections, type TReturnConnectionOption, type TReturnHoleOption, type TScanDistance } from '@/lib/returnHole';
import type { TRawSignature } from '@/lib/SignatureParser';
import { aliasedSolarsystemLabel } from '@/lib/solarsystem';
import { createSignature, TProcessedConnection, updateMapUserSettings, updateSignature, useMapSolarsystems } from '@/map/api';
import type { TResolvedSelectedMapSolarsystem } from '@/pages/maps';
import type { TSignature } from '@/types/models';
import { useLocalStorage, useNow } from '@vueuse/core';
import { ArrowDown, ArrowUp, CircleHelp, Cloud, Database, Fan, Flag, Gem, Landmark, Rows2, Rows3, Shield, Swords } from 'lucide-vue-next';
import { type Component, computed, nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';

const props = defineProps<{
    map_solarsystem: TResolvedSelectedMapSolarsystem | null;
}>();

const { connections } = useSignatures();

const { canEdit: can_write } = usePermission();

const character = useActiveMapCharacter();

const map_user_settings = useMapUserSettings();

const page = useShowMap();

const { popup_seconds } = useCombat();

function toggleCompactSignatureList() {
    updateMapUserSettings(page.props.map.slug, {
        compact_signature_list: !map_user_settings.value.compact_signature_list,
    });
}

const {
    signatures,
    pasted_signatures,
    deleted_signatures,
    deleteMissingSignatures,
    pasteSignatures,
    show_system_mismatch_warning,
    confirmPasteInDifferentSystem,
    cancelPaste,
} = usePasteSignatures(() => props.map_solarsystem, handlePasted);

const { map_solarsystems } = useMapSolarsystems();

// Reserve a chain alias for every unjumped wormhole in this system (statics
// first), so e.g. the static suggests " 1" and the next hole " 2".
// This system as the map knows it (combat chain color, combat home).
const map_system = computed(() => map_solarsystems.value.find((solarsystem) => solarsystem.id === props.map_solarsystem?.id) ?? null);

// Numbers already used in this system's chain (each combat chain numbers from 1 on its own).
const chain_aliases = computed(() => chainAliases(map_solarsystems.value, map_system.value));

// Red rows (missing from the last paste: ignored in game, or gone) are
// included, so the numbers they hold stay reserved until they are deleted.
const planned_aliases = computed(() =>
    planAliasesForSystem({
        signatures: signatures.value,
        system: props.map_solarsystem
            ? {
                  alias: props.map_solarsystem.alias,
                  solarsystem: props.map_solarsystem.solarsystem,
                  combat_home: map_system.value?.combat_home ?? false,
                  combat_color: map_system.value?.combat_color ?? null,
              }
            : null,
        aliases: chain_aliases.value,
        formats: page.props.map,
    }),
);

// Who holds each number in this system, for hand-set numbers ("already used by …").
const number_owners = computed(() => {
    const owners = new Map<string, { signatureId: number | null; label: string }>();
    for (const alias of chain_aliases.value) {
        owners.set(alias.toUpperCase(), { signatureId: null, label: `system ${alias} on the map` });
    }
    for (const signature of signatures.value) {
        const alias = planned_aliases.value.get(signature.id) ?? signature.alias ?? null;
        if (alias) owners.set(alias.toUpperCase(), { signatureId: signature.id, label: signature.signature_id ?? 'another signature' });
    }
    return owners;
});

// The one hole in this system marked as the static, if any (red rows included).
const static_owner_id = computed(() => signatures.value.find((signature) => signature.is_static)?.id ?? null);

// Combat chains number holes in the order they are claimed (copied or jumped):
// the next free number in this system, for a hole still in "limbo".
const claim_alias = computed<string | null>(() => {
    const system = props.map_solarsystem;
    if (!system || !map_system.value?.combat_color) return null;
    return suggestAlias({
        parentAlias: system.alias,
        targetIsWormhole: true,
        originIsWormhole: true,
        aliases: [...chain_aliases.value, ...number_owners.value.keys()],
        scheme: page.props.map.bookmark_alias_scheme,
        ignoredAlias: page.props.map.bookmark_ignored_alias,
        combatHome: Boolean(map_system.value.combat_home),
    });
});

// ---- Return hole after a paste ---------------------------------------------
// After a jump the connection back has no signature on this side yet. When a
// scan is pasted here, link the hole we came through: automatically within
// 80 s of our own jump when exactly one wormhole is on grid, otherwise ask.

type TReturnCandidate = { id: number; distance: TScanDistance | null; signature: TSignature };

const dismissed_connections = new Set<number>();
const return_dialog_open = ref(false);
const return_options = ref<TReturnHoleOption[]>([]);
const return_preselect = ref<number | null>(null);
const return_connections = ref<TReturnConnectionOption[]>([]);
let pending_return: { candidates: TReturnCandidate[]; connections: TProcessedConnection[] } | null = null;

async function handlePasted(pasted: TRawSignature[]): Promise<void> {
    // Let the fresh signature list reach this component first.
    await nextTick();

    const system = props.map_solarsystem;
    if (!system) return;

    const open = connections.value.filter(
        (connection) =>
            connection.type !== 'stargate' &&
            !dismissed_connections.has(connection.id) &&
            !(connection.signatures ?? []).some((signature) => signature.map_solarsystem_id === system.id),
    );
    if (open.length === 0) return;

    const jump = recentJump.value && recentJump.value.toSolarsystemId === system.solarsystem_id ? recentJump.value : null;

    const ordered = orderOpenConnections(
        open.map((connection) => ({ ...connection, otherSolarsystemId: connection.target.solarsystem_id, createdAt: connection.created_at })),
        jump?.fromSolarsystemId ?? null,
    );

    const distances = new Map(pasted.map((raw) => [raw.signature_id, raw.distance ?? null]));
    const candidates: TReturnCandidate[] = system.signatures
        .filter((signature) => {
            if (signature.map_connection_id || !signature.signature_id || !distances.has(signature.signature_id)) return false;
            if (isWormholeSignature(signature)) return true;
            // Not categorised yet but sitting on grid: still a candidate.
            return !signature.signature_category_id && Boolean(distances.get(signature.signature_id)?.onGrid);
        })
        .map((signature) => ({ id: signature.id, distance: distances.get(signature.signature_id ?? '') ?? null, signature }));

    // A paste of just one signature after your jump into this system can only be the hole you came through.
    if (pasted.length === 1 && jump) {
        const only = system.signatures.find((signature) => signature.signature_id === pasted[0].signature_id);
        if (only && !only.map_connection_id && (isWormholeSignature(only) || !only.signature_category_id)) {
            linkReturnHole(only, ordered[0], true);
            return;
        }
    }

    const decision = decideReturnHole({ candidates, jumpedAt: jump?.at ?? null, now: Date.now() });
    if (decision.mode === 'none') return;

    if (decision.mode === 'auto') {
        const candidate = candidates.find((entry) => entry.id === decision.candidateId);
        if (candidate) linkReturnHole(candidate.signature, ordered[0], true);
        return;
    }

    pending_return = { candidates, connections: ordered };
    return_options.value = decision.ordered.map((id) => {
        const candidate = candidates.find((entry) => entry.id === id)!;
        return {
            id,
            signatureId: candidate.signature.signature_id ?? '???',
            typeLabel: candidate.signature.signature_type?.name ?? (isWormholeSignature(candidate.signature) ? 'Wormhole' : 'Unknown'),
            distanceText: candidate.distance?.text ?? null,
            onGrid: Boolean(candidate.distance?.onGrid),
        };
    });
    return_preselect.value = decision.preselectId;
    return_connections.value = ordered.map((connection) => ({
        id: connection.id,
        label: `Back to ${aliasedSolarsystemLabel(connection.target.alias, connection.target.solarsystem.name)}`,
    }));
    return_dialog_open.value = true;
}

/** Link the return hole, copy its return bookmark, and offer Undo. */
function linkReturnHole(signature: TSignature, connection: TProcessedConnection, automatic: boolean): void {
    const system = props.map_solarsystem;
    if (!system) return;

    const previousTypeId = signature.signature_type_id;
    // A hole not categorised yet becomes a wormhole when it is linked.
    const wormholeCategoryId = signatureCategories.find((category) => category.code === 'wormhole')?.id ?? null;
    updateSignature(signature, {
        map_connection_id: connection.id,
        ...(!signature.signature_category_id && wormholeCategoryId !== null ? { signature_category_id: wormholeCategoryId } : {}),
    });

    const name = formatBookmarkName(
        connection.target,
        {
            signatureId: signature.signature_id,
            shipSize: connection.ship_size,
            massStatus: connection.mass_status,
            lifetime: connection.lifetime_status,
        },
        page.props.map,
        system.alias,
        system.alias,
        system.solarsystem.class,
        map_system.value?.combat_color ?? null,
    );
    if (name) navigator.clipboard.writeText(name).catch(() => undefined);

    toast.success(automatic ? `Linked ${signature.signature_id} as your return hole` : `Return hole ${signature.signature_id} linked`, {
        description: name ? `Copied ${visibleBookmarkName(name)}` : undefined,
        action: {
            label: 'Undo',
            onClick: () => updateSignature(signature, { map_connection_id: null, ...(previousTypeId === null ? { signature_type_id: null } : {}) }),
        },
    });
}

function handleReturnConfirm(selection: { signatureId: number; connectionId: number }): void {
    const candidate = pending_return?.candidates.find((entry) => entry.id === selection.signatureId);
    const connection = pending_return?.connections.find((entry) => entry.id === selection.connectionId);
    if (candidate && connection) linkReturnHole(candidate.signature, connection, false);
    pending_return = null;
}

// Seconds left to paste a scan and have the return hole linked automatically:
// shown while you are in the system you just jumped into and its connection
// back still has no signature on this side.
const now = useNow({ interval: 1000 });
const auto_link_seconds = computed(() => {
    const system = props.map_solarsystem;
    const jump = recentJump.value;
    if (!system || !jump || jump.toSolarsystemId !== system.solarsystem_id) return 0;

    const waiting = connections.value.some(
        (connection) =>
            connection.type !== 'stargate' &&
            !dismissed_connections.has(connection.id) &&
            !(connection.signatures ?? []).some((signature) => signature.map_solarsystem_id === system.id),
    );
    if (!waiting) return 0;

    return Math.max(0, Math.ceil((AUTO_LINK_WINDOW_MS - (now.value.getTime() - jump.at)) / 1000));
});

function handleReturnSkip(connectionIds: number[]): void {
    for (const id of connectionIds) dismissed_connections.add(id);
    pending_return = null;
}

const UNCATEGORIZED_FILTER = '__uncategorized__';

const categoryFilterOptions: Array<{ value: string; icon: Component; color: string; label: string }> = [
    { value: 'Wormhole', icon: Fan, color: 'text-sky-400', label: 'Wormhole' },
    { value: 'Data Site', icon: Database, color: 'text-cyan-400', label: 'Data Site' },
    { value: 'Relic Site', icon: Landmark, color: 'text-amber-400', label: 'Relic Site' },
    { value: 'Ore Site', icon: Gem, color: 'text-yellow-400', label: 'Ore Site' },
    { value: 'Gas Site', icon: Cloud, color: 'text-orange-400', label: 'Gas Site' },
    { value: 'Combat Site', icon: Swords, color: 'text-green-400', label: 'Combat Site' },
    { value: 'Homefront Operations', icon: Shield, color: 'text-rose-400', label: 'Homefront Operations' },
    { value: 'Factional Warfare Site', icon: Flag, color: 'text-fuchsia-400', label: 'Factional Warfare Site' },
    { value: UNCATEGORIZED_FILTER, icon: CircleHelp, color: 'text-muted-foreground', label: 'Uncategorized' },
];

// Persist the hidden categories instead of the visible ones so categories added
// in later releases default to visible for users with saved filters.
const hiddenCategoryFilters = useLocalStorage<string[]>('signatures-category-hidden-filters', []);

const activeCategoryFilters = computed<string[]>({
    get: () => categoryFilterOptions.map((option) => option.value).filter((value) => !hiddenCategoryFilters.value.includes(value)),
    set: (values) => {
        hiddenCategoryFilters.value = categoryFilterOptions.map((option) => option.value).filter((value) => !values.includes(value));
    },
});

const filteredSignatures = computed(() =>
    signatures.value.filter((signature) => {
        const key = signature.signature_category?.name ?? UNCATEGORIZED_FILTER;
        return !hiddenCategoryFilters.value.includes(key);
    }),
);

const hiddenSignaturesCount = computed(() => signatures.value.length - filteredSignatures.value.length);

const { sortPreferences, sorted, updateSortPreferences } = useSortableSignatures(filteredSignatures);

const connected_connections = computed(() => {
    return connections.value.filter((connection) => {
        return signatures.value.some((signature) => {
            return signature.map_connection_id === connection.id;
        });
    });
});

const unconnected_connections = computed(() => {
    return connections.value.filter((connection) => {
        return !signatures.value.some((signature) => {
            return signature.map_connection_id === connection.id;
        });
    });
});

function handleSort(column: 'id' | 'category' | 'type' | 'age') {
    let newDirection: 'asc' | 'desc';

    if (sortPreferences.value.column === column) {
        newDirection = sortPreferences.value.direction === 'asc' ? 'desc' : 'asc';
    } else {
        newDirection = 'asc';
    }

    updateSortPreferences(column, newDirection);
}

function createNewSignature() {
    if (!props.map_solarsystem) return;
    createSignature(props.map_solarsystem.id);
}
</script>

<template>
    <!-- Empty state when no system selected -->
    <SignaturesEmptyState v-if="!map_solarsystem" />

    <!-- Signatures list when system is selected -->
    <MapPanel v-if="map_solarsystem" class="overflow-x-hidden">
        <MapPanelHeader>
            Signatures
            <span v-if="filteredSignatures.length" class="ml-1 text-amber-400">{{ filteredSignatures.length }}</span>
            <span v-if="hiddenSignaturesCount > 0" class="ml-1 text-muted-foreground/70">{{ hiddenSignaturesCount }} hidden</span>
            <template #actions>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <MapPanelHeaderActionButton size="icon" @click="toggleCompactSignatureList">
                            <Rows2 v-if="map_user_settings.compact_signature_list" class="size-3.5" />
                            <Rows3 v-else class="size-3.5" />
                        </MapPanelHeaderActionButton>
                    </TooltipTrigger>
                    <TooltipContent>
                        {{ map_user_settings.compact_signature_list ? 'Switch to comfortable signature list' : 'Switch to compact signature list' }}
                    </TooltipContent>
                </Tooltip>
                <ToggleGroup v-model="activeCategoryFilters" type="multiple" size="sm" variant="outline">
                    <Tooltip v-for="option in categoryFilterOptions" :key="option.value">
                        <TooltipTrigger as-child>
                            <ToggleGroupItem :value="option.value" :aria-label="option.label">
                                <component :is="option.icon" class="mx-1 size-3" :class="option.color" />
                            </ToggleGroupItem>
                        </TooltipTrigger>
                        <TooltipContent>{{ option.label }}</TooltipContent>
                    </Tooltip>
                </ToggleGroup>
                <template v-if="can_write">
                    <Tooltip v-if="pasted_signatures">
                        <TooltipTrigger as-child>
                            <MapPanelHeaderActionButton v-if="pasted_signatures" @click="pasted_signatures = null">
                                Unselect
                            </MapPanelHeaderActionButton>
                        </TooltipTrigger>
                        <TooltipContent> Unselect signatures</TooltipContent>
                    </Tooltip>
                    <Tooltip v-if="deleted_signatures.length > 0">
                        <TooltipTrigger as-child>
                            <MapPanelHeaderActionButton @click="deleteMissingSignatures(true)" variant="destructive" size="icon">
                                <TrashIcon />
                            </MapPanelHeaderActionButton>
                        </TooltipTrigger>
                        <TooltipContent>
                            Delete missing (red) signatures and their connections. Careful: signatures you ignore in game show red too.
                        </TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <MapPanelHeaderActionButton @click="pasteSignatures" size="icon">
                                <PasteIcon />
                            </MapPanelHeaderActionButton>
                        </TooltipTrigger>
                        <TooltipContent> Paste signatures from clipboard (Ctrl/Cmd + V)</TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <MapPanelHeaderActionButton @click="createNewSignature" size="icon">
                                <PlusIcon />
                            </MapPanelHeaderActionButton>
                        </TooltipTrigger>
                        <TooltipContent> Create new signature</TooltipContent>
                    </Tooltip>
                </template>
            </template>
        </MapPanelHeader>
        <MapPanelContent>
            <!-- Header -->
            <div
                class="flex items-center gap-2 border-b border-border/30 bg-muted/20 px-3 font-mono text-[10px] tracking-wider text-muted-foreground uppercase"
                :class="map_user_settings.compact_signature_list ? 'py-0.5' : 'py-1.5'"
            >
                <button class="flex w-16 shrink-0 items-center gap-1 hover:text-foreground" @click="handleSort('id')">
                    <span>ID</span>
                    <ArrowUp v-if="sortPreferences.column === 'id' && sortPreferences.direction === 'asc'" class="size-3" />
                    <ArrowDown v-if="sortPreferences.column === 'id' && sortPreferences.direction === 'desc'" class="size-3" />
                </button>
                <button class="flex w-24 shrink-0 items-center gap-1 hover:text-foreground" @click="handleSort('category')">
                    <span>Cat</span>
                    <ArrowUp v-if="sortPreferences.column === 'category' && sortPreferences.direction === 'asc'" class="size-3" />
                    <ArrowDown v-if="sortPreferences.column === 'category' && sortPreferences.direction === 'desc'" class="size-3" />
                </button>
                <button class="flex min-w-0 flex-1 items-center gap-1 hover:text-foreground" @click="handleSort('type')">
                    <span>Type</span>
                    <ArrowUp v-if="sortPreferences.column === 'type' && sortPreferences.direction === 'asc'" class="size-3" />
                    <ArrowDown v-if="sortPreferences.column === 'type' && sortPreferences.direction === 'desc'" class="size-3" />
                </button>
                <span class="min-w-0 flex-1">Conn</span>
                <span class="w-16 shrink-0">Name</span>
                <span class="w-14 shrink-0"></span>
                <button class="flex w-10 shrink-0 items-center justify-end gap-1 hover:text-foreground" @click="handleSort('age')">
                    <span>Age</span>
                    <ArrowUp v-if="sortPreferences.column === 'age' && sortPreferences.direction === 'asc'" class="size-3" />
                    <ArrowDown v-if="sortPreferences.column === 'age' && sortPreferences.direction === 'desc'" class="size-3" />
                </button>
            </div>

            <!-- Auto-link countdown -->
            <div
                v-if="auto_link_seconds > 0"
                class="flex items-center justify-between border-b border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs text-emerald-300"
            >
                <span>Paste your scan to auto-link the return hole</span>
                <span class="font-mono font-medium">{{ auto_link_seconds }}s</span>
            </div>

            <!-- Signature rows -->
            <template v-if="sorted.length">
                <Signature
                    v-for="signature in sorted"
                    :signature="signature"
                    :key="signature.id"
                    :is_deleted="signature.deleted"
                    :is_new="signature.new"
                    :is_updated="signature.updated"
                    :unconnected_connections="unconnected_connections"
                    :connected_connections="connected_connections"
                    :selected_map_solarsystem="map_solarsystem"
                    :planned_alias="planned_aliases.get(signature.id) ?? null"
                    :claim_alias="claim_alias"
                    :number_owners="number_owners"
                    :static_owner_id="static_owner_id"
                />
            </template>
            <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-4">
                <p class="font-mono text-[10px] tracking-wider text-muted-foreground/60 uppercase">
                    {{ hiddenSignaturesCount > 0 ? `${hiddenSignaturesCount} hidden by filters` : 'No signatures' }}
                </p>
            </div>
        </MapPanelContent>
        <ReturnHoleDialog
            v-model:open="return_dialog_open"
            :options="return_options"
            :preselect-id="return_preselect"
            :connections="return_connections"
            :countdown-seconds="popup_seconds"
            @confirm="handleReturnConfirm"
            @skip="handleReturnSkip"
        />
    </MapPanel>

    <!-- Warning dialog for pasting in different system -->
    <PasteSignatureWarningDialog
        v-model:open="show_system_mismatch_warning"
        :target-system="map_solarsystem"
        :character="character"
        @confirm="confirmPasteInDifferentSystem"
        @cancel="cancelPaste"
    />
</template>
