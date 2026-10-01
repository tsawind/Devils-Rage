<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { add_mass_target, describeKg, parseMillionKg } from '@/map/actions/addMass';
import { createMapConnectionJump } from '@/map/actions/createMapConnectionJump';
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Right-click a connection → "Add mass…" (patch 12): mass seen going through,
 * typed in million kg ("1200" = 1.2 billion kg). Saved as a manual jump entry,
 * so it shows in the connection's mass list where it can be edited or deleted.
 */
const value = ref('');
const input_ref = ref<InstanceType<typeof Input> | null>(null);

const open = computed({
    get: () => add_mass_target.value !== null,
    set: (isOpen: boolean) => {
        if (!isOpen) add_mass_target.value = null;
    },
});

const kg = computed(() => parseMillionKg(value.value));
const preview = computed(() => {
    if (value.value.trim() === '') return 'Type the mass in million kg, e.g. 1200';
    return kg.value === null ? 'Enter a number of million kg (up to 100,000)' : `= ${describeKg(kg.value)} · Enter to add`;
});

watch(open, (isOpen) => {
    if (!isOpen) return;
    value.value = '';
    // The context menu hands focus back as it closes; take it after that.
    void nextTick(() => setTimeout(() => (input_ref.value?.$el as HTMLInputElement | undefined)?.focus(), 50));
});

function submit(): void {
    const target = add_mass_target.value;
    const mass = kg.value;
    if (!target || mass === null) return;

    add_mass_target.value = null;
    createMapConnectionJump(
        target.connectionId,
        { direction: 'outbound', mass },
        {
            onSuccess: () => toast.success(`Added ${describeKg(mass)}`, { description: target.label }),
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not add the mass.'),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogScrollContent class="max-w-sm gap-0 overflow-hidden p-0">
            <form @submit.prevent="submit">
                <DialogHeader class="gap-1.5 border-b border-border/50 bg-muted/30 px-6 py-4 text-left">
                    <DialogTitle>Add mass</DialogTitle>
                    <DialogDescription>Through {{ add_mass_target?.label }}</DialogDescription>
                </DialogHeader>
                <div class="grid gap-1.5 px-6 py-4">
                    <div class="flex items-center gap-2">
                        <Input ref="input_ref" v-model="value" inputmode="decimal" placeholder="1200" class="w-32 font-mono" aria-label="Mass in million kg" />
                        <span class="text-sm text-muted-foreground">million kg</span>
                    </div>
                    <p class="text-xs" :class="kg === null && value.trim() !== '' ? 'text-destructive' : 'text-muted-foreground'">{{ preview }}</p>
                </div>
                <DialogFooter class="gap-2 border-t border-border/50 bg-muted/30 px-6 py-3">
                    <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                    <Button type="submit" :disabled="kg === null">Add</Button>
                </DialogFooter>
            </form>
        </DialogScrollContent>
    </Dialog>
</template>
