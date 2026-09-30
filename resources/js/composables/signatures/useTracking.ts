import { recordJump } from '@/composables/signatures/recentJump';
import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import { useMapIgnoredSystems } from '@/composables/useMapIgnoredSystems';
import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { useShowMap } from '@/composables/useShowMap';
import { useStaticData } from '@/composables/useStaticData';
import { useTrackingSystems } from '@/composables/useTrackingSystems';
import { aliasTargetKind, staticSlotAlias, suggestAlias } from '@/lib/alias';
import { planAliasesForSystem } from '@/lib/aliasPlan';
import { formatBookmarkName, visibleBookmarkName } from '@/lib/bookmark';
import { groupSignatureOptions } from '@/lib/signatureCompatibility';
import { isWormholeSystem } from '@/lib/solarsystem';
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

    const known_aliases = computed(() => map_solarsystems.value.map((s) => s.alias).filter((alias): alias is string => Boolean(alias)));

    // The origin's reserved static slot and the hole already marked as its static.
    const static_slot_alias = computed(() => staticSlotAlias(origin_map_solarsystem.value?.alias, page.props.map.bookmark_ignored_alias));
    const static_owner_id = computed(() => signatures.value?.find((signature) => signature.is_static)?.id ?? null);

    // The alias each unjumped wormhole in the origin has reserved (statics
    // first), so the jump dialog can prefill the one for the chosen signature.
    // Empty when the target already carries an alias or suggestions are off.
    const planned_aliases = computed<Map<number, string>>(() => {
        if (existing_map_solarsystem.value?.alias || !map_user_settings.value.suggest_alias_enabled) return new Map();

        return planAliasesForSystem({
            signatures: signatures.value,
            system: origin_map_solarsystem.value,
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
            // Skip slots already reserved by other unjumped holes, and slot 1 (the static's).
            aliases: [...known_aliases.value, ...planned_aliases.value.values(), static_slot_alias.value],
            scheme: page.props.map.bookmark_alias_scheme,
            targetKind: aliasTargetKind(targetIsWormhole, target.class),
            ignoredAlias: page.props.map.bookmark_ignored_alias,
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
        if (gate_connected || !possible_signatures.value.length || !map_user_settings.value.prompt_for_signature_enabled) {
            return createTracking(origin_map_solarsystem.value!.id, target_solarsystem_id, {}, () => followInto(target_solarsystem_id));
        }

        // The dialog defers the tracking request until the scout picks a
        // signature, so following waits for that path instead.
        show_signature_modal.value = true;
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

        const name = formatBookmarkName(
            { alias: origin.alias, occupier_alias: origin.occupier_alias, solarsystem: origin.solarsystem },
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
        static_owner_id,
    };
}
