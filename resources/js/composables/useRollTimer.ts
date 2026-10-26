import MapRageRollController from '@/actions/App/Http/Controllers/MapRageRollController';
import { useMapEffects } from '@/composables/useMapEffects';
import { useRageRoll } from '@/composables/useRageRoll';
import { ROLL_SCAN_AT_SECONDS, rollTimer } from '@/lib/rageRoll';
import { playScanTone } from '@/lib/rageSound';
import { router } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import { computed, watch } from 'vue';

/** The collapse whose SCAN tone this window already played (kept-alive pages share it: one tone per window). */
let tonePlayedFor: string | null = null;

/** How late after the SCAN moment the tone still plays (a page opened later stays quiet). */
const TONE_LATE_SECONDS = 3;

/**
 * Patch 57: the rage roll timer. When the static collapsed, everyone's mapper counts down to
 * the new static's signature (59 s); at 54 s the SCAN tone tells scanners to press Scan, so the
 * scan lands as the signature appears. Muted by Sounds off or the menu's "Roll timer sound".
 */
export function useRollTimer() {
    const { rageRoll, mapSlug } = useRageRoll();
    const { effects } = useMapEffects();
    const now = useNow({ interval: 250 });

    const timer = computed(() => rollTimer(rageRoll.value, now.value.getTime()));

    function markCollapsed(atMs: number | null = null, characterName: string | null = null): void {
        router.post(
            MapRageRollController.collapsed(mapSlug.value).url,
            { at: atMs === null ? null : new Date(atMs).toISOString(), character_name: characterName },
            { preserveScroll: true, preserveState: true, only: ['map'] },
        );
    }

    return { timer, markCollapsed, effects };
}

/** Call once per map page (the overlay does): plays the SCAN tone when the timer gets there. */
export function useRollTimerTone(): void {
    const { timer } = useRollTimer();
    const { rageRoll } = useRageRoll();
    const { effects } = useMapEffects();

    watch(timer, (value) => {
        const collapsedAt = rageRoll.value?.collapsed_at ?? null;
        if (!value || value.phase !== 'counting' || !collapsedAt || tonePlayedFor === collapsedAt) return;
        if (value.elapsed < ROLL_SCAN_AT_SECONDS || value.elapsed > ROLL_SCAN_AT_SECONDS + TONE_LATE_SECONDS) return;
        tonePlayedFor = collapsedAt;
        if (effects.value.sounds && effects.value.rollTimerSound) playScanTone();
    });
}
