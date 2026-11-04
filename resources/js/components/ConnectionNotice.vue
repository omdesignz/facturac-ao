<script setup lang="ts">
import { WifiOff, X } from '@lucide/vue';
import { computed } from 'vue';
import AppButton from '@/components/AppButton.vue';
import {
    connectionMessage,
    failedVisit,
    isOffline,
    retryFailedVisit,
} from '@/lib/connection';

const message = computed(() =>
    connectionMessage(isOffline.value, failedVisit.value),
);
</script>

<template>
    <!-- Stays mounted so a screen reader is already listening when it speaks. -->
    <div role="status" aria-live="polite">
        <div
            v-if="message"
            class="fixed inset-x-3 top-20 z-50 mx-auto flex max-w-xl items-start gap-3 rounded-2xl bg-white p-4 shadow-[0_0_0_1px_rgb(23_23_22/0.08),0_10px_26px_-12px_rgb(23_23_22/0.3)] dark:bg-zinc-900 dark:shadow-[0_0_0_1px_rgb(255_255_255/0.12)]"
        >
            <WifiOff
                class="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-zinc-400"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1 text-sm/5">
                <p class="font-medium text-zinc-950 dark:text-white">
                    {{ message.title }}
                </p>
                <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">
                    {{ message.detail }}
                </p>
            </div>
            <AppButton
                v-if="message.canRetry"
                size="sm"
                class="shrink-0"
                @click="retryFailedVisit"
            >
                Tentar de novo
            </AppButton>
            <button
                v-else-if="failedVisit"
                type="button"
                class="grid size-6 shrink-0 place-items-center rounded-full text-zinc-500 focus-ring hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white pointer-coarse:size-11"
                @click="failedVisit = null"
            >
                <X class="size-4" aria-hidden="true" />
                <span class="sr-only">Fechar</span>
            </button>
        </div>
    </div>
</template>
