import { createInertiaApp, router } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import '../css/app.css';
import { initializeTheme } from './composables/useAppearance';
import { isPWA } from './composables/useOnClient';
import { initializeRouting } from './composables/useRoutingWorker';
import { preloadSovereigntyData } from './composables/useSovereigntyData';
import { preloadStaticData } from './composables/useStaticData';
import { driverHeaders, initDriver } from './lib/windowDriver';

configureEcho({
    broadcaster: 'reverb',
});

router.on('finish', () => {
    router.flushAll();
});

// Patch 39: this window's own main goes with every request (X-Map-Driver).
function loginMain(): number | null {
    try {
        const page = JSON.parse(document.getElementById('app')?.dataset.page ?? '{}') as { props?: { auth?: { user?: { active_character?: { id?: number } } | null } } };
        return page.props?.auth?.user?.active_character?.id ?? null;
    } catch {
        return null;
    }
}
const login = loginMain();
const driver = login === null ? null : initDriver(login);
router.on('before', (event) => {
    Object.assign(event.detail.visit.headers, driverHeaders());
});

void preloadStaticData();
void initializeRouting();
void preloadSovereigntyData();

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => {
        if (isPWA()) {
            return appName;
        }
        return title ? `${title} | ${appName}` : appName;
    },
    progress: {
        color: '#4B5563',
        delay: 1_000,
    },
});

// A refresh of a tab whose main differs from the login's: load it again as this tab's main.
if (driver !== null && driver !== login) {
    const stop = router.on('navigate', () => {
        stop();
        router.reload();
    });
}

initializeTheme();
