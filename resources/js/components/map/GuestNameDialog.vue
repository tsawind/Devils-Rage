<script setup lang="ts">
import GuestNameController from '@/actions/App/Http/Controllers/GuestNameController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * Patch 39: a guest (here through a share link, not on SeAT) is asked once what people
 * should call them. The name shows for every "who" in the mapper; it is unique across
 * SeAT people's names and other guests.
 */
type TGuestName = { needed: boolean; suggested: string | null; current: string | null };

const page = usePage();
const guest = computed(() => (page.props as unknown as { guest_name?: TGuestName | null }).guest_name ?? null);

const open = ref(false);
const name = ref('');
const error = ref<string | null>(null);
const busy = ref(false);

watch(
    guest,
    (value) => {
        if (value?.needed && !open.value) {
            name.value = value.suggested ?? '';
            open.value = true;
        }
    },
    { immediate: true },
);

function save(): void {
    busy.value = true;
    error.value = null;
    router.put(
        GuestNameController.update().url,
        { name: name.value.trim() },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['guest_name', 'pilot_mains'],
            onSuccess: () => (open.value = false),
            onError: (errors) => (error.value = errors.name ?? 'That name did not work.'),
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogScrollContent class="max-w-sm">
            <DialogHeader>
                <DialogTitle>What should people call you?</DialogTitle>
                <DialogDescription>Shown to everyone on this map wherever you did something (jumps, holes you armed, the pilots list).</DialogDescription>
            </DialogHeader>
            <form class="space-y-2" @submit.prevent="save">
                <input v-model="name" type="text" maxlength="24" autofocus class="h-9 w-full rounded-md border bg-background px-2 text-sm" />
                <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
            </form>
            <DialogFooter>
                <Button variant="ghost" @click="open = false">Later</Button>
                <Button :disabled="busy || name.trim().length < 2" @click="save">Save</Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
