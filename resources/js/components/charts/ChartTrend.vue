<script setup lang="ts">
import { onClickOutside, useElementSize } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

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

/** What the chart is drawn at until the container has been measured. */
const FALLBACK_WIDTH = 720;

/** Room one x-axis label needs, so the stride can follow the real width. */
const LABEL_SLOT = 56;

/** The tooltip's own minimum width (`min-w-40`), used before it is measured. */
const TOOLTIP_MIN = 160;

const box = ref<HTMLElement | null>(null);
const tooltip = ref<HTMLElement | null>(null);

/**
 * The viewBox follows the container's real pixel width, so a phone gets a
 * chart drawn for a phone — 11px axis text stays 11px — instead of a 720-wide
 * drawing shrunk to fit. Height is a prop and never changes, so there is no
 * layout jump when the first measurement lands.
 */
const { width: measuredWidth } = useElementSize(box);
const { width: tooltipWidth } = useElementSize(tooltip);

const WIDTH = computed(() =>
    measuredWidth.value > 0 ? measuredWidth.value : FALLBACK_WIDTH,
);

const hasComparison = computed(
    () =>
        props.comparisonLabel !== undefined &&
        props.points.some((point) => (point.comparison ?? 0) > 0),
);

const plotHeight = computed(() => props.height - PADDING.top - PADDING.bottom);
const plotWidth = computed(() => WIDTH.value - PADDING.left - PADDING.right);

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
        return PADDING.left + plotWidth.value / 2;
    }

    return PADDING.left + (index / (props.points.length - 1)) * plotWidth.value;
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

/**
 * The svg's left edge, read once per interaction instead of on every move:
 * `getBoundingClientRect` forces layout, and a pointer fires dozens of moves a
 * second. The width watcher below drops it when the chart is resized.
 */
let cachedLeft: number | null = null;

watch(WIDTH, () => {
    cachedLeft = null;
});

function trackPointer(event: PointerEvent): void {
    if (cachedLeft === null) {
        cachedLeft = (
            event.currentTarget as SVGSVGElement
        ).getBoundingClientRect().left;
    }

    const position = event.clientX - cachedLeft;

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

function startPointer(event: PointerEvent): void {
    // A fresh touch or press may follow a scroll or a layout shift.
    cachedLeft = null;
    trackPointer(event);
}

function clearHover(): void {
    cachedLeft = null;
    hoverIndex.value = null;
}

/** A mouse leaving ends the hover; a finger lifting leaves the reading up. */
function leavePointer(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        clearHover();
    }
}

onClickOutside(box, clearHover);

/**
 * Keeps the tooltip inside the chart in pixels, using its measured width, so
 * it can never hang off an edge however narrow the container is.
 */
const tooltipStyle = computed(() => {
    if (hoverIndex.value === null) {
        return {};
    }

    const half = Math.min(
        Math.max(tooltipWidth.value, TOOLTIP_MIN) / 2,
        WIDTH.value / 2,
    );
    const centre = Math.min(
        WIDTH.value - half,
        Math.max(half, x(hoverIndex.value)),
    );

    return {
        left: `${centre}px`,
        transform: 'translateX(-50%)',
    };
});

/** Only every nth tick is drawn when the axis would otherwise collide. */
const labelStride = computed(() =>
    Math.ceil(
        props.points.length / Math.max(2, Math.floor(WIDTH.value / LABEL_SLOT)),
    ),
);

const summary = computed(() => {
    const first = props.points[0];
    const last = props.points[props.points.length - 1];

    if (first === undefined || last === undefined) {
        return `${props.seriesLabel} ao longo do tempo`;
    }

    const peak = props.points.reduce((best, point) =>
        point.value > best.value ? point : best,
    );

    return `${props.seriesLabel} ao longo do tempo: ${first.label}, ${props.format(first.value)}; ${last.label}, ${props.format(last.value)}; máximo ${props.format(peak.value)} em ${peak.label}. Ver tabela para todos os valores.`;
});
</script>

<template>
    <div ref="box" class="relative w-full min-w-0">
        <svg
            :viewBox="`0 0 ${WIDTH} ${height}`"
            class="block w-full touch-pan-y"
            :style="{ height: `${height}px` }"
            role="img"
            :aria-label="summary"
            @pointerdown="startPointer"
            @pointermove="trackPointer"
            @pointerleave="leavePointer"
            @pointercancel="clearHover"
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
                stroke-dasharray="5 4"
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
            ref="tooltip"
            class="pointer-events-none absolute top-0 z-10 w-max max-w-full min-w-40 rounded-xl bg-white p-3 text-xs shadow-lg ring-1 ring-zinc-900/10 dark:bg-zinc-800 dark:ring-white/10"
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
