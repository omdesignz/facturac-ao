<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * The facturac.ao wordmark, drawn as outlines rather than set in a font.
 *
 * The letters are Century Gothic Bold converted to paths, so the logo looks the
 * same on every device whether or not the typeface is installed, and the page
 * never ships the font file itself. The gold dot is the one part that is ours:
 * it is where "facturação" loses its tilde and cedilla and becomes the domain.
 *
 * With `animated`, the mark first reads "facturação" and, once it scrolls into
 * view, the tilde and cedilla slide into the gap and become the dot. Anyone who
 * asked for less motion gets the finished mark straight away.
 */
const props = withDefaults(
    defineProps<{
        animated?: boolean;
        title?: string;
    }>(),
    {
        animated: false,
        title: 'facturac.ao',
    },
);

type WordmarkState = 'start' | 'playing' | 'final';

const state = ref<WordmarkState>(props.animated ? 'start' : 'final');
const root = ref<SVGSVGElement | null>(null);

let observer: IntersectionObserver | null = null;

const viewBox = computed(() =>
    props.animated ? '-1.6 2.1 524 98.5' : '-1.6 3 524 80.4',
);

function prefersReducedMotion(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Plays the word-to-domain sequence from the beginning. */
function replay(): void {
    if (!props.animated || prefersReducedMotion()) {
        state.value = 'final';

        return;
    }

    state.value = 'start';

    // Two frames: one to paint the starting word, one to begin the motion.
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            state.value = 'playing';
        });
    });
}

onMounted(() => {
    if (!props.animated) {
        return;
    }

    if (prefersReducedMotion() || !('IntersectionObserver' in window)) {
        state.value = 'final';

        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                observer?.disconnect();
                observer = null;
                replay();
            }
        },
        { threshold: 0.6 },
    );

    if (root.value) {
        observer.observe(root.value);
    }
});

onUnmounted(() => {
    observer?.disconnect();
});

defineExpose({ replay });
</script>

<template>
    <svg
        ref="root"
        :viewBox="viewBox"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        :aria-label="title"
        :class="['brand-wordmark-svg', `is-${state}`]"
    >
        <path
            class="fill-current"
            d="M0.4 26.9H5.1Q5.2 16.4 5.5 14.5Q6 10.1 9.2 7.6Q12.3 5 18.1 5Q22.2 5 27.4 6.9V17.1Q24.6 16.2 22.7 16.2Q20.4 16.2 19.3 17.2Q18.5 17.9 18.5 20.2L18.5 26.9H26.9V38.2H18.5V80H5.1V38.2H0.4ZM69 26.9H82.3V80H69V74.4Q65.1 78.1 61.1 79.7Q57.2 81.4 52.6 81.4Q42.3 81.4 34.8 73.4Q27.3 65.4 27.3 53.5Q27.3 41.2 34.6 33.4Q41.8 25.5 52.2 25.5Q57 25.5 61.2 27.3Q65.4 29.1 69 32.7ZM55 37.8Q48.8 37.8 44.7 42.2Q40.6 46.6 40.6 53.4Q40.6 60.3 44.7 64.7Q48.9 69.2 55 69.2Q61.3 69.2 65.5 64.8Q69.6 60.4 69.6 53.3Q69.6 46.4 65.5 42.1Q61.3 37.8 55 37.8ZM142.7 37.6 131.7 43.7Q128.5 40.4 125.5 39.2Q122.4 37.9 118.3 37.9Q110.8 37.9 106.2 42.4Q101.6 46.8 101.6 53.8Q101.6 60.6 106.1 64.9Q110.5 69.2 117.7 69.2Q126.7 69.2 131.7 63.1L142.1 70.3Q133.6 81.4 118 81.4Q104 81.4 96.1 73.1Q88.1 64.8 88.1 53.6Q88.1 45.9 92 39.4Q95.9 32.9 102.8 29.2Q109.7 25.5 118.2 25.5Q126.1 25.5 132.4 28.7Q138.7 31.8 142.7 37.6ZM151.4 7.3H164.6V26.9H172.5V38.3H164.6V80H151.4V38.3H144.5V26.9H151.4ZM174.7 26.9H188.2V52.5Q188.2 59.9 189.2 62.8Q190.2 65.7 192.5 67.4Q194.7 69 198.1 69Q201.4 69 203.7 67.4Q206 65.8 207.1 62.7Q208 60.4 208 52.9V26.9H221.4V49.4Q221.4 63.3 219.2 68.4Q216.5 74.7 211.2 78Q206 81.4 198 81.4Q189.2 81.4 183.8 77.5Q178.4 73.6 176.2 66.6Q174.7 61.7 174.7 49ZM226.6 26.9H238V33.6Q239.9 29.6 243 27.6Q246 25.5 249.7 25.5Q252.3 25.5 255.1 26.9L251 38.3Q248.6 37.2 247.1 37.2Q244 37.2 241.9 41Q239.8 44.8 239.8 55.9L239.8 58.5V80H226.6ZM296 26.9H309.3V80H296V74.4Q292.1 78.1 288.1 79.7Q284.2 81.4 279.6 81.4Q269.3 81.4 261.8 73.4Q254.3 65.4 254.3 53.5Q254.3 41.2 261.6 33.4Q268.8 25.5 279.2 25.5Q284 25.5 288.2 27.3Q292.4 29.1 296 32.7ZM282 37.8Q275.8 37.8 271.7 42.2Q267.6 46.6 267.6 53.4Q267.6 60.3 271.7 64.7Q275.9 69.2 282 69.2Q288.3 69.2 292.5 64.8Q296.6 60.4 296.6 53.3Q296.6 46.4 292.5 42.1Q288.3 37.8 282 37.8ZM369.7 37.6 358.7 43.7Q355.5 40.4 352.5 39.2Q349.4 37.9 345.3 37.9Q337.9 37.9 333.2 42.4Q328.6 46.8 328.6 53.8Q328.6 60.6 333.1 64.9Q337.5 69.2 344.7 69.2Q353.7 69.2 358.7 63.1L369.2 70.3Q360.6 81.4 345 81.4Q331 81.4 323.1 73.1Q315.1 64.8 315.1 53.6Q315.1 45.9 319 39.4Q322.9 32.9 329.8 29.2Q336.7 25.5 345.2 25.5Q353.1 25.5 359.4 28.7Q365.7 31.8 369.7 37.6Z"
        />
        <path
            class="wordmark-tail fill-current"
            d="M445.5 26.9H458.8V80H445.5V74.4Q441.6 78.1 437.7 79.7Q433.7 81.4 429.1 81.4Q418.8 81.4 411.3 73.4Q403.8 65.4 403.8 53.5Q403.8 41.2 411.1 33.4Q418.4 25.5 428.8 25.5Q433.5 25.5 437.7 27.3Q441.9 29.1 445.5 32.7ZM431.5 37.8Q425.3 37.8 421.2 42.2Q417.1 46.6 417.1 53.4Q417.1 60.3 421.3 64.7Q425.4 69.2 431.5 69.2Q437.8 69.2 442 64.8Q446.1 60.4 446.1 53.3Q446.1 46.4 442 42.1Q437.8 37.8 431.5 37.8ZM492.2 25.5Q499.7 25.5 506.4 29.3Q513 33 516.7 39.5Q520.4 45.9 520.4 53.4Q520.4 60.9 516.7 67.5Q512.9 74 506.5 77.7Q500 81.4 492.3 81.4Q480.8 81.4 472.8 73.2Q464.7 65.1 464.7 53.5Q464.7 41 473.8 32.7Q481.8 25.5 492.2 25.5ZM492.4 38.1Q486.2 38.1 482.1 42.4Q478 46.7 478 53.4Q478 60.4 482 64.7Q486.1 69 492.4 69Q498.6 69 502.8 64.6Q506.9 60.3 506.9 53.4Q506.9 46.6 502.8 42.3Q498.8 38.1 492.4 38.1Z"
        />
        <template v-if="animated">
            <path
                class="wordmark-accent wordmark-tilde"
                style="--move-x: -18.01px; --move-y: 59.38px"
                d="M388 17 382.7 8.9Q388.9 4.1 395.3 4.1Q397.7 4.1 403.5 5.7Q407.5 6.8 409.8 6.8Q413.9 6.8 418.3 4.3L423.4 11.9Q417.2 17.2 411.5 17.2Q408.4 17.2 404.1 15.8Q400.6 14.8 399.6 14.6Q398.4 14.4 396.6 14.4Q392.3 14.4 388 17Z"
            />
            <path
                class="wordmark-accent wordmark-cedilla"
                style="--move-x: 43.18px; --move-y: -18.91px"
                d="M338.8 79.2H347.1L346.5 81.5Q349.8 82.2 351.6 84.3Q353.5 86.4 353.5 89Q353.5 92.9 350.2 95.8Q346.9 98.6 341 98.6Q336.2 98.6 330.1 96.4L331.9 90.1Q335.4 91.2 338.8 91.2Q341.3 91.2 342.5 90.2Q343.7 89.3 343.7 87.7Q343.7 86.4 342.8 85.7Q341.9 85 339.9 85L337.1 85.1Z"
            />
        </template>
        <circle class="wordmark-dot" cx="385.01" cy="70" r="10" />
    </svg>
