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
 * map's Discord settings) and who to mention each time; the Safest route and a note go with it.
 */
type TTarget = { id: number; name: string };
type TTargets = { webhooks: TTarget[]; mentions: TTarget[] };

const open = defineModel<boolean>('open', { default: false });
const { mapSlug, rallySolarsystemId, where } = defineProps<{ mapSlug: string; rallySolarsystemId: number; where: string }>();

const page = usePage<AppPageProps<{ rally_ping_targets?: TTargets }>>();
const targets = computed<TTargets>(() => page.props.rally_ping_targets ?? { webhooks: [], mentions: [] });
const loading = ref(false);
const { routeText } = useRouteCopy();
const route = ref<string | null>(null);
const includeRoute = ref(true);

const LAST_KEY = 'rally-ping-last';
function readLast(): { webhook?: number; mention?: string } {
    try {
        return JSON.parse(localStorage.getItem(LAST_KEY) ?? '{}');
    } catch {
        return {};
    }
}

const form = useForm({ map_webhook_id: null as number | null, mention: 'none', route: '' as string | null, note: '' });

watch(
    open,
    async (isOpen) => {
        if (!isOpen) return;
        loading.value = true;
        router.reload({
            only: ['rally_ping_targets'],
            onFinish: () => {
                loading.value = false;
                const last = readLast();
                const webhooks = targets.value.webhooks;
                form.map_webhook_id = webhooks.find((webhook) => webhook.id === last.webhook)?.id ?? webhooks[0]?.id ?? null;
                const mentionOk = last.mention === 'none' || last.mention === 'here' || targets.value.mentions.some((mention) => `role:${mention.id}` === last.mention);
                form.mention = mentionOk && last.mention ? last.mention : 'none';
            },
        });
        route.value = await routeText('safest', rallySolarsystemId);
    },
);

function send(): void {
    form.route = includeRoute.value ? route.value : null;
    try {
        localStorage.setItem(LAST_KEY, JSON.stringify({ webhook: form.map_webhook_id, mention: form.mention }));
    } catch {
        /* remembered only when the browser allows it */
    }
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
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>📣 Ping: form up at {{ where }}</DialogTitle>
                <DialogDescription>Posts to Discord. One ping per map every two minutes.</DialogDescription>
            </DialogHeader>

            <p v-if="!loading && targets.webhooks.length === 0" class="text-sm text-muted-foreground">
                No channel yet. Add a webhook for your pings channel in
                <Link :href="`/maps/${mapSlug}/settings/discord`" class="underline">the map's Discord settings</Link>.
            </p>

            <form v-else class="grid gap-4" @submit.prevent="send">
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
                                <SelectItem value="here">@here</SelectItem>
                                <SelectItem v-for="mention in targets.mentions" :key="mention.id" :value="`role:${mention.id}`">@{{ mention.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                        <Checkbox :model-value="includeRoute" @update:model-value="(value) => (includeRoute = value === true)" />
                        Include the Safest route
                    </label>
                    <pre v-if="includeRoute" class="max-h-24 overflow-y-auto rounded-md bg-muted/40 p-2 font-mono text-[11px] whitespace-pre-wrap">{{
                        route ?? 'No route found from Routing\'s From (or home).'
                    }}</pre>
                </div>

                <div class="grid gap-1.5">
                    <Label for="rally-note">Note</Label>
                    <Textarea id="rally-note" v-model="form.note" maxlength="300" rows="2" placeholder="Doctrine, time, who brings what…" />
                </div>

                <InputError :message="form.errors.map_webhook_id ?? form.errors.mention ?? form.errors.note ?? form.errors.route" />

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                    <Button type="submit" class="bg-pink-600 text-white hover:bg-pink-500" :disabled="form.processing || !form.map_webhook_id">Send ping</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
