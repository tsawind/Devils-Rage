import { TRawSignature } from '@/lib/SignatureParser';
import PasteSignatures from '@/routes/paste-signatures';
import { recordRestorable, undoToken } from '@/composables/undo/mapUndo';
import { lastPaste } from '@/composables/useRageRoll';
import { router, usePage } from '@inertiajs/vue3';

export function pasteSignatures(map_solarsystem_id: number, signatures: TRawSignature[], onSuccess?: () => void): void {
    // Patch 21: the server saves the system's signatures first, so Undo can put them back.
    const token = undoToken();
    // Patch 35: remember what was there, so a rage roll can ask "New Alpha?" about the new holes.
    const selected = (usePage().props as { selected_map_solarsystem?: { id: number; signatures?: { id: number }[] } | null }).selected_map_solarsystem;
    const beforeIds = new Set(selected?.id === map_solarsystem_id ? (selected.signatures ?? []).map((signature) => signature.id) : []);
    return router.post(
        PasteSignatures.store().url,
        {
            map_solarsystem_id,
            undo_token: token,
            signatures: signatures.map((signature) => ({
                signature_id: signature.signature_id,
                signature_category_id: signature.signature_category_id,
                signature_type_id: signature.signature_type_id,
                raw_type_name: !signature.signature_type_id ? signature.raw_type_name : undefined,
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['map', 'selected_map_solarsystem'],
            onSuccess: () => {
                recordRestorable(`pasted ${signatures.length} signature${signatures.length === 1 ? '' : 's'}`, token);
                lastPaste.value = { mapSolarsystemId: map_solarsystem_id, beforeIds, at: Date.now() };
                onSuccess?.();
            },
            onError: () => router.reload({ only: ['map'] }),
        },
    );
}
