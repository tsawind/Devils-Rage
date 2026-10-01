<script setup lang="ts">
import StaticRenameDialog from '@/components/signatures/StaticRenameDialog.vue';
import { useCombat } from '@/composables/combat/useCombat';
import { certainAsk } from '@/composables/signatures/useStaticCertainty';
import { displayAlias } from '@/lib/alias';
import { computed, ref, watch } from 'vue';

/**
 * Patch 14: the certain-static check found the static, and marking it would
 * rename a hole already named on the map (A2 → A0). Keep is the default.
 */
const { popup_seconds } = useCombat();
const open = ref(false);
watch(certainAsk, (ask) => (open.value = ask !== null), { immediate: true });

const ask = computed(() => certainAsk.value);

function handleChoose(choice: 'rename' | 'keep'): void {
    ask.value?.choose(choice);
}
</script>

<template>
    <StaticRenameDialog
        v-if="ask"
        v-model:open="open"
        :signature-label="ask.signatureLabel"
        :from-alias="displayAlias(ask.from)"
        :to-alias="displayAlias(ask.to)"
        :changes="ask.changes"
        :beyond="ask.beyond"
        :countdown-seconds="popup_seconds"
        description="Every signature is scanned and nothing else can be it, so it is marked as the static either way. Renaming means you change these bookmarks in game; keeping leaves every name as it is."
        @choose="handleChoose"
    />
</template>
