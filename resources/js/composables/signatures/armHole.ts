import { displayAlias, type TAliasScheme } from '@/lib/alias';
import { buildSignatureBookmark, visibleBookmarkName, type TBookmarkFormats } from '@/lib/bookmark';
import { armSignature } from '@/map/actions/arm';
import type { TStringedSolarsystemClass } from '@/types/models';
import { signatureToast as toast } from '@/lib/signatureToast';
import { autoCopy, clipboardAllowed, copyButton } from '@/composables/useClipboardSetting';

/**
 * Patch 13: arm a hole as your next jump, from the signature list, a paste or
 * the map. Copies its bookmark name (with the number it is armed as) and says
 * so: "Armed LIH → 1 · copied 1 LIH C2". Your next jump is linked to it with
 * no prompt.
 */
export function armHole(params: {
    signature: Parameters<typeof buildSignatureBookmark>[0]['signature'] & { id: number };
    system: { alias?: string | null; class?: TStringedSolarsystemClass | null; combatHome?: boolean; combatColor?: string | null };
    /** Numbers used in the system's chain. */
    aliases: string[];
    formats: TBookmarkFormats & { bookmark_alias_scheme?: TAliasScheme };
    /** The number to arm it as. */
    alias: string;
    /** For a swap: the number the other scanner gets when this hole has none yet. */
    fallbackAlias?: string | null;
    swap?: boolean;
}): void {
    const name = buildSignatureBookmark({
        signature: params.signature,
        currentSystem: params.system,
        aliases: params.aliases,
        formats: params.formats,
        plannedAlias: params.alias,
    });
    const copying = Boolean(name) && clipboardAllowed();
    if (copying) void autoCopy(name);

    const short = params.signature.signature_id?.slice(0, 3) ?? 'the hole';
    armSignature(
        params.signature.id,
        { alias: params.alias, fallback_alias: params.fallbackAlias ?? null, swap: params.swap ?? false },
        () =>
            toast.success(`Armed ${short} → ${displayAlias(params.alias, params.formats.bookmark_alias_scheme)}`, {
                description: name
                    ? copying
                        ? `Copied ${visibleBookmarkName(name)}. Bookmark it, then jump.`
                        : `Bookmark it as ${visibleBookmarkName(name)}, then jump.`
                    : 'Bookmark it, then jump.',
                ...(name && !copying ? { action: copyButton(name), duration: 20_000 } : {}),
            }),
    );
}
