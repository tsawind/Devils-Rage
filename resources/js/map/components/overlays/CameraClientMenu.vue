<script setup lang="ts">
import { ContextMenuItem, ContextMenuLabel, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger } from '@/components/ui/context-menu';
import { controlCameraClient, useCameraClients, type TCameraClient } from '@/composables/useCameraClients';
import { Check, Video } from 'lucide-vue-next';

/**
 * Patch 62m: right-click a pipe (or a dotted hole): point one of your clients' cameras at it,
 * or turn it off there. The clients are the EVE windows your share window has up.
 */
const { pin } = defineProps<{ pin: string }>();
const { clients } = useCameraClients();
const here = (client: TCameraClient): boolean => client.on && client.pin === pin;

function toggle(client: TCameraClient): void {
    if (here(client)) void controlCameraClient(client.name, { on: false });
    else void controlCameraClient(client.name, { pin, on: true });
}
</script>

<template>
    <ContextMenuSub v-if="clients.length">
        <ContextMenuSubTrigger>
            <Video class="size-4" />
            Camera
            <span v-if="clients.some(here)" class="ml-1 size-1.5 rounded-full bg-red-500" />
        </ContextMenuSubTrigger>
        <ContextMenuSubContent class="w-60">
            <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">Point a client's camera here (it goes on Watching) or turn it off</ContextMenuLabel>
            <ContextMenuItem v-for="client in clients" :key="client.name" class="text-xs" @select="toggle(client)">
                <Check v-if="here(client)" class="size-4 text-red-400" />
                <span v-else class="size-4" />
                {{ client.name }}
                <span class="ml-auto text-[10px] text-muted-foreground">{{ here(client) ? 'on here · turn off' : client.on ? 'move here' : 'turn on here' }}</span>
            </ContextMenuItem>
        </ContextMenuSubContent>
    </ContextMenuSub>
</template>
