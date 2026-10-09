import { useRageRoll } from '@/composables/useRageRoll';
import { displayAlias } from '@/lib/alias';
import { targetHits, type TTargetHit } from '@/lib/rageRoll';
import { playRageHitSound, unlockRageSound } from '@/lib/rageSound';
import { soundsEnabled } from '@/composables/useMapEffects';
import { useMapStore } from '@/map/store/mapStore';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

/** The latest target hit of the current roll ("Alpha → J123456"), shared with the top-bar chip. */
export const rageRollHit = ref<{ key: string; text: string } | null>(null);

/**
 * Patch 35: mounted once with the map. When the static being rolled opens into a
 * target system, everyone with the map open hears the drop and sees the chip turn
 * green. Hits already there when the page loads are shown but not played again.
 */
export function useRageRollWatcher(): void {
    const store = useMapStore();
    const { rageRoll } = useRageRoll();
    const stopUnlock = unlockRageSound();
    onBeforeUnmount(stopUnlock);

    const rollingSystemId = computed(() => {
        const roll = rageRoll.value;
        if (!roll) return null;
        for (const system of store.systems.values()) {
            if (system.solarsystem_id === roll.solarsystem_id) return system.id;
        }
        return null;
    });

    const hits = computed<TTargetHit[]>(() => {
        const roll = rageRoll.value;
        const rollingId = rollingSystemId.value;
        if (!roll || rollingId === null) return [];
        return targetHits(
            rollingId,
            roll.targets.map((target) => target.id),
            store.systems,
            store.connections.values(),
            roll.started_at,
        );
    });

    const seen = new Set<string>();
    let seededFor: string | null = null;

    watch(
        [hits, () => rageRoll.value?.started_at ?? null],
        ([current, startedAt]) => {
            if (!startedAt) {
                rageRollHit.value = null;
                seen.clear();
                seededFor = null;
                return;
            }

            const fresh = seededFor === startedAt;
            if (!fresh) {
                seen.clear();
                seededFor = startedAt;
            }

            const describe = (hit: TTargetHit) => {
                const far = store.systems.get(hit.mapSolarsystemId);
                const rolling = rollingSystemId.value !== null ? store.systems.get(rollingSystemId.value) : null;
                const from = rolling ? displayAlias(rolling.alias) || rolling.solarsystem.name : 'the static';
                const into = far ? `${far.solarsystem.name}${far.alias ? ` (${displayAlias(far.alias)})` : ''}` : 'the target';
                return `${from} → ${into}`;
            };

            for (const hit of current) {
                const key = `${hit.connectionId}:${hit.solarsystemId}`;
                if (seen.has(key)) continue;
                seen.add(key);
                rageRollHit.value = { key, text: describe(hit) };
                if (fresh) {
                    if (soundsEnabled()) playRageHitSound();
                    toast.success('🎯 Target hit!', { description: describe(hit), duration: 15_000 });
                }
            }

            if (current.length === 0) rageRollHit.value = null;
        },
        { immediate: true },
    );
}
