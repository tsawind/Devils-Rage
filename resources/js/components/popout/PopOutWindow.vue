<script setup lang="ts">
import PopOutController from '@/actions/App/Http/Controllers/PopOutController';
import { CharacterImage } from '@/components/images';
import { armHole } from '@/composables/signatures/armHole';
import { heldWayBack } from '@/composables/signatures/wayBack';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { displayAlias } from '@/lib/alias';
import { planAliasesForSystem, isWormholeSignature } from '@/lib/aliasPlan';
import { visibleBookmarkName } from '@/lib/bookmark';
import { chainAliases } from '@/lib/combat';
import { jsonFetch } from '@/lib/jsonFetch';
import { closePopOut, popOut, popOutWindow } from '@/lib/popOut';
import { signatureParser } from '@/lib/SignatureParser';
import { driverId } from '@/lib/windowDriver';
import { pasteSignatures } from '@/map/actions/pasteSignatures';
import { useMapSolarsystems } from '@/map/api';
import type { TMap } from '@/pages/maps';
import type { AppPageProps } from '@/types';
import type { TCharacter, TSignature } from '@/types/models';
import { usePage } from '@inertiajs/vue3';
import { useIntervalFn } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Patch 39: the scanner pop-out's contents, drawn into the always-on-top window: the
 * character, "where am I" with the way back to copy, the systems around it, and its
 * system's signatures (Ctrl+V pastes a scan into that system; ⚡ arms a hole and copies its
 * bookmark). Questions the mapper asks (the jump prompt, renames) still open in the main
 * window for now.
 */
type TPopSystem = { id: number; map_id: number; solarsystem_id: number; alias: string | null; signatures: TSignature[] };

const page = usePage<AppPageProps<{ map: TMap; map_characters?: TCharacter[] | null }>>();
const { map_solarsystems } = useMapSolarsystems();
const { resolveSolarsystem } = useStaticSolarsystems();

const target = computed(() => popOutWindow.value?.document.body ?? null);
const character = computed(() => (popOut.value ? (page.props.map_characters ?? []).find((candidate) => candidate.id === popOut.value!.characterId) ?? null : null));
const solarsystemId = computed(() => character.value?.status?.solarsystem_id ?? null);
const mapSystem = computed(() => map_solarsystems.value.find((system) => system.solarsystem_id === solarsystemId.value) ?? null);
const staticSystem = computed(() => (solarsystemId.value ? resolveSolarsystem(solarsystemId.value) : null));
const system = ref<TPopSystem | null>(null);
const note = ref<string | null>(null);

async function refresh(): Promise<void> {
    const id = mapSystem.value?.id;
    if (!id || !popOut.value) {
        system.value = null;
        return;
    }
    system.value = await jsonFetch<TPopSystem>(PopOutController.system(id).url);
}

watch(() => [popOut.value?.characterId, mapSystem.value?.id, page.props.map] as const, () => void refresh(), { immediate: true });
const { pause, resume } = useIntervalFn(() => void refresh(), 8_000, { immediate: false });
watch(popOutWindow, (opened) => (opened ? resume() : pause()), { immediate: true });

const signatures = computed(() => [...(system.value?.signatures ?? [])].sort((a, b) => (a.signature_id ?? '').localeCompare(b.signature_id ?? '')));
const chain = computed(() => chainAliases(map_solarsystems.value, mapSystem.value));
const planned = computed(() =>
    planAliasesForSystem({
        signatures: signatures.value,
        system: system.value
            ? {
                  alias: system.value.alias,
                  solarsystem: staticSystem.value,
                  combat_home: mapSystem.value?.combat_home ?? false,
                  combat_color: mapSystem.value?.combat_color ?? null,
                  id: system.value.id,
                  hole_slot_high: mapSystem.value?.hole_slot_high ?? null,
              }
            : null,
        aliases: chain.value,
        formats: page.props.map,
    }),
);

