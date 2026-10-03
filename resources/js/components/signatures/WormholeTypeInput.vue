<script setup lang="ts">
import UnknownTypeOption from '@/components/signatures/UnknownTypeOption.vue';
import WormholeOption from '@/components/signatures/WormholeOption.vue';
import { Combobox, ComboboxAnchor, ComboboxInput, ComboboxItem, ComboboxTrigger, ComboboxVirtualList } from '@/components/ui/combobox';
import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { typeSearchMatches } from '@/lib/arming';
import { comboboxRowText, flattenComboboxSections, type TComboboxRow } from '@/lib/comboboxSections';
import { k162Hint, k162Order, offersK162 } from '@/lib/k162';
import { isQuickKey, isRareType, oftenSeen, oftenSeenCounts, quickKeyRank } from '@/lib/typeOrdering';
import { useMapStore } from '@/map/store/mapStore';
import type { TSignatureType } from '@/types/models';
import { computed, ref, watch } from 'vue';

const {
    wormhole_options,
    other_options = [],
    current_class,
    static_signatures = [],
    standing_class = null,
} = defineProps<{
    can_write: boolean;
    wormhole_options: TSignatureType[];
    /** Patch 14: every other wormhole type (the data doesn't list it for this class), so a hole seen in space is always pickable. */
    other_options?: TSignatureType[];
    current_class: string | number | null;
    static_signatures?: string[];
    /** Patch 20: the class of the system the signature is in (K162 options and hints depend on it). */
    standing_class?: string | null;
}>();

const model = defineModel<number | null>({
    required: true,
});

const map_user_settings = useMapUserSettings();

const open = ref(false);
const search = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        search.value = '';
    }
});

const statics = computed<TSignatureType[]>(() => {
    if (static_signatures.length === 0) {
        return [];
    }
    return wormhole_options.filter((option: TSignatureType) => static_signatures.includes(option.signature)).filter(filterByCurrentClass);
});

// Patch 20: the K162 options for where you stand (grouped ones only where Show Info can tell them apart).
const k162_options = computed<TSignatureType[]>(() => {
    return wormhole_options
        .filter((option: TSignatureType) => option.signature === 'K162' && offersK162(option, standing_class))
        .filter(filterByCurrentClass)
        .toSorted((a, b) => k162Order(a) - k162Order(b));
});

const wormholes = computed<TSignatureType[]>(() => {
    return wormhole_options
        .filter((option: TSignatureType) => option.signature !== 'K162' && !static_signatures.includes(option.signature))
        .filter(filterByCurrentClass);
});

// Patch 20: what the corp types most from this class, counted from the map (learns by itself).
const often_counts = computed(() => {
    let store;
    try {
        store = useMapStore();
    } catch {
        return new Map<string, number>();
    }
    return oftenSeenCounts(store.systems.values(), store.connections.values(), standing_class);
});
const often = computed(() => oftenSeen(wormholes.value, often_counts.value, static_signatures));

const hint = (option: TSignatureType) => k162Hint(option, standing_class);

