<script setup lang="ts">
/**
 * The heading and actions of a working screen, with room to wrap on a phone.
 */
defineProps<{
    /** The group in the sidebar, e.g. "Cobrar". */
    section: string;
    title: string;
}>();
</script>

<template>
    <header
        class="flex flex-wrap items-center gap-x-3 gap-y-4 px-4 pt-6 pb-5 sm:px-6"
    >
        <span class="sr-only">{{ section }}</span>
        <h1
            class="text-page-title leading-[1.2] font-[650] tracking-[-0.025em] text-[var(--ui-ink)]"
        >
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
