<script setup lang="ts">
import { classMeta } from '@/const/solarsystemClasses';
import { isK162Frigate, k162Classes } from '@/lib/k162';
import type { TSignatureType, TStringedSolarsystemClass } from '@/types/models';
import { computed } from 'vue';

const props = defineProps<{
    wormhole: TSignatureType;
}>();

const meta = computed(() => (props.wormhole.target_class ? classMeta(props.wormhole.target_class) : null));

/** Patch 20: a grouped K162 shows its classes in their colours ("C4/C5"); a K162 frigate says so. */
const range = computed(() => {
    const classes = k162Classes(props.wormhole);
    return classes.length > 1 ? classes.map((value) => classMeta(value as TStringedSolarsystemClass)) : null;
});
const frigate = computed(() => isK162Frigate(props.wormhole));
</script>

<template>
    <span class="flex gap-2">
        <span class="inline-block w-[4ch]">
            {{ wormhole.signature }}
        </span>
        <span v-if="range">
            <template v-for="(entry, index) in range" :key="entry.value">
                <span v-if="index > 0" class="text-muted-foreground">/</span>
                <span :class="`text-${entry.color_token}`">{{ entry.short_label }}</span>
            </template>
        </span>
        <span v-else-if="frigate" class="text-muted-foreground">frigate</span>
        <template v-else>
            <span v-if="meta" :class="`text-${meta.color_token}`">
                {{ meta.short_label }}
            </span>
            <span v-if="wormhole.extra" class="text-muted-foreground"> ({{ wormhole.extra }}) </span>
        </template>
    </span>
</template>

<style scoped></style>
