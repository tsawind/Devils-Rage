<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import type { TReturnConnectionOption, TReturnHoleOption } from '@/lib/returnHole';
import { ref, watch } from 'vue';

const props = defineProps<{
    options: TReturnHoleOption[];
    preselectId: number | null;
    connections: TReturnConnectionOption[];
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    confirm: [selection: { signatureId: number; connectionId: number }];
    skip: [connectionIds: number[]];
}>();

const selectedId = ref<number | null>(null);
const connectionId = ref<number | null>(null);

watch(open, (isOpen) => {
    if (!isOpen) return;
    selectedId.value = props.preselectId;
    connectionId.value = props.connections[0]?.id ?? null;
});

function confirm(): void {
    if (selectedId.value === null || connectionId.value === null) return;
    emit('confirm', { signatureId: selectedId.value, connectionId: connectionId.value });
    open.value = false;
}

function skip(): void {
    emit(
        'skip',
        props.connections.map((connection) => connection.id),
    );
    open.value = false;
}

function handleOpenChange(isOpen: boolean): void {
    if (!isOpen) skip();
}
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogScrollContent class="max-w-md gap-0 overflow-hidden p-0">
            <DialogHeader class="gap-1.5 border-b border-border/50 bg-muted/30 px-6 py-4 text-left">
                <DialogTitle>Which is your return hole?</DialogTitle>
                <DialogDescription>Pick the wormhole you came through. It will be linked, set as a K162 and its return bookmark copied.</DialogDescription>
            </DialogHeader>

            <form class="grid gap-4 px-6 py-5" @submit.prevent="confirm">
                <div v-if="connections.length > 1" class="grid gap-1.5 text-xs">
                    <label class="font-medium" for="return-connection">Connection</label>
                    <select id="return-connection" v-model="connectionId" class="h-8 rounded-md border border-border bg-background px-2 text-xs">
                        <option v-for="connection in connections" :key="connection.id" :value="connection.id">{{ connection.label }}</option>
                    </select>
                </div>

                <div class="grid max-h-64 gap-0.5 overflow-y-auto">
                    <label
                        v-for="option in options"
                        :key="option.id"
                        class="flex cursor-pointer items-center gap-3 rounded-sm p-2 text-xs hover:bg-muted/40"
                        :class="{ 'bg-muted/60': selectedId === option.id }"
                    >
                        <input v-model="selectedId" type="radio" name="return-hole" class="accent-primary" :value="option.id" />
                        <span class="font-mono font-medium">{{ option.signatureId }}</span>
                        <span class="text-muted-foreground">{{ option.typeLabel }}</span>
                        <span class="ml-auto font-mono" :class="option.onGrid ? 'text-emerald-400' : 'text-muted-foreground'">
                            {{ option.distanceText ?? '—' }}
                        </span>
                    </label>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="skip">Skip</Button>
                    <Button type="submit" :disabled="selectedId === null || connectionId === null">Link return hole</Button>
                </DialogFooter>
            </form>
        </DialogScrollContent>
    </Dialog>
</template>
