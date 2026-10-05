<script setup lang="ts">
/**
 * The short form of the wordmark: "c." on its own.
 *
 * It is the hinge of the name, the exact spot where "facturação" becomes the
 * domain, so it stands in for the whole logo wherever there is only room for
 * one letter: the collapsed sidebar, the app icon and the favicon. Like
 * BrandWordmark it is Century Gothic Bold converted to outlines.
 */
withDefaults(
    defineProps<{
        title?: string;
    }>(),
    {
        title: 'facturac.ao',
    },
);
</script>

<template>
    <svg
        viewBox="2.15 23.51 83.86 59.86"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        :aria-label="title"
    >
        <path
            class="fill-current"
            d="M58.74 37.62 47.66 43.72Q44.53 40.45 41.48 39.18Q38.43 37.91 34.33 37.91Q26.86 37.91 22.24 42.38Q17.63 46.85 17.63 53.83Q17.63 60.62 22.07 64.91Q26.51 69.21 33.74 69.21Q42.68 69.21 47.66 63.11L58.15 70.28Q49.61 81.37 34.03 81.37Q20.02 81.37 12.08 73.07Q4.15 64.77 4.15 53.63Q4.15 45.92 8.01 39.42Q11.87 32.93 18.77 29.22Q25.68 25.51 34.23 25.51Q42.14 25.51 48.44 28.66Q54.74 31.81 58.74 37.62Z"
        />
        <circle
            cx="74.01"
            cy="70"
            r="10"
            :style="{
                fill: 'var(--wordmark-dot, var(--color-accent-400))',
            }"
        />
    </svg>
</template>
