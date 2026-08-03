<script setup lang="ts">
import {
    CircleCheck,
    CircleDashed,
    CircleX,
    Clock3,
    FilePenLine,
    ShieldAlert,
    WifiOff,
} from '@lucide/vue';
import { computed } from 'vue';

type StatusTone =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'
    | 'draft'
    | 'contingency';

const props = withDefaults(
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

const styles: Record<StatusTone, string> = {
    success:
        'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20',
    warning:
        'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20',
    danger: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-400/10 dark:text-rose-300 dark:ring-rose-400/20',
    info: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-300 dark:ring-sky-400/20',
    neutral:
        'bg-zinc-100 text-zinc-700 ring-zinc-500/20 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
    draft: 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-400/10 dark:text-violet-300 dark:ring-violet-400/20',
    contingency:
        'bg-orange-50 text-orange-800 ring-orange-600/20 dark:bg-orange-400/10 dark:text-orange-300 dark:ring-orange-400/20',
};

const icons = {
    success: CircleCheck,
    warning: ShieldAlert,
    danger: CircleX,
    info: Clock3,
    neutral: CircleDashed,
    draft: FilePenLine,
    contingency: WifiOff,
} as const;

const icon = computed(() => icons[props.tone]);
</script>

<template>
    <span
        :class="[
            styles[tone],
            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset',
        ]"
    >
        <span v-if="pulse" class="relative flex size-1.5" aria-hidden="true">
            <span
                class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-40"
            />
            <span
                class="relative inline-flex size-1.5 rounded-full bg-current"
            />
        </span>
        <component
            :is="icon"
            v-else
            class="size-3.5"
            :stroke-width="2"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
