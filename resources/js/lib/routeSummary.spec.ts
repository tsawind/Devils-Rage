import { FLEET_RULES } from '@/lib/fleetRules';
import { condenseRoute, EVE_SCOUT_FOLDER, gateWaypoints, holdFacts, routeDiagram, routeSummary, scanPlan, type TRouteHole, type TRouteHop, type TRouteStep } from '@/lib/routeSummary';
import { describe, expect, it } from 'vitest';

const step = (name: string, depth: number | null, extra: Partial<TRouteStep> = {}): TRouteStep => ({ name, mapped: depth !== null, depth, rage: false, ...extra });
const hole = (extra: Partial<TRouteHole> = {}): TRouteHole => ({
    typeName: 'D845',
    totalMass: 5_000_000_000,
    maxJumpMass: 375_000_000,
    jumpedMass: 0,
    massStatus: 'fresh',
    lifetimeStatus: 'healthy',
    eolSince: null,
    nearSignature: null,
    ...extra,
});
const wh = (extra: Partial<TRouteHole> = {}): TRouteHop => ({ via: 'wormhole', hole: hole(extra) });
const gate: TRouteHop = { via: 'stargate', hole: null };
const now = new Date('2026-10-05T12:00:00Z');

describe('patch 26: the route line', () => {
    it('down your own chain: only the deepest point', () => {
        const steps = [step('Daisy', 0), step('Alpha', 1), step('A1', 2), step('A11', 3), step('A112-11', 4)];
        expect(condenseRoute(steps, [wh(), wh(), wh(), wh()])).toBe('Daisy → A112-11');
    });

    it('out to k-space: the way out with its sig, the gate entry and the destination', () => {
        const steps = [step('Daisy', 0), step('Alpha', 1), step('A121', 2), step('Niarja', null), step('Ahbazon', null), step('Amarr', null)];
        expect(condenseRoute(steps, [wh(), wh(), wh({ nearSignature: 'ABC-123' }), gate, gate])).toBe('Daisy → A121 (ABC) → Niarja → 2j → Amarr');
    });

    it('coming home: the * way-back bookmarks where it matters', () => {
        const steps = [step('Amarr', null), step('Badivefi', null), step('1112', 4), step('111', 3), step('11', 2), step('A121-1', 1), step('Alpha', 0)];
        expect(condenseRoute(steps, [gate, wh(), wh(), wh(), wh(), wh()])).toBe('Amarr → 1j → Badivefi → *1112 → *Alpha');
    });

    it('over the top of the chain: the turning point is written down', () => {
        const steps = [step('A12', 3), step('A1', 2), step('Alpha', 1), step('Daisy', 0), step('Bravo', 1), step('B1', 2)];
        expect(condenseRoute(steps, [wh(), wh(), wh(), wh(), wh()])).toBe('A12 → *Daisy → B1');
    });

    it('rage chains: only the start and the last return bookmark', () => {
        const steps = [step('Daisy', 0), step('1', 1, { rage: true }), step('11', 2, { rage: true }), step('111', 3, { rage: true }), step('Jita', null)];
        expect(condenseRoute(steps, [wh(), wh(), wh(), wh()])).toBe('Daisy → 1 → 111 → Jita');
    });
});

