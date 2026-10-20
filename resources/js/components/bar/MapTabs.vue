<script setup lang="ts">
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { tabsThatFit } from '@/lib/characterColor';
import { clearTabAlerts, currentMapId, describeAlerts, keepNextMap, tabAlerts, watchTabMaps, type TTabAlerts } from '@/lib/mapTabs';
import { updateMapUserSettings } from '@/map/api';
import { show } from '@/routes/maps';
import type { AppPageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { useIntervalFn, useStorage } from '@vueuse/core';
import { ChevronDown, Pin, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * Patch 41: map tabs, like a browser's. The maps you opened (kept per person: they're the
 * maps pinned to your bar), the one you're on underlined in red; × closes a tab (the map
 * itself isn't touched). As many as fit in `width`; the rest go into "+N ▾".
 *
 * Patch 42: only maps opened from the map picker / All maps (or kept with the pin) become
 * tabs; a map reached from a link is a passing tab (dashed) until you leave it. Tabs show
 * kills and notices on their map while you're elsewhere.
 */
const { map, width } = defineProps<{ map: { id: number; name: string; slug: string }; width: number }>();

type TMapTab = { id: number; name: string; slug: string };
const page = usePage<AppPageProps>();

/** One tab takes this many pixels (fixed, so the count is easy to work out). */
const TAB_WIDTH = 92;

/** Patch 51b: the order you dragged the tabs into (this browser); new tabs go last. */
const tabOrder = useStorage<number[]>('map-tab-order', []);

const tabs = computed<TMapTab[]>(() => {
    const pinned = page.props.pinned_maps ?? [];
    const all = pinned.some((tab) => tab.id === map.id) ? pinned : [{ id: map.id, name: map.name, slug: map.slug }, ...pinned];
    const rank = (id: number): number => {
        const at = tabOrder.value.indexOf(id);
        return at < 0 ? Number.MAX_SAFE_INTEGER : at;
    };
    return [...all].sort((a, b) => rank(a.id) - rank(b.id));
});

const draggingTab = ref<number | null>(null);
const dropOnTab = ref<number | null>(null);
function onTabDragStart(event: DragEvent, tab: TMapTab): void {
    draggingTab.value = tab.id;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('application/x-dr-map', String(tab.id));
    }
}
function onTabDragOver(event: DragEvent, tab: TMapTab): void {
    if (draggingTab.value === null || draggingTab.value === tab.id) return;
    event.preventDefault();
    dropOnTab.value = tab.id;
}
function onTabDrop(tab: TMapTab): void {
    const moving = draggingTab.value;
    draggingTab.value = null;
    dropOnTab.value = null;
    if (moving === null || moving === tab.id) return;
    const ids = tabs.value.map((candidate) => candidate.id).filter((id) => id !== moving);
    ids.splice(ids.indexOf(tab.id), 0, moving);
    tabOrder.value = ids;
}

/** The ones on the bar (the current map always is), and the rest for the dropdown. */
const split = computed(() => {
    const fit = tabsThatFit(tabs.value.length, width, TAB_WIDTH);
    const shown = tabs.value.slice(0, fit);
    if (!shown.some((tab) => tab.id === map.id) && shown.length) {
        shown[shown.length - 1] = tabs.value.find((tab) => tab.id === map.id)!;
    }
    const ids = new Set(shown.map((tab) => tab.id));
    return { shown, rest: tabs.value.filter((tab) => !ids.has(tab.id)) };
});

const kept = computed(() => (page.props.pinned_maps ?? []).some((tab) => tab.id === map.id));

/** Keeps a map as a tab (until you close it). */
function keep(slug: string): void {
    if (page.props.auth?.user) updateMapUserSettings(slug, { is_pinned: true }, ['pinned_maps']);
}

// A map opened from the picker (or your very first map) becomes a tab; one reached from a link passes.
watch(
    () => map.slug,
    (slug) => {
        const asked = keepNextMap.value === slug;
        keepNextMap.value = null;
        if (!kept.value && (asked || !(page.props.pinned_maps ?? []).length)) keep(slug);
    },
    { immediate: true },
);

// Kills and notices: the map you're on is seen; the other tabs' maps are listened to.
watch(
    () => map.id,
    (id) => {
        currentMapId.value = id;
        clearTabAlerts(id);
    },
    { immediate: true },
);
const otherIds = computed(() => tabs.value.filter((tab) => tab.id !== map.id).map((tab) => tab.id));
watch(otherIds, (ids) => setTimeout(() => watchTabMaps(ids), 1_000), { immediate: true });
useIntervalFn(() => watchTabMaps(otherIds.value), 20_000);

function alertsOf(tab: TMapTab): TTabAlerts | undefined {
    return tab.id === map.id ? undefined : tabAlerts.value[tab.id];
}
const hiddenAlerts = computed(() => split.value.rest.some((tab) => alertsOf(tab)));