</template>

<style scoped>
/*
 * The dot takes the brand gold unless the surface asks otherwise: on a gold
 * panel it has to turn ink, so a parent can set --wordmark-dot.
 */
.wordmark-dot,
.wordmark-accent {
    fill: var(--wordmark-dot, var(--color-accent-400));
    transform-box: fill-box;
    transform-origin: center;
}

.is-start .wordmark-tail {
    transform: translateX(-29.5px);
}

.is-start .wordmark-dot {
    transform: scale(0);
}

.is-final .wordmark-accent {
    opacity: 0;
}

@media (prefers-reduced-motion: no-preference) {
    .is-playing .wordmark-tilde {
        animation: wordmark-collapse 900ms cubic-bezier(0.65, 0, 0.35, 1) 700ms
            both;
    }

    .is-playing .wordmark-cedilla {
        animation: wordmark-collapse 800ms cubic-bezier(0.65, 0, 0.35, 1) 1050ms
            both;
    }

    .is-playing .wordmark-tail {
        animation: wordmark-open 700ms cubic-bezier(0.2, 0.8, 0.2, 1) 1300ms
            both;
    }

    /* A slight overshoot, as if the dot were pressed onto the page. */
    .is-playing .wordmark-dot {
        animation: wordmark-press 620ms cubic-bezier(0.34, 1.4, 0.64, 1) 1650ms
            both;
    }
}

@media (prefers-reduced-motion: reduce) {
    .wordmark-accent {
        opacity: 0;
    }

    .wordmark-tail,
    .wordmark-dot {
        transform: none !important;
    }
}

@keyframes wordmark-collapse {
    from {
        transform: translate(0, 0) scale(1);
        opacity: 1;
    }

    to {
        transform: translate(var(--move-x), var(--move-y)) scale(0.25);
        opacity: 0;
    }
}

@keyframes wordmark-open {
    from {
        transform: translateX(-29.5px);
    }

    to {
        transform: translateX(0);
    }
}

@keyframes wordmark-press {
    0% {
        transform: scale(0);
    }

    60% {
        transform: scale(1.18);
    }

    100% {
        transform: scale(1);
    }
}
</style>
