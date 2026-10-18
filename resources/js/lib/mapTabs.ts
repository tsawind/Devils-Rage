import { getMapChannelName } from '@/const/channels';
import { KillmailReceivedEvent } from '@/const/events';
import { echo } from '@laravel/echo-vue';
import { useSessionStorage } from '@vueuse/core';
import { ref } from 'vue';

/**
 * Patch 42: map tabs.
 *
 * Kept tabs: a map becomes a tab only when you open it from the map picker or the All
 * maps page (or keep it with the pin on its tab). A map you reach from a link shows as a
 * passing tab until you leave it.
 *
 * Badges: kills (not high-sec) and notices on a tab's map while you're on another map.
 * They clear when you open that map, and last until this browser tab closes.
 */
export const keepNextMap = ref<string | null>(null);

/** Called by the map picker / All maps cards just before they open a map. */
export function openingAsTab(slug: string): void {
    keepNextMap.value = slug;
}

export type TTabAlerts = { kills: number; capitals: number; notices: number };

export const tabAlerts = useSessionStorage<Record<string, TTabAlerts>>('map-tab-alerts', {});
export const currentMapId = ref<number | null>(null);

function bump(mapId: number, change: Partial<TTabAlerts>): void {
    if (mapId === currentMapId.value) return;
    const now = tabAlerts.value[mapId] ?? { kills: 0, capitals: 0, notices: 0 };
    tabAlerts.value = {
        ...tabAlerts.value,
        [mapId]: { kills: now.kills + (change.kills ?? 0), capitals: now.capitals + (change.capitals ?? 0), notices: now.notices + (change.notices ?? 0) },
    };
}

export function noteMapNotice(mapId: number): void {
    bump(mapId, { notices: 1 });
}

export function clearTabAlerts(mapId: number): void {
    if (!tabAlerts.value[mapId]) return;
    const rest = { ...tabAlerts.value };
    delete rest[mapId];
    tabAlerts.value = rest;
}

type TKillEvent = { killmail: { is_capital: boolean; highsec?: boolean } | null };

/** The channel this window listens on per map (the map page leaves a map's channel when you leave the map, so it's checked again). */
const listening = new Map<number, unknown>();

function stillSubscribed(mapId: number): boolean {
    const channel = listening.get(mapId);
    if (!channel) return false;
    const channels = (echo().connector as { channels?: Record<string, unknown> }).channels ?? {};
    return channels[`private-${getMapChannelName(mapId)}`] === channel;
}

/** Listen for kills on the tabs' maps (call again now and then: it only fixes what's missing). */
export function watchTabMaps(mapIds: number[]): void {
    if (typeof window === 'undefined') return;
    for (const mapId of mapIds) {
        try {
            if (stillSubscribed(mapId)) continue;
            const channel = echo().private(getMapChannelName(mapId));
            channel.listen(KillmailReceivedEvent, (event: TKillEvent) => {
                if (!event.killmail || event.killmail.highsec) return;
                bump(mapId, { kills: 1, capitals: event.killmail.is_capital ? 1 : 0 });
            });
            listening.set(mapId, channel);
        } catch {
            listening.delete(mapId);
        }
    }
}

export function describeAlerts(alerts: TTabAlerts | undefined): string {
    if (!alerts) return '';
    const parts: string[] = [];
    if (alerts.kills) parts.push(`${alerts.kills} kill${alerts.kills === 1 ? '' : 's'}${alerts.capitals ? ` (${alerts.capitals} capital)` : ''}`);
    if (alerts.notices) parts.push(`${alerts.notices} notice${alerts.notices === 1 ? '' : 's'}`);
    return parts.length ? `${parts.join(', ')} since you looked` : '';
}
