import MapSolarsystems from '@/routes/map-solarsystems';
import Signatures from '@/routes/signatures';
import { updateSignature } from '@/map/actions/updateSignature';
import type { TSignature } from '@/types/models';
import type { FormDataConvertible } from '@inertiajs/core';
import { recordUndo } from '@/composables/undo/mapUndo';
import { router, usePage } from '@inertiajs/vue3';
import { signatureToast as toast } from '@/lib/signatureToast';

/**
 * Patch 20: your signature edits (category, type, mass, life, Static / Wandering,
 * deleting a signature) as steps of the map's Undo / Redo (patch 21: one list for
 * everything, see mapUndo). They go back through the same saves the signature
 * list makes, so everyone sees the undo. When someone else changed that
 * signature after you, the step is skipped with a message rather than
 * overwriting their change.
 */

/** The fields an undo can put back. */
const TRACKED = ['signature_category_id', 'signature_type_id', 'mass_status', 'lifetime', 'is_static', 'is_wandering', 'alias'] as const;
type TTracked = (typeof TRACKED)[number];
type TValues = Partial<Record<TTracked, FormDataConvertible>>;

type TEdit = { kind: 'edit'; label: string; signatureId: number; before: TValues; after: TValues };
type TDelete = { kind: 'delete'; label: string; mapSolarsystemId: number; snapshot: TSignature };
type TEntry = TEdit | TDelete;

function push(entry: TEntry): void {
    recordUndo({ label: entry.label, undo: () => apply(entry, 'undo'), redo: () => apply(entry, 'redo') });
}

/** The signature as the page has it now (the selected system's list), if it is there. */
function currentSignature(signatureId: number): TSignature | null {
    const props = usePage().props as unknown as { selected_map_solarsystem?: { signatures?: TSignature[] | null } | null };
    return props.selected_map_solarsystem?.signatures?.find((candidate) => candidate.id === signatureId) ?? null;
}

function valuesOf(signature: TSignature, keys: readonly TTracked[]): TValues {
    const values: TValues = {};
    for (const key of keys) values[key] = ((signature as Record<string, unknown>)[key] ?? null) as FormDataConvertible;
    return values;
}

const same = (a: FormDataConvertible | undefined, b: FormDataConvertible | undefined) => (a ?? null) === (b ?? null);

/** Record an edit you are about to save on `signature` (call before saving). */
export function recordSignatureEdit(signature: TSignature, data: Record<string, FormDataConvertible>, label: string): void {
    const keys = TRACKED.filter((key) => key in data);
    if (keys.length === 0) return;
    const before = valuesOf(signature, keys);
    const after: TValues = {};
    for (const key of keys) after[key] = data[key] ?? null;
    if (keys.every((key) => same(before[key], after[key]))) return;
    push({ kind: 'edit', label: `${signature.signature_id ?? 'Signature'} ${label}`, signatureId: signature.id, before, after });
}

/** Record a signature you are about to delete. */
export function recordSignatureDelete(signature: TSignature): void {
    push({ kind: 'delete', label: `deleted ${signature.signature_id ?? 'a signature'}`, mapSolarsystemId: signature.map_solarsystem_id, snapshot: { ...signature } });
}

function apply(entry: TEntry, direction: 'undo' | 'redo'): boolean {
    if (entry.kind === 'edit') {
        const target = direction === 'undo' ? entry.before : entry.after;
        const expected = direction === 'undo' ? entry.after : entry.before;
        const now = currentSignature(entry.signatureId);
        // Someone changed it after you: leave their change alone.
        if (now && (Object.keys(expected) as TTracked[]).some((key) => !same(valuesOf(now, [key])[key], expected[key]))) {
            toast.warning(`Not ${direction === 'undo' ? 'undone' : 'redone'}: ${entry.label}`, { description: 'Someone changed that signature after you.' });
            return false;
        }
        updateSignature({ id: entry.signatureId } as TSignature, target as Record<string, FormDataConvertible>);
        return true;
    }
    if (direction === 'redo') {
        const now = currentSignature(entry.snapshot.id);
        if (now) router.delete(Signatures.destroy(entry.snapshot.id).url, { preserveScroll: true, preserveState: true, only: ['map', 'selected_map_solarsystem'] });
        return true;
    }
    // Undo a delete: paste it back (same ID, category, type, mass, life), then give it its number back.
    const snapshot = entry.snapshot;
    router.post(
        MapSolarsystems.signatures.store(entry.mapSolarsystemId),
        {
            map_solarsystem_id: entry.mapSolarsystemId,
            signature_id: snapshot.signature_id ?? '',
            signature_type_id: snapshot.signature_type_id,
            signature_category_id: snapshot.signature_category_id,
            mass_status: snapshot.mass_status,
            lifetime: snapshot.lifetime,
            ship_size: snapshot.ship_size,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['map', 'selected_map_solarsystem'],
            onSuccess: () => {
                const props = usePage().props as unknown as { selected_map_solarsystem?: { signatures?: TSignature[] | null } | null };
                const back = props.selected_map_solarsystem?.signatures?.find((candidate) => candidate.signature_id && candidate.signature_id === snapshot.signature_id);
                if (back && (snapshot.alias || snapshot.is_static || snapshot.is_wandering)) {
                    updateSignature(back, { alias: snapshot.alias ?? null, is_static: Boolean(snapshot.is_static), is_wandering: Boolean(snapshot.is_wandering) });
                }
                if (back) entry.snapshot = { ...snapshot, id: back.id };
            },
        },
    );
    return true;
}

export { canRedo, canUndo, handleUndoKeydown, redoLabel, redoLast, undoLabel, undoLast } from '@/composables/undo/mapUndo';
