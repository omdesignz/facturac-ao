<script setup lang="ts">
import BrandSymbol from '@/components/BrandSymbol.vue';

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
    <div
        class="flex items-center gap-2.5"
        aria-label="facturac.ao — Fiscal, sem ruído"
    >
        <BrandSymbol
            :mark-only="compact"
            :class="[
                compact ? 'size-9' : 'h-8 w-auto sm:h-9',
                inverted
                    ? 'text-stone-100'
                    : 'text-[#575756] dark:text-stone-100',
            ]"
        />
        <div v-if="!compact" class="min-w-0">
            <p
                :class="[
                    'truncate text-base font-bold tracking-[-0.035em]',
                    inverted
                        ? 'text-stone-50'
                        : 'text-[#575756] dark:text-stone-50',
                ]"
            >
                facturac<span class="text-[#f9b233]">.ao</span>
            </p>
            <p
                :class="[
                    'truncate text-[0.59rem] font-semibold tracking-[0.2em] uppercase',
                    inverted
                        ? 'text-stone-300/75'
                        : 'text-zinc-500 dark:text-zinc-400',
                ]"
            >
                Fiscal, sem ruído
            </p>
        </div>
    </div>
</template>
