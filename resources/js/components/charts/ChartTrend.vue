<script setup lang="ts">
import { onClickOutside, useElementSize } from '@vueuse/core';
import { computed, ref, useId, watch } from 'vue';
import { axisNumber, niceTicks } from '@/lib/chart-axis';

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
        /** Formats a gridline value; amounts in cents pass `axisAmount`. */
        tickFormat?: (value: number) => string;
        seriesLabel: string;
        comparisonLabel?: string;
        height?: number;
        highlightLast?: boolean;
        area?: boolean;
    }>(),
    {
        height: 220,
        comparisonLabel: undefined,
        tickFormat: axisNumber,
        highlightLast: false,
        area: true,
    },
);

const PADDING = { top: 14, right: 20, bottom: 28 };

/** What the chart is drawn at until the container has been measured. */
const FALLBACK_WIDTH = 720;

/** Room one x-axis label needs, so the stride can follow the real width. */
const LABEL_SLOT = 60;

/** Roughly one 11px digit, to size the tick gutter from its longest label. */
const TICK_CHAR = 6.4;

/** The tooltip's own minimum width (`min-w-40`), used before it is measured. */
const TOOLTIP_MIN = 160;

const box = ref<HTMLElement | null>(null);
const tooltip = ref<HTMLElement | null>(null);
const fillId = `trend-fill-${useId()}`;

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

/**
 * Gridlines on round values from zero: four bands when there is height for
 * them, three on a short chart, so they never crowd the line they frame.
 */
const ticks = computed(() => {
    const peak = Math.max(
        0,
        ...props.points.flatMap((point) => [
            point.value,
            hasComparison.value ? (point.comparison ?? 0) : 0,
        ]),
    );

    return niceTicks(peak, plotHeight.value >= 150 ? 4 : 3);
});

const maxValue = computed(() => ticks.value[ticks.value.length - 1] || 1);

const tickLabels = computed(() =>
    ticks.value.map((value) => props.tickFormat(value)),
);

/** The gutter is as wide as the longest tick, so the plot keeps every pixel it can. */
const left = computed(
    () =>
        Math.ceil(
            Math.max(...tickLabels.value.map((label) => label.length), 1) *
                TICK_CHAR,
        ) + 12,
);

const plotWidth = computed(() => WIDTH.value - left.value - PADDING.right);

function x(index: number): number {
    if (props.points.length <= 1) {
        return left.value + plotWidth.value / 2;
    }

    return left.value + (index / (props.points.length - 1)) * plotWidth.value;
}

function y(value: number): number {
    return PADDING.top + plotHeight.value * (1 - value / maxValue.value);
}

