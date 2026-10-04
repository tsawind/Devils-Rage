<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import usePermission from '@/composables/usePermission';
import { displayAlias } from '@/lib/alias';
import { isK162Frigate, k162RangeLabel } from '@/lib/k162';
import { formatMass } from '@/lib/massEstimate';
import { SHIP_SIZE_LETTERS } from '@/lib/shipSize';
import { openPlaceholderDetails } from '@/map/components/overlays/placeholderDetails';
import { copyPlaceholderBookmark } from '@/map/holeBookmark';
import { holeFacts } from '@/map/placeholderFacts';
import { useMapStore } from '@/map/store/mapStore';
import { ClipboardCopy } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Patch 21: the details of an unjumped hole, opened by clicking its dotted
 * pipe's pill: the signature, type, size (and how it is known), mass, life.
 */
const store = useMapStore();
const { canEdit } = usePermission();

const open = computed({
    get: () => openPlaceholderDetails.value !== null && placeholder.value !== null,
    set: (value: boolean) => {
        if (!value) openPlaceholderDetails.value = null;
    },
});

const placeholder = computed(() => {
    const id = openPlaceholderDetails.value?.nodeId;
    return id === undefined ? null : (store.placeholders.value.find((candidate) => candidate.nodeId === id) ?? null);
});

const reference = computed(() => {
    const at = openPlaceholderDetails.value;
    return at ? { getBoundingClientRect: () => new DOMRect(at.x, at.y, 0, 0) } : undefined;
});

const LIFE: Record<string, string> = { healthy: 'Fresh', eol: 'End of life', critical: 'End of life (critical)' };
const MASS: Record<string, string> = { fresh: 'Fresh', reduced: 'Reduced', critical: 'Critical' };

const details = computed(() => {
    const current = placeholder.value;
    const parent = current ? store.systems.get(current.parentId) : null;
    if (!current || !parent) return null;
    const hole = parent.pending_holes?.find((candidate) => candidate.id === current.signatureId) ?? null;
    const facts = holeFacts(current, parent);
    const range = facts.holeTypeInfo ? k162RangeLabel(facts.holeTypeInfo) : null;
    const type = isK162Frigate(facts.holeTypeInfo)
        ? 'K162 frigate'
        : current.wormhole
          ? `${current.wormhole.toUpperCase()}${range ? ` ${range}` : ''}`
          : facts.isK162
            ? `K162${range ? ` ${range}` : ''}`
            : 'Not known yet';
    let size: string;
    if (facts.size) size = `${facts.size.letter} (${facts.isK162 ? 'every hole that fits is this size' : 'from its type'})`;
    else if (facts.guess?.name) size = `≈ ${facts.guess.size ? SHIP_SIZE_LETTERS[facts.guess.size] : '?'} · probably ${facts.guess.name}`;
    else if (facts.guess?.size) size = `≈ ${SHIP_SIZE_LETTERS[facts.guess.size]} (guessed)`;
    else if (facts.guess) size = `≈ ${formatMass(facts.guess.total)} kg hole (guessed)`;
    else size = 'Not known';
    return {
        title: current.label || 'Unnamed hole',
        where: displayAlias(parent.alias) || parent.solarsystem.name,
        signature: hole?.signature_id ?? (current.expected ? 'Not scanned yet' : '—'),
        type,
        leadsTo: current.destination || current.detail || '?',
        size,
        mass: MASS[current.massStatus ?? 'fresh'] ?? 'Fresh',
        life: LIFE[current.lifetime ?? 'healthy'] ?? 'Fresh',
        static: current.isStatic || current.expected ? 'Static' : current.maybeStatic ? 'Maybe the static' : null,
        canCopy: Boolean(hole),
    };
});

function copy(): void {
    if (placeholder.value) copyPlaceholderBookmark(store, placeholder.value, canEdit.value);
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverAnchor :reference="reference" />
        <PopoverContent v-if="details" class="w-64 text-sm">
            <div class="flex items-baseline justify-between gap-2">
                <span class="font-semibold">{{ details.title }}</span>
                <span class="text-xs text-muted-foreground">not jumped yet</span>
            </div>
            <span v-if="details.static" class="mt-1 inline-block rounded-full bg-green-800 px-1.5 text-[10px] leading-[14px] font-bold text-green-100">{{ details.static }}</span>
            <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                <dt class="text-muted-foreground">Sig in {{ details.where }}</dt>
                <dd class="font-mono">{{ details.signature }}</dd>
                <dt class="text-muted-foreground">Type</dt>
                <dd>{{ details.type }}</dd>
                <dt class="text-muted-foreground">Leads to</dt>
                <dd>{{ details.leadsTo }}</dd>
                <dt class="text-muted-foreground">Size</dt>
                <dd>{{ details.size }}</dd>
                <dt class="text-muted-foreground">Mass</dt>
                <dd>{{ details.mass }}</dd>
                <dt class="text-muted-foreground">Life</dt>
                <dd>{{ details.life }}</dd>
            </dl>
            <Button v-if="details.canCopy" variant="outline" size="sm" class="mt-3 w-full" @click="copy">
                <ClipboardCopy class="size-3.5" />
                Copy bookmark
            </Button>
        </PopoverContent>
    </Popover>
</template>
