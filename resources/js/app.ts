import { createInertiaApp, router } from '@inertiajs/vue3';
import {
    applyAppearance,
    getStoredAppearance,
    watchSystemAppearance,
} from '@/lib/appearance';

const appName = import.meta.env.VITE_APP_NAME || 'facturac.ao';

applyAppearance(getStoredAppearance());
watchSystemAppearance();

/*
 * When a visit is answered with one of the server's HTML error pages, Inertia
 * would show it inside a modal, which turns a designed page into a broken
 * overlay. Two cases are handled here and everything else keeps Inertia's
 * default:
 *
 * - a 419 on any method: the page outlived its security token. Say so, then
 *   reload (a plain GET, so there is nothing to loop on);
 * - a failed GET navigation: leave the SPA and load the address as a document,
 *   so the error page is shown as a page.
 *
 * Only an HTML body counts. An error answered as an Inertia page arrives as an
 * object and is left to the framework. The event carries no URL, so the visit
 * that started last is remembered; prefetches are skipped because they never
 * produce this event.
 */
const retryWindowMs = 10_000;
const retryKey = 'vap-error-page-load';
const expiredMessage =
    'A página esteve aberta demasiado tempo e expirou. Vamos recarregá-la; a última acção não foi concluída.';

type StartedVisit = { url: string; navigates: boolean };

let lastVisit: StartedVisit | null = null;

router.on('start', (event) => {
    const visit = event.detail.visit;

    if (visit.prefetch) {
        return;
    }

    const partial = visit as typeof visit & {
        deferredProps?: boolean;
        poll?: boolean;
    };

    lastVisit = {
        url: String(visit.url),
        navigates:
            visit.method === 'get' &&
            !partial.deferredProps &&
            !partial.poll &&
            visit.only.length === 0 &&
            visit.except.length === 0,
    };
});

/** True when this address was already loaded as a document a moment ago. */
function wasJustLoaded(url: string): boolean {
    try {
        const [lastUrl, lastAt] = (
            window.sessionStorage.getItem(retryKey) ?? ''
        ).split('|');

        return lastUrl === url && Date.now() - Number(lastAt) < retryWindowMs;
    } catch {
        return false;
    }
}

router.on('httpException', (event) => {
    const { status, data } = event.detail.response;

    if (typeof data !== 'string') {
        return;
    }

    if (status === 419) {
        window.alert(expiredMessage);
        window.location.reload();

        return false;
    }

    if (!lastVisit?.navigates || wasJustLoaded(lastVisit.url)) {
        return;
    }

    try {
        window.sessionStorage.setItem(
            retryKey,
            `${lastVisit.url}|${Date.now()}`,
        );
    } catch {
        // Without storage the guard is off; the load below is not an Inertia
        // visit, so it cannot raise this event again.
    }

    window.location.assign(lastVisit.url);

    return false;
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#F9B233',
    },
});
