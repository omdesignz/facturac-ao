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
        class="min-w-0 pe-6 sm:border-s sm:border-[var(--ui-line)] sm:ps-6 sm:first:border-s-0 sm:first:ps-0"
    >
        <dt class="text-xs font-medium text-[var(--ui-ink-3)]">
            {{ label }}
        </dt>
        <dd
            :class="[
                compact
                    ? 'text-base font-medium tracking-[-0.01em]'
                    : 'text-2xl/none font-[650] tracking-[-0.03em]',
                'mt-2 numeric break-words text-[var(--ui-ink)]',
            ]"
        >
            <slot>{{ value }}</slot>
        </dd>
        <dd v-if="detail" class="mt-1.5 text-xs text-[var(--ui-ink-3)]">
            {{ detail }}
        </dd>
    </div>
</template>
