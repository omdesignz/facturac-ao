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
    <div>
        <header
            class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
        >
            <div class="max-w-3xl min-w-0">
                <p
                    class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{ eyebrow }}
                </p>
                <h1
                    class="mt-2.5 text-page-title leading-[1.08] font-normal tracking-[-0.028em] text-balance text-zinc-950 dark:text-white"
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
                    class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                >
                    {{ description }}
                </p>
            </div>
            <div
                v-if="$slots.actions"
                class="flex flex-wrap items-center gap-2.5"
            >
                <slot name="actions" />
            </div>
        </header>
        <dl
            v-if="$slots.stats"
            class="mt-7 grid grid-cols-2 gap-y-6 sm:flex sm:flex-wrap"
        >
            <slot name="stats" />
        </dl>
    </div>
</template>
