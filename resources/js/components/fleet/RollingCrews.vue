<script setup lang="ts">
import { fleetMembers } from '@/composables/useFleet';
import { massState, useMassSessions, type TCrewMember, type TRollingCrew } from '@/composables/useMassSessions';
import useUser from '@/composables/useUser';
import { computed, ref } from 'vue';

/**
 * Patch 37: rolling crews. Each person makes their own (any characters, in fleet or not, in
 * their ISBoxer order; ⚠ expendable ones may gamble when a session is in a hurry; "can swap
 * pilots" lets a polarized pilot's ship go on with a free one). Everyone above viewer sees
 * and uses them; only the owner changes them.
 */
const { mapSlug } = defineProps<{ mapSlug: string }>();
const api = useMassSessions(mapSlug);
const user = useUser();

type TDraft = { id: number | null; name: string; can_swap: boolean; members: TCrewMember[] };
const draft = ref<TDraft | null>(null);
const byName = ref('');
const pick = ref<string>('');
const dragFrom = ref<number | null>(null);

const mine = computed(() => massState.value.crews.filter((crew) => crew.user_id === user.value?.id));
const others = computed(() => massState.value.crews.filter((crew) => crew.user_id !== user.value?.id));

/** Characters to add: my own, then the fleet, without the ones already in. */
const candidates = computed(() => {
    const taken = new Set(draft.value?.members.map((member) => member.character_id) ?? []);
    const list = [
        ...(user.value?.characters ?? []).map((character) => ({ character_id: character.id, name: character.name, from: 'mine' })),
        ...fleetMembers.value.map((member) => ({ character_id: member.character_id, name: member.name, from: 'fleet' })),
    ];
    const seen = new Set<number>();
    return list.filter((row) => !taken.has(row.character_id) && !seen.has(row.character_id) && seen.add(row.character_id));
});

function edit(crew: TRollingCrew | null): void {
    draft.value = crew ? { id: crew.id, name: crew.name, can_swap: crew.can_swap, members: crew.members.map((member) => ({ ...member })) } : { id: null, name: 'My rollers', can_swap: false, members: [] };
}

function add(member: { character_id: number; name: string }): void {
    draft.value?.members.push({ character_id: member.character_id, name: member.name, expendable: false });
}

function addPicked(): void {
    const row = candidates.value.find((candidate) => String(candidate.character_id) === pick.value);
    if (row) add(row);
    pick.value = '';
}

async function addByName(): Promise<void> {
    const name = byName.value.trim();
    if (!name) return;
    const found = await api.lookup(name);
    if (found) {
        add(found);
        byName.value = '';
    } else {
        byName.value = `${name} (not known)`;
    }
}

function drop(to: number): void {
    const from = dragFrom.value;
    dragFrom.value = null;
    if (!draft.value || from === null || from === to) return;
    const [moved] = draft.value.members.splice(from, 1);
    draft.value.members.splice(to, 0, moved);
}

async function save(): Promise<void> {
    if (draft.value && (await api.saveCrew(draft.value))) draft.value = null;
}

async function remove(crew: TRollingCrew): Promise<void> {
    await api.removeCrew(crew.id);
    if (draft.value?.id === crew.id) draft.value = null;
}
</script>

