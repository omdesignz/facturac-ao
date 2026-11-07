<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * What was billed each day, one bar per day, today in gold. Hovering or
 * arrowing along the bars names the day and its amount; the same figures sit
 * in a table for screen readers.
 */
const props = defineProps<{
    days: { date: string; billed_minor: number }[];
    currencyLabel: string;
}>();

const PLOT_HEIGHT = 188;

const active = ref<number | null>(null);

/** A round ceiling for the axis, so the ticks read 400 mil, 800 mil, 1,2 M. */
const scaleMax = computed(() => {
    const max = Math.max(0, ...props.days.map((day) => day.billed_minor / 100));

    if (max === 0) {
        return 4;
    }

    const step = max / 4;
    const magnitude = 10 ** Math.floor(Math.log10(step));
    const nice =
        [1, 2, 2.5, 5, 10].find((factor) => factor * magnitude >= step) ?? 10;

    return nice * magnitude * 4;
});

const ticks = computed(() =>
    [4, 3, 2, 1, 0].map((index) => (scaleMax.value / 4) * index),
);

function axisLabel(value: number): string {
    if (value === 0) {
        return '0';
    }

    const format = (amount: number) =>
        new Intl.NumberFormat('pt-AO', { maximumFractionDigits: 1 }).format(
            amount,
        );

    if (value >= 1_000_000) {
        return `${format(value / 1_000_000)} M`;
    }

    if (value >= 1_000) {
        return `${format(value / 1_000)} mil`;
    }

    return format(value);
}

function amount(minor: number): string {
    return `${new Intl.NumberFormat('pt-AO', { maximumFractionDigits: 0 }).format(Math.round(minor / 100))} ${props.currencyLabel}`;
}

const shortMonth = new Intl.DateTimeFormat('pt-PT', {
    month: 'short',
    timeZone: 'UTC',
});

const longDay = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
});

/**
 * Calendar days, printed in UTC so no browser timezone moves them. The short
 * form is built by hand: the locale's own short date is "9/10", not "9 out".
 */
function dayLabel(date: string, style: 'short' | 'long'): string {
    const day = new Date(`${date}T00:00:00Z`);

    if (style === 'long') {
        return longDay.format(day);
    }

    return `${day.getUTCDate()} ${shortMonth.format(day).replace('.', '')}`;
}

const lastIndex = computed(() => props.days.length - 1);

/** Five evenly spaced dates under the bars, the last one always "Hoje". */
const axisDates = computed(() => {
    const count = props.days.length;

    if (count === 0) {
        return [];
    }

    const marks = Math.min(5, count);

    return Array.from({ length: marks }, (_, mark) => {
        const index = Math.round((mark * (count - 1)) / (marks - 1 || 1));

        return index === count - 1
            ? 'Hoje'
            : dayLabel(props.days[index].date, 'short');
    });
});

const tooltip = computed(() => {
    if (active.value === null || !props.days[active.value]) {
        return null;
    }

    const day = props.days[active.value];
    const centre =
        props.days.length > 1
            ? ((active.value + 0.5) / props.days.length) * 100
            : 50;
    /** Beside the bar, never on it: to its right in the first half, to its left after. */
    const toRight = centre < 50;

    return {
        day:
            active.value === lastIndex.value
                ? `Hoje, ${dayLabel(day.date, 'short')}`
                : dayLabel(day.date, 'long'),
        amount: amount(day.billed_minor),
        left: `calc(${centre}% ${toRight ? '+' : '-'} 0.875rem)`,
        toRight,
    };
});

function barHeight(minor: number): string {
    if (minor <= 0) {
        return '0px';
    }

    return `max(2px, ${(minor / 100 / scaleMax.value) * 100}%)`;
}

function barColour(index: number): string {
    if (index === lastIndex.value) {
        return 'var(--color-accent-400)';
    }

    return index === active.value ? 'var(--ui-ink)' : 'var(--ui-chart-bar)';
}

function pick(event: PointerEvent): void {
    const plot = event.currentTarget as HTMLElement;
    const rect = plot.getBoundingClientRect();
    const share = (event.clientX - rect.left) / rect.width;

    active.value = Math.min(
        lastIndex.value,
        Math.max(0, Math.floor(share * props.days.length)),
    );
}

