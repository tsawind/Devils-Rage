import SignatureArmController from '@/actions/App/Http/Controllers/SignatureArmController';
import { router } from '@inertiajs/vue3';
import { signatureToast as toast } from '@/lib/signatureToast';

/**
 * Patch 13: arm a hole as your next jump (it takes `alias`), "Arm as" another
 * number (`swap`: someone else's armed number is swapped, they get yours or
 * `fallback_alias`), or disarm it.
 */
export function armSignature(
    signatureId: number,
    data: { alias: string; fallback_alias?: string | null; swap?: boolean },
    onSuccess?: () => void,
    onFailure?: () => void,
): void {
    router.post(SignatureArmController.store(signatureId).url, data, {
        preserveScroll: true,
        preserveState: true,
        only: ['map', 'selected_map_solarsystem'],
        onSuccess: () => onSuccess?.(),
        onError: (errors) => {
            toast.error(Object.values(errors)[0] ?? 'Could not arm that hole.');
            onFailure?.();
        },
    });
}

export function disarmSignature(signatureId: number, onSuccess?: () => void): void {
    router.delete(SignatureArmController.destroy(signatureId).url, {
        preserveScroll: true,
        preserveState: true,
        only: ['map', 'selected_map_solarsystem'],
        onSuccess: () => onSuccess?.(),
        onError: (errors) => {
            toast.error(Object.values(errors)[0] ?? 'Could not disarm that hole.');
        },
    });
}
