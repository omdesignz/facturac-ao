<script setup lang="ts">
import { computed, useId } from 'vue';
import { formatSaving } from '@/lib/packages';
import type { PeriodOption } from '@/lib/packages';

/**
 * The payment-period switch above the packages: «Mensal · Trimestral · Anual».
 *
 * Native radios, so the browser owns the keyboard: Tab enters the group on the
 * chosen period, the arrow keys move and choose, and a screen reader hears
 * «1 de 3». Each period that saves money says so, so the reason to pick a longer
 * one is visible before it is chosen. Every option is the same height whether it
 * has a saving or not; if the periods do not fit the screen, the control scrolls
 * inside its own box and never the page.
 */
const props = defineProps<{
    modelValue: string;
    options: PeriodOption[];
    /** Names the group for assistive technology, e.g. «Período de pagamento». */
    label: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const group = `period-${useId()}`;

/** Below `sm`: two columns, which is one row for two periods and 2×2 for four. */
const narrowColumns = computed(() =>
    props.options.length === 1 ? 'grid-cols-1' : 'grid-cols-2',
);

/** With three periods the last one takes the whole second row. */
function narrowSpan(index: number): string {
    return props.options.length === 3 && index === 2
        ? 'col-span-2 sm:col-auto'
        : '';
}
</script>

<template>
    <div
        role="radiogroup"
        :aria-label="label"
        :class="[
            narrowColumns,
            'grid w-full max-w-md gap-1 rounded-3xl bg-zinc-900/[0.06] p-1 sm:flex sm:w-fit sm:max-w-full sm:[scrollbar-width:thin] sm:overflow-x-auto sm:overscroll-x-contain sm:rounded-full dark:bg-white/[0.08]',
        ]"
    >
        <label
            v-for="(option, index) in options"
            :key="option.value"
            :class="[
                narrowSpan(index),
                'relative flex min-w-0 cursor-pointer sm:shrink-0',
            ]"
        >
            <input
                type="radio"
                :name="group"
                :value="option.value"
                :checked="modelValue === option.value"
                class="peer sr-only"
                @change="emit('update:modelValue', option.value)"
            />
            <span
                class="grid min-h-12 flex-1 place-items-center rounded-full px-3 text-center text-sm font-semibold whitespace-nowrap text-zinc-700 peer-checked:bg-white peer-checked:text-zinc-950 peer-checked:shadow-sm peer-focus-visible:outline-2 peer-focus-visible:-outline-offset-2 peer-focus-visible:outline-brand-600 motion-safe:transition-colors motion-safe:duration-150 motion-safe:ease-out sm:px-4 dark:text-zinc-300 dark:peer-checked:bg-zinc-100 dark:peer-checked:text-brand-950 dark:peer-focus-visible:outline-brand-300"
            >
                <span>{{ option.label }}</span>
                <span
                    v-if="option.saving !== null"
                    class="text-[0.6875rem] leading-4 font-medium opacity-80"
                >
                    <span aria-hidden="true"
                        >até {{ formatSaving(option.saving) }}</span
                    >
                    <span class="sr-only">poupa até {{ option.saving }}%</span>
                </span>
            </span>
        </label>
    </div>
</template>
