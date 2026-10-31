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
        class="flex flex-col items-center gap-3 px-6 py-14 text-center"
        :class="
            bare
                ? ''
                : 'rounded-3xl border border-dashed border-zinc-900/15 dark:border-white/15'
        "
    >
        <span
            class="grid size-12 place-items-center rounded-2xl bg-zinc-900/[0.05] text-zinc-500 dark:bg-white/[0.07] dark:text-zinc-400"
        >
            <component :is="icon" class="size-6" aria-hidden="true" />
        </span>
        <p class="text-base font-semibold text-zinc-950 dark:text-white">
            {{ title }}
        </p>
        <p
            v-if="description"
            class="max-w-md text-sm/6 text-zinc-500 dark:text-zinc-400"
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-1">
            <slot />
        </div>
    </div>
</template>
