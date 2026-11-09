<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        value: string;
        detail?: string;
        /** Previous-period value, in the same unit, to derive the change from. */
        previous?: number;
        current?: number;
        /** For costs and debts, a rise is not good news. */
        higherIsBetter?: boolean;
        comparisonLabel?: string;
        tone?: 'neutral' | 'good' | 'warning' | 'critical';
    }>(),
    { higherIsBetter: true, tone: 'neutral' },
);

const change = computed(() => {
    if (props.previous === undefined || props.current === undefined) {
        return null;
    }

    if (props.previous === 0) {
        // A jump from nothing is not "infinity percent"; say it in words.
        return props.current === 0
            ? { percent: 0, direction: 'flat' as const }
            : null;
    }

    const percent =
        ((props.current - props.previous) / Math.abs(props.previous)) * 100;

    return {
        percent,
        direction:
            Math.abs(percent) < 0.5
                ? ('flat' as const)
                : percent > 0
                  ? ('up' as const)
                  : ('down' as const),
    };
});

const changeIsGood = computed(() => {
    if (change.value === null || change.value.direction === 'flat') {
        return null;
    }

    return (change.value.direction === 'up') === props.higherIsBetter;
});

/** "+12 %", "−100 %": signed, so the figure reads without its arrow. */
const changeText = computed(() => {
    if (change.value === null || change.value.direction === 'flat') {
        return 'sem variação';
    }

    const figure = Math.abs(change.value.percent).toFixed(0);

    return `${change.value.direction === 'up' ? '+' : '−'}${figure}%`;
});

const valueTone = computed(
    () =>
        ({
            neutral: 'text-[var(--ui-ink)]',
            good: 'text-[var(--ui-positive)]',
            warning: 'text-[var(--ui-warning)]',
            critical: 'text-[var(--ui-critical)]',
        })[props.tone],
);
</script>

<template>
    <div class="min-w-0 rounded-2xl surface p-3.5 sm:p-4">
        <p class="text-xs font-medium text-[var(--ui-ink-3)]">{{ label }}</p>

        <!-- Proportional figures on the headline number: tabular digits make a
             large standalone value look loose. -->
        <p
            :class="[
                valueTone,
                'mt-1.5 text-lg font-semibold tracking-tight [overflow-wrap:anywhere] lining-nums sm:text-[1.625rem]/8',
            ]"
        >
            {{ value }}
        </p>

        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
            <span
                v-if="change"
                :class="[
                    changeIsGood === null
                        ? 'bg-[var(--ui-well)] text-[var(--ui-ink-2)]'
                        : changeIsGood
                          ? 'bg-[var(--ui-positive-wash)] text-[var(--ui-positive)]'
                          : 'bg-[var(--ui-critical-wash)] text-[var(--ui-critical)]',
                    'inline-flex h-5 items-center rounded-md px-1.5 text-xs font-medium tabular-nums',
                ]"
            >
                <span v-if="change.direction !== 'flat'" class="sr-only">{{
                    change.direction === 'up' ? 'Subiu ' : 'Desceu '
                }}</span>
                {{ changeText }}
            </span>

            <span
                v-else-if="previous !== undefined && current !== undefined"
                class="text-xs text-[var(--ui-ink-3)]"
            >
                {{ current > 0 ? 'novo neste período' : 'sem movimento' }}
            </span>

            <span
                v-if="comparisonLabel && (change || previous !== undefined)"
                class="text-xs text-[var(--ui-ink-3)]"
            >
                vs {{ comparisonLabel.toLowerCase() }}
            </span>

            <span v-if="detail" class="text-xs text-[var(--ui-ink-3)]">
                {{ detail }}
            </span>
        </div>
    </div>
</template>
