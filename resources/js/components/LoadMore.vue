<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';

/**
 * The foot of a list that grows as it scrolls. After a few automatic loads it
 * waits to be asked, so a slow or metered connection is not spent on rows nobody
 * asked for, and whatever sits below the list can still be reached.
 */
defineProps<{
    shown: number;
    total: number;
    loading: boolean;
    hasMore: boolean;
    manualMode: boolean;
    fetch: () => void;
}>();
</script>

<template>
    <div
        class="mt-3 flex min-h-9 items-center justify-between gap-3 px-1 text-xs text-[var(--ui-ink-3)] [.data-table-host_&]:px-4 sm:[.data-table-host_&]:px-6"
    >
        <p class="numeric">{{ shown }} de {{ total }}</p>
        <LoaderCircle
            v-if="loading"
            class="size-4 animate-spin-delayed text-zinc-400"
            aria-hidden="true"
        />
        <AppButton v-else-if="hasMore && manualMode" size="sm" @click="fetch()">
            Mostrar mais
        </AppButton>
    </div>
</template>
