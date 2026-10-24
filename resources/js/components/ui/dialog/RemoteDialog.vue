<script setup lang="ts">
import { pathOf, sendDialog } from '@/lib/dialogBus'
import { useEventListener } from '@vueuse/core'
import { computed, onMounted, ref, watch } from 'vue'

/**
 * Patch 54 (step 2): a working copy of another page's dialog. Its look comes from the real one;
 * a click or typing here is sent back and done on the real one (which then closes both).
 */
const props = defineProps<{ id: string; html: string; to: HTMLElement }>()
const box = ref<HTMLElement | null>(null)
const doc = computed(() => props.to.ownerDocument)

/** The copy is redrawn as the real one changes, but not under your fingers while you type in it. */
function draw(): void {
  const element = box.value
  if (!element) return
  const active = doc.value.activeElement
  if (active && element.contains(active) && (active instanceof HTMLInputElement || active instanceof HTMLTextAreaElement)) return
  element.innerHTML = props.html
}
onMounted(draw)
watch(() => props.html, draw)

function rootOf(): Element | null {
  return box.value?.firstElementChild ?? null
}

function onClick(event: MouseEvent): void {
  const root = rootOf()
  const target = event.target instanceof Element ? event.target.closest('button, a, [role="button"], [role="checkbox"], [role="radio"], [role="option"], input[type="checkbox"], input[type="radio"], label') : null
  if (!root || !target) return
  event.preventDefault()
  const path = pathOf(root, target)
  if (path) sendDialog({ kind: 'click', id: props.id, path })
}

function onInput(event: Event): void {
  const root = rootOf()
  const target = event.target
  if (!root || !(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) return
  if (target instanceof HTMLInputElement && (target.type === 'checkbox' || target.type === 'radio')) return
  const path = pathOf(root, target)
  if (path) sendDialog({ kind: 'input', id: props.id, path, value: target.value })
}

useEventListener(doc, 'keydown', (event: KeyboardEvent) => {
  if (event.key === 'Escape') sendDialog({ kind: 'escape', id: props.id })
})
</script>

<template>
  <Teleport :to="to">
    <div data-slot="dialog-remote" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/80 text-foreground">
      <div ref="box" class="contents" @click.capture="onClick" @input="onInput" />
    </div>
  </Teleport>
</template>
