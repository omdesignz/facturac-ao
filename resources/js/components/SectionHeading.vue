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
            class="grid size-8 shrink-0 place-items-center rounded-lg bg-[var(--ui-well)] text-[var(--ui-ink-2)]"
        >
            <component :is="icon" class="size-4" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <h2 class="text-sm font-semibold text-[var(--ui-ink)]">
                <slot />
            </h2>
            <p
                v-if="$slots.description"
                class="mt-0.5 text-xs/5 text-[var(--ui-ink-3)]"
            >
                <slot name="description" />
            </p>
        </div>
    </div>
</template>
