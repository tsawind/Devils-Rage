import MapCombatController from '@/actions/App/Http/Controllers/MapCombatController';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

/**
 * How combat mode is turned on: start a chain in a system (a map system, or by
 * its solar system when it isn't on the map yet: it gets added), join a chain
 * by color, or combat speed alone.
 */
export type TCombatStart =
    | { mode: 'start'; map_solarsystem_id: number }
    | { mode: 'start'; solarsystem_id: number }
    | { mode: 'join'; color: string }
    | { mode: 'solo' };

export function startCombat(mapSlug: string, data: TCombatStart, onSuccess?: () => void): void {
    router.post(MapCombatController.store(mapSlug).url, data, {
        preserveScroll: true,
        preserveState: true,
        only: ['map_user_settings', 'map'],
        onSuccess: () => onSuccess?.(),
        onError: (errors) => {
            toast.error(errors.combat ?? 'Could not turn combat mode on.');
        },
    });
}

export function stopCombat(mapSlug: string): void {
    router.delete(MapCombatController.destroy(mapSlug).url, {
        preserveScroll: true,
        preserveState: true,
        only: ['map_user_settings', 'map'],
        onError: () => {
            toast.error('Could not turn combat mode off.');
        },
    });
}

/** Take a chain's colors off the map (its systems and numbers stay). */
export function clearCombatChain(mapSolarsystemId: number): void {
    router.delete(MapCombatController.clear(mapSolarsystemId).url, {
        preserveScroll: true,
        preserveState: true,
        only: ['map_user_settings', 'map'],
        onSuccess: () => {
            toast.success('Combat chain cleared');
        },
        onError: () => {
            toast.error('Could not clear the combat chain.');
        },
    });
}
