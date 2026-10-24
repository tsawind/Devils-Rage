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

/** Patch 37: 👁 Watching on / off. Patch 40c: watching alone doesn't change the mass math. */
export function setConnectionWatched(connectionId: number, on: boolean): void {
    router.post(HoleCheckedController.watch(connectionId).url, { on }, {
        preserveScroll: true,
        preserveState: true,
        only: ['map'],
        onSuccess: () => toast.success(on ? 'Watching this hole' : 'Stopped watching'),
        onError: () => toast.error('Could not change watching.'),
    });
}

/** Patch 45: 👁 Watching on a hole nobody has jumped yet (a dotted pipe). */
export function setSignatureWatched(signatureId: number, on: boolean): void {
    router.post(HoleCheckedController.watchSignature(signatureId).url, { on }, {
        preserveScroll: true,
        preserveState: true,
        only: ['map', 'selected_map_solarsystem'],
        onSuccess: () => toast.success(on ? 'Watching: its pipe is watched once someone jumps it' : 'Stopped watching'),
        onError: () => toast.error('Could not change watching.'),
    });
}

const quiet = { preserveScroll: true, preserveState: true, only: ['map'] };

/**
 * Patch 40c: Brand new hole: marked fresh (not disrupted). With `watch` the watcher opens too.
 * Neither changes the mass math by itself; "every jump logged" (setCounting) does.
 */
export function markConnectionFresh(connectionId: number, watch: boolean): void {
    router.post(HoleCheckedController.fresh(connectionId).url, { watch }, {
        ...quiet,
        onSuccess: () => toast.success(watch ? 'Brand new · watching' : 'Marked brand new'),
        onError: () => toast.error('Could not mark it brand new.'),
    });
}

/** Patch 40c: the watcher's "every jump logged" switch: from now only logged jumps count. */
export function setCounting(connectionId: number, on: boolean): void {
    router.post(HoleCheckedController.watch(connectionId).url, { counting: on }, {
        ...quiet,
        onSuccess: () => toast.success(on ? 'Every jump logged: only jumps from now count' : 'Back to the normal mass math'),
        onError: () => toast.error('Could not change it.'),
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

/** Patch 52: a mark that doesn't fit the range a mass session kept: keep the range, or trust the mark. */
export function answerKeptRange(connectionId: number, choice: 'keep' | 'trust'): void {
    router.post(HoleCheckedController.kept(connectionId).url, { choice }, {
        ...quiet,
        onSuccess: () => toast.success(choice === 'keep' ? 'Kept the range' : 'Using the mark (kept range dropped)'),
        onError: () => toast.error('Could not change the kept range.'),
    });
}
