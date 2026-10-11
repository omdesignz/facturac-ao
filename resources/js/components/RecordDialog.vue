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
    /** A short label above the title naming the register, e.g. "Artigos". */
    eyebrow?: string;
}>();

defineEmits<{ close: []; afterLeave: [] }>();
</script>

<template>
    <TransitionRoot
        as="template"
        :show="open"
        @after-leave="$emit('afterLeave')"
    >
        <Dialog class="relative z-50" @close="$emit('close')">
            <TransitionChild
                as="template"
                enter="ease-out duration-200"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-out duration-150"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 dialog-scrim" />
            </TransitionChild>

            <div class="fixed inset-0 z-50 overflow-y-auto overscroll-contain">
                <div
                    class="flex min-h-full items-end justify-center pt-10 sm:items-center sm:p-6"
                >
                    <TransitionChild
                        as="template"
                        enter="transition-[opacity,translate,scale] ease-out duration-200"
                        enter-from="max-sm:translate-y-full sm:scale-95 sm:opacity-0"
                        enter-to="max-sm:translate-y-0 sm:scale-100 sm:opacity-100"
                        leave="transition-[opacity,translate,scale] ease-out duration-150"
                        leave-from="max-sm:translate-y-0 sm:scale-100 sm:opacity-100"
                        leave-to="max-sm:translate-y-full sm:scale-95 sm:opacity-0"
                    >
                        <DialogPanel
                            class="relative w-full max-w-2xl dialog-panel p-6 max-sm:rounded-b-none max-sm:pb-[max(1.5rem,env(safe-area-inset-bottom))] sm:p-8"
                        >
                            <p
                                v-if="eyebrow"
                                class="eyebrow text-zinc-500 dark:text-zinc-400"
                            >
                                {{ eyebrow }}
                            </p>
                            <DialogTitle
                                class="pr-12 text-[1.625rem] leading-[1.12] display text-zinc-950 dark:text-white"
                                :class="eyebrow ? 'mt-2' : ''"
                            >
                                {{ title }}
                            </DialogTitle>
                            <p
                                v-if="description"
                                class="mt-2 max-w-[34rem] text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                {{ description }}
                            </p>

                            <div class="mt-7">
                                <slot />
                            </div>

                            <!-- Last in the DOM so the dialog opens focused on
                                 the first field, not on its own close button. -->
                            <button
                                type="button"
                                class="absolute top-5 right-5 icon-button rounded-full text-zinc-400 focus-ring transition hover:bg-zinc-900/[0.05] hover:text-zinc-800 sm:top-6 sm:right-6 dark:hover:bg-white/10 dark:hover:text-white"
                                @click="$emit('close')"
                            >
                                <span class="sr-only">Fechar</span>
                                <X class="size-4" aria-hidden="true" />
                            </button>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
