import { useCombat } from '@/composables/combat/useCombat';
import { useClipboardSetting } from '@/composables/useClipboardSetting';
import { getTypesByCategory, signatureCategories } from '@/const/signatures';
import { displayAlias, staticSlotFor } from '@/lib/alias';
import { visibleBookmarkName } from '@/lib/bookmark';
import { asStaticHole, mappedBelow, pendingHoleBookmark, renameChanges } from '@/map/holeBookmark';
import { planPendingHoles } from '@/lib/placeholders';
import { classCode, decideStatic, type TCertaintyHole, type TCertaintyWayBack } from '@/lib/staticCertainty';
import { updateSignature } from '@/map/actions/updateSignature';
import type { MapStore } from '@/map/store/mapStore';
import type { TMapSolarsystem } from '@/pages/maps';
import type { TSignature } from '@/types/models';
import { onBeforeUnmount, ref, watch } from 'vue';
import { signatureToast as toast } from '@/lib/signatureToast';

/**
 * Patch 13: a hole is only marked the static once it is certain (see
 * lib/staticCertainty). The check runs after your own paste or type change in
 * a system — once the server's update for that system has arrived — so only
 * the scanner who changed something writes the result.
 */

type TPending = { reference: TMapSolarsystem | undefined; at: number };

const requested = new Map<number, TPending>();

/**
 * Patch 14: the certain static would change a name used on the map (A2 → A0):
 * the popup (StaticCertainDialog, mounted with the map) asks first.
 */
export type TCertainAsk = {
    signatureLabel: string;
    from: string;
    to: string;
    changes: { label: string; from: string; to: string }[];
    beyond: string[];
    /** Patch 20: "rename-quiet" renames without touching the clipboard. */
    choose: (choice: 'rename' | 'rename-quiet' | 'keep') => void;
};
export const certainAsk = ref<TCertainAsk | null>(null);
/** Patch 20: a second static settled at the same time waits its turn (one popup at a time). */
const askQueue: TCertainAsk[] = [];
function showAsk(ask: TCertainAsk): void {
    if (certainAsk.value) askQueue.push(ask);
    else certainAsk.value = ask;
}
function nextAsk(): void {
    certainAsk.value = askQueue.shift() ?? null;
}
/**
 * Patch 18b: "★ This is the static" from the map asks first (a wandering hole
 * can look the same); StaticConfirmDialog shows this.
 */
export type TStaticConfirm = { signatureLabel: string; where: string; staticName: string; leadsTo: string; slot: string; confirm: () => void };
export const staticConfirm = ref<TStaticConfirm | null>(null);
let markHandler: ((mapSolarsystemId: number, mark: { signatureId: number; staticName: string; setType: boolean }, byHand: boolean) => void) | null = null;

/** Patch 18b: mark a hole as the static by hand (after the warning). */
export function markStaticByHand(mapSolarsystemId: number, signatureId: number, staticName: string, setType: boolean): void {
    markHandler?.(mapSolarsystemId, { signatureId, staticName, setType }, true);
}

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
        // Patch 18b: an untyped hole that leads somewhere else can't be the static.
        leadsTo: classCode(hole.target_class ?? null),
    }));

    let wayBack: TCertaintyWayBack | null = null;
    const unpastedLeadsTo: string[] = [];
    const parentId = store.bandLayout.value?.parentOf.get(system.id) ?? null;
    for (const connection of store.connections.values()) {
        const ends = [connection.from_map_solarsystem_id, connection.to_map_solarsystem_id];
        if (!ends.includes(system.id)) continue;
        const thisSide = (connection.signatures ?? []).find((signature) => signature.map_solarsystem_id === system.id) ?? null;
        const farSide = (connection.signatures ?? []).find((signature) => signature.map_solarsystem_id !== system.id) ?? null;
        const otherId = ends[0] === system.id ? ends[1] : ends[0];
        const leadsTo = classCode(store.systems.get(otherId)?.solarsystem.class ?? null);
        if (thisSide) {
            // A known non-K162 type on the far side: the hole opened there, so this side is its K162.
            const farType = farSide?.wormhole?.name ?? null;
            const typeName = thisSide.wormhole?.name ?? (farType && !farType.toUpperCase().startsWith('K162') ? 'K162' : null);
            holes.push({ signatureId: thisSide.id, typeName, isStatic: Boolean(thisSide.is_static), linked: true, leadsTo });
        } else if (connection.type !== 'stargate' && otherId !== parentId && leadsTo) {
            unpastedLeadsTo.push(leadsTo);
        }
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

    return { statics, holes, uncategorized: system.uncategorized_signatures_count ?? 0, wayBack, unpastedLeadsTo };
}

