import { elementAt, pathOf, showsCopiesFrom } from '@/lib/dialogBus';
import { describe, expect, it } from 'vitest';

/**
 * Patch 54 (step 2): a click in another window's copy of a dialog is found again in the real
 * dialog by its place in the tree (child indexes), the same in both.
 */
type TFake = { children: TFake[]; parentElement: TFake | null; name: string };
function tree(name: string, ...kids: TFake[]): TFake {
    const node: TFake = { name, children: kids, parentElement: null };
    for (const kid of kids) kid.parentElement = node;
    return node;
}

describe('patch 54: dialog copies across windows', () => {
    const keep = tree('keep');
    const rename = tree('rename');
    const root = tree('dialog', tree('title'), tree('buttons', keep, tree('spacer'), rename));

    it('finds a button by its place in the dialog, and back', () => {
        const path = pathOf(root as unknown as Element, rename as unknown as Element);
        expect(path).toEqual([1, 2]);
        expect((elementAt(root as unknown as Element, path!) as unknown as TFake).name).toBe('rename');
        expect((elementAt(root as unknown as Element, [1, 0]) as unknown as TFake).name).toBe('keep');
    });

    it('gives up on elements outside the dialog or paths that lead nowhere', () => {
        expect(pathOf(root as unknown as Element, tree('elsewhere') as unknown as Element)).toBeNull();
        expect(elementAt(root as unknown as Element, [5])).toBeNull();
    });

    it('takes no part outside the desktop app', () => {
        expect(showsCopiesFrom('main')).toBe(false);
        expect(showsCopiesFrom('host')).toBe(false);
    });
});
