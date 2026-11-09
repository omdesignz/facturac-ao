<script setup lang="ts">
/**
 * The top of every working screen: where you are, what the page is, one line
 * on what it does, and its actions on the right.
 *
 * Deliberately plain. A page opens on its content, not on a banner; figures
 * that belong to the page go in the `stats` slot as PageStat items, which
 * render as one hairline-divided row under the title.
 */
defineProps<{
    /** Where the page sits, e.g. "AGT · Ligação". */
    eyebrow: string;
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="border-b border-[var(--ui-line)] pb-5">
        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div class="max-w-3xl min-w-0">
                <p class="text-[0.8125rem] text-[var(--ui-ink-3)]">
                    {{ eyebrow }}
                </p>
                <h1
                    class="mt-0.5 text-page-title leading-[1.2] font-[650] tracking-[-0.025em] text-balance text-[var(--ui-ink)]"
                >
                    {{ title }}
                </h1>
                <div
                    v-if="$slots.meta"
                    class="mt-3 flex flex-wrap items-center gap-2"
                >
                    <slot name="meta" />
                </div>
                <p
                    v-if="description"
                    class="mt-1.5 max-w-2xl text-[0.8125rem]/5 text-[var(--ui-ink-3)]"
                >
                    {{ description }}
                </p>
            </div>
            <div
                v-if="$slots.actions"
                class="flex flex-wrap items-center gap-2.5 lg:shrink-0 lg:flex-nowrap"
            >
                <slot name="actions" />
            </div>
        </header>
        <dl
            v-if="$slots.stats"
            class="mt-5 grid grid-cols-2 gap-y-5 sm:flex sm:flex-wrap"
        >
            <slot name="stats" />
        </dl>
    </div>
</template>
