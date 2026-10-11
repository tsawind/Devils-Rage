<script setup lang="ts">
import { autoCopy } from '@/composables/useClipboardSetting';
import { ContextMenuItem, ContextMenuLabel, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger } from '@/components/ui/context-menu';
import WormholeOption from '@/components/signatures/WormholeOption.vue';
import usePermission from '@/composables/usePermission';
import { getTypesByCategory, signatureCategories } from '@/const/signatures';
import { classSortWeight } from '@/const/solarsystemClasses';
import { displayAlias } from '@/lib/alias';
import { typeSearchMatches } from '@/lib/arming';
import { k162Classes, k162Hint, k162Order, offersK162 } from '@/lib/k162';
import { visibleBookmarkName } from '@/lib/bookmark';
import { setConnectionHoleType } from '@/map/actions/holeType';
import { linkedForwardBookmark } from '@/map/holeBookmark';
import { useMapStore } from '@/map/store/mapStore';
import type { TMapConnection, TMapSolarsystem } from '@/pages/maps';
import type { TSignatureType } from '@/types/models';
import { Fan } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { signatureToast as toast } from '@/lib/signatureToast';

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

/** The type the hole has now (either side's, not the K162). */
const current = computed(() => {
    for (const signature of connection.signatures ?? []) {
        const name = signature.wormhole?.name ?? null;
        if (name && !name.toUpperCase().startsWith('K162')) return name;
    }
    return null;
});

/**
 * Patch 20: one entry per side, each with its own list: the types that can spawn in that
 * system (its statics first, the ones that lead to the other end's class next) and the
 * K162 options for its class. Your own side is listed first.
 */
type TSideEntry = { type: TSignatureType; sideId: number; hint: string | null };
const sides = computed(() => {
    const here = store.currentSystemId.value;
    const list = [connection.source, connection.target].map((system) => {
        const other = system.id === connection.source.id ? connection.target : connection.source;
        const signature = (connection.signatures ?? []).find((candidate) => candidate.map_solarsystem_id === system.id) ?? null;
        return { system, other, signature };
    });
    return list.toSorted((a, b) => Number(b.system.id === here) - Number(a.system.id === here));
});

function typesFor(side: { system: TMapSolarsystem; other: TMapSolarsystem }): { k162: TSideEntry[]; statics: TSideEntry[]; fits: TSideEntry[]; other: TSideEntry[] } {
    const empty = { k162: [], statics: [], fits: [], other: [] };
    if (wormholeCategoryId === null) return empty;
    const standing = side.system.solarsystem.class;
    const staticNames = (side.system.solarsystem.statics ?? []).map((value) => value.name.toUpperCase());
    const result: { k162: TSideEntry[]; statics: TSideEntry[]; fits: TSideEntry[]; other: TSideEntry[] } = { k162: [], statics: [], fits: [], other: [] };
    for (const type of getTypesByCategory(wormholeCategoryId)) {
        if (!typeSearchMatches(typed.value, type)) continue;
        const entry = { type, sideId: side.system.id, hint: k162Hint(type, standing) };
        if (type.signature.toUpperCase() === 'K162') {
            if (!offersK162(type, standing)) continue;
            // The K162 leading to the other end's class first.
            result.k162.push(entry);
            continue;
        }
        if (!type.spawn_areas?.includes(standing)) continue;
        if (staticNames.includes(type.signature.toUpperCase())) result.statics.push(entry);
        else if (type.target_class === side.other.solarsystem.class) result.fits.push(entry);
        else result.other.push(entry);
    }
    const order = (x: TSideEntry, y: TSideEntry) =>
        classSortWeight(x.type.target_class) - classSortWeight(y.type.target_class) || x.type.signature.localeCompare(y.type.signature);
    const otherClass = side.other.solarsystem.class;
    result.k162.sort((x, y) => Number(k162Classes(y.type).includes(otherClass)) - Number(k162Classes(x.type).includes(otherClass)) || k162Order(x.type) - k162Order(y.type));
    result.statics.sort(order);
    result.fits.sort(order);
    result.other.sort(order);
    return result;
}

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
    if (entry.type.signature.toUpperCase() === 'K162') {
        setConnectionHoleType(connection.id, entry.sideId, entry.type.id, () => toast.success(`${sideName(entry.sideId)}'s side is ${entry.type.name}`));
        return;
    }
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
        if (name) void autoCopy(name).then(say);
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
        <ContextMenuSubContent class="w-64">
            <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">{{ sideName(connection.source.id) }} ↔ {{ sideName(connection.target.id) }}</ContextMenuLabel>
            <ContextMenuSub v-for="side in sides" :key="side.system.id">
                <ContextMenuSubTrigger class="text-xs">
                    <span>{{ sideName(side.system.id) }} side</span>
                    <span class="ml-auto pl-3 font-mono text-[11px] text-muted-foreground">
                        {{ side.signature?.signature_id?.slice(0, 7) ?? 'no sig' }} · {{ side.signature?.wormhole?.name ?? '?' }}
                    </span>
                </ContextMenuSubTrigger>
                <ContextMenuSubContent class="max-h-80 w-72 overflow-y-auto" @keydown.capture="handleKeydown">
                    <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">
                        {{ typed ? `Search: ${typed.toUpperCase()} · Backspace to edit` : `Types that spawn in ${sideName(side.system.id)} · type to search` }}
                    </ContextMenuLabel>
                    <template v-for="group in [
                        { key: 'statics', label: 'Statics', items: typesFor(side).statics },
                        { key: 'k162', label: 'K162', items: typesFor(side).k162 },
                        { key: 'fits', label: `Lead to ${sideName(side.other.id)}'s class`, items: typesFor(side).fits },
                        { key: 'other', label: 'Other wormholes', items: typesFor(side).other },
                    ]" :key="group.key">
                        <template v-if="group.items.length">
                            <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">{{ group.label }}</ContextMenuLabel>
                            <ContextMenuItem v-for="entry in group.items" :key="entry.type.id" class="text-xs" @select="pick(entry)">
                                <WormholeOption :wormhole="entry.type" />
                                <span v-if="entry.hint" class="ml-auto pl-2 text-[11px] text-muted-foreground">{{ entry.hint }}</span>
                            </ContextMenuItem>
                        </template>
                    </template>
                </ContextMenuSubContent>
            </ContextMenuSub>
            <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">A real type on one side makes the other its K162.</ContextMenuLabel>
        </ContextMenuSubContent>
    </ContextMenuSub>
</template>
