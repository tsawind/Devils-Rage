<script setup lang="ts">
import { dialogRole, elementAt, onDialogMessage, sendDialog } from '@/lib/dialogBus'
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Patch 54 (step 2): placed inside an open dialog's content. In the desktop app it sends a live
 * copy of the dialog to the app's other mapper pages (main window ↔ pop-outs) and carries out the
 * clicks and typing done in those copies. Nothing happens outside the app.
 */
const marker = ref<HTMLElement | null>(null)
const id = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
let observer: MutationObserver | null = null
let timer: ReturnType<typeof setTimeout> | null = null
let stop: (() => void) | null = null

onMounted(() => {
  const role = dialogRole()
  const root = marker.value?.parentElement ?? null
  if (!role || !root) return
  const html = () => root.outerHTML
  sendDialog({ kind: 'open', id, from: role, html: html() })
  observer = new MutationObserver(() => {
    if (timer) return
    timer = setTimeout(() => {
      timer = null
      sendDialog({ kind: 'update', id, from: role, html: html() })
    }, 80)
  })
  observer.observe(root, { subtree: true, childList: true, attributes: true, characterData: true })
  stop = onDialogMessage((message) => {
    if (message.id !== id) return
    if (message.kind === 'click') {
      const target = elementAt(root, message.path)
      if (target instanceof HTMLElement) target.click()
    } else if (message.kind === 'input') {
      const target = elementAt(root, message.path)
      if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
        const proto = target instanceof HTMLInputElement ? HTMLInputElement.prototype : HTMLTextAreaElement.prototype
        Object.getOwnPropertyDescriptor(proto, 'value')?.set?.call(target, message.value)
        target.dispatchEvent(new Event('input', { bubbles: true }))
        target.dispatchEvent(new Event('change', { bubbles: true }))
      }
    } else if (message.kind === 'escape') {
      root.ownerDocument.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', bubbles: true, cancelable: true }))
    }
  })
})

onBeforeUnmount(() => {
  observer?.disconnect()
  if (timer) clearTimeout(timer)
  stop?.()
  if (dialogRole()) sendDialog({ kind: 'close', id })
})
</script>

<template>
  <span ref="marker" hidden aria-hidden="true" />
</template>
