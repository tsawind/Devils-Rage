<script setup lang="ts">
import { CharacterImage } from '@/components/images';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/user/UserMenuContent.vue';
import { useMapChrome } from '@/composables/useMapChrome';
import useUser from '@/composables/useUser';

/** Patch 23: with the site header folded away, your character floats as a pill, top-right of the map. */
const { barsFolded } = useMapChrome();
const user = useUser();
</script>

<template>
    <div v-if="barsFolded && user" class="absolute top-2 right-2 z-40">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-full border border-border/60 bg-card/90 py-0.5 pr-3 pl-1 text-xs shadow-md backdrop-blur hover:bg-muted"
                >
                    <CharacterImage
                        v-if="user.active_character"
                        :character_id="user.active_character.id"
                        :character_name="user.active_character.name"
                        class="size-6 rounded-full"
                    />
                    <span class="max-w-28 truncate font-medium">{{ user.active_character?.name }}</span>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-56">
                <UserMenuContent :user="user" />
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
