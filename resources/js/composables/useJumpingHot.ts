import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { useShowMap } from '@/composables/useShowMap';
import { updateMapUserSettings } from '@/map/actions/updateMapUserSettings';
import { computed, ref, watch } from 'vue';

/**
 * Patch 37: the JUMPING HOT switch: one per person, for all your characters. Jumps you log
 * count hot (prop on) or cold from it, and the rolling plans use it. Saved with your map
 * settings; flips at once on screen.
 */
const local = ref<boolean | null>(null);
/** The switch as last seen by any component (for code outside components, like Will it hold). */
export const jumpingHotNow = ref(false);

export function useJumpingHot() {
    const settings = useMapUserSettings();
    const page = useShowMap();
    watch(
        () => settings.value.jumping_hot,
        () => (local.value = null),
    );
    const hot = computed(() => local.value ?? Boolean(settings.value.jumping_hot));
    watch(hot, (value) => (jumpingHotNow.value = value), { immediate: true });
    function setHot(value: boolean): void {
        local.value = value;
        updateMapUserSettings(page.props.map.slug, { jumping_hot: value }, ['map_user_settings']);
    }
    return { hot, setHot, toggle: () => setHot(!hot.value) };
}