<template>
    <div class="grid min-w-0 gap-3 text-xs">
        <div v-if="draft" class="grid gap-1.5 rounded-md border border-purple-500/60 p-2">
            <div class="flex flex-wrap items-center gap-2">
                <input v-model="draft.name" maxlength="60" class="min-w-0 flex-1 rounded border border-border bg-background px-2 py-1" placeholder="Crew name" />
                <label class="flex items-center gap-1.5" title="A polarized pilot's ship goes on with a free pilot (juggling)">
                    <input v-model="draft.can_swap" type="checkbox" /> can swap pilots
                </label>
            </div>
            <ol class="grid gap-0.5 font-mono">
                <li
                    v-for="(member, index) in draft.members"
                    :key="member.character_id"
                    draggable="true"
                    class="flex items-center gap-2 rounded px-1 hover:bg-muted/50"
                    @dragstart="dragFrom = index"
                    @dragover.prevent
                    @drop="drop(index)"
                >
                    <span class="cursor-grab text-muted-foreground">⋮⋮</span>
                    <span class="w-4 text-right text-muted-foreground">{{ index + 1 }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ member.name }}</span>
                    <button
                        type="button"
                        class="rounded px-1"
                        :class="member.expendable ? 'text-amber-400' : 'text-muted-foreground/50 hover:text-muted-foreground'"
                        title="Expendable: may gamble when a session is in a hurry"
                        @click="member.expendable = !member.expendable"
                    >
                        ⚠
                    </button>
                    <button type="button" class="text-muted-foreground hover:text-foreground" @click="draft.members.splice(index, 1)">✕</button>
                </li>
            </ol>
            <div class="flex flex-wrap items-center gap-1.5">
                <select v-model="pick" class="min-w-0 rounded border border-border bg-background px-1 py-0.5" @change="addPicked">
                    <option value="">+ Add mine / from fleet…</option>
                    <option v-for="row in candidates" :key="row.character_id" :value="String(row.character_id)">{{ row.name }} ({{ row.from }})</option>
                </select>
                <input v-model="byName" class="min-w-0 flex-1 rounded border border-border bg-background px-2 py-0.5" placeholder="Add by exact name" @keydown.enter="addByName" />
                <button type="button" class="rounded border border-border px-2 py-0.5" @click="addByName">Add</button>
            </div>
            <div class="flex gap-1.5">
                <button type="button" class="rounded border border-purple-500 px-2 py-0.5 font-semibold text-purple-200" :disabled="!draft.name.trim()" @click="save">Save crew</button>
                <button type="button" class="rounded border border-border px-2 py-0.5" @click="draft = null">Cancel</button>
            </div>
            <p class="text-[11px] text-muted-foreground">Drag to your ISBoxer order. Masses come from each pilot's ship profile (Ship profiles tab).</p>
        </div>

        <div class="grid gap-2">
            <div class="flex items-center gap-2">
                <span class="font-semibold">Your crews</span>
                <button v-if="!draft" type="button" class="ml-auto rounded border border-border px-2 py-0.5" @click="edit(null)">+ New crew</button>
            </div>
            <p v-if="!mine.length" class="text-muted-foreground">None yet.</p>
            <div v-for="crew in mine" :key="crew.id" class="grid gap-0.5">
                <div class="flex items-center gap-2">
                    <span class="font-semibold">{{ crew.name }}</span>
                    <span class="text-muted-foreground">· {{ crew.members.length }}<template v-if="crew.can_swap"> · swaps pilots</template></span>
                    <button type="button" class="ml-auto text-muted-foreground hover:text-foreground" @click="edit(crew)">Edit</button>
                    <button type="button" class="text-muted-foreground hover:text-red-400" @click="remove(crew)">Delete</button>
                </div>
                <div class="font-mono text-[11px] text-muted-foreground">
                    <span v-for="(member, index) in crew.members" :key="member.character_id" class="mr-2 inline-block">
                        {{ index + 1 }} {{ member.name }}<span v-if="member.expendable" class="text-amber-400"> ⚠</span><span v-if="member.ship"> · {{ member.ship }}</span>
                    </span>
                </div>
            </div>
        </div>

        <div v-if="others.length" class="grid gap-2">
            <span class="font-semibold">Everyone else's</span>
            <div v-for="crew in others" :key="crew.id" class="grid gap-0.5">
                <div>
                    <span class="font-semibold">{{ crew.name }}</span>
                    <span class="text-muted-foreground"> · {{ crew.owner_name ?? 'someone' }} · {{ crew.members.length }}<template v-if="crew.can_swap"> · swaps pilots</template></span>
                </div>
                <div class="font-mono text-[11px] text-muted-foreground">
                    <span v-for="(member, index) in crew.members" :key="member.character_id" class="mr-2 inline-block">
                        {{ index + 1 }} {{ member.name }}<span v-if="member.expendable" class="text-amber-400"> ⚠</span><span v-if="member.ship"> · {{ member.ship }}</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
