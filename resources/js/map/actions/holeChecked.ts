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

const quiet = { preserveScroll: true, preserveState: true, only: ['map'] };

/**
 * Patch 39: Mark fresh: its range goes back to its size ±10%; earlier jumps stay in the log but
 * stop counting ("ours only" from now). With `watch` the watch banner opens, else a Fresh chip.
 */
export function markConnectionFresh(connectionId: number, watch: boolean): void {
    router.post(HoleCheckedController.fresh(connectionId).url, { watch }, {
        ...quiet,
        onSuccess: () => toast.success(watch ? 'Fresh · watching: every jump counts from now' : 'Marked fresh: only jumps from now count'),
        onError: () => toast.error('Could not mark it fresh.'),
    });
}

/** Patch 39: open the watch banner, or fold it to the Fresh chip. */
export function setWatchOpen(connectionId: number, open: boolean): void {
    router.post(HoleCheckedController.watch(connectionId).url, { open }, quiet);
}

/** Patch 39: "still on it?": yes. */
export function touchWatch(connectionId: number): void {
    router.post(HoleCheckedController.watch(connectionId).url, { touch: true }, quiet);
}
