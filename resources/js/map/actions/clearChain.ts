import { ref } from 'vue';

export { planChainClear } from '@/lib/chainClear';

/** The combat chain the "Clear chain" confirmation is open for (patch 12), or null. */
export const clear_chain_color = ref<string | null>(null);

/** Ask to clear a combat chain (right-click the map → Clear Red chain). */
export function openClearChain(color: string): void {
    clear_chain_color.value = color;
}