function step(event: KeyboardEvent): void {
    const moves: Record<string, number> = {
        ArrowLeft: -1,
        ArrowRight: 1,
        Home: -Infinity,
        End: Infinity,
    };
    const move = moves[event.key];

    if (move === undefined) {
        return;
    }

    event.preventDefault();
    const from = active.value ?? lastIndex.value;
    active.value = Math.min(lastIndex.value, Math.max(0, from + move));
}

const summary = computed(() => {
    if (props.days.length === 0) {
        return '';
    }

    const first = props.days[0];
    const today = props.days[lastIndex.value];

    return `Facturação por dia de ${dayLabel(first.date, 'short')} a ${dayLabel(today.date, 'short')}; hoje ${amount(today.billed_minor)}.`;
});
</script>

<template>
    <div class="flex gap-3">
        <div
            class="-mbs-1.5 flex h-[12.5rem] w-11 shrink-0 flex-col justify-between text-end numeric text-[0.6875rem] text-[var(--ui-ink-3)]"
            aria-hidden="true"
        >
            <span v-for="tick in ticks" :key="tick">{{ axisLabel(tick) }}</span>
        </div>
        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <div
                class="relative rounded-md outline-offset-4 focus-visible:outline-2 focus-visible:outline-accent-400"
                :style="{ height: `${PLOT_HEIGHT}px` }"
                tabindex="0"
                role="group"
                :aria-label="summary"
                aria-roledescription="gráfico"
                @pointermove="pick"
                @pointerleave="active = null"
                @keydown="step"
                @blur="active = null"
            >
                <div
                    class="pointer-events-none absolute inset-0 flex flex-col justify-between"
                    aria-hidden="true"
                >
                    <span
                        v-for="tick in ticks"
                        :key="tick"
                        class="h-px"
                        :class="
                            tick === 0
                                ? 'bg-[var(--ui-line-strong)]'
                                : 'bg-[var(--ui-chart-grid)]'
                        "
                    />
                </div>
                <div
                    class="absolute inset-0 flex items-end gap-[3px]"
                    aria-hidden="true"
                >
                    <div
                        v-for="(day, index) in days"
                        :key="day.date"
                        class="flex h-full min-w-0 flex-1 items-end rounded-[5px] px-0.5"
                        :class="
                            index === active
                                ? 'bg-[var(--ui-chart-column)]'
                                : ''
                        "
                    >
                        <div
                            class="w-full rounded-t-[3px] rounded-b-[1px]"
                            :style="{
                                height: barHeight(day.billed_minor),
                                backgroundColor: barColour(index),
                            }"
                        />
                    </div>
                </div>
                <div
                    v-if="tooltip"
                    class="pointer-events-none absolute inset-bs-1 flex min-w-[9.5rem] flex-col gap-0.5 rounded-lg bg-[var(--ui-pop)] px-2.5 py-2 whitespace-nowrap shadow-[var(--ui-pop-shadow)]"
                    :class="tooltip.toRight ? '' : '-translate-x-full'"
                    :style="{ left: tooltip.left }"
                    aria-live="polite"
                >
                    <span
                        class="text-[0.71875rem] text-[var(--ui-ink-3)] first-letter:uppercase"
                        >{{ tooltip.day }}</span
                    >
                    <span
                        class="numeric text-[0.8125rem] font-semibold text-[var(--ui-ink)]"
                        >{{ tooltip.amount }}</span
                    >
                </div>
            </div>
            <div
                class="flex justify-between text-[0.6875rem] text-[var(--ui-ink-3)]"
                aria-hidden="true"
            >
                <span v-for="(label, index) in axisDates" :key="index">{{
                    label
                }}</span>
            </div>
        </div>

        <table class="sr-only">
            <caption>
                Facturação por dia
            </caption>
            <thead>
                <tr>
                    <th scope="col">Dia</th>
                    <th scope="col">Facturado</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="day in days" :key="day.date">
                    <th scope="row">{{ dayLabel(day.date, 'long') }}</th>
                    <td>{{ amount(day.billed_minor) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
