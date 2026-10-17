import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Patch 37: names as "Alt - Main", so everyone knows which human to ask. The main is
 * the one set in SeAT (synced hourly); a character SeAT doesn't know reads
 * "Alt - Missing SeAT". A pilot flying their main shows just the name. Until SeAT is
 * set up (and answering), names show as before.
 *
 * Patch 39: every "who" shows the PERSON: the name they go by in SeAT (or their main's
 * name), or a guest's own name. Hover tells the rest: "Tsa · main Tsawind · on LuigiSquirrel".
 */
export type TPilotMains = { enabled: boolean; mains: Record<string, string>; people?: Record<string, string> };

export const MISSING_SEAT = 'Missing SeAT';

/** The main shown after a name: the SeAT main, "Missing SeAT", or null (no suffix). */
export function mainSuffix(name: string, data: TPilotMains | null | undefined): string | null {
    if (!data?.enabled) return null;
    const main = data.mains[name];
    if (!main) return data.people?.[name] ? null : MISSING_SEAT;
    return main === name ? null : main;
}

/** The person behind a character (SeAT's "Name you go by" or a guest's name), or null. */
export function personOf(name: string | null | undefined, data: TPilotMains | null | undefined): string | null {
    if (!name) return null;
    return data?.people?.[name] ?? null;
}

export function pilotLabel(name: string | null | undefined, data: TPilotMains | null | undefined): string {
    if (!name) return 'someone';
    const person = personOf(name, data);
    if (person) return person;
    const suffix = mainSuffix(name, data);
    return suffix ? `${name} - ${suffix}` : name;
}

/** Hover text for a "who": "Tsa · main Tsawind · on LuigiSquirrel" (parts that add nothing are left out). */
export function pilotDetail(name: string | null | undefined, data: TPilotMains | null | undefined): string {
    if (!name) return '';
    const person = personOf(name, data);
    const main = data?.enabled ? data.mains[name] : undefined;
    const parts: string[] = [person ?? name];
    if (main && main !== parts[0]) parts.push(`main ${main}`);
    if (person && name !== person) parts.push(`on ${name}`);
    if (!person && !main && data?.enabled) parts.push(MISSING_SEAT);
    return parts.join(' · ');
}

/** The person shown after a character's name on map cards ("LuigiSquirrel · Tsa"), null when it adds nothing. */
export function personAfter(name: string, data: TPilotMains | null | undefined): string | null {
    const person = personOf(name, data);
    if (person) return person === name ? null : person;
    return mainSuffix(name, data);
}

/**
 * Characters grouped by person, for the pilots panel. Characters nobody claims stay
 * on their own (keyed by their name).
 */
export function groupByPerson<T>(items: T[], nameOf: (item: T) => string, data: TPilotMains | null | undefined): { person: string; known: boolean; items: T[] }[] {
    const groups = new Map<string, { person: string; known: boolean; items: T[] }>();
    for (const item of items) {
        const name = nameOf(item);
        const person = personOf(name, data);
        const key = person ? `p:${person.toLowerCase()}` : `c:${name}`;
        const group = groups.get(key) ?? { person: person ?? name, known: person !== null, items: [] };
        group.items.push(item);
        groups.set(key, group);
    }
    return [...groups.values()];
}

export function usePilotNames() {
    const page = usePage();
    // Outside a map page (or in tests) there are no page props: names show as they are.
    const data = computed(() => (page?.props as unknown as { pilot_mains?: TPilotMains } | undefined)?.pilot_mains ?? null);
    return {
        data,
        label: (name: string | null | undefined) => pilotLabel(name, data.value),
        suffix: (name: string) => mainSuffix(name, data.value),
        person: (name: string | null | undefined) => personOf(name, data.value),
        detail: (name: string | null | undefined) => pilotDetail(name, data.value),
        after: (name: string) => personAfter(name, data.value),
    };
}
