<script setup lang="ts">
import MapBackgroundImageController from '@/actions/App/Http/Controllers/MapBackgroundImageController';
import MapDefaultBackgroundController from '@/actions/App/Http/Controllers/MapDefaultBackgroundController';
import usePermission from '@/composables/usePermission';
import HubLayoutIcon from '@/components/icons/HubLayoutIcon.vue';
import BackgroundImageIcon from '@/components/icons/BackgroundImageIcon.vue';
import MinusIcon from '@/components/icons/MinusIcon.vue';
import PlusIcon from '@/components/icons/PlusIcon.vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useLayout } from '@/composables/useLayout';
import { TMapBackgroundMode, useMapBackground } from '@/composables/useMapBackground';
import { updateMapUserSettings } from '@/map/actions/updateMapUserSettings';
import { useMapStore } from '@/map/store/mapStore';
import { router } from '@inertiajs/vue3';
import { CircleDashed, ImageUp, Loader2, Trash2, Volume1, Volume2, VolumeX, Waypoints, Workflow } from 'lucide-vue-next';
import { useMapEffects } from '@/composables/useMapEffects';
import { pulseKill } from '@/composables/useKillPulses';
import { playKillSound, playTargetAcquired } from '@/lib/rageSound';
import { computed, ref, useTemplateRef, type Component } from 'vue';
import { toast } from 'vue-sonner';

/**
 * The bottom-right map options cluster (placement mode, background image,
 * zoom). Ported from the old tree onto the store: the effective layout and map
 * metadata come from the store, and zoom writes go through useLayout's
 * setLayout (cookie + debounced server sync) — MapRoot mirrors the layout's
 * scale back into the store, exactly like the old mapState.scale flow.
 */
type TMapLayoutMode = 'manual' | 'tree';

const store = useMapStore();

const { layout, setLayout } = useLayout();

const scale = computed(() => store.scale.value);

function setScale(newScale: number) {
    const roundedScale = Math.round(newScale * 10) / 10;
    if (roundedScale < 0.5 || roundedScale > 2) return;
    setLayout({
        ...layout.value,
        scale: roundedScale,
    });
}

const effective_layout = computed(() => store.effectiveLayout.value);

const layoutModes: { value: TMapLayoutMode; icon: Component; label: string }[] = [
    { value: 'manual', icon: Waypoints, label: 'Custom placement' },
    { value: 'tree', icon: Workflow, label: 'Automatic placement' },
];

// A personal override of the map's default placement. Picking the map default clears the
// override so the viewer follows the map again.
function setPlacement(mode: TMapLayoutMode) {
    const meta = store.meta.value;
    if (!meta) return;
    updateMapUserSettings(meta.slug, { layout_override: mode === meta.layout ? null : mode }, ['map_user_settings']);
}

const allowLayoutOverride = computed(() => store.meta.value?.allow_layout_override ?? false);
const mapSlug = computed(() => store.meta.value?.slug ?? null);

const { backgroundImageUrl, backgroundMode, canUpload, backgroundChoice, ownBackgroundUrl, mapBackgroundUrl } = useMapBackground();
const { canManageAccess } = usePermission();

// Patch 37: which background you see, and the map's default (managers).
const choiceOptions: { value: 'map' | 'none' | 'own'; label: string; hint: string }[] = [
    { value: 'map', label: 'Map default', hint: "The map's background, set by its managers" },
    { value: 'none', label: 'None', hint: 'No picture behind the map' },
    { value: 'own', label: 'My own', hint: 'Your own picture: only you see it' },
];
const defaultInput = useTemplateRef<HTMLInputElement>('default-input');

function setChoice(choice: 'map' | 'none' | 'own'): void {
    if (!mapSlug.value) return;
    updateMapUserSettings(mapSlug.value, { background_choice: choice }, ['map_user_settings']);
}

function sendDefault(data: Record<string, File | string>): void {
    if (!mapSlug.value) return;
    router.post(MapDefaultBackgroundController.store(mapSlug.value).url, data, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        only: ['map_user_settings'],
        onStart: () => (is_uploading.value = true),
        onFinish: () => (is_uploading.value = false),
        onSuccess: () => toast.success('Map default background set'),
        onError: (errors) => toast.error(errors.background_image ?? 'Could not set the map default background'),
    });
}

function onDefaultSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file) sendDefault({ background_image: file });
}

function useOwnAsDefault(): void {
    sendDefault({ from_own: '1' });
}

function removeDefault(): void {
    if (!mapSlug.value) return;
    router.delete(MapDefaultBackgroundController.destroy(mapSlug.value).url, { preserveScroll: true, preserveState: true, only: ['map_user_settings'] });
}

