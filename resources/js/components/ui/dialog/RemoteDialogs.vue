<script setup lang="ts">
import { dialogRole, joinDialogs, remoteDialogs } from '@/lib/dialogBus'
import { openPopOutBody } from '@/lib/portalTarget'
import { computed } from 'vue'
import RemoteDialog from './RemoteDialog.vue'

/**
 * Patch 54 (step 2): the other mapper pages' open questions, drawn here so they can be answered
 * in either place: in the main window, the pop-outs' questions; in a pop-out (its hidden page),
 * the main window's. Mounted once with the map.
 */
joinDialogs()
const role = dialogRole()
const target = computed<HTMLElement | null>(() => {
  if (role === 'main') return typeof document === 'undefined' ? null : document.body
  if (role === 'host') return openPopOutBody.value?.isConnected ? openPopOutBody.value : null
  return null
})
const dialogs = computed(() => [...remoteDialogs.value.entries()])
</script>

<template>
  <template v-if="target">
    <RemoteDialog v-for="[id, dialog] in dialogs" :id="id" :key="id" :html="dialog.html" :to="target" />
  </template>
</template>
