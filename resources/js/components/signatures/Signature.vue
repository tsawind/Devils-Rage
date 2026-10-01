<script setup lang="ts">
import TrashIcon from '@/components/icons/TrashIcon.vue';
import MapConnectionInput from '@/components/signatures/MapConnectionInput.vue';
import SignatureTimeDetails from '@/components/signatures/SignatureTimeDetails.vue';
import SignatureTypeInput from '@/components/signatures/SignatureTypeInput.vue';
import StaticRenameDialog from '@/components/signatures/StaticRenameDialog.vue';
import WormholeTypeInput from '@/components/signatures/WormholeTypeInput.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import CountdownBar from '@/components/combat/CountdownBar.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useCombat } from '@/composables/combat/useCombat';
import { usePopupCountdown } from '@/composables/combat/usePopupCountdown';
import { requestStaticCheck } from '@/composables/signatures/useStaticCertainty';
import { useMapUserSettings } from '@/composables/useMapUserSettings';
import usePermission from '@/composables/usePermission';
import { useShowMap } from '@/composables/useShowMap';
import { getTypesByCategory, signatureCategories } from '@/const/signatures';
import { classSortWeight } from '@/const/solarsystemClasses';
import { aliasForSlot, displayAlias, isIgnoredAlias, staticSlotAlias, suggestAlias } from '@/lib/alias';
import type { TArmAsOption } from '@/lib/arming';
import { buildSignatureBookmark, formatBookmarkName, visibleBookmarkName } from '@/lib/bookmark';
import { chainAliases, combatColorLabel } from '@/lib/combat';
import { isK162, validateManualAlias } from '@/lib/chainNumbering';
import { Data } from '@/lib/data';
import { formatDateToISO } from '@/lib/utils';
import { deleteSignature, TProcessedConnection, updateMapConnection, updateSignature, useMapSolarsystems, useMapStore } from '@/map/api';
import type { TResolvedSelectedMapSolarsystem } from '@/pages/maps';
import { TSignature } from '@/types/models';
import { UTCDate } from '@date-fns/utc';
import type { FormDataConvertible } from '@inertiajs/core';
import { syncRefs } from '@vueuse/core';
import { Check, Cloud, Copy, Crosshair, Database, Fan, Flag, Gem, Heart, Landmark, MoreVertical, Shield, Swords } from 'lucide-vue-next';
import { AcceptableValue } from 'reka-ui';
import { type Component, computed, nextTick, ref, toRef } from 'vue';
import { toast } from 'vue-sonner';

const {
    signature,
    unconnected_connections,
    connected_connections,
    selected_map_solarsystem,
    planned_alias,
    claim_alias = null,
    number_owners,
    static_owner_id,
    user_id = null,
    arm_as = [],
} = defineProps<{
    signature: TSignature;
    is_deleted?: boolean;
    is_new?: boolean;
    is_updated?: boolean;
    unconnected_connections: TProcessedConnection[];
    connected_connections: TProcessedConnection[];
    selected_map_solarsystem: TResolvedSelectedMapSolarsystem;
    /** The chain alias reserved for this hole among the system's unjumped wormholes. */
    planned_alias?: string | null;
    /** Combat chains: the next free number, taken by this hole if it is copied before it has one. */
    claim_alias?: string | null;
    /** Who holds each number in this system (alias → owner), for hand-set numbers. */
    number_owners?: Map<string, { signatureId: number | null; label: string }>;
    /** The signature marked as this system's static, if any. */
    static_owner_id?: number | null;
    /** Patch 13: you, for "armed by you". */
    user_id?: number | null;
    /** Patch 13: the numbers "Arm as…" offers (combat chains). */
    arm_as?: TArmAsOption[];
}>();

const emit = defineEmits<{
    /** Arm this hole as your next jump: as its own number (null) or as another (swap: someone else's). */
    arm: [alias: string | null, swap: boolean];
    disarm: [];
}>();

const original = toRef(() => signature.signature_id || '');
const signature_id = ref('');
syncRefs(original, signature_id);

const { canEdit: can_write } = usePermission();

// Inline editing state
const editingId = ref(false);
const idInputRef = ref<HTMLInputElement | null>(null);

const selected_connection = computed(() => {
    return (
        unconnected_connections.find((c) => c.id === signature.map_connection_id) ??
        connected_connections.find((c) => c.id === signature.map_connection_id) ??
        null
    );
});

const solarsystem_class = computed(() => selected_map_solarsystem.solarsystem.class);

const availableTypes = computed(() => {
    if (!signature.signature_category_id) return [];
    return getTypesByCategory(signature.signature_category_id).filter((type) => type.spawn_areas?.includes(solarsystem_class.value));
});

const sortedAvailableTypes = computed(() => {
    return availableTypes.value.toSorted((a, b) => classSortWeight(a.target_class) - classSortWeight(b.target_class));
});

const wormholeCategoryId = computed(() => {
    return signatureCategories.find((cat) => cat.name === 'Wormhole')?.id;
});

const isWormhole = computed(() => {
    return signature.signature_category_id === wormholeCategoryId.value;
});

const current_class = computed(() => {
    if (!selected_connection.value?.target) return null;
    return selected_connection.value.target.solarsystem.class;
});

const map_user_settings = useMapUserSettings();

