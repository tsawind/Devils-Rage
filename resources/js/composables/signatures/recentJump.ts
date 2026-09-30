import { ref } from 'vue';

/** Your most recent jump as the mapper saw it (EVE system ids), for the return-hole auto-link window. */
export type TRecentJump = {
    fromSolarsystemId: number;
    toSolarsystemId: number;
    /** When the mapper noticed the jump (ms since epoch). */
    at: number;
};

export const recentJump = ref<TRecentJump | null>(null);

export function recordJump(fromSolarsystemId: number, toSolarsystemId: number): void {
    recentJump.value = { fromSolarsystemId, toSolarsystemId, at: Date.now() };
}
