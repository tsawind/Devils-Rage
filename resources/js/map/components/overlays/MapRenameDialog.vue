<script setup lang="ts">
import { focusedClipboard } from '@/lib/popOut';
import RenameHoleDialog from '@/components/signatures/RenameHoleDialog.vue';
import { clipboardAllowed } from '@/composables/useClipboardSetting';
import { mapRenameRequest } from '@/composables/useMapRename';
import { aliasesBelow, displayAlias } from '@/lib/alias';
import { visibleBookmarkName } from '@/lib/bookmark';
import { renameHolder } from '@/lib/renameHolder';
import { signatureToast as toast } from '@/lib/signatureToast';
import { changeNames, type TRenameMode } from '@/map/actions/names';
import { linkedForwardBookmark, pendingHoleBookmark, wayBackBookmark } from '@/map/holeBookmark';
import { useMapStore } from '@/map/store/mapStore';
import MapSolarsystems from '@/routes/map-solarsystems';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Patch 39: double-click a dotted box or a system on the map to rename it, with the same
 * popup as the signature list (bookmarks before → after; a name in use can be swapped,
 * given the next number, or kept floating).
 */
const store = useMapStore();

const request = computed(() => mapRenameRequest.value);
const open = computed({
    get: () => request.value !== null,
    set: (value: boolean) => {
        if (!value) mapRenameRequest.value = null;
    },
});

const parent = computed(() => (request.value?.parentId != null ? (store.systems.get(request.value.parentId) ?? null) : null));
const target = computed(() => (request.value?.targetId != null ? (store.systems.get(request.value.targetId) ?? null) : null));
const meta = computed(() => store.meta.value);
const pending = computed(() => parent.value?.pending_holes?.find((hole) => hole.id === request.value?.signatureId) ?? null);
/** The parent's holes: unjumped, and jumped (on the parent's side of its pipes). */
const parentHoles = computed(() => {
    const system = parent.value;
    if (!system) return [];
    const jumped = [...store.connections.values()].flatMap((connection) => (connection.signatures ?? []).filter((signature) => signature.map_solarsystem_id === system.id));
    return [...(system.pending_holes ?? []), ...jumped];
});
const planned = computed(() => store.allPlaceholders.value.find((placeholder) => placeholder.signatureId === request.value?.signatureId)?.alias ?? null);
const currentName = computed(() => target.value?.alias ?? pending.value?.alias ?? planned.value ?? '');

const others = computed(() => {
    const names = [...store.systems.values()].filter((system) => system.id !== target.value?.id).flatMap((system) => (system.alias ? [system.alias] : []));
    const below = new Set(currentName.value ? aliasesBelow(names, currentName.value, meta.value?.bookmark_ignored_alias) : []);
    const holes = parentHoles.value.filter((hole) => hole.id !== request.value?.signatureId).flatMap((hole) => (hole.alias ? [hole.alias] : []));
    return [...names.filter((name) => !below.has(name)), ...holes];
});

function bookmarks(name: string): { thisSide: string; wayBack: string | null } {
    const from = parent.value;
    if (!from) {
        return { thisSide: displayAlias(name), wayBack: null };
    }
    if (target.value) {
        return { thisSide: linkedForwardBookmark(store, from, target.value.id, name), wayBack: wayBackBookmark(store, { ...target.value, alias: name }, from) };
    }
    return { thisSide: pending.value ? pendingHoleBookmark(store, from, pending.value, name) : name, wayBack: null };
}

function holderOf(name: string): { label: string; next: string | null } | null {
    const from = parent.value;
    const signatureId = request.value?.signatureId;
    if (!from || !signatureId) return null;
    return renameHolder({
        name,
        signatureId,
        targetId: target.value?.id ?? null,
        parent: from,
        parentHoles: parentHoles.value,
        systems: [...store.systems.values()],
        scheme: meta.value?.bookmark_alias_scheme,
        ignoredAlias: meta.value?.bookmark_ignored_alias,
    });
}

function rename(name: string, copy: boolean, mode: TRenameMode, next: string | null): void {
    const current = request.value;
    if (!current) return;
    const bookmark = bookmarks(name).thisSide;
    const copying = copy && clipboardAllowed() && Boolean(parent.value);
    if (copying) focusedClipboard().writeText(bookmark).catch(() => undefined);
    const done = () =>
        toast.success(`Renamed to ${displayAlias(name)}`, { description: copying ? `Copied ${visibleBookmarkName(bookmark)}` : visibleBookmarkName(bookmark) });

    if (current.parentId != null && current.signatureId != null) {
        changeNames(current.parentId, { action: 'rename', signature_id: current.signatureId, name, mode, next }, `Rename to ${displayAlias(name)}`, done);
        return;
    }
    // No hole leads to it (home, a side chain start): just the system's own name.
    if (current.targetId != null) {
        router.put(
            MapSolarsystems.update(current.targetId).url,
            { alias: name },
            { preserveScroll: true, preserveState: true, only: ['map', 'selected_map_solarsystem'], onSuccess: done, onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not rename it.') },
        );
    }
}
</script>

<template>
    <RenameHoleDialog
        v-if="open"
        v-model:open="open"
        :current-name="currentName"
        :others="others"
        :ignored-alias="meta?.bookmark_ignored_alias ?? null"
        :bookmarks="bookmarks"
        :holder-of="request?.signatureId ? holderOf : null"
        :jumped="Boolean(target && parent)"
        @rename="rename"
    />
</template>
