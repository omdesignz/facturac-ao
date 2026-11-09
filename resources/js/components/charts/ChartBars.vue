<script setup lang="ts">
import { computed, ref } from 'vue';

export interface BarDatum {
    label: string;
    value: number;
    /** Optional caption under the label, e.g. a count or a NIF. */
    detail?: string;
    /** 1-5 to take a categorical slot; omitted means every bar shares slot 1. */
    slot?: number;
}

const props = withDefaults(
    defineProps<{
        bars: BarDatum[];
        format: (value: number) => string;
        /** Colour each bar by its own identity rather than sharing one hue. */
        categorical?: boolean;
        /**
         * Print each bar's part of the whole. Only for bars that add up to the
         * whole (types of one total), never a top-N list that leaves the rest out.
         */
        shares?: boolean;
    }>(),
    { categorical: false, shares: false },
);

const maxValue = computed(() =>
    Math.max(1, ...props.bars.map((bar) => Math.abs(bar.value))),
);

const total = computed(() =>
    props.bars.reduce((sum, bar) => sum + Math.max(0, bar.value), 0),
);

/**
 * A single bar is always full: it compares against nothing, so it is left out
 * and the row reads as the figure it is.
 */
const drawsBars = computed(() => props.bars.length > 1);

/**
 * Nominal bars share one hue: length already encodes the value, so spending the
 * identity channel on re-encoding it would be waste. Only genuinely distinct
 * categories take their own slot, and a lone category has nothing to be told
 * apart from, so it stays ink like any single series.
 */
function colour(bar: BarDatum, index: number): string {
    if (!props.categorical || props.bars.length < 2) {
        return 'var(--viz-primary)';
    }

    return `var(--viz-series-${bar.slot ?? Math.min(5, index + 1)})`;
}

function share(value: number): number {
    return Math.max(0, (Math.abs(value) / maxValue.value) * 100);
}

const percent = new Intl.NumberFormat('pt-AO', {
    style: 'percent',
    maximumFractionDigits: 0,
});

/** Each bar's part of the whole, so "28,3 mil" also reads as "73 %". */
function partOfWhole(value: number): string | null {
    if (!props.shares || !drawsBars.value || total.value <= 0 || value <= 0) {
        return null;
    }

    return percent.format(value / total.value);
}

const hovered = ref<number | null>(null);

/** Dimming the other bars is a pointer affordance; a tap would leave it stuck. */
function highlight(event: PointerEvent, index: number): void {
    if (event.pointerType === 'mouse') {
        hovered.value = index;
    }
}
</script>

<template>
    <ul :class="drawsBars ? 'space-y-3.5' : ''">
        <li
            v-for="(bar, index) in bars"
            :key="`${index}-${bar.label}`"
            class="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-4 gap-y-1.5 transition-opacity duration-150"
            :class="hovered === null || hovered === index ? '' : 'opacity-60'"
            @pointerenter="highlight($event, index)"
            @pointerleave="hovered = null"
        >
            <p class="min-w-0 truncate text-sm text-[var(--ui-ink)]">
                {{ bar.label }}
                <span v-if="bar.detail" class="text-[var(--ui-ink-3)]">
                    · {{ bar.detail }}
                </span>
            </p>

            <!-- Value at the tip, always visible: this is the relief the
                 light-mode contrast check requires, not decoration. -->
            <p class="numeric text-sm font-medium text-[var(--ui-ink)]">
                <span
                    v-if="partOfWhole(bar.value)"
                    class="me-2 text-xs font-normal text-[var(--ui-ink-3)]"
                    >{{ partOfWhole(bar.value) }}</span
                >{{ format(bar.value) }}
            </p>

            <!-- The track is the full scale; the bar grows from a square
                 baseline to a rounded data end. -->
            <div
                v-if="drawsBars"
                class="col-span-2 h-1.5 w-full overflow-clip rounded-[2px] bg-[var(--ui-chart-bar)]/60"
                aria-hidden="true"
            >
                <div
                    class="h-full rounded-s-[1px] rounded-e-full transition-[width] duration-300 ease-out motion-reduce:transition-none"
                    :style="{
                        width: `${share(bar.value)}%`,
                        background: colour(bar, index),
                    }"
                />
            </div>
        </li>
    </ul>
</template>
