<script setup lang="ts">
import { k162Hint, offersK162 } from '@/lib/k162';
import { oftenSeenCounts, typeSections } from '@/lib/typeOrdering';
import { autoCopy, copyButton } from '@/composables/useClipboardSetting';
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
import { markStaticByHand, requestStaticCheck, staticConfirm } from '@/composables/signatures/useStaticCertainty';
import usePermission from '@/composables/usePermission';
import useUser from '@/composables/useUser';
import { getTypesByCategory, signatureCategories } from '@/const/signatures';
import { classSortWeight } from '@/const/solarsystemClasses';
import { displayAlias, staticSlotFor } from '@/lib/alias';
import { armAsOptions, myArmedHole, typeSearchMatches } from '@/lib/arming';
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
import { Check, ClipboardCopy, Crosshair, Fan, Hourglass, ListTree, Scale, Star, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
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

// ---- Patch 18b: the static ------------------------------------------------------

/** The parent's statics no hole is marked as yet (one static per system in our numbering). */
const missingStatics = computed(() => {
    const system = parent.value;
    if (!system || (system.pending_holes ?? []).some((candidate) => candidate.is_static)) return [];
    return (system.solarsystem.statics ?? []).map((candidate) => ({ name: candidate.name, leadsTo: candidate.leads_to }));
});

function askStatic(candidate: { name: string; leadsTo: string }): void {
    const system = parent.value;
    const current = hole.value;
    if (!system || !current) return;
    const meta = store.meta.value;
    staticConfirm.value = {
        signatureLabel: current.signature_id?.slice(0, 3) ?? 'This hole',
        where: displayAlias(system.alias) || system.solarsystem.name,
        staticName: candidate.name,
        leadsTo: candidate.leadsTo.toUpperCase(),
        slot: displayAlias(staticSlotFor(system.alias, system.solarsystem.statics, candidate.name, meta?.bookmark_ignored_alias, Boolean(system.combat_home))),
        confirm: () => markStaticByHand(system.id, current.id, candidate.name, (current.wormhole ?? '').toUpperCase() !== candidate.name.toUpperCase()),
    };
}

function notStatic(): void {
    updateSignature({ id: placeholder.signatureId } as TSignature, { is_static: false });
    toast.success('No longer marked as the static');
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

/** Patch 14: what you typed in the Type menu ("f", "F1"…). */
const typed = ref('');
watch(
    () => placeholder.signatureId,
    () => (typed.value = ''),
);
const wormholeCategoryId = signatureCategories.find((category) => category.code === 'wormhole')?.id ?? null;

/**
 * The wormhole types: the ones that spawn in the parent's class first (statics
 * on top), then every other type under "Other wormholes" (the data may miss
 * one you can see in space).
 */
const typeGroups = computed(() => {
    const system = parent.value;
    if (!system || wormholeCategoryId === null) return [];
    const standing = system.solarsystem.class;
    const all = getTypesByCategory(wormholeCategoryId);
    const spawnsHere = (type: { spawn_areas?: string[] | null; signature: string }) =>
        Boolean(type.spawn_areas?.includes(standing)) || type.signature === 'K162';
    // Patch 20: the same order as the signature list (statics, K162s, often seen, the rest, rare last).
    return typeSections({
        here: all.filter(spawnsHere).toSorted((a, b) => classSortWeight(a.target_class) - classSortWeight(b.target_class) || a.signature.localeCompare(b.signature)),
        elsewhere: all.filter((type) => !spawnsHere(type)).toSorted((a, b) => classSortWeight(a.target_class) - classSortWeight(b.target_class) || a.signature.localeCompare(b.signature)),
        staticNames: (system.solarsystem.statics ?? []).map((candidate) => candidate.name),
        standingClass: standing,
        query: typed.value,
        counts: oftenSeenCounts(store.systems.values(), store.connections.values(), standing),
        offers: (type) => offersK162(type, standing),
        matches: (query, type) => typeSearchMatches(query, type),
    });
});

/** Typing in the Type menu searches (letters, digits); Backspace deletes, Escape still closes. */
function handleTypeKeydown(event: KeyboardEvent): void {
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (event.key === 'Backspace') {
        if (!typed.value) return;
        typed.value = typed.value.slice(0, -1);
    } else if (/^[a-z0-9]$/i.test(event.key)) {
        typed.value += event.key;
    } else {
        return;
    }
    event.preventDefault();
    event.stopPropagation();
}

function setType(typeId: number): void {
    const system = parent.value;
    if (!system) return;
    // Patch 16: also copy the hole's bookmark, named with the type just picked, and lock its number.
    const type = getTypesByCategory(wormholeCategoryId ?? 0).find((candidate) => candidate.id === typeId);
    const lock = canEdit.value && hole.value && !hole.value.alias && claim.value ? claim.value : null;
    const name =
        hole.value && type
            ? pendingHoleBookmark(store, system, { ...hole.value, wormhole: type.signature, target_class: type.target_class }, hole.value.alias ?? claim.value)
            : '';
    updateSignature({ id: placeholder.signatureId } as TSignature, { signature_type_id: typeId, ...(lock ? { alias: lock } : {}) });
    if (name) {
        void autoCopy(name).then((copied) =>
            copied
                ? toast.success(`${type?.signature ?? 'Type'} set · copied bookmark`, { description: visibleBookmarkName(name) })
                : toast.success(`${type?.signature ?? 'Type'} set`, { description: visibleBookmarkName(name), action: copyButton(name) }),
        );
    }
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
    // One hole fewer may leave only one that can be the static.
    if (parent.value) requestStaticCheck(parent.value.id, parent.value);
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
                    <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">
                        {{ typed ? `Search: ${typed.toUpperCase()} · Backspace to edit` : 'Type to search · 1-6, h, l, n, f lift that class to the top' }}
                    </ContextMenuLabel>
                    <template v-for="group in typeGroups" :key="group.key">
                        <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">{{ group.label }}</ContextMenuLabel>
                        <ContextMenuItem v-for="type in group.items" :key="type.id" class="text-xs" @select="setType(type.id)">
                            <WormholeOption :wormhole="type" />
                            <span v-if="parent && k162Hint(type, parent.solarsystem.class)" class="ml-auto pl-2 text-[11px] text-muted-foreground">
                                {{ k162Hint(type, parent.solarsystem.class) }}
                            </span>
                        </ContextMenuItem>
                    </template>
                    <ContextMenuItem v-if="typeGroups.length === 0" disabled class="text-xs">No types match</ContextMenuItem>
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
        <!-- Patch 18b: mark (or unmark) the static by hand, after a warning -->
        <template v-if="canEdit && hole">
            <ContextMenuItem v-if="hole.is_static" @select="notStatic">
                <Star class="size-4" />
                Not the static
            </ContextMenuItem>
            <template v-else>
                <ContextMenuItem v-for="candidate in missingStatics" :key="candidate.name" @select="askStatic(candidate)">
                    <Star class="size-4 text-green-400" />
                    This is the static
                    <span class="ml-auto pl-3 font-mono text-xs text-green-400">{{ candidate.name }}</span>
                </ContextMenuItem>
            </template>
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
