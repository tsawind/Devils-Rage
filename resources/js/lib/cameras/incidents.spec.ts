import { groupIncidents, incidentSummary } from '@/lib/cameras/incidents';
import { describe, expect, it } from 'vitest';

const at = (minute: number) => `2026-10-29T18:${String(minute).padStart(2, '0')}:00Z`;

describe('camera incidents', () => {
    it('groups the events of an incident under its first one, newest incident first', () => {
        const groups = groupIncidents([
            { id: 1, incident_id: null, kind: 'activation', strength: 70, state: 'new', happened_at: at(10) },
            { id: 2, incident_id: 1, kind: 'appeared', strength: 50, state: 'new', happened_at: at(10) },
            { id: 3, incident_id: null, kind: 'vanished', strength: 25, state: 'new', happened_at: at(20) },
            { id: 4, incident_id: 1, kind: 'vanished', strength: 25, state: 'new', happened_at: at(11) },
        ]);
        expect(groups.map((group) => group.head.id)).toEqual([3, 1]);
        expect(groups[1].events.map((event) => event.id)).toEqual([1, 2, 4]);
        expect(groups[1].kinds).toEqual({ activation: 1, appeared: 2, vanished: 1 });
    });

    it('lets an event whose incident is gone stand on its own', () => {
        const groups = groupIncidents([{ id: 9, incident_id: 5, kind: 'appeared', strength: 25, state: 'new', happened_at: at(1) }]);
        expect(groups).toHaveLength(1);
        expect(groups[0].head.id).toBe(9);
    });

    it('says what happened in a few words', () => {
        expect(incidentSummary({ activation: 1, appeared: 2, vanished: 1 })).toBe('⚡ Activation · ＋2 ships · － ship');
        expect(incidentSummary({ activation: 3 })).toBe('⚡ Activations ×3');
        expect(incidentSummary({ local_in: 1 })).toBe('＋ in local');
    });
});
