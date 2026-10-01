<script setup lang="ts">
import NearestHighsecButton from '@/components/combat/NearestHighsecButton.vue';
import StartCombatDialog from '@/components/combat/StartCombatDialog.vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useCombat } from '@/composables/combat/useCombat';
import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import usePermission from '@/composables/usePermission';
import { useShowMap } from '@/composables/useShowMap';
import { useStaticSolarsystem } from '@/composables/useStaticSolarsystems';
import { combatColorHex, combatColorLabel } from '@/lib/combat';
import { startCombat, stopCombat, type TCombatStart } from '@/map/actions/combat';
import { useMapSolarsystems } from '@/map/api';
import { Swords } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Combat mode toggle and Nearest highsec, at the top left of the signatures
 * panel (patch 12; they used to sit in the status bar).
 */
const page = useShowMap();
const character = useActiveMapCharacter();
const { canEdit } = usePermission();
const { map_solarsystems } = useMapSolarsystems();
const { is_combat, combat_color } = useCombat();

const show_combat_dialog = ref(false);

const location_id = computed(() => character.value?.status?.solarsystem_id ?? null);
const location = useStaticSolarsystem(() => location_id.value);

const current_map_solarsystem = computed(() => {
    const solarsystem_id = location_id.value;
    return solarsystem_id ? (map_solarsystems.value.find((system) => system.solarsystem_id === solarsystem_id) ?? null) : null;
});
const current_is_home = computed(() => location_id.value !== null && location_id.value === page.props.map.home_solarsystem_id);
/** Where you are when it isn't on the map yet: starting a chain there adds it. */
const off_map_location = computed(() =>
    !current_map_solarsystem.value && location_id.value && location.value ? { id: location_id.value, name: location.value.name } : null,
);
const combat_homes = computed(() => map_solarsystems.value.filter((system) => system.combat_home && system.combat_color));

const combat_label = computed(() => {
    const chain = combatColorLabel(combat_color.value);
    return chain ? `Rage Scanning · ${chain}` : 'Rage Scanning';
});
const combat_dot = computed(() => combatColorHex(combat_color.value));

function handleToggleCombat(): void {
    if (is_combat.value) {
        stopCombat(page.props.map.slug);
        return;
    }
    show_combat_dialog.value = true;
}

function handleChooseCombat(choice: TCombatStart): void {
    startCombat(page.props.map.slug, choice);
}
</script>

<template>
    <span v-if="canEdit" class="inline-flex items-center gap-1.5 align-middle font-sans tracking-normal normal-case">
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="flex items-center gap-1.5 rounded px-1.5 py-0.5 text-xs transition-colors"
                    :class="is_combat ? 'bg-red-500/20 text-red-400 hover:bg-red-500/30' : 'bg-muted text-muted-foreground hover:bg-muted/80'"
                    @click="handleToggleCombat"
                >
                    <Swords class="size-3.5" />
                    <span v-if="combat_dot" class="inline-block size-2 rounded-full" :style="{ backgroundColor: combat_dot }" />
                    <span>{{ combat_label }}</span>
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom" class="max-w-sm">
                <p class="text-xs font-medium">Rage Scanning · {{ is_combat ? 'On' : 'Off' }} (just for you)</p>
                <div class="mt-1 space-y-1.5 text-xs text-muted-foreground">
                    <p>
                        The mapper stops asking and keeps up with you. No jump prompt, popups answer themselves after 60 s, holes are numbered
                        1, 2, 3 in the order you jump them (no automatic 0), and your chain gets its own color and lane.
                    </p>
                    <p>
                        <span class="font-medium text-foreground">Scan, then arm:</span> paste only the signature of the hole you want to jump, or
                        press the crosshair next to its ID. That arms it: it takes the next number and its bookmark name is copied ("1 LIH C2").
                        After a full paste you pick the hole from a yellow list (armed at once only when there is one). Right-click a dashed system
                        → Arm works too.
                    </p>
                    <p>
                        <span class="font-medium text-foreground">In game:</span> bookmark the hole as the number (type "1", or paste the copied
                        name), jump. After the jump the way back is on your clipboard: paste it as the return bookmark. Your jump is linked to
                        the armed hole with no prompt.
                    </p>
                    <p>
                        <span class="font-medium text-foreground">Clipboard:</span> the signature list's copy = the full name, the way back after
                        each jump, Nearest highsec, Copy route.
                    </p>
                </div>
            </TooltipContent>
        </Tooltip>

        <NearestHighsecButton v-if="is_combat" :map="page.props.map" :from-solarsystem-id="location_id" />

        <StartCombatDialog
            v-model:open="show_combat_dialog"
            :current-system="current_map_solarsystem"
            :current-solarsystem="off_map_location"
            :current-is-home="current_is_home"
            :combat-homes="combat_homes"
            @choose="handleChooseCombat"
        />
    </span>
</template>
