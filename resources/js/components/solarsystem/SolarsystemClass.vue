<script setup lang="ts">
import { classMeta } from '@/const/solarsystemClasses';
import { specialClassWord } from '@/lib/specialSystems';
import { TStringedSolarsystemClass } from '@/types/models';
import { computed } from 'vue';

const { solarsystem_class, is_shattered = false } = defineProps<{
    solarsystem_class: TStringedSolarsystemClass;
    /** Patch 39: a shattered C1–C6 reads "C4shattered". */
    is_shattered?: boolean | null;
}>();

const meta = computed(() => classMeta(solarsystem_class));
/** Patch 39: special systems in words (Thera, C13 frig, Vidette, Pochven, C4shattered). */
const label = computed(() => specialClassWord(solarsystem_class, { isShattered: is_shattered }) ?? meta.value.short_label);
</script>

<template>
    <span :class="`text-${meta.color_token}`" :title="label !== meta.short_label ? meta.label : undefined">{{ label }}</span>
</template>
