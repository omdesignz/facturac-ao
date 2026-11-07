<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';

/**
 * The thin bar a work surface opens on: where it sits, its name, a quiet word
 * or two about it, and its actions on the right. The content below runs edge
 * to edge, so a list or a table starts at the top of the screen.
 */
defineProps<{
    /** The group in the sidebar, e.g. "Cobrar". */
    section: string;
    title: string;
}>();
</script>

<template>
    <header
        class="flex min-h-[3.25rem] flex-wrap items-center gap-x-2 gap-y-2 border-b border-[var(--ui-line)] px-4 py-2 sm:px-6 md:h-[3.25rem] md:flex-nowrap md:py-0"
    >
        <span class="text-[0.8125rem] text-[var(--ui-ink-3)]">{{
            section
        }}</span>
        <ChevronRight
            class="size-3.5 text-[var(--ui-ink-3)]"
            aria-hidden="true"
        />
        <h1 class="text-[0.8125rem] font-semibold text-[var(--ui-ink)]">
            {{ title }}
        </h1>
        <slot name="meta" />
        <span class="flex-1" />
        <div
            v-if="$slots.actions"
            class="flex items-center gap-2 max-md:w-full max-md:flex-wrap"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
