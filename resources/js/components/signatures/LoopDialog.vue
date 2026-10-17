<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { COMBAT_POPUP_SECONDS, useCombat } from '@/composables/combat/useCombat';
import { usePopupCountdown } from '@/composables/combat/usePopupCountdown';
import { closeLoop, loopPrompt } from '@/composables/signatures/loopPrompt';
import { displayAlias, splitStamp } from '@/lib/alias';
import { isFrigateOnly } from '@/lib/bookmark';
import { signatureToast as toast } from '@/lib/signatureToast';
import { changeNames } from '@/map/actions/names';
import { mappedBelow } from '@/map/holeBookmark';
import { useMapStore } from '@/map/store/mapStore';
import { Zap } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Patch 39: the loop popup. You jumped into a system already on the map through a hole with
 * its own number: which name does the system keep? Keep is the default (and the answer after
 * 60 s in Rage Scanning); the other name floats in a violet tag above the card.
 */
const store = useMapStore();
const { is_combat } = useCombat();

const open = computed({
    get: () => loopPrompt.value !== null,
    set: (value: boolean) => {
        if (!value) keep();
    },
});

const prompt = computed(() => loopPrompt.value);
const system = computed(() => (prompt.value ? (store.systems.get(prompt.value.systemId) ?? null) : null));
const origin = computed(() => (prompt.value ? (store.systems.get(prompt.value.originId) ?? null) : null));
const scheme = computed(() => store.meta.value?.bookmark_alias_scheme);
const main = computed(() => displayAlias(prompt.value?.main, scheme.value));
const hole = computed(() => displayAlias(prompt.value?.holeName, scheme.value));

/** Systems already mapped beyond the main name, and numbers bookmarked on its unjumped holes. */
const beyond = computed(() => (system.value && prompt.value ? mappedBelow(store, system.value, prompt.value.main) : []));
const bookmarked = computed(() => (system.value?.pending_holes ?? []).map((candidate) => candidate.alias).filter((alias): alias is string => Boolean(alias)));

/** The jumped hole, as the pipe knows it now. */
const jumped = computed(() => {
    const id = prompt.value?.signatureId;
    for (const connection of store.connections.values()) {
        const signature = (connection.signatures ?? []).find((candidate) => candidate.id === id);
        if (signature) return { signature, connection };
    }
    return null;
});
const frigate = computed(() =>
    jumped.value ? isFrigateOnly({ wormholeName: jumped.value.signature.wormhole?.name ?? null, shipSize: jumped.value.connection.ship_size ?? null }) : false,
);

/** The shorter name (closer to home) is the better one, unless the longer one is already in use beyond it. */
const closer = computed(() => {
    if (!prompt.value) return null;
    const mainLength = splitStamp(prompt.value.main).base.length;
    const holeLength = splitStamp(prompt.value.holeName).base.length;
    if (holeLength >= mainLength || frigate.value || beyond.value.length || bookmarked.value.length) return 'keep';
    return 'switch';
});

const { remaining } = usePopupCountdown(
    open,
    () => (is_combat.value ? COMBAT_POPUP_SECONDS : null),
    () => keep(),
);

function keep(): void {
    if (!prompt.value) return;
    closeLoop();
}

function switchName(): void {
    const current = prompt.value;
    if (!current) return;
    closeLoop();
    changeNames(current.systemId, { action: 'switch', signature_id: current.signatureId }, `Switch ${main.value} to ${hole.value}`, () =>
        toast.success(`${main.value} is now ${hole.value}`, { description: `${main.value} floats above the card while a hole still uses it.` }),
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogScrollContent v-if="prompt" class="max-w-md gap-0 overflow-hidden p-0">
            <DialogHeader class="gap-1.5 border-b border-border/50 bg-muted/30 px-6 py-4 text-left">
                <DialogTitle class="flex items-center gap-2"><Zap class="size-4 text-violet-400" /> A loop into {{ main }}</DialogTitle>
                <DialogDescription>
                    Hole <span class="font-mono font-semibold">{{ hole }}</span>
                    <template v-if="origin"> from {{ displayAlias(origin.alias, scheme) || origin.solarsystem.name }}</template>
                    leads into <span class="font-mono font-semibold">{{ main }}</span>, which is already on the map. Which name does it keep? The other one
                    floats above its card.
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-2 px-6 py-4 text-xs">
                <p v-if="beyond.length" class="text-muted-foreground">
                    Mapped beyond {{ main }}: {{ beyond.slice(0, 4).map((name) => displayAlias(name, scheme)).join(', ') }}{{ beyond.length > 4 ? '…' : '' }}. They keep their names
                    either way.
                </p>
                <p v-if="bookmarked.length" class="text-muted-foreground">
                    Bookmarked holes in {{ main }}: {{ bookmarked.slice(0, 4).map((name) => displayAlias(name, scheme)).join(', ') }}{{ bookmarked.length > 4 ? '…' : '' }}.
                </p>
                <p v-if="frigate" class="rounded-md bg-amber-500/15 px-3 py-2 text-amber-700 dark:text-amber-300">
                    {{ hole }} is a frigate hole: only frigates can use it, so the shorter name through it doesn't help much.
                </p>
                <p v-else-if="closer === 'switch'" class="text-muted-foreground">{{ hole }} is closer to home and nothing is bookmarked beyond {{ main }} yet.</p>
                <p class="text-muted-foreground">
                    In game the way back through {{ hole }} is <span class="font-mono">*@{{ hole }}</span>; bookmarks into it say
                    <span class="font-mono">loop=</span> the other name.
                </p>
            </div>
            <DialogFooter class="gap-2 border-t border-border/50 bg-muted/30 px-6 py-3 sm:justify-between">
                <Button variant="outline" :title="`Switch: the system becomes ${hole}; ${main} floats`" @click="switchName">
                    Switch to {{ hole }}<span v-if="closer === 'switch'" class="ml-1 text-[10px] text-emerald-500">closer</span>
                </Button>
                <Button autofocus :title="`Keep: the system stays ${main}; ${hole} floats`" @click="keep">
                    Keep {{ main }}<span class="ml-1 text-[10px] opacity-70">({{ hole }} floats)</span>
                    <span v-if="remaining !== null" class="ml-1 tabular-nums opacity-70">{{ remaining }}s</span>
                </Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
