import { router } from '@inertiajs/vue3';
import { useTimestamp } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { WorkSessionSettings } from '@/types/work-session';

/**
 * Countdown for a server-owned work block.
 *
 * The server sends how many seconds remain on every response, and the client
 * anchors that to its own clock. Two consequences follow, both deliberate:
 * a refresh cannot reset the clock, because the next response reports the same
 * remaining time; and a browser with the wrong system time still counts down
 * correctly, because nothing compares an absolute server time to a local one.
 */
export function useWorkSession(
    settings: () => WorkSessionSettings | null,
    renewUrl: string,
    logoutUrl: string,
) {
    const clock = useTimestamp({ interval: 1000 });
    const anchoredAt = ref(Date.now());
    const anchoredRemaining = ref(settings()?.remaining_seconds ?? 0);
    const signingOut = ref(false);
    const renewing = ref(false);
    const failure = ref('');
    let expiryHandled = false;

    // Every Inertia response carries a fresh server reading; re-anchor to it so
    // the two never drift apart over a long session.
    watch(settings, (value) => {
        const remaining = value?.remaining_seconds;

        if (typeof remaining === 'number') {
            anchoredRemaining.value = remaining;
            anchoredAt.value = Date.now();
        }
    });

    const remainingSeconds = computed(() => {
        const elapsed = Math.max(
            0,
            Math.floor((clock.value - anchoredAt.value) / 1000),
        );

        return Math.max(0, anchoredRemaining.value - elapsed);
    });

    const enabled = computed(() => settings()?.enabled === true);
    const warningSeconds = computed(() => settings()?.warning_seconds ?? 60);
    const totalSeconds = computed(() => settings()?.total_seconds ?? 0);

    const warningVisible = computed(
        () =>
            enabled.value &&
            (remainingSeconds.value <= warningSeconds.value ||
                failure.value !== ''),
    );

    const elapsedFraction = computed(() => {
        if (totalSeconds.value === 0) {
            return 0;
        }

        return 1 - remainingSeconds.value / totalSeconds.value;
    });

    function requestSessionChange(action: 'renew' | 'signOut'): void {
        if (signingOut.value || renewing.value) {
            return;
        }

        const busy = action === 'renew' ? renewing : signingOut;
        const failed = () => {
            failure.value =
                action === 'renew'
                    ? 'Não foi possível confirmar a nova sessão. Tente novamente. O prazo anterior continua em vigor.'
                    : 'Não foi possível confirmar a saída. Verifique a ligação e tente terminar a sessão novamente.';
        };
        failure.value = '';
        router.post(
            action === 'renew' ? renewUrl : logoutUrl,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    busy.value = true;

                    if (action === 'signOut' && remainingSeconds.value <= 0) {
                        expiryHandled = true;
                    }
                },
                onError: failed,
                onCancel: failed,
                onNetworkError: () => {
                    failed();

                    return false;
                },
                onHttpException: () => {
                    failed();

                    return false;
                },
                // No local guess at the new window: the redirect lands on a
                // page whose shared prop already reports it, and the watcher
                // above re-anchors the countdown to that.
                onFinish: () => {
                    busy.value = false;
                },
            },
        );
    }

    function signOut(): void {
        requestSessionChange('signOut');
    }

    /** Only an explicit choice starts a new block; working does not extend it. */
    function renew(): void {
        requestSessionChange('renew');
    }

    // Immediate, so a page that loads with nothing left signs out instead of
    // sitting on a dead 0:00 — a plain watcher only reacts to a change.
    const stop = watch(
        [remainingSeconds, enabled, renewing, signingOut],
        ([seconds, isEnabled, isRenewing, isSigningOut]) => {
            if (seconds > 0) {
                expiryHandled = false;
            }

            if (
                isEnabled &&
                seconds <= 0 &&
                !isRenewing &&
                !isSigningOut &&
                !expiryHandled
            ) {
                expiryHandled = true;
                signOut();
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(stop);

    return {
        enabled,
        remainingSeconds,
        totalSeconds,
        elapsedFraction,
        warningVisible,
        signingOut,
        renewing,
        failure,
        renew,
        signOut,
    };
}

export function formatCountdown(totalSeconds: number): string {
    const safe = Math.max(0, totalSeconds);
    const hours = Math.floor(safe / 3600);
    const minutes = Math.floor((safe % 3600) / 60);
    const seconds = safe % 60;
    const pad = (value: number): string => String(value).padStart(2, '0');

    return hours > 0
        ? `${hours}:${pad(minutes)}:${pad(seconds)}`
        : `${minutes}:${pad(seconds)}`;
}
