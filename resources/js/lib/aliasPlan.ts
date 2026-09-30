import { signatureCategories } from '@/const/signatures';
import { isWormholeClass } from '@/const/solarsystemClasses';
import { planSignatureAliases, TAliasScheme } from '@/lib/alias';
import { TSignature, TStringedSolarsystemClass } from '@/types/models';

/**
 * Planned chain aliases for every unjumped wormhole signature in one system
 * (signature id → alias), so each hole gets its own number before anyone jumps
 * it. The system's statics come first. See `planSignatureAliases`.
 */
export function planAliasesForSystem(params: {
    signatures: TSignature[] | null | undefined;
    system: {
        alias?: string | null;
        solarsystem?: { class?: TStringedSolarsystemClass | null; statics?: { name: string }[] | null } | null;
    } | null | undefined;
    aliases: string[];
    formats: { bookmark_alias_scheme?: TAliasScheme; bookmark_ignored_alias?: string };
}): Map<number, string> {
    const { signatures, system, aliases, formats } = params;
    if (!signatures?.length || !system) return new Map();

    const wormholeCategoryId = signatureCategories.find((category) => category.name === 'Wormhole')?.id;

    return planSignatureAliases({
        parentAlias: system.alias,
        originIsWormhole: isWormholeClass(system.solarsystem?.class),
        staticNames: (system.solarsystem?.statics ?? []).map((wormholeStatic) => wormholeStatic.name),
        aliases,
        scheme: formats.bookmark_alias_scheme,
        ignoredAlias: formats.bookmark_ignored_alias,
        signatures: signatures.map((signature) => {
            const targetClass = signature.signature_type?.target_class ?? null;
            const knownClass = targetClass && targetClass !== 'unknown' ? targetClass : null;
            return {
                id: signature.id,
                isWormhole: wormholeCategoryId !== undefined && signature.signature_category_id === wormholeCategoryId,
                isConnected: Boolean(signature.map_connection_id),
                wormholeName: signature.wormhole?.name ?? null,
                targetIsWormhole: !knownClass || isWormholeClass(knownClass as TStringedSolarsystemClass),
                targetClass: knownClass,
            };
        }),
    });
}
