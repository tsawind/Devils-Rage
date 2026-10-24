<script setup lang="ts">
import PopOutController from '@/actions/App/Http/Controllers/PopOutController';
import { CharacterImage } from '@/components/images';
import Signatures from '@/components/signatures/Signatures.vue';
import { heldWayBack } from '@/composables/signatures/wayBack';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { displayAlias } from '@/lib/alias';
import { visibleBookmarkName } from '@/lib/bookmark';
import { jsonFetch } from '@/lib/jsonFetch';
import { jumpChoice } from '@/lib/jumpChoice';
import { openPopOutBody, providePortalTarget, workingInPopOut } from '@/lib/portalTarget';
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
 * Questions the mapper asks (the jump prompt, renames) open in the pop-out while the main window isn't in front (patch 53).
 * Patch 47: in the desktop app the jump prompt's likely holes also show here, one click each.
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
useEventListener(() => popOutWindow.value?.document ?? null, 'pointerdown', closeMenusOnOutsideClick, { capture: true });

/** Open menus, popovers and selects in the pop-out (their "click outside" watch is on the main window). */
const FLOATING = '[data-reka-popper-content-wrapper], [role="menu"], [role="listbox"], [role="dialog"]';

/**
 * Patch 42e: a click in the pop-out outside an open menu closes it (with Escape, the way the
 * menus close themselves), as a click outside does in the main window.
 */
function closeMenusOnOutsideClick(event: PointerEvent): void {
    workingInPopOut.value = target.value;
    const doc = popOutWindow.value?.document;
    if (!doc || !doc.querySelector(FLOATING)) return;
    if (event.target instanceof Element && event.target.closest(FLOATING)) return;
    pressEscape(doc, 3);
}

/** Escape closes the top layer (a sub-menu first), so press it again while something is still open. */
function pressEscape(doc: Document, times: number): void {
    for (const where of [doc, document]) {
        where.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', bubbles: true, cancelable: true }));
    }
    if (times > 1) setTimeout(() => doc.querySelector('[role="menu"], [role="listbox"]') && pressEscape(doc, times - 1), 30);
}
for (const type of ['pointerdown', 'pointerover'] as const) {
    useEventListener(document, type, () => {
        if (workingInPopOut.value) workingInPopOut.value = null;
    }, { capture: true, passive: true });
}
watch(
    target,
    (body) => {
        if (!body) workingInPopOut.value = null;
        // Patch 53: questions the mapper asks open here while the main window isn't in front.
        openPopOutBody.value = body;
    },
    { immediate: true },
);
onBeforeUnmount(() => (openPopOutBody.value = null));
const character = computed(() => (popOut.value ? ((page.props.map_characters ?? []).find((candidate) => candidate.id === popOut.value!.characterId) ?? null) : null));
const hereId = computed(() => character.value?.status?.solarsystem_id ?? null);
const viewing = ref<number | null>(null);
const shownId = computed(() => viewing.value ?? hereId.value);
const hereMapSystem = computed(() => map_solarsystems.value.find((system) => system.solarsystem_id === hereId.value) ?? null);
const shownMapSystem = computed(() => map_solarsystems.value.find((system) => system.solarsystem_id === shownId.value) ?? null);
const hereStatic = computed(() => (hereId.value ? resolveSolarsystem(hereId.value) : null));
const mapOpen = useLocalStorage('popout-map-open', true);

