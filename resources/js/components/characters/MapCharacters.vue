<script setup lang="ts">
import Character from '@/components/characters/Character.vue';
import MapPanel from '@/components/ui/map-panel/MapPanel.vue';
import MapPanelContent from '@/components/ui/map-panel/MapPanelContent.vue';
import MapPanelHeader from '@/components/ui/map-panel/MapPanelHeader.vue';
import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import { useJumpCounts } from '@/composables/useJumpCounts';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import type { TMap, TResolvedSelectedMapSolarsystem, TResolvedSolarsystem } from '@/pages/maps';
import { TCharacter } from '@/types/models';
import { computed, onMounted, reactive } from 'vue';
import usePermission from '@/composables/usePermission';
import { useMapPresent } from '@/composables/useMapPresence';
import { groupByPerson, usePilotNames } from '@/composables/usePilotNames';
import { router, usePage } from '@inertiajs/vue3';

const { map_characters, map, selected_map_solarsystem, ignored_systems } = defineProps<{
    map_characters: TCharacter[];
    map: TMap;
    selected_map_solarsystem: TResolvedSelectedMapSolarsystem | null;
    ignored_systems: number[];
}>();

const { resolveSolarsystem, getSolarsystemById } = useStaticSolarsystems();
const activeCharacter = useActiveMapCharacter();

const originSolarsystemId = computed(() => {
    return activeCharacter.value?.status?.solarsystem_id ?? selected_map_solarsystem?.solarsystem_id ?? null;
});

const targetIds = computed(() => {
    const ids = new Set<number>();

    for (const character of map_characters) {
        const targetId = character.status?.solarsystem_id;
        if (targetId) {
            ids.add(targetId);
        }
    }

    return [...ids];
});

const { routesByTarget } = useJumpCounts({
    fromId: originSolarsystemId,
    targets: targetIds,
    mapConnections: computed(() => map.map_connections ?? []),
    mapSolarsystems: computed(() => map.map_solarsystems ?? []),
    ignoredSystems: computed(() => ignored_systems ?? []),
});

const resolved_characters = computed(() =>
    map_characters.map((character) => ({
        ...character,
        route: resolveRoute(character.status?.solarsystem_id ?? null),
        static_solarsystem: getSolarsystemById(character.status?.solarsystem_id),
    })),
);

const sorted_characters = computed(() => resolved_characters.value.toSorted(sortCharacters));

function sortCharacters(a: TCharacter, b: TCharacter) {
    if (a.status?.ship_type?.name === 'Capsule' && b.status?.ship_type?.name !== 'Capsule') {
        return 1;
    }
    if (b.status?.ship_type?.name === 'Capsule' && a.status?.ship_type?.name !== 'Capsule') {
        return -1;
    }

    if ((a.status?.station_id || a.status?.structure_id) && !(b.status?.station_id || b.status?.structure_id)) {
        return 1;
    }

    if (!(a.status?.station_id || a.status?.structure_id) && (b.status?.station_id || b.status?.structure_id)) {
        return -1;
    }

    if (isCovertOps(a) && !isCovertOps(b)) {
        return 1;
    }

    if (!isCovertOps(a) && isCovertOps(b)) {
        return -1;
    }

    return a.name.localeCompare(b.name);
}

function isCovertOps(character: TCharacter) {
    return character.status?.ship_type?.group_id === 830;
}

function resolveRoute(targetId: number | null): TResolvedSolarsystem[] {
    if (!targetId) {
        return [];
    }

    const routeResult = routesByTarget.value.get(targetId);

    if (!routeResult) {
        return [];
    }

    return routeResult.route
        .map<TResolvedSolarsystem | null>((step, index) => {
            const solarsystem = resolveSolarsystem(step.id);

            if (!solarsystem) {
                return null;
            }

            return {
                ...solarsystem,
                connection_type: routeResult.route[index + 1]?.via ?? null,
            };
        })
        .filter((entry): entry is TResolvedSolarsystem => entry !== null);
}

// ---- Patch 37: who has the map open (managers only) ----
const { canManageAccess } = usePermission();
const { present } = useMapPresent();
// Viewer links opened lately (the same list as the Share button's).
onMounted(() => {
    if (canManageAccess.value) router.reload({ only: ['share_links'] });
});
const { after: mainSuffix, data: pilotData } = usePilotNames();

/**
 * Patch 39: one row per person ("Tsa · 2 online ▾"); their first character shows, the
 * rest open from the row. Characters nobody claims stay on their own.
 */
