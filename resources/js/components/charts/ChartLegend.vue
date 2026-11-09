<script setup lang="ts">
/**
 * The key above a line chart: one short stroke per series, drawn the way the
 * series is drawn, with its name in text ink. A series with no data is left
 * out by the caller, so the key never names a line the reader cannot find,
 * and a lone series needs no key at all: the chart's title already names it.
 */
defineProps<{
    items: { label: string; color: string; muted?: boolean }[];
}>();
</script>

<template>
    <ul
        v-if="items.length > 1"
        class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-[var(--ui-ink-2)]"
        aria-label="Legenda"
    >
        <li
            v-for="item in items"
            :key="item.label"
            class="inline-flex items-center gap-1.5"
        >
            <span
                class="w-3.5 shrink-0 rounded-full"
                :class="item.muted ? 'h-[1.5px]' : 'h-0.5'"
                :style="{ background: item.color }"
                aria-hidden="true"
            />
            {{ item.label }}
        </li>
    </ul>
</template>
