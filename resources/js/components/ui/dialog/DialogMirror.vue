<script setup lang="ts">
import { cn } from '@/lib/utils'
import { useEventListener } from '@vueuse/core'
import { X } from 'lucide-vue-next'
import { DialogClose } from 'reka-ui'
import { computed, ref, type HTMLAttributes } from 'vue'

/**
 * Patch 54: a working copy of an open dialog in the other window (main window ↔ pop-out). It is
 * the same dialog drawn twice: its buttons and fields are the dialog's own, so answering here
 * answers it, and both copies close together. Mounted inside the dialog's content, so it only
 * exists while the dialog is open.
 */
const props = defineProps<{ to?: HTMLElement; class?: HTMLAttributes['class'] }>()
const closer = ref<{ $el: HTMLElement } | null>(null)
const doc = computed(() => props.to?.ownerDocument ?? null)

useEventListener(doc, 'keydown', (event: KeyboardEvent) => {
  if (event.key === 'Escape') closer.value?.$el.click()
})
</script>

<template>
  <Teleport v-if="to" :to="to">
    <div data-slot="dialog-mirror" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/80 text-foreground">
      <div role="dialog" aria-modal="true" :class="cn('relative z-50 my-8 grid w-full max-w-[calc(100%-2rem)] gap-4 rounded-lg border border-border bg-background p-6 shadow-lg sm:max-w-lg', props.class)">
        <slot />
        <DialogClose ref="closer" class="absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 [&_svg]:size-4">
          <X />
          <span class="sr-only">Close</span>
        </DialogClose>
      </div>
    </div>
  </Teleport>
</template>
