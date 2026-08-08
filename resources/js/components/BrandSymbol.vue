<script setup lang="ts">
withDefaults(
    defineProps<{
        animated?: boolean;
        /**
         * Renders the outer ring solid instead of perforated. The perforations
         * fill in below roughly 24px, so use this for favicon-scale marks.
         */
        solidRing?: boolean;
    }>(),
    {
        animated: true,
        solidRing: false,
    },
);
</script>

<template>
    <svg
        viewBox="0 0 64 64"
        xmlns="http://www.w3.org/2000/svg"
        :class="{ 'brand-symbol--animated': animated }"
        fill="none"
        aria-hidden="true"
    >
        <!-- Off-axis, as though pressed by hand. -->
        <g class="brand-symbol__seal" transform="rotate(-8 32 32)">
            <circle
                class="brand-symbol__perf"
                cx="32"
                cy="32"
                r="29"
                stroke="#F9B233"
                stroke-width="2.4"
                stroke-linecap="round"
                :stroke-dasharray="solidRing ? undefined : '0.4 5.2'"
            />
            <circle
                cx="32"
                cy="32"
                r="19.5"
                stroke="#F9B233"
                stroke-width="2.6"
            />
            <path
                class="brand-symbol__check"
                d="M22 33L29.2 40.2L43.8 22.4"
                stroke="currentColor"
                stroke-width="5.4"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </g>
    </svg>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    .brand-symbol--animated .brand-symbol__seal {
        animation: brand-seal-press 620ms cubic-bezier(0.34, 1.4, 0.64, 1) both;
        transform-origin: 32px 32px;
    }

    .brand-symbol--animated .brand-symbol__check {
        /* Path length is ~33; 35 clears it with a little slack. */
        stroke-dasharray: 35;
        animation: brand-check-draw 460ms cubic-bezier(0.22, 1, 0.36, 1) 220ms
            both;
    }
}

@keyframes brand-seal-press {
    from {
        transform: rotate(-14deg) scale(1.14);
        opacity: 0;
    }

    to {
        transform: rotate(-8deg) scale(1);
        opacity: 1;
    }
}

@keyframes brand-check-draw {
    from {
        stroke-dashoffset: 35;
    }

    to {
        stroke-dashoffset: 0;
    }
}
</style>
