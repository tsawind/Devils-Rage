import { copyHeldWayBack, heldWayBack } from '@/composables/signatures/wayBack';
import { useShowMap } from '@/composables/useShowMap';
import { displayAlias } from '@/lib/alias';
import SystemIntelController from '@/actions/App/Http/Controllers/SystemIntelController';
import { APP_INTEL_EVENT, APP_SCAN_EVENT, inDesktopApp, rememberAppScan, scanCharacter, type TAppScan } from '@/lib/desktopApp';
import { jsonFetch } from '@/lib/jsonFetch';
import { signatureParser } from '@/lib/SignatureParser';
import { signatureToast as toast } from '@/lib/signatureToast';
import { pasteSignatures } from '@/map/api';
import { tryUseMapStore } from '@/map/store/mapStore';
import { router } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';

/**
 * Patch 47: a probe scan the desktop app saw copied in EVE goes into the system of the character
 * who copied it. A signature panel showing that system takes it first (usePasteSignatures, same
 * as a Ctrl+V there); otherwise it is pasted straight into the character's system on this map.
 */
export function useDesktopAppScans(): void {
    if (!inDesktopApp()) return;
    const page = useShowMap();

    useEventListener(window, APP_SCAN_EVENT, (raw: Event) => {
        const scan = (raw as CustomEvent<TAppScan>).detail;
        // After the signature panels had their turn.
        setTimeout(() => {
            if (!scan || scan.claimed || scan.toasted) return;
            const signatures = signatureParser.parseSignatures(scan.text);
            if (!signatures || !signatures.length) return;
            const where = scanCharacter(scan, page.props.map_characters)?.status?.solarsystem_id;
            // The map on screen now (not the one this listener started with: tabs keep old pages alive).
            const store = tryUseMapStore();
            const system = where && store ? [...store.systems.values()].find((candidate) => candidate.solarsystem_id === where) : null;
            const paste = (target: { id: number; alias?: string | null; solarsystem?: { name: string } | null }, solarsystemId: number | null | undefined) => {
                scan.claimed = true;
                rememberAppScan(scan.text);
                const name = displayAlias(target.alias) || target.solarsystem?.name || 'the system';
                pasteSignatures(target.id, signatures, () =>
                    toast.success(`Pasted ${signatures.length} signature${signatures.length === 1 ? '' : 's'} into ${name}`, { description: `${scan.character_name}'s scan, from the app` }),
                );
                if (solarsystemId && heldWayBack.value?.alt?.solarsystemId === solarsystemId) void copyHeldWayBack();
            };
            if (!system) {
                scan.toasted = true;
                // The open system as a one-click way out when the pilot's location isn't known here.
                const selected = page.props.selected_map_solarsystem;
                toast.info(`${scan.character_name}'s scan wasn't pasted`, {
                    description: where ? 'Their system is not on this map yet. Paste it with Ctrl+V.' : 'Not sure where they are (is tracking on?). Paste it with Ctrl+V.',
                    action: selected
                        ? { label: `Paste into ${displayAlias(selected.alias) || selected.solarsystem?.name || 'the open system'}`, onClick: () => paste(selected, selected.solarsystem_id) }
                        : undefined,
                });
                return;
            }
            paste(system, where);
        }, 0);
    });

    /**
     * Patch 57: a D-scan or overview rows with structures copied in EVE: the structures go into
     * that pilot's system's intel (new ones added, known ones marked seen), with an Undo.
     */
    useEventListener(window, APP_INTEL_EVENT, (raw: Event) => {
        const copy = (raw as CustomEvent<TAppScan>).detail;
        setTimeout(() => {
            if (!copy || copy.claimed || copy.toasted) return;
            const where = scanCharacter(copy, page.props.map_characters)?.status?.solarsystem_id;
            const store = tryUseMapStore();
            const system = where && store ? [...store.systems.values()].find((candidate) => candidate.solarsystem_id === where) : null;
            const send = (target: { id: number; alias?: string | null; solarsystem?: { name: string } | null }) => {
                copy.claimed = true;
                void importInto(target, copy);
            };
            if (system) {
                send(system);
                return;
            }
            copy.toasted = true;
            const selected = page.props.selected_map_solarsystem;
            toast.info(`${copy.character_name}'s structures weren't added`, {
                description: where ? 'Their system is not on this map yet.' : 'Not sure where they are (is tracking on?).',
                action: selected ? { label: `Add to ${displayAlias(selected.alias) || selected.solarsystem?.name || 'the open system'}`, onClick: () => send(selected) } : undefined,
            });
        }, 0);
    });
}

async function importInto(target: { id: number; alias?: string | null; solarsystem?: { name: string } | null }, copy: TAppScan): Promise<void> {
    const name = displayAlias(target.alias) || target.solarsystem?.name || 'the system';
    const answer = await jsonFetch<{ added: number[]; updated: number[]; structures: { id: number; kind: string; name: string | null }[] }>(
        SystemIntelController.importStructures(target.id).url,
        'POST',
        { text: copy.text },
    );
    if (!answer) {
        toast.error(`Couldn't add ${copy.character_name}'s structures to ${name}`);
        return;
    }
    // Patch 58b: the Notes panel's short structure list catches up.
    router.reload({ only: ['selected_map_solarsystem'] });
    const added = answer.structures.filter((structure) => answer.added.includes(structure.id));
    const label = (structure: { kind: string; name: string | null }) => `${structure.kind === 'pos' ? 'POS' : structure.kind[0].toUpperCase() + structure.kind.slice(1)}${structure.name ? ` (${structure.name})` : ''}`;
    if (!added.length) {
        toast.success(`${answer.updated.length} structure${answer.updated.length === 1 ? '' : 's'} in ${name} seen again`, { description: `${copy.character_name}'s copy, from the app` });
        return;
    }
    toast.success(added.length === 1 ? `Added ${label(added[0])} to ${name} intel` : `Added ${added.length} structures to ${name} intel`, {
        description: answer.updated.length ? `${answer.updated.length} more seen again · ${copy.character_name}'s copy` : `${copy.character_name}'s copy, from the app`,
        duration: 10_000,
        action: {
            label: 'Undo',
            onClick: () => {
                for (const structure of added) {
                    void jsonFetch(SystemIntelController.destroyStructure({ mapSolarsystem: target.id, structure: structure.id }).url, 'DELETE');
                }
            },
        },
    });
}