function path(pick: (point: TrendPoint) => number): string {
    return props.points
        .map(
            (point, index) =>
                `${index === 0 ? 'M' : 'L'}${x(index).toFixed(1)},${y(pick(point)).toFixed(1)}`,
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

const hoverIndex = ref<number | null>(null);

const hovered = computed(() =>
    hoverIndex.value === null ? null : props.points[hoverIndex.value],
);

const lastIndex = computed(() => props.points.length - 1);

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

/** The keyboard reads the same points the pointer does, one arrow at a time. */
function step(event: KeyboardEvent): void {
    const moves: Record<string, number> = {
        ArrowLeft: -1,
        ArrowRight: 1,
        Home: -Infinity,
        End: Infinity,
    };
    const move = moves[event.key];

    if (move === undefined || props.points.length === 0) {
        return;
    }

    event.preventDefault();
    const from = hoverIndex.value ?? lastIndex.value;
    hoverIndex.value = Math.min(lastIndex.value, Math.max(0, from + move));
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
        props.points.length /
            Math.max(2, Math.floor(plotWidth.value / LABEL_SLOT)),
    ),
);

/**
 * Labels run on the stride from the start; the last point is always named,
 * and the one before it gives way if the two would touch.
 */
function showsLabel(index: number): boolean {
    if (index === lastIndex.value) {
        return true;
    }

    return (
        index % labelStride.value === 0 &&
        lastIndex.value - index >= Math.max(1, labelStride.value * 0.75)
    );
}

const endLabel = computed(() => {
    const point = props.points[lastIndex.value];

    if (point === undefined) {
        return null;
    }

    const top = y(point.value);

    // Above the dot when there is room, beneath it when the line is at the top.
    return {
        x: x(lastIndex.value) - 8,
        y: top - 10 < PADDING.top + 4 ? top + 18 : top - 10,
        text: props.format(point.value),
    };
});

const summary = computed(() => {
    const first = props.points[0];
    const last = props.points[props.points.length - 1];

    if (first === undefined || last === undefined) {
        return `${props.seriesLabel} ao longo do tempo`;
    }

    const peak = props.points.reduce((best, point) =>
        point.value > best.value ? point : best,
    );

    return `${props.seriesLabel} ao longo do tempo: ${first.label}, ${props.format(first.value)}; ${last.label}, ${props.format(last.value)}; máximo ${props.format(peak.value)} em ${peak.label}. Setas para percorrer; ver tabela para todos os valores.`;
});
</script>

<template>
    <div ref="box" class="relative w-full min-w-0">
        <svg
            :viewBox="`0 0 ${WIDTH} ${height}`"
            class="block w-full touch-pan-y rounded-md outline-offset-4 focus-visible:outline-2 focus-visible:outline-accent-400"
            :style="{ height: `${height}px` }"
            role="img"
            tabindex="0"
            :aria-label="summary"
            @pointerdown="startPointer"
            @pointermove="trackPointer"
            @pointerleave="leavePointer"
            @pointercancel="clearHover"
            @keydown="step"
            @blur="clearHover"
        >
            <defs>
                <linearGradient :id="fillId" x1="0" y1="0" x2="0" y2="1">
                    <stop
                        offset="0"
                        stop-color="var(--viz-primary)"
                        stop-opacity="0.12"
                    />
                    <stop
                        offset="1"
                        stop-color="var(--viz-primary)"
                        stop-opacity="0"
                    />
                </linearGradient>
            </defs>

            <!-- Solid hairlines one step off the surface, the baseline one
                 step firmer: there to read against, never competing. -->
            <g aria-hidden="true">
                <template v-for="(value, index) in ticks" :key="value">
                    <line
                        :x1="left"
                        :x2="WIDTH - PADDING.right"
                        :y1="y(value)"
                        :y2="y(value)"
                        :stroke="
                            value === 0 ? 'var(--viz-axis)' : 'var(--viz-grid)'
                        "
                        stroke-width="1"
                        shape-rendering="crispEdges"
                    />
                    <text
                        :x="left - 10"
                        :y="y(value)"
                        dy="0.32em"
                        text-anchor="end"
                        class="text-[11px] tabular-nums"
                        fill="var(--viz-ink-muted)"
                    >
                        {{ tickLabels[index] }}
                    </text>
                </template>
            </g>

            <path
                v-if="hasComparison"
                :d="comparisonPath"
                fill="none"
                stroke="var(--viz-comparison)"
                stroke-width="1.5"
                stroke-linejoin="round"
                stroke-linecap="round"
            />

            <path v-if="area" :d="areaPath" :fill="`url(#${fillId})`" />
            <path
                :d="linePath"
                fill="none"
                stroke="var(--viz-primary)"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />

            <template v-if="highlightLast && endLabel && hoverIndex === null">
                <circle
                    :cx="x(lastIndex)"
                    :cy="y(points[lastIndex].value)"
                    r="4.5"
                    fill="var(--color-accent-400)"
                    stroke="var(--viz-surface)"
                    stroke-width="2"
                />
                <text
                    :x="endLabel.x"
                    :y="endLabel.y"
                    text-anchor="end"
                    class="text-xs font-semibold tabular-nums"
                    fill="var(--ui-ink)"
                    stroke="var(--viz-surface)"
                    stroke-width="4"
                    paint-order="stroke"
                >
                    {{ endLabel.text }}
                </text>
            </template>

            <template v-if="hoverIndex !== null">
                <line
                    :x1="x(hoverIndex)"
                    :x2="x(hoverIndex)"
                    :y1="PADDING.top"
                    :y2="PADDING.top + plotHeight"
                    stroke="var(--viz-axis)"
                    stroke-width="1"
                    shape-rendering="crispEdges"
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
                    r="4.5"
                    fill="var(--viz-primary)"
                    stroke="var(--viz-surface)"
                    stroke-width="2"
                />
            </template>

            <g aria-hidden="true">
                <template v-for="(point, index) in points" :key="point.label">
                    <text
                        v-if="showsLabel(index)"
                        :x="x(index)"
                        :y="height - 8"
                        text-anchor="middle"
                        class="text-[11px] tabular-nums"
                        :fill="
                            index === hoverIndex
                                ? 'var(--ui-ink)'
                                : 'var(--viz-ink-muted)'
                        "
                    >
                        {{ point.label }}
                    </text>
                </template>
            </g>
        </svg>

        <div
            v-if="hovered"
            ref="tooltip"
            class="pointer-events-none absolute top-0 z-10 flex w-max max-w-full min-w-40 flex-col gap-1 rounded-lg bg-[var(--ui-pop)] px-3 py-2.5 text-xs shadow-[var(--ui-pop-shadow)]"
            :style="tooltipStyle"
            aria-live="polite"
        >
            <p class="text-[var(--ui-ink-3)] first-letter:uppercase">
                {{ hovered.label }}
            </p>
            <p class="flex items-center gap-2">
                <span
                    class="h-0.5 w-3 shrink-0 rounded-full"
                    style="background: var(--viz-primary)"
                    aria-hidden="true"
                />
                <span class="text-[var(--ui-ink-2)]">{{ seriesLabel }}</span>
                <span
                    class="ms-auto ps-3 numeric font-semibold text-[var(--ui-ink)]"
                    >{{ format(hovered.value) }}</span
                >
            </p>
            <p v-if="hasComparison" class="flex items-center gap-2">
                <span
                    class="h-0.5 w-3 shrink-0 rounded-full"
                    style="background: var(--viz-comparison)"
                    aria-hidden="true"
                />
                <span class="text-[var(--ui-ink-2)]">{{
                    comparisonLabel
                }}</span>
                <span class="ms-auto ps-3 numeric text-[var(--ui-ink-2)]">{{
                    format(hovered.comparison ?? 0)
                }}</span>
            </p>
        </div>
    </div>
</template>
