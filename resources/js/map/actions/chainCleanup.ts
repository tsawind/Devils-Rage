import ChainCleanupController from '@/actions/App/Http/Controllers/ChainCleanupController';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

/** Patch 14: clean a combat chain up into another chain, one system at a time. */

const options = (success?: () => void, failure = 'Something went wrong.') => ({
    preserveScroll: true,
    preserveState: true,
    only: ['map', 'selected_map_solarsystem', 'map_user_settings'],
    onSuccess: () => success?.(),
    onError: (errors: Record<string, string>) => {
        toast.error(Object.values(errors)[0] ?? failure);
    },
});

export function startChainCleanup(homeId: number, label: string): void {
    router.post(
        ChainCleanupController.store(homeId).url,
        {},
        options(
            () => toast.success(`Cleaning up the ${label} chain`, { description: 'Follow the cleanup rows at the top of each system’s signature list.' }),
            'Could not start the cleanup.',
        ),
    );
}

export function convertCleanupSystem(systemId: number, parentId: number, alias: string, onSuccess?: () => void): void {
    router.post(ChainCleanupController.convert(systemId).url, { parent_id: parentId, alias }, options(onSuccess, 'Could not convert that system.'));
}

export function finishCleanupReturn(systemId: number): void {
    router.delete(ChainCleanupController.returnDone(systemId).url, options(undefined, 'Could not tick the way back.'));
}
