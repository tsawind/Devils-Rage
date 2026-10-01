import { holeSide } from '@/lib/holeSide';
import { describe, expect, it } from 'vitest';

const D211 = { id: 1, class: '5' };
const QM = { id: 2, class: 'n' };

describe('patch 14: which side a hole type belongs to', () => {
    it('N432 (nullsec → C5) on a C5 ↔ nullsec connection is the nullsec side', () => {
        expect(holeSide({ spawn_areas: ['n'], target_class: '5' }, D211, QM)).toEqual({ sideId: 2, fits: true });
    });

    it('a C5 hole to nullsec is the C5 side', () => {
        expect(holeSide({ spawn_areas: ['5', '6'], target_class: 'n' }, D211, QM)).toEqual({ sideId: 1, fits: true });
    });

    it('falls back to where it can spawn when the classes do not both fit', () => {
        expect(holeSide({ spawn_areas: ['n'], target_class: '12' }, D211, QM)).toEqual({ sideId: 2, fits: false });
        expect(holeSide({ spawn_areas: ['2'], target_class: '1' }, D211, QM)).toBeNull();
    });
});
