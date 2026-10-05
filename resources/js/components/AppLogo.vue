<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue';
import BrandWordmark from '@/components/BrandWordmark.vue';

withDefaults(
    defineProps<{
        compact?: boolean;
        inverted?: boolean;
        /** The signature line under the wordmark. Off where space is tight, like a printed footer. */
        tagline?: boolean;
    }>(),
    {
        compact: false,
        inverted: false,
        tagline: true,
    },
);
</script>

<template>
    <!--
        Sized in em, so the logo follows the font size of wherever it is placed:
        a class like text-xs on the component shrinks the whole lockup.
    -->
    <div
        class="inline-flex flex-col items-start gap-[0.55em]"
        aria-label="facturac.ao — Emitida. Validada. Paga."
        role="img"
    >
        <BrandMark
            v-if="compact"
            :class="[
                'h-[1.6em] w-auto shrink-0',
                inverted
                    ? 'text-stone-50'
                    : 'text-brand-950 dark:text-stone-50',
            ]"
            aria-hidden="true"
        />
        <template v-else>
            <BrandWordmark
                :class="[
                    'h-[1.375em] w-auto',
                    inverted
                        ? 'text-stone-50'
                        : 'text-brand-950 dark:text-stone-50',
                ]"
                aria-hidden="true"
            />
            <!--
                The three stages of every invoice, each closed by the gold dot
                from the wordmark. It replaces "Feito para humanos", and like the
                line before it makes no claim about the AGT: a document can be
                validated by the AGT, the software cannot say so of itself.
            -->
            <p
                v-if="tagline"
                :class="[
                    'eyebrow',
                    inverted
                        ? 'text-stone-300/75'
                        : 'text-zinc-500 dark:text-zinc-400',
                ]"
                aria-hidden="true"
            >
                Emitida<span class="brand-dot" /> Validada<span
                    class="brand-dot"
                />
                Paga<span class="brand-dot" />
            </p>
        </template>
    </div>
</template>
