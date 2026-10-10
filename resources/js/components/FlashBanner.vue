<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, CircleAlert } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';

/**
 * Shows the message the last request flashed back.
 *
 * A redirect that carries a message is how the server answers a button press;
 * without somewhere to render it the press looks like it did nothing.
 */
const page = usePage();

const success = computed(() => page.props.flash.success);
const error = computed(() => page.props.flash.error);

const banner = ref<HTMLElement | null>(null);

/**
 * A `preserveScroll` action leaves the page where the button was, which can be
 * far below the banner. When a message arrives, bring it into view the shortest
 * way. `nearest` does nothing when it is already visible (the document's
 * `scroll-padding` keeps it clear of the sticky header), and the scroll is
 * never animated for someone who asked for less motion.
 */
watch(
    () => page.props.flash,
    async () => {
        if (!success.value && !error.value) {
            return;
        }

        await nextTick();

        const reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        banner.value?.scrollIntoView({
            block: 'nearest',
            behavior: reduced ? 'instant' : 'auto',
        });
    },
);
</script>

<template>
    <!-- Always in the page, so a screen reader is already listening when a
         message is rendered into it. A real block rather than `contents`: the
         pages put this inside `space-y-*` stacks, and spacing is applied to the
         element itself, which has to exist for the gap to survive. Empty, it
         collapses to nothing. -->
    <div role="status" aria-live="polite">
        <Transition
            appear
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="-translate-y-1 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-out"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="success || error" ref="banner" class="grid gap-3">
                <div
                    v-if="success"
                    class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300"
                >
                    <Check class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                    <p>{{ success }}</p>
                </div>

                <div
                    v-if="error"
                    class="flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-400/20 dark:bg-rose-400/10 dark:text-rose-300"
                    role="alert"
                >
                    <CircleAlert
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <p>{{ error }}</p>
                </div>
            </div>
        </Transition>
    </div>
</template>