const static_signatures = computed<string[]>(() => {
    if (!map_user_settings.value.show_statics_first) {
        return [];
    }
    return (selected_map_solarsystem.solarsystem.statics ?? []).map((wormhole_static) => wormhole_static.name);
});

const categoryAbbrev: Record<string, string> = {
    Wormhole: 'WH',
    'Data Site': 'Data',
    'Relic Site': 'Relic',
    'Ore Site': 'Ore',
    'Gas Site': 'Gas',
    'Combat Site': 'Combat',
    'Homefront Operations': 'HF',
    'Factional Warfare Site': 'FW',
};

const categoryIcon: Record<string, Component> = {
    Wormhole: Fan,
    'Data Site': Database,
    'Relic Site': Landmark,
    'Ore Site': Gem,
    'Gas Site': Cloud,
    'Combat Site': Swords,
    'Homefront Operations': Shield,
    'Factional Warfare Site': Flag,
};

const categoryColor: Record<string, string> = {
    Wormhole: 'text-sky-400',
    'Data Site': 'text-cyan-400',
    'Relic Site': 'text-amber-400',
    'Combat Site': 'text-green-400',
    'Gas Site': 'text-orange-400',
    'Ore Site': 'text-yellow-400',
    'Homefront Operations': 'text-rose-400',
    'Factional Warfare Site': 'text-fuchsia-400',
};

function getCategoryAbbrev(name?: string | null): string {
    return name ? (categoryAbbrev[name] ?? name) : '—';
}

function handleChange(data: Record<string, FormDataConvertible>) {
    updateSignature(signature, data);
}

function handleDelete() {
    deleteSignature(signature);
}

function handleCategoryChange(value: AcceptableValue) {
    handleChange({
        signature_category_id: value as number,
        signature_type_id: null,
        map_connection_id: null,
    });
}

function handleTypeChange(value: AcceptableValue) {
    const typeId = value as number | null;
    const wormholeName = typeId ? (availableTypes.value.find((type) => type.id === typeId)?.signature ?? null) : null;

    if (!isWormhole.value) {
        handleChange({ signature_type_id: typeId });
        return;
    }

    // A type that is one of this system's statics could be the static or a
    // wandering hole of the same type: ask. Any other type can be neither.
    const staticNames = (selected_map_solarsystem.solarsystem.statics ?? []).map((wormholeStatic) => wormholeStatic.name.trim().toUpperCase());
    const isStaticType = wormholeName !== null && !isK162(wormholeName) && staticNames.includes(wormholeName.trim().toUpperCase());

    // Patch 13: a static-type hole is only marked the static once it is
    // certain (everything scanned, nothing else can be it): save the type and
    // let the check decide when the update is back.
    const clearFlags = !isStaticType && (signature.is_static || signature.is_wandering) ? flagChanges(false, false) : {};
    handleChange({ signature_type_id: typeId, ...clearFlags });
    if (isStaticType) requestStaticCheck(selected_map_solarsystem.id, map_system.value);
}

// ---- "Static, wandering or unknown?" when a static type is picked ---------

const static_choice_open = ref(false);
const pending_type_id = ref<number | null>(null);
const pending_type_name = ref<string | null>(null);

function chooseStaticKind(kind: 'unknown' | 'static' | 'wandering'): void {
    if (!static_choice_open.value) return;
    static_choice_open.value = false;

    if (kind === 'static' && static_taken_by_other.value) kind = 'unknown';

    if (kind === 'static') {
        makeStatic(pending_type_id.value, true);
        return;
    }
    handleChange({ signature_type_id: pending_type_id.value, ...flagChanges(false, kind === 'wandering') });
}

function handleStaticChoiceOpenChange(isOpen: boolean): void {
    // Closing the popup without choosing keeps the type and counts as Unknown.
    if (!isOpen) chooseStaticKind('unknown');
}

// Combat mode: no answer within 60 s counts as Unknown.
const { popup_seconds, is_combat } = useCombat();
const { remaining: static_choice_remaining, fraction: static_choice_fraction } = usePopupCountdown(
    static_choice_open,
    () => popup_seconds.value,
    () => chooseStaticKind('unknown'),
);

// ---- Marking the static (patch 12) ------------------------------------------

const rename_open = ref(false);
/** The fields saved for the static whichever way the rename popup goes (type, flags). */
const pending_static = ref<Record<string, FormDataConvertible>>({});

/**
 * Mark this hole as the system's static.
 *
 * - Already named for the static slot, or a main-chain hole with no number
 *   yet: just mark it (an unnumbered hole then takes the static's slot).
 * - Main chain, already numbered (bookmarked in game): ask whether to rename
 *   it to the static's number; Keep is the default.
 * - Combat chain: never switch to 0 on its own; it is marked and keeps its
 *   number. Only marking it by hand (`manual`) offers the rename.
 *
 * `typeId` undefined leaves the type as it is.
 */
