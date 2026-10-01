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
        aria-label="facturac.ao — Feito para humanos, para negócios angolanos."
    >
        <BrandSymbol
            :class="[
                compact ? 'size-9' : 'size-10 sm:size-11',
                'shrink-0',
                inverted
                    ? 'text-stone-100'
                    : 'text-brand-600 dark:text-stone-100',
            ]"
        />
        <div v-if="!compact" class="min-w-0">
            <p
                :class="[
                    'brand-wordmark truncate text-base',
                    inverted
                        ? 'text-stone-50'
                        : 'text-brand-600 dark:text-stone-50',
                ]"
            >
                facturac<span class="text-accent-400">.ao</span>
            </p>
            <!--
                Stacked rather than run on one line: at the lockup's tracking
                the full slogan is wider than the 288px sidebar and would
                truncate. aria-label above carries it as one sentence.

                The second line used to read "Aceite pela AGT", which claimed an
                endorsement the product does not hold. A document can be aceite
                pela AGT — the software saying it of itself is a different claim
                altogether, and not one to make on every screen.
            -->
            <p
                :class="[
                    'eyebrow',
                    inverted
                        ? 'text-stone-300/75'
                        : 'text-zinc-500 dark:text-zinc-400',
                ]"
                aria-hidden="true"
            >
                <span class="block truncate">Feito para humanos</span>
                <span class="block truncate">Para negócios angolanos</span>
            </p>
        </div>
    </div>
</template>
