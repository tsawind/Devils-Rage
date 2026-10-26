import { copyHeldWayBack, heldWayBack } from '@/composables/signatures/wayBack';
import { useShowMap } from '@/composables/useShowMap';
import { displayAlias } from '@/lib/alias';
import { APP_SCAN_EVENT, inDesktopApp, rememberAppScan, scanCharacter, type TAppScan } from '@/lib/desktopApp';
import { signatureParser } from '@/lib/SignatureParser';
import { signatureToast as toast } from '@/lib/signatureToast';
import { pasteSignatures } from '@/map/api';
import { tryUseMapStore } from '@/map/store/mapStore';
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
}
