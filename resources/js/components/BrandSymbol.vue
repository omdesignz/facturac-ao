<script setup lang="ts">
withDefaults(
    defineProps<{
        animated?: boolean;
        markOnly?: boolean;
    }>(),
    {
        animated: true,
        markOnly: false,
    },
);
</script>

<template>
    <svg
        :viewBox="markOnly ? '400 -37 442 442' : '0 0 841.89 368'"
        xmlns="http://www.w3.org/2000/svg"
        :class="{ 'brand-symbol--animated': animated }"
        fill="none"
        aria-hidden="true"
    >
        <g v-if="!markOnly" class="brand-symbol__letters">
            <path
                d="M58.61 184H234.87V243.48H58.61V360.69H0V184Z"
                fill="currentColor"
            />
            <path
                d="M273.32 7.73H264.18H36.72L95.76 67.22H264.18V360.26H323.23V66.78H440.44V7.73H273.32Z"
                fill="currentColor"
            />
        </g>
        <path
            class="brand-symbol__confirmation"
            d="M680.82 248.74A50.13 50.13 0 0 1 609.82 248.74L538.73 177.66A50.17 50.17 0 1 1 609.73 106.66L645.34 142.25L743.65 44A183.12 183.12 0 0 0 624.44 0C522.82 0 440.44 82.38 440.44 184A184 184 0 0 0 624.44 368C726.05 368 808.44 285.62 808.44 184A184.16 184.16 0 0 0 800.2 129.39L680.82 248.74Z"
            fill="#F9B233"
        />
        <path
            class="brand-symbol__check"
            d="M645.34 230A16.63 16.63 0 0 1 633.52 225.1L562.43 154A16.72 16.72 0 0 1 586.08 130.35L645.34 189.61L813.34 21.61A16.73 16.73 0 1 1 837 45.28L657.17 225.09A16.64 16.64 0 0 1 645.34 230Z"
            fill="currentColor"
        />
    </svg>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    .brand-symbol--animated .brand-symbol__letters {
        animation: brand-letters-arrive 560ms cubic-bezier(0.22, 1, 0.36, 1)
            both;
        transform-box: fill-box;
        transform-origin: center;
    }

    .brand-symbol--animated .brand-symbol__confirmation {
        animation: brand-confirmation-arrive 680ms
            cubic-bezier(0.34, 1.56, 0.64, 1) 90ms both;
        transform-box: fill-box;
        transform-origin: center;
    }

    .brand-symbol--animated .brand-symbol__check {
        animation: brand-check-arrive 520ms cubic-bezier(0.22, 1, 0.36, 1) 270ms
            both;
        transform-box: fill-box;
        transform-origin: center;
    }
}

@keyframes brand-letters-arrive {
    from {
        transform: translateX(-10px);
    }

    to {
        transform: translateX(0);
    }
}

@keyframes brand-confirmation-arrive {
    from {
        transform: scale(0.86) rotate(-7deg);
    }

    to {
        transform: scale(1) rotate(0deg);
    }
}

@keyframes brand-check-arrive {
    from {
        transform: translate(-14px, 14px);
    }

    to {
        transform: translate(0, 0);
    }
}
</style>