/** Systems connected to this one ("the map around me"). */
const around = computed(() => {
    const here = mapSystem.value?.id;
    if (!here) return [];
    return (page.props.map?.map_connections ?? [])
        .filter((connection) => connection.from_map_solarsystem_id === here || connection.to_map_solarsystem_id === here)
        .map((connection) => {
            const otherId = connection.from_map_solarsystem_id === here ? connection.to_map_solarsystem_id : connection.from_map_solarsystem_id;
            const other = map_solarsystems.value.find((candidate) => candidate.id === otherId);
            const resolved = other ? resolveSolarsystem(other.solarsystem_id) : null;
            return { id: otherId, label: displayAlias(other?.alias) || resolved?.name || '?', klass: resolved?.class ?? null, mass: connection.mass_status, eol: connection.lifetime_status === 'eol' };
        });
});

/** The way back held for this character (copied with a click in here). */
const wayBack = computed(() => {
    const held = heldWayBack.value;
    if (!held || !popOut.value) return null;
    if (held.alt) return held.alt.characterName === popOut.value.name ? held : null;
    return popOut.value.characterId === driverId.value ? held : null;
});

async function copyText(text: string): Promise<boolean> {
    try {
        await (popOutWindow.value?.navigator ?? navigator).clipboard.writeText(text);
        return true;
    } catch {
        return false;
    }
}

async function copyWayBack(): Promise<void> {
    const held = wayBack.value;
    if (!held) return;
    if (await copyText(held.name)) {
        if (heldWayBack.value === held) heldWayBack.value = null;
        note.value = `Copied your way back: ${visibleBookmarkName(held.name)}`;
    }
}

