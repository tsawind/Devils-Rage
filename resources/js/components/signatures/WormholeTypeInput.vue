<script setup lang="ts">
import UnknownTypeOption from '@/components/signatures/UnknownTypeOption.vue';
import WormholeOption from '@/components/signatures/WormholeOption.vue';
import { Combobox, ComboboxAnchor, ComboboxInput, ComboboxItem, ComboboxTrigger, ComboboxVirtualList } from '@/components/ui/combobox';
import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { matchesQuickKey, nextQuickKey, quickKeyLabel } from '@/lib/arming';
import { comboboxRowText, flattenComboboxSections, type TComboboxRow } from '@/lib/comboboxSections';
import type { TSignatureType } from '@/types/models';
import { computed, ref, watch } from 'vue';

const {
    wormhole_options,
    current_class,
    static_signatures = [],
} = defineProps<{
    can_write: boolean;
    wormhole_options: TSignatureType[];
    current_class: string | number | null;
    static_signatures?: string[];
}>();

const model = defineModel<number | null>({
    required: true,
});

const map_user_settings = useMapUserSettings();

const open = ref(false);
const search = ref('');
/** Patch 13: quick key filter (1-6 = leads to C1-C6, h / l / n = high, low, null, f = frigate holes). */
const quick = ref<string | null>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        search.value = '';
        quick.value = null;
    }
});

/** A quick key on an empty search sets (or clears) the filter instead of typing. */
function handleSearchKeydown(event: KeyboardEvent): void {
    if (search.value !== '' || event.ctrlKey || event.metaKey || event.altKey) return;
    const next = nextQuickKey(quick.value, event.key);
    if (next === undefined) return;
    event.preventDefault();
    quick.value = next;
}

const statics = computed<TSignatureType[]>(() => {
    if (static_signatures.length === 0) {
        return [];
    }
    return wormhole_options.filter((option: TSignatureType) => static_signatures.includes(option.signature)).filter(filterByCurrentClass);
});

const k162_options = computed<TSignatureType[]>(() => {
    return wormhole_options.filter((option: TSignatureType) => option.signature === 'K162').filter(filterByCurrentClass);
});

const wormholes = computed<TSignatureType[]>(() => {
    return wormhole_options
        .filter((option: TSignatureType) => option.signature !== 'K162' && !static_signatures.includes(option.signature))
        .filter(filterByCurrentClass);
});

// A pinned null row lets a wormhole signature be reset to an unknown type.
const rows = computed<TComboboxRow<TSignatureType | null>[]>(() => {
    const needle = search.value.trim().toLowerCase();
    const text = (option: TSignatureType, value: string) =>
        value === '' || option.name.toLowerCase().includes(value) || (option.extra ?? '').toLowerCase().includes(value);
    // Typing on after a quick letter also finds names starting with it ("h" then "296" finds H296).
    const matches = (option: TSignatureType) =>
        (matchesQuickKey(quick.value, option) && text(option, needle)) || (quick.value !== null && needle !== '' && text(option, quick.value + needle));

    return flattenComboboxSections<TSignatureType | null>([
        { key: 'unknown', heading: '', items: quick.value === null && (needle === '' || 'unknown'.includes(needle)) ? [null] : [] },
        { key: 'statics', heading: 'Statics', items: statics.value.filter(matches) },
        { key: 'k162', heading: 'K162', items: k162_options.value.filter(matches) },
        { key: 'wormholes', heading: 'Wormholes', items: wormholes.value.filter(matches) },
    ]);
});

const selected_signature = computed(() => {
    return wormhole_options.find((option: TSignatureType) => option.id === model.value) || null;
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
                    :placeholder="quick ? `${quickKeyLabel(quick)} only · ${quick} again to clear` : 'Search · 1-6, h, l, n, f to filter'"
                    class="h-8 border-b text-xs"
                    auto-focus
                    @keydown="handleSearchKeydown"
                />
            </template>
            <template #default="{ option }">
                <div class="w-full">
                    <div v-if="option.kind === 'heading'" class="px-2 py-1.5 text-xs text-muted-foreground">{{ option.label }}</div>
                    <ComboboxItem v-else :value="option" class="text-xs" @select.prevent="() => handleSelect(option)">
                        <UnknownTypeOption v-if="option.value === null" />
                        <WormholeOption v-else :wormhole="option.value" />
                    </ComboboxItem>
                </div>
            </template>
        </ComboboxVirtualList>
    </Combobox>
</template>

<style scoped></style>