describe('patch 26: size, mass, chokepoints and risks', () => {
    const steps = [step('Daisy', 0), step('Alpha', 1), step('A12', 2), step('A121', 3)];

    it('size from the smallest hole, mass from the tightest, counted cold with plates on', () => {
        const text = routeSummary({ steps, hops: [wh(), wh({ totalMass: 3_000_000_000, typeName: 'B274' }), wh()], roundTrip: false, now });
        expect(text).toContain('Battleship sized');
        // Patch 37: battleships worst ↔ best case, the rest "at least".
        const low = Math.floor((3_000_000_000 * 0.9 * 0.5) / FLEET_RULES.shipMass.battleship);
        const high = Math.floor((3_000_000_000 * 1.1) / FLEET_RULES.shipMass.battleship);
        expect(text).toContain(`≈ ${low}↔${high} BS / `);
        expect(text).toMatch(/\d+\+ BC \/ \d+\+ cruisers/);
        expect(text).toContain('Choke: Alpha↔A12 "B274"');
    });

    it('in and back out halves the mass', () => {
        const oneWay = routeSummary({ steps, hops: [wh(), wh(), wh()], roundTrip: false, now });
        const both = routeSummary({ steps, hops: [wh(), wh(), wh()], roundTrip: true, now });
        expect(both).toContain('in+out');
        expect(oneWay).not.toContain('in+out');
    });

    it('a medium hole on the way: no battleship count', () => {
        const text = routeSummary({ steps, hops: [wh(), wh({ maxJumpMass: 62_000_000, totalMass: 1_000_000_000 }), wh()], roundTrip: false, now });
        expect(text).toContain('Medium sized');
        expect(text).not.toContain(' BS');
    });

    it('patch 37: EOL as a collapse window in EVE time, from the first mark', () => {
        const text = routeSummary({ steps, hops: [wh({ lifetimeStatus: 'eol', eolSince: '2026-10-05T08:40:00Z' }), wh(), wh()], roundTrip: false, now });
        expect(text).toContain('Risk: Daisy↔Alpha EOL, collapsing now–12:40: scout it');
    });

    it('patch 37: a check 5 minutes ago guarantees 55 minutes, no "scout it"', () => {
        const hop = wh({ lifetimeStatus: 'eol', eolSince: '2026-10-05T11:30:00Z', lastLookAt: '2026-10-05T11:55:00Z' });
        const text = routeSummary({ steps, hops: [hop, wh(), wh()], roundTrip: false, now });
        expect(text).toContain('Risk: Daisy↔Alpha EOL, collapsing 12:50–15:30');
        expect(text).not.toContain('scout it');
    });

    it('patch 37: critical: now until an hour after the first critical mark, always "scout it"', () => {
        const early = wh({ lifetimeStatus: 'critical', eolSince: '2026-10-05T10:00:00Z', criticalSince: '2026-10-05T11:30:00Z' });
        expect(routeSummary({ steps, hops: [early, wh(), wh()], roundTrip: false, now })).toContain('Risk: Daisy↔Alpha EOL!, collapsing now–12:30: scout it');
        const late = wh({ lifetimeStatus: 'critical', eolSince: '2026-10-05T10:00:00Z', criticalSince: '2026-10-05T11:05:00Z' });
        expect(routeSummary({ steps, hops: [late, wh(), wh()], roundTrip: false, now })).toContain('Risk: Daisy↔Alpha EOL!, collapsing now–12:05: scout it');
    });

    it('patch 37: imminent: closing now, scout it', () => {
        const hop = wh({ lifetimeStatus: 'imminent', eolSince: '2026-10-05T09:00:00Z', criticalSince: '2026-10-05T10:40:00Z', imminentSince: '2026-10-05T11:40:00Z' });
        expect(routeSummary({ steps, hops: [hop, wh(), wh()], roundTrip: false, now })).toContain('Risk: Daisy↔Alpha closing now (imminent since 11:40): scout it');
    });

    it('patch 37: past the latest time it is probably gone', () => {
        const text = routeSummary({ steps, hops: [wh({ lifetimeStatus: 'eol', eolSince: '2026-10-05T07:00:00Z' }), wh(), wh()], roundTrip: false, now });
        expect(text).toContain('Risk: Daisy↔Alpha EOL, probably gone (since 11:00): scout it');
    });

    it('never longer than the chat limit, and the route always stays', () => {
        const long = Array.from({ length: 120 }, (_, index) => step(`Very-Long-Name-${index}`, null));
        const text = routeSummary({ steps: long, hops: long.slice(1).map(() => gate), roundTrip: false, now });
        expect(text.length).toBeLessThanOrEqual(FLEET_RULES.maxCopyLength);
        expect(text.startsWith('119j: Very-Long-Name-0')).toBe(true);
    });
});

describe('patch 26: experimental predictions', () => {
    const steps = [step('Daisy', 0), step('Alpha', 1), step('A12', 2)];

    it('Will it hold? gives facts for the risky holes', () => {
        const text = holdFacts({ steps, hops: [wh(), wh({ massStatus: 'critical', totalMass: 3_000_000_000, maxJumpMass: 2_000_000_000 })], roundTrip: false, now });
        expect(text).toContain('Will it hold? (experimental, one way)');
        expect(text).toContain('Alpha↔A12 "D845": critical:');
        expect(text).toContain('BS worst case');
    });

    it('Will it hold? says so when nothing is risky', () => {
        expect(holdFacts({ steps: [step('Daisy', 0), step('Jita', null)], hops: [gate], roundTrip: false, now })).toContain('Nothing risky known');
    });

    it('Scan plan: up to three, with why', () => {
        const text = scanPlan(
            [
                { name: 'A12', unscannedSigs: 3, unfoundStatics: [] },
                { name: 'A1', unscannedSigs: 0, unfoundStatics: ['c3'] },
                { name: 'Alpha', unscannedSigs: 0, unfoundStatics: [] },
                { name: 'Daisy', unscannedSigs: 2, unfoundStatics: [] },
                { name: 'X', unscannedSigs: 1, unfoundStatics: [] },
            ],
            true,
        );
        expect(text).toBe('No backup route. Scan (experimental): A12 (3 unscanned sigs), A1 (C3 static not found), Daisy (2 unscanned sigs)');
    });
});

