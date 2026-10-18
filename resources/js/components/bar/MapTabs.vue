<script setup lang="ts">
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { tabsThatFit } from '@/lib/characterColor';
import { updateMapUserSettings } from '@/map/api';
import { show } from '@/routes/maps';
import type { AppPageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown, X } from 'lucide-vue-next';
import { computed, watch } from 'vue';

/**
 * Patch 41: map tabs, like a browser's. The maps you opened (kept per person: they're the
 * maps pinned to your bar), the one you're on underlined in red; × closes a tab (the map
 * itself isn't touched). As many as fit in `width`; the rest go into "+N ▾".
 */
const { map, width } = defineProps<{ map: { id: number; name: string; slug: string }; width: number }>();

type TMapTab = { id: number; name: string; slug: string };
const page = usePage<AppPageProps>();

/** One tab takes this many pixels (fixed, so the count is easy to work out). */
const TAB_WIDTH = 92;

const tabs = computed<TMapTab[]>(() => {
    const pinned = page.props.pinned_maps ?? [];
    return pinned.some((tab) => tab.id === map.id) ? pinned : [{ id: map.id, name: map.name, slug: map.slug }, ...pinned];
});

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

// A map you open becomes a tab (kept until you close it).
watch(
    () => map.slug,
    (slug) => {
        if (!page.props.auth?.user) return;
        if (!(page.props.pinned_maps ?? []).some((tab) => tab.slug === slug)) updateMapUserSettings(slug, { is_pinned: true }, ['pinned_maps']);
    },
    { immediate: true },
);

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
            :class="tab.id === map.id ? 'border-border bg-muted text-foreground' : 'border-transparent text-muted-foreground hover:bg-muted/60'"
            :style="{ width: `${TAB_WIDTH - 2}px` }"
            :title="tab.name"
            @click="open(tab)"
        >
            <span class="min-w-0 flex-1 truncate text-left">{{ tab.name }}</span>
            <span
                v-if="tabs.length > 1"
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
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-52">
                <DropdownMenuLabel class="text-xs">Open maps</DropdownMenuLabel>
                <DropdownMenuItem v-for="tab in split.rest" :key="tab.id" class="text-xs" @select="open(tab)">
                    <span class="flex-1 truncate">{{ tab.name }}</span>
                    <span role="button" class="text-muted-foreground hover:text-foreground" title="Close this tab" @click.stop="close(tab)"><X class="size-3" /></span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
