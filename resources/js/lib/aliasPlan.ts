import { signatureCategories } from '@/const/signatures';
import { isWormholeClass } from '@/const/solarsystemClasses';
import { planSignatureAliases, TAliasScheme } from '@/lib/alias';
import { TSignature, TStringedSolarsystemClass } from '@/types/models';

/** Whether a signature is categorised as a wormhole, whichever shape the data arrived in. */
export function isWormholeSignature(signature: Pick<TSignature, 'signature_category_id'> & { signature_category?: { code?: string; name?: string } | null }): boolean {
    const category = signature.signature_category;
    if (category?.code === 'wormhole' || category?.name === 'Wormhole') return true;

    const wormholeCategoryId = signatureCategories.find((candidate) => candidate.code === 'wormhole')?.id;
    return wormholeCategoryId !== undefined && signature.signature_category_id === wormholeCategoryId;
}

/**
 * Chain numbers for every wormhole signature in one system (signature id →
 * alias): locked numbers are kept, the hole marked Static takes the static slot, the
 * rest get the lowest free slot from 2 up. See `planSignatureAliases`.
 */
export function planAliasesForSystem(params: {
    signatures: TSignature[] | null | undefined;
    system:
        | {
              alias?: string | null;
              solarsystem?: { class?: TStringedSolarsystemClass | null } | null;
          }
        | null
        | undefined;
    aliases: string[];
    formats: { bookmark_alias_scheme?: TAliasScheme; bookmark_ignored_alias?: string };
}): Map<number, string> {
    const { signatures, system, aliases, formats } = params;
    if (!signatures?.length || !system) return new Map();

    return planSignatureAliases({
        parentAlias: system.alias,
        originIsWormhole: isWormholeClass(system.solarsystem?.class),
        aliases,
        scheme: formats.bookmark_alias_scheme,
        ignoredAlias: formats.bookmark_ignored_alias,
        signatures: signatures.map((signature) => {
            const targetClass = signature.signature_type?.target_class ?? null;
            const knownClass = targetClass && targetClass !== 'unknown' ? targetClass : null;
            return {
                id: signature.id,
                isWormhole: isWormholeSignature(signature),
                isConnected: Boolean(signature.map_connection_id),
                lockedAlias: signature.alias ?? null,
                isStatic: Boolean(signature.is_static),
                targetIsWormhole: !knownClass || isWormholeClass(knownClass as TStringedSolarsystemClass),
                targetClass: knownClass,
            };
        }),
    });
}
