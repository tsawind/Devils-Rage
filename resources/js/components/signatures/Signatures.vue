<script setup lang="ts">
import PasteIcon from '@/components/icons/PasteIcon.vue';
import PlusIcon from '@/components/icons/PlusIcon.vue';
import TrashIcon from '@/components/icons/TrashIcon.vue';
import PasteSignatureWarningDialog from '@/components/signatures/PasteSignatureWarningDialog.vue';
import ReturnHoleDialog from '@/components/signatures/ReturnHoleDialog.vue';
import SideChainLetterDialog from '@/components/signatures/SideChainLetterDialog.vue';
import CombatControls from '@/components/combat/CombatControls.vue';
import ChainCleanupRows from '@/components/signatures/ChainCleanupRows.vue';
import SolarsystemClass from '@/components/solarsystem/SolarsystemClass.vue';
import Signature from '@/components/signatures/Signature.vue';
import SignaturesEmptyState from '@/components/signatures/SignaturesEmptyState.vue';
import MapPanel from '@/components/ui/map-panel/MapPanel.vue';
import MapPanelContent from '@/components/ui/map-panel/MapPanelContent.vue';
import MapPanelHeader from '@/components/ui/map-panel/MapPanelHeader.vue';
import MapPanelHeaderActionButton from '@/components/ui/map-panel/MapPanelHeaderActionButton.vue';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useCombat } from '@/composables/combat/useCombat';
import { recentJump } from '@/composables/signatures/recentJump';
import { armHole } from '@/composables/signatures/armHole';
import { clearHeldWayBack, copyHeldWayBack, heldWayBack, PASTE_SIGNATURES_EVENT } from '@/composables/signatures/wayBack';
import { requestStaticCheck } from '@/composables/signatures/useStaticCertainty';
import { usePasteSignatures } from '@/composables/signatures/usePasteSignatures';
import { useSignatures } from '@/composables/signatures/useSignatures';
import { useSortableSignatures } from '@/composables/signatures/useSortedSignatures';
import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { useShowMap } from '@/composables/useShowMap';
import usePermission from '@/composables/usePermission';
import useUser from '@/composables/useUser';
import { signatureCategories } from '@/const/signatures';
import { displayAlias, suggestAlias } from '@/lib/alias';
import { isWormholeSignature, planAliasesForSystem } from '@/lib/aliasPlan';
import { armAsOptions, armedSummary, myArmedHole, pasteArmDecision, type TArmAsOption } from '@/lib/arming';
import { formatBookmarkName, visibleBookmarkName } from '@/lib/bookmark';
import { chainAliases, combatColorHex, combatColorLabel } from '@/lib/combat';
import { AUTO_LINK_WINDOW_MS, decideReturnHole, orderOpenConnections, type TReturnConnectionOption, type TReturnHoleOption, type TScanDistance } from '@/lib/returnHole';
import type { TRawSignature } from '@/lib/SignatureParser';
import { needsSideChainLetter, suggestSideChainLetter, takenLetters } from '@/lib/sideChain';
import { aliasedSolarsystemLabel } from '@/lib/solarsystem';
import { disarmSignature } from '@/map/actions/arm';
import { absorbSignature } from '@/map/actions/holeType';
import { updateMapSolarsystem } from '@/map/actions/updateMapSolarsystem';
import { createSignature, TProcessedConnection, updateMapUserSettings, updateSignature, useMapSolarsystems, useMapStore } from '@/map/api';
import type { TResolvedSelectedMapSolarsystem } from '@/pages/maps';
import type { TSignature } from '@/types/models';
import { useEventListener, useLocalStorage, useNow } from '@vueuse/core';
import { ArrowDown, ArrowUp, CircleHelp, Cloud, Database, Fan, Flag, Gem, Landmark, Rows2, Rows3, Shield, Swords } from 'lucide-vue-next';
import { type Component, computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

const props = defineProps<{
    map_solarsystem: TResolvedSelectedMapSolarsystem | null;
}>();

const { connections } = useSignatures();

const { canEdit: can_write } = usePermission();

const character = useActiveMapCharacter();

const map_user_settings = useMapUserSettings();

const page = useShowMap();

const { popup_seconds, is_combat } = useCombat();

const user = useUser();
const user_id = computed<number | null>(() => user.value?.id ?? null);

function toggleCompactSignatureList() {
    updateMapUserSettings(page.props.map.slug, {
        compact_signature_list: !map_user_settings.value.compact_signature_list,
    });
}

const {
    signatures,
    pasted_signatures,
    deleted_signatures,
    deleteMissingSignatures,
    pasteSignatures,
    show_system_mismatch_warning,
    confirmPasteInDifferentSystem,
    cancelPaste,
} = usePasteSignatures(() => props.map_solarsystem, handlePasted);

const { map_solarsystems } = useMapSolarsystems();

// Reserve a chain alias for every unjumped wormhole in this system (statics
// first), so e.g. the static suggests " 1" and the next hole " 2".
// This system as the map knows it (combat chain color, combat home).
const map_system = computed(() => map_solarsystems.value.find((solarsystem) => solarsystem.id === props.map_solarsystem?.id) ?? null);

// Numbers already used in this system's chain (each combat chain numbers from 1 on its own).
const chain_aliases = computed(() => chainAliases(map_solarsystems.value, map_system.value));

// Red rows (missing from the last paste: ignored in game, or gone) are
// included, so the numbers they hold stay reserved until they are deleted.
const planned_aliases = computed(() =>
    planAliasesForSystem({
        signatures: signatures.value,
        system: props.map_solarsystem
            ? {
                  alias: props.map_solarsystem.alias,
                  solarsystem: props.map_solarsystem.solarsystem,
                  combat_home: map_system.value?.combat_home ?? false,
                  combat_color: map_system.value?.combat_color ?? null,
              }
            : null,
        aliases: chain_aliases.value,
        formats: page.props.map,
    }),
);

// Who holds each number in this system, for hand-set numbers ("already used by …").
const number_owners = computed(() => {
    const owners = new Map<string, { signatureId: number | null; label: string }>();
    for (const alias of chain_aliases.value) {
        owners.set(alias.toUpperCase(), { signatureId: null, label: `system ${alias} on the map` });
    }
    for (const signature of signatures.value) {
        const alias = planned_aliases.value.get(signature.id) ?? signature.alias ?? null;
        if (alias) owners.set(alias.toUpperCase(), { signatureId: signature.id, label: signature.signature_id ?? 'another signature' });
    }
    return owners;
});

// The one hole in this system marked as the static, if any (red rows included).
const static_owner_id = computed(() => signatures.value.find((signature) => signature.is_static)?.id ?? null);

// Combat chains number holes in the order they are claimed (copied or jumped):
// the next free number in this system, for a hole still in "limbo".
const claim_alias = computed<string | null>(() => {
    const system = props.map_solarsystem;
    if (!system || !map_system.value?.combat_color) return null;
    return suggestAlias({
        parentAlias: system.alias,
        targetIsWormhole: true,
        originIsWormhole: true,
        aliases: [...chain_aliases.value, ...number_owners.value.keys()],
        scheme: page.props.map.bookmark_alias_scheme,
        ignoredAlias: page.props.map.bookmark_ignored_alias,
        combatHome: Boolean(map_system.value.combat_home),
    });
});

// ---- Arming (patch 13) -------------------------------------------------------
// Arm the hole you are about to jump: it takes its number (the planned one, or
// the next jump-order number in a combat chain), its bookmark name is copied,
// and your next jump is linked to it with no prompt.

/** "1 LIH (you) · 2 QXP (Kyle)" for the header. */
const armed_text = computed(() => armedSummary(signatures.value, user_id.value));

function armRow(signature: TSignature, asAlias: string | null = null, swap = false): void {
    const system = props.map_solarsystem;
    if (!system || !can_write.value || signature.map_connection_id) return;
    const limbo = Boolean(map_system.value?.combat_color);
    // Re-arming another hole in this combat system keeps your number.
    const mine = myArmedHole(signatures.value, user_id.value);
    const reuse = limbo && mine && mine.id !== signature.id && mine.alias && mine.armed_claimed ? mine.alias : null;
    const own = signature.alias ?? reuse ?? planned_aliases.value.get(signature.id) ?? claim_alias.value;
    const alias = asAlias ?? own;
    if (!alias) {
        toast.error('No free number to arm this hole as.');
        return;
    }
    armHole({
        signature,
        system: {
            alias: system.alias,
            class: system.solarsystem.class,
            combatHome: Boolean(map_system.value?.combat_home),
            combatColor: map_system.value?.combat_color ?? null,
        },
        aliases: chain_aliases.value,
        formats: page.props.map,
        alias,
        fallbackAlias: own,
        swap,
    });
}

function disarmRow(signature: TSignature): void {
    disarmSignature(signature.id, () => toast.success(`Disarmed ${signature.signature_id?.slice(0, 3) ?? 'the hole'}`));
}

/** "Arm as…" numbers for a hole in a combat chain (empty elsewhere: those holes keep their planned number). */
function armAsFor(signature: TSignature): TArmAsOption[] {
    const system = props.map_solarsystem;
    if (!system || !map_system.value?.combat_color || signature.map_connection_id) return [];
    return armAsOptions({
        parentAlias: system.alias,
        ignoredAlias: page.props.map.bookmark_ignored_alias,
        combatHome: Boolean(map_system.value.combat_home),
        usedBySystems: chain_aliases.value,
        signatures: signatures.value,
        signatureId: signature.id,
        userId: user_id.value,
    });
}

/**
 * Rage Scanning: a paste arms the hole you want to jump, but never by guessing
 * from the grid (patch 15). One signature pasted, or exactly one unjumped
 * wormhole in the paste, arms at once; more than one opens the yellow list
 * (100% scanned first, then closest) and you pick. Returns true when handled.
 */
const arm_choice = ref<{ systemId: number; ids: number[]; unscanned: number; at: number } | null>(null);
const ARM_CHOICE_SECONDS = 60;

function armFromPaste(pasted: TRawSignature[], system: TResolvedSelectedMapSolarsystem): boolean {
    const raws = new Map(pasted.map((raw) => [raw.signature_id, raw]));
    const decision = pasteArmDecision(
        pasted.length,
        system.signatures
            .filter((signature) => signature.signature_id && raws.has(signature.signature_id))
            .map((signature) => {
                const raw = raws.get(signature.signature_id ?? '');
                return {
                    id: signature.id,
                    // One signature pasted: it's the hole you mean, even before it is categorised.
                    isWormhole: isWormholeSignature(signature) || (pasted.length === 1 && !signature.signature_category_id),
                    linked: Boolean(signature.map_connection_id),
                    signal: raw?.signal ?? null,
                    meters: raw?.distance?.meters ?? null,
                    unscanned: !signature.signature_category_id,
                };
            }),
    );
    if (decision.mode === 'arm') {
        const hole = system.signatures.find((signature) => signature.id === decision.id);
        if (hole) armRow(hole);
        return true;
    }
    if (decision.mode === 'ask') {
        arm_choice.value = { systemId: system.id, ids: decision.ids, unscanned: decision.unscanned, at: Date.now() };
        return true;
    }
    return false;
}

/** The yellow list's rows, for the system you are looking at. */
const arm_choice_rows = computed(() => {
    const choice = arm_choice.value;
    const system = props.map_solarsystem;
    if (!choice || !system || choice.systemId !== system.id) return [];
    return choice.ids
        .map((id) => system.signatures.find((signature) => signature.id === id))
        .filter((signature): signature is TSignature => Boolean(signature && !signature.map_connection_id));
});

const arm_choice_seconds = computed(() =>
    arm_choice.value ? Math.max(0, Math.ceil((ARM_CHOICE_SECONDS * 1000 - (now.value.getTime() - arm_choice.value.at)) / 1000)) : 0,
);
// Answers itself after 60 s: nothing armed.
watch(arm_choice, (choice) => {
    if (!choice) return;
    setTimeout(() => {
        if (arm_choice.value === choice) arm_choice.value = null;
    }, ARM_CHOICE_SECONDS * 1000);
});

function pickArmChoice(signature: TSignature): void {
    arm_choice.value = null;
    armRow(signature);
}

// ---- Where am I (patch 16) ---------------------------------------------------
// Rage Scanning: the system you're in, in big text at the top of the panel, to
// call on comms ("111-2 · Red · C4 J212129 · 3 unscanned").
const where_am_i = computed(() => {
    if (!is_combat.value) return null;
    const solarsystemId = character.value?.status?.solarsystem_id ?? null;
    if (!solarsystemId) return null;
    const system = map_solarsystems.value.find((candidate) => candidate.solarsystem_id === solarsystemId);
    if (!system) return null;
    return {
        name: displayAlias(system.alias) || system.solarsystem.name,
        chain: combatColorLabel(system.combat_color),
        hex: combatColorHex(system.combat_color),
        solarsystemClass: system.solarsystem.class,
        jcode: system.solarsystem.name,
        unscanned: system.uncategorized_signatures_count ?? 0,
    };
});

// ---- Side chain letters (patch 12) ------------------------------------------
// A chain not linked to Daisy numbers its holes plainly (1, 11, 111) when it is
// the only one; from the second such chain on, its start takes a letter
// (Z, Y, X…) before its holes are numbered.

const side_chains = computed(() => {
    try {
        return useMapStore().bandLayout.value?.sideChains ?? [];
    } catch {
        return [];
    }
});

const letter_taken = computed(() => takenLetters(map_solarsystems.value.map((system) => system.alias)));
const letter_suggested = computed(() => suggestSideChainLetter(letter_taken.value));

const needs_letter = computed(() => {
    const system = map_system.value;
    if (!system || system.combat_color || !can_write.value || !letter_suggested.value) return false;
    if (!signatures.value.some((signature) => isWormholeSignature(signature))) return false;
    const aliasOf = new Map(map_solarsystems.value.map((candidate) => [candidate.id, candidate.alias]));
    const chains = side_chains.value.map((chain) => ({
        rootId: chain.rootId,
        rootAlias: aliasOf.get(chain.rootId) ?? null,
        memberAliases: chain.memberIds.map((id) => aliasOf.get(id) ?? null),
    }));
    return needsSideChainLetter(system.id, chains);
});

const letter_open = ref(false);
const letter_asked = new Set<number>();
watch(
    needs_letter,
    (needed) => {
        const system = map_system.value;
        if (!needed || !system || letter_asked.has(system.id)) return;
        letter_asked.add(system.id);
        letter_open.value = true;
    },
    { immediate: true },
);

function handleLetterChoice(letter: string): void {
    const system = map_system.value;
    if (!system || system.alias || letter_taken.value.has(letter)) return;
    updateMapSolarsystem(system, { alias: letter });
    toast.success(`Side chain ${letter} (${displayAlias(letter)})`, { description: `Holes here are numbered ${letter}1, ${letter}2…` });
}

// ---- Return hole after a paste ---------------------------------------------
// After a jump the connection back has no signature on this side yet. When a
// scan is pasted here, link the hole we came through: automatically within
// 80 s of our own jump when exactly one wormhole is on grid, otherwise ask.

type TReturnCandidate = { id: number; distance: TScanDistance | null; signature: TSignature };

const dismissed_connections = new Set<number>();
const return_dialog_open = ref(false);
const return_options = ref<TReturnHoleOption[]>([]);
const return_preselect = ref<number | null>(null);
const return_connections = ref<TReturnConnectionOption[]>([]);
let pending_return: { candidates: TReturnCandidate[]; connections: TProcessedConnection[] } | null = null;

async function handlePasted(pasted: TRawSignature[]): Promise<void> {
    // Patch 14: when the return signature is in the paste, the better way back is copied
    // (and the held one dropped); otherwise the held one waits for a click on its red area.
    await handlePastedScan(pasted);
}

// The way-back popup's green area: paste, same as the paste button.
useEventListener(window, PASTE_SIGNATURES_EVENT, () => {
    if (can_write.value) pasteSignatures();
});

async function handlePastedScan(pasted: TRawSignature[]): Promise<void> {
    // The map's copy of this system before the server's update, for the static check.
    const before = map_system.value;

    // Let the fresh signature list reach this component first.
    await nextTick();

    const system = props.map_solarsystem;
    if (!system) return;

    // Patch 13: once everything is scanned, the static may now be certain.
    requestStaticCheck(system.id, before);

    const jump = recentJump.value && recentJump.value.toSolarsystemId === system.solarsystem_id ? recentJump.value : null;

    // Patch 14: a hole typed from the map before anyone pasted here has a row
    // without an ID. The paste fills it in: the one signature pasted, or the
    // one wormhole on grid with you (you sit on the way back after a jump).
    const unnamed = system.signatures.filter((signature) => !signature.signature_id && signature.map_connection_id);
    if (unnamed.length > 0) {
        const wayBackRow = jump
            ? unnamed.find((signature) =>
                  connections.value.some((connection) => connection.id === signature.map_connection_id && connection.target.solarsystem_id === jump.fromSolarsystemId),
              )
            : undefined;
        const row = wayBackRow ?? (unnamed.length === 1 ? unnamed[0] : undefined);
        if (row) {
            const distances = new Map(pasted.map((raw) => [raw.signature_id, raw.distance ?? null]));
            const fresh = system.signatures.filter(
                (signature) =>
                    signature.signature_id &&
                    distances.has(signature.signature_id) &&
                    !signature.map_connection_id &&
                    (isWormholeSignature(signature) || !signature.signature_category_id),
            );
            const onGrid = fresh.filter((signature) => distances.get(signature.signature_id ?? '')?.onGrid);
            const match = pasted.length === 1 ? fresh[0] : onGrid.length === 1 ? onGrid[0] : undefined;
            if (match) {
                absorbSignature(row.id, match.id, () =>
                    toast.success(`${match.signature_id} is the hole typed from the map`, { description: 'Its ID was filled in; no second row.' }),
                );
                return;
            }
        }
    }

    // Patch 13: the hole you came through already has its signature on this
    // side, so the way back is known: don't ask about other unlinked connections.
    const return_known = Boolean(
        jump &&
            connections.value.some(
                (connection) =>
                    connection.target.solarsystem_id === jump.fromSolarsystemId &&
                    (connection.signatures ?? []).some((signature) => signature.map_solarsystem_id === system.id),
            ),
    );

    const open = connections.value.filter(
        (connection) =>
            connection.type !== 'stargate' &&
            !dismissed_connections.has(connection.id) &&
            !(connection.signatures ?? []).some((signature) => signature.map_solarsystem_id === system.id),
    );

    // Patch 13: in combat mode, once the way back is known, a paste arms your next hole.
    if (is_combat.value && can_write.value && (return_known || open.length === 0) && armFromPaste(pasted, system)) return;

    if (return_known || open.length === 0) return;

    const ordered = orderOpenConnections(
        open.map((connection) => ({ ...connection, otherSolarsystemId: connection.target.solarsystem_id, createdAt: connection.created_at })),
        jump?.fromSolarsystemId ?? null,
    );

    const distances = new Map(pasted.map((raw) => [raw.signature_id, raw.distance ?? null]));
    const candidates: TReturnCandidate[] = system.signatures
        .filter((signature) => {
            if (signature.map_connection_id || !signature.signature_id || !distances.has(signature.signature_id)) return false;
            if (isWormholeSignature(signature)) return true;
            // Not categorised yet but sitting on grid: still a candidate.
            return !signature.signature_category_id && Boolean(distances.get(signature.signature_id)?.onGrid);
        })
        .map((signature) => ({ id: signature.id, distance: distances.get(signature.signature_id ?? '') ?? null, signature }));

    // A paste of just one signature after your jump into this system can only be the hole you came through.
    if (pasted.length === 1 && jump) {
        const only = system.signatures.find((signature) => signature.signature_id === pasted[0].signature_id);
        if (only && !only.map_connection_id && (isWormholeSignature(only) || !only.signature_category_id)) {
            linkReturnHole(only, ordered[0], true);
            return;
        }
    }

    const decision = decideReturnHole({ candidates, jumpedAt: jump?.at ?? null, now: Date.now() });
    if (decision.mode === 'none') return;

    if (decision.mode === 'auto') {
        const candidate = candidates.find((entry) => entry.id === decision.candidateId);
        if (candidate) linkReturnHole(candidate.signature, ordered[0], true);
        return;
    }

    pending_return = { candidates, connections: ordered };
    return_options.value = decision.ordered.map((id) => {
        const candidate = candidates.find((entry) => entry.id === id)!;
        return {
            id,
            signatureId: candidate.signature.signature_id ?? '???',
            typeLabel: candidate.signature.signature_type?.name ?? (isWormholeSignature(candidate.signature) ? 'Wormhole' : 'Unknown'),
            distanceText: candidate.distance?.text ?? null,
            onGrid: Boolean(candidate.distance?.onGrid),
        };
    });
    return_preselect.value = decision.preselectId;
    return_connections.value = ordered.map((connection) => ({
        id: connection.id,
        label: `Back to ${aliasedSolarsystemLabel(connection.target.alias, connection.target.solarsystem.name)}`,
    }));
    return_dialog_open.value = true;
}

/** Link the return hole, copy its return bookmark, and offer Undo. */
function linkReturnHole(signature: TSignature, connection: TProcessedConnection, automatic: boolean): void {
    const system = props.map_solarsystem;
    if (!system) return;

    const previousTypeId = signature.signature_type_id;
    // A hole not categorised yet becomes a wormhole when it is linked.
    const wormholeCategoryId = signatureCategories.find((category) => category.code === 'wormhole')?.id ?? null;
    updateSignature(signature, {
        map_connection_id: connection.id,
        ...(!signature.signature_category_id && wormholeCategoryId !== null ? { signature_category_id: wormholeCategoryId } : {}),
    });

    const name = formatBookmarkName(
        connection.target,
        {
            signatureId: signature.signature_id,
            shipSize: connection.ship_size,
            massStatus: connection.mass_status,
            lifetime: connection.lifetime_status,
        },
        page.props.map,
        system.alias,
        system.alias,
        system.solarsystem.class,
        map_system.value?.combat_color ?? null,
        Boolean(map_system.value?.combat_home),
    );
    if (name) {
        navigator.clipboard.writeText(name).catch(() => undefined);
        // This is the better way back (with the return signature): drop the held one.
        clearHeldWayBack();
    }

    toast.success(automatic ? `Linked ${signature.signature_id} as your return hole` : `Return hole ${signature.signature_id} linked`, {
        description: name ? `Copied ${visibleBookmarkName(name)}` : undefined,
        action: {
            label: 'Undo',
            onClick: () => updateSignature(signature, { map_connection_id: null, ...(previousTypeId === null ? { signature_type_id: null } : {}) }),
        },
    });
}

function handleReturnConfirm(selection: { signatureId: number; connectionId: number }): void {
    const candidate = pending_return?.candidates.find((entry) => entry.id === selection.signatureId);
    const connection = pending_return?.connections.find((entry) => entry.id === selection.connectionId);
    if (candidate && connection) linkReturnHole(candidate.signature, connection, false);
    pending_return = null;
}

// Seconds left to paste a scan and have the return hole linked automatically:
// shown while you are in the system you just jumped into and its connection
// back still has no signature on this side.
const now = useNow({ interval: 1000 });
const auto_link_seconds = computed(() => {
    const system = props.map_solarsystem;
    const jump = recentJump.value;
    if (!system || !jump || jump.toSolarsystemId !== system.solarsystem_id) return 0;

    const waiting = connections.value.some(
        (connection) =>
            connection.type !== 'stargate' &&
            !dismissed_connections.has(connection.id) &&
            !(connection.signatures ?? []).some((signature) => signature.map_solarsystem_id === system.id),
    );
    if (!waiting) return 0;

    return Math.max(0, Math.ceil((AUTO_LINK_WINDOW_MS - (now.value.getTime() - jump.at)) / 1000));
});

function handleReturnSkip(connectionIds: number[]): void {
    for (const id of connectionIds) dismissed_connections.add(id);
    pending_return = null;
}

const UNCATEGORIZED_FILTER = '__uncategorized__';

const categoryFilterOptions: Array<{ value: string; icon: Component; color: string; label: string }> = [
    { value: 'Wormhole', icon: Fan, color: 'text-sky-400', label: 'Wormhole' },
    { value: 'Data Site', icon: Database, color: 'text-cyan-400', label: 'Data Site' },
    { value: 'Relic Site', icon: Landmark, color: 'text-amber-400', label: 'Relic Site' },
    { value: 'Ore Site', icon: Gem, color: 'text-yellow-400', label: 'Ore Site' },
    { value: 'Gas Site', icon: Cloud, color: 'text-orange-400', label: 'Gas Site' },
    { value: 'Combat Site', icon: Swords, color: 'text-green-400', label: 'Combat Site' },
    { value: 'Homefront Operations', icon: Shield, color: 'text-rose-400', label: 'Homefront Operations' },
    { value: 'Factional Warfare Site', icon: Flag, color: 'text-fuchsia-400', label: 'Factional Warfare Site' },
    { value: UNCATEGORIZED_FILTER, icon: CircleHelp, color: 'text-muted-foreground', label: 'Uncategorized' },
];

// Persist the hidden categories instead of the visible ones so categories added
// in later releases default to visible for users with saved filters.
const hiddenCategoryFilters = useLocalStorage<string[]>('signatures-category-hidden-filters', []);

const activeCategoryFilters = computed<string[]>({
    get: () => categoryFilterOptions.map((option) => option.value).filter((value) => !hiddenCategoryFilters.value.includes(value)),
    set: (values) => {
        hiddenCategoryFilters.value = categoryFilterOptions.map((option) => option.value).filter((value) => !values.includes(value));
    },
});

const filteredSignatures = computed(() =>
    signatures.value.filter((signature) => {
        const key = signature.signature_category?.name ?? UNCATEGORIZED_FILTER;
        return !hiddenCategoryFilters.value.includes(key);
    }),
);

const hiddenSignaturesCount = computed(() => signatures.value.length - filteredSignatures.value.length);

const { sortPreferences, sorted, updateSortPreferences } = useSortableSignatures(filteredSignatures);

const connected_connections = computed(() => {
    return connections.value.filter((connection) => {
        return signatures.value.some((signature) => {
            return signature.map_connection_id === connection.id;
        });
    });
});

const unconnected_connections = computed(() => {
    return connections.value.filter((connection) => {
        return !signatures.value.some((signature) => {
            return signature.map_connection_id === connection.id;
        });
    });
});

function handleSort(column: 'id' | 'category' | 'type' | 'age') {
    let newDirection: 'asc' | 'desc';

    if (sortPreferences.value.column === column) {
        newDirection = sortPreferences.value.direction === 'asc' ? 'desc' : 'asc';
    } else {
        newDirection = 'asc';
    }

    updateSortPreferences(column, newDirection);
}

function createNewSignature() {
    if (!props.map_solarsystem) return;
    createSignature(props.map_solarsystem.id);
}
</script>

<template>
    <!-- Empty state when no system selected -->
    <SignaturesEmptyState v-if="!map_solarsystem" show-combat />

    <!-- Signatures list when system is selected -->
    <MapPanel v-if="map_solarsystem" class="@container/sigheader overflow-x-hidden">
        <MapPanelHeader>
            <CombatControls class="mr-2" />
            Signatures
            <span v-if="filteredSignatures.length" class="ml-1 text-amber-400">{{ filteredSignatures.length }}</span>
            <span v-if="hiddenSignaturesCount > 0" class="ml-1 text-muted-foreground/70">{{ hiddenSignaturesCount }} hidden</span>
            <button
                v-if="heldWayBack && is_combat"
                type="button"
                class="ml-2 rounded bg-amber-500/20 px-1.5 py-0.5 font-sans text-[10px] tracking-normal text-amber-300 normal-case hover:bg-amber-500/30"
                :title="`Copies ${heldWayBack.name}`"
                @click="copyHeldWayBack"
            >
                Way back ready · click to copy
            </button>
            <span v-if="armed_text" class="ml-2 truncate font-sans text-[10px] tracking-normal text-red-400 normal-case" :title="`Armed holes: ${armed_text}`">
                Armed: {{ armed_text }}
            </span>
            <template #actions>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <MapPanelHeaderActionButton size="icon" @click="toggleCompactSignatureList">
                            <Rows2 v-if="map_user_settings.compact_signature_list" class="size-3.5" />
                            <Rows3 v-else class="size-3.5" />
                        </MapPanelHeaderActionButton>
                    </TooltipTrigger>
                    <TooltipContent>
                        {{ map_user_settings.compact_signature_list ? 'Switch to comfortable signature list' : 'Switch to compact signature list' }}
                    </TooltipContent>
                </Tooltip>
                <ToggleGroup v-model="activeCategoryFilters" type="multiple" size="sm" variant="outline">
                    <Tooltip v-for="option in categoryFilterOptions" :key="option.value">
                        <TooltipTrigger as-child>
                            <ToggleGroupItem :value="option.value" :aria-label="option.label">
                                <component :is="option.icon" class="mx-1 size-3" :class="option.color" />
                            </ToggleGroupItem>
                        </TooltipTrigger>
                        <TooltipContent>{{ option.label }}</TooltipContent>
                    </Tooltip>
                </ToggleGroup>
                <template v-if="can_write">
                    <Tooltip v-if="pasted_signatures">
                        <TooltipTrigger as-child>
                            <MapPanelHeaderActionButton v-if="pasted_signatures" @click="pasted_signatures = null">
                                Unselect
                            </MapPanelHeaderActionButton>
                        </TooltipTrigger>
                        <TooltipContent> Unselect signatures</TooltipContent>
                    </Tooltip>
                    <Tooltip v-if="deleted_signatures.length > 0">
                        <TooltipTrigger as-child>
                            <MapPanelHeaderActionButton @click="deleteMissingSignatures(true)" variant="destructive" size="icon">
                                <TrashIcon />
                            </MapPanelHeaderActionButton>
                        </TooltipTrigger>
                        <TooltipContent>
                            Delete missing (red) signatures and their connections. Careful: signatures you ignore in game show red too.
                        </TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <MapPanelHeaderActionButton @click="pasteSignatures" size="icon">
                                <PasteIcon />
                            </MapPanelHeaderActionButton>
                        </TooltipTrigger>
                        <TooltipContent> Paste signatures from clipboard (Ctrl/Cmd + V)</TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <MapPanelHeaderActionButton @click="createNewSignature" size="icon">
                                <PlusIcon />
                            </MapPanelHeaderActionButton>
                        </TooltipTrigger>
                        <TooltipContent> Create new signature</TooltipContent>
                    </Tooltip>
                </template>
            </template>
        </MapPanelHeader>
        <MapPanelContent>
            <!-- Patch 16: where am I (Rage Scanning), big enough to call on comms -->
            <div
                v-if="where_am_i"
                class="flex items-baseline gap-2 border-b border-border/40 px-3 py-1.5"
                :style="where_am_i.hex ? { borderLeft: `4px solid ${where_am_i.hex}` } : undefined"
            >
                <span class="font-display text-[28px] leading-none font-bold">{{ where_am_i.name }}</span>
                <span v-if="where_am_i.chain" class="font-display text-lg font-semibold" :style="{ color: where_am_i.hex ?? undefined }">{{ where_am_i.chain }}</span>
                <span class="flex items-center gap-1 font-mono text-xs text-muted-foreground">
                    <SolarsystemClass :solarsystem_class="where_am_i.solarsystemClass" /> {{ where_am_i.jcode }}
                </span>
                <span v-if="where_am_i.unscanned > 0" class="ml-auto text-xs text-amber-400">{{ where_am_i.unscanned }} unscanned</span>
                <span v-else class="ml-auto text-xs text-emerald-400">all scanned</span>
            </div>
            <!-- Header -->
            <div
                class="flex items-center gap-2 border-b border-border/30 bg-muted/20 px-3 font-mono text-[10px] tracking-wider text-muted-foreground uppercase"
                :class="map_user_settings.compact_signature_list ? 'py-0.5' : 'py-1.5'"
            >
                <span class="w-4 shrink-0" aria-hidden="true"></span>
                <button class="flex w-16 shrink-0 items-center gap-1 hover:text-foreground" @click="handleSort('id')">
                    <span>ID</span>
                    <ArrowUp v-if="sortPreferences.column === 'id' && sortPreferences.direction === 'asc'" class="size-3" />
                    <ArrowDown v-if="sortPreferences.column === 'id' && sortPreferences.direction === 'desc'" class="size-3" />
                </button>
                <button class="flex w-24 shrink-0 items-center gap-1 hover:text-foreground" @click="handleSort('category')">
                    <span>Cat</span>
                    <ArrowUp v-if="sortPreferences.column === 'category' && sortPreferences.direction === 'asc'" class="size-3" />
                    <ArrowDown v-if="sortPreferences.column === 'category' && sortPreferences.direction === 'desc'" class="size-3" />
                </button>
                <button class="flex min-w-0 flex-1 items-center gap-1 hover:text-foreground" @click="handleSort('type')">
                    <span>Type</span>
                    <ArrowUp v-if="sortPreferences.column === 'type' && sortPreferences.direction === 'asc'" class="size-3" />
                    <ArrowDown v-if="sortPreferences.column === 'type' && sortPreferences.direction === 'desc'" class="size-3" />
                </button>
                <span class="min-w-0 flex-1">Conn</span>
                <span class="w-16 shrink-0">Name</span>
                <span class="w-14 shrink-0"></span>
                <button class="flex w-10 shrink-0 items-center justify-end gap-1 hover:text-foreground" @click="handleSort('age')">
                    <span>Age</span>
                    <ArrowUp v-if="sortPreferences.column === 'age' && sortPreferences.direction === 'asc'" class="size-3" />
                    <ArrowDown v-if="sortPreferences.column === 'age' && sortPreferences.direction === 'desc'" class="size-3" />
                </button>
            </div>

            <!-- Patch 15: which hole to arm (more than one after a full paste) -->
            <div v-if="arm_choice_rows.length" class="border-b border-amber-500/40 bg-amber-500/15 px-3 py-1.5 text-xs">
                <div class="mb-1 flex items-center justify-between">
                    <span class="font-medium text-amber-300">Arm the hole you're jumping? Click one: it arms and copies its bookmark</span>
                    <span class="flex items-center gap-2 font-mono text-amber-300/80">
                        {{ arm_choice_seconds }}s
                        <button type="button" class="rounded px-1 hover:bg-amber-500/20" aria-label="Arm nothing" @click="arm_choice = null">✕</button>
                    </span>
                </div>
                <button
                    v-for="hole in arm_choice_rows"
                    :key="hole.id"
                    type="button"
                    class="flex w-full items-center gap-3 rounded px-2 py-0.5 text-left hover:bg-amber-500/25"
                    @click="pickArmChoice(hole)"
                >
                    <span class="w-16 font-mono font-semibold">{{ hole.signature_id }}</span>
                    <span class="flex-1 truncate text-muted-foreground">{{ hole.signature_type?.name ?? 'Wormhole, type not set' }}</span>
                    <span class="font-mono text-amber-300">→ {{ displayAlias(planned_aliases.get(hole.id) ?? claim_alias ?? '') || 'next' }}</span>
                </button>
                <p v-if="arm_choice && arm_choice.unscanned > 0" class="px-2 pt-0.5 text-amber-300/80">
                    {{ arm_choice.unscanned }} signature{{ arm_choice.unscanned === 1 ? '' : 's' }} not scanned yet: could be the hole you want.
                </p>
            </div>

            <!-- Patch 14: cleanup rows (a combat chain being converted, one system at a time) -->
            <ChainCleanupRows :map-solarsystem-id="map_solarsystem.id" />

            <!-- Auto-link countdown -->
            <div
                v-if="auto_link_seconds > 0"
                class="flex items-center justify-between border-b border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs text-emerald-300"
            >
                <span>Paste your scan to auto-link the return hole</span>
                <span class="font-mono font-medium">{{ auto_link_seconds }}s</span>
            </div>

            <!-- Signature rows -->
            <template v-if="sorted.length">
                <Signature
                    v-for="signature in sorted"
                    :signature="signature"
                    :key="signature.id"
                    :is_deleted="signature.deleted"
                    :is_new="signature.new"
                    :is_updated="signature.updated"
                    :unconnected_connections="unconnected_connections"
                    :connected_connections="connected_connections"
                    :selected_map_solarsystem="map_solarsystem"
                    :planned_alias="planned_aliases.get(signature.id) ?? null"
                    :claim_alias="claim_alias"
                    :number_owners="number_owners"
                    :static_owner_id="static_owner_id"
                    :user_id="user_id"
                    :arm_as="armAsFor(signature)"
                    @arm="(alias, swap) => armRow(signature, alias, swap)"
                    @disarm="disarmRow(signature)"
                />
            </template>
            <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-4">
                <p class="font-mono text-[10px] tracking-wider text-muted-foreground/60 uppercase">
                    {{ hiddenSignaturesCount > 0 ? `${hiddenSignaturesCount} hidden by filters` : 'No signatures' }}
                </p>
            </div>
        </MapPanelContent>
        <SideChainLetterDialog
            v-if="letter_suggested"
            v-model:open="letter_open"
            :system-name="map_solarsystem.solarsystem.name"
            :suggested="letter_suggested"
            :taken="letter_taken"
            @choose="handleLetterChoice"
        />
        <ReturnHoleDialog
            v-model:open="return_dialog_open"
            :options="return_options"
            :preselect-id="return_preselect"
            :connections="return_connections"
            :countdown-seconds="popup_seconds"
            @confirm="handleReturnConfirm"
            @skip="handleReturnSkip"
        />
    </MapPanel>

    <!-- Warning dialog for pasting in different system -->
    <PasteSignatureWarningDialog
        v-model:open="show_system_mismatch_warning"
        :target-system="map_solarsystem"
        :character="character"
        @confirm="confirmPasteInDifferentSystem"
        @cancel="cancelPaste"
    />
</template>
