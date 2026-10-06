<script setup lang="ts">
import MapRallyPingController from '@/actions/App/Http/Controllers/MapRallyPingController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import InputError from '@/components/ui/error/InputError.vue';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useRouteCopy } from '@/composables/useRouteCopy';
import type { AppPageProps } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * Patch 29: "Form up at the rally point" to Discord. Pick the channel (a webhook from the
 * map's Discord settings) and who to mention each time; routes, predictions and a note go with it.
 */
type TTarget = { id: number; name: string };
type TTargets = { webhooks: TTarget[]; mentions: TTarget[] };

const open = defineModel<boolean>('open', { default: false });
const { mapSlug, rallySolarsystemId, where } = defineProps<{ mapSlug: string; rallySolarsystemId: number; where: string }>();

const page = usePage<AppPageProps<{ rally_ping_targets?: TTargets }>>();
const targets = computed<TTargets>(() => page.props.rally_ping_targets ?? { webhooks: [], mentions: [] });
const loading = ref(false);
const { pingSections } = useRouteCopy();

/**
 * Patch 29c: every route copy and prediction can go in the ping. Defaults: Safest and its
 * backup on (patch 29d: Shortest too, listed first); the rest off. Ticks are remembered.
 */
type TPick = 'default' | 'shortest' | 'shortestBackup' | 'safest' | 'safestBackup' | 'roundTrip' | 'hold' | 'scan';
const PICKS: { key: TPick; label: string; hint: string; experimental?: boolean }[] = [
    { key: 'shortest', label: 'Shortest route', hint: 'Fewest jumps' },
    { key: 'safest', label: 'Safest route', hint: 'Frigate holes skipped' },
    { key: 'shortestBackup', label: 'Shortest backup', hint: 'If a hole on it goes' },
    { key: 'safestBackup', label: 'Safest backup', hint: 'If a hole on it goes' },
    { key: 'default', label: 'Default route', hint: 'Your route settings' },
    { key: 'roundTrip', label: 'In and back out', hint: '½ mass in the fleet size' },
    { key: 'hold', label: 'Will it hold?', hint: 'Mass and EOL facts', experimental: true },
    { key: 'scan', label: 'Scan plan', hint: 'Where to scan if it collapses', experimental: true },
];
// Patch 29d: Shortest and Safest first and on (plus the Safest backup).
const DEFAULT_PICKS: TPick[] = ['shortest', 'safest', 'safestBackup'];
const PICKS_KEY = 'rally-ping-picks-v2';
function readPicks(): TPick[] {
    try {
        const saved = JSON.parse(localStorage.getItem(PICKS_KEY) ?? 'null');
        return Array.isArray(saved) ? saved.filter((key): key is TPick => PICKS.some((pick) => pick.key === key)) : DEFAULT_PICKS;
    } catch {
        return DEFAULT_PICKS;
    }
}
const picked = ref<Set<TPick>>(new Set(readPicks()));
function setPick(key: TPick, on: boolean): void {
    const next = new Set(picked.value);
    if (on) next.add(key);
    else next.delete(key);
    picked.value = next;
    try {
        localStorage.setItem(PICKS_KEY, JSON.stringify([...next]));
    } catch {
        /* remembered only when the browser allows it */
    }
}

const sections = ref<{ title: string; text: string }[]>([]);
const building = ref(false);
async function buildSections(): Promise<void> {
    building.value = true;
    const kinds = (['shortest', 'safest', 'shortestBackup', 'safestBackup', 'default'] as const).filter((kind) => picked.value.has(kind));
    sections.value = await pingSections(rallySolarsystemId, { kinds, roundTrip: picked.value.has('roundTrip'), hold: picked.value.has('hold'), scan: picked.value.has('scan') });
    building.value = false;
}
watch(picked, () => {
    if (open.value) buildSections();
});

/** Patch 30: Form up, Rally moved (a new rally point) or Stand down; no @here / @everyone for the last two. */
type TKind = 'form_up' | 'moved' | 'stand_down';
const KINDS: { key: TKind; label: string }[] = [
    { key: 'form_up', label: '⚑ Form up' },
    { key: 'moved', label: '↪ Rally moved' },
    { key: 'stand_down', label: '✋ Stand down' },
];
const form = useForm({ kind: 'form_up' as TKind, map_webhook_id: null as number | null, mention: 'everyone', sections: [] as { title: string; text: string }[], note: '' });
const broadMentions = computed(() => form.kind === 'form_up');
const showRoutes = computed(() => form.kind !== 'stand_down');
const heading = computed(() => (form.kind === 'stand_down' ? 'Stand down' : form.kind === 'moved' ? `Rally moved to ${where}` : `Form up at ${where}`));
function setKind(kind: TKind): void {
    form.kind = kind;
    if (kind === 'form_up') form.mention = 'everyone';
    else if (form.mention === 'here' || form.mention === 'everyone') form.mention = 'none';
}

/** Defaults: the channel with "ping" in its name (else the first) and @everyone. */
watch(open, (isOpen) => {
    if (!isOpen) return;
    loading.value = true;
    router.reload({
        only: ['rally_ping_targets'],
        onFinish: () => {
            loading.value = false;
            const webhooks = targets.value.webhooks;
            form.map_webhook_id = (webhooks.find((webhook) => /ping/i.test(webhook.name)) ?? webhooks[0])?.id ?? null;
            setKind('form_up');
        },
    });
    buildSections();
});

function send(): void {
    form.sections = showRoutes.value ? sections.value : [];
    form.submit(MapRallyPingController.store(mapSlug), {
        preserveScroll: true,
        preserveState: true,
        only: ['map'],
        onSuccess: () => {
            open.value = false;
            form.reset('note');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>📣 Ping: {{ heading }}</DialogTitle>
                <DialogDescription>Posts to Discord. One ping of each kind per map every two minutes.</DialogDescription>
            </DialogHeader>

            <p v-if="!loading && targets.webhooks.length === 0" class="text-sm text-muted-foreground">
                No channel yet. Add a webhook for your pings channel in
                <Link :href="`/maps/${mapSlug}/settings/discord`" class="underline">the map's Discord settings</Link>.
            </p>

            <form v-else class="grid gap-4" @submit.prevent="send">
                <div class="grid grid-cols-3 gap-1 rounded-lg bg-muted/40 p-1">
                    <button
                        v-for="kind in KINDS"
                        :key="kind.key"
                        type="button"
                        class="rounded-md px-2 py-1.5 text-sm font-medium transition-colors"
                        :class="form.kind === kind.key ? 'bg-pink-600 text-white' : 'text-muted-foreground hover:bg-muted'"
                        @click="setKind(kind.key)"
                    >
                        {{ kind.label }}
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="rally-channel">Channel</Label>
                        <Select :model-value="form.map_webhook_id ?? undefined" @update:model-value="(value) => (form.map_webhook_id = Number(value))">
                            <SelectTrigger id="rally-channel" class="w-full"><SelectValue placeholder="Pick a channel" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="webhook in targets.webhooks" :key="webhook.id" :value="webhook.id">{{ webhook.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="rally-mention">Mention</Label>
                        <Select :model-value="form.mention" @update:model-value="(value) => (form.mention = String(value))">
                            <SelectTrigger id="rally-mention" class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">No mention</SelectItem>
                                <SelectItem v-if="broadMentions" value="here">@here</SelectItem>
                                <SelectItem v-if="broadMentions" value="everyone">@everyone</SelectItem>
                                <SelectItem v-for="mention in targets.mentions" :key="mention.id" :value="`role:${mention.id}`">@{{ mention.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div v-if="showRoutes" class="grid gap-2">
                    <Label>Include</Label>
                    <div class="grid grid-cols-2 gap-x-3 gap-y-1.5">
                        <label v-for="pick in PICKS" :key="pick.key" class="flex cursor-pointer items-start gap-2 text-sm" :title="pick.hint">
                            <Checkbox class="mt-0.5" :model-value="picked.has(pick.key)" @update:model-value="(value) => setPick(pick.key, value === true)" />
                            <span class="leading-tight">
                                {{ pick.label }}
                                <span v-if="pick.experimental" class="text-[10px] font-semibold text-amber-400 uppercase">exp</span>
                                <span class="block text-[11px] text-muted-foreground">{{ pick.hint }}</span>
                            </span>
                        </label>
                    </div>
                    <div class="max-h-40 overflow-y-auto rounded-md bg-muted/40 p-2 font-mono text-[11px]">
                        <p v-if="building" class="text-muted-foreground">Working out the routes…</p>
                        <p v-else-if="sections.length === 0" class="text-muted-foreground">Nothing ticked (or no route from Routing's From / home).</p>
                        <template v-else>
                            <div v-for="section in sections" :key="section.title" class="mb-1.5 last:mb-0">
                                <p class="font-sans font-semibold">{{ section.title }}</p>
                                <p class="whitespace-pre-wrap">{{ section.text }}</p>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <Label for="rally-note">Note</Label>
                    <Textarea id="rally-note" v-model="form.note" maxlength="300" rows="2" :placeholder="form.kind === 'stand_down' ? 'Why, and what next…' : 'Doctrine, time, who brings what…'" />
                </div>

                <InputError :message="form.errors.map_webhook_id ?? form.errors.mention ?? form.errors.note ?? form.errors.sections ?? form.errors.kind" />

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                    <Button type="submit" class="bg-pink-600 text-white hover:bg-pink-500" :disabled="form.processing || building || !form.map_webhook_id">Send ping</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
