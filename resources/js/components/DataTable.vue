<script setup lang="ts">
defineProps<{
    /** Named for a screen reader only. */
    caption?: string;
    /** A minimum width, for a table that scrolls rather than squeezes. */
    tableClass?: string;
}>();
</script>

<template>
    <div class="overflow-x-auto overscroll-x-contain">
        <table class="data-table w-full text-left text-sm" :class="tableClass">
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
            <tbody class="divide-y divide-zinc-900/[0.06] dark:divide-white/10">
                <slot />
            </tbody>
        </table>
    </div>
</template>
