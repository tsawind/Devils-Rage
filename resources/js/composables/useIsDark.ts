import { ref } from 'vue';

/**
 * Patch 20: whether dark mode is on (html.dark), as a ref, for the few map
 * colours drawn in code (pipe ink is dark in light mode).
 */
function read(): boolean {
    return typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
}

export const isDark = ref(read());

if (typeof window !== 'undefined' && typeof MutationObserver !== 'undefined') {
    new MutationObserver(() => {
        isDark.value = read();
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
}
