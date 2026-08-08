<script setup lang="ts">
import { ArrowDownRight, ArrowRight, ArrowUpRight } from '@lucide/vue';
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

const changeIcon = computed(() => {
    if (change.value === null) {
        return ArrowRight;
    }

    return change.value.direction === 'up'
        ? ArrowUpRight
        : change.value.direction === 'down'
          ? ArrowDownRight
          : ArrowRight;
});

const valueTone = computed(
    () =>
        ({
            neutral: 'text-zinc-950 dark:text-white',
            good: 'text-emerald-700 dark:text-emerald-400',
            warning: 'text-amber-700 dark:text-amber-400',
            critical: 'text-rose-700 dark:text-rose-400',
        })[props.tone],
);
</script>

<template>
    <div class="rounded-2xl surface p-4">
        <p class="eyebrow text-zinc-500">{{ label }}</p>

        <!-- Proportional figures on the headline number: tabular digits make a
             large standalone value look loose. -->
        <p :class="[valueTone, 'mt-2 text-2xl font-semibold lining-nums']">
            {{ value }}
        </p>

        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
            <span
                v-if="change"
                :class="[
                    changeIsGood === null
                        ? 'text-zinc-500 dark:text-zinc-400'
                        : changeIsGood
                          ? 'text-emerald-700 dark:text-emerald-400'
                          : 'text-rose-700 dark:text-rose-400',
                    'inline-flex items-center gap-1 text-xs font-medium tabular-nums',
                ]"
            >
                <component
                    :is="changeIcon"
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{
                    change.direction === 'flat'
                        ? 'sem variação'
                        : `${Math.abs(change.percent).toFixed(0)}%`
                }}
            </span>

            <span
                v-else-if="previous !== undefined && current !== undefined"
                class="text-xs text-zinc-500 dark:text-zinc-400"
            >
                {{ current > 0 ? 'novo neste período' : 'sem movimento' }}
            </span>

            <span
                v-if="comparisonLabel && (change || previous !== undefined)"
                class="text-xs text-zinc-400 dark:text-zinc-500"
            >
                vs {{ comparisonLabel.toLowerCase() }}
            </span>

            <span
                v-if="detail"
                class="text-xs text-zinc-500 dark:text-zinc-400"
            >
                {{ detail }}
            </span>
        </div>
    </div>
</template>
