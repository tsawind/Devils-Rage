import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { computed } from 'vue';

/**
 * How the background image is laid out:
 * - `grid`: stretched across the whole map grid; pans and zooms with the systems.
 * - `viewport`: sized to the visible map panel and kept static while panning/zooming.
 */
export type TMapBackgroundMode = 'grid' | 'viewport';

/**
 * The map background is persisted in the user's map settings, so it follows them
 * across devices. Authenticated users can upload an image; guests cannot persist
 * one and simply see no background.
 */
export function useMapBackground() {
    const settings = useMapUserSettings();

    const canUpload = computed(() => Boolean(settings.value.user_id));
    const backgroundImageUrl = computed<string>(() => settings.value.background_image_url ?? '');
    const backgroundMode = computed<TMapBackgroundMode>(() => settings.value.background_image_mode ?? 'grid');

    // Patch 37: the pilot's choice, and the map's default (set by managers) and their own picture.
    const backgroundChoice = computed<'map' | 'none' | 'own'>(() => settings.value.background_choice ?? (settings.value.own_background_image_url ? 'own' : 'map'));
    const ownBackgroundUrl = computed<string>(() => settings.value.own_background_image_url ?? '');
    const mapBackgroundUrl = computed<string>(() => settings.value.map_background_image_url ?? '');
    const mapBackgroundMode = computed<TMapBackgroundMode>(() => settings.value.map_background_image_mode ?? 'viewport');

    return {
        backgroundImageUrl,
        backgroundMode,
        canUpload,
        backgroundChoice,
        ownBackgroundUrl,
        mapBackgroundUrl,
        mapBackgroundMode,
    };
}
