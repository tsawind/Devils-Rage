<script setup lang="ts">
import { ContextMenuContent, ContextMenuItem, ContextMenuLabel, ContextMenuSeparator } from '@/components/ui/context-menu';
import usePermission from '@/composables/usePermission';
import { suggestAlias } from '@/lib/alias';
import { buildSignatureBookmark, visibleBookmarkName } from '@/lib/bookmark';
import { chainAliases } from '@/lib/combat';
import type { TPlaceholder } from '@/lib/placeholders';
import { deleteSignature } from '@/map/actions/deleteSignature';
import { updateSignature } from '@/map/actions/updateSignature';
import { useMapStore } from '@/map/store/mapStore';
import { show } from '@/routes/maps';
import type { TSignature } from '@/types/models';
import { router } from '@inertiajs/vue3';
import { ClipboardCopy, ListTree, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Right-click a placeholder system (patch 12): copy its bookmark (locks its
 * number, like the signature list), open it in the signature list (Static and
 * Set number live there), or delete the signature.
 */
const { placeholder } = defineProps<{ placeholder: TPlaceholder }>();

const store = useMapStore();
const { canEdit } = usePermission();

const parent = computed(() => store.systems.get(placeholder.parentId) ?? null);
const hole = computed(() => parent.value?.pending_holes?.find((candidate) => candidate.id === placeholder.signatureId) ?? null);

/** The number this hole gets when copied: its own, the planned one, or (combat chain) the next free one. */
const claim = computed(() => {
    const system = parent.value;
    if (!system || hole.value?.alias) return hole.value?.alias ?? null;
    if (placeholder.alias) return placeholder.alias;
    const meta = store.meta.value;
    const systems = [...store.systems.values()];
    const locked = (system.pending_holes ?? []).map((candidate) => candidate.alias).filter((alias): alias is string => Boolean(alias));
    return suggestAlias({
        parentAlias: system.alias,
        targetIsWormhole: true,
        originIsWormhole: true,
        aliases: [...chainAliases(systems, system), ...locked],
        scheme: meta?.bookmark_alias_scheme,
        ignoredAlias: meta?.bookmark_ignored_alias,
        combatHome: Boolean(system.combat_home),
    });
});

const bookmark = computed(() => {
    const system = parent.value;
    const meta = store.meta.value;
    if (!system || !hole.value || !meta) return '';
    return buildSignatureBookmark({
        signature: {
            signature_id: hole.value.signature_id,
            ship_size: null,
            mass_status: null,
            lifetime: 'healthy',
            wormhole: { name: hole.value.wormhole },
            signature_type: { target_class: hole.value.target_class },
            is_static: hole.value.is_static,
            is_wandering: hole.value.is_wandering,
        },
        currentSystem: {
            alias: system.alias,
            class: system.solarsystem.class,
            combatHome: Boolean(system.combat_home),
            combatColor: system.combat_color ?? null,
        },
        aliases: chainAliases([...store.systems.values()], system),
        formats: meta,
        plannedAlias: claim.value,
    });
});

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
}
</script>

<template>
    <ContextMenuContent>
        <ContextMenuLabel class="font-mono text-xs text-muted-foreground">{{ placeholder.label || 'Not numbered yet' }} · {{ placeholder.detail }}</ContextMenuLabel>
        <ContextMenuSeparator />
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
