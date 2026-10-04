import { canRedo, canUndo, recordRestorable, recordUndo, redoLast, resetUndo, undoLabel, undoLast } from '@/composables/undo/mapUndo';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { posts } = vi.hoisted(() => ({ posts: [] as { url: string; data: Record<string, unknown> }[] }));
vi.mock('@inertiajs/vue3', () => ({ router: { post: (url: string, data: Record<string, unknown>) => posts.push({ url, data }), reload: vi.fn() } }));
vi.mock('@/routes/map-undo', () => ({ default: { store: (token: string) => ({ url: `/map-undo/${token}` }) } }));


describe('patch 21: one Undo / Redo for everything', () => {
    beforeEach(() => {
        resetUndo();
        posts.length = 0;
    });

    it('undoes in reverse order and redoes in order', () => {
        const done: string[] = [];
        recordUndo({ label: 'moved a system', undo: () => (done.push('undo move'), true), redo: () => (done.push('redo move'), true) });
        recordUndo({ label: 'deleted A1', undo: () => (done.push('undo delete'), true), redo: () => (done.push('redo delete'), true) });
        expect(undoLabel.value).toBe('deleted A1');

        undoLast();
        undoLast();
        expect(done).toEqual(['undo delete', 'undo move']);
        expect(canUndo.value).toBe(false);

        redoLast();
        expect(done.at(-1)).toBe('redo move');
        expect(undoLabel.value).toBe('moved a system');
    });

    it('a new change clears the redo list', () => {
        recordUndo({ label: 'a', undo: () => true, redo: () => true });
        undoLast();
        expect(canRedo.value).toBe(true);
        recordUndo({ label: 'b', undo: () => true, redo: () => true });
        expect(canRedo.value).toBe(false);
    });

    it('a step that cannot be undone stays off the redo list', () => {
        recordUndo({ label: 'someone changed it', undo: () => false, redo: () => true });
        undoLast();
        expect(canRedo.value).toBe(false);
    });

    it('a paste is put back from the server, saving the state it replaces for Redo', () => {
        recordRestorable('pasted 3 signatures', 'first-token');
        undoLast();
        expect(posts[0].url).toBe('/map-undo/first-token');
        const redoToken = posts[0].data.redo_token as string;
        expect(redoToken).toBeTruthy();

        redoLast();
        expect(posts[1].url).toBe(`/map-undo/${redoToken}`);
        expect(posts[1].data.redo_token).toBeTruthy();
    });

    it('keeps the last 30 steps', () => {
        for (let i = 0; i < 35; i++) recordUndo({ label: `step ${i}`, undo: () => true, redo: () => true });
        let count = 0;
        while (canUndo.value) {
            undoLast();
            count++;
        }
        expect(count).toBe(30);
    });
});
