<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref, shallowRef, watch } from 'vue';
import { usePendingConfirm } from '@/lib/confirm';
import type { ConfirmRequest } from '@/lib/confirm';

/**
 * The app's answer to `window.confirm`.
 *
 * Mounted once in the layout and driven by `confirmAction`, so every page asks
 * the same way. Three things the browser's dialog cannot do and this must: say
 * what the button will actually do, look like the rest of the app in either
 * theme, and open with the focus on Cancelar when the answer destroys
 * something — the safe key is the one under your finger.
 */
const { pending, answer } = usePendingConfirm();

const cancelButton = ref<HTMLButtonElement | null>(null);
const activePrompt = shallowRef<ConfirmRequest | null>(pending.value);

watch(
    pending,
    (prompt) => {
        if (prompt !== null) {
            activePrompt.value = prompt;
        }
    },
    { immediate: true },
);

const isDanger = computed(() => activePrompt.value?.tone !== 'neutral');
</script>

<template>
    <TransitionRoot as="template" :show="pending !== null">
        <Dialog
            class="relative z-[60]"
            :initial-focus="cancelButton"
            @close="answer(false)"
        >
            <TransitionChild
                as="template"
                enter="ease-out duration-200"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-in duration-150"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 dialog-scrim" />
            </TransitionChild>

            <div class="fixed inset-0 z-[60] overflow-y-auto">
                <div
                    class="flex min-h-full items-end justify-center p-4 sm:items-center"
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
                            class="relative w-full max-w-md dialog-panel p-6"
                        >
                            <div v-if="activePrompt" class="flex gap-4">
                                <span
                                    :class="[
                                        isDanger
                                            ? 'bg-rose-100 text-rose-700 dark:bg-rose-400/10 dark:text-rose-300'
                                            : 'bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300',
                                        'grid size-10 shrink-0 place-items-center rounded-full',
                                    ]"
                                >
                                    <TriangleAlert
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>

                                <div class="min-w-0 flex-1">
                                    <DialogTitle
                                        class="text-base font-semibold text-zinc-950 dark:text-white"
                                    >
                                        {{ activePrompt.title }}
                                    </DialogTitle>
                                    <p
                                        class="mt-1.5 text-sm/6 text-zinc-600 dark:text-zinc-400"
                                    >
                                        {{ activePrompt.message }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="activePrompt"
                                class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                            >
                                <button
                                    ref="cancelButton"
                                    type="button"
                                    class="inline-flex h-10 items-center justify-center rounded-full px-[1.125rem] text-sm font-semibold text-zinc-800 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                    @click="answer(false)"
                                >
                                    {{ activePrompt.cancelLabel ?? 'Cancelar' }}
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        isDanger
                                            ? 'bg-rose-600 text-white hover:bg-rose-500'
                                            : 'bg-brand-950 text-white hover:bg-brand-800 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white',
                                        'inline-flex h-10 items-center justify-center rounded-full px-[1.125rem] text-sm font-semibold focus-ring transition',
                                    ]"
                                    @click="answer(true)"
                                >
                                    {{ activePrompt.confirmLabel }}
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
