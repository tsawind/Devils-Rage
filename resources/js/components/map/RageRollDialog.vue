<script setup lang="ts">
import MapRageRollController from '@/actions/App/Http/Controllers/MapRageRollController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import InputError from '@/components/ui/error/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useRageRoll } from '@/composables/useRageRoll';
import { displayAlias } from '@/lib/alias';
import { describeKspacePath, describeStatics, nearestKspacePath, parseTargets } from '@/lib/rageRoll';
import { useMapStore } from '@/map/store/mapStore';
import type { AppPageProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * Patch 35: the RAGE ROLL popup. Says what is being rolled (the system, its static,
 * the nearest way into k-space), pings a Discord channel with None / @here /
 * @everyone, takes J-code targets and a note, and can make it a Rage Scanning
 * session (Rage speed for everyone until the roll ends).
 */
type TTarget = { id: number; name: string };
type TPingTargets = { webhooks: TTarget[]; mentions: TTarget[] };

const NO_PING = 0;

const { dialogSolarsystemId, closeRageRoll, mapSlug } = useRageRoll();
const store = useMapStore();
const page = usePage<AppPageProps<{ rally_ping_targets?: TPingTargets }>>();
const webhooks = computed(() => page.props.rally_ping_targets?.webhooks ?? []);
const loading = ref(false);

const open = computed({
    get: () => dialogSolarsystemId.value !== null,
    set: (value: boolean) => {
        if (!value) closeRageRoll();
    },
});

const system = computed(() => {
    const id = dialogSolarsystemId.value;
    if (id === null) return null;
    for (const candidate of store.systems.values()) {
        if (candidate.solarsystem_id === id) return candidate;
    }
    return null;
});

const name = computed(() => {
    const current = system.value;
    if (!current) return '';
    const alias = displayAlias(current.alias);
    return alias && alias !== current.solarsystem.name ? alias : current.solarsystem.name;
});
const details = computed(() => {
    const current = system.value;
    if (!current) return '';
    const cls = current.solarsystem.class;
    return [current.solarsystem.name !== name.value ? current.solarsystem.name : null, cls ? (/^\d+$/.test(String(cls)) ? `C${cls}` : String(cls).toUpperCase()) : null]
        .filter(Boolean)
        .join(' · ');
});
const staticText = computed(() => (system.value ? describeStatics(system.value, store.systems, store.connections.values()) : null));
const kspaceText = computed(() => (system.value ? describeKspacePath(nearestKspacePath(system.value.id, store.systems, store.connections.values())) : null));

const MENTIONS = [
    { key: 'none', label: 'None' },
    { key: 'here', label: '@here' },
    { key: 'everyone', label: '@everyone' },
] as const;

const form = useForm({
    solarsystem_id: 0,
    map_webhook_id: NO_PING as number,
    mention: 'here' as 'none' | 'here' | 'everyone',
    system_text: '' as string | null,
    static_text: '' as string | null,
    kspace_text: '' as string | null,
    note: '',
    targets: [] as string[],
    scanning: false,
});
const targetsText = ref('');

watch(open, (isOpen) => {
    if (!isOpen) return;
    form.clearErrors();
    form.mention = 'here';
    loading.value = true;
    router.reload({
        only: ['rally_ping_targets'],
        onFinish: () => {
            loading.value = false;
            form.map_webhook_id = (webhooks.value.find((webhook) => /ping/i.test(webhook.name)) ?? webhooks.value[0])?.id ?? NO_PING;
        },
    });
});

const pinging = computed(() => form.map_webhook_id !== NO_PING);

function start(): void {
    if (!system.value) return;
    form.solarsystem_id = system.value.solarsystem_id;
    form.system_text = details.value ? `${name.value} (${details.value.replace(' · ', ', ')})` : name.value;
    form.static_text = staticText.value;
    form.kspace_text = kspaceText.value;
    form.targets = parseTargets(targetsText.value);
    form.transform((data) => ({ ...data, map_webhook_id: data.map_webhook_id === NO_PING ? null : data.map_webhook_id })).submit(
        MapRageRollController.store(mapSlug.value),
        {
            preserveScroll: true,
            preserveState: true,
            only: ['map'],
            onSuccess: () => {
                closeRageRoll();
                form.reset('note', 'scanning');
                targetsText.value = '';
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="border-red-900/80 sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="font-display text-lg font-bold tracking-wider text-red-400 uppercase">⚡ Rage roll · {{ name }}</DialogTitle>
                <DialogDescription>Red mode on the map for everyone until someone unticks RAGE ROLL. One ping every two minutes.</DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="start">
                <dl class="grid grid-cols-[7.5rem_1fr] gap-x-3 gap-y-1.5 text-sm">
                    <dt class="text-muted-foreground">System</dt>
                    <dd>
                        <span class="font-semibold">{{ name }}</span>
                        <span v-if="details" class="ml-1 font-mono text-xs text-muted-foreground">{{ details }}</span>
                    </dd>
                    <dt class="text-muted-foreground">Static</dt>
                    <dd class="font-mono text-xs leading-5">{{ staticText ?? 'No static (k-space or unknown)' }}</dd>
                    <dt class="text-muted-foreground">Nearest k-space</dt>
                    <dd class="font-mono text-xs leading-5">{{ kspaceText ?? 'No k-space exit on the map' }}</dd>
                </dl>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="rage-channel">Channel</Label>
                        <Select :model-value="form.map_webhook_id" @update:model-value="(value) => (form.map_webhook_id = Number(value))">
                            <SelectTrigger id="rage-channel" class="w-full"><SelectValue placeholder="Pick a channel" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="webhook in webhooks" :key="webhook.id" :value="webhook.id">#{{ webhook.name }}</SelectItem>
                                <SelectItem :value="NO_PING">Don't ping</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Mention</Label>
                        <div class="grid grid-cols-3 overflow-hidden rounded-md border border-border" :class="{ 'opacity-50': !pinging }">
                            <button
                                v-for="mention in MENTIONS"
                                :key="mention.key"
                                type="button"
                                :disabled="!pinging"
                                class="px-2 py-1.5 text-xs font-medium transition-colors"
                                :class="form.mention === mention.key ? 'bg-red-700 text-white' : 'text-muted-foreground hover:bg-muted'"
                                @click="form.mention = mention.key"
                            >
                                {{ mention.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <label class="flex cursor-pointer items-start gap-2.5 rounded-md border border-red-900/60 bg-red-950/30 p-2.5 text-sm">
                    <Checkbox class="mt-0.5" :model-value="form.scanning" @update:model-value="(value) => (form.scanning = value === true)" />
                    <span class="leading-tight">
                        <span class="font-semibold text-red-300">⚔ Rage Scanning session</span>
                        <span class="block text-xs text-muted-foreground">
                            Rage speed for everyone on the map until the roll ends, then off for everyone. The ping tells scanners to turn on their Rage
                            speed.
                        </span>
                    </span>
                </label>

                <div class="grid gap-1.5">
                    <Label for="rage-targets">Target systems</Label>
                    <Input id="rage-targets" v-model="targetsText" placeholder="J123456, J234567 (optional)" class="font-mono text-sm" />
                    <p class="text-xs text-muted-foreground">If the static opens into one of these, everyone hears it.</p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="rage-note">Note</Label>
                    <Textarea id="rage-note" v-model="form.note" maxlength="300" rows="2" placeholder="e.g. need 2 BS + HIC, rolling for a C5" />
                </div>

                <InputError :message="form.errors.map_webhook_id ?? form.errors.targets ?? form.errors.note ?? form.errors.mention ?? form.errors.solarsystem_id" />

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                    <Button type="submit" class="bg-red-700 font-semibold text-white hover:bg-red-600" :disabled="form.processing || loading || !system">
                        {{ pinging ? 'Ping & start roll' : 'Start roll' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
