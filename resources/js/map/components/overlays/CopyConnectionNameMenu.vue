<script setup lang="ts">
import { ContextMenuItem, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger } from '@/components/ui/context-menu';
import { formatBookmarkName, visibleBookmarkName } from '@/lib/bookmark';
import { connectionFlag } from '@/lib/chainNumbering';
import { useMapStore } from '@/map/store/mapStore';
import { TMapConnection, TMapSolarsystem } from '@/pages/maps';
import { Copy } from 'lucide-vue-next';
import { computed } from 'vue';
import { toast } from 'vue-sonner';

const { map_connection } = defineProps<{
    map_connection: TMapConnection & { source: TMapSolarsystem; target: TMapSolarsystem };
}>();

const store = useMapStore();

const source_signature = computed(() => {
    if (!map_connection.signatures?.length) return null;
    return map_connection.signatures.find((sig) => sig.map_solarsystem_id === map_connection.source.id) || null;
});

const target_signature = computed(() => {
    if (!map_connection.signatures?.length) return null;
    return map_connection.signatures.find((sig) => sig.map_solarsystem_id === map_connection.target.id) || null;
});

const source_name = computed(() =>
    formatBookmarkName(
        map_connection.source,
        {
            signatureId: target_signature.value?.signature_id,
            shipSize: map_connection.ship_size,
            massStatus: map_connection.mass_status,
            lifetime: map_connection.lifetime_status,
            wormholeCode: target_signature.value?.wormhole?.name,
            classSuffix: connectionFlag({
                is_static: target_signature.value?.is_static,
                is_wandering: target_signature.value?.is_wandering,
                wormholeName: target_signature.value?.wormhole?.name,
            }),
        },
        store.meta.value,
        map_connection.target.alias,
        map_connection.target.alias,
        map_connection.target.solarsystem.class,
        map_connection.target.combat_color ?? null,
    ),
);

const target_name = computed(() =>
    formatBookmarkName(
        map_connection.target,
        {
            signatureId: source_signature.value?.signature_id,
            shipSize: map_connection.ship_size,
            massStatus: map_connection.mass_status,
            lifetime: map_connection.lifetime_status,
            wormholeCode: source_signature.value?.wormhole?.name,
            classSuffix: connectionFlag({
                is_static: source_signature.value?.is_static,
                is_wandering: source_signature.value?.is_wandering,
                wormholeName: source_signature.value?.wormhole?.name,
            }),
        },
        store.meta.value,
        map_connection.source.alias,
        map_connection.source.alias,
        map_connection.source.solarsystem.class,
        map_connection.source.combat_color ?? null,
    ),
);

function copyNameToClipboard(value: string) {
    navigator.clipboard.writeText(value);
    toast.success('Copied to clipboard', { description: 'You successfully copied the name to your clipboard.' });
}
</script>

<template>
    <ContextMenuSub>
        <ContextMenuSubTrigger>
            <Copy class="size-4" />
            Copy name
        </ContextMenuSubTrigger>
        <ContextMenuSubContent>
            <ContextMenuItem @select="copyNameToClipboard(source_name)">
                {{ visibleBookmarkName(source_name) }}
            </ContextMenuItem>
            <ContextMenuItem @select="copyNameToClipboard(target_name)">
                {{ visibleBookmarkName(target_name) }}
            </ContextMenuItem>
        </ContextMenuSubContent>
    </ContextMenuSub>
</template>

<style scoped></style>