function open(tab: TMapTab): void {
    if (tab.id !== map.id) router.visit(show(tab.slug).url);
}

function close(tab: TMapTab): void {
    updateMapUserSettings(tab.slug, { is_pinned: false }, ['pinned_maps']);
    if (tab.id === map.id) {
        const next = tabs.value.find((other) => other.id !== tab.id);
        if (next) router.visit(show(next.slug).url);
    }
}
</script>

<template>
    <div class="flex shrink-0 items-center gap-0.5 overflow-hidden" :style="{ maxWidth: `${width}px` }">
        <button
            v-for="tab in split.shown"
            :key="tab.id"
            type="button"
            class="group relative flex h-7 shrink-0 items-center gap-1 rounded-md border px-2 text-[11px]"
            :class="
                tab.id !== map.id
                    ? 'border-transparent text-muted-foreground hover:bg-muted/60'
                    : kept
                      ? 'border-border bg-muted text-foreground'
                      : 'border-dashed border-muted-foreground/60 bg-muted text-foreground'
            "
            :style="{ width: `${TAB_WIDTH - 2}px`, ...(dropOnTab === tab.id ? { boxShadow: 'inset 2px 0 0 var(--color-primary)' } : {}), ...(draggingTab === tab.id ? { opacity: 0.5 } : {}) }"
            :title="[tab.name, tab.id === map.id && !kept ? '(passing tab: pin it to keep it)' : '', describeAlerts(alertsOf(tab)), 'Drag to move the tab'].filter(Boolean).join('\n')"
            draggable="true"
            @dragstart="onTabDragStart($event, tab)"
            @dragover="onTabDragOver($event, tab)"
            @dragleave="dropOnTab === tab.id && (dropOnTab = null)"
            @drop.prevent="onTabDrop(tab)"
            @dragend="draggingTab = null; dropOnTab = null"
            @click="open(tab)"
        >
            <span class="min-w-0 flex-1 truncate text-left" :class="tab.id === map.id && !kept ? 'italic' : ''">{{ tab.name }}</span>
            <span
                v-if="alertsOf(tab)?.kills"
                class="shrink-0 rounded-full px-1 text-[9px] leading-[14px] font-bold text-white group-hover:hidden"
                :class="alertsOf(tab)?.capitals ? 'animate-pulse bg-red-600' : 'bg-red-600/90'"
            >
                {{ alertsOf(tab)?.kills }}
            </span>
            <span v-else-if="alertsOf(tab)?.notices" class="size-2 shrink-0 rounded-full bg-amber-400 group-hover:hidden" />
            <span
                v-if="tab.id === map.id && !kept && $page.props.auth?.user"
                role="button"
                class="shrink-0 rounded text-muted-foreground hover:text-foreground"
                title="Keep this map as a tab"
                @click.stop="keep(tab.slug)"
            >
                <Pin class="size-3" />
            </span>
            <span
                v-else-if="tabs.length > 1"
                role="button"
                class="hidden shrink-0 rounded text-muted-foreground group-hover:block hover:text-foreground"
                :title="`Close the ${tab.name} tab (the map isn't touched)`"
                @click.stop="close(tab)"
            >
                <X class="size-3" />
            </span>
            <span v-if="tab.id === map.id" class="absolute right-2 -bottom-[7px] left-2 h-0.5 rounded bg-red-500" />
        </button>
        <DropdownMenu v-if="split.rest.length">
            <DropdownMenuTrigger as-child>
                <button type="button" class="flex h-7 shrink-0 items-center gap-0.5 rounded-md border border-dashed border-border px-1.5 text-[11px] text-muted-foreground hover:text-foreground">
                    +{{ split.rest.length }}<ChevronDown class="size-3" />
                    <span v-if="hiddenAlerts" class="size-1.5 rounded-full bg-red-500" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-52">
                <DropdownMenuLabel class="text-xs">Open maps</DropdownMenuLabel>
                <DropdownMenuItem v-for="tab in split.rest" :key="tab.id" class="text-xs" @select="open(tab)">
                    <span class="flex-1 truncate">{{ tab.name }}</span>
                    <span v-if="alertsOf(tab)?.kills" class="rounded-full bg-red-600 px-1 text-[9px] font-bold text-white" :title="describeAlerts(alertsOf(tab))">{{ alertsOf(tab)?.kills }}</span>
                    <span v-else-if="alertsOf(tab)?.notices" class="size-2 rounded-full bg-amber-400" :title="describeAlerts(alertsOf(tab))" />
                    <span role="button" class="text-muted-foreground hover:text-foreground" title="Close this tab" @click.stop="close(tab)"><X class="size-3" /></span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
