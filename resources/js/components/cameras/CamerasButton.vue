<script setup lang="ts">
import MapCameraController from '@/actions/App/Http/Controllers/MapCameraController';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useCameras } from '@/composables/useCameras';
import { openCameraPreview } from '@/lib/cameras/preview';
import { appSignal, inDesktopApp } from '@/lib/desktopApp';
import { Video } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Patch 62: the bar's 📹. How many cameras are on (purple dot while a hole just activated);
 * opens the list (click one: its live picture), the camera wall and "Share a camera".
 */
const { mapSlug, canShare = false } = defineProps<{ mapSlug: string; canShare?: boolean }>();
const { enabled, cameras, pipeActivity } = useCameras();
const busy = computed(() => pipeActivity.value.size > 0);

/**
 * Patch 62c: the desktop app shows our pages itself and can't pick a window to share yet, so
 * there "Share a camera" (and the wall, so the map stays) open in the normal browser (the app's open-in-browser signal).
 */
const inApp = inDesktopApp();
function inBrowser(event: MouseEvent, path: string): void {
    if (!inApp) return;
    event.preventDefault();
    appSignal(`open?url=${encodeURIComponent(new URL(path, window.location.origin).href)}`);
}

const KIND_LABEL: Record<string, string> = { hole: 'Hole', structure: 'Structure', other: 'Local / other', pi: 'PI scan', screen: 'Whole screen' };
</script>

<template>
    <Popover v-if="enabled">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="relative flex items-center gap-1 rounded border border-border/50 bg-background p-1.5 text-muted-foreground hover:border-border hover:text-foreground"
                :aria-label="`Cameras (${cameras.length} on)`"
                :title="`Cameras (${cameras.length} on)`"
            >
                <Video class="size-4" :class="cameras.length ? 'text-purple-400' : ''" />
                <span v-if="cameras.length" class="text-[11px] leading-none font-semibold text-purple-300">{{ cameras.length }}</span>
                <span v-if="busy" class="absolute -top-0.5 -right-0.5 size-2 animate-ping rounded-full bg-fuchsia-500" />
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-80 max-w-[95vw] p-3" align="start">
            <p class="mb-2 text-sm font-semibold">Cameras</p>
            <div v-if="cameras.length" class="mb-3 flex max-h-64 flex-col gap-1 overflow-auto">
                <button
                    v-for="camera in cameras"
                    :key="camera.id"
                    type="button"
                    class="flex items-center gap-2 rounded px-2 py-1 text-left text-xs hover:bg-muted"
                    @click="(event: MouseEvent) => openCameraPreview(camera.id, event.clientX, event.clientY)"
                >
                    <span class="size-2 shrink-0 rounded-full" :class="camera.status === 'live' ? 'bg-red-500' : 'bg-neutral-500'" />
                    <span class="truncate font-medium">{{ camera.name }}</span>
                    <span class="ml-auto shrink-0 text-muted-foreground">{{ KIND_LABEL[camera.kind] ?? camera.kind }} · {{ camera.owner }}</span>
                </button>
            </div>
            <p v-else class="mb-3 text-xs text-muted-foreground">No cameras on right now.</p>
            <div class="flex gap-2">
                <a
                    :href="MapCameraController.wall(mapSlug).url"
                    target="_blank"
                    rel="noopener"
                    class="flex-1 rounded border border-border px-2 py-1 text-center text-xs hover:bg-muted"
                    @click="(event: MouseEvent) => inBrowser(event, MapCameraController.wall(mapSlug).url)"
                    >Camera wall{{ inApp ? ' ↗' : '' }}</a
                >
                <a
                    v-if="canShare"
                    :href="MapCameraController.share(mapSlug).url"
                    target="_blank"
                    rel="noopener"
                    class="flex-1 rounded border border-purple-700 bg-purple-900/40 px-2 py-1 text-center text-xs text-purple-100 hover:bg-purple-800/60"
                    :title="inApp ? 'Opens in your browser (Chrome / Edge): the app can\'t share a window yet' : undefined"
                    @click="(event: MouseEvent) => inBrowser(event, MapCameraController.share(mapSlug).url)"
                    >Share a camera{{ inApp ? ' ↗' : '' }}</a
                >
            </div>
        </PopoverContent>
    </Popover>
</template>
