<script setup lang="ts">
import { Button } from '@/components/ui/button';
import usePermission from '@/composables/usePermission';
import { displayAlias } from '@/lib/alias';
import { visibleBookmarkName } from '@/lib/bookmark';
import { cleanupRows } from '@/lib/chainCleanup';
import { chainAliases, combatColorLabel } from '@/lib/combat';
import { convertCleanupSystem, finishCleanupReturn } from '@/map/actions/chainCleanup';
import { linkedForwardBookmark, wayBackBookmark } from '@/map/holeBookmark';
import { useMapStore } from '@/map/store/mapStore';
import { Brush, Check, Copy } from 'lucide-vue-next';
import { computed } from 'vue';
import { signatureToast as toast } from '@/lib/signatureToast';

/**
 * Patch 14: cleanup rows for the system you are looking at (top of its
 * signature list). The way back to re-bookmark when this system was just
 * converted, and every chain system hanging off it: "1 → D12". Copy the new
 * name, re-bookmark in game, press Done: only that one system converts.
 */
const { mapSolarsystemId } = defineProps<{ mapSolarsystemId: number }>();

const { canEdit } = usePermission();

const store = (() => {
    try {
        return useMapStore();
    } catch {
        return null;
    }
})();

const here = computed(() => store?.systems.get(mapSolarsystemId) ?? null);

const parent = computed(() => {
    const id = store?.bandLayout.value?.parentOf.get(mapSolarsystemId);
    return id !== undefined ? (store?.systems.get(id) ?? null) : null;
});

const wayBack = computed(() => {
    const system = here.value;
    if (!store || !system?.cleanup_return_pending || !parent.value) return null;
    return wayBackBookmark(store, system, parent.value);
});

const rows = computed(() => {
    const system = here.value;
    if (!store || !system) return [];
    const systems = [...store.systems.values()];
    const meta = store.meta.value;
    return cleanupRows({
        here: system,
        systems,
        connections: [...store.connections.values()],
        parentOf: store.bandLayout.value?.parentOf ?? new Map(),
        taken: [
            ...chainAliases(systems, system),
            ...systems.map((candidate) => candidate.alias).filter((alias): alias is string => Boolean(alias)),
            // Numbers locked on this system's unjumped holes (copied or armed) are taken too.
            ...(system.pending_holes ?? []).map((hole) => hole.alias).filter((alias): alias is string => Boolean(alias)),
        ],
        scheme: meta?.bookmark_alias_scheme,
        ignoredAlias: meta?.bookmark_ignored_alias,
    }).map((row) => {
        const child = store.systems.get(row.systemId);
        return {
            ...row,
            color: combatColorLabel(child?.combat_color) ?? '',
            bookmark: linkedForwardBookmark(store, system, row.systemId, row.to),
        };
    });
});

function copy(name: string): void {
    navigator.clipboard
        .writeText(name)
        .then(() => toast.success('Copied bookmark to clipboard', { description: visibleBookmarkName(name) }))
        .catch(() => toast.error('Could not copy: click the mapper first.'));
}

function convert(row: { systemId: number; to: string; from: string }): void {
    convertCleanupSystem(row.systemId, mapSolarsystemId, row.to, () =>
        toast.success(`${displayAlias(row.from) || 'System'} is now ${displayAlias(row.to)}`, { description: 'Only this system moved; the ones further out keep their names for now.' }),
    );
}
</script>

<template>
    <div v-if="wayBack || rows.length" class="border-b border-sky-500/30 bg-sky-500/10 px-3 py-1.5 text-xs">
        <p class="mb-1 flex items-center gap-1.5 font-medium text-sky-300">
            <Brush class="size-3.5" />
            Cleanup here: re-bookmark in game, then press Done
        </p>
        <div v-if="wayBack" class="flex items-center gap-2 py-0.5">
            <span class="w-8 shrink-0 font-mono font-bold">*</span>
            <span class="min-w-0 flex-1 truncate font-mono" :title="wayBack">→ {{ visibleBookmarkName(wayBack) }}</span>
            <Button variant="ghost" size="icon" class="size-6" title="Copy" @click="copy(wayBack)"><Copy class="size-3.5" /></Button>
            <Button v-if="canEdit" variant="outline" size="sm" class="h-6 px-2 text-xs" @click="finishCleanupReturn(mapSolarsystemId)">
                <Check class="size-3.5" />
                Done
            </Button>
        </div>
        <div v-for="row in rows" :key="row.systemId" class="flex items-center gap-2 py-0.5">
            <span class="w-8 shrink-0 font-mono font-bold" :title="`${row.color} ${displayAlias(row.from)}`">{{ row.digit }}</span>
            <span class="min-w-0 flex-1 truncate font-mono" :title="row.bookmark">→ {{ visibleBookmarkName(row.bookmark) || displayAlias(row.to) }}</span>
            <Button variant="ghost" size="icon" class="size-6" title="Copy" @click="copy(row.bookmark)"><Copy class="size-3.5" /></Button>
            <Button v-if="canEdit" variant="outline" size="sm" class="h-6 px-2 text-xs" @click="convert(row)">
                <Check class="size-3.5" />
                Done
            </Button>
        </div>
    </div>
</template>
