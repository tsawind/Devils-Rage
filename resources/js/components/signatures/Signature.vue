<script setup lang="ts">
import TrashIcon from '@/components/icons/TrashIcon.vue';
import MapConnectionInput from '@/components/signatures/MapConnectionInput.vue';
import SignatureTimeDetails from '@/components/signatures/SignatureTimeDetails.vue';
import SignatureTypeInput from '@/components/signatures/SignatureTypeInput.vue';
import WormholeTypeInput from '@/components/signatures/WormholeTypeInput.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import CountdownBar from '@/components/combat/CountdownBar.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useCombat } from '@/composables/combat/useCombat';
import { usePopupCountdown } from '@/composables/combat/usePopupCountdown';
import { useMapUserSettings } from '@/composables/useMapUserSettings';
import usePermission from '@/composables/usePermission';
import { useShowMap } from '@/composables/useShowMap';
import { getTypesByCategory, signatureCategories } from '@/const/signatures';
import { classSortWeight } from '@/const/solarsystemClasses';
import { aliasForSlot, isIgnoredAlias, staticSlotAlias } from '@/lib/alias';
import { buildSignatureBookmark, visibleBookmarkName } from '@/lib/bookmark';
import { chainAliases } from '@/lib/combat';
import { isK162, validateManualAlias } from '@/lib/chainNumbering';
import { Data } from '@/lib/data';
import { formatDateToISO } from '@/lib/utils';
import { deleteSignature, TProcessedConnection, updateMapConnection, updateSignature, useMapSolarsystems } from '@/map/api';
import type { TResolvedSelectedMapSolarsystem } from '@/pages/maps';
import { TSignature } from '@/types/models';
import { UTCDate } from '@date-fns/utc';
import type { FormDataConvertible } from '@inertiajs/core';
import { syncRefs } from '@vueuse/core';
import { Check, Cloud, Copy, Database, Fan, Flag, Gem, Heart, Landmark, MoreVertical, Shield, Swords } from 'lucide-vue-next';
import { AcceptableValue } from 'reka-ui';
import { type Component, computed, nextTick, ref, toRef } from 'vue';
import { toast } from 'vue-sonner';

const { signature, unconnected_connections, connected_connections, selected_map_solarsystem, planned_alias, number_owners, static_owner_id } = defineProps<{
    signature: TSignature;
    is_deleted?: boolean;
    is_new?: boolean;
    is_updated?: boolean;
    unconnected_connections: TProcessedConnection[];
    connected_connections: TProcessedConnection[];
    selected_map_solarsystem: TResolvedSelectedMapSolarsystem;
    /** The chain alias reserved for this hole among the system's unjumped wormholes. */
    planned_alias?: string | null;
    /** Who holds each number in this system (alias → owner), for hand-set numbers. */
    number_owners?: Map<string, { signatureId: number | null; label: string }>;
    /** The signature marked as this system's static, if any. */
    static_owner_id?: number | null;
}>();

const original = toRef(() => signature.signature_id || '');
const signature_id = ref('');
syncRefs(original, signature_id);

const { canEdit: can_write } = usePermission();

// Inline editing state
const editingId = ref(false);
const idInputRef = ref<HTMLInputElement | null>(null);

const selected_connection = computed(() => {
    return (
        unconnected_connections.find((c) => c.id === signature.map_connection_id) ??
        connected_connections.find((c) => c.id === signature.map_connection_id) ??
        null
    );
});

const solarsystem_class = computed(() => selected_map_solarsystem.solarsystem.class);

const availableTypes = computed(() => {
    if (!signature.signature_category_id) return [];
    return getTypesByCategory(signature.signature_category_id).filter((type) => type.spawn_areas?.includes(solarsystem_class.value));
});

const sortedAvailableTypes = computed(() => {
    return availableTypes.value.toSorted((a, b) => classSortWeight(a.target_class) - classSortWeight(b.target_class));
});

const wormholeCategoryId = computed(() => {
    return signatureCategories.find((cat) => cat.name === 'Wormhole')?.id;
});

const isWormhole = computed(() => {
    return signature.signature_category_id === wormholeCategoryId.value;
});

const current_class = computed(() => {
    if (!selected_connection.value?.target) return null;
    return selected_connection.value.target.solarsystem.class;
});

const map_user_settings = useMapUserSettings();

const static_signatures = computed<string[]>(() => {
    if (!map_user_settings.value.show_statics_first) {
        return [];
    }
    return (selected_map_solarsystem.solarsystem.statics ?? []).map((wormhole_static) => wormhole_static.name);
});

