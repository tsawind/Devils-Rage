import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { useShowMap } from '@/composables/useShowMap';
import { updateMapUserSettings } from '@/map/actions/updateMapUserSettings';
import { computed, ref, toValue, watch, type MaybeRefOrGetter } from 'vue';

/**
 * Patch 37: the JUMPING HOT switch (and its 🧵 thread part): one per person, for all your characters. Jumps you log
 * count hot (prop on) or cold from it, and the rolling plans use it. Saved with your map
 * settings; flips at once on screen.
 */
const local = ref<boolean | null>(null);
const localThread = ref<boolean | null>(null);
/** Patch 53: a pop-out's own switches (character id → what you just clicked), until the server's copy arrives. */
const localByCharacter = ref<Record<string, { hot?: boolean; thread?: boolean }>>({});
/** The switches as last seen by any component (for code outside components, like Will it hold). */
export const jumpingHotNow = ref(false);
export const jumpingThreadNow = ref(false);

/**
 * Patch 53: with a character (the pop-out's), its own switches: they start as the main ones, and
 * clicking them changes only that character. The main switch (no character) resets them all.
 */
export function useJumpingHot(characterId: MaybeRefOrGetter<number | null | undefined> = null) {
    const settings = useMapUserSettings();
    const page = useShowMap();
    watch(
        () => settings.value.jumping_hot,
        () => (local.value = null),
    );
    watch(
        () => settings.value.jumping_by_character,
        () => (localByCharacter.value = {}),
    );
    const mainHot = computed(() => local.value ?? Boolean(settings.value.jumping_hot));
    const mainThread = computed(() => localThread.value ?? Boolean(settings.value.jumping_thread));
    const own = computed(() => {
        const id = toValue(characterId);
        if (!id) return null;
        const key = String(id);
        return { ...(settings.value.jumping_by_character?.[key] ?? {}), ...(localByCharacter.value[key] ?? {}) };
    });
    const hot = computed(() => own.value?.hot ?? mainHot.value);
    const thread = computed(() => own.value?.thread ?? mainThread.value);
    /** This character has its own switch (differs from the main one). */
    const separate = computed(() => own.value !== null && (own.value.hot !== undefined || own.value.thread !== undefined));
    if (toValue(characterId) === null) {
        watch(mainHot, (value) => (jumpingHotNow.value = value), { immediate: true });
        watch(mainThread, (value) => (jumpingThreadNow.value = value), { immediate: true });
    }

    function setOwn(change: { hot?: boolean; thread?: boolean }): boolean {
        const id = toValue(characterId);
        if (!id) return false;
        const key = String(id);
        localByCharacter.value = { ...localByCharacter.value, [key]: { ...(localByCharacter.value[key] ?? {}), ...change } };
        updateMapUserSettings(page.props.map.slug, { jumping_for: { character_id: id, ...change } }, ['map_user_settings']);
        return true;
    }
    function setHot(value: boolean): void {
        if (setOwn({ hot: value })) return;
        local.value = value;
        localByCharacter.value = {};
        updateMapUserSettings(page.props.map.slug, { jumping_hot: value }, ['map_user_settings']);
    }
    // Patch 37: 🧵 thread = entanglers on (a HIC threading a hole).
    watch(
        () => settings.value.jumping_thread,
        () => (localThread.value = null),
    );
    function setThread(value: boolean): void {
        if (setOwn({ thread: value })) return;
        localThread.value = value;
        localByCharacter.value = {};
        updateMapUserSettings(page.props.map.slug, { jumping_thread: value }, ['map_user_settings']);
    }
    return { hot, setHot, toggle: () => setHot(!hot.value), thread, setThread, toggleThread: () => setThread(!thread.value), separate };
}