/** The signature panel is drawn at this width and shown at its zoom (80% to start), the same whatever the window's size. */
const SIGNATURES_WIDTH = 490;
/** Patch 42f: − / + bottom right of the signatures (50%–120%); the window grows or shrinks with it. */
const signaturesZoom = useLocalStorage('popout-signatures-zoom', 0.8);
function zoomSignatures(next: number): void {
    const zoom = Math.round(Math.min(1.2, Math.max(0.5, next)) * 100) / 100;
    const change = Math.round(SIGNATURES_WIDTH * (zoom - signaturesZoom.value));
    signaturesZoom.value = zoom;
    try {
        if (change) popOutWindow.value?.resizeBy(change, 0);
    } catch {
        // The browser didn't allow it: the map gives up the room.
    }
}
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
                    class="rounded px-2 py-0.5 font-mono text-[11px] text-white"
                    :class="wayBack.copied ? 'bg-emerald-700/80 hover:bg-emerald-600' : 'bg-red-600/80 hover:bg-red-500'"
                    :title="wayBack.copied ? 'On your clipboard (click to copy it again)' : 'Click to copy your way back'"
                    @click="copyWayBack"
                >
                    ⧉ {{ visibleBookmarkName(wayBack.name) }}
                </button>
                <button type="button" class="ml-auto rounded px-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Close the pop-out" @click="closePopOut()">✕</button>
            </div>

            <!-- Patch 47: the jump prompt, answered from on top of EVE -->
            <div v-if="jumpChoice" class="border-b border-amber-500/50 bg-amber-500/10 px-2 py-1.5">
                <div class="mb-1 flex items-center gap-2 text-xs">
                    <span class="font-semibold text-amber-300">Which hole did {{ jumpChoice.characterName ?? 'you' }} come through?</span>
                    <span class="truncate text-muted-foreground">{{ jumpChoice.from }} → {{ jumpChoice.to }}</span>
                </div>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="option in jumpChoice.options"
                        :key="option.id"
                        type="button"
                        class="rounded border border-amber-500/40 bg-neutral-900 px-2 py-0.5 text-xs hover:border-amber-400 hover:bg-amber-500/20"
                        :title="`Jumped through ${option.signature}`"
                        @click="jumpChoice.choose(option.id)"
                    >
                        <span class="font-mono font-semibold">{{ option.signature }}</span>
                        <span class="ml-1">{{ displayAlias(option.label) || option.label }}</span>
                        <span v-if="option.detail" class="ml-1 text-muted-foreground">{{ option.detail }}</span>
                    </button>
                    <button type="button" class="rounded border border-border px-2 py-0.5 text-xs text-muted-foreground hover:bg-muted" title="Not one of these: map it as unknown" @click="jumpChoice.choose(null)">
                        Unknown
                    </button>
                </div>
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
                <div class="flex min-h-0 shrink-0 flex-col" :style="{ width: `${SIGNATURES_WIDTH * signaturesZoom}px` }">
                    <div v-if="viewing" class="flex items-center gap-2 border-b border-amber-500/40 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300">
                        Showing {{ displayAlias(shownMapSystem?.alias) || resolved?.solarsystem.name }}
                        <button type="button" class="ml-auto underline" @click="viewing = null">back to {{ popOut.name }}</button>
                    </div>
                    <div class="relative min-h-0 flex-1 overflow-hidden">
                        <div :style="{ width: `${SIGNATURES_WIDTH}px`, height: `${100 / signaturesZoom}%`, zoom: signaturesZoom }">
                            <Signatures :map_solarsystem="resolved" :popout="popoutOptions" />
                        </div>
                        <div class="absolute right-2 bottom-2 z-30 flex items-center gap-0.5 rounded-full bg-neutral-800/70 px-1 text-xs text-neutral-300">
                            <button type="button" class="flex size-6 items-center justify-center rounded-full hover:bg-neutral-700" title="Smaller signatures" @click="zoomSignatures(signaturesZoom - 0.1)">−</button>
                            <button type="button" class="w-9 text-center hover:text-white" title="Back to 80%" @click="zoomSignatures(0.8)">{{ Math.round(signaturesZoom * 100) }}%</button>
                            <button type="button" class="flex size-6 items-center justify-center rounded-full hover:bg-neutral-700" title="Bigger signatures" @click="zoomSignatures(signaturesZoom + 0.1)">+</button>
                        </div>
                    </div>
                    <div v-if="note" class="truncate border-t border-border px-2 py-0.5 text-[11px] text-emerald-400">{{ note }}</div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
