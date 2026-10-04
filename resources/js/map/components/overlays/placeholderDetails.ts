import { ref } from 'vue';

/** Patch 21: the dotted pipe whose pill was clicked (its placeholder node id) and where, in viewport pixels. */
export const openPlaceholderDetails = ref<{ nodeId: number; x: number; y: number } | null>(null);
