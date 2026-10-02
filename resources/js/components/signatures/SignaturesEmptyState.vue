<script setup lang="ts">
import CombatControls from '@/components/combat/CombatControls.vue';

/** The Signatures panel shows the combat buttons here too; other cards reusing this empty state don't. */
const { showCombat = false } = defineProps<{ showCombat?: boolean }>();
import MapPanel from '@/components/ui/map-panel/MapPanel.vue';
import MapPanelContent from '@/components/ui/map-panel/MapPanelContent.vue';
import MapPanelHeader from '@/components/ui/map-panel/MapPanelHeader.vue';
</script>

<template>
    <MapPanel>
        <MapPanelHeader>
            <CombatControls v-if="showCombat" class="mr-2" />
            Signatures
        </MapPanelHeader>
        <MapPanelContent>
            <div class="flex h-full flex-col items-center justify-center gap-2 p-4">
                <p class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/60 uppercase">Select a system</p>
            </div>
        </MapPanelContent>
    </MapPanel>
</template>
