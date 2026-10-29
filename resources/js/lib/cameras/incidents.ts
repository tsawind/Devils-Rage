/**
 * Patch 62p: what one client's triggers saw within a minute is one incident: the first event
 * (with the clip and picture of the whole client) and the ones that point at it.
 */
export type TIncidentEvent = { id: number; incident_id?: number | null; kind: string; strength: number; state: string; happened_at: string };
export type TIncident<T extends TIncidentEvent> = { head: T; events: T[]; kinds: Record<string, number> };

export function groupIncidents<T extends TIncidentEvent>(events: readonly T[]): TIncident<T>[] {
    const byId = new Map(events.map((event) => [event.id, event]));
    const groups = new Map<number, TIncident<T>>();
    for (const event of events) {
        // An event whose incident isn't in the list (older, or pruned) stands on its own.
        const headId = event.incident_id && byId.has(event.incident_id) ? event.incident_id : event.id;
        const group = groups.get(headId) ?? { head: byId.get(headId) ?? event, events: [], kinds: {} };
        group.events.push(event);
        // Rows carry a count ("+2"): strength is 25 a row.
        const amount = event.kind === 'activation' ? 1 : Math.max(1, Math.round(event.strength / 25));
        group.kinds[event.kind] = (group.kinds[event.kind] ?? 0) + amount;
        groups.set(headId, group);
    }
    return [...groups.values()].sort((a, b) => Date.parse(b.head.happened_at) - Date.parse(a.head.happened_at));
}

const WORDS: Record<string, [string, string]> = {
    activation: ['⚡ Activation', '⚡ Activations'],
    appeared: ['＋ ship', '＋ ships'],
    vanished: ['－ ship', '－ ships'],
    scan_new: ['＋ scan result', '＋ scan results'],
    scan_gone: ['－ scan result', '－ scan results'],
    local_in: ['＋ in local', '＋ in local'],
    local_out: ['－ left local', '－ left local'],
};
const ORDER = ['activation', 'local_in', 'appeared', 'vanished', 'scan_new', 'scan_gone', 'local_out'];

/** "⚡ Activation · ＋2 ships · －1 ship" */
export function incidentSummary(kinds: Record<string, number>): string {
    return ORDER.filter((kind) => kinds[kind])
        .map((kind) => {
            const count = kinds[kind];
            const [one, many] = WORDS[kind] ?? [kind, kind];
            if (kind === 'activation') return count > 1 ? `${many} ×${count}` : one;
            const [sign, ...rest] = (count > 1 ? many : one).split(' ');
            return `${sign}${count > 1 ? count : ''} ${rest.join(' ')}`.replace(/^([＋－])1 /, '$1 ');
        })
        .join(' · ');
}
