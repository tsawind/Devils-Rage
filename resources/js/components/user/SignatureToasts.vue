<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { dismissSignatureToast, pauseSignatureToasts, resumeSignatureToasts, signatureToasts, type TSignatureToastButton } from '@/lib/signatureToast';
import { cn } from '@/lib/utils';
import { X } from 'lucide-vue-next';

/**
 * Patch 21: the signature popups' own column, just right of the usual ones
 * (same height, same look), so both can be read when they arrive together.
 */
const accent: Record<string, string> = {
    success: 'border-l-green-500',
    error: 'border-l-red-500',
    warning: 'border-l-amber-500',
    info: 'border-l-blue-500',
    default: 'border-l-muted-foreground',
};

function press(id: number, button: TSignatureToastButton, event: MouseEvent): void {
    button.onClick(event);
    dismissSignatureToast(id);
}
</script>

<template>
    <ol
        v-if="signatureToasts.length"
        class="pointer-events-none fixed top-6 z-[999999999] flex w-[356px] flex-col gap-2"
        style="left: calc(50% + 190px)"
        aria-label="Signature notifications"
        @mouseenter="pauseSignatureToasts"
        @mouseleave="resumeSignatureToasts"
    >
        <li
            v-for="toast in signatureToasts"
            :key="toast.id"
            role="status"
            :class="cn('pointer-events-auto relative flex items-start gap-3 border border-l-2 border-border/50 bg-card px-4 py-3 text-sm text-foreground shadow-lg', accent[toast.type])"
        >
            <div class="min-w-0 flex-1 pr-4">
                <div class="text-sm font-medium text-foreground">{{ toast.title }}</div>
                <div v-if="toast.description" class="mt-1 text-xs break-words text-muted-foreground">{{ toast.description }}</div>
                <div v-if="toast.action || toast.cancel" class="mt-3 flex gap-2">
                    <button v-if="toast.cancel" type="button" :class="buttonVariants({ variant: 'outline', size: 'sm' })" @click="(event) => press(toast.id, toast.cancel!, event)">
                        {{ toast.cancel.label }}
                    </button>
                    <button v-if="toast.action" type="button" :class="buttonVariants({ variant: 'default', size: 'sm' })" @click="(event) => press(toast.id, toast.action!, event)">
                        {{ toast.action.label }}
                    </button>
                </div>
            </div>
            <button
                type="button"
                aria-label="Close"
                class="absolute top-2 right-2 flex size-5 items-center justify-center text-muted-foreground/60 transition-colors hover:text-foreground"
                @click="dismissSignatureToast(toast.id)"
            >
                <X class="size-3.5" />
            </button>
        </li>
    </ol>
</template>
