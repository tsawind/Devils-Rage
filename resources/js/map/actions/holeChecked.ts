import HoleCheckedController from '@/actions/App/Http/Controllers/HoleCheckedController';
import { signatureToast as toast } from '@/lib/signatureToast';
import { router } from '@inertiajs/vue3';

/**
 * Patch 37: ✓ Checked in game: someone looked at the hole's Show Info and its mass and
 * life are as shown. Resets the 4 h purple "?".
 */
export function markConnectionChecked(connectionId: number): void {
    router.post(HoleCheckedController.connection(connectionId).url, {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['map'],
        onSuccess: () => toast.success('Marked as checked in game'),
        onError: () => toast.error('Could not mark it as checked.'),
    });
}

export function markSignatureChecked(signatureId: number): void {
    router.post(HoleCheckedController.signature(signatureId).url, {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['map', 'selected_map_solarsystem'],
        onSuccess: () => toast.success('Marked as checked in game'),
        onError: () => toast.error('Could not mark it as checked.'),
    });
}

/** Patch 37: 👁 Watching on / off: every jump through this hole is logged. */
export function setConnectionWatched(connectionId: number, on: boolean): void {
    router.post(HoleCheckedController.watch(connectionId).url, { on }, {
        preserveScroll: true,
        preserveState: true,
        only: ['map'],
        onSuccess: () => toast.success(on ? 'Watching: every jump through it is logged' : 'Stopped watching: back to the normal mass math'),
        onError: () => toast.error('Could not change watching.'),
    });
}
