<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { staticConfirm } from '@/composables/signatures/useStaticCertainty';
import { computed, ref, watch } from 'vue';

/**
 * Patch 18b: "★ This is the static" on the map asks first: a wandering hole
 * can look just like the static.
 */
const open = ref(false);
watch(staticConfirm, (ask) => (open.value = ask !== null), { immediate: true });
watch(open, (value) => {
    if (!value) staticConfirm.value = null;
});

const ask = computed(() => staticConfirm.value);

function confirm(): void {
    const current = staticConfirm.value;
    staticConfirm.value = null;
    current?.confirm();
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="ask" class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="font-display">Is {{ ask.signatureLabel }} {{ ask.where }}'s static?</DialogTitle>
                <DialogDescription>
                    Marking it makes it <b>{{ ask.staticName }} → {{ ask.leadsTo }}</b>, names it <b>{{ ask.slot }}</b> and removes the "not identified"
                    box.
                </DialogDescription>
            </DialogHeader>
            <ul class="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                <li>A wandering hole can look just like it. Check Show Info in game: it should lead to {{ ask.leadsTo }} space.</li>
                <li>If you're not 100% sure, leave it and set the type when you know.</li>
            </ul>
            <DialogFooter>
                <Button variant="secondary" @click="open = false">Cancel</Button>
                <Button class="bg-green-700 text-green-50 hover:bg-green-600" @click="confirm">Yes, it's the static</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
