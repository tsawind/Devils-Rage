<script setup lang="ts">
import AnnouncementBanner from '@/components/announcement/AnnouncementBanner.vue';
import { TooltipProvider } from '@/components/ui/tooltip';
import Notifications from '@/components/user/Notifications.vue';
import AppContent from '@/layouts/AppContent.vue';
import AppHeader from '@/layouts/AppHeader.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
    /** Patch 23: the map page folds the site header away for more room. */
    hideHeader?: boolean;
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    hideHeader: false,
});
</script>

<template>
    <TooltipProvider>
        <div class="flex flex-col">
            <AnnouncementBanner />
            <AppHeader v-show="!hideHeader" />
            <AppContent>
                <slot />
            </AppContent>
            <Notifications />
        </div>
    </TooltipProvider>
</template>
