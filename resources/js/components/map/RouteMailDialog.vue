<script setup lang="ts">
import RouteMailController from '@/actions/App/Http/Controllers/RouteMailController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import useUser from '@/composables/useUser';
import { useRouteCopy } from '@/composables/useRouteCopy';
import { useShowMap } from '@/composables/useShowMap';
import { usePilotNames } from '@/composables/usePilotNames';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 37: mail the route report (every route, real system links, a 24 h route page) to
 * your main (from SeAT) or to the character you fly, through EVE mail. Forward it in game.
 */
const open = defineModel<boolean>('open', { required: true });

const page = useShowMap();
const user = useUser();
const character = computed(() => user.value?.active_character ?? null);
const { suffix } = usePilotNames();
const { routeReport } = useRouteCopy();

const to = ref<'main' | 'self'>('main');
const note = ref('');
const sending = ref(false);
const error = ref<string | null>(null);

const characterName = computed(() => character.value?.name ?? 'this character');
const mainName = computed(() => (character.value ? suffix(character.value.name) : null));

async function send(): Promise<void> {
    error.value = null;
    sending.value = true;
    const report = await routeReport(note.value.trim() || null);
    if (!report) {
        sending.value = false;
        return;
    }
    router.post(
        RouteMailController.store(page.props.map.slug).url,
        { to: to.value, subject: report.subject, body: report.body, report: report.report },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['notification'],
            onSuccess: () => {
                open.value = false;
                note.value = '';
            },
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? 'The mail could not be sent.';
                toast.error(error.value);
            },
            onFinish: () => (sending.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Mail the route report</DialogTitle>
                <DialogDescription>Every route (Default, Shortest, Safest and the backups) with real system links, and a link to them on the mapper for 24 h. Sent from {{ characterName }}.</DialogDescription>
            </DialogHeader>
            <div class="space-y-3 text-sm">
                <div class="space-y-1">
                    <p class="text-xs font-medium text-muted-foreground">Send to</p>
                    <label class="flex items-center gap-2">
                        <input v-model="to" type="radio" value="main" :disabled="mainName === 'Missing SeAT'" />
                        My main <span class="text-muted-foreground">({{ mainName === 'Missing SeAT' ? 'not in SeAT' : (mainName ?? characterName) }})</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="to" type="radio" value="self" />
                        This character <span class="text-muted-foreground">({{ characterName }})</span>
                    </label>
                </div>
                <div class="space-y-1">
                    <p class="text-xs font-medium text-muted-foreground">Note (optional)</p>
                    <input v-model="note" type="text" maxlength="200" placeholder="Form up in Daisy at 16:00" class="h-8 w-full rounded-md border bg-background px-2" />
                </div>
                <p class="text-xs text-muted-foreground">First time: your character needs the mail permission (Settings → EVE characters → Grant all permissions). EVE limits how often mail can be sent.</p>
                <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button :disabled="sending" @click="send">{{ sending ? 'Sending…' : 'Send mail' }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