function makeStatic(typeId: number | null | undefined, manual: boolean): void {
    const base: Record<string, FormDataConvertible> = {
        ...(typeId !== undefined ? { signature_type_id: typeId } : {}),
        is_static: true,
        is_wandering: false,
    };
    const current = signature.alias ?? null;

    // Patch 14: ask when a name used in game would change (a locked number, or the jumped system's name).
    // A planned number nobody copied, armed or jumped just shifts on its own.
    const name = current ?? forward_target_alias.value;
    if (name === static_slot.value || (!is_limbo.value && !name)) {
        const previous: Record<string, FormDataConvertible> = {
            signature_type_id: signature.signature_type_id,
            is_static: Boolean(signature.is_static),
            is_wandering: Boolean(signature.is_wandering),
        };
        handleChange(base);
        if (!manual) {
            toast.success(`${signature.signature_id ?? 'Signature'} marked as the static`, {
                description: `Takes ${displayAlias(static_slot.value)}`,
                action: { label: 'Undo', onClick: () => handleChange(previous) },
            });
        }
        return;
    }

    // Combat mode (outside combat chains): no popup, it keeps its name.
    if (!is_limbo.value && is_combat.value) {
        handleChange({ ...base, ...(!current && name ? { alias: name } : {}) });
        toast.success(`${signature.signature_id ?? 'Signature'} marked as the static`, { description: `Keeps ${displayAlias(name)} (combat mode).` });
        return;
    }

    if (is_limbo.value && !manual) {
        handleChange(base);
        toast.success(`${signature.signature_id ?? 'Signature'} marked as the static`, {
            description: `Keeps ${current ? displayAlias(current) : 'its jump-order number'}.`,
            action: { label: `Rename to ${displayAlias(static_slot.value)}…`, onClick: () => openStaticRename({ is_static: true, is_wandering: false }) },
        });
        return;
    }

    openStaticRename(base);
}

function openStaticRename(base: Record<string, FormDataConvertible>): void {
    pending_static.value = base;
    if (typeof base.signature_type_id === 'number' || base.signature_type_id === null) {
        pending_type_id.value = base.signature_type_id as number | null;
    } else {
        pending_type_id.value = signature.signature_type_id ?? null;
    }
    rename_open.value = true;
}

/** The type picked for the pending static, to name the bookmarks it will get. */
const pending_type = computed(() => availableTypes.value.find((type) => type.id === pending_type_id.value) ?? null);

/** The bookmark this hole gets as the static, named `alias`. */
function staticBookmarkName(alias: string): string {
    const target = selected_connection.value?.target ?? null;
    return buildSignatureBookmark({
        signature: {
            ...signature,
            is_static: true,
            is_wandering: false,
            signature_type: pending_type.value ? { target_class: pending_type.value.target_class } : signature.signature_type,
            wormhole: pending_type.value ? { name: pending_type.value.signature } : signature.wormhole,
        },
        currentSystem: {
            alias: selected_map_solarsystem.alias,
            class: selected_map_solarsystem.solarsystem.class,
            combatHome: is_combat_home.value,
            combatColor: map_system.value?.combat_color ?? null,
        },
        connectionTarget: target ? { ...target, alias } : null,
        aliases: chainAliases(map_solarsystems.value, map_system.value),
        formats: page.props.map,
        detectReturn: true,
        plannedAlias: alias,
    });
}

/** The far side's way-back bookmark, when the hole is already jumped and the far system is named `alias`. */
function farSideReturnName(alias: string): string | null {
    const connection = selected_connection.value;
    if (!connection) return null;
    const farSignature = (connection.signatures ?? []).find((candidate) => candidate.map_solarsystem_id !== selected_map_solarsystem.id);
    return formatBookmarkName(
        {
            alias: selected_map_solarsystem.alias,
            solarsystem: selected_map_solarsystem.solarsystem,
            combat_home: is_combat_home.value,
            combat_color: map_system.value?.combat_color ?? null,
        },
        { signatureId: farSignature?.signature_id ?? null },
        page.props.map,
        alias,
        alias,
        connection.target.solarsystem.class,
        connection.target.combat_color ?? null,
        Boolean(connection.target.combat_home),
    );
}

const rename_changes = computed(() => {
    if (!rename_open.value) return [];
    const from = rename_from_alias.value;
    const to = static_slot.value;
    const changes = [{ label: 'In this system', from: bookmark_name.value, to: staticBookmarkName(to) }];
    const farFrom = farSideReturnName(from);
    const farTo = farSideReturnName(to);
    if (farFrom && farTo) changes.push({ label: 'On the far side (way back)', from: farFrom, to: farTo });
    return changes;
});

/** The name the hole has now: its locked number, or the one it would get (planned, or the next in a combat chain). */
const rename_from_alias = computed(() => signature.alias ?? forward_target_alias.value ?? planned_alias ?? claim_alias ?? '');

/**
 * Systems already mapped further down this hole: renaming it is blocked then
 * (patch 12, all chains), since everything below was bookmarked from its name.
 */
const rename_beyond = computed(() => descendantsOf(rename_from_alias.value));

function descendantsOf(alias: string): string[] {
    const from = alias.toUpperCase();
    if (!from) return [];
    return chainAliases(map_solarsystems.value, map_system.value)
        .filter((candidate) => candidate.toUpperCase().startsWith(from) && candidate.length > from.length)
        .map((candidate) => displayAlias(candidate));
}

