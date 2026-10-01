import { ref } from 'vue';

/** The connection the "Add mass" box is open for (patch 12), or null when closed. */
export type TAddMassTarget = { connectionId: number; label: string };

export const add_mass_target = ref<TAddMassTarget | null>(null);

/** Open the "Add mass" box for a connection (right-click a line → Add mass…). */
export function openAddMass(target: TAddMassTarget): void {
    add_mass_target.value = target;
}

/**
 * Mass typed in million kg ("1200" = 1.2 billion kg) to kg, or null when it
 * isn't a positive number. Commas and spaces are ignored ("1,200").
 */
export function parseMillionKg(input: string): number | null {
    const cleaned = input.replace(/[,\s_]/g, '');
    if (!/^\d+(\.\d+)?$/.test(cleaned)) return null;
    const value = Number(cleaned);
    if (!Number.isFinite(value) || value <= 0) return null;
    const kg = Math.round(value * 1_000_000);
    return kg > 100_000_000_000 ? null : kg;
}

/** "1.2 billion kg", "450 million kg". */
export function describeKg(kg: number): string {
    if (kg >= 1_000_000_000) {
        return `${(kg / 1_000_000_000).toLocaleString('en-US', { maximumFractionDigits: 2 })} billion kg`;
    }
    return `${(kg / 1_000_000).toLocaleString('en-US', { maximumFractionDigits: 1 })} million kg`;
}
