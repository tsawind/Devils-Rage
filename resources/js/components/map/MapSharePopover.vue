<script setup lang="ts">
import MapShareLinkController from '@/actions/App/Http/Controllers/MapShareLinkController';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import usePermission from '@/composables/usePermission';
import { usePilotNames } from '@/composables/usePilotNames';
import { router, usePage } from '@inertiajs/vue3';
import { Copy, Infinity as InfinityIcon, Share2, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 37: share the map read only. Members make links for 1 h – 7 days and see / remove
 * their own; managers see every live link, add or cut time, and make permanent ones. Links
 * that run out are deleted. `inline`: shown as a panel (map settings) instead of a popover.
 */
type TShareLink = {
    id: number;
    kind: 'map' | 'route';
    label: string | null;
    url: string;
    created_by_name: string | null;
    mine: boolean;
    expires_at: string | null;
    permanent: boolean;
    last_used_at: string | null;
    uses: number;
};

const { mapSlug, inline = false } = defineProps<{ mapSlug: string; inline?: boolean }>();

const page = usePage();
const { canManageAccess: canManage } = usePermission();
const { label: pilotName } = usePilotNames();

const links = computed(() => ((page.props as unknown as { share_links?: TShareLink[] }).share_links ?? []) as TShareLink[]);
const hours = ref(12);
const permanent = ref(false);
const linkLabel = ref('');
const busy = ref(false);
const MAX_HOURS = 168;

function load(): void {
    router.reload({ only: ['share_links'] });
}

function copy(url: string): void {
    navigator.clipboard.writeText(url).then(
        () => toast.success('Link copied', { description: url }),
        () => toast.info('Copy the link', { description: url, duration: 20_000 }),
    );
}

function create(): void {
    busy.value = true;
    const before = new Set(links.value.map((link) => link.id));
    router.post(
        MapShareLinkController.store(mapSlug).url,
        { hours: Math.min(Math.max(Math.round(hours.value), 1), MAX_HOURS), permanent: permanent.value, label: linkLabel.value || null },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['share_links'],
            onSuccess: () => {
                const made = links.value.find((link) => !before.has(link.id) && link.mine);
                if (made) copy(made.url);
                linkLabel.value = '';
            },
            onFinish: () => (busy.value = false),
        },
    );
}

function addHours(link: TShareLink, add: number): void {
    router.patch(MapShareLinkController.update(link.id).url, { add_hours: add }, { preserveScroll: true, preserveState: true, only: ['share_links'] });
}

function makePermanent(link: TShareLink, value: boolean): void {
    router.patch(MapShareLinkController.update(link.id).url, { permanent: value }, { preserveScroll: true, preserveState: true, only: ['share_links'] });
}

function remove(link: TShareLink): void {
    router.delete(MapShareLinkController.destroy(link.id).url, { preserveScroll: true, preserveState: true, only: ['share_links'] });
}

function endsIn(link: TShareLink): string {
    if (link.permanent || !link.expires_at) return 'permanent';
    const minutes = Math.max(0, Math.round((Date.parse(link.expires_at) - Date.now()) / 60_000));
    if (minutes < 60) return `ends in ${minutes} min`;
    const hoursLeft = Math.floor(minutes / 60);
    return hoursLeft < 24 ? `ends in ${hoursLeft} h ${minutes % 60} min` : `ends in ${Math.floor(hoursLeft / 24)} d ${hoursLeft % 24} h`;
}

const presets = [2, 12, 24, 168];
</script>

<template>
    <div v-if="inline" class="space-y-3">
        <slot name="before" />
        <div class="space-y-2 text-sm">
            <p class="text-muted-foreground">Anyone with a link can see the map, read only, until it runs out. Then it's deleted.</p>
            <div class="flex flex-wrap items-center gap-2">
                <span>For</span>
                <input v-model.number="hours" type="number" min="1" :max="MAX_HOURS" :disabled="permanent" class="h-8 w-20 rounded-md border bg-background px-2" />
                <span>hours</span>
                <Button v-for="preset in presets" :key="preset" size="sm" variant="outline" class="h-7 px-2 text-xs" :disabled="permanent" @click="hours = preset">
                    {{ preset < 24 ? `${preset} h` : preset === 24 ? '1 day' : '7 days' }}
                </Button>
                <label v-if="canManage" class="flex items-center gap-1 text-xs"><input v-model="permanent" type="checkbox" /> Permanent</label>
            </div>
            <input v-model="linkLabel" type="text" maxlength="80" placeholder="Who it's for (Gorgon FC)" class="h-8 w-full rounded-md border bg-background px-2" />
            <Button size="sm" :disabled="busy" @click="create">Create and copy link</Button>
        </div>
        <div class="space-y-1">
            <div class="flex items-center justify-between text-xs font-medium text-muted-foreground">
                <span>{{ canManage ? 'Live viewer links' : 'Your viewer links' }}</span>
                <button type="button" class="underline" @click="load">refresh</button>
            </div>
            <p v-if="!links.length" class="text-xs text-muted-foreground">None.</p>
            <div v-for="link in links" :key="link.id" class="flex flex-wrap items-center justify-between gap-1 border-t py-1.5 text-xs">
                <span class="min-w-0">
                    <span class="font-medium">{{ link.label || (link.kind === 'route' ? 'Route report' : 'Viewer link') }}</span>
                    <span class="text-muted-foreground">
                        · {{ link.kind === 'route' ? 'route only' : 'whole map' }} · by {{ pilotName(link.created_by_name) }} · {{ endsIn(link) }}<template v-if="link.uses">
                            · opened {{ link.uses }}×</template
                        ></span
                    >
                </span>
                <span class="flex items-center gap-1">
                    <Button size="sm" variant="ghost" class="h-6 px-1.5" title="Copy the link" @click="copy(link.url)"><Copy class="size-3.5" /></Button>
                    <template v-if="canManage">
                        <Button size="sm" variant="outline" class="h-6 px-1.5 text-[11px]" :disabled="link.permanent" @click="addHours(link, -1)">−1 h</Button>
                        <Button size="sm" variant="outline" class="h-6 px-1.5 text-[11px]" :disabled="link.permanent" @click="addHours(link, 1)">+1 h</Button>
                        <Button size="sm" variant="outline" class="h-6 px-1.5 text-[11px]" :disabled="link.permanent" @click="addHours(link, 24)">+1 day</Button>
                        <Button size="sm" variant="outline" class="h-6 px-1.5 text-[11px]" :title="link.permanent ? 'Give it an end time (12 h)' : 'Make it permanent'" @click="makePermanent(link, !link.permanent)">
                            <InfinityIcon class="size-3.5" />
                        </Button>
                    </template>
                    <Button v-if="canManage || link.mine" size="sm" variant="ghost" class="h-6 px-1.5 text-red-500" title="Remove the link" @click="remove(link)">
                        <Trash2 class="size-3.5" />
                    </Button>
                </span>
            </div>
        </div>
    </div>
    <Popover v-else @update:open="(value: boolean) => value && load()">
        <PopoverTrigger as-child>
            <button type="button" class="flex items-center rounded border border-border/50 bg-background p-1.5 text-muted-foreground hover:border-border hover:text-foreground" aria-label="Share the map (viewer link)" title="Share the map (viewer link)">
                <Share2 class="size-4" />
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-[26rem] max-w-[95vw]" align="start">
            <p class="mb-2 text-sm font-semibold">Share (view only)</p>
            <MapSharePopover :map-slug="mapSlug" inline />
        </PopoverContent>
    </Popover>
</template>