function handleRenameChoice(choice: 'rename' | 'keep'): void {
    const base = pending_static.value;
    if (choice === 'keep' || rename_beyond.value.length > 0) {
        // Keep its name: lock it, or the static would take the static's slot on its own.
        const keep = forward_target_alias.value;
        handleChange({ ...base, ...(!is_limbo.value && !signature.alias && keep && keep !== static_slot.value ? { alias: keep } : {}) });
        return;
    }

    const name = staticBookmarkName(static_slot.value);
    handleChange({ ...base, alias: static_slot.value, ...(selected_connection.value ? { rename_system: true } : {}) });
    navigator.clipboard.writeText(name).catch(() => undefined);
    toast.success(`Renamed to ${displayAlias(static_slot.value)}`, { description: `Copied ${visibleBookmarkName(name)}` });
}

// ---- Chain numbering: Static / Wandering / hand-set number ----------------

const is_k162 = computed(() => isK162(signature.wormhole?.name));

// This system as the map knows it: a combat home numbers its holes 1, 2, 3 (static 0).
const map_system = computed(() => map_solarsystems.value.find((solarsystem) => solarsystem.id === selected_map_solarsystem.id) ?? null);
const is_combat_home = computed(() => Boolean(map_system.value?.combat_home));
/** In a combat chain (home included): holes are numbered in jump order and never switch to 0 on their own. */
const is_limbo = computed(() => Boolean(map_system.value?.combat_color));
/** Combat chains and combat mode never stop you with a rename popup: the change just happens (or the name is kept). */
const quiet = computed(() => is_limbo.value || is_combat.value);

const map_store = (() => {
    try {
        return useMapStore();
    } catch {
        return null;
    }
})();

/** The system this hole leads on to, unless it is the way back (the parent's name is not this hole's name). */
const forward_target_alias = computed(() => {
    const target = selected_connection.value?.target ?? null;
    if (!target) return null;
    const parentId = map_store?.bandLayout.value?.parentOf.get(selected_map_solarsystem.id);
    return parentId === target.id ? null : (target.alias ?? null);
});

const static_slot = computed(() => staticSlotAlias(selected_map_solarsystem.alias, page.props.map.bookmark_ignored_alias, is_combat_home.value));
const static_taken_by_other = computed(() => static_owner_id != null && static_owner_id !== signature.id);

/**
 * The fields to send when Static / Wandering change. Becoming the static goes
 * through `makeStatic` (it may ask before renaming); a hole that stops being
 * the static gives the slot back and gets a normal number again.
 */
function flagChanges(isStatic: boolean, isWandering: boolean): Record<string, FormDataConvertible> {
    const changes: Record<string, FormDataConvertible> = { is_static: isStatic, is_wandering: isWandering };

    if (!isStatic && signature.alias === static_slot.value) {
        changes.alias = null;
    }

    return changes;
}

function handleToggleStatic() {
    if (is_k162.value) return;
    if (signature.is_static) {
        // Patch 14: an unjumped hole bookmarked as the static's slot gets a new number: ask first.
        if (!is_limbo.value && !selected_connection.value && signature.alias && signature.alias === static_slot.value) {
            const next = nextFreeNumber();
            if (next && is_combat.value) {
                handleChange({ is_static: false, is_wandering: false, alias: next });
                return;
            }
            if (next) {
                askRename({
                    title: `${signature.signature_id ?? 'This hole'} is not the static: rename ${displayAlias(signature.alias)} → ${displayAlias(next)}?`,
                    description: 'The static slot is only for the static. Cancel keeps it marked as the static.',
                    from: signature.alias,
                    to: next,
                    isStatic: false,
                    keepLabel: 'Cancel',
                    renameLabel: `Untick and rename to ${displayAlias(next)}`,
                    onRename: () => handleChange({ is_static: false, is_wandering: false, alias: next }),
                });
                return;
            }
        }
        handleChange(flagChanges(false, false));
        return;
    }
    if (static_taken_by_other.value) {
        toast.error('Another signature in this system is already the static.');
        return;
    }
    makeStatic(undefined, true);
}

function handleToggleWandering() {
    if (is_k162.value) return;
    handleChange(flagChanges(false, !signature.is_wandering));
}

