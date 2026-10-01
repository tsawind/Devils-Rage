import { recordJump } from '@/composables/signatures/recentJump';
import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import { useMapIgnoredSystems } from '@/composables/useMapIgnoredSystems';
import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { useShowMap } from '@/composables/useShowMap';
import { useStaticData } from '@/composables/useStaticData';
import { useTrackingSystems } from '@/composables/useTrackingSystems';
import useUser from '@/composables/useUser';
import { aliasTargetKind, displayAlias, staticSlotAlias, suggestAlias } from '@/lib/alias';
import { isWormholeSignature, planAliasesForSystem } from '@/lib/aliasPlan';
import { jumpMatchesArm, myArmedHole } from '@/lib/arming';
import { formatBookmarkName, visibleBookmarkName } from '@/lib/bookmark';
import { chainAliases } from '@/lib/combat';
import { groupSignatureOptions } from '@/lib/signatureCompatibility';
import { isWormholeSystem } from '@/lib/solarsystem';
import { disarmSignature } from '@/map/actions/arm';
import { createTracking, updateMapUserSettings, useMapSolarsystems } from '@/map/api';
import { show } from '@/routes/maps';
import { TLifetimeStatus, TMassStatus, TShipSize, TSignature } from '@/types/models';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

export function useTracking() {
    const character = useActiveMapCharacter();
    const map_user_settings = useMapUserSettings();
    const { isIgnored } = useMapIgnoredSystems();
    const page = useShowMap();
    const { staticData } = useStaticData();
    const { map_solarsystems } = useMapSolarsystems();
    const user = useUser();

    const is_tracking = computed(() => map_user_settings.value?.is_tracking && character.value && map_user_settings.value?.tracking_allowed);
    const is_tracking_allowed = computed(() => map_user_settings.value.tracking_allowed);
    const can_track = computed(() => character.value && map_user_settings.value.tracking_allowed);

    const { origin_map_solarsystem, target_solarsystem, update } = useTrackingSystems();

    const show_signature_modal = ref(false);
    // All of the origin's signatures; the dialog demotes the ones that cannot
    // lead to the target instead of hiding them.
    const signatures = computed(() => origin_map_solarsystem.value?.signatures?.toSorted(sortSignatures));
    const possible_signatures = computed(() => groupSignatureOptions(signatures.value ?? [], target_solarsystem.value?.class).likely);
    const existing_map_solarsystem = computed(() => map_solarsystems.value.find((s) => s.solarsystem_id === target_solarsystem.value?.id));
    const existing_connection = computed(() => {
        if (!existing_map_solarsystem.value) return null;
        return (
            signatures.value?.find(
                (s) =>
                    s.map_connection?.to_map_solarsystem_id === existing_map_solarsystem.value?.id ||
                    s.map_connection?.from_map_solarsystem_id === existing_map_solarsystem.value?.id,
            ) || null
        );
    });

    // The origin as the map knows it: its combat chain, and whether it is a combat home.
    const origin_map_system = computed(() => map_solarsystems.value.find((s) => s.id === origin_map_solarsystem.value?.id) ?? null);
    const origin_is_combat_home = computed(() => Boolean(origin_map_system.value?.combat_home));

    // Numbers already used in the origin's chain (each combat chain numbers from 1 on its own).
    const known_aliases = computed(() => chainAliases(map_solarsystems.value, origin_map_system.value));

    // The origin's reserved static slot and the hole already marked as its static.
    const static_slot_alias = computed(() =>
        staticSlotAlias(origin_map_solarsystem.value?.alias, page.props.map.bookmark_ignored_alias, origin_is_combat_home.value),
    );
    const static_owner_id = computed(() => signatures.value?.find((signature) => signature.is_static)?.id ?? null);
    // The slot the jump prompt moves a hole to when Static is ticked. Combat chains never
    // switch a hole to 0 on their own (patch 12): the hole keeps its jump-order number.
    const prompt_static_slot_alias = computed(() => (origin_map_system.value?.combat_color ? null : static_slot_alias.value));

    // The alias each unjumped wormhole in the origin has reserved (statics
    // first), so the jump dialog can prefill the one for the chosen signature.
    // Empty when the target already carries an alias or suggestions are off.
    const planned_aliases = computed<Map<number, string>>(() => {
        if (existing_map_solarsystem.value?.alias || !map_user_settings.value.suggest_alias_enabled) return new Map();

        const origin = origin_map_solarsystem.value;
        return planAliasesForSystem({
            signatures: signatures.value,
            system: origin
                ? {
                      alias: origin.alias,
                      solarsystem: origin.solarsystem,
                      combat_home: origin_is_combat_home.value,
                      combat_color: origin_map_system.value?.combat_color ?? null,
                  }
                : null,
            aliases: known_aliases.value,
            formats: page.props.map,
        });
    });

    // Pre-fill the signature dialog's alias field. An alias the target already
    // carries on the map wins; otherwise we guess the next chain alias.
    const suggested_alias = computed(() => {
        if (existing_map_solarsystem.value?.alias) {
            return existing_map_solarsystem.value.alias;
        }

        if (!map_user_settings.value.suggest_alias_enabled) return null;

        const origin = origin_map_solarsystem.value;
        const target = target_solarsystem.value;
        if (!origin || !target) return null;

        const targetIsWormhole = isWormholeSystem(target);

        return suggestAlias({
            parentAlias: origin.alias,
            targetIsWormhole,
            originIsWormhole: isWormholeSystem(origin.solarsystem),
            // Skip slots already reserved by other unjumped holes, and the static's slot.
            aliases: [...known_aliases.value, ...planned_aliases.value.values(), static_slot_alias.value],
            scheme: page.props.map.bookmark_alias_scheme,
            targetKind: aliasTargetKind(targetIsWormhole, target.class),
            ignoredAlias: page.props.map.bookmark_ignored_alias,
            combatHome: origin_is_combat_home.value,
        });
    });

    watch(
        () => [character.value?.id, character.value?.status?.solarsystem_id] as const,
        ([new_character_id, new_solarsystem_id], [old_character_id, old_solarsystem_id]) => {
            if (!map_user_settings.value.is_tracking) return;
            if (!new_solarsystem_id || !old_solarsystem_id) return;
            if (new_solarsystem_id === old_solarsystem_id) return;
            // Only a single character moving between systems is a real jump. When
            // the active character is switched the watched system id also changes,
            // but that must not create a connection between the two characters' systems.
            if (new_character_id !== old_character_id) return;

            recordJump(old_solarsystem_id, new_solarsystem_id);
            handleSolarsystemJump(old_solarsystem_id, new_solarsystem_id);
        },
    );

    // Follow the pilot: select the system the character jumped into, so the
    // signature panel and details follow it.
    //
    // Always called once the system is known to be on the map — either it
    // already was, or the tracking request that added it has come back. That
    // ordering matters: the requests a jump fires off (the tracking lookup, the
    // tracking POST) carry the pre-jump URL, and whichever lands last decides
    // what the page URL is. Selecting after them, rather than racing them, is
    // what keeps the selection from being reverted.
    function followInto(solarsystem_id: number) {
        if (!map_user_settings.value.follow_character_enabled) return;

        router.visit(show(page.props.map.slug, { mergeQuery: { solarsystem_id } }).url, {
            preserveScroll: true,
            preserveState: true,
            only: ['map', 'selected_map_solarsystem', 'map_navigation', 'map_characters', 'eve_scout_connections', 'threat_analysis'],
        });
    }

    function handleSolarsystemJump(old_solarsystem_id: number | null, new_solarsystem_id: number) {
        if (isIgnored(new_solarsystem_id)) return;
        const old_map_solarsystem = map_solarsystems.value.find((s) => s.solarsystem_id === old_solarsystem_id);
        if (!old_map_solarsystem) return;
        if (old_map_solarsystem.solarsystem_id === new_solarsystem_id) return;
        update(old_map_solarsystem.solarsystem_id, new_solarsystem_id, performJump);
    }

    function isGateConnected(origin_solarsystem_id: number | null | undefined, target_solarsystem_id: number | null | undefined): boolean {
        if (!origin_solarsystem_id || !target_solarsystem_id) return false;
        return staticData.value?.connections[origin_solarsystem_id]?.includes(target_solarsystem_id) ?? false;
    }

    function sortSignatures(a: TSignature, b: TSignature) {
        if (!a.signature_id || !b.signature_id) return 0;
        return a.signature_id.localeCompare(b.signature_id);
    }

    function performJump() {
        const target_solarsystem_id = target_solarsystem.value!.id;

        // Already connected: the system is on the map, nothing to wait for.
        if (existing_connection.value?.map_connection_id) {
            followInto(target_solarsystem_id);

            return;
        }

        const gate_connected = isGateConnected(origin_map_solarsystem.value?.solarsystem_id, target_solarsystem.value?.id);

        // Patch 13: you armed a hole in the system you left: that's the one you jumped.
        if (!gate_connected && performArmedJump()) {
            return;
        }

        // Skip the prompt when it is certain which hole was jumped (everyone, not just combat mode).
        if (!gate_connected && performCertainJump()) {
            return;
        }

        if (gate_connected || !possible_signatures.value.length || !map_user_settings.value.prompt_for_signature_enabled) {
            return createTracking(origin_map_solarsystem.value!.id, target_solarsystem_id, {}, () => followInto(target_solarsystem_id));
        }

        // The dialog defers the tracking request until the scout picks a
        // signature, so following waits for that path instead.
        show_signature_modal.value = true;
    }

    /**
     * Patch 13: the hole you armed in the system you left is the one you
     * jumped: link it with no prompt (its number is the one it was armed as).
     * When you clearly jumped another hole (its class doesn't match where you
     * landed), the arm is released and the usual rules decide.
     */
    function performArmedJump(): boolean {
        const armed = myArmedHole(signatures.value, user.value?.id ?? null);
        if (!armed) return false;

        const label = armed.signature_id?.slice(0, 3) ?? 'a hole';
        if (
            !jumpMatchesArm({
                armedTargetClass: armed.signature_type?.target_class ?? null,
                destinationClass: target_solarsystem.value?.class ?? null,
                connectedByOtherHole: false,
            })
        ) {
            disarmSignature(armed.id);
            toast.warning(`You armed ${label} but jumped another hole`, {
                description: `${label} released. Check the bookmark you made in game.`,
                duration: 15_000,
            });
            return false;
        }

        const alias = existing_map_solarsystem.value?.alias ? suggested_alias.value : (armed.alias ?? planned_aliases.value.get(armed.id) ?? suggested_alias.value);
        toast.info(`Jumped armed ${label}${alias ? ` → ${displayAlias(alias)}` : ''}`, { description: 'Linked to the hole you armed, no prompt.' });
        handleSelectSignature({
            signatureId: armed.id,
            alias,
            lifetime: armed.lifetime ?? 'healthy',
            massStatus: armed.mass_status ?? 'fresh',
            shipSize: armed.ship_size ?? null,
            isStatic: null,
            isWandering: null,
        });
        return true;
    }

    /**
     * When it is certain which hole was jumped, record the jump without asking.
     * No hole left that could lead here, and nothing unscanned, means a hole
     * that was never scanned (new system, next number, no signature); exactly
     * one unjumped wormhole that fits (and nothing unscanned) means that one.
     * Returns false when there is any other choice, so the prompt shows.
     */
    function performCertainJump(): boolean {
        const candidates = possible_signatures.value;
        const unidentified = candidates.filter((signature) => !isWormholeSignature(signature));
        const wormholes = candidates.filter((signature) => isWormholeSignature(signature));

        if (candidates.length === 0) {
            const alias = suggested_alias.value;
            if (alias) toast.info(`New system ${displayAlias(alias)}`, { description: 'No scanned hole fits, so no signature was linked.' });
            handleSelectSignature({
                signatureId: null,
                alias: suggested_alias.value,
                lifetime: 'healthy',
                massStatus: 'fresh',
                shipSize: null,
                isStatic: null,
                isWandering: null,
            });
            return true;
        }

        if (wormholes.length === 1 && unidentified.length === 0) {
            const signature = wormholes[0];
            const alias = existing_map_solarsystem.value?.alias ? suggested_alias.value : (planned_aliases.value.get(signature.id) ?? suggested_alias.value);
            toast.info(`Jumped ${signature.signature_id ?? 'the only hole'}${alias ? ` → ${displayAlias(alias)}` : ''}`, {
                description: 'The only hole that fits, so no prompt.',
            });
            handleSelectSignature({
                signatureId: signature.id,
                alias,
                lifetime: signature.lifetime ?? 'healthy',
                massStatus: signature.mass_status ?? 'fresh',
                shipSize: signature.ship_size ?? null,
                isStatic: null,
                isWandering: null,
            });
            return true;
        }

        return false;
    }

    function handleToggle() {
        if (!map_user_settings.value.tracking_allowed) return;

        updateMapUserSettings(page.props.map.slug, {
            is_tracking: !map_user_settings.value.is_tracking,
        });
    }

    const follow_enabled = computed(() => map_user_settings.value.follow_character_enabled);

    function handleToggleFollow() {
        updateMapUserSettings(page.props.map.slug, {
            follow_character_enabled: !map_user_settings.value.follow_character_enabled,
        });
    }

    function handleSelectSignature(selection: {
        signatureId: number | null;
        alias: string | null;
        lifetime: TLifetimeStatus;
        massStatus: TMassStatus;
        shipSize: TShipSize | null;
        isStatic: boolean | null;
        isWandering: boolean | null;
    }) {
        show_signature_modal.value = false;
        if (!origin_map_solarsystem.value || !target_solarsystem.value) return;

        const target_solarsystem_id = target_solarsystem.value.id;

        copyConnectionBookmark(selection.signatureId, selection.alias);
        createTracking(
            origin_map_solarsystem.value.id,
            target_solarsystem_id,
            {
                signature_id: selection.signatureId,
                alias: selection.alias,
                lifetime: selection.lifetime,
                mass_status: selection.massStatus,
                ship_size: selection.shipSize,
                is_static: selection.isStatic,
                is_wandering: selection.isWandering,
            },
            () => followInto(target_solarsystem_id),
        );
    }

    // Copy the "way back" bookmark for the hole we just came through, named from
    // where we now stand: the origin system is the destination of that
    // bookmark, and the system we jumped into (its chosen alias) is `{here}`.
    // Jumping down-chain (from "1" into "12") gives the return name, e.g.
    // "  * 12"; jumping back up-chain (from "12" into "1") gives the forward
    // name of the hole we left, e.g. " 12", since that is the bookmark we need
    // on this side.
    function copyConnectionBookmark(signatureId: number | null, alias: string | null) {
        if (!map_user_settings.value.copy_bookmark_enabled) return;
        const origin = origin_map_solarsystem.value;
        if (!origin?.solarsystem || !target_solarsystem.value) return;

        const signature = signatures.value?.find((s) => s.id === signatureId) ?? null;
        const here = alias || existing_map_solarsystem.value?.alias || null;
        // The chain the system we jumped into belongs to; a new system joins the origin's chain.
        const here_color = existing_map_solarsystem.value
            ? (existing_map_solarsystem.value.combat_color ?? null)
            : (origin_map_system.value?.combat_color ?? null);

        const name = formatBookmarkName(
            {
                alias: origin.alias,
                occupier_alias: origin.occupier_alias,
                solarsystem: origin.solarsystem,
                combat_home: origin_is_combat_home.value,
                combat_color: origin_map_system.value?.combat_color ?? null,
            },
            {
                // The signature on this side of the hole isn't known yet.
                signatureId: null,
                shipSize: signature?.ship_size ?? null,
                massStatus: signature?.mass_status ?? null,
                lifetime: signature?.lifetime ?? 'healthy',
                wormholeCode: null,
            },
            page.props.map,
            here,
            here,
            target_solarsystem.value.class,
            here_color,
            Boolean(existing_map_solarsystem.value?.combat_home),
        );

        if (!name) return;

        navigator.clipboard.writeText(name);
        toast.success('Copied bookmark to clipboard', { description: visibleBookmarkName(name) });
    }

    return {
        toggle: handleToggle,
        toggle_follow: handleToggleFollow,
        follow_enabled,
        is_tracking,
        is_tracking_allowed,
        can_track,
        signatures,
        show_signature_modal,
        handleSelectSignature,
        origin_map_solarsystem,
        target_solarsystem,
        existing_map_solarsystem,
        suggested_alias,
        planned_aliases,
        static_slot_alias,
        prompt_static_slot_alias,
        static_owner_id,
    };
}