describe('patch 29d: jump counts and the route kind', () => {
    it('names the kind and the total jumps at the front', () => {
        const steps = [step('Daisy', 0), step('Alpha', 1), step('A1', 2), step('Jita', null), step('Perimeter', null)];
        const text = routeSummary({ steps, hops: [wh(), wh(), wh(), gate], roundTrip: false, now, kind: 'Safest' });
        expect(text.startsWith('Safest 4j: Daisy → A1 → Jita → 1j → Perimeter')).toBe(true);
    });

    it('counts a long gate stretch', () => {
        const kspace = Array.from({ length: 8 }, (_, index) => step(`K${index}`, null));
        const steps = [step('Daisy', 0), ...kspace];
        expect(condenseRoute(steps, [wh(), ...kspace.slice(1).map(() => gate)])).toBe('Daisy → K0 → 7j → K7');
    });

    it('patch 37: out through a mapped k-space system, one gate count to the end ("A324-0 Turba → 15j → Jita")', () => {
        const gates = Array.from({ length: 14 }, (_, index) => step(index === 0 ? 'Yooh' : `G${index}`, null));
        const steps = [step('Daisy', 0), step('A3', 1), step('A32', 2), step('A324', 3), step('A324-0', 4, { system: 'Turba' }), ...gates, step('Jita', null)];
        const hops = [wh(), wh(), wh(), wh(), ...Array.from({ length: 15 }, () => gate)];
        expect(condenseRoute(steps, hops)).toBe('Daisy → A324-0 Turba → 15j → Jita');
    });
});

describe('patch 32: frigate-only routes skip the mass', () => {
    it('says "Frig sized" with no mass, fleet count or chokepoint', () => {
        const steps = [step('Daisy', 0), step('Bravo', 1), step('Jita', null)];
        const frig = wh({ typeName: 'Q003', totalMass: 1_000_000_000, maxJumpMass: 5_000_000 });
        const text = routeSummary({ steps, hops: [frig, wh()], roundTrip: false, now });
        expect(text).toContain('Frig sized');
        expect(text).not.toContain(' kg');
        expect(text).not.toContain('Choke');
    });
});

describe('patch 37: EVE-Scout holes, linking and waypoints', () => {
    const scout: TRouteHop = { via: 'evescout', hole: null };
    const k = (name: string, id: number) => step(name, null, { id });
    // Daisy → A1 (Zephan) → 5 gates → Naga ⇢ Turnur ⇢ Hampinen → 7 gates → Jita
    const steps = [
        step('Daisy', 0, { id: 31002171 }),
        step('A1', 1, { system: 'Zephan', id: 30001 }),
        k('G1', 2), k('G2', 3), k('G3', 4), k('G4', 5),
        k('Naga', 30002),
        k('Turnur', 30002086),
        k('Hampinen', 30003),
        k('H1', 4), k('H2', 5), k('H3', 6), k('H4', 7), k('H5', 8), k('H6', 9),
        k('Jita', 30000142),
    ];
    const hops: TRouteHop[] = [wh(), gate, gate, gate, gate, gate, scout, scout, gate, gate, gate, gate, gate, gate, gate];

    it('keeps both ends of every EVE-Scout hole and marks them', () => {
        expect(condenseRoute(steps, hops)).toBe('Daisy → A1 Zephan → 5j → Naga ⇢ES⇢ Turnur ⇢ES⇢ Hampinen → 7j → Jita');
    });

    it('says where the EVE-Scout bookmarks are', () => {
        expect(EVE_SCOUT_FOLDER).toBe('EvE-Scout \\\\ Hubs');
        expect(routeSummary({ steps, hops, roundTrip: false, now })).toContain('| bm-folder "EvE-Scout \\\\ Hubs" via Turnur');
    });

    it('one real system per line, for Auto Link', () => {
        expect(condenseRoute(steps, hops, { layout: 'lines' }).split('\n')).toEqual([
            'Daisy → A1',
            'Zephan · 5 gates',
            'Naga ⇢ EVE-Scout hole',
            'Turnur ⇢ EVE-Scout hole',
            'Hampinen · 7 gates',
            'Jita',
        ]);
    });

    it('Notepad links wrap the systems in EVE link codes; mail uses mail links', () => {
        expect(condenseRoute(steps, hops, { links: 'notepad' })).toContain('<url=showinfo:5//30000142>Jita</url>');
        expect(condenseRoute(steps, hops, { links: 'notepad' })).toContain('A1 <url=showinfo:5//30001>Zephan</url>');
        expect(condenseRoute(steps, hops, { layout: 'lines', links: 'mail' })).toContain('<a href="showinfo:5//30002">Naga</a> ⇢ EVE-Scout hole<br>');
    });

    it('the chat limit counts what people see, not the link codes', () => {
        const text = routeSummary({ steps, hops, roundTrip: false, now }, null, { links: 'notepad' });
        expect(text.replace(/<url=[^>]*>|<\/url>/g, '').length).toBeLessThanOrEqual(FLEET_RULES.maxCopyLength);
    });

    it('in-game waypoints: the end of every gate leg', () => {
        expect(gateWaypoints(steps, hops)).toEqual([30002, 30000142]);
    });

    it('patch 37: Will it hold never sends battleships down a frigate hole', () => {
        const steps = [step('Daisy', 0), step('Golf', 1)];
        const text = holdFacts({ steps, hops: [wh({ typeName: 'Z006', lifetimeStatus: 'eol', eolSince: now.toISOString(), totalMass: 3_000_000_000, maxJumpMass: 5_000_000 })], roundTrip: false, now });
        expect(text).toContain('frigates only');
        expect(text).not.toContain('BS');
    });

    it('patch 37: Will it hold counts cruisers on a medium hole', () => {
        const steps = [step('Daisy', 0), step('Golf', 1)];
        const text = holdFacts({ steps, hops: [wh({ massStatus: 'reduced', totalMass: 1_000_000_000, maxJumpMass: 62_000_000 })], roundTrip: false, now });
        expect(text).toContain('cruisers worst case');
        expect(text).not.toContain('BS');
    });
});