// Patch 37: the speaker menu and the full-screen background (per browser).
const { effects } = useMapEffects();

/** Patch 37: every mapper sound, to hear them all (only on this screen). */
const soundTests: { label: string; play: () => void }[] = [
    { label: '🎯 Target acquired (Rage Roll)', play: playTargetAcquired },
    { label: '💥 Kill', play: () => playKillSound(false) },
    { label: '☠ Capital kill', play: () => playKillSound(true) },
];

/** The full kill effect with its sound on the home system, on this screen only. */
function testKill(): void {
    const home = store.meta.value?.home_solarsystem_id ?? null;
    if (home === null) return;
    pulseKill(home, Date.now(), { ship: 'Test kill' }, { sound: true, force: true });
}

const fileInput = useTemplateRef<HTMLInputElement>('file-input');
const is_dragging = ref(false);
const is_uploading = ref(false);

const modeOptions: { value: TMapBackgroundMode; label: string; hint: string }[] = [
    { value: 'grid', label: 'Move with map', hint: 'Spans the grid, pans and zooms with the systems' },
    { value: 'viewport', label: 'Fit to view', hint: 'Fills the panel and stays put while you navigate' },
];

function triggerUpload() {
    if (!is_uploading.value) fileInput.value?.click();
}

function uploadFile(file: File) {
    if (!mapSlug.value) return;
    router.post(
        MapBackgroundImageController.store(mapSlug.value).url,
        { background_image: file },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            only: ['map_user_settings'],
            onStart: () => (is_uploading.value = true),
            onFinish: () => (is_uploading.value = false),
            onError: (errors) => toast.error(errors.background_image ?? 'Failed to upload background image'),
        },
    );
}

function removeBackground() {
    if (!mapSlug.value) return;
    router.delete(MapBackgroundImageController.destroy(mapSlug.value).url, {
        preserveScroll: true,
        preserveState: true,
        only: ['map_user_settings'],
    });
}

function setMode(mode: TMapBackgroundMode) {
    if (!mapSlug.value) return;
    // Patch 37: on the map default, a manager sets the position for everyone.
    if (backgroundChoice.value === 'map') {
        router.patch(MapDefaultBackgroundController.update(mapSlug.value).url, { mode }, { preserveScroll: true, preserveState: true, only: ['map_user_settings'] });
        return;
    }
    updateMapUserSettings(mapSlug.value, { background_image_mode: mode }, ['map_user_settings']);
}

function onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    // Reset so re-selecting the same file fires change again.
    input.value = '';
    if (file) uploadFile(file);
}

function onDrop(event: DragEvent) {
    is_dragging.value = false;
    if (is_uploading.value) return;
    const file = event.dataTransfer?.files?.[0];
    if (file?.type.startsWith('image/')) uploadFile(file);
}
</script>

