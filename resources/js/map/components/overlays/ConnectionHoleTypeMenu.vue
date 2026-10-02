<script setup lang="ts">
import { ContextMenuItem, ContextMenuLabel, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger } from '@/components/ui/context-menu';
import WormholeOption from '@/components/signatures/WormholeOption.vue';
import usePermission from '@/composables/usePermission';
import { getTypesByCategory, signatureCategories } from '@/const/signatures';
import { classSortWeight } from '@/const/solarsystemClasses';
import { displayAlias } from '@/lib/alias';
import { typeSearchMatches } from '@/lib/arming';
import { holeSide } from '@/lib/holeSide';
import { visibleBookmarkName } from '@/lib/bookmark';
import { setConnectionHoleType } from '@/map/actions/holeType';
import { linkedForwardBookmark } from '@/map/holeBookmark';
import { useMapStore } from '@/map/store/mapStore';
import type { TMapConnection, TMapSolarsystem } from '@/pages/maps';
import type { TSignatureType } from '@/types/models';
import { Fan } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 14: right-click a jumped connection → Type. Lists the hole types,
 * the ones that fit these two systems first (spawn on one, lead to the
 * other's class). Picking one types the side it spawned from; the other side
 * becomes its K162 (the size letter, arrow and pipe follow).
 */
const { connection } = defineProps<{
    connection: TMapConnection & { source: TMapSolarsystem; target: TMapSolarsystem };
}>();

const { canEdit } = usePermission();
const store = useMapStore();
const typed = ref('');

const wormholeCategoryId = signatureCategories.find((category) => category.code === 'wormhole')?.id ?? null;

const ends = computed(() => [
    { id: connection.source.id, class: connection.source.solarsystem.class },
    { id: connection.target.id, class: connection.target.solarsystem.class },
]);

/** The type the hole has now (either side's, not the K162). */
const current = computed(() => {
    for (const signature of connection.signatures ?? []) {
        const name = signature.wormhole?.name ?? null;
        if (name && !name.toUpperCase().startsWith('K162')) return name;
    }
    return null;
});

const groups = computed(() => {
    if (wormholeCategoryId === null) return { fits: [], other: [] };
    const [a, b] = ends.value;
    const fits: { type: TSignatureType; sideId: number }[] = [];
    const other: { type: TSignatureType; sideId: number }[] = [];
    for (const type of getTypesByCategory(wormholeCategoryId)) {
        if (type.signature.toUpperCase().startsWith('K162') || !typeSearchMatches(typed.value, type)) continue;
        const side = holeSide(type, a, b);
        if (!side) continue;
        (side.fits ? fits : other).push({ type, sideId: side.sideId });
    }
    const order = (x: { type: TSignatureType }, y: { type: TSignatureType }) =>
        classSortWeight(x.type.target_class) - classSortWeight(y.type.target_class) || x.type.signature.localeCompare(y.type.signature);
    return { fits: fits.toSorted(order), other: other.toSorted(order) };
});

function sideName(id: number): string {
    const system = id === connection.source.id ? connection.source : connection.target;
    return displayAlias(system.alias) || system.solarsystem.name;
}

function handleKeydown(event: KeyboardEvent): void {
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

function pick(entry: { type: TSignatureType; sideId: number }): void {
    const other = entry.sideId === connection.source.id ? connection.target.id : connection.source.id;
    setConnectionHoleType(connection.id, entry.sideId, entry.type.id, () => {
        // Patch 16: copy the hole's bookmark from your side (the side you're in, else the first end), now with its type.
        const here = [connection.source.id, connection.target.id].includes(store.currentSystemId.value ?? -1) ? store.currentSystemId.value! : connection.source.id;
        const there = here === connection.source.id ? connection.target : connection.source;
        const system = store.systems.get(here);
        const name = system ? linkedForwardBookmark(store, system, there.id, there.alias ?? '') : '';
        const say = (copied: boolean) =>
            toast.success(`${entry.type.signature} from ${sideName(entry.sideId)}`, {
                description: copied ? `${sideName(other)}'s side is its K162 · copied ${visibleBookmarkName(name)}` : `${sideName(other)}'s side is its K162.`,
            });
        // Only say "copied" when the browser let us (after the round trip it may not).
        if (name) navigator.clipboard.writeText(name).then(() => say(true), () => say(false));
        else say(false);
    });
}
</script>

<template>
    <ContextMenuSub v-if="canEdit && connection.type !== 'stargate'">
        <ContextMenuSubTrigger>
            <Fan class="size-4" />
            Type
            <span class="ml-auto pl-3 font-mono text-xs text-muted-foreground">{{ current ?? '?' }}</span>
        </ContextMenuSubTrigger>
        <ContextMenuSubContent class="max-h-80 w-72 overflow-y-auto" @keydown.capture="handleKeydown">
            <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">
                {{ typed ? `Search: ${typed.toUpperCase()} · Backspace to edit` : 'Type to search · 1-6, h, l, n, f also match class' }}
            </ContextMenuLabel>
            <template v-if="groups.fits.length">
                <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">Fits these two systems</ContextMenuLabel>
                <ContextMenuItem v-for="entry in groups.fits" :key="entry.type.id" class="text-xs" @select="pick(entry)">
                    <WormholeOption :wormhole="entry.type" />
                    <span class="ml-auto pl-2 text-[11px] text-muted-foreground">from {{ sideName(entry.sideId) }}</span>
                </ContextMenuItem>
            </template>
            <template v-if="groups.other.length">
                <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">Other wormholes</ContextMenuLabel>
                <ContextMenuItem v-for="entry in groups.other" :key="entry.type.id" class="text-xs" @select="pick(entry)">
                    <WormholeOption :wormhole="entry.type" />
                    <span class="ml-auto pl-2 text-[11px] text-muted-foreground">from {{ sideName(entry.sideId) }}</span>
                </ContextMenuItem>
            </template>
            <ContextMenuItem v-if="groups.fits.length + groups.other.length === 0" disabled class="text-xs">No types match</ContextMenuItem>
        </ContextMenuSubContent>
    </ContextMenuSub>
</template>
