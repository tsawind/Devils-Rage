import { shallowRef } from 'vue';

/**
 * Patch 39: double-click a dotted box or a system on the map to rename it (the hole leading
 * to it and the system it leads to). `parentId` holds the hole; `targetId` is set when the
 * hole was jumped. A system with no hole leading to it (home, a side chain start) is renamed
 * by itself (`signatureId` null).
 */
export type TMapRenameRequest = { parentId: number | null; signatureId: number | null; targetId: number | null };

export const mapRenameRequest = shallowRef<TMapRenameRequest | null>(null);

export function requestMapRename(request: TMapRenameRequest): void {
    mapRenameRequest.value = request;
}
