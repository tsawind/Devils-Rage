<script setup lang="ts">
import { mirrorTargetFor, usePortalTarget } from '@/lib/portalTarget'
import { cn } from '@/lib/utils'
import { X } from 'lucide-vue-next'
import {
  DialogClose,
  DialogContent,
  type DialogContentEmits,
  type DialogContentProps,
  DialogOverlay,
  DialogPortal,
  injectDialogRootContext,
  useForwardPropsEmits,
} from 'reka-ui'
import { computed, ref, watch, type HTMLAttributes } from 'vue'
import DialogBroadcast from './DialogBroadcast.vue'
import DialogMirror from './DialogMirror.vue'

const props = defineProps<DialogContentProps & { class?: HTMLAttributes['class'] }>()
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

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
    mirrorTo.value = mirrorTargetFor(portalTo.value)
  },
  { immediate: true, flush: 'sync' },
)
</script>

<template>
  <DialogPortal :to="openedIn">
    <DialogOverlay
      class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/80  data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
    >
      <DialogContent
        :class="
          cn(
            'relative z-50 grid w-full max-w-lg my-8 gap-4 border border-border bg-background p-6 shadow-lg duration-200 sm:rounded-lg md:w-full',
            props.class,
          )
        "
        v-bind="forwarded"
        @pointer-down-outside="(event) => {
          const originalEvent = event.detail.originalEvent;
          const target = originalEvent.target as HTMLElement;
          if (originalEvent.offsetX > target.clientWidth || originalEvent.offsetY > target.clientHeight) {
            event.preventDefault();
          }
        }"
      >
        <slot />
        <DialogMirror :to="mirrorTo" :class="props.class"><slot /></DialogMirror>
        <DialogBroadcast />

        <DialogClose
          class="absolute top-4 right-4 p-0.5 transition-colors rounded-md hover:bg-secondary"
        >
          <X class="w-4 h-4" />
          <span class="sr-only">Close</span>
        </DialogClose>
      </DialogContent>
    </DialogOverlay>
  </DialogPortal>
</template>
