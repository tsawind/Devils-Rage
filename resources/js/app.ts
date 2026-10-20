import { createInertiaApp, router } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import '../css/app.css';
import { initializeTheme } from './composables/useAppearance';
import { isPWA } from './composables/useOnClient';
import { initializeRouting } from './composables/useRoutingWorker';
import { preloadSovereigntyData } from './composables/useSovereigntyData';
import { preloadStaticData } from './composables/useStaticData';
import { initServerReconnect } from './lib/serverReconnect';
import { driverFromUrl, driverHeaders, initDriver, initSolo } from './lib/windowDriver';

configureEcho({
    broadcaster: 'reverb',
});

router.on('finish', () => {
    router.flushAll();
});

// Patch 48: while the mapper restarts, wait for it and reload instead of showing the proxy's error page.
initServerReconnect();

// Patch 39: this window's own main goes with every request (X-Map-Driver).
function loginMain(): number | null {
    try {
        // The first page is on #app's data-page, or (newer Inertia) in a <script data-page> element.
        const raw = document.getElementById('app')?.dataset.page ?? document.querySelector('script[data-page]')?.textContent ?? '{}';
        const page = JSON.parse(raw) as { props?: { auth?: { user?: { active_character?: { id?: number } } | null } } };
        return page.props?.auth?.user?.active_character?.id ?? null;
    } catch {
        return null;
    }
}
const login = loginMain();
// Patch 45b: a one-character tab is one even when the first page couldn't be read.
initSolo();
const driver = login === null ? (driverFromUrl(window.location.href) === null ? null : initDriver(null)) : initDriver(login);
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
