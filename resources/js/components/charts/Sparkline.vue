<script setup lang="ts">
import { computed, useId } from 'vue';

/**
 * A trend in the corner of a figure: a line with a soft fill under it, no axes.
 * It says "rising" or "falling" at a glance; the figure above says how much.
 */
const props = withDefaults(
    defineProps<{
        values: number[];
        /** Any CSS colour, so the caller can pass a theme variable. */
        color: string;
        height?: number;
    }>(),
    { height: 40 },
);

const gradientId = `spark-${useId()}`;

const paths = computed(() => {
    const values = props.values.length > 1 ? props.values : [0, 0];
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const step = 200 / (values.length - 1);
    const top = 4;
    const usable = props.height - top - 2;

    const points = values.map((value, index) => [
        index * step,
        top + usable - ((value - min) / span) * usable,
    ]);
    const line = points
        .map(
            ([x, y], index) =>
                `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`,
        )
        .join(' ');

    return { line, area: `${line} L200,${props.height} L0,${props.height} Z` };
});
</script>

<template>
    <svg
        class="block w-full"
        :height="height"
        :viewBox="`0 0 200 ${height}`"
        preserveAspectRatio="none"
        aria-hidden="true"
    >
        <defs>
            <linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" :stop-color="color" stop-opacity="0.2" />
                <stop offset="1" :stop-color="color" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path :d="paths.area" :fill="`url(#${gradientId})`" />
        <path
            :d="paths.line"
            fill="none"
            :stroke="color"
            stroke-width="1.6"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
