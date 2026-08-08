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
    }>(),
    { categorical: false },
);

const maxValue = computed(() =>
    Math.max(1, ...props.bars.map((bar) => Math.abs(bar.value))),
);

/**
 * Nominal bars share one hue: length already encodes the value, so spending the
 * identity channel on re-encoding it would be waste. Only genuinely distinct
 * categories take their own slot.
 */
function colour(bar: BarDatum, index: number): string {
    if (!props.categorical) {
        return 'var(--viz-series-1)';
    }

    return `var(--viz-series-${bar.slot ?? Math.min(5, index + 1)})`;
}

function share(value: number): number {
    return Math.max(0, (Math.abs(value) / maxValue.value) * 100);
}

const hovered = ref<number | null>(null);
</script>

<template>
    <ul class="space-y-3">
        <li
            v-for="(bar, index) in bars"
            :key="bar.label"
            class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1"
            @pointerenter="hovered = index"
            @pointerleave="hovered = null"
        >
            <p
                class="min-w-0 truncate text-sm text-zinc-700 dark:text-zinc-300"
            >
                {{ bar.label }}
                <span
                    v-if="bar.detail"
                    class="text-zinc-400 dark:text-zinc-500"
                >
                    · {{ bar.detail }}
                </span>
            </p>

            <!-- Value at the tip, always visible: this is the relief the
                 light-mode contrast check requires, not decoration. -->
            <p
                class="numeric text-sm font-medium text-zinc-950 dark:text-white"
            >
                {{ format(bar.value) }}
            </p>

            <div
                class="col-span-2 h-2 w-full overflow-hidden rounded-full"
                :style="{
                    background:
                        'color-mix(in srgb, var(--viz-grid) 60%, transparent)',
                }"
            >
                <div
                    class="h-full rounded-full transition-[width] duration-500 ease-out"
                    :style="{
                        width: `${share(bar.value)}%`,
                        background: colour(bar, index),
                        opacity:
                            hovered === null || hovered === index ? 1 : 0.55,
                    }"
                />
            </div>
        </li>
    </ul>
</template>
