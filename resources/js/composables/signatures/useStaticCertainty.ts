import { getTypesByCategory, signatureCategories } from '@/const/signatures';
import { displayAlias } from '@/lib/alias';
import { classCode, decideStatic, type TCertaintyHole, type TCertaintyWayBack } from '@/lib/staticCertainty';
import { updateSignature } from '@/map/actions/updateSignature';
import type { MapStore } from '@/map/store/mapStore';
import type { TMapSolarsystem } from '@/pages/maps';
import type { TSignature } from '@/types/models';
import { onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 13: a hole is only marked the static once it is certain (see
 * lib/staticCertainty). The check runs after your own paste or type change in
 * a system — once the server's update for that system has arrived — so only
 * the scanner who changed something writes the result.
 */

type TPending = { reference: TMapSolarsystem | undefined; at: number };

const requested = new Map<number, TPending>();
/** Bumped on every request, so the watcher picks up new systems to follow. */
const requestedVersion = ref(0);
let notify: (() => void) | null = null;

/**
 * Ask for the static check in a map system after your own change there (paste,
 * type). Pass the system as the map has it now: the check waits for the
 * server's update to replace it (else it runs after 4 s).
 */
export function requestStaticCheck(mapSolarsystemId: number, current?: TMapSolarsystem | null): void {
    requested.set(mapSolarsystemId, { reference: current ?? undefined, at: Date.now() });
    requestedVersion.value++;
    notify?.();
}

/** What the map knows about one system, as the certainty check needs it. */
export function certaintyInputFor(store: MapStore, system: TMapSolarsystem) {
    const statics = (system.solarsystem.statics ?? []).map((wormhole) => ({ name: wormhole.name, leadsTo: wormhole.leads_to }));

    const holes: TCertaintyHole[] = (system.pending_holes ?? []).map((hole) => ({
        signatureId: hole.id,
        typeName: hole.wormhole,
        isStatic: hole.is_static,
        linked: false,
    }));

    let wayBack: TCertaintyWayBack | null = null;
    const parentId = store.bandLayout.value?.parentOf.get(system.id) ?? null;
    for (const connection of store.connections.values()) {
        const ends = [connection.from_map_solarsystem_id, connection.to_map_solarsystem_id];
        if (!ends.includes(system.id)) continue;
        const thisSide = (connection.signatures ?? []).find((signature) => signature.map_solarsystem_id === system.id) ?? null;
        const farSide = (connection.signatures ?? []).find((signature) => signature.map_solarsystem_id !== system.id) ?? null;
        if (thisSide) {
            holes.push({ signatureId: thisSide.id, typeName: thisSide.wormhole?.name ?? null, isStatic: Boolean(thisSide.is_static), linked: true });
        }
        const otherId = ends[0] === system.id ? ends[1] : ends[0];
        if (parentId !== null && otherId === parentId && connection.type !== 'stargate') {
            const parent = store.systems.get(parentId);
            wayBack = {
                signatureId: thisSide?.id ?? null,
                thisSideType: thisSide?.wormhole?.name ?? null,
                farSideType: farSide?.wormhole?.name ?? null,
                leadsTo: classCode(parent?.solarsystem.class ?? null),
            };
        }
    }

    return { statics, holes, uncategorized: system.uncategorized_signatures_count ?? 0, wayBack };
}

/** Mounted once with the map: runs the requested checks as the updates arrive. */
export function useStaticCertainty(store: MapStore): void {
    const wormholeCategoryId = signatureCategories.find((category) => category.code === 'wormhole')?.id ?? null;

    function run(mapSolarsystemId: number): void {
        requested.delete(mapSolarsystemId);
        const system = store.systems.get(mapSolarsystemId);
        if (!system || !system.solarsystem.statics?.length) return;

        const result = decideStatic(certaintyInputFor(store, system));
        if (result.mark) {
            const { signatureId, staticName, setType } = result.mark;
            const type =
                setType && wormholeCategoryId !== null
                    ? getTypesByCategory(wormholeCategoryId).find(
                          (candidate) => candidate.signature === staticName && candidate.spawn_areas?.includes(system.solarsystem.class),
                      )
                    : undefined;
            updateSignature({ id: signatureId } as TSignature, {
                is_static: true,
                is_wandering: false,
                ...(type ? { signature_type_id: type.id } : {}),
            });
            const where = displayAlias(system.alias) || system.solarsystem.name;
            toast.success(`${staticName} is ${where}'s static`, { description: 'Every signature is scanned and nothing else can be it.' });
        } else if (result.ambiguous) {
            const where = displayAlias(system.alias) || system.solarsystem.name;
            toast.info(`${result.ambiguous.staticName} in ${where}: more than one hole could be the static`, {
                description: 'Mark the right one by hand (signature row menu → Static).',
            });
        }
    }

    function check(): void {
        const now = Date.now();
        for (const [id, pending] of [...requested]) {
            const current = store.systems.get(id);
            // Wait for the server's update of that system (a new object), or give up waiting after 4 s.
            if ((pending.reference !== undefined && current !== pending.reference) || now - pending.at > 4000) run(id);
        }
    }

    notify = () => setTimeout(check, 4100);
    const stop = watch(
        () => [requestedVersion.value, ...[...requested.keys()].map((id) => store.systems.get(id))],
        () => check(),
    );
    onBeforeUnmount(() => {
        stop();
        notify = null;
    });
}
