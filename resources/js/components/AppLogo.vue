<script setup lang="ts">
withDefaults(
    defineProps<{
        compact?: boolean;
        inverted?: boolean;
    }>(),
    {
        compact: false,
        inverted: false,
    },
);
</script>

<template>
    <div class="flex items-center gap-3">
        <div
            class="relative grid size-9 shrink-0 place-items-center overflow-hidden rounded-xl bg-amber-300 text-brand-950 shadow-[0_10px_30px_-12px_rgba(251,191,36,0.9)]"
            aria-hidden="true"
        >
            <span
                class="absolute -top-3 -right-2 size-7 rotate-45 rounded-sm bg-white/45"
            />
            <span
                class="relative font-display text-lg font-semibold tracking-[-0.08em]"
                >V</span
            >
        </div>
        <div v-if="!compact" class="min-w-0">
            <p
                :class="[
                    'truncate text-sm font-semibold tracking-wide',
                    inverted ? 'text-white' : 'text-zinc-950 dark:text-white',
                ]"
            >
                VAP Fatura
            </p>
            <p
                :class="[
                    'truncate text-[0.65rem] font-medium tracking-[0.18em] uppercase',
                    inverted
                        ? 'text-brand-100/70'
                        : 'text-zinc-500 dark:text-zinc-400',
                ]"
            >
                Fiscal, sem ruído
            </p>
        </div>
    </div>
</template>