/** Mounted once with the map: runs the requested checks as the updates arrive. */
export function useStaticCertainty(store: MapStore): void {
    const { clipboardAllowed } = useClipboardSetting();
    // Combat mode never stops you with a popup: the static is marked and keeps its name.
    const { is_combat } = useCombat();
    const wormholeCategoryId = signatureCategories.find((category) => category.code === 'wormhole')?.id ?? null;

    const toldAmbiguous = new Map<number, string>();

    function run(mapSolarsystemId: number): void {
        requested.delete(mapSolarsystemId);
        const system = store.systems.get(mapSolarsystemId);
        if (!system || !system.solarsystem.statics?.length) return;

        const result = decideStatic(certaintyInputFor(store, system));
        // Patch 20: every static that is certain now (two statics can settle with one paste).
        for (const mark of result.marks) applyMark(system, mark, false);
        if (!result.marks.length && result.ambiguous) {
            // Said once per system and set of holes, not again on every change.
            const key = `${result.ambiguous.staticName}:${result.ambiguous.signatureIds.toSorted((a, b) => a - b).join(',')}`;
            if (toldAmbiguous.get(mapSolarsystemId) === key) return;
            toldAmbiguous.set(mapSolarsystemId, key);
            const where = displayAlias(system.alias) || system.solarsystem.name;
            toast.info(`${result.ambiguous.staticName} in ${where}: more than one hole could be the static`, {
                description: 'Mark the right one by hand (right-click it on the map → This is the static).',
            });
        }
    }

    /** Patch 18b: "Undo" on every static message: unmark it (and drop the type / name it was given). */
    function undoAction(signatureId: number, setType: boolean, previousAlias: string | null | undefined) {
        return {
            label: 'Undo',
            onClick: () =>
                updateSignature({ id: signatureId } as TSignature, {
                    is_static: false,
                    ...(setType ? { signature_type_id: null } : {}),
                    ...(previousAlias !== undefined ? { alias: previousAlias } : {}),
                }),
        };
    }

    function applyMark(system: TMapSolarsystem, mark: { signatureId: number; staticName: string; setType: boolean }, byHand: boolean): void {
        {
            const { signatureId, staticName, setType } = mark;
            const type =
                setType && wormholeCategoryId !== null
                    ? getTypesByCategory(wormholeCategoryId).find(
                          (candidate) => candidate.signature === staticName && candidate.spawn_areas?.includes(system.solarsystem.class),
                      )
                    : undefined;
            const payload = { is_static: true, is_wandering: false, ...(type ? { signature_type_id: type.id } : {}) };
            const where = displayAlias(system.alias) || system.solarsystem.name;
            const meta = store.meta.value;
            // Patch 20: each static has its own slot (B0 for the first, B1 for the second).
            const slot = staticSlotFor(system.alias, system.solarsystem.statics, staticName, meta?.bookmark_ignored_alias, Boolean(system.combat_home));
            const name = currentName(system, signatureId);

            // Combat chains keep their jump-order numbers; a hole already named for the slot (or unnamed) changes nothing.
            if (system.combat_color || is_combat.value || !name || name.toUpperCase() === slot.toUpperCase()) {
                updateSignature({ id: signatureId } as TSignature, payload);
                toast.success(`${staticName} is ${where}'s static`, {
                    description: byHand ? 'Marked by hand.' : 'Every signature is scanned and nothing else can be it.',
                    action: undoAction(signatureId, setType, undefined),
                });
                return;
            }

            const hole = system.pending_holes?.find((candidate) => candidate.id === signatureId);
            const linked = !hole;
            const beyond = mappedBelow(store, system, name).map((alias) => displayAlias(alias));
            // One question at a time (patch 20: queued, not answered Keep).
            showAsk({
                signatureLabel: hole ? (hole.signature_id ?? 'This hole') : `The hole to ${displayAlias(name)}`,
                from: name,
                to: slot,
                changes: renameChanges(store, system, signatureId, name, slot, true, staticName),
                beyond,
                choose: (choice) => {
                    nextAsk();
                    if ((choice === 'rename' || choice === 'rename-quiet') && beyond.length === 0) {
                        updateSignature({ id: signatureId } as TSignature, {
                            ...payload,
                            alias: slot,
                            lock_others: lockOthersFor(system, signatureId),
                            ...(linked ? { rename_system: true } : {}),
                        });
                        // Patch 18b: the bookmark as it will be once marked (the static's type, class and size).
                        const copy = hole ? pendingHoleBookmark(store, system, asStaticHole(system, hole, staticName), slot, true) : '';
                        const quiet = choice === 'rename-quiet' || !clipboardAllowed();
                        if (copy && !quiet) navigator.clipboard.writeText(copy).catch(() => undefined);
                        toast.success(`${staticName} is ${where}'s static · renamed to ${displayAlias(slot)}`, {
                            description: copy ? (quiet ? `New name ${visibleBookmarkName(copy)}` : `Copied ${visibleBookmarkName(copy)}`) : undefined,
                            action: undoAction(signatureId, setType, hole?.alias ?? null),
                            // Patch 20: renamed without copying: copy it from the toast if you want it after all.
                            ...(copy && quiet ? { cancel: { label: '⧉ Copy', onClick: () => navigator.clipboard.writeText(copy).catch(() => undefined) } } : {}),
                            ...(copy && quiet ? { duration: 15_000 } : {}),
                        });
                        return;
                    }
                    // Keep: lock the name it has, or the static would take the slot on its own.
                    updateSignature({ id: signatureId } as TSignature, { ...payload, alias: name });
                    toast.success(`${staticName} is ${where}'s static`, {
                        description: `Keeps the name ${displayAlias(name)}.`,
                        action: undoAction(signatureId, setType, hole?.alias ?? null),
                    });
                },
            });
        }
    }

    /** The numbers the system's unjumped holes show now (locked or planned). */
    function plannedOf(system: TMapSolarsystem): Map<number, string> {
        const meta = store.meta.value;
        return planPendingHoles([...store.systems.values()], system, { bookmark_alias_scheme: meta?.bookmark_alias_scheme, bookmark_ignored_alias: meta?.bookmark_ignored_alias });
    }

    /** Lock the other unjumped holes to the numbers they show, so a rename doesn't shift them. */
    function lockOthersFor(system: TMapSolarsystem, exceptId: number): Record<string, string> {
        const planned = plannedOf(system);
        const locks: Record<string, string> = {};
        for (const hole of system.pending_holes ?? []) {
            if (hole.id === exceptId || hole.alias) continue;
            const alias = planned.get(hole.id);
            if (alias) locks[String(hole.id)] = alias;
        }
        return locks;
    }

    /** The name a hole has in game: its locked number, or the system it leads to. */
    function currentName(system: TMapSolarsystem, signatureId: number): string | null {
        const hole = system.pending_holes?.find((candidate) => candidate.id === signatureId);
        // Its locked number, or the planned one shown on the map (someone may have bookmarked it by hand).
        if (hole) return hole.alias ?? plannedOf(system).get(signatureId) ?? null;
        for (const connection of store.connections.values()) {
            const signature = (connection.signatures ?? []).find((candidate) => candidate.id === signatureId);
            if (!signature) continue;
            if (signature.alias) return signature.alias;
            const otherId = connection.from_map_solarsystem_id === system.id ? connection.to_map_solarsystem_id : connection.from_map_solarsystem_id;
            // The way back leads to the parent: its name isn't this hole's name, nothing gets renamed.
            if (store.bandLayout.value?.parentOf.get(system.id) === otherId) return null;
            return store.systems.get(otherId)?.alias ?? null;
        }
        return null;
    }

    function check(): void {
        const now = Date.now();
        for (const [id, pending] of [...requested]) {
            const current = store.systems.get(id);
            // Wait for the server's update of that system (a new object), or give up waiting after 4 s.
            if ((pending.reference !== undefined && current !== pending.reference) || now - pending.at > 4000) run(id);
        }
    }

    markHandler = (mapSolarsystemId, mark, byHand) => {
        const system = store.systems.get(mapSolarsystemId);
        if (system) applyMark(system, mark, byHand);
    };
    notify = () => setTimeout(check, 4100);
    const stop = watch(
        () => [requestedVersion.value, ...[...requested.keys()].map((id) => store.systems.get(id))],
        () => check(),
    );
    onBeforeUnmount(() => {
        stop();
        notify = null;
        markHandler = null;
    });
}
