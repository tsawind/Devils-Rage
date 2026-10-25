<script setup lang="ts">
import { mirrorTargetFor, usePortalTarget } from '@/lib/portalTarget'
import { cn } from '@/lib/utils'
import { X } from 'lucide-vue-next'
import {
  DialogClose,
  DialogContent,
  type DialogContentEmits,
  type DialogContentProps,
  DialogPortal,
  injectDialogRootContext,
  useForwardPropsEmits,
} from 'reka-ui'
import { computed, ref, watch, type HTMLAttributes } from 'vue'
import DialogBroadcast from './DialogBroadcast.vue'
import DialogMirror from './DialogMirror.vue'
import DialogOverlay from './DialogOverlay.vue'

// Patch 55: `local` keeps a big window (intel) in the window it opened in: no copy in the pop-out.
const props = defineProps<DialogContentProps & { class?: HTMLAttributes['class']; local?: boolean }>()
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = computed(() => {
  const { class: _, local: __, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)
const portalTo = usePortalTarget()

// Patch 54: where it opens is settled when it opens (it doesn't hop windows while you answer),
// and a copy shows in the other window (main window / pop-out), answerable from either.
const root = injectDialogRootContext()
const openedIn = ref<HTMLElement | undefined>(portalTo.value)
const mirrorTo = ref<HTMLElement | undefined>(undefined)
watch(
  () => root.open.value,
  (open) => {
    if (!open) return
    openedIn.value = portalTo.value
    mirrorTo.value = props.local ? undefined : mirrorTargetFor(portalTo.value)
  },
  { immediate: true, flush: 'sync' },
)
</script>

<template>
  <DialogPortal :to="openedIn">
    <DialogOverlay />
    <DialogContent
      data-slot="dialog-content"
      v-bind="forwarded"
      :class="
        cn(
          'bg-background data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] gap-4 rounded-lg border p-6 shadow-lg duration-200 sm:max-w-lg',
          props.class,
        )"
    >
      <slot />
      <DialogMirror :to="mirrorTo" :class="props.class"><slot /></DialogMirror>
      <DialogBroadcast v-if="!props.local" />

      <DialogClose
        class="ring-offset-background focus:ring-ring data-[state=open]:bg-accent data-[state=open]:text-muted-foreground absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
      >
        <X />
        <span class="sr-only">Close</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
