<script setup lang="ts">
import { focusedClipboard } from '@/lib/popOut';
import { Button } from '@/components/ui/button';
import usePermission from '@/composables/usePermission';
import { clipboardAllowed } from '@/composables/useClipboardSetting';
import { displayAlias } from '@/lib/alias';
import { visibleBookmarkName } from '@/lib/bookmark';
import { chainAliases } from '@/lib/combat';
import { countsUp } from '@/lib/numberingHubs';
import { oldChainRows, type TOldChainRow } from '@/lib/oldChainRows';
import { signatureToast as toast } from '@/lib/signatureToast';
import { changeNames } from '@/map/actions/names';
import { linkedForwardBookmark, wayBackBookmark } from '@/map/holeBookmark';
import { useMapStore } from '@/map/store/mapStore';
import { Check, Copy, History } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Patch 39: an old chain reconnected here. The mapper never renames it by itself: copy the
 * new name, re-bookmark in game, press Done (only that row changes; the old name floats as
 * "was"), or Keep old.
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
const scheme = computed(() => store?.meta.value?.bookmark_alias_scheme);

const rows = computed(() => {
    const system = here.value;
    if (!store || !system) return [];
    const systems = [...store.systems.values()];
    const meta = store.meta.value;
    return oldChainRows({
        here: system,
        systems,
        connections: [...store.connections.values()],
        parentOf: store.bandLayout.value?.parentOf ?? new Map(),
        taken: [
            ...chainAliases(systems, system),
            ...(system.pending_holes ?? []).map((hole) => hole.alias).filter((alias): alias is string => Boolean(alias)),
        ],
        scheme: meta?.bookmark_alias_scheme,
        ignoredAlias: meta?.bookmark_ignored_alias,
        countUp: countsUp(system),
    }).map((row) => ({ ...row, bookmark: bookmarkFor(row) }));
});

/** Self: the way back with the new name ("··*@Z2111 FFT C6"). Hole: the forward bookmark ("·Z21111 QXP C4 old=A11~1917"). */
function bookmarkFor(row: TOldChainRow): string {
    const system = here.value;
    if (!store || !system) return '';
    if (row.kind === 'self') {
        const via = store.systems.get(row.viaSystemId);
        return via ? wayBackBookmark(store, { ...system, alias: row.to }, via) : '';
    }
    const target = store.systems.get(row.systemId);
    return linkedForwardBookmark(store, system, row.systemId, row.to, target?.alias ?? null);
}

function copy(name: string): void {
    if (!name) return;
    if (!clipboardAllowed()) {
        toast.info('Bookmark name', { description: visibleBookmarkName(name) });
        return;
    }
    focusedClipboard()
        .writeText(name)
        .then(() => toast.success('Copied bookmark to clipboard', { description: visibleBookmarkName(name) }))
        .catch(() => toast.error('Could not copy: click the mapper first.'));
}

function done(row: TOldChainRow): void {
    const from = displayAlias(row.from, scheme.value);
    const to = displayAlias(row.to, scheme.value);
    if (row.kind === 'self') {
        changeNames(mapSolarsystemId, { action: 'adopt', signature_id: row.signatureId }, `Rename ${from} to ${to}`, () =>
            toast.success(`This system is now ${to}`, { description: `${from} floats above the card as "was". The holes beyond follow one row at a time.` }),
        );
        return;
    }
    changeNames(mapSolarsystemId, { action: 'hole', signature_id: row.signatureId, name: row.to }, `Rename ${from} to ${to}`, () =>
        toast.success(`${from} is now ${to}`, { description: 'Only that system moved; the ones beyond keep their old names for now.' }),
    );
}

function keepOld(): void {
    const system = here.value;
    if (!system) return;
    changeNames(mapSolarsystemId, { action: 'keep' }, `Keep ${displayAlias(system.alias, scheme.value)}`, () =>
        toast.info(`Kept ${displayAlias(system.alias, scheme.value)}`, { description: 'The new number still floats above the card.' }),
    );
}
</script>

<template>
    <div v-if="rows.length" class="border-b border-violet-500/30 bg-violet-500/10 px-3 py-1.5 text-xs">
        <p class="mb-1 flex items-center gap-1.5 font-medium text-violet-300" title="The mapper never renames an old chain by itself">
            <History class="size-3.5" />
            Old chain here: re-bookmark in game, then press Done
        </p>
        <div v-for="row in rows" :key="`${row.kind}:${row.signatureId}`" class="flex items-center gap-2 py-0.5">
            <span class="max-w-20 shrink-0 truncate font-mono font-bold" :title="displayAlias(row.from, scheme)">
                {{ row.kind === 'self' ? 'here' : displayAlias(row.from, scheme) }}
            </span>
            <span class="min-w-0 flex-1 truncate font-mono" :title="row.bookmark">
                → {{ displayAlias(row.to, scheme) }}
                <span class="text-muted-foreground">{{ row.bookmark ? `· ${visibleBookmarkName(row.bookmark)}` : '' }}</span>
            </span>
            <Button
                variant="ghost"
                size="icon"
                class="size-6"
                :title="row.kind === 'self' ? 'Copy the way back with the new name' : 'Copy the bookmark with the new name'"
                @click="copy(row.bookmark)"
            >
                <Copy class="size-3.5" />
            </Button>
            <template v-if="canEdit">
                <Button variant="outline" size="sm" class="h-6 px-2 text-xs" :title="`Re-bookmarked in game: rename to ${displayAlias(row.to, scheme)}`" @click="done(row)">
                    <Check class="size-3.5" />
                    Done
                </Button>
                <Button v-if="row.kind === 'self'" variant="ghost" size="sm" class="h-6 px-2 text-xs" title="Keep the old name; the new number keeps floating" @click="keepOld">
                    Keep old
                </Button>
            </template>
        </div>
    </div>
</template>