function handleSetNumber() {
    const current = signature.alias ?? planned_alias ?? '';
    const allowed =
        !is_combat_home.value && isIgnoredAlias(selected_map_solarsystem.alias, page.props.map.bookmark_ignored_alias)
            ? 'A (static), B, D, G, J, K, M, N, P … Z'
            : '0 (static), 1-9 or A-Z';
    const input = window.prompt(`Number for ${signature.signature_id ?? 'this signature'} — enter one slot: ${allowed} (current: ${current || 'none'})`);
    if (input === null) return;

    const alias = aliasForSlot(selected_map_solarsystem.alias, input, page.props.map.bookmark_ignored_alias, is_combat_home.value);

    // Numbers held by this signature or by the system it already leads to aren't clashes.
    const others = new Map<string, string>();
    for (const [takenAlias, owner] of number_owners ?? []) {
        if (owner.signatureId === signature.id) continue;
        if (selected_connection.value?.target.alias?.toUpperCase() === takenAlias) continue;
        others.set(takenAlias, owner.label);
    }

    const result = validateManualAlias(alias, others);
    if (!result.ok) {
        toast.error(result.error);
        return;
    }

    const makesStatic = result.alias === static_slot.value;
    if (makesStatic && is_k162.value) {
        toast.error(`${static_slot.value} is the static's slot, and a K162 can never be the static.`);
        return;
    }
    if (makesStatic && static_taken_by_other.value) {
        toast.error(`${static_slot.value} is the static's slot, and another signature in this system is already the static.`);
        return;
    }
    // Systems mapped below this hole were bookmarked from its current name: don't rename it.
    const below = signature.alias && result.alias !== signature.alias ? descendantsOf(signature.alias) : [];
    if (below.length > 0) {
        toast.error(`Can't rename to ${displayAlias(result.alias)}: ${below.join(', ')} ${below.length === 1 ? 'is' : 'are'} already mapped further down.`);
        return;
    }

    const payload: Record<string, FormDataConvertible> = {
        alias: result.alias,
        is_static: makesStatic,
        ...(makesStatic ? { is_wandering: false } : {}),
    };
    // Patch 14: a name already used in game (jumped, or locked by a copy or arm) changes: ask first.
    const before = signature.alias ?? forward_target_alias.value ?? null;
    const renamesSystem = Boolean(forward_target_alias.value);
    if (!quiet.value && before && before.toUpperCase() !== result.alias.toUpperCase()) {
        askRename({
            title: `Rename ${displayAlias(before)} → ${displayAlias(result.alias)}?`,
            description: renamesSystem
                ? `The system ${displayAlias(before)} on the map becomes ${displayAlias(result.alias)}. Change these bookmarks in game.`
                : 'Change this bookmark in game.',
            from: before,
            to: result.alias,
            isStatic: makesStatic,
            keepLabel: `Keep ${displayAlias(before)}`,
            renameLabel: `Rename to ${displayAlias(result.alias)}`,
            onRename: () => handleChange({ ...payload, ...(renamesSystem ? { rename_system: true } : {}) }),
        });
        return;
    }
    handleChange({ ...payload, ...(renamesSystem && quiet.value ? { rename_system: true } : {}) });
}

// ---- Rename popups (patch 14) -------------------------------------------------
// Any change to a name already used in game asks first (not in combat chains).

type TRenameAsk = {
    title: string;
    description: string;
    from: string;
    to: string;
    isStatic: boolean;
    keepLabel: string;
    renameLabel: string;
    onRename: () => void;
};

const rename_ask = ref<TRenameAsk | null>(null);
const rename_ask_open = ref(false);

function askRename(ask: TRenameAsk): void {
    rename_ask.value = ask;
    rename_ask_open.value = true;
}

const rename_ask_changes = computed(() => {
    const ask = rename_ask.value;
    if (!ask) return [];
    const target = selected_connection.value?.target ?? null;
    const name = (alias: string, isStatic: boolean) =>
        buildSignatureBookmark({
            signature: { ...signature, is_static: isStatic, is_wandering: false },
            currentSystem: {
                alias: selected_map_solarsystem.alias,
                class: selected_map_solarsystem.solarsystem.class,
                combatHome: is_combat_home.value,
                combatColor: map_system.value?.combat_color ?? null,
            },
            connectionTarget: target ? { ...target, alias } : null,
            aliases: chainAliases(map_solarsystems.value, map_system.value),
            formats: page.props.map,
            detectReturn: true,
            plannedAlias: alias,
        });
    const changes = [{ label: 'In this system', from: name(ask.from, Boolean(signature.is_static)), to: name(ask.to, ask.isStatic) }];
    const farFrom = farSideReturnName(ask.from);
    const farTo = farSideReturnName(ask.to);
    if (farFrom && farTo) changes.push({ label: 'On the far side (way back)', from: farFrom, to: farTo });
    return changes;
});

const rename_ask_beyond = computed(() => (rename_ask.value ? descendantsOf(rename_ask.value.from) : []));

function handleRenameAsk(choice: 'rename' | 'keep'): void {
    const ask = rename_ask.value;
    const blocked = rename_ask_beyond.value.length > 0;
    rename_ask.value = null;
    if (ask && choice === 'rename' && !blocked) ask.onRename();
}

/** The next free number in this system, for a hole that stops being the static. */
function nextFreeNumber(): string | null {
    const taken = [...(number_owners ?? new Map()).entries()].filter(([, owner]) => owner.signatureId !== signature.id).map(([alias]) => alias);
    return suggestAlias({
        parentAlias: selected_map_solarsystem.alias,
        targetIsWormhole: true,
        originIsWormhole: true,
        aliases: [...taken, static_slot.value],
        scheme: page.props.map.bookmark_alias_scheme,
        ignoredAlias: page.props.map.bookmark_ignored_alias,
        combatHome: is_combat_home.value,
    });
}

function handleMapConnectionChange(value: AcceptableValue) {
    handleChange({ map_connection_id: value as number | null });
}

function startEditId() {
    if (!can_write.value) return;
    signature_id.value = signature.signature_id || '';
    editingId.value = true;
    nextTick(() => {
        idInputRef.value?.focus();
        idInputRef.value?.select();
    });
}

function handleIdInput(event: Event) {
    const target = event.target as HTMLInputElement;
    let value = target.value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
    if (value.length >= 4) {
        value = value.slice(0, 3) + '-' + value.slice(3, 6);
    }
    signature_id.value = value;
}

