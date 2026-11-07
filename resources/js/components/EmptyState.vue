<script setup lang="ts">
import type { Component } from 'vue';

/**
 * What a list says when it has nothing to list: what is missing, why that is
 * fine, and the one way to fix it. One shape everywhere, so an empty screen
 * never looks like a broken one.
 *
 * `bare` drops the outline for places that already sit inside a card.
 */
defineProps<{
    icon: Component;
    title: string;
    description?: string;
    bare?: boolean;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-2 px-6 py-14 text-center"
        :class="
            bare
                ? ''
                : 'rounded-xl border border-dashed border-[var(--ui-line-strong)]'
        "
    >
        <span
            class="mb-1 grid size-10 place-items-center rounded-[10px] bg-[var(--ui-well)] text-[var(--ui-ink-2)]"
        >
            <component :is="icon" class="size-5" aria-hidden="true" />
        </span>
        <p class="text-sm font-semibold text-[var(--ui-ink)]">
            {{ title }}
        </p>
        <p
            v-if="description"
            class="max-w-md text-[0.8125rem]/5 text-[var(--ui-ink-3)]"
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-1">
            <slot />
        </div>
    </div>
</template>
