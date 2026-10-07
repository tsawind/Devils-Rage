import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { useRageRoll } from '@/composables/useRageRoll';
import { computed } from 'vue';

/** Popups auto-answer after this long while you are in combat mode. */
export const COMBAT_POPUP_SECONDS = 60;

/** Your own combat mode: whether it is on, which chain you work, and the popup countdown to use. */
export function useCombat() {
    const settings = useMapUserSettings();
    const { rageRoll } = useRageRoll();

    /** Your own Rage Scanning, or a rage roll that is also a Rage Scanning session (patch 35: speed for everyone). */
    const is_combat = computed(() => Boolean(settings.value.combat_mode) || Boolean(rageRoll.value?.scanning));
    /** Rage speed only because of the map's rage roll session (your own button is off). */
    const is_session_speed = computed(() => !settings.value.combat_mode && Boolean(rageRoll.value?.scanning));
    const combat_color = computed(() => (is_combat.value ? (settings.value.combat_color ?? null) : null));
    /** Seconds before a popup answers itself, or null outside combat mode. */
    const popup_seconds = computed<number | null>(() => (is_combat.value ? COMBAT_POPUP_SECONDS : null));

    return { is_combat, is_session_speed, combat_color, popup_seconds };
}
