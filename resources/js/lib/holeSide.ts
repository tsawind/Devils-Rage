/**
 * Patch 14: which end of a jumped connection a hole type belongs to. A normal
 * (non-K162) hole spawns in one system and leads to the other's class; its far
 * end is the K162. E.g. N432 spawns in nullsec and leads to C4/C5, so on a
 * C5 ↔ nullsec connection it's the nullsec side.
 */
export type THoleEnd = { id: number; class: string | null | undefined };
export type THoleType = { spawn_areas?: readonly string[] | null; target_class?: string | null };

/** The side the hole spawned on, and whether the type fits both ends (spawns on one, leads to the other's class). */
export function holeSide(type: THoleType, a: THoleEnd, b: THoleEnd): { sideId: number; fits: boolean } | null {
    const spawnsIn = (end: THoleEnd): boolean => Boolean(end.class && type.spawn_areas?.includes(end.class));
    const leadsTo = (end: THoleEnd): boolean => Boolean(end.class && type.target_class && type.target_class === end.class);
    if (spawnsIn(a) && leadsTo(b)) return { sideId: a.id, fits: true };
    if (spawnsIn(b) && leadsTo(a)) return { sideId: b.id, fits: true };
    // Not a clean fit (the data may be incomplete): the side it can spawn on, else the side it leads away from.
    if (spawnsIn(a) && !spawnsIn(b)) return { sideId: a.id, fits: false };
    if (spawnsIn(b) && !spawnsIn(a)) return { sideId: b.id, fits: false };
    if (leadsTo(b) && !leadsTo(a)) return { sideId: a.id, fits: false };
    if (leadsTo(a) && !leadsTo(b)) return { sideId: b.id, fits: false };
    return null;
}