// A pinned null row lets a wormhole signature be reset to an unknown type.
const rows = computed<TComboboxRow<TSignatureType | null>[]>(() => {
    const needle = search.value.trim().toLowerCase();
    const upperStatics = static_signatures.map((name) => name.toUpperCase());
    const others = other_options.filter(filterByCurrentClass);
    const common = wormholes.value.filter((option) => !isRareType(option) && !often.value.includes(option));
    const rare = [...wormholes.value.filter((option) => isRareType(option)), ...others.filter((option) => isRareType(option))];
    const otherCommon = others.filter((option) => !isRareType(option));

    // Patch 20: a quick key lifts its matches to the top and keeps everything else below.
    if (isQuickKey(needle)) {
        const all = [...statics.value, ...k162_options.value, ...often.value, ...common, ...otherCommon, ...rare];
        const ranked = all
            .map((option, index) => ({ option, index, rank: quickKeyRank(needle, option, upperStatics) }))
            .filter((entry) => entry.rank !== null)
            .toSorted((a, b) => (a.rank as number) - (b.rank as number) || a.index - b.index)
            .map((entry) => entry.option);
        const lifted = new Set(ranked.map((option) => option.id));
        const rest = (items: TSignatureType[]) => items.filter((option) => !lifted.has(option.id));
        return flattenComboboxSections<TSignatureType | null>([
            { key: 'match', heading: `Matches ${needle.toUpperCase()}`, items: ranked },
            { key: 'statics', heading: 'Statics', items: rest(statics.value) },
            { key: 'k162', heading: 'K162', items: rest(k162_options.value) },
            { key: 'often', heading: 'Often seen on this map', items: rest(often.value) },
            { key: 'wormholes', heading: 'Wormholes', items: rest(common) },
            { key: 'other', heading: 'Other wormholes (not listed for this class)', items: rest(otherCommon) },
            { key: 'rare', heading: 'Rare: frigate, Thera, Turnur, drifter', items: rest(rare) },
            { key: 'unknown', heading: '', items: [null] },
        ]);
    }

    // Names first ("F1" finds F135).
    const matches = (option: TSignatureType) => typeSearchMatches(needle, option);

    return flattenComboboxSections<TSignatureType | null>([
        { key: 'unknown', heading: '', items: needle === '' || 'unknown'.includes(needle) ? [null] : [] },
        { key: 'statics', heading: 'Statics', items: statics.value.filter(matches) },
        { key: 'k162', heading: 'K162', items: k162_options.value.filter(matches) },
        { key: 'often', heading: 'Often seen on this map', items: often.value.filter(matches) },
        { key: 'wormholes', heading: 'Wormholes', items: common.filter(matches) },
        { key: 'other', heading: 'Other wormholes (not listed for this class)', items: otherCommon.filter(matches) },
        { key: 'rare', heading: 'Rare: frigate, Thera, Turnur, drifter', items: rare.filter(matches) },
    ]);
});

const selected_signature = computed(() => {
    return [...wormhole_options, ...other_options].find((option: TSignatureType) => option.id === model.value) || null;
});

function handleSelect(row: TComboboxRow<TSignatureType | null>) {
    if (row.kind !== 'option') {
        return;
    }
    model.value = row.value?.id ?? null;
    open.value = false;
}

function rowText(row: TComboboxRow<TSignatureType | null>): string {
    return comboboxRowText(row, (option) => option?.name ?? 'Unknown');
}

function filterByCurrentClass(option: TSignatureType) {
    if (!current_class) {
        return true;
    }
    return option.target_class === current_class.toString();
}
</script>

<template>
    <Combobox v-model:open="open" :ignore-filter="true" :disabled="!can_write">
        <ComboboxAnchor as-child>
            <ComboboxTrigger class="text-xs" :size="map_user_settings.compact_signature_list ? 'sm' : 'default'" :disabled="!can_write">
                <WormholeOption v-if="selected_signature" :wormhole="selected_signature" />
                <span v-else class="truncate text-muted-foreground">Type</span>
            </ComboboxTrigger>
        </ComboboxAnchor>
        <ComboboxVirtualList :options="rows" :text-content="rowText" empty-text="No types found" class="min-w-44">
            <template #header>
                <ComboboxInput
                    v-model="search"
                    placeholder="Search · 1-6, h, l, n, f lift that class to the top"
                    class="h-8 border-b text-xs"
                    auto-focus
                />
            </template>
            <template #default="{ option }">
                <div class="w-full">
                    <div v-if="option.kind === 'heading'" class="px-2 py-1.5 text-xs text-muted-foreground">{{ option.label }}</div>
                    <ComboboxItem v-else :value="option" class="text-xs" @select.prevent="() => handleSelect(option)">
                        <UnknownTypeOption v-if="option.value === null" />
                        <span v-else class="flex w-full items-center gap-2">
                            <WormholeOption :wormhole="option.value" />
                            <span v-if="hint(option.value)" class="ml-auto text-[11px] text-muted-foreground">{{ hint(option.value) }}</span>
                        </span>
                    </ComboboxItem>
                </div>
            </template>
        </ComboboxVirtualList>
    </Combobox>
</template>

<style scoped></style>
