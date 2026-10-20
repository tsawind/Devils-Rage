import { copyHeldWayBack, heldWayBack } from '@/composables/signatures/wayBack';
import { useShowMap } from '@/composables/useShowMap';
import { displayAlias } from '@/lib/alias';
import { APP_SCAN_EVENT, inDesktopApp, rememberAppScan, type TAppScan } from '@/lib/desktopApp';
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
    const store = tryUseMapStore();

    useEventListener(window, APP_SCAN_EVENT, (raw: Event) => {
        const scan = (raw as CustomEvent<TAppScan>).detail;
        // After the signature panels had their turn.
        setTimeout(() => {
            if (!scan || scan.claimed) return;
            const signatures = signatureParser.parseSignatures(scan.text);
            if (!signatures || !signatures.length) return;
            const where = page.props.map_characters?.find((candidate) => candidate.id === scan.character_id)?.status?.solarsystem_id;
            const system = where && store ? [...store.systems.values()].find((candidate) => candidate.solarsystem_id === where) : null;
            if (!system) {
                toast.info(`${scan.character_name}'s scan wasn't pasted`, {
                    description: where ? 'Their system is not on this map yet. Paste it with Ctrl+V.' : 'Not sure where they are (is tracking on?). Paste it with Ctrl+V.',
                });
                return;
            }
            scan.claimed = true;
            rememberAppScan(scan.text);
            const name = displayAlias(system.alias) || system.solarsystem.name;
            pasteSignatures(system.id, signatures, () =>
                toast.success(`Pasted ${signatures.length} signature${signatures.length === 1 ? '' : 's'} into ${name}`, { description: `${scan.character_name}'s scan, from the app` }),
            );
            if (heldWayBack.value?.alt?.solarsystemId === where) void copyHeldWayBack();
        }, 0);
    });
}
