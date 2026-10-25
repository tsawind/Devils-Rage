<script setup lang="ts">
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
import { computed } from 'vue';
import { useMinuteNow } from '@/composables/useMinuteNow';
import { uncheckedFor } from '@/lib/holeChecked';
import { lifeLine } from '@/lib/holeSummary';
import { estimateMass, massHints } from '@/lib/massEstimate';
import { lifetimeRange, wormholeLifetimeHours } from '@/lib/wormholeLifetime';
import { markSignatureChecked } from '@/map/actions/holeChecked';
import { couldBeNames, holeCandidates } from '@/map/holeGuess';
import HoleSummary, { type THoleSummary } from '@/map/components/overlays/connection/HoleSummary.vue';

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

// ---- Patch 37: who and when, life, mass left, last look (same box as jumped pipes) ----
const now = useMinuteNow();

const summary = computed((): THoleSummary | null => {
    const current = placeholder.value;
    const parent = current ? store.systems.get(current.parentId) : null;
    if (!current || !parent) return null;
    const hole = parent.pending_holes?.find((candidate) => candidate.id === current.signatureId) ?? null;
    const facts = holeFacts(current, parent);
    const knownType = current.wormhole && !facts.isK162 ? current.wormhole.toUpperCase() : null;
    // Patch 37: a K162's "Could be" lists the holes from its far side (frigate holes for a K162 frigate).
    const frigate = isK162Frigate(facts.holeTypeInfo) || current.shipSize === 'frigate';
    const candidates = knownType
        ? []
        : facts.isK162
          ? holeCandidates({ k162Class: facts.parentClass, spawnClasses: facts.farClasses, frigate })
          : holeCandidates({ endClasses: [facts.parentClass, current.targetClass ?? null], frigate });
    const total = facts.typeMass?.total ?? facts.guess?.total ?? null;
    const status = hole?.mass_status ?? current.massStatus ?? 'fresh';
    const estimate = total ? estimateMass({ totalMass: total, jumped: 0, jumpedSinceStatus: 0, status }) : null;
    const jumps = [...new Set(candidates.map((candidate) => candidate.maxJump))];
    const lifeHours = knownType ? wormholeLifetimeHours(knownType) : null;
    return {
        typeLine: null,
        couldBe: couldBeNames(candidates),
        addedAt: hole?.created_at ?? null,
        addedBy: hole?.created_by_name ?? null,
        typeSetAt: hole?.type_set_at ?? null,
        typeSetBy: hole?.type_set_by_name ?? null,
        life: lifeLine({
            lifetime: hole?.lifetime ?? current.lifetime,
            markedAt: [hole?.lifetime_updated_at, hole?.checked_at].filter((value): value is string => Boolean(value)).sort().at(-1) ?? null,
            eolSince: hole?.eol_since ?? null,
            criticalSince: hole?.critical_since ?? null,
            imminentSince: hole?.imminent_since ?? null,
            hours: lifeHours ? [lifeHours, lifeHours] : lifetimeRange(candidates.map((candidate) => candidate.name)),
            addedAt: hole?.created_at ?? null,
            now: now.value,
        }),
        perJump: facts.typeMass ? `${formatMass(facts.typeMass.maxJump)} kg` : jumps.length === 1 ? `${formatMass(jumps[0])} kg (all of them)` : null,
        estimate,
        status,
        loggedSinceMark: 0,
        markedAt: null,
        markedBy: null,
        hints: massHints({ estimate, totalMass: total, status, jumpedSinceStatus: 0 }),
        lastLook: hole?.checked_at ? { at: Date.parse(hole.checked_at), by: hole.checked_by_name ?? null, note: hole.checked_note ?? 'checked' } : null,
        unchecked: hole ? uncheckedFor({ created_at: hole.created_at, lifetime_status_updated_at: hole.lifetime_updated_at, checked_at: hole.checked_at }, now.value) : null,
        canCheck: Boolean(hole) && canEdit.value,
        canCopy: Boolean(hole),
    };
});

function checked(): void {
    if (placeholder.value) markSignatureChecked(placeholder.value.signatureId);
}

function copy(): void {
    if (placeholder.value) copyPlaceholderBookmark(store, placeholder.value, canEdit.value);
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverAnchor :reference="reference" />
        <PopoverContent v-if="details" class="w-80 text-sm">
            <div class="flex items-baseline justify-between gap-2">
                <span class="font-semibold">{{ details.title }}</span>
                <span class="text-xs text-muted-foreground">not jumped yet</span>
            </div>
            <span v-if="details.static" class="mt-1 inline-block rounded-full bg-yellow-500 px-1.5 text-[10px] leading-[14px] font-bold text-yellow-950">{{ details.static }}</span>
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
            </dl>
            <HoleSummary v-if="summary" class="mt-2 border-t pt-2" :summary="summary" :now="now" @checked="checked" @copy="copy" />
        </PopoverContent>
    </Popover>
</template>
