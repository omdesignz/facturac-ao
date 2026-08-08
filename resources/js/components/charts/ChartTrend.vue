<script setup lang="ts">
import { computed, ref } from 'vue';

export interface TrendPoint {
    label: string;
    value: number;
    comparison?: number;
}

const props = withDefaults(
    defineProps<{
        points: TrendPoint[];
        /** Formats a value for the tooltip and the end label. */
        format: (value: number) => string;
        seriesLabel: string;
        comparisonLabel?: string;
        height?: number;
    }>(),
    { height: 220, comparisonLabel: undefined },
);

const PADDING = { top: 16, right: 16, bottom: 26, left: 8 };
const WIDTH = 720;

const hasComparison = computed(
    () =>
        props.comparisonLabel !== undefined &&
        props.points.some((point) => (point.comparison ?? 0) > 0),
);

const plotHeight = computed(() => props.height - PADDING.top - PADDING.bottom);
const plotWidth = WIDTH - PADDING.left - PADDING.right;

/**
 * The scale starts at zero and is rounded up to a clean number, so the axis
 * ticks land on values a reader recognises and the shape of the line cannot be
 * exaggerated by a cropped baseline.
 */
const maxValue = computed(() => {
    const values = props.points.flatMap((point) => [
        point.value,
        point.comparison ?? 0,
    ]);
    const peak = Math.max(0, ...values);

    if (peak === 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(peak));
    const step = magnitude / 2;

    return Math.ceil(peak / step) * step;
});

function x(index: number): number {
    if (props.points.length <= 1) {
        return PADDING.left + plotWidth / 2;
    }

    return PADDING.left + (index / (props.points.length - 1)) * plotWidth;
}

function y(value: number): number {
    return PADDING.top + plotHeight.value * (1 - value / maxValue.value);
}

function path(pick: (point: TrendPoint) => number): string {
    return props.points
        .map(
            (point, index) =>
                `${index === 0 ? 'M' : 'L'}${x(index)},${y(pick(point))}`,
        )
        .join(' ');
}

const linePath = computed(() => path((point) => point.value));
const comparisonPath = computed(() => path((point) => point.comparison ?? 0));

const areaPath = computed(() => {
    if (props.points.length === 0) {
        return '';
    }

    const baseline = y(0);

    return `${linePath.value} L${x(props.points.length - 1)},${baseline} L${x(0)},${baseline} Z`;
});

/** Three gridlines plus the baseline: enough to read against, quiet enough to ignore. */
const gridValues = computed(() =>
    [0, 0.5, 1].map((share) => share * maxValue.value),
);

const hoverIndex = ref<number | null>(null);

const hovered = computed(() =>
    hoverIndex.value === null ? null : props.points[hoverIndex.value],
);

function trackPointer(event: PointerEvent): void {
    const svg = event.currentTarget as SVGSVGElement;
    const rect = svg.getBoundingClientRect();
    const ratio = (event.clientX - rect.left) / rect.width;
    const position = ratio * WIDTH;

    let nearest = 0;
    let smallest = Number.POSITIVE_INFINITY;

    props.points.forEach((_, index) => {
        const distance = Math.abs(x(index) - position);

        if (distance < smallest) {
            smallest = distance;
            nearest = index;
        }
    });

    hoverIndex.value = nearest;
}

/** Keeps the tooltip inside the plot instead of letting it hang off an edge. */
const tooltipStyle = computed(() => {
    if (hoverIndex.value === null) {
        return {};
    }

    const share = (x(hoverIndex.value) / WIDTH) * 100;

    return {
        left: `${Math.min(88, Math.max(12, share))}%`,
        transform: 'translateX(-50%)',
    };
});

/** Only every nth tick is drawn when the axis would otherwise collide. */
const labelStride = computed(() => Math.ceil(props.points.length / 12));
</script>

<template>
    <div class="relative">
        <svg
            :viewBox="`0 0 ${WIDTH} ${height}`"
            class="w-full touch-none"
            :style="{ height: `${height}px` }"
            role="img"
            :aria-label="`${seriesLabel} ao longo do tempo`"
            @pointermove="trackPointer"
            @pointerleave="hoverIndex = null"
        >
            <!-- Solid hairlines, one step off the surface: present to read
                 against, never competing with the data. -->
            <line
                v-for="value in gridValues"
                :key="value"
                :x1="PADDING.left"
                :x2="WIDTH - PADDING.right"
                :y1="y(value)"
                :y2="y(value)"
                stroke="var(--viz-grid)"
                stroke-width="1"
            />

            <path
                v-if="hasComparison"
                :d="comparisonPath"
                fill="none"
                stroke="var(--viz-comparison)"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />

            <path :d="areaPath" fill="var(--viz-series-1)" opacity="0.1" />
            <path
                :d="linePath"
                fill="none"
                stroke="var(--viz-series-1)"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />

            <template v-if="hoverIndex !== null">
                <line
                    :x1="x(hoverIndex)"
                    :x2="x(hoverIndex)"
                    :y1="PADDING.top"
                    :y2="PADDING.top + plotHeight"
                    stroke="var(--viz-axis)"
                    stroke-width="1"
                />
                <circle
                    v-if="hasComparison"
                    :cx="x(hoverIndex)"
                    :cy="y(points[hoverIndex].comparison ?? 0)"
                    r="4"
                    fill="var(--viz-comparison)"
                    stroke="var(--viz-surface)"
                    stroke-width="2"
                />
                <circle
                    :cx="x(hoverIndex)"
                    :cy="y(points[hoverIndex].value)"
                    r="4"
                    fill="var(--viz-series-1)"
                    stroke="var(--viz-surface)"
                    stroke-width="2"
                />
            </template>

            <text
                v-for="(point, index) in points"
                :key="point.label"
                :x="x(index)"
                :y="height - 8"
                text-anchor="middle"
                class="text-[11px] tabular-nums"
                fill="var(--viz-ink-muted)"
            >
                {{ index % labelStride === 0 ? point.label : '' }}
            </text>
        </svg>

        <div
            v-if="hovered"
            class="pointer-events-none absolute top-0 z-10 min-w-40 rounded-xl bg-white p-3 text-xs shadow-lg ring-1 ring-zinc-900/10 dark:bg-zinc-800 dark:ring-white/10"
            :style="tooltipStyle"
        >
            <p class="font-semibold text-zinc-950 dark:text-white">
                {{ hovered.label }}
            </p>
            <p class="mt-1.5 flex items-center gap-2">
                <span
                    class="size-2 shrink-0 rounded-full"
                    style="background: var(--viz-series-1)"
                    aria-hidden="true"
                />
                <span class="text-zinc-500 dark:text-zinc-400">{{
                    seriesLabel
                }}</span>
                <span
                    class="ms-auto numeric font-medium text-zinc-950 dark:text-white"
                    >{{ format(hovered.value) }}</span
                >
            </p>
            <p v-if="hasComparison" class="mt-1 flex items-center gap-2">
                <span
                    class="size-2 shrink-0 rounded-full"
                    style="background: var(--viz-comparison)"
                    aria-hidden="true"
                />
                <span class="text-zinc-500 dark:text-zinc-400">{{
                    comparisonLabel
                }}</span>
                <span
                    class="ms-auto numeric font-medium text-zinc-950 dark:text-white"
                    >{{ format(hovered.comparison ?? 0) }}</span
                >
            </p>
        </div>
    </div>
</template>
