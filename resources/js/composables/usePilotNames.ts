import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Patch 37: names as "Alt - Main", so everyone knows which human to ask. The main is
 * the one set in SeAT (synced hourly); a character SeAT doesn't know reads
 * "Alt - Missing SeAT". A pilot flying their main shows just the name. Until SeAT is
 * set up (and answering), names show as before.
 */
export type TPilotMains = { enabled: boolean; mains: Record<string, string> };

export const MISSING_SEAT = 'Missing SeAT';

/** The main shown after a name: the SeAT main, "Missing SeAT", or null (no suffix). */
export function mainSuffix(name: string, data: TPilotMains | null | undefined): string | null {
    if (!data?.enabled) return null;
    const main = data.mains[name];
    if (!main) return MISSING_SEAT;
    return main === name ? null : main;
}

export function pilotLabel(name: string | null | undefined, data: TPilotMains | null | undefined): string {
    if (!name) return 'someone';
    const suffix = mainSuffix(name, data);
    return suffix ? `${name} - ${suffix}` : name;
}

export function usePilotNames() {
    const page = usePage();
    // Outside a map page (or in tests) there are no page props: names show as they are.
    const data = computed(() => (page?.props as unknown as { pilot_mains?: TPilotMains } | undefined)?.pilot_mains ?? null);
    return {
        label: (name: string | null | undefined) => pilotLabel(name, data.value),
        suffix: (name: string) => mainSuffix(name, data.value),
    };
}