const categoryAbbrev: Record<string, string> = {
    Wormhole: 'WH',
    'Data Site': 'Data',
    'Relic Site': 'Relic',
    'Ore Site': 'Ore',
    'Gas Site': 'Gas',
    'Combat Site': 'Combat',
    'Homefront Operations': 'HF',
    'Factional Warfare Site': 'FW',
};

const categoryIcon: Record<string, Component> = {
    Wormhole: Fan,
    'Data Site': Database,
    'Relic Site': Landmark,
    'Ore Site': Gem,
    'Gas Site': Cloud,
    'Combat Site': Swords,
    'Homefront Operations': Shield,
    'Factional Warfare Site': Flag,
};

const categoryColor: Record<string, string> = {
    Wormhole: 'text-sky-400',
    'Data Site': 'text-cyan-400',
    'Relic Site': 'text-amber-400',
    'Combat Site': 'text-green-400',
    'Gas Site': 'text-orange-400',
    'Ore Site': 'text-yellow-400',
    'Homefront Operations': 'text-rose-400',
    'Factional Warfare Site': 'text-fuchsia-400',
};

function getCategoryAbbrev(name?: string | null): string {
    return name ? (categoryAbbrev[name] ?? name) : '—';
}

function handleChange(data: Record<string, FormDataConvertible>) {
    updateSignature(signature, data);
}

function handleDelete() {
    deleteSignature(signature);
}

function handleCategoryChange(value: AcceptableValue) {
    handleChange({
        signature_category_id: value as number,
        signature_type_id: null,
        map_connection_id: null,
    });
}

function handleTypeChange(value: AcceptableValue) {
    const typeId = value as number | null;
    const wormholeName = typeId ? (availableTypes.value.find((type) => type.id === typeId)?.signature ?? null) : null;

    if (!isWormhole.value) {
        handleChange({ signature_type_id: typeId });
        return;
    }

    // A type that is one of this system's statics could be the static or a
    // wandering hole of the same type: ask. Any other type can be neither.
    const staticNames = (selected_map_solarsystem.solarsystem.statics ?? []).map((wormholeStatic) => wormholeStatic.name.trim().toUpperCase());
    const isStaticType = wormholeName !== null && !isK162(wormholeName) && staticNames.includes(wormholeName.trim().toUpperCase());

    if (isStaticType) {
        pending_type_id.value = typeId;
        pending_type_name.value = wormholeName;
        static_choice_open.value = true;
        return;
    }

    const clearFlags = signature.is_static || signature.is_wandering ? flagChanges(false, false) : {};
    handleChange({ signature_type_id: typeId, ...clearFlags });
}

// ---- "Static, wandering or unknown?" when a static type is picked ---------

const static_choice_open = ref(false);
const pending_type_id = ref<number | null>(null);
const pending_type_name = ref<string | null>(null);

function chooseStaticKind(kind: 'unknown' | 'static' | 'wandering'): void {
    if (!static_choice_open.value) return;
    static_choice_open.value = false;

    if (kind === 'static' && static_taken_by_other.value) kind = 'unknown';

    handleChange({ signature_type_id: pending_type_id.value, ...flagChanges(kind === 'static', kind === 'wandering') });
}

function handleStaticChoiceOpenChange(isOpen: boolean): void {
    // Closing the popup without choosing keeps the type and counts as Unknown.
    if (!isOpen) chooseStaticKind('unknown');
}

// Combat mode: no answer within 60 s counts as Unknown.
const { popup_seconds } = useCombat();
const { remaining: static_choice_remaining, fraction: static_choice_fraction } = usePopupCountdown(
    static_choice_open,
    () => popup_seconds.value,
    () => chooseStaticKind('unknown'),
);

// ---- Chain numbering: Static / Wandering / hand-set number ----------------

const is_k162 = computed(() => isK162(signature.wormhole?.name));

// This system as the map knows it: a combat home numbers its holes 1, 2, 3 (static 0).
const map_system = computed(() => map_solarsystems.value.find((solarsystem) => solarsystem.id === selected_map_solarsystem.id) ?? null);
const is_combat_home = computed(() => Boolean(map_system.value?.combat_home));

const static_slot = computed(() => staticSlotAlias(selected_map_solarsystem.alias, page.props.map.bookmark_ignored_alias, is_combat_home.value));
const static_taken_by_other = computed(() => static_owner_id != null && static_owner_id !== signature.id);