function saveId() {
    const newId = signature_id.value.trim();
    if (newId !== signature.signature_id) {
        handleChange({ signature_id: newId || null });
    }
    editingId.value = false;
}

function cancelEditId() {
    editingId.value = false;
    signature_id.value = signature.signature_id || '';
}

function handleLifetimeChange(lifetime: AcceptableValue) {
    handleChange({
        lifetime: lifetime as string,
        lifetime_updated_at: formatDateToISO(new UTCDate()),
    });
}

function handleMassStatusChange(mass_status: AcceptableValue) {
    const value = mass_status === 'unknown' ? null : mass_status;
    handleChange({ mass_status: value as string | null });
}

function handleTogglePreserveMass() {
    if (!selected_connection.value) return;
    updateMapConnection(selected_connection.value, { preserve_mass: !selected_connection.value.preserve_mass });
}

const page = useShowMap();
const { map_solarsystems } = useMapSolarsystems();

// ---- Name column -----------------------------------------------------------

/** Linked holes show where they lead; unjumped ones the number they have (bold once locked) or will get. */
const name_label = computed(() => {
    if (!isWormhole.value) return '';
    const target = selected_connection.value?.target;
    if (target) {
        // A loop into another combat chain's system carries that chain's color ("111-2Red").
        const otherChain = target.combat_color && !target.combat_home && target.combat_color !== (map_system.value?.combat_color ?? null);
        const name = displayAlias(target.alias) || '—';
        return otherChain && target.alias ? `${name}${combatColorLabel(target.combat_color) ?? ''}` : name;
    }
    const alias = signature.alias ?? planned_alias;
    return alias ? displayAlias(alias) : '·';
});

const name_class = computed(() => {
    if (selected_connection.value) return 'text-foreground';
    if (signature.alias) return 'font-bold text-foreground';
    return 'text-muted-foreground';
});

const name_title = computed(() => {
    if (!isWormhole.value) return undefined;
    if (selected_connection.value) return 'Leads to this system';
    if (signature.alias) return 'Number locked (copied or jumped)';
    if (planned_alias) return 'The number this hole gets';
    return 'Combat chain: numbered when it is jumped or copied';
});

// The destination bookmark for this hole: the real connection target when one
// is set, otherwise the auto-suggested next chain alias.
const bookmark_name = computed(() =>
    buildSignatureBookmark({
        signature,
        currentSystem: {
            alias: selected_map_solarsystem.alias,
            class: selected_map_solarsystem.solarsystem.class,
            combatHome: is_combat_home.value,
            combatColor: map_system.value?.combat_color ?? null,
        },
        connectionTarget: selected_connection.value?.target ?? null,
        aliases: chainAliases(map_solarsystems.value, map_system.value),
        formats: page.props.map,
        detectReturn: true,
        plannedAlias: planned_alias ?? claim_alias,
    }),
);

// ---- Arming (patch 13) -----------------------------------------------------

const can_arm = computed(() => isWormhole.value && !selected_connection.value && can_write.value);
const armed_by_me = computed(() => Boolean(signature.armed_by_user_id) && signature.armed_by_user_id === user_id);
const armed_label = computed(() => {
    if (!signature.armed_by_user_id || selected_connection.value) return null;
    return armed_by_me.value ? 'armed' : `armed · ${signature.armed_by_name ?? '?'}`;
});

function copyBookmark() {
    navigator.clipboard.writeText(bookmark_name.value);

    // Copying an unjumped hole's bookmark locks its number, so it never shifts
    // under a bookmark someone has saved in game.
    // In a combat chain a hole still in limbo claims the next free number.
    const lock = planned_alias ?? claim_alias;
    if (isWormhole.value && !signature.alias && !signature.map_connection_id && lock && can_write.value) {
        handleChange({ alias: lock });
    }

    toast.success('Copied bookmark to clipboard', { description: visibleBookmarkName(bookmark_name.value) });
}
</script>

