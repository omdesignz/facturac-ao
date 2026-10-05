<script setup lang="ts">
type StatusTone =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'
    | 'draft'
    | 'contingency';

withDefaults(
    defineProps<{
        label: string;
        tone?: StatusTone;
        pulse?: boolean;
    }>(),
    {
        tone: 'neutral',
        pulse: false,
    },
);

/**
 * One pill for every status in the app.
 *
 * A single shape — 22px, fully round, 12/600 — so a status reads the same on
 * every page, and the colour always arrives with a word: nobody has to know
 * the palette to know what a state means. The colours keep to the brand's
 * roles: lime for done, gold for something the system is still working on,
 * orange for a warning, red for a failure, grey for everything at rest.
 */
const styles: Record<StatusTone, string> = {
    success: 'bg-lime-300 text-lime-950',
    info: 'bg-accent-400 text-brand-950',
    warning:
        'bg-orange-50 text-orange-700 dark:bg-orange-400/10 dark:text-orange-300',
    danger: 'bg-rose-50 text-rose-700 dark:bg-rose-400/10 dark:text-rose-300',
    contingency:
        'bg-orange-100 text-orange-800 dark:bg-orange-400/15 dark:text-orange-200',
    neutral: 'bg-zinc-200/70 text-zinc-700 dark:bg-white/10 dark:text-zinc-300',
    draft: 'bg-white text-zinc-600 ring-1 ring-zinc-900/10 ring-inset dark:bg-transparent dark:text-zinc-300 dark:ring-white/15',
};
</script>

<template>
    <span
        :class="[
            styles[tone],
            'inline-flex h-[1.375rem] items-center gap-1.5 rounded-full px-2 text-xs font-semibold whitespace-nowrap',
        ]"
    >
        <span v-if="pulse" class="relative flex size-1.5" aria-hidden="true">
            <span
                class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-40 motion-reduce:animate-none"
            />
            <span
                class="relative inline-flex size-1.5 rounded-full bg-current"
            />
        </span>
        {{ label }}
    </span>
</template>
