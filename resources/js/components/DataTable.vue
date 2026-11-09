<script setup lang="ts">
defineProps<{
    /** Named for a screen reader only. */
    caption?: string;
    /** A minimum width, for a table that scrolls rather than squeezes. */
    tableClass?: string;
    /** Lets `InfiniteScroll` find the rows it appends to. */
    bodyId?: string;
    /** The register's inset surface, sharing the heading's outer gutters. */
    edge?: boolean;
}>();
</script>

<template>
    <div
        class="overflow-x-auto overscroll-x-contain"
        :class="
            edge
                ? 'mx-4 my-4 rounded-xl bg-[var(--surface-raised)] shadow-[var(--ui-card-shadow)] sm:mx-6'
                : ''
        "
    >
        <table
            class="data-table w-full text-left text-sm"
            :class="[tableClass, edge ? 'data-table-edge' : '']"
        >
            <caption v-if="caption" class="sr-only">
                {{
                    caption
                }}
            </caption>
            <thead>
                <tr
                    class="border-b border-zinc-900/[0.07] dark:border-white/10"
                >
                    <slot name="head" />
                </tr>
            </thead>
            <tbody
                :id="bodyId"
                class="divide-y divide-zinc-900/[0.06] dark:divide-white/10"
            >
                <slot />
            </tbody>
        </table>
    </div>
</template>