<template>
    <div
        class="flex items-center gap-2 border-b border-border/30 px-3 hover:bg-muted/30 data-deleted:bg-red-500/10 data-new:bg-green-500/10 data-updated:bg-amber-500/15"
        :class="map_user_settings.compact_signature_list ? 'py-0.5' : 'py-1.5'"
        :data-deleted="Data(is_deleted)"
        :data-new="Data(is_new)"
        :data-updated="Data(is_updated)"
        :title="is_deleted ? 'Not in your last paste (ignored in game, or gone). It keeps its number until you delete it.' : undefined"
    >
        <!-- Signature ID -->
        <div class="w-16 shrink-0">
            <input
                v-if="editingId"
                ref="idInputRef"
                :value="signature_id"
                @input="handleIdInput"
                @blur="saveId"
                @keydown.enter="saveId"
                @keydown.escape="cancelEditId"
                class="w-full rounded border border-border/50 bg-background/50 px-1.5 font-mono text-xs uppercase focus:border-primary focus:outline-none"
                :class="map_user_settings.compact_signature_list ? 'h-5' : 'h-6'"
                maxlength="7"
                placeholder="XXX-XXX"
            />
            <button
                v-else
                class="flex items-center font-mono text-xs hover:text-amber-400"
                :class="[can_write ? 'cursor-pointer' : 'cursor-default', map_user_settings.compact_signature_list ? 'h-5' : 'h-6']"
                @click="startEditId"
            >
                {{ signature.signature_id || '---' }}
            </button>
        </div>

        <!-- Category -->
        <div class="w-24 shrink-0">
            <Select :model-value="signature.signature_category_id" @update:modelValue="handleCategoryChange" :disabled="!can_write">
                <SelectTrigger class="w-full text-xs" :class="map_user_settings.compact_signature_list ? '!h-5 !py-0' : ''">
                    <SelectValue placeholder="Category">
                        <span class="flex items-center gap-1">
                            <component
                                :is="categoryIcon[signature.signature_category?.name ?? '']"
                                class="size-3 shrink-0"
                                :class="categoryColor[signature.signature_category?.name ?? '']"
                            />
                            {{ getCategoryAbbrev(signature.signature_category?.name) }}
                        </span>
                    </SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="category in signatureCategories" :key="category.id" :value="category.id" class="text-xs">
                        <span class="flex items-center gap-1.5">
                            <component :is="categoryIcon[category.name]" class="size-3 shrink-0" :class="categoryColor[category.name]" />
                            {{ category.name }}
                        </span>
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Type / Wormhole Info: capped for wormhole rows so the connection column gets the extra room. -->
        <div class="min-w-0 flex-1" :class="{ 'max-w-44': isWormhole }">
            <WormholeTypeInput
                v-if="isWormhole"
                :model-value="signature.signature_type_id"
                @update:model-value="handleTypeChange"
                :can_write="can_write"
                :wormhole_options="sortedAvailableTypes"
                :current_class="current_class"
                :static_signatures="static_signatures"
            />
            <SignatureTypeInput
                v-else
                :model-value="signature.signature_type_id"
                @update:model-value="handleTypeChange"
                :can_write="can_write"
                :options="sortedAvailableTypes"
                :category="signature.signature_category?.name"
                :raw-type-name="signature.raw_type_name"
            />
        </div>

        <!-- Connection (only for wormholes) -->
        <div v-if="isWormhole" class="min-w-0 flex-1">
            <MapConnectionInput
                :type="signature.signature_type"
                :selected="selected_connection"
                :unconnected_connections="unconnected_connections"
                :connected_connections="connected_connections"
                :model-value="signature.map_connection_id"
                :disabled="!can_write"
                @update:model-value="handleMapConnectionChange"
            />
        </div>

        <!-- Name: the number this hole has or gets, or where it leads -->
        <div class="flex w-16 shrink-0 items-center gap-1 truncate font-mono text-xs" :title="armed_label ? `${name_title ?? ''} · ${armed_by_me ? 'armed by you: your next jump' : `armed by ${signature.armed_by_name ?? 'someone'}`}` : name_title">
            <span :class="name_class">{{ name_label }}</span>
            <span v-if="armed_label" class="truncate rounded bg-red-500/20 px-1 font-sans text-[9px] leading-tight text-red-400">{{ armed_label }}</span>
        </div>

        <!-- Actions -->
        <div class="flex w-14 shrink-0 items-center justify-end gap-1">
            <Button v-if="isWormhole" variant="ghost" size="icon" class="size-6 text-muted-foreground hover:text-foreground" @click="copyBookmark">
                <Copy class="size-3.5" />
            </Button>

            <DropdownMenu v-if="can_write">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground hover:text-foreground"
                        :class="map_user_settings.compact_signature_list ? 'size-5' : 'size-6'"
                    >
                        <MoreVertical class="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-44">
                    <template v-if="isWormhole">
                        <!-- Mass Status Options -->
                        <DropdownMenuRadioGroup :model-value="signature.mass_status || 'unknown'" @update:model-value="handleMassStatusChange">
                            <DropdownMenuRadioItem value="fresh" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-neutral-500" />
                                    Fresh Mass
                                </span>
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="reduced" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-amber-500" />
                                    Reduced Mass
                                </span>
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="critical" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-red-500" />
                                    Critical Mass
                                </span>
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>

                        <DropdownMenuSeparator />

                        <!-- Lifetime Options -->
                        <DropdownMenuRadioGroup :model-value="signature.lifetime" @update:model-value="handleLifetimeChange">
                            <DropdownMenuRadioItem value="healthy" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-neutral-500" />
                                    Healthy
                                </span>
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="eol" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-purple-500" />
                                    End of Life
                                </span>
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="critical" class="text-xs">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2 rounded-full bg-red-500" />
                                    Critical
                                </span>
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>

                        <DropdownMenuSeparator />

                        <!-- Chain numbering -->
                        <DropdownMenuItem :disabled="is_k162 || (!signature.is_static && static_taken_by_other)" @select.prevent="handleToggleStatic" class="text-xs">
                            <span class="mr-2 inline-flex size-3.5 items-center justify-center font-mono text-[10px] font-bold">S</span>
                            Static
                            <Check v-if="signature.is_static" class="ml-auto size-3.5" />
                        </DropdownMenuItem>
                        <DropdownMenuItem :disabled="is_k162" @select.prevent="handleToggleWandering" class="text-xs">
                            <span class="mr-2 inline-flex size-3.5 items-center justify-center font-mono text-[10px] font-bold">W</span>
                            Wandering
                            <Check v-if="signature.is_wandering" class="ml-auto size-3.5" />
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="handleSetNumber" class="text-xs">
                            <span class="mr-2 inline-flex size-3.5 items-center justify-center font-mono text-[10px] font-bold">#</span>
                            Set number…
                            <span class="ml-auto font-mono text-muted-foreground">{{ signature.alias ?? planned_alias ?? '' }}</span>
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <!-- Arming (patch 13) -->
                        <template v-if="can_arm">
                            <DropdownMenuItem v-if="!signature.armed_by_user_id || armed_by_me" class="text-xs" @select="emit('arm', null, false)">
                                <Crosshair class="mr-2 size-3.5 text-red-400" />
                                {{ armed_by_me ? 'Re-arm (copy again)' : 'Arm (next jump)' }}
                            </DropdownMenuItem>
                            <DropdownMenuSub v-if="arm_as.length && (!signature.armed_by_user_id || armed_by_me)">
                                <DropdownMenuSubTrigger class="text-xs">
                                    <Crosshair class="mr-2 size-3.5 text-red-400" />
                                    Arm as…
                                </DropdownMenuSubTrigger>
                                <DropdownMenuSubContent class="w-48">
                                    <DropdownMenuItem
                                        v-for="option in arm_as"
                                        :key="option.alias"
                                        :disabled="option.state === 'taken' || option.state === 'mine'"
                                        class="text-xs"
                                        @select="emit('arm', option.alias, option.state === 'swap')"
                                    >
                                        <span class="font-mono font-bold">{{ displayAlias(option.alias) }}</span>
                                        <span class="ml-auto text-muted-foreground">
                                            {{ option.state === 'swap' ? `swap with ${option.holder}` : option.state === 'mine' ? 'this hole' : option.holder ?? 'free' }}
                                        </span>
                                    </DropdownMenuItem>
                                </DropdownMenuSubContent>
                            </DropdownMenuSub>
                            <DropdownMenuItem v-if="armed_by_me" class="text-xs" @select="emit('disarm')">
                                <Crosshair class="mr-2 size-3.5 text-muted-foreground" />
                                Disarm
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                        </template>

                        <template v-if="selected_connection">
                            <DropdownMenuItem @select.prevent="handleTogglePreserveMass" class="text-xs">
                                <Heart class="mr-2 size-3.5" />
                                Preserve mass
                                <Check v-if="selected_connection.preserve_mass" class="ml-auto size-3.5" />
                            </DropdownMenuItem>

                            <DropdownMenuSeparator />
                        </template>
                    </template>

                    <!-- Delete Option -->
                    <DropdownMenuItem @click="handleDelete" class="text-xs text-destructive focus:text-destructive">
                        <TrashIcon class="mr-2 size-3.5" />
                        Delete Signature
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- Age -->
        <div class="w-10 shrink-0 text-right">
            <SignatureTimeDetails :category="signature.signature_category?.name" :selected_connection="selected_connection" :signature="signature" />
        </div>

        <!-- Already bookmarked hole is the static: rename or keep? -->
        <StaticRenameDialog
            v-if="rename_open"
            v-model:open="rename_open"
            :signature-label="signature.signature_id ?? 'This signature'"
            :from-alias="displayAlias(rename_from_alias) || 'its number'"
            :to-alias="displayAlias(static_slot)"
            :changes="rename_changes"
            :beyond="rename_beyond"
            :countdown-seconds="popup_seconds"
            @choose="handleRenameChoice"
        />

        <!-- Any other rename of a name used in game (patch 14) -->
        <StaticRenameDialog
            v-if="rename_ask"
            v-model:open="rename_ask_open"
            :signature-label="signature.signature_id ?? 'This signature'"
            :from-alias="displayAlias(rename_ask.from)"
            :to-alias="displayAlias(rename_ask.to)"
            :changes="rename_ask_changes"
            :beyond="rename_ask_beyond"
            :title="rename_ask.title"
            :description="rename_ask.description"
            :keep-label="rename_ask.keepLabel"
            :rename-label="rename_ask.renameLabel"
            :countdown-seconds="popup_seconds"
            @choose="handleRenameAsk"
        />

        <!-- Static, wandering or unknown? -->
        <Dialog :open="static_choice_open" @update:open="handleStaticChoiceOpenChange">
            <DialogScrollContent class="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{{ pending_type_name }} — which is it?</DialogTitle>
                    <DialogDescription>
                        {{ signature.signature_id ?? 'This signature' }} has this system's static type. Static takes {{ static_slot }}; wandering is another hole of the same
                        type.
                    </DialogDescription>
                </DialogHeader>
                <CountdownBar class="-mx-6" :remaining="static_choice_remaining" :fraction="static_choice_fraction" action="Unknown" />
                <DialogFooter class="gap-2 sm:justify-start">
                    <Button autofocus @click="chooseStaticKind('unknown')">Unknown</Button>
                    <Button variant="outline" :disabled="static_taken_by_other" @click="chooseStaticKind('static')">Static</Button>
                    <Button variant="outline" @click="chooseStaticKind('wandering')">Wandering</Button>
                </DialogFooter>
                <p v-if="static_taken_by_other" class="text-xs text-muted-foreground">Another signature in this system is already the static.</p>
            </DialogScrollContent>
        </Dialog>
    </div>
</template>
