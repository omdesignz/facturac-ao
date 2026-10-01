<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { X } from '@lucide/vue';

/**
 * Modal shell for the create/edit forms on the register pages. The forms are
 * short enough that a dialog beats a dedicated route — the list stays in view
 * behind it, which is the context you want when editing a record.
 */
defineProps<{
    open: boolean;
    title: string;
    description?: string;
}>();

defineEmits<{ close: [] }>();
</script>

<template>
    <TransitionRoot as="template" :show="open">
        <Dialog class="relative z-50" @close="$emit('close')">
            <TransitionChild
                as="template"
                enter="ease-out duration-200"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-in duration-150"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-zinc-950/60 backdrop-blur-sm" />
            </TransitionChild>

            <div class="fixed inset-0 z-50 overflow-y-auto">
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
                            class="relative w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl ring-1 ring-zinc-900/10 dark:bg-zinc-900 dark:ring-white/10"
                        >
                            <button
                                type="button"
                                class="absolute top-4 right-4 icon-button text-zinc-400 focus-ring transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/5 dark:hover:text-white"
                                @click="$emit('close')"
                            >
                                <span class="sr-only">Fechar</span>
                                <X class="size-4" aria-hidden="true" />
                            </button>

                            <DialogTitle
                                class="pr-10 text-lg font-semibold text-zinc-950 dark:text-white"
                            >
                                {{ title }}
                            </DialogTitle>
                            <p
                                v-if="description"
                                class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                {{ description }}
                            </p>

                            <div class="mt-6">
                                <slot />
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
