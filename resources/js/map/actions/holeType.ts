import ConnectionHoleTypeController from '@/actions/App/Http/Controllers/ConnectionHoleTypeController';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

/** Patch 14: type a jumped hole from the map (it goes on the side it spawned from; the other side becomes the K162). */
export function setConnectionHoleType(connectionId: number, mapSolarsystemId: number, signatureTypeId: number, onSuccess?: () => void): void {
    router.post(
        ConnectionHoleTypeController.store(connectionId).url,
        { map_solarsystem_id: mapSolarsystemId, signature_type_id: signatureTypeId },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['map', 'selected_map_solarsystem'],
            onSuccess: () => onSuccess?.(),
            onError: (errors) => {
                toast.error(Object.values(errors)[0] ?? 'Could not set the hole type.');
            },
        },
    );
}

/** Patch 14: a pasted signature is the hole typed from the map: it gives its ID to that row. */
export function absorbSignature(typedId: number, pastedId: number, onSuccess?: () => void): void {
    router.post(
        ConnectionHoleTypeController.absorb(typedId).url,
        { pasted_id: pastedId },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['map', 'selected_map_solarsystem'],
            onSuccess: () => onSuccess?.(),
            onError: (errors) => {
                toast.error(Object.values(errors)[0] ?? 'Could not merge the signature.');
            },
        },
    );
}

/** Patch 16: the jump went through a different signature on that side: move the link to it. */
export function relinkConnection(connectionId: number, signatureId: number, onSuccess?: () => void): void {
    router.post(
        ConnectionHoleTypeController.relink(connectionId).url,
        { signature_id: signatureId },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['map', 'selected_map_solarsystem'],
            onSuccess: () => onSuccess?.(),
            onError: (errors) => {
                toast.error(Object.values(errors)[0] ?? 'Could not move the link.');
            },
        },
    );
}
