<script setup lang="ts">
import BrandSymbol from '@/components/BrandSymbol.vue';

type FiscalOrbMode = 'breathing' | 'shaping' | 'connecting' | 'settling';

withDefaults(
    defineProps<{
        active?: boolean;
        label: string;
        mode?: FiscalOrbMode;
        size?: 'sm' | 'lg';
    }>(),
    {
        active: false,
        mode: 'connecting',
        size: 'lg',
    },
);

const particles = [
    { cx: 32, cy: 10, radius: 2.2, delay: -0.1 },
    { cx: 43, cy: 13, radius: 1.7, delay: -0.45 },
    { cx: 51, cy: 21, radius: 2.4, delay: -0.8 },
    { cx: 54, cy: 32, radius: 1.8, delay: -1.15 },
    { cx: 49, cy: 43, radius: 2.1, delay: -1.5 },
    { cx: 41, cy: 51, radius: 1.6, delay: -1.85 },
    { cx: 30, cy: 54, radius: 2.3, delay: -2.2 },
    { cx: 19, cy: 50, radius: 1.8, delay: -2.55 },
    { cx: 11, cy: 41, radius: 2.1, delay: -2.9 },
    { cx: 10, cy: 29, radius: 1.6, delay: -3.25 },
    { cx: 15, cy: 19, radius: 2.4, delay: -3.6 },
    { cx: 23, cy: 12, radius: 1.7, delay: -3.95 },
];
</script>

<template>
    <span
        :class="[
            'fiscal-orb',
            `fiscal-orb--${mode}`,
            `fiscal-orb--${size}`,
            { 'is-active': active },
        ]"
        role="img"
        :aria-label="label"
    >
        <svg
            class="fiscal-orb__field"
            viewBox="0 0 64 64"
            fill="none"
            aria-hidden="true"
        >
            <circle class="fiscal-orb__halo" cx="32" cy="32" r="25" />
            <ellipse
                class="fiscal-orb__orbit fiscal-orb__orbit--wide"
                cx="32"
                cy="32"
                rx="23"
                ry="12"
            />
            <ellipse
                class="fiscal-orb__orbit fiscal-orb__orbit--tall"
                cx="32"
                cy="32"
                rx="13"
                ry="22"
            />
            <g class="fiscal-orb__particles">
                <circle
                    v-for="(particle, index) in particles"
                    :key="index"
                    class="fiscal-orb__particle"
                    :cx="particle.cx"
                    :cy="particle.cy"
                    :r="particle.radius"
                    :style="{ '--particle-delay': `${particle.delay}s` }"
                />
            </g>
            <circle class="fiscal-orb__core" cx="32" cy="32" r="6" />
            <circle class="fiscal-orb__core-dot" cx="32" cy="32" r="2" />
        </svg>

        <BrandSymbol
            class="fiscal-orb__seal text-brand-950"
            :animated="false"
        />
    </span>
</template>

<style scoped>
.fiscal-orb {
    position: relative;
    display: inline-grid;
    flex: none;
    place-items: center;
    color: var(--color-accent-400);
}

.fiscal-orb--sm {
    width: 2.5rem;
    height: 2.5rem;
}

.fiscal-orb--lg {
    width: 4.75rem;
    height: 4.75rem;
}

.fiscal-orb__field,
.fiscal-orb__seal {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    transition:
        opacity 220ms ease,
        transform 420ms cubic-bezier(0.22, 1, 0.36, 1);
}

.fiscal-orb__field {
    opacity: 0;
    transform: scale(0.72);
}

.fiscal-orb__seal {
    opacity: 1;
    transform: scale(1);
}

.fiscal-orb.is-active .fiscal-orb__field {
    opacity: 1;
    transform: scale(1);
}

.fiscal-orb.is-active .fiscal-orb__seal {
    opacity: 0;
    transform: rotate(8deg) scale(0.72);
}

.fiscal-orb__halo {
    fill: color-mix(in srgb, currentColor 10%, transparent);
    stroke: color-mix(in srgb, currentColor 36%, transparent);
    stroke-width: 0.8;
}

.fiscal-orb__orbit {
    stroke: color-mix(in srgb, currentColor 45%, transparent);
    stroke-width: 0.8;
    stroke-dasharray: 2 4;
    transform-origin: 32px 32px;
}

.fiscal-orb__particles {
    transform-origin: 32px 32px;
}

.fiscal-orb__particle {
    fill: currentColor;
    transform-box: fill-box;
    transform-origin: center;
}

.fiscal-orb__core {
    fill: color-mix(in srgb, currentColor 18%, transparent);
    stroke: currentColor;
    stroke-width: 1;
}

.fiscal-orb__core-dot {
    fill: currentColor;
}

@media (prefers-reduced-motion: no-preference) {
    .fiscal-orb.is-active .fiscal-orb__halo {
        animation: fiscal-halo-breathe 1.6s ease-in-out infinite;
    }

    .fiscal-orb.is-active .fiscal-orb__particle {
        animation: fiscal-particle-pulse 1.25s ease-in-out infinite alternate;
        animation-delay: var(--particle-delay);
    }

    .fiscal-orb--breathing.is-active .fiscal-orb__particles {
        animation: fiscal-breathe 2.4s ease-in-out infinite;
    }

    .fiscal-orb--shaping.is-active .fiscal-orb__particles {
        animation: fiscal-shape 1.7s cubic-bezier(0.65, 0, 0.35, 1) infinite;
    }

    .fiscal-orb--shaping.is-active .fiscal-orb__orbit--wide {
        animation: fiscal-spin 2.2s linear infinite;
    }

    .fiscal-orb--connecting.is-active .fiscal-orb__orbit--wide {
        animation: fiscal-spin 1.6s linear infinite;
    }

    .fiscal-orb--connecting.is-active .fiscal-orb__orbit--tall {
        animation: fiscal-spin-reverse 2s linear infinite;
    }

    .fiscal-orb--settling.is-active .fiscal-orb__particles {
        animation: fiscal-settle 1.35s cubic-bezier(0.22, 1, 0.36, 1) infinite;
    }
}

@keyframes fiscal-halo-breathe {
    50% {
        opacity: 0.48;
        transform: scale(0.9);
        transform-origin: center;
    }
}

@keyframes fiscal-particle-pulse {
    to {
        opacity: 0.3;
        transform: scale(0.56);
    }
}

@keyframes fiscal-breathe {
    50% {
        transform: scale(0.84);
    }
}

@keyframes fiscal-shape {
    50% {
        transform: rotate(45deg) scale(0.76);
    }
}

@keyframes fiscal-spin {
    to {
        transform: rotate(360deg);
    }
}

@keyframes fiscal-spin-reverse {
    to {
        transform: rotate(-360deg);
    }
}

@keyframes fiscal-settle {
    50% {
        transform: rotate(-14deg) scale(0.8);
    }
}

@media (prefers-reduced-motion: reduce) {
    .fiscal-orb__field,
    .fiscal-orb__seal {
        transition: none;
    }
}
</style>
