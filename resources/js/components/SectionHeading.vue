<script setup lang="ts">
import type { Component } from 'vue';

/**
 * The heading every form card opens with: a small icon chip, the title and one
 * line saying what the card is for. One component so the invoice, the quote and
 * the transport guide read as the same screen in three places.
 */
defineProps<{
    icon?: Component;
}>();
</script>

<template>
    <div class="flex items-center gap-3">
        <span
            v-if="icon"
            class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
        >
            <component :is="icon" class="size-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <h2 class="font-semibold text-zinc-950 dark:text-white">
                <slot />
            </h2>
            <p
                v-if="$slots.description"
                class="mt-0.5 text-xs/5 text-zinc-500 dark:text-zinc-400"
            >
                <slot name="description" />
            </p>
        </div>
    </div>
</template>
