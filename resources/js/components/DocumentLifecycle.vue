<script setup lang="ts">
/**
 * A document's lifecycle as a track: every stage it has really reached, in
 * order, with the time it got there.
 *
 * Shared by the dashboard's focus card and the register's detail panel. The
 * steps come from DocumentSnapshot on the server, so a stage without a
 * timestamp is one the record has not reached, never a guess.
 */
export interface LifecycleStep {
    key: string;
    label: string;
    at: string | null;
    state: 'done' | 'current' | 'error' | 'todo';
    detail: string | null;
}

defineProps<{
    steps: LifecycleStep[];
}>();

function progressFor(steps: LifecycleStep[]): number {
    const reached = steps.reduce(
        (last, step, index) => (step.state === 'todo' ? last : index),
        0,
    );

    return steps.length > 1 ? reached / (steps.length - 1) : 1;
}

function stepPosition(index: number, count: number): string {
    return `${count > 1 ? (index / (count - 1)) * 100 : 0}%`;
}

function stepAlignment(index: number, count: number): string {
    if (index === 0) {
        return 'translate-x-0 text-left';
    }

    if (index === count - 1) {
        return '-translate-x-full text-right';
    }

    return '-translate-x-1/2 text-center';
}

function formatTime(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-AO', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Africa/Luanda',
    }).format(new Date(value));
}
</script>

<template>
    <div>
        <div class="relative mx-1 h-6">
            <span
                class="absolute inset-x-0 top-[0.6875rem] h-0.5 rounded-full bg-zinc-900/10 dark:bg-white/10"
            />
            <span
                class="absolute top-[0.6875rem] left-0 h-0.5 rounded-full bg-brand-950 dark:bg-zinc-100"
                :style="{
                    width: `${progressFor(steps) * 100}%`,
                }"
            />
            <span
                v-for="(step, index) in steps"
                :key="step.key"
                class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full"
                :style="{
                    left: stepPosition(index, steps.length),
                }"
                :class="{
                    'size-2.5 bg-brand-950 ring-[3px] ring-zinc-100 dark:bg-zinc-100 dark:ring-zinc-900':
                        step.state === 'done',
                    'size-4 bg-accent-400 ring-4 ring-accent-400/25':
                        step.state === 'current',
                    'size-4 bg-white ring-4 [box-shadow:inset_0_0_0_4px_var(--color-rose-500)] ring-rose-500/25 dark:bg-zinc-900':
                        step.state === 'error',
                    'size-2.5 bg-zinc-100 ring-2 ring-zinc-300 dark:bg-zinc-900 dark:ring-zinc-600':
                        step.state === 'todo',
                }"
                aria-hidden="true"
            />
        </div>
        <ol class="relative mt-2 h-12" aria-label="Estado do documento">
            <li
                v-for="(step, index) in steps"
                :key="step.key"
                class="absolute top-0 text-xs font-semibold whitespace-nowrap"
                :class="[
                    stepAlignment(index, steps.length),
                    step.state === 'error'
                        ? 'text-rose-600 dark:text-rose-400'
                        : step.state === 'todo'
                          ? 'text-zinc-400 dark:text-zinc-500'
                          : 'text-zinc-950 dark:text-white',
                ]"
                :style="{
                    left: stepPosition(index, steps.length),
                }"
            >
                {{ step.label }}
                <span
                    class="mt-0.5 block font-normal"
                    :class="
                        step.state === 'error'
                            ? 'text-rose-600 dark:text-rose-400'
                            : 'text-zinc-500 dark:text-zinc-400'
                    "
                    >{{
                        step.key === 'paid'
                            ? (step.detail ??
                              (step.state === 'done' ? 'liquidada' : '—'))
                            : formatTime(step.at)
                    }}</span
                >
            </li>
        </ol>
    </div>
</template>
