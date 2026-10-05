<script setup lang="ts">
/**
 * One figure in a PageHeader's row: a small label, the value at display size,
 * and an optional line of context underneath.
 */
withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        detail?: string;
        /** Long text values ("Ainda não verificada") read better smaller. */
        compact?: boolean;
    }>(),
    { detail: undefined, compact: false },
);
</script>

<template>
    <div
        class="pr-6 sm:border-l sm:border-zinc-900/10 sm:pl-6 sm:first:border-l-0 sm:first:pl-0 dark:sm:border-white/10"
    >
        <dt
            class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
        >
            {{ label }}
        </dt>
        <dd
            :class="[
                compact
                    ? 'text-base font-medium tracking-[-0.01em]'
                    : 'text-[1.75rem] leading-none tracking-[-0.03em]',
                'mt-2.5 numeric text-zinc-950 dark:text-white',
            ]"
        >
            <slot>{{ value }}</slot>
        </dd>
        <dd
            v-if="detail"
            class="mt-2 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
        >
            {{ detail }}
        </dd>
    </div>
</template>
