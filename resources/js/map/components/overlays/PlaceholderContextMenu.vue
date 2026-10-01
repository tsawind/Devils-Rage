<script setup lang="ts">
import {
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuSeparator,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
} from '@/components/ui/context-menu';
import WormholeOption from '@/components/signatures/WormholeOption.vue';
import { armHole } from '@/composables/signatures/armHole';
import { requestStaticCheck } from '@/composables/signatures/useStaticCertainty';
import usePermission from '@/composables/usePermission';
import useUser from '@/composables/useUser';
import { getTypesByCategory, signatureCategories } from '@/const/signatures';
import { classSortWeight } from '@/const/solarsystemClasses';
import { displayAlias } from '@/lib/alias';
import { armAsOptions, matchesQuickKey, myArmedHole, nextQuickKey, quickKeyLabel } from '@/lib/arming';
import { visibleBookmarkName } from '@/lib/bookmark';
import { chainAliases } from '@/lib/combat';
import type { TPlaceholder } from '@/lib/placeholders';
import { disarmSignature } from '@/map/actions/arm';
import { deleteSignature } from '@/map/actions/deleteSignature';
import { updateSignature } from '@/map/actions/updateSignature';
import { claimFor, holeAsSignature, pendingHoleBookmark } from '@/map/holeBookmark';
import { useMapStore } from '@/map/store/mapStore';
import { show } from '@/routes/maps';
import type { TSignature } from '@/types/models';
import { formatDateToISO } from '@/lib/utils';
import { UTCDate } from '@date-fns/utc';
import { router } from '@inertiajs/vue3';
import { Check, ClipboardCopy, Crosshair, Fan, Hourglass, ListTree, Scale, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Right-click a placeholder system: arm it as your next jump (patch 13), or
 * "Arm as…" another number in a combat chain; set its type (quick keys 1-6,
 * h, l, n, f filter the list); copy its bookmark (locks its number, like the
 * signature list); open it in the signature list; delete the signature.
 */
const { placeholder } = defineProps<{ placeholder: TPlaceholder }>();

const store = useMapStore();
const { canEdit } = usePermission();
const user = useUser();
const userId = computed<number | null>(() => user.value?.id ?? null);

const parent = computed(() => store.systems.get(placeholder.parentId) ?? null);
const hole = computed(() => parent.value?.pending_holes?.find((candidate) => candidate.id === placeholder.signatureId) ?? null);

/** The number this hole gets when copied or armed: its own, the planned one, or (combat chain) the next free one. */
const claim = computed(() => (parent.value && hole.value ? claimFor(store, parent.value, hole.value, placeholder.alias) : null));

const bookmark = computed(() => (parent.value && hole.value ? pendingHoleBookmark(store, parent.value, hole.value, claim.value) : ''));

function copyBookmark(): void {
    const name = bookmark.value;
    if (!name) return;
    navigator.clipboard.writeText(name).catch(() => undefined);
    // Copying locks the number, so it never shifts under a bookmark saved in game.
    if (canEdit.value && hole.value && !hole.value.alias && claim.value) {
        updateSignature({ id: placeholder.signatureId } as TSignature, { alias: claim.value });
    }
    toast.success('Copied bookmark to clipboard', { description: visibleBookmarkName(name) });
}

// ---- Arming ------------------------------------------------------------------

const armedByMe = computed(() => Boolean(hole.value?.armed_by_user_id) && hole.value?.armed_by_user_id === userId.value);
const armedByOther = computed(() => Boolean(hole.value?.armed_by_user_id) && !armedByMe.value);
const limbo = computed(() => Boolean(parent.value?.combat_color));

/** In a combat chain, re-arming another hole keeps your number. */
const armAlias = computed(() => {
    if (hole.value?.alias) return hole.value.alias;
    const mine = limbo.value ? myArmedHole(parent.value?.pending_holes, userId.value) : null;
    return mine && mine.id !== placeholder.signatureId && mine.alias && mine.armed_claimed ? mine.alias : claim.value;
});

const armAs = computed(() => {
    const system = parent.value;
    if (!system || !limbo.value) return [];
    return armAsOptions({
        parentAlias: system.alias,
        ignoredAlias: store.meta.value?.bookmark_ignored_alias,
        combatHome: Boolean(system.combat_home),
        usedBySystems: chainAliases([...store.systems.values()], system),
        signatures: system.pending_holes ?? [],
        signatureId: placeholder.signatureId,
        userId: userId.value,
    });
});

function arm(alias: string | null = null, swap = false): void {
    const system = parent.value;
    const meta = store.meta.value;
    const target = alias ?? armAlias.value;
    if (!system || !hole.value || !meta) return;
    if (!target) {
        toast.error('No free number to arm this hole as.');
        return;
    }
    armHole({
        signature: holeAsSignature(hole.value),
        system: {
            alias: system.alias,
            class: system.solarsystem.class,
            combatHome: Boolean(system.combat_home),
            combatColor: system.combat_color ?? null,
        },
        aliases: chainAliases([...store.systems.values()], system),
        formats: meta,
        alias: target,
        fallbackAlias: armAlias.value,
        swap,
    });
}

function disarm(): void {
    disarmSignature(placeholder.signatureId, () => toast.success(`Disarmed ${hole.value?.signature_id?.slice(0, 3) ?? 'the hole'}`));
}

// ---- Type --------------------------------------------------------------------

const quick = ref<string | null>(null);
const wormholeCategoryId = signatureCategories.find((category) => category.code === 'wormhole')?.id ?? null;

/** The wormhole types that spawn in the parent's class: statics first, then by where they lead. */
const types = computed(() => {
    const system = parent.value;
    if (!system || wormholeCategoryId === null) return [];
    const statics = (system.solarsystem.statics ?? []).map((candidate) => candidate.name.toUpperCase());
    return getTypesByCategory(wormholeCategoryId)
        .filter((type) => type.spawn_areas?.includes(system.solarsystem.class) || type.signature === 'K162')
        .filter((type) => matchesQuickKey(quick.value, type))
        .toSorted(
            (a, b) =>
                Number(statics.includes(b.signature.toUpperCase())) - Number(statics.includes(a.signature.toUpperCase())) ||
                Number(a.signature === 'K162') - Number(b.signature === 'K162') ||
                classSortWeight(a.target_class) - classSortWeight(b.target_class) ||
                a.signature.localeCompare(b.signature),
        );
});

function handleTypeKeydown(event: KeyboardEvent): void {
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    const next = nextQuickKey(quick.value, event.key);
    if (next === undefined) return;
    event.preventDefault();
    event.stopPropagation();
    quick.value = next;
}

function setType(typeId: number): void {
    const system = parent.value;
    if (!system) return;
    updateSignature({ id: placeholder.signatureId } as TSignature, { signature_type_id: typeId });
    // The type may make a static certain (everything scanned, nothing else can be it).
    requestStaticCheck(system.id, system);
}

// ---- Mass and life (patch 14) ---------------------------------------------------

const massOptions = [
    { value: 'fresh', label: 'Fresh mass', dot: 'bg-neutral-500' },
    { value: 'reduced', label: 'Reduced mass', dot: 'bg-amber-500' },
    { value: 'critical', label: 'Critical mass', dot: 'bg-red-500' },
] as const;
const lifeOptions = [
    { value: 'healthy', label: 'Healthy', dot: 'bg-neutral-500' },
    { value: 'eol', label: 'End of life', dot: 'bg-purple-500' },
    { value: 'critical', label: 'Critical (under 1 h)', dot: 'bg-red-500' },
] as const;

const massLabel = computed(() => massOptions.find((option) => option.value === hole.value?.mass_status)?.label.replace(' mass', '') ?? 'Unknown');
const lifeLabel = computed(() => lifeOptions.find((option) => option.value === hole.value?.lifetime)?.label ?? 'Healthy');

function setMass(value: string): void {
    updateSignature({ id: placeholder.signatureId } as TSignature, { mass_status: value });
}

function setLife(value: string): void {
    updateSignature({ id: placeholder.signatureId } as TSignature, { lifetime: value, lifetime_updated_at: formatDateToISO(new UTCDate()) });
}

// ---- Other -------------------------------------------------------------------

function openInList(): void {
    const meta = store.meta.value;
    const system = parent.value;
    if (!meta || !system) return;
    router.visit(show(meta.slug, { mergeQuery: { solarsystem_id: system.solarsystem_id } }).url, {
        preserveState: true,
        preserveScroll: true,
        only: ['map', 'selected_map_solarsystem', 'map_navigation', 'map_characters'],
    });
}

function removeSignature(): void {
    deleteSignature({ id: placeholder.signatureId } as TSignature);
}
</script>

<template>
    <ContextMenuContent>
        <ContextMenuLabel class="font-mono text-xs text-muted-foreground">{{ placeholder.label || 'Not numbered yet' }} · {{ placeholder.detail }}</ContextMenuLabel>
        <ContextMenuSeparator />
        <template v-if="canEdit">
            <ContextMenuItem v-if="armedByOther" disabled>
                <Crosshair class="size-4 text-red-400" />
                Armed by {{ hole?.armed_by_name ?? 'someone' }}
            </ContextMenuItem>
            <template v-else>
                <ContextMenuItem :disabled="!hole" @select="arm()">
                    <Crosshair class="size-4 text-red-400" />
                    {{ armedByMe ? 'Re-arm (copy again)' : 'Arm (next jump)' }}
                    <span v-if="armAlias" class="ml-auto pl-3 font-mono text-xs text-muted-foreground">{{ displayAlias(armAlias) }}</span>
                </ContextMenuItem>
                <ContextMenuSub v-if="armAs.length">
                    <ContextMenuSubTrigger>
                        <Crosshair class="size-4 text-red-400" />
                        Arm as…
                    </ContextMenuSubTrigger>
                    <ContextMenuSubContent class="w-52">
                        <ContextMenuItem
                            v-for="option in armAs"
                            :key="option.alias"
                            :disabled="option.state === 'taken' || option.state === 'mine'"
                            @select="arm(option.alias, option.state === 'swap')"
                        >
                            <span class="font-mono font-bold">{{ displayAlias(option.alias) }}</span>
                            <span class="ml-auto text-xs text-muted-foreground">
                                {{ option.state === 'swap' ? `swap with ${option.holder}` : option.state === 'mine' ? 'this hole' : (option.holder ?? 'free') }}
                            </span>
                        </ContextMenuItem>
                    </ContextMenuSubContent>
                </ContextMenuSub>
                <ContextMenuItem v-if="armedByMe" @select="disarm">
                    <Crosshair class="size-4 text-muted-foreground" />
                    Disarm
                </ContextMenuItem>
            </template>
            <ContextMenuSeparator />
            <ContextMenuSub>
                <ContextMenuSubTrigger>
                    <Fan class="size-4" />
                    Type
                    <span v-if="hole?.wormhole" class="ml-auto pl-3 font-mono text-xs text-muted-foreground">{{ hole.wormhole }}</span>
                </ContextMenuSubTrigger>
                <ContextMenuSubContent class="max-h-80 w-56 overflow-y-auto" @keydown.capture="handleTypeKeydown">
                    <ContextMenuLabel class="text-[10px] font-normal text-muted-foreground">
                        {{ quick ? `${quickKeyLabel(quick)} only · ${quick} again to clear` : 'Keys: 1-6, h, l, n, f to filter' }}
                    </ContextMenuLabel>
                    <ContextMenuItem v-for="type in types" :key="type.id" class="text-xs" @select="setType(type.id)">
                        <WormholeOption :wormhole="type" />
                    </ContextMenuItem>
                    <ContextMenuItem v-if="types.length === 0" disabled class="text-xs">No types match</ContextMenuItem>
                </ContextMenuSubContent>
            </ContextMenuSub>
            <ContextMenuSub>
                <ContextMenuSubTrigger>
                    <Scale class="size-4" />
                    Mass
                    <span class="ml-auto pl-3 text-xs text-muted-foreground">{{ massLabel }}</span>
                </ContextMenuSubTrigger>
                <ContextMenuSubContent class="w-44">
                    <ContextMenuItem v-for="option in massOptions" :key="option.value" class="text-xs" @select="setMass(option.value)">
                        <span class="inline-block size-2 rounded-full" :class="option.dot" />
                        {{ option.label }}
                        <Check v-if="hole?.mass_status === option.value" class="ml-auto size-3.5" />
                    </ContextMenuItem>
                </ContextMenuSubContent>
            </ContextMenuSub>
            <ContextMenuSub>
                <ContextMenuSubTrigger>
                    <Hourglass class="size-4" />
                    Life
                    <span class="ml-auto pl-3 text-xs text-muted-foreground">{{ lifeLabel }}</span>
                </ContextMenuSubTrigger>
                <ContextMenuSubContent class="w-48">
                    <ContextMenuItem v-for="option in lifeOptions" :key="option.value" class="text-xs" @select="setLife(option.value)">
                        <span class="inline-block size-2 rounded-full" :class="option.dot" />
                        {{ option.label }}
                        <Check v-if="(hole?.lifetime ?? 'healthy') === option.value" class="ml-auto size-3.5" />
                    </ContextMenuItem>
                </ContextMenuSubContent>
            </ContextMenuSub>
        </template>
        <ContextMenuItem :disabled="!bookmark" @select="copyBookmark">
            <ClipboardCopy class="size-4" />
            Copy bookmark
        </ContextMenuItem>
        <ContextMenuItem @select="openInList">
            <ListTree class="size-4" />
            Open in signature list
        </ContextMenuItem>
        <template v-if="canEdit">
            <ContextMenuSeparator />
            <ContextMenuItem class="text-destructive focus:text-destructive" @select="removeSignature">
                <Trash2 class="size-4" />
                Delete signature
            </ContextMenuItem>
        </template>
    </ContextMenuContent>
</template>