function age(signature: TSignature): string {
    const created = signature.created_at ? Date.parse(String(signature.created_at)) : NaN;
    if (Number.isNaN(created)) return '';
    const minutes = Math.max(0, Math.round((Date.now() - created) / 60_000));
    return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h`;
}

function arm(signature: TSignature): void {
    if (!system.value || signature.map_connection_id) return;
    const alias = signature.alias ?? planned.value.get(signature.id) ?? null;
    if (!alias) {
        note.value = 'No free number to arm this hole as.';
        return;
    }
    armHole({
        signature,
        system: { alias: system.value.alias, class: staticSystem.value?.class ?? null, combatHome: Boolean(mapSystem.value?.combat_home), combatColor: mapSystem.value?.combat_color ?? null },
        aliases: chain.value,
        formats: page.props.map,
        alias,
        fallbackAlias: alias,
    });
    note.value = `Armed ${signature.signature_id?.slice(0, 3) ?? ''} as ${displayAlias(alias)}`;
    setTimeout(() => void refresh(), 1_200);
}

/** Ctrl+V in the pop-out: the scan goes into this character's system; then the way back is copied. */
function onPaste(event: ClipboardEvent): void {
    const text = event.clipboardData?.getData('text/plain') ?? '';
    const parsed = text ? signatureParser.parseSignatures(text) : [];
    if (!system.value || !parsed?.length) return;
    event.preventDefault();
    const name = displayAlias(system.value.alias) || staticSystem.value?.name || 'this system';
    pasteSignatures(system.value.id, parsed, () => {
        note.value = `Pasted ${parsed.length} signature${parsed.length === 1 ? '' : 's'} into ${name}`;
        void refresh();
        void copyWayBack();
    });
}

let listening: Window | null = null;
watch(
    popOutWindow,
    (opened) => {
        listening?.document.removeEventListener('paste', onPaste);
        listening = opened;
        opened?.document.addEventListener('paste', onPaste);
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    listening?.document.removeEventListener('paste', onPaste);
    closePopOut();
});
</script>

<template>
    <Teleport v-if="target && popOut" :to="target">
        <div class="flex h-screen flex-col overflow-hidden border-2 bg-black text-sm text-neutral-200" :style="{ borderColor: popOut.color }">
            <!-- Top bar -->
            <div class="flex items-center gap-2 border-b border-neutral-800 px-2 py-1">
                <CharacterImage :character_id="popOut.characterId" :character_name="popOut.name" class="size-5 rounded" />
                <span class="truncate font-semibold">{{ popOut.name }}</span>
                <span v-if="character?.status?.ship_type" class="truncate text-xs text-neutral-500">{{ character.status.ship_type.name }}</span>
                <span class="ml-auto text-[10px] text-neutral-500" title="Ctrl+V here pastes a scan into this character's system">Ctrl+V pastes here</span>
                <button type="button" class="rounded px-1.5 text-neutral-400 hover:bg-neutral-800 hover:text-white" title="Close the pop-out" @click="closePopOut()">✕</button>
            </div>

            <!-- Where am I -->
            <div class="flex flex-wrap items-center gap-2 border-b border-neutral-800 px-2 py-1 text-xs">
                <template v-if="staticSystem">
                    <span class="font-semibold text-white">{{ displayAlias(system?.alias ?? mapSystem?.alias) || staticSystem.name }}</span>
                    <span class="text-neutral-500">{{ staticSystem.name }}<template v-if="staticSystem.class"> · C{{ staticSystem.class }}</template></span>
                    <span v-if="!mapSystem" class="text-amber-400">not on the map</span>
                </template>
                <span v-else class="text-neutral-500">{{ character ? 'Location unknown (is tracking on?)' : 'Not online' }}</span>
                <button
                    v-if="wayBack"
                    type="button"
                    class="ml-auto rounded bg-red-600/80 px-2 py-0.5 font-mono text-[11px] text-white hover:bg-red-500"
                    title="Click to copy your way back"
                    @click="copyWayBack"
                >
                    ⧉ {{ visibleBookmarkName(wayBack.name) }}
                </button>
            </div>

            <!-- Around me -->
            <div v-if="around.length" class="flex flex-wrap gap-1 border-b border-neutral-800 px-2 py-1 text-[11px]">
                <span class="text-neutral-500">Around:</span>
                <span v-for="item in around" :key="item.id" class="rounded border border-neutral-700 px-1" :class="{ 'border-red-500/70': item.eol }">
                    {{ item.label }}<span v-if="item.klass" class="text-neutral-500"> C{{ item.klass }}</span
                    ><span v-if="item.mass && item.mass !== 'fresh'" class="text-amber-400"> · {{ item.mass }}</span>
                </span>
            </div>

            <!-- Signatures -->
            <div class="min-h-0 flex-1 overflow-y-auto" style="font-size: 0.75rem">
                <table class="w-full">
                    <thead class="sticky top-0 bg-neutral-900 text-left text-[10px] tracking-wider text-neutral-500 uppercase">
                        <tr>
                            <th class="px-2 py-0.5">Name</th>
                            <th class="px-1">ID</th>
                            <th class="px-1">Cat</th>
                            <th class="px-1">Type</th>
                            <th class="px-1 text-right">Age</th>
                            <th class="w-6"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="signature in signatures" :key="signature.id" class="border-t border-neutral-900 hover:bg-neutral-900">
                            <td class="px-2 font-semibold" :class="signature.armed_by_name ? 'text-sky-400' : 'text-white'">
                                {{ displayAlias(signature.alias ?? planned.get(signature.id) ?? '') }}
                            </td>
                            <td class="px-1 font-mono">{{ signature.signature_id?.slice(0, 7) }}</td>
                            <td class="px-1 text-neutral-400">{{ signature.signature_category?.name ?? '' }}</td>
                            <td class="px-1">{{ signature.wormhole?.name ?? signature.signature_type?.name ?? signature.raw_type_name ?? '' }}</td>
                            <td class="px-1 text-right text-neutral-500">{{ age(signature) }}</td>
                            <td class="px-1 text-center">
                                <button
                                    v-if="isWormholeSignature(signature) && !signature.map_connection_id"
                                    type="button"
                                    class="text-neutral-500 hover:text-sky-400"
                                    :title="signature.armed_by_name ? `Armed by ${signature.armed_by_name}` : 'Arm as your next jump (copies its bookmark)'"
                                    @click="arm(signature)"
                                >
                                    ⚡
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!signatures.length">
                            <td colspan="6" class="px-2 py-3 text-center text-neutral-500">No signatures yet. Copy your probe scanner in EVE and press Ctrl+V here.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="note" class="truncate border-t border-neutral-800 px-2 py-0.5 text-[11px] text-emerald-400">{{ note }}</div>
        </div>
    </Teleport>
</template>
