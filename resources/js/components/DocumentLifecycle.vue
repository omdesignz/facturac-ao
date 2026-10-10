<script setup lang="ts">
/**
 * A document's lifecycle as a track: every stage it has really reached, in
 * order, with the time it got there.
 *
 * Shared by the dashboard's focus card and the register's detail panel. The
 * steps come from DocumentSnapshot on the server, so a stage without a
 * timestamp is one the record has not reached, never a guess.
 *
 * The layout follows the width of the component's own box, not the viewport:
 * the same track sits in a full-width phone card and in a narrow panel on a
 * desktop. Below 32rem it is a vertical list (label left, time or detail
 * right); above, a horizontal track with one equal column per step. Either way
 * a label wraps inside its cell, so nothing can push the page sideways.
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

/** The last stage the record has reached; the track is filled up to it. */
function reachedIndex(steps: LifecycleStep[]): number {
    return steps.reduce(
        (last, step, index) => (step.state === 'todo' ? last : index),
        0,
    );
}

const stateSuffix: Record<LifecycleStep['state'], string> = {
    done: ', concluído',
    current: ', em curso',
    error: ', com problema',
    todo: ', por alcançar',
};

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

function trailing(step: LifecycleStep): string {
    return step.key === 'paid'
        ? (step.detail ?? (step.state === 'done' ? 'liquidada' : '—'))
        : formatTime(step.at);
}
</script>

<template>
    <div class="@container w-full min-w-0">
        <ol
            class="grid grid-cols-1 @lg:auto-cols-fr @lg:grid-flow-col @lg:grid-cols-none"
            aria-label="Estado do documento"
        >
            <li
                v-for="(step, index) in steps"
                :key="step.key"
                class="relative flex min-w-0 items-center gap-3 py-1.5 @lg:flex-col @lg:items-stretch @lg:gap-0 @lg:py-0"
                :aria-current="step.state === 'current' ? 'step' : undefined"
            >
                <!-- The track, drawn per column so it ends at the first and
                     last dot and never reaches past the component's box. -->
                <span
                    v-if="index > 0"
                    class="absolute start-0 top-[0.6875rem] hidden h-0.5 w-1/2 @lg:block"
                    :class="
                        index <= reachedIndex(steps)
                            ? 'bg-brand-950 dark:bg-zinc-100'
                            : 'bg-zinc-900/10 dark:bg-white/10'
                    "
                    aria-hidden="true"
                />
                <span
                    v-if="index < steps.length - 1"
                    class="absolute end-0 top-[0.6875rem] hidden h-0.5 w-1/2 @lg:block"
                    :class="
                        index + 1 <= reachedIndex(steps)
                            ? 'bg-brand-950 dark:bg-zinc-100'
                            : 'bg-zinc-900/10 dark:bg-white/10'
                    "
                    aria-hidden="true"
                />

                <span
                    class="relative grid h-5 w-4 shrink-0 place-items-center @lg:h-6 @lg:w-full"
                    aria-hidden="true"
                >
                    <span
                        class="rounded-full"
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
                    />
                </span>

                <span
                    class="flex min-w-0 flex-1 items-baseline justify-between gap-3 text-xs/5 font-semibold @lg:mt-2 @lg:block @lg:flex-none @lg:px-1 @lg:text-center @lg:text-xs/4"
                    :class="
                        step.state === 'error'
                            ? 'text-rose-600 dark:text-rose-400'
                            : step.state === 'todo'
                              ? 'text-zinc-500 dark:text-zinc-400'
                              : 'text-zinc-950 dark:text-white'
                    "
                >
                    <span class="min-w-0"
                        >{{ step.label
                        }}<span class="sr-only">{{
                            stateSuffix[step.state]
                        }}</span></span
                    >
                    <span
                        class="min-w-0 text-end font-normal @lg:mt-0.5 @lg:block @lg:text-center"
                        :class="
                            step.state === 'error'
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-zinc-500 dark:text-zinc-400'
                        "
                        >{{ trailing(step) }}</span
                    >
                </span>
            </li>
        </ol>
    </div>
</template>