const people = computed(() => groupByPerson(sorted_characters.value, (character) => character.name, pilotData.value));
const openPeople = reactive(new Set<string>());
function togglePerson(person: string) {
    if (openPeople.has(person)) openPeople.delete(person);
    else openPeople.add(person);
}
const presentSorted = computed(() => [...present.value].sort((a, b) => a.name.localeCompare(b.name)));
const page = usePage();
type TLinkSeen = { id: number; label: string | null; last_used_at: string | null; kind: string };
const recentLinks = computed(() =>
    (((page.props as unknown as { share_links?: TLinkSeen[] }).share_links ?? []) as TLinkSeen[]).filter(
        (link) => link.last_used_at && Date.now() - Date.parse(link.last_used_at) < 60 * 60_000,
    ),
);
function linkOpened(iso: string | null): string {
    if (!iso) return '';
    const date = new Date(iso);
    return `${String(date.getUTCHours()).padStart(2, '0')}:${String(date.getUTCMinutes()).padStart(2, '0')} EVE`;
}
</script>

<template>
    <MapPanel>
        <MapPanelHeader card-id="characters">
            <span class="inline-flex items-center gap-2">
                <span class="size-2 animate-pulse rounded-full bg-green-500" />
                Pilots
            </span>
            <span class="ml-1 font-mono text-amber-400">{{ sorted_characters.length }}</span>
        </MapPanelHeader>
        <MapPanelContent>
            <!-- Patch 37: managers see everyone who has the map open right now (and viewer links opened lately) -->
            <details v-if="canManageAccess" class="border-b border-border/50 px-3 py-1.5 text-xs" open>
                <summary class="cursor-pointer font-sans text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                    In the mapper <span class="font-mono text-amber-400">{{ present.length }}</span>
                    <span class="ml-1 font-normal normal-case">live</span>
                </summary>
                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                    <span v-for="person in presentSorted" :key="person.id" class="inline-flex items-center gap-1">
                        <template v-if="person.kind === 'link'">
                            <span class="size-1.5 rounded-full bg-amber-500" /><span class="text-muted-foreground">Viewer link "{{ person.name }}"</span>
                        </template>
                        <template v-else>
                            <span class="size-1.5 rounded-full bg-green-500" />{{ person.name
                            }}<span v-if="mainSuffix(person.name)" class="text-muted-foreground"> · {{ mainSuffix(person.name) }}</span>
                            <span class="text-muted-foreground">({{ person.permission ?? '?' }}{{ person.tabs > 1 ? ` · ${person.tabs} tabs` : '' }})</span>
                        </template>
                    </span>
                    <span v-for="link in recentLinks" :key="`link-${link.id}`" class="inline-flex items-center gap-1 text-muted-foreground">
                        <span class="size-1.5 rounded-full bg-amber-500" />Viewer link "{{ link.label ?? 'no label' }}" · opened {{ linkOpened(link.last_used_at) }}
                    </span>
                    <span v-if="!present.length && !recentLinks.length" class="text-muted-foreground">Nobody else right now.</span>
                </div>
            </details>
            <template v-if="sorted_characters?.length">
                <div class="grid grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_auto_minmax(0,1fr)_auto_minmax(0,1fr)] text-sm">
                    <div
                        class="col-span-full grid grid-cols-subgrid items-center gap-2 border-b border-border/50 bg-muted/50 px-3 py-1.5 font-sans font-semibold text-[11px] tracking-wider text-muted-foreground uppercase"
                    >
                        <div></div>
                        <div>Pilot</div>
                        <div>Ship</div>
                        <div></div>
                        <div>Location</div>
                        <div></div>
                        <div class="text-right">Jumps</div>
                    </div>
                    <template v-for="group in people" :key="group.person">
                        <button
                            v-if="group.known && group.items.length > 1"
                            type="button"
                            class="col-span-full flex items-center gap-1.5 border-b border-border/30 bg-muted/20 px-3 py-0.5 text-left text-[11px] hover:bg-muted/40"
                            @click="togglePerson(group.person)"
                        >
                            <span class="font-semibold text-foreground">{{ group.person }}</span>
                            <span class="text-muted-foreground">· {{ group.items.length }} online {{ openPeople.has(group.person) ? '▴' : '▾' }}</span>
                        </button>
                        <TransitionGroup name="list">
                            <Character
                                v-for="character in openPeople.has(group.person) ? group.items : group.items.slice(0, 1)"
                                :key="character.id"
                                :character="character"
                                :static_solarsystem="character.static_solarsystem"
                            />
                        </TransitionGroup>
                    </template>
                </div>
            </template>
            <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-4">
                <p class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/60 uppercase">No pilots online</p>
            </div>
        </MapPanelContent>
    </MapPanel>
</template>

<style scoped>
.list-move, /* apply transition to moving elements */
.list-enter-active,
.list-leave-active {
    transition: all 0.5s ease;
}

.list-enter-from,
.list-leave-to {
    opacity: 0;
    transform: translateX(30px);
}

/* ensure leaving items are taken out of layout flow so that moving
   animations can be calculated correctly. */
.list-leave-active {
    position: absolute;
    opacity: 0;
    transition-duration: 0ms;
}
</style>
