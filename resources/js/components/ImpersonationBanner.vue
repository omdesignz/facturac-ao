<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { EyeOff, LogOut, ShieldAlert } from '@lucide/vue';
import { useTimestamp } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { formatCountdown } from '@/lib/work-session';
import { destroy as stopImpersonation } from '@/routes/support/impersonation';

const page = usePage();

const impersonation = computed(() => page.props.impersonation);

const clock = useTimestamp({ interval: 1000 });
const anchoredAt = ref(Date.now());
const anchoredRemaining = ref(impersonation.value?.remaining_seconds ?? 0);
const stopping = ref(false);

// Same anchoring as the work session: the server states what is left on every
// response, so a refresh cannot buy more time.
watch(
    () => impersonation.value?.remaining_seconds,
    (remaining) => {
        if (typeof remaining === 'number') {
            anchoredRemaining.value = remaining;
            anchoredAt.value = Date.now();
        }
    },
);

const remainingSeconds = computed(() => {
    const elapsed = Math.floor((clock.value - anchoredAt.value) / 1000);

    return Math.max(0, anchoredRemaining.value - elapsed);
});

const runningOut = computed(() => remainingSeconds.value <= 120);

function stop(): void {
    if (stopping.value) {
        return;
    }

    stopping.value = true;
    router.delete(stopImpersonation.url(), {
        onFinish: () => {
            stopping.value = false;
        },
    });
}
</script>

<template>
    <div
        v-if="impersonation"
        class="sticky top-0 z-50 bg-rose-600 text-white dark:bg-rose-700"
        role="alert"
    >
        <div
            class="mx-auto flex max-w-screen-2xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 sm:px-6 lg:px-8"
        >
            <ShieldAlert class="size-5 shrink-0" aria-hidden="true" />

            <p class="text-sm/6 font-semibold">
                Sessão de diagnóstico — está a ver a conta de
                {{ impersonation.subject_name }}
                <span class="font-normal opacity-80"
                    >({{ impersonation.subject_email }})</span
                >
            </p>

            <p class="hidden text-sm/6 opacity-90 md:block">
                Tudo o que fizer fica registado em nome de
                {{ impersonation.impersonator_name }}.
            </p>

            <div class="ms-auto flex items-center gap-3">
                <span
                    :class="[
                        runningOut ? 'bg-white/25' : 'bg-white/15',
                        'inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-semibold tabular-nums',
                    ]"
                    :title="`A sessão termina em ${formatCountdown(remainingSeconds)}`"
                >
                    <EyeOff class="size-3.5" aria-hidden="true" />
                    {{ formatCountdown(remainingSeconds) }}
                </span>

                <button
                    type="button"
                    :disabled="stopping"
                    class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-60"
                    @click="stop"
                >
                    <LogOut class="size-3.5" aria-hidden="true" />
                    Terminar diagnóstico
                </button>
            </div>
        </div>
    </div>
</template>
