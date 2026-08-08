<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, CircleAlert } from '@lucide/vue';
import { computed } from 'vue';

/**
 * Shows the message the last request flashed back.
 *
 * A redirect that carries a message is how the server answers a button press;
 * without somewhere to render it the press looks like it did nothing.
 */
const page = usePage();

const success = computed(() => page.props.flash.success);
const error = computed(() => page.props.flash.error);
</script>

<template>
    <div v-if="success || error" class="grid gap-3">
        <div
            v-if="success"
            class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300"
            role="status"
        >
            <Check class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>{{ success }}</p>
        </div>

        <div
            v-if="error"
            class="flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-400/20 dark:bg-rose-400/10 dark:text-rose-300"
            role="alert"
        >
            <CircleAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>{{ error }}</p>
        </div>
    </div>
</template>
