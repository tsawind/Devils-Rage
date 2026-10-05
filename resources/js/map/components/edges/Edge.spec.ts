// @vitest-environment happy-dom
import Edge from '@/map/components/edges/Edge.vue';
import type { EdgeGeometry } from '@/map/core/types';
import type { TMapConnection } from '@/pages/maps';
import type { TShipSize } from '@/types/models';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const geometry: EdgeGeometry = {
    id: 1,
    kind: 'curve',
    from: { x: 0, y: 0 },
    to: { x: 100, y: 100 },
};

function connection(overrides: Partial<TMapConnection> = {}): TMapConnection {
    return {
        id: 1,
        from_map_solarsystem_id: 1,
        to_map_solarsystem_id: 2,
        type: 'wormhole',
        preserve_mass: false,
        mass_status: 'fresh',
        lifetime_status: 'healthy',
        lifetime_status_updated_at: null,
        signatures: [],
        ship_size: 'large',
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        ...overrides,
    } as TMapConnection;
}

/** The badge cluster's text (patch 16: the hover title now also carries the hole's age). */
function badgeText(overrides: Partial<TMapConnection> = {}): string {
    return mount(Edge, { props: { geometry, connection: connection(overrides), scale: 1 } })
        .findAll('foreignObject')
        .map((badges) => badges.text())
        .join('')
        .trim();
}

describe('Edge ship size badge', () => {
    it.each<[TShipSize, string]>([
        ['frigate', 'S'],
        ['medium', 'M'],
        ['large', 'L'],
        ['xlarge', 'XL'],
    ])('labels a %s wormhole with %s', (ship_size, letter) => {
        expect(badgeText({ ship_size })).toBe(letter);
    });

    it('omits the badge for stargates, whose size is only a column default', () => {
        expect(badgeText({ type: 'stargate', ship_size: 'large' })).toBe('');
    });

    it('omits the badge while nobody has said what fits', () => {
        expect(badgeText({ ship_size: null })).toBe('');
    });

    it('omits the badge on the ghost edge drawn while dragging a new connection', () => {
        expect(
            mount(Edge, { props: { geometry, connection: null, scale: 1 } })
                .text()
                .trim(),
        ).toBe('');
    });
});

describe('patch 21: Static and EOL inside the pill', () => {
    it('puts the static in the pill, in green', () => {
        const wrapper = mount(Edge, { props: { geometry, connection: connection(), staticEnd: { side: 'from', doubt: false }, scale: 1 } });
        const pill = wrapper.find('foreignObject');
        expect(pill.text()).toContain('Static');
        expect(pill.find('.bg-green-800').exists()).toBe(true);
        // No separate floating "Static" label any more.
        expect(wrapper.findAll('text').some((text) => text.text().includes('Static'))).toBe(false);
    });

    it('shows end of life as EOL (EOL! when critical) inside the pill', () => {
        expect(badgeText({ lifetime_status: 'eol' })).toContain('EOL');
        expect(badgeText({ lifetime_status: 'critical' })).toContain('EOL!');
    });

    it('clicking the pill opens the pipe details', async () => {
        const wrapper = mount(Edge, { props: { geometry, connection: connection(), scale: 1 } });
        await wrapper.find('foreignObject').trigger('click');
        expect(wrapper.emitted('connectionClick')).toHaveLength(1);
    });
});

describe('patch 23: hub-lane pills point up or down', () => {
    // Hole 2 sits above hub 1; the pipe runs straight down from the hole to the hub.
    const lane: EdgeGeometry = {
        id: 1,
        kind: 'elbow',
        from: { x: 210, y: 100 },
        to: { x: 210, y: 300 },
        fromNormal: { x: 0, y: 1 },
        toNormal: { x: 0, y: -1 },
        bend: null,
        pillAt: 'from',
    };
    const arrowOf = (geometry: EdgeGeometry, openedIn: number) =>
        mount(Edge, {
            props: {
                geometry,
                scale: 1,
                connection: connection({
                    from_map_solarsystem_id: 2,
                    to_map_solarsystem_id: 1,
                    signatures: [{ id: 5, map_solarsystem_id: openedIn, wormhole: { name: 'V753' } }] as TMapConnection['signatures'],
                }),
            },
        })
            .find('foreignObject svg')
            .attributes('aria-label');

    it('opened in the hub below: up toward the hole', () => {
        expect(arrowOf(lane, 1)).toContain('goes up');
    });

    it('opened in the hole above: down toward the hub', () => {
        expect(arrowOf(lane, 2)).toContain('goes down');
    });

    it('an ordinary pipe keeps left / right', () => {
        expect(arrowOf({ ...lane, pillAt: undefined, to: { x: 460, y: 100 } }, 2)).toBe('Opened on this side: it goes toward its K162 exit');
    });
});
