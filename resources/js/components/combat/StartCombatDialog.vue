<script setup lang="ts">
import CountdownBar from '@/components/combat/CountdownBar.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { COMBAT_POPUP_SECONDS } from '@/composables/combat/useCombat';
import { usePopupCountdown } from '@/composables/combat/usePopupCountdown';
import { combatColorHex, combatColorLabel } from '@/lib/combat';
import { aliasedSolarsystemLabel } from '@/lib/solarsystem';
import type { TCombatStart } from '@/map/actions/combat';
import type { TMapSolarsystem } from '@/pages/maps';
import { computed } from 'vue';

/**
 * Turning combat mode on: start a new chain in the system you are in, join a
 * chain already on the map, or just use combat speed. Answers itself after 60 s.
 */
const props = defineProps<{
    /** The map system you are in, if it is on the map. */
    currentSystem: TMapSolarsystem | null;
    /** You are in the map's home (Daisy), which can't be a combat home. */
    currentIsHome: boolean;
    /** The combat homes on the map, one per chain. */
    combatHomes: TMapSolarsystem[];
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    choose: [choice: TCombatStart];
}>();

const canStartHere = computed(() => props.currentSystem !== null && !props.currentIsHome);

/** The chain the system you are in already belongs to (not as its home). */
const currentChainColor = computed(() => {
    const system = props.currentSystem;
    return system && system.combat_color && !system.combat_home ? system.combat_color : null;
});

const chains = computed(() =>
    props.combatHomes
        .filter((home) => home.combat_color)
        .map((home) => ({
            color: home.combat_color as string,
            label: combatColorLabel(home.combat_color) ?? home.combat_color ?? '',
            hex: combatColorHex(home.combat_color) ?? '#888888',
            home: aliasedSolarsystemLabel(home.alias, home.solarsystem.name),
            active: Boolean(home.combat_active),
        }))
        .toSorted((a, b) => (a.color === currentChainColor.value ? -1 : b.color === currentChainColor.value ? 1 : 0)),
);

const currentLabel = computed(() => (props.currentSystem ? aliasedSolarsystemLabel(props.currentSystem.alias, props.currentSystem.solarsystem.name) : null));

/** What the countdown picks: join the chain you're standing in, else start one here, else combat speed. */
const defaultChoice = computed<TCombatStart>(() => {
    if (currentChainColor.value) return { mode: 'join', color: currentChainColor.value };
    if (canStartHere.value && props.currentSystem) return { mode: 'start', map_solarsystem_id: props.currentSystem.id };
    return { mode: 'solo' };
});

const defaultLabel = computed(() => {
    const choice = defaultChoice.value;
    if (choice.mode === 'join') return `Join ${combatColorLabel(choice.color) ?? choice.color}`;
    if (choice.mode === 'start') return 'Yes';
    return 'Combat speed';
});

function choose(choice: TCombatStart): void {
    if (!open.value) return;
    open.value = false;
    emit('choose', choice);
}

const { remaining, fraction } = usePopupCountdown(
    open,
    () => COMBAT_POPUP_SECONDS,
    () => choose(defaultChoice.value),
);

function startHere(): void {
    if (props.currentSystem) choose({ mode: 'start', map_solarsystem_id: props.currentSystem.id });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogScrollContent class="max-w-md gap-0 overflow-hidden p-0">
            <DialogHeader class="gap-1.5 border-b border-border/50 bg-muted/30 px-6 py-4 text-left">
                <DialogTitle>⚔ Start a new chain here?</DialogTitle>
                <DialogDescription>
                    <template v-if="canStartHere">
                        <strong>{{ currentLabel }}</strong> becomes a combat home. Its holes are numbered 1, 2, 3 (static 0) and the chain gets its own color.
                    </template>
                    <template v-else-if="currentIsHome">You're in the home system, which can't be a combat home. Join a chain or use combat speed.</template>
                    <template v-else>Your location isn't on the map. Join a chain or use combat speed.</template>
                </DialogDescription>
            </DialogHeader>

            <CountdownBar :remaining="remaining" :fraction="fraction" :action="defaultLabel" />

            <div class="grid gap-2 px-6 py-4">
                <Button v-if="canStartHere" @click="startHere">Yes — start a new chain here</Button>
                <Button
                    v-for="chain in chains"
                    :key="chain.color"
                    variant="outline"
                    class="justify-start gap-2"
                    @click="choose({ mode: 'join', color: chain.color })"
                >
                    <span class="inline-block size-2.5 rounded-full" :style="{ backgroundColor: chain.hex }" />
                    Join the {{ chain.label }} chain
                    <span class="ml-auto truncate text-xs text-muted-foreground">from {{ chain.home }}{{ chain.active ? '' : ' · idle' }}</span>
                </Button>
            </div>

            <DialogFooter class="border-t border-border/50 bg-muted/30 px-6 py-3 sm:justify-between">
                <Button variant="ghost" @click="open = false">Cancel</Button>
                <Button variant="outline" @click="choose({ mode: 'solo' })">No — combat speed only</Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