/**
 * The fields to send when Static / Wandering change. A hole that becomes the
 * static moves to the static slot (A in home, 0 elsewhere) if it already had
 * a locked number; a hole that stops being the static gives the slot back and
 * gets a normal number again.
 */
function flagChanges(isStatic: boolean, isWandering: boolean): Record<string, FormDataConvertible> {
    const changes: Record<string, FormDataConvertible> = { is_static: isStatic, is_wandering: isWandering };

    if (isStatic && signature.alias && signature.alias !== static_slot.value) {
        changes.alias = static_slot.value;
    }
    if (!isStatic && signature.alias === static_slot.value) {
        changes.alias = null;
    }

    return changes;
}

function handleToggleStatic() {
    if (is_k162.value) return;
    if (!signature.is_static && static_taken_by_other.value) {
        toast.error('Another signature in this system is already the static.');
        return;
    }
    handleChange(flagChanges(!signature.is_static, false));
}

function handleToggleWandering() {
    if (is_k162.value) return;
    handleChange(flagChanges(false, !signature.is_wandering));
}

function handleSetNumber() {
    const current = signature.alias ?? planned_alias ?? '';
    const allowed =
        !is_combat_home.value && isIgnoredAlias(selected_map_solarsystem.alias, page.props.map.bookmark_ignored_alias)
            ? 'A (static), B, D, G, J, K, M, N, P … Z'
            : '0 (static), 1-9 or A-Z';
    const input = window.prompt(`Number for ${signature.signature_id ?? 'this signature'} — enter one slot: ${allowed} (current: ${current || 'none'})`);
    if (input === null) return;

    const alias = aliasForSlot(selected_map_solarsystem.alias, input, page.props.map.bookmark_ignored_alias, is_combat_home.value);

    // Numbers held by this signature or by the system it already leads to aren't clashes.
    const others = new Map<string, string>();
    for (const [takenAlias, owner] of number_owners ?? []) {
        if (owner.signatureId === signature.id) continue;
        if (selected_connection.value?.target.alias?.toUpperCase() === takenAlias) continue;
        others.set(takenAlias, owner.label);
    }

    const result = validateManualAlias(alias, others);
    if (!result.ok) {
        toast.error(result.error);
        return;
    }

    const makesStatic = result.alias === static_slot.value;
    if (makesStatic && is_k162.value) {
        toast.error(`${static_slot.value} is the static's slot, and a K162 can never be the static.`);
        return;
    }
    if (makesStatic && static_taken_by_other.value) {
        toast.error(`${static_slot.value} is the static's slot, and another signature in this system is already the static.`);
        return;
    }

    handleChange({
        alias: result.alias,
        is_static: makesStatic,
        ...(makesStatic ? { is_wandering: false } : {}),
    });
}

function handleMapConnectionChange(value: AcceptableValue) {
    handleChange({ map_connection_id: value as number | null });
}

function startEditId() {
    if (!can_write.value) return;
    signature_id.value = signature.signature_id || '';
    editingId.value = true;
    nextTick(() => {
        idInputRef.value?.focus();
        idInputRef.value?.select();
    });
}

function handleIdInput(event: Event) {
    const target = event.target as HTMLInputElement;
    let value = target.value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
    if (value.length >= 4) {
        value = value.slice(0, 3) + '-' + value.slice(3, 6);
    }
    signature_id.value = value;
}

function saveId() {
    const newId = signature_id.value.trim();
    if (newId !== signature.signature_id) {
        handleChange({ signature_id: newId || null });
    }
    editingId.value = false;
}

function cancelEditId() {
    editingId.value = false;
    signature_id.value = signature.signature_id || '';
}

function handleLifetimeChange(lifetime: AcceptableValue) {
    handleChange({
        lifetime: lifetime as string,
        lifetime_updated_at: formatDateToISO(new UTCDate()),
    });
}

function handleMassStatusChange(mass_status: AcceptableValue) {
    const value = mass_status === 'unknown' ? null : mass_status;
    handleChange({ mass_status: value as string | null });
}

function handleTogglePreserveMass() {
    if (!selected_connection.value) return;
    updateMapConnection(selected_connection.value, { preserve_mass: !selected_connection.value.preserve_mass });
}

const page = useShowMap();
const { map_solarsystems } = useMapSolarsystems();

// The destination bookmark for this hole: the real connection target when one
// is set, otherwise the auto-suggested next chain alias.
const bookmark_name = computed(() =>
    buildSignatureBookmark({
        signature,
        currentSystem: { alias: selected_map_solarsystem.alias, class: selected_map_solarsystem.solarsystem.class, combatHome: is_combat_home.value, combatColor: map_system.value?.combat_color ?? null },
        connectionTarget: selected_connection.value?.target ?? null,
        aliases: chainAliases(map_solarsystems.value, map_system.value),
        formats: page.props.map,
        detectReturn: true,
        plannedAlias: planned_alias,
    }),
);

