import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

export interface FailedVisit {
    url: string;
    /** It asked the server to change something, so it may have got through. */
    writes: boolean;
}

export const isOffline = ref(false);
export const failedVisit = ref<FailedVisit | null>(null);

export function connectionMessage(
    offline: boolean,
    failed: FailedVisit | null,
): { title: string; detail: string; canRetry: boolean } | null {
    if (failed?.writes) {
        return {
            title: 'Não foi possível confirmar',
            detail: 'A ligação falhou antes da resposta. O que escreveu continua no ecrã; confirme se ficou guardado antes de repetir.',
            canRetry: false,
        };
    }

    if (failed) {
        return {
            title: 'Sem ligação',
            detail: 'A página não abriu. Tentamos de novo quando a ligação voltar.',
            canRetry: true,
        };
    }

    if (offline) {
        return {
            title: 'Sem ligação à internet',
            detail: 'Pode continuar a ver esta página. Nada fica guardado até a ligação voltar.',
            canRetry: false,
        };
    }

    return null;
}

/**
 * Only ever repeats a read. A write that lost its answer may have reached the
 * server, and repeating it could issue or send something twice.
 */
export function retryFailedVisit(): void {
    const failed = failedVisit.value;

    if (failed === null || failed.writes) {
        return;
    }

    failedVisit.value = null;
    router.visit(failed.url, { preserveScroll: true, preserveState: true });
}

/** Follows the browser's connection and the visits that fail for lack of one. */
export function watchConnection(): void {
    isOffline.value = !window.navigator.onLine;

    window.addEventListener('offline', () => {
        isOffline.value = true;
    });

    window.addEventListener('online', () => {
        isOffline.value = false;
        retryFailedVisit();
    });

    // What the visit in progress is, since the error event does not say. Hover
    // prefetches, polls, deferred props and a list's next page stay out: nobody
    // is waiting on those, and repeating one as a full visit would lose the list.
    let running: FailedVisit | null = null;

    router.on('start', ({ detail: { visit } }) => {
        const background = visit as typeof visit & {
            deferredProps?: boolean;
            poll?: boolean;
        };

        if (
            visit.prefetch ||
            background.deferredProps ||
            background.poll ||
            'X-Inertia-Infinite-Scroll-Merge-Intent' in visit.headers
        ) {
            return;
        }

        failedVisit.value = null;
        running = {
            url: String(visit.url),
            writes: visit.method !== 'get',
        };
    });

    router.on('finish', ({ detail: { visit } }) => {
        if (!visit.prefetch) {
            running = null;
        }
    });

    router.on('networkError', () => {
        if (running !== null) {
            failedVisit.value = running;
        }

        return false;
    });
}