describe('patch 37: the mail and route page list every choke and risk', () => {
    const steps = [step('Daisy', 0), step('Bravo', 1), step('B0', 2), step('B01', 3), step('Jita', null, { cls: 'h' })];
    const hops: TRouteHop[] = [
        wh({ typeName: 'V753', massStatus: 'fresh', totalMass: 3_000_000_000, maxJumpMass: 1_000_000_000 }),
        wh({ typeName: 'L477', massStatus: 'reduced', totalMass: 2_000_000_000 }),
        wh({ typeName: null, totalMass: 2_000_000_000, lastLookAt: '2026-10-05T06:00:00Z' }),
        gate,
    ];

    it('puts each choke and risk on its own line', () => {
        const text = routeSummary({ steps, hops, roundTrip: false, now }, null, { layout: 'lines' });
        const listed = text.split('\n').filter((line) => line.startsWith('Choke:') || line.startsWith('Risk:'));
        expect(listed.length).toBeGreaterThan(1);
        expect(text).toContain('not checked for 6h');
        expect(text).toContain('type unknown');
    });

    it('keeps the one-line chat copy short', () => {
        const text = routeSummary({ steps, hops, roundTrip: false, now });
        expect(text).not.toContain('not checked');
    });

    it('draws the route: holes one by one, gates as one link', () => {
        const diagram = routeDiagram({ steps, hops, roundTrip: false, now });
        expect(diagram.links.map((link) => link.kind)).toEqual(['hole', 'hole', 'hole', 'gates']);
        expect(diagram.nodes.at(-1)?.kspace).toBe(true);
    });
});

describe('patch 37: Will it hold with the real fleet', () => {
    const steps = [step('Daisy', 0), step('Golf', 1)];
    const fleet = { ships: 3, mass: 300_000_000, ofTotal: 5, hulls: [{ ship: 'Megathron', jumpMass: 100_000_000 }, { ship: 'Megathron', jumpMass: 100_000_000 }, { ship: 'Archon', jumpMass: 1_100_000_000 }] };

    it('names the ships too big for the hole', () => {
        const text = holdFacts({ steps, hops: [wh({ maxJumpMass: 375_000_000, massStatus: 'reduced', totalMass: 2_000_000_000 })], roundTrip: false, now }, fleet);
        expect(text).toContain('fleet 3 of 5 ships');
        expect(text).toContain('1 too big for 375m per jump (Archon)');
    });

    it('says whether the fleet mass holds', () => {
        const text = holdFacts({ steps, hops: [wh({ maxJumpMass: 2_000_000_000, massStatus: 'critical', totalMass: 3_300_000_000 })], roundTrip: false, now }, { ...fleet, hulls: fleet.hulls.slice(0, 2), mass: 200_000_000 });
        expect(text).toMatch(/fleet 200m: (holds|may not hold|won't hold)/);
        expect(text).not.toContain('BS worst case');
    });
});