function copyBookmark() {
    navigator.clipboard.writeText(bookmark_name.value);

    // Copying an unjumped hole's bookmark locks its number, so it never shifts
    // under a bookmark someone has saved in game.
    if (isWormhole.value && !signature.alias && !signature.map_connection_id && planned_alias && can_write.value) {
        handleChange({ alias: planned_alias });
    }

    toast.success('Copied bookmark to clipboard', { description: visibleBookmarkName(bookmark_name.value) });
}
</script>

<template>
    <div
        class="flex items-center gap-2 border-b border-border/30 px-3 hover:bg-muted/30 data-deleted:bg-red-500/10 data-new:bg-green-500/10 data-updated:bg-amber-500/15"
        :class="map_user_settings.compact_signature_list ? 'py-0.5' : 'py-1.5'"
        :data-deleted="Data(is_deleted)"
        :data-new="Data(is_new)"
        :data-updated="Data(is_updated)"
    >
        <!-- Signature ID -->
        <div class="w-16 shrink-0">
            <input
                v-if="editingId"
                ref="idInputRef"
                :value="signature_id"
                @input="handleIdInput"
                @blur="saveId"
                @keydown.enter="saveId"
                @keydown.escape="cancelEditId"
                class="w-full rounded border border-border/50 bg-background/50 px-1.5 font-mono text-xs uppercase focus:border-primary focus:outline-none"
                :class="map_user_settings.compact_signature_list ? 'h-5' : 'h-6'"
                maxlength="7"
                placeholder="XXX-XXX"
            />
            <button
                v-else
                class="flex items-center font-mono text-xs hover:text-amber-400"
                :class="[can_write ? 'cursor-pointer' : 'cursor-default', map_user_settings.compact_signature_list ? 'h-5' : 'h-6']"
                @click="startEditId"
            >
                {{ signature.signature_id || '---' }}
            </button>
        </div>

        <!-- Category -->
        <div class="w-24 shrink-0">
            <Select :model-value="signature.signature_category_id" @update:modelValue="handleCategoryChange" :disabled="!can_write">
                <SelectTrigger class="w-full text-xs" :class="map_user_settings.compact_signature_list ? '!h-5 !py-0' : ''">
                    <SelectValue placeholder="Category">
                        <span class="flex items-center gap-1">
                            <component
                                :is="categoryIcon[signature.signature_category?.name ?? '']"
                                class="size-3 shrink-0"
                                :class="categoryColor[signature.signature_category?.name ?? '']"
                            />
                            {{ getCategoryAbbrev(signature.signature_category?.name) }}
                        </span>
                    </SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="category in signatureCategories" :key="category.id" :value="category.id" class="text-xs">
                        <span class="flex items-center gap-1.5">
                            <component :is="categoryIcon[category.name]" class="size-3 shrink-0" :class="categoryColor[category.name]" />
                            {{ category.name }}
                        </span>
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Type / Wormhole Info: capped for wormhole rows so the connection column gets the extra room. -->
        <div class="min-w-0 flex-1" :class="{ 'max-w-44': isWormhole }">
            <WormholeTypeInput
                v-if="isWormhole"
                :model-value="signature.signature_type_id"
                @update:model-value="handleTypeChange"
                :can_write="can_write"
                :wormhole_options="sortedAvailableTypes"
                :current_class="current_class"
                :static_signatures="static_signatures"
            />
            <SignatureTypeInput
                v-else
                :model-value="signature.signature_type_id"
                @update:model-value="handleTypeChange"
                :can_write="can_write"
                :options="sortedAvailableTypes"
                :category="signature.signature_category?.name"
                :raw-type-name="signature.raw_type_name"
            />
        </div>

        <!-- Connection (only for wormholes) -->
        <div v-if="isWormhole" class="min-w-0 flex-1">
            <MapConnectionInput
                :type="signature.signature_type"
                :selected="selected_connection"
                :unconnected_connections="unconnected_connections"
                :connected_connections="connected_connections"
                :model-value="signature.map_connection_id"
                :disabled="!can_write"
                @update:model-value="handleMapConnectionChange"
            />
        </div>

        <!-- Age -->
        <div class="w-10 shrink-0 text-right">
            <SignatureTimeDetails :category="signature.signature_category?.name" :selected_connection="selected_connection" :signature="signature" />
        </div>

        <!-- Actions -->
        <div class="flex w-14 shrink-0 items-center justify-end gap-1">
            <Button v-if="isWormhole" variant="ghost" size="icon" class="size-6 text-muted-foreground hover:text-foreground" @click="copyBookmark">
                <Copy class="size-3.5" />
            </Button>

            <DropdownMenu v-if="can_write">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground hover:text-foreground"
                        :class="map_user_settings.compact_signature_list ? 'size-5' : 'size-6'"
                    >
                        <MoreVertical class="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-44">
                    <template v-if="isWormhole">
                        <!-- Mass Status Options -->
                        <DropdownMenuRadioGroup :model-value="signature.mass_status || 'unknown'" @update:model-value="handleMassStatusChange">
                            <DropdownMenuRadioItem value="fresh" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-neutral-500" />
                                    Fresh Mass
                                </span>
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="reduced" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-amber-500" />
                                    Reduced Mass
                                </span>
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="critical" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-red-500" />
                                    Critical Mass
                                </span>
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>

                        <DropdownMenuSeparator />

                        <!-- Lifetime Options -->
                        <DropdownMenuRadioGroup :model-value="signature.lifetime" @update:model-value="handleLifetimeChange">
                            <DropdownMenuRadioItem value="healthy" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-neutral-500" />
                                    Healthy
                                </span>
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="eol" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-purple-500" />
                                    End of Life
                                </span>
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="critical" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-red-500" />
                                    Critical
                                </span>
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>

                        <DropdownMenuSeparator />

                        <!-- Chain numbering -->
                        <DropdownMenuItem :disabled="is_k162 || (!signature.is_static && static_taken_by_other)" @select.prevent="handleToggleStatic" class="text-xs">
                            <span class="mr-2 inline-flex size-3.5 items-center justify-center font-mono text-[10px] font-bold">S</span>
                            Static
                            <Check v-if="signature.is_static" class="ml-auto size-3.5" />
                        </DropdownMenuItem>
                        <DropdownMenuItem :disabled="is_k162" @select.prevent="handleToggleWandering" class="text-xs">
                            <span class="mr-2 inline-flex size-3.5 items-center justify-center font-mono text-[10px] font-bold">W</span>
                            Wandering
                            <Check v-if="signature.is_wandering" class="ml-auto size-3.5" />
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="handleSetNumber" class="text-xs">
                            <span class="mr-2 inline-flex size-3.5 items-center justify-center font-mono text-[10px] font-bold">#</span>
                            Set number…
                            <span class="ml-auto font-mono text-muted-foreground">{{ signature.alias ?? planned_alias ?? '' }}</span>
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <template v-if="selected_connection">
                            <DropdownMenuItem @select.prevent="handleTogglePreserveMass" class="text-xs">
                                <Heart class="mr-2 size-3.5" />
                                Preserve mass
                                <Check v-if="selected_connection.preserve_mass" class="ml-auto size-3.5" />
                            </DropdownMenuItem>

                            <DropdownMenuSeparator />
                        </template>
                    </template>

                    <!-- Delete Option -->
                    <DropdownMenuItem @click="handleDelete" class="text-xs text-destructive focus:text-destructive">
                        <TrashIcon class="mr-2 size-3.5" />
                        Delete Signature
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- Static, wandering or unknown? -->
        <Dialog :open="static_choice_open" @update:open="handleStaticChoiceOpenChange">
            <DialogScrollContent class="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{{ pending_type_name }} — which is it?</DialogTitle>
                    <DialogDescription>
                        {{ signature.signature_id ?? 'This signature' }} has this system's static type. Static takes {{ static_slot }}; wandering is another hole of the same
                        type.
                    </DialogDescription>
                </DialogHeader>
                <CountdownBar class="-mx-6" :remaining="static_choice_remaining" :fraction="static_choice_fraction" action="Unknown" />
                <DialogFooter class="gap-2 sm:justify-start">
                    <Button autofocus @click="chooseStaticKind('unknown')">Unknown</Button>
                    <Button variant="outline" :disabled="static_taken_by_other" @click="chooseStaticKind('static')">Static</Button>
                    <Button variant="outline" @click="chooseStaticKind('wandering')">Wandering</Button>
                </DialogFooter>
                <p v-if="static_taken_by_other" class="text-xs text-muted-foreground">Another signature in this system is already the static.</p>
            </DialogScrollContent>
        </Dialog>
    </div>
</template>
