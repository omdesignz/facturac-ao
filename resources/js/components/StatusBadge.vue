<script setup lang="ts">
import {
    CircleAlert,
    CircleCheck,
    Clock3,
    PencilLine,
    TriangleAlert,
} from '@lucide/vue';
import type { Component } from 'vue';

type StatusTone =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'
    | 'draft'
    | 'contingency';

withDefaults(
    defineProps<{
        label: string;
        tone?: StatusTone;
        /** Still moving. The clock already says so; kept so callers need not change. */
        pulse?: boolean;
    }>(),
    {
        tone: 'neutral',
        pulse: false,
    },
);

/**
 * One chip for every status in the app.
 *
 * A single shape (22px, a 5px corner, 12/550) on a soft wash of its colour, so
 * a column of statuses reads as quiet text until something is wrong. The
 * colour always arrives with a word and a mark, so nobody has to know the
 * palette: green and a tick for done, gold and a clock for something the
 * system is still working on, orange for a warning, red for a failure, grey
 * for everything at rest.
 */
const styles: Record<StatusTone, string> = {
    success: 'bg-[var(--ui-positive-wash)] text-[var(--ui-positive)]',
    info: 'bg-[var(--ui-gold-wash)] text-[var(--ui-gold-ink)]',
    warning:
        'bg-[color-mix(in_oklch,var(--ui-warning)_13%,transparent)] text-[var(--ui-warning)]',
    danger: 'bg-[var(--ui-critical-wash)] text-[var(--ui-critical)]',
    contingency:
        'bg-[color-mix(in_oklch,var(--ui-warning)_13%,transparent)] text-[var(--ui-warning)]',
    neutral: 'bg-[var(--ui-well)] text-[var(--ui-ink-2)]',
    draft: 'bg-transparent text-[var(--ui-ink-2)] shadow-[inset_0_0_0_1px_var(--ui-line-strong)]',
};

const marks: Partial<Record<StatusTone, Component>> = {
    success: CircleCheck,
    info: Clock3,
    warning: TriangleAlert,
    danger: CircleAlert,
    contingency: TriangleAlert,
    draft: PencilLine,
};
</script>

<template>
    <span
        :title="label"
        :class="[
            styles[tone],
            marks[tone] ? 'ps-1.5 pe-2' : 'px-2',
            'inline-flex h-[1.375rem] max-w-full min-w-0 items-center gap-[0.3125rem] rounded-[5px] text-xs font-[550] whitespace-nowrap',
        ]"
    >
        <component
            :is="marks[tone]"
            v-if="marks[tone]"
            class="size-[0.8125rem] shrink-0"
            :stroke-width="2.2"
            aria-hidden="true"
        />
        <span class="truncate">{{ label }}</span>
    </span>
</template>
