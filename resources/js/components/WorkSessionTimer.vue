<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { usePage } from '@inertiajs/vue3';
import { LoaderCircle, LogOut, Timer } from '@lucide/vue';
import { computed } from 'vue';
import { formatCountdown, useWorkSession } from '@/lib/work-session';
import { logout } from '@/routes';
import { renew as renewRoute } from '@/routes/session';

const page = usePage();

const {
    enabled,
    remainingSeconds,
    elapsedFraction,
    warningVisible,
    renewing,
    renew,
    signOut,
} = useWorkSession(
    () => page.props.workSession,
    renewRoute.url(),
    logout.url(),
);

/**
 * Quiet for most of the block, and only earns attention as it runs down, so it
 * never competes with the page for the eye.
 */
const tone = computed(() => {
    if (remainingSeconds.value <= 60) {
        return 'text-rose-600 dark:text-rose-400';
    }

    if (remainingSeconds.value <= 300) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-zinc-400 dark:text-zinc-500';
});

/** Circumference of the r=7 ring below, so the dash offset can trace it. */
const RING = 2 * Math.PI * 7;

const ringOffset = computed(() => RING * elapsedFraction.value);

const label = computed(
    () =>
        `Sessão de trabalho termina em ${formatCountdown(remainingSeconds.value)}`,
);
</script>

<template>
    <div v-if="enabled" class="contents">
        <span
            :class="[
                tone,
                'hidden items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium tabular-nums transition-colors md:inline-flex',
            ]"
            :title="label"
        >
            <!-- The ring drains as the block is spent: readable at a glance
                 without having to parse the digits. -->
            <svg
                class="size-4 -rotate-90"
                viewBox="0 0 18 18"
                fill="none"
                aria-hidden="true"
            >
                <circle
                    cx="9"
                    cy="9"
                    r="7"
                    stroke="currentColor"
                    stroke-width="2"
                    opacity="0.25"
                />
                <circle
                    cx="9"
                    cy="9"
                    r="7"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    :stroke-dasharray="RING"
                    :stroke-dashoffset="ringOffset"
                />
            </svg>
            <span aria-hidden="true">{{
                formatCountdown(remainingSeconds)
            }}</span>
            <span class="sr-only">{{ label }}</span>
        </span>

        <TransitionRoot as="template" :show="warningVisible">
            <!-- Not closable by click-away or Escape: continuing has to be an
                 explicit choice, since nothing else can extend the block. -->
            <Dialog class="relative z-[60]" :static="true" @close="() => {}">
                <TransitionChild
                    as="template"
                    enter="ease-out duration-200"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="ease-in duration-150"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div
                        class="fixed inset-0 bg-zinc-950/70 backdrop-blur-sm"
                    />
                </TransitionChild>

                <div class="fixed inset-0 z-[60] overflow-y-auto">
                    <div
                        class="flex min-h-full items-center justify-center p-4"
                    >
                        <TransitionChild
                            as="template"
                            enter="ease-out duration-200"
                            enter-from="translate-y-3 opacity-0 sm:scale-95"
                            enter-to="translate-y-0 opacity-100 sm:scale-100"
                            leave="ease-in duration-150"
                            leave-from="opacity-100 sm:scale-100"
                            leave-to="opacity-0 sm:scale-95"
                        >
                            <DialogPanel
                                class="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-2xl ring-1 ring-zinc-900/10 dark:bg-zinc-900 dark:ring-white/10"
                            >
                                <span
                                    class="mx-auto grid size-12 place-items-center rounded-2xl bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300"
                                >
                                    <Timer class="size-6" aria-hidden="true" />
                                </span>

                                <DialogTitle
                                    class="mt-5 text-lg font-semibold text-zinc-950 dark:text-white"
                                >
                                    A sua sessão está a terminar
                                </DialogTitle>
                                <p
                                    class="mt-2 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    As sessões de trabalho têm uma duração fixa.
                                    Comece outra para continuar — nenhum
                                    rascunho guardado se perde.
                                </p>

                                <p
                                    class="mt-5 numeric text-4xl font-semibold tracking-tight text-rose-600 dark:text-rose-400"
                                    role="timer"
                                    aria-live="assertive"
                                >
                                    {{ formatCountdown(remainingSeconds) }}
                                </p>

                                <div
                                    class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-center"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                        @click="signOut"
                                    >
                                        <LogOut
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Terminar agora
                                    </button>
                                    <button
                                        type="button"
                                        autofocus
                                        :disabled="renewing"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:opacity-60 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                                        @click="renew"
                                    >
                                        <LoaderCircle
                                            v-if="renewing"
                                            class="size-4 animate-spin"
                                            aria-hidden="true"
                                        />
                                        Nova sessão
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>
    </div>
</template>
