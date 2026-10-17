import Signatures from '@/routes/signatures';
import { TSignature } from '@/types/models';
import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { signatureToast as toast } from '@/lib/signatureToast';
import { holeKey, recopyAfterChange } from '@/lib/recopy';

export function updateSignature(signature: TSignature, data: Record<string, FormDataConvertible>): void {
    return router.put(Signatures.update(signature.id).url, data, {
        preserveScroll: true,
        preserveState: true,
        only: ['map', 'selected_map_solarsystem'],
        // Patch 39: a change to a hole copied in the last 20 s copies its updated name.
        onSuccess: () => recopyAfterChange(holeKey(signature.id)),
        onError: (errors) => {
            // Show why the change was refused (e.g. a number already in use).
            const message = Object.values(errors)[0];
            if (message) toast.error(message);
            router.reload({ only: ['map'] });
        },
    });
}
