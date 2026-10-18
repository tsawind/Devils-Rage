<script setup lang="ts">
import PopOutController from '@/actions/App/Http/Controllers/PopOutController';
import { CharacterImage } from '@/components/images';
import Signatures from '@/components/signatures/Signatures.vue';
import { heldWayBack } from '@/composables/signatures/wayBack';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { displayAlias } from '@/lib/alias';
import { visibleBookmarkName } from '@/lib/bookmark';
import { jsonFetch } from '@/lib/jsonFetch';
import { providePortalTarget, workingInPopOut } from '@/lib/portalTarget';
import { closePopOut, focusedClipboard, popOut, popOutWindow } from '@/lib/popOut';
import { driverId } from '@/lib/windowDriver';
import { useMapSolarsystems } from '@/map/api';
import MapRoot from '@/map/components/MapRoot.vue';
import { POPOUT_MAP } from '@/map/popoutMap';
import type { TMap, TResolvedSelectedMapSolarsystem, TSelectedMapSolarsystem } from '@/pages/maps';
import type { AppPageProps } from '@/types';
import type { TMapConfig } from '@/types/map';
import type { TCharacter } from '@/types/models';
import { usePage } from '@inertiajs/vue3';
import { useEventListener, useIntervalFn, useLocalStorage } from '@vueuse/core';
import { computed, onBeforeUnmount, provide, ref, watch } from 'vue';

/**
 * Patch 39: the scanner pop-out, drawn into the always-on-top window: the character,
 * "where am I" with the way back to copy, the map around the character (◂ folds it away,
 * ▸ brings it back; dragging the window wider gives the map the room) and the same
 * signature panel as the main mapper for the character's system (Ctrl+V pastes into it).
 * Clicking another system on the pop-out map shows its signatures until the next jump.
 * Questions the mapper asks (the jump prompt, renames) still open in the main window.
 *
 * Patch 42c: the map is the real map (a second copy with its own zoom, always centred on
 * the character), not a drawing of the systems around it.
 */
const { map, config } = defineProps<{ map: TMap; config: TMapConfig }>();
const page = usePage<AppPageProps<{ map: TMap; map_characters?: TCharacter[] | null }>>();
const { map_solarsystems } = useMapSolarsystems();
const { resolveSolarsystem } = useStaticSolarsystems();

const target = computed(() => popOutWindow.value?.document.body ?? null);

// Patch 42d: menus, selects and popovers in the pop-out open in the pop-out; prompts the main
// window draws (rename, add a note, …) open there too while you're working in the pop-out.
providePortalTarget(target);
useEventListener(() => popOutWindow.value?.document ?? null, 'pointerdown', () => (workingInPopOut.value = target.value), { capture: true });
for (const type of ['pointerdown', 'pointerover'] as const) {
    useEventListener(document, type, () => {
        if (workingInPopOut.value) workingInPopOut.value = null;
    }, { capture: true, passive: true });
}
watch(target, (body) => {
    if (!body) workingInPopOut.value = null;
});
const character = computed(() => (popOut.value ? ((page.props.map_characters ?? []).find((candidate) => candidate.id === popOut.value!.characterId) ?? null) : null));
const hereId = computed(() => character.value?.status?.solarsystem_id ?? null);
const viewing = ref<number | null>(null);
const shownId = computed(() => viewing.value ?? hereId.value);
const hereMapSystem = computed(() => map_solarsystems.value.find((system) => system.solarsystem_id === hereId.value) ?? null);
const shownMapSystem = computed(() => map_solarsystems.value.find((system) => system.solarsystem_id === shownId.value) ?? null);
const hereStatic = computed(() => (hereId.value ? resolveSolarsystem(hereId.value) : null));
const mapOpen = useLocalStorage('popout-map-open', true);

/** The signature panel is drawn at this width and shown at 80%, the same whatever the window's size. */
const SIGNATURES_WIDTH = 490;
const SIGNATURES_ZOOM = 0.8;
const mapPane = ref<HTMLElement | null>(null);
const foldedMapWidth = useLocalStorage('popout-map-width', 480);

/** ◂: the map goes and the window shrinks by its width (the signatures stay as they are). */
function foldMap(): void {
    const width = mapPane.value?.getBoundingClientRect().width ?? 0;
    if (width > 0) foldedMapWidth.value = Math.round(width);
    mapOpen.value = false;
    try {
        popOutWindow.value?.resizeBy(-Math.round(width) + 16, 0);
    } catch {
        // The browser didn't allow it: the window keeps its size.
    }
}

/** ▸: the map comes back and the window grows by its width again. */
function openMap(): void {
    mapOpen.value = true;
    try {
        popOutWindow.value?.resizeBy(foldedMapWidth.value - 16, 0);
    } catch {
        // The browser didn't allow it: the map takes what room there is.
    }
}

// A jump goes back to the character's own system.
watch(hereId, () => (viewing.value = null));

const selected = ref<TSelectedMapSolarsystem | null>(null);
const resolved = computed<TResolvedSelectedMapSolarsystem | null>(() =>
    selected.value ? { ...selected.value, solarsystem: resolveSolarsystem(selected.value.solarsystem_id) } : null,
);

