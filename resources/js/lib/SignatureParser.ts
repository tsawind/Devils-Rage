import { getTypesByCategory, signatureCategories, signatureCategoryByCode } from '@/const/signatures';
import { distanceFromScanRow, TScanDistance } from '@/lib/returnHole';
import { TSignatureCategory, TSignatureType } from '@/types/models';
import { UTCDate } from '@date-fns/utc';
import { signatureToast as toast } from '@/lib/signatureToast';

export type TRawSignature = {
    signature_id: string;
    signature_category_id: number | null;
    signature_type_id: number | null;
    raw_type_name: string | null;
    created_at?: string;
    /** Distance from the probe scanner (kept on the page only, never sent to the server). */
    distance?: TScanDistance | null;
    /** Patch 15: signal strength in % ("100.0%" → 100), page only. */
    signal?: number | null;
};

/** "100.0%", "45,2 %" → 100, 45.2; anything else → null. */
export function parseSignal(value: string | null | undefined): number | null {
    const match = /^\s*([\d.,]+)\s*%\s*$/.exec(value ?? '');
    if (!match) return null;
    const amount = Number.parseFloat(match[1].replace(',', '.'));
    return Number.isFinite(amount) ? amount : null;
}

/**
 * Patch 20: a probe scanner row that isn't a cosmic signature or anomaly
 * (its scan group, the second column, is Ship, Deployable, Structure, Drone…).
 * Rows with no scan group (older or hand-made pastes) are kept.
 */
export function isNonSignatureRow(row: readonly string[]): boolean {
    const group = (row[1] ?? '').trim();
    if (!group) return false;
    return !/signat|anomal|аномал|сигнат/i.test(group);
}

class SignatureParser {
    parseSignatures(text: string): TRawSignature[] {
        if (!text) {
            return [] satisfies TRawSignature[];
        }

        const rows = text
            .split('\n')
            .filter((line) => line.trim() !== '')
            .map((sig) => sig.split('\t'));
        // Patch 20: ships, deployables, structures, drones… in the probe scanner are not signatures.
        const kept = rows.filter((row) => !isNonSignatureRow(row));
        const skipped = rows.length - kept.length;
        if (skipped > 0) toast.info(`Ignored ${skipped} ship${skipped === 1 ? '' : 's'} / deployable${skipped === 1 ? '' : 's'}`, { description: 'Only cosmic signatures and anomalies are kept.' });

        return kept.map((sig) => this.parseSignature(sig)).filter((sig): sig is TRawSignature => sig !== null);
    }

    parseSignature(signature: string[]): TRawSignature | null {
        if (signature.length < 4) {
            toast.error('Invalid signature format. Expected at least 4 tab-separated values.');
            return null;
        }

        const [signature_id, group, category_name, type_name] = signature;

        if (!signature_id) {
            toast.error('Invalid signature format. Signature ID is required.');
            return null;
        }

        const signature_category = this.scannableIfProbed(this.getCategory(category_name), group);
        const signature_type = this.getType(signature_category, type_name);

        // Store the raw type name if we have a category but no matching type
        // This captures temporary event sites that aren't in the database
        const raw_type_name = signature_category && !signature_type && type_name?.trim() ? type_name.trim() : null;

        return {
            signature_id: signature_id.trim(),
            signature_category_id: signature_category?.id || null,
            signature_type_id: signature_type?.id || null,
            raw_type_name,
            created_at: new UTCDate().toISOString(),
            distance: distanceFromScanRow(signature.slice(4)),
            signal: signature.slice(4).map(parseSignal).find((value) => value !== null) ?? null,
        };
    }

    getCategory(categoryName: string): TSignatureCategory | null {
        const name = categoryName?.trim();
        const exact = signatureCategories.find((cat) => cat.name === name);
        if (exact) {
            return exact;
        }

        // Faction Warfare sites paste as e.g. "Factional Warfare Site - Combat Site",
        // so fall back to matching a known category in any " - " separated segment.
        return (
            name
                ?.split(' - ')
                .map((segment) => signatureCategories.find((cat) => cat.name === segment.trim()))
                .find(Boolean) || null
        );
    }

    /**
     * Patch 23: a combat site in the "Cosmic Signature" group had to be probed down
     * (anomalies are "Cosmic Anomaly"): it is a scannable combat site.
     */
    scannableIfProbed(category: TSignatureCategory | null, group: string | undefined): TSignatureCategory | null {
        if (category?.code !== 'combat' || !/signat|сигнат/i.test(group ?? '')) return category;
        return signatureCategoryByCode.get('scannable-combat') ?? category;
    }

    getType(category: TSignatureCategory | null, typeName: string): TSignatureType | null {
        if (!category) {
            return null;
        }
        if (category.name === 'Wormhole') {
            return null;
        }

        return getTypesByCategory(category.id).find((type) => type.name === typeName.trim()) || null;
    }
}

export const signatureParser = new SignatureParser();