<template>
    <div class="absolute right-3 bottom-3 z-30 flex items-center gap-0.5 rounded-full bg-white/60 px-1 dark:bg-neutral-800/60">
        <template v-if="allowLayoutOverride">
            <Tooltip v-for="mode in layoutModes" :key="mode.value" :delay-duration="300">
                <TooltipTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 rounded-full"
                        :class="
                            effective_layout === mode.value
                                ? 'bg-white text-foreground shadow-sm dark:bg-neutral-700'
                                : 'text-neutral-600 dark:text-neutral-400'
                        "
                        @click="setPlacement(mode.value)"
                    >
                        <component :is="mode.icon" class="size-4" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{{ mode.label }}</TooltipContent>
            </Tooltip>
            <span class="mx-0.5 h-5 w-px bg-neutral-300/70 dark:bg-neutral-600/70" />
        </template>
        <Tooltip v-if="effective_layout === 'tree'" :delay-duration="300">
            <TooltipTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8 rounded-full"
                    :class="store.showPlaceholders.value ? 'bg-white text-foreground shadow-sm dark:bg-neutral-700' : 'text-neutral-600 dark:text-neutral-400'"
                    @click="store.setShowPlaceholders(!store.showPlaceholders.value)"
                >
                    <CircleDashed class="size-4" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{{ store.showPlaceholders.value ? 'Hide' : 'Show' }} unjumped holes</TooltipContent>
        </Tooltip>
        <!-- Patch 22: the home layout (hubs with straight lanes), on unless switched off here -->
        <Tooltip v-if="effective_layout === 'tree'" :delay-duration="300">
            <TooltipTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8 rounded-full"
                    :class="store.homeLayout.value ? 'bg-white text-foreground shadow-sm dark:bg-neutral-700' : 'text-neutral-600 dark:text-neutral-400'"
                    :aria-pressed="store.homeLayout.value"
                    @click="store.setHomeLayout(!store.homeLayout.value)"
                >
                    <HubLayoutIcon class="size-4" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>Hub layout: {{ store.homeLayout.value ? 'on (click for the old layout)' : 'off (click to turn on)' }}</TooltipContent>
        </Tooltip>
        <!-- Patch 37: sounds and kill effects, with two tests (only on your own screen) -->
        <Popover>
            <PopoverTrigger as-child>
                <Button variant="ghost" size="icon" class="h-8 w-8 rounded-full text-neutral-600 dark:text-neutral-400" title="Sounds and kill effects">
                    <Volume2 v-if="effects.sounds" class="size-4" />
                    <VolumeX v-else class="size-4" />
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-64 p-3" side="top" align="end">
                <div class="flex flex-col gap-2 text-sm">
                    <label class="flex items-center justify-between gap-2">
                        <span>Sounds</span>
                        <input v-model="effects.sounds" type="checkbox" class="size-4 accent-red-600" />
                    </label>
                    <!-- Patch 37: volume of every mapper sound (and the tests) -->
                    <label class="flex items-center gap-2" :class="{ 'opacity-50': !effects.sounds }">
                        <Volume1 class="size-4 shrink-0" />
                        <input v-model.number="effects.volume" type="range" min="0" max="1" step="0.05" class="flex-1 accent-red-600" aria-label="Volume" />
                        <span class="w-9 text-right text-xs tabular-nums">{{ Math.round(effects.volume * 100) }}%</span>
                    </label>
                    <label class="flex items-center justify-between gap-2">
                        <span>Kill effects on the map</span>
                        <input v-model="effects.killEffects" type="checkbox" class="size-4 accent-red-600" />
                    </label>
                    <span class="mt-1 text-xs font-medium text-muted-foreground">Test the sounds</span>
                    <div class="flex flex-col gap-1">
                        <Button
                            v-for="test in soundTests"
                            :key="test.label"
                            variant="outline"
                            size="sm"
                            class="h-7 justify-start text-xs"
                            @click="test.play"
                            >▶ {{ test.label }}</Button
                        >
                    </div>
                    <Button variant="outline" size="sm" class="h-7 text-xs" @click="testKill">💥 Show a test kill on home</Button>
                    <p class="text-[11px] text-muted-foreground">Tests only play on your screen. The test kill flashes your home system with its sound.</p>
                </div>
            </PopoverContent>
        </Popover>
        <Popover>
            <PopoverTrigger as-child>
                <Button variant="ghost" size="icon" class="h-8 w-8 rounded-full text-neutral-600 dark:text-neutral-400" title="Background Image">
                    <BackgroundImageIcon />
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-72 p-3" side="top" align="end">
                <div class="flex flex-col gap-3">
                    <span class="text-sm font-medium">Background</span>
                    <!-- Patch 37: the map's default (set by managers), none, or your own picture -->
                    <div class="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1">
                        <button
                            v-for="option in choiceOptions"
                            :key="option.value"
                            type="button"
                            class="rounded-md px-2 py-1.5 text-xs font-medium transition-colors"
                            :class="backgroundChoice === option.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                            :title="option.hint"
                            :disabled="option.value === 'own' && !canUpload"
                            @click="setChoice(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <input ref="file-input" type="file" accept="image/*" class="hidden" @change="onFileSelected" />
                    <input ref="default-input" type="file" accept="image/*" class="hidden" @change="onDefaultSelected" />

                    <template v-if="backgroundChoice === 'map'">
                        <div class="relative flex aspect-video items-center justify-center overflow-hidden rounded-xl border border-border bg-muted/40">
                            <img v-if="mapBackgroundUrl" :src="mapBackgroundUrl" alt="The map's default background" class="absolute inset-0 h-full w-full object-cover" />
                            <span v-else class="px-3 text-center text-xs text-muted-foreground">No map default yet{{ canManageAccess ? '' : ': a manager can set one' }}.</span>
                            <div v-if="is_uploading" class="absolute inset-0 flex items-center justify-center bg-background/70">
                                <Loader2 class="size-5 animate-spin text-primary" />
                            </div>
                        </div>
                        <div v-if="canManageAccess" class="flex flex-col gap-1.5 rounded-lg border border-dashed border-border p-2">
                            <span class="text-[11px] font-medium text-muted-foreground">Managers: the map default every pilot sees</span>
                            <div class="flex flex-wrap gap-1">
                                <Button variant="outline" size="sm" class="h-7 text-xs" :disabled="is_uploading" @click="defaultInput?.click()">
                                    <ImageUp class="size-3.5" />
                                    Upload
                                </Button>
                                <Button v-if="ownBackgroundUrl" variant="outline" size="sm" class="h-7 text-xs" :disabled="is_uploading" @click="useOwnAsDefault">
                                    Use my picture
                                </Button>
                                <Button
                                    v-if="mapBackgroundUrl"
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 text-xs text-muted-foreground hover:text-destructive"
                                    :disabled="is_uploading"
                                    @click="removeDefault"
                                >
                                    <Trash2 class="size-3.5" />
                                    Remove
                                </Button>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="backgroundChoice === 'own'">
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-muted-foreground">Only you see it · max 8 MB</span>
                            <Button
                                v-if="ownBackgroundUrl"
                                variant="ghost"
                                size="sm"
                                class="h-6 gap-1 px-2 text-xs text-muted-foreground hover:text-destructive"
                                :disabled="is_uploading"
                                @click="removeBackground"
                            >
                                <Trash2 class="size-3.5" />
                                Remove
                            </Button>
                        </div>
                        <div
                            v-if="canUpload"
                            class="group relative flex aspect-video cursor-pointer items-center justify-center overflow-hidden rounded-xl border-2 border-dashed transition-colors"
                            :class="is_dragging ? 'border-primary bg-primary/10' : 'border-border hover:border-primary/50 hover:bg-muted/40'"
                            @click="triggerUpload"
                            @dragover.prevent="is_dragging = true"
                            @dragleave="is_dragging = false"
                            @drop.prevent="onDrop"
                        >
                            <template v-if="ownBackgroundUrl">
                                <img :src="ownBackgroundUrl" alt="Your background" class="absolute inset-0 h-full w-full object-cover" />
                                <div class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-black/55 text-white opacity-0 transition-opacity group-hover:opacity-100">
                                    <ImageUp class="size-5" />
                                    <span class="text-xs font-medium">Replace image</span>
                                </div>
                            </template>
                            <div v-else class="flex flex-col items-center gap-1.5 text-muted-foreground">
                                <div class="flex size-9 items-center justify-center rounded-full bg-muted transition-colors group-hover:bg-primary/10">
                                    <ImageUp class="size-4" />
                                </div>
                                <span class="text-xs font-medium text-foreground">Drag &amp; drop or click</span>
                                <span class="text-[11px]">PNG, JPG, GIF or WebP · max 8 MB</span>
                            </div>
                            <div v-if="is_uploading" class="absolute inset-0 flex items-center justify-center bg-background/70">
                                <Loader2 class="size-5 animate-spin text-primary" />
                            </div>
                        </div>
                    </template>

                    <div v-if="backgroundImageUrl && (backgroundChoice === 'own' || canManageAccess)" class="flex flex-col gap-1.5">
                        <span class="text-xs font-medium text-muted-foreground">Position{{ backgroundChoice === 'map' ? ' (for everyone)' : '' }}</span>
                        <div class="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1">
                            <button
                                v-for="option in modeOptions"
                                :key="option.value"
                                type="button"
                                class="rounded-md px-2 py-1.5 text-xs font-medium transition-colors"
                                :class="
                                    backgroundMode === option.value
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                :title="option.hint"
                                @click="setMode(option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>
                    <!-- Patch 37: the background also runs under the side windows, which turn see-through -->
                    <div v-if="backgroundImageUrl" class="flex flex-col gap-1.5">
                        <label class="flex items-center justify-between gap-2 text-xs font-medium">
                            <span>Full screen (under the windows)</span>
                            <input v-model="effects.fullScreenBackground" type="checkbox" class="size-4" />
                        </label>
                        <label v-if="effects.fullScreenBackground" class="flex flex-col gap-1 text-xs text-muted-foreground">
                            <span>Windows: {{ effects.panelSolidity >= 0.99 ? 'solid' : `${Math.round((1 - effects.panelSolidity) * 100)}% see-through` }}</span>
                            <input v-model.number="effects.panelSolidity" type="range" min="0.3" max="1" step="0.05" />
                        </label>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
        <!-- Patch 39: click the zoom % to go back to 100% -->
        <button
            type="button"
            class="flex h-8 items-center rounded px-1 text-neutral-600 hover:bg-muted dark:text-neutral-400"
            title="Back to 100%"
            @click="setScale(1)"
        >
            {{ (scale * 100).toFixed(0) + '%' }}
        </button>
        <Button variant="ghost" size="icon" class="h-8 w-8 rounded-full text-neutral-600 dark:text-neutral-400" @click="setScale(scale - 0.1)">
            <MinusIcon />
        </Button>
        <Button variant="ghost" size="icon" class="h-8 w-8 rounded-full text-neutral-600 dark:text-neutral-400" @click="setScale(scale + 0.1)">
            <PlusIcon />
        </Button>
    </div>
</template>

<style scoped></style>