async function refresh(): Promise<void> {
    const id = shownMapSystem.value?.id;
    if (!id || !popOut.value) {
        selected.value = null;
        return;
    }
    const answer = await jsonFetch<{ data: TSelectedMapSolarsystem }>(PopOutController.system(id).url);
    if (answer && shownMapSystem.value?.id === id) selected.value = answer.data;
}

watch(() => [popOut.value?.characterId, shownMapSystem.value?.id, page.props.map] as const, () => void refresh(), { immediate: true });
const { pause, resume } = useIntervalFn(() => void refresh(), 8_000, { immediate: false });
watch(popOutWindow, (opened) => (opened ? resume() : pause()), { immediate: true });

/** The way back held for this character (copied with a click in here). */
const wayBack = computed(() => {
    const held = heldWayBack.value;
    if (!held || !popOut.value) return null;
    if (held.alt) return held.alt.characterName === popOut.value.name ? held : null;
    return popOut.value.characterId === driverId.value ? held : null;
});
const note = ref<string | null>(null);

async function copyWayBack(): Promise<void> {
    const held = wayBack.value;
    if (!held) return;
    try {
        await focusedClipboard().writeText(held.name);
        if (heldWayBack.value === held) heldWayBack.value = null;
        note.value = `Copied your way back: ${visibleBookmarkName(held.name)}`;
    } catch {
        note.value = 'Click in the pop-out first, then copy.';
    }
}

/** The signature panel's paste and location check belong to the pop-out. */
const popoutOptions = {
    target: () => popOutWindow.value?.document ?? null,
    locationSystemId: () => hereId.value,
};

function pick(solarsystemId: number): void {
    viewing.value = solarsystemId === hereId.value ? null : solarsystemId;
}
provide(POPOUT_MAP, { pick });

onBeforeUnmount(() => closePopOut());
</script>

<template>
    <Teleport v-if="target && popOut" :to="target">
        <div class="flex h-screen flex-col overflow-hidden border-2 bg-background text-sm text-foreground" :style="{ borderColor: popOut.color }">
            <!-- Top bar -->
            <div class="flex items-center gap-2 border-b border-border px-2 py-1">
                <CharacterImage :character_id="popOut.characterId" :character_name="popOut.name" class="size-5 rounded" />
                <span class="truncate font-semibold">{{ popOut.name }}</span>
                <span v-if="character?.status?.ship_type" class="truncate text-xs text-muted-foreground">{{ character.status.ship_type.name }}</span>
                <template v-if="hereStatic">
                    <span class="ml-2 font-semibold">{{ displayAlias(hereMapSystem?.alias) || hereStatic.name }}</span>
                    <span class="text-xs text-muted-foreground">{{ hereStatic.name }}<template v-if="hereStatic.class"> · C{{ hereStatic.class }}</template></span>
                    <span v-if="!hereMapSystem" class="text-xs text-amber-400">not on the map</span>
                </template>
                <span v-else class="text-xs text-muted-foreground">{{ character ? 'Location unknown (is tracking on?)' : 'Not online' }}</span>
                <button
                    v-if="wayBack"
                    type="button"
                    class="rounded bg-red-600/80 px-2 py-0.5 font-mono text-[11px] text-white hover:bg-red-500"
                    title="Click to copy your way back"
                    @click="copyWayBack"
                >
                    ⧉ {{ visibleBookmarkName(wayBack.name) }}
                </button>
                <button type="button" class="ml-auto rounded px-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Close the pop-out" @click="closePopOut()">✕</button>
            </div>

            <div class="flex min-h-0 flex-1">
                <!-- The map around the character: ◂ folds it away and the window shrinks by its width -->
                <div v-if="mapOpen" ref="mapPane" class="relative min-w-0 flex-1 border-r border-border">
                    <MapRoot :map="map" :config="config" popout :follow-solarsystem-id="hereId" />
                    <button
                        type="button"
                        class="absolute top-1 right-1 z-40 rounded bg-neutral-800/80 px-1.5 text-xs text-neutral-300 hover:bg-neutral-700"
                        title="Fold the map away (the window gets narrower)"
                        @click="foldMap"
                    >
                        ◂
                    </button>
                </div>
                <button
                    v-else
                    type="button"
                    class="w-4 shrink-0 border-r border-border bg-neutral-900 text-xs text-neutral-400 hover:bg-neutral-800"
                    title="Show the map (the window gets wider)"
                    @click="openMap"
                >
                    ▸
                </button>

                <!-- The signature panel, as on the main mapper: always the same size (80%), whatever the window's size -->
                <div class="flex min-h-0 shrink-0 flex-col" :style="{ width: `${SIGNATURES_WIDTH * SIGNATURES_ZOOM}px` }">
                    <div v-if="viewing" class="flex items-center gap-2 border-b border-amber-500/40 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300">
                        Showing {{ displayAlias(shownMapSystem?.alias) || resolved?.solarsystem.name }}
                        <button type="button" class="ml-auto underline" @click="viewing = null">back to {{ popOut.name }}</button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-hidden">
                        <div :style="{ width: `${SIGNATURES_WIDTH}px`, height: `${100 / SIGNATURES_ZOOM}%`, zoom: SIGNATURES_ZOOM }">
                            <Signatures :map_solarsystem="resolved" :popout="popoutOptions" />
                        </div>
                    </div>
                    <div v-if="note" class="truncate border-t border-border px-2 py-0.5 text-[11px] text-emerald-400">{{ note }}</div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
