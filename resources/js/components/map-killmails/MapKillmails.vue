<script setup lang="ts">
import MapUserSettingController from '@/actions/App/Http/Controllers/MapUserSettingController';
import SettingsIcon from '@/components/icons/SettingsIcon.vue';
import Killmail from '@/components/map-killmails/Killmail.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import MapPanel from '@/components/ui/map-panel/MapPanel.vue';
import MapPanelContent from '@/components/ui/map-panel/MapPanelContent.vue';
import MapPanelHeader from '@/components/ui/map-panel/MapPanelHeader.vue';
import MapPanelHeaderActionButton from '@/components/ui/map-panel/MapPanelHeaderActionButton.vue';
import { useOnClient } from '@/composables/useOnClient';
import { getMapChannelName } from '@/const/channels';
import { KillmailReceivedEvent } from '@/const/events';
import { TKillmail, TMapUserSetting } from '@/types/models';
import { Deferred, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import type { AcceptableValue } from 'reka-ui';
import { Eraser } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const { map_killmails, map_id, map_slug, map_user_settings } = defineProps<{
    map_killmails?: TKillmail[];
    map_id: number;
    map_slug: string;
    map_user_settings: TMapUserSetting;
}>();

/**
 * Patch 26: Clear hides every killmail listed now, so only new ones show; just for you,
 * remembered in this browser per map. "Show cleared" brings them back.
 */
const clearedKey = `killmails-cleared:${map_id}`;
function readCleared(): Set<number> {
    try {
        const ids: unknown = JSON.parse(window.localStorage.getItem(clearedKey) ?? '[]');
        return new Set(Array.isArray(ids) ? ids.filter((id): id is number => typeof id === 'number') : []);
    } catch {
        return new Set();
    }
}
const cleared = ref<Set<number>>(readCleared());
const showCleared = ref(false);
watch(cleared, (ids) => {
    try {
        window.localStorage.setItem(clearedKey, JSON.stringify([...ids].slice(-500)));
    } catch {
        // Storage unavailable: it stays cleared for this visit.
    }
});
const shownKillmails = computed(() => (showCleared.value ? (map_killmails ?? []) : (map_killmails ?? []).filter((killmail) => !cleared.value.has(killmail.id))));
const clearedCount = computed(() => (map_killmails ?? []).filter((killmail) => cleared.value.has(killmail.id)).length);
function clearKillmails(): void {
    cleared.value = new Set([...cleared.value, ...(map_killmails ?? []).map((killmail) => killmail.id)]);
    showCleared.value = false;
}

type KillmailReceivedEvent = {
    killmail: TKillmail;
};

function handleFilterChange(value: AcceptableValue) {
    router.put(
        MapUserSettingController.update(map_slug).url,
        {
            killmail_filter: value as string,
        },
        {
            only: ['map_killmails', 'map_user_settings'],
            preserveState: true,
            preserveScroll: true,
        },
    );
}

useOnClient(() =>
    useEcho<KillmailReceivedEvent>(getMapChannelName(map_id), KillmailReceivedEvent, () => {
        router.reload({
            only: ['map_killmails'],
        });
    }),
);
</script>

<template>
    <MapPanel>
        <MapPanelHeader card-id="killmails">
            Killmails
            <span v-if="shownKillmails.length" class="ml-1 text-amber-400">{{ shownKillmails.length }}</span>
            <button
                v-if="clearedCount"
                type="button"
                class="ml-2 font-sans text-[11px] tracking-normal text-muted-foreground normal-case hover:text-foreground"
                @click="showCleared = !showCleared"
            >
                {{ showCleared ? 'Hide cleared' : `Show cleared (${clearedCount})` }}
            </button>
            <template #actions>
                <MapPanelHeaderActionButton :disabled="!shownKillmails.length" title="Clear: hide these, show only new killmails (just for you)" @click="clearKillmails">
                    <Eraser class="size-3.5" /> Clear
                </MapPanelHeaderActionButton>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <MapPanelHeaderActionButton size="icon">
                            <SettingsIcon />
                        </MapPanelHeaderActionButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent>
                        <DropdownMenuRadioGroup :model-value="map_user_settings.killmail_filter" @update:model-value="handleFilterChange">
                            <DropdownMenuRadioItem value="all"> All killmails</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="jspace"> J-Space killmails</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="kspace"> K-Space killmails</DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </MapPanelHeader>
        <MapPanelContent>
            <Deferred data="map_killmails">
                <div class="@container">
                    <template v-if="shownKillmails.length">
                        <div class="grid grid-cols-[1.25rem_1.25rem_1.25rem_auto_1.25rem_auto_auto_1.25rem_1.25rem_2rem_auto_auto] gap-x-2">
                            <TransitionGroup name="list">
                                <Killmail v-for="killmail in shownKillmails" :key="killmail.id" :killmail="killmail" />
                            </TransitionGroup>
                        </div>
                    </template>
                    <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-4">
                        <p class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/60 uppercase">{{ clearedCount ? 'No new killmails' : 'No killmails' }}</p>
                    </div>
                </div>
                <template #fallback>
                    <div class="flex h-full animate-pulse items-center justify-center gap-2 p-4">
                        <p class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/60 uppercase">Loading killmails...</p>
                    </div>
                </template>
            </Deferred>
        </MapPanelContent>
    </MapPanel>
</template>

<style scoped>
.list-move, /* apply transition to moving elements */
.list-enter-active,
.list-leave-active {
    transition: all 0.5s ease;
}

.list-enter-from,
.list-leave-to {
    opacity: 0;
    transform: translateX(30px);
}

/* ensure leaving items are taken out of layout flow so that moving
   animations can be calculated correctly. */
.list-leave-active {
    position: absolute;
    opacity: 0;
    transition-duration: 0ms;
}
</style>
