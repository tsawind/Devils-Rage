import MapNamesController from '@/actions/App/Http/Controllers/MapNamesController';
import { recordRestorable, undoToken } from '@/composables/undo/mapUndo';
import { signatureToast as toast } from '@/lib/signatureToast';
import { router } from '@inertiajs/vue3';

/**
 * Patch 39: change a system's names (see MapNamesAction on the server): switch a loop to the
 * hole's number, drop a floating name, adopt an old chain's new name, keep the old one, or
 * rename an old chain's hole. Every change can be undone (Ctrl+Z).
 */
export type TNamesChange =
    | { action: 'switch' | 'adopt'; signature_id: number }
    | { action: 'drop'; signature_id?: number | null; name?: string | null }
    | { action: 'keep' }
    | { action: 'hole'; signature_id: number; name: string }
    | { action: 'rename'; signature_id: number; name: string; mode: TRenameMode; next?: string | null };

/** Patch 39: a double-click rename to a name already in use: swap, give the other the next number, or keep it floating. */
export type TRenameMode = 'plain' | 'swap' | 'bump' | 'float';

export function changeNames(systemId: number, change: TNamesChange, label: string, onSuccess?: () => void): void {
    const token = undoToken();
    router.post(
        MapNamesController.update(systemId).url,
        { ...change, undo_token: token },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['map', 'selected_map_solarsystem'],
            onSuccess: () => {
                recordRestorable(label, token);
                onSuccess?.();
            },
            onError: (errors: Record<string, string>) => {
                toast.error(Object.values(errors)[0] ?? 'Could not change the name.');
            },
        },
    );
}
