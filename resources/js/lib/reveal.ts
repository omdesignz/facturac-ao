import type { Directive, DirectiveBinding } from 'vue';

/**
 * Reveals an element the first time it scrolls into view.
 *
 * An observer rather than a scroll handler: the browser does the watching, so
 * nothing runs on the main thread between one section and the next. Elements
 * reveal once and are then forgotten — content that re-animates every time it
 * passes the fold stops being an entrance and becomes a distraction.
 *
 * Usage is `v-reveal` on its own, or `v-reveal="n"` to place the element at
 * position n in a stagger.
 */

/** How far up the viewport an element travels as it arrives, in pixels. */
const TRAVEL = 12;

/** The gap between staggered siblings. Long enough to read as a sequence. */
const STEP_MS = 70;

/**
 * Held open for the life of the page.
 *
 * One observer for every revealed element rather than one each: the callback is
 * the same and the browser batches entries, so this stays cheap on a page with
 * a hundred of them.
 */
let observer: IntersectionObserver | null = null;

/**
 * Whether the observer has ever called back.
 *
 * An observer delivers a first callback for everything it is given, on screen
 * or not, so this turns true within a frame or two wherever observers work at
 * all. It is what lets the failsafe tell "the observer is broken" from "the
 * observer is simply waiting for the reader to scroll".
 */
let alive = false;

function watcher(): IntersectionObserver {
    observer ??= new IntersectionObserver(
        (entries) => {
            alive = true;

            for (const entry of entries) {
                if (!entry.isIntersecting) {
                    continue;
                }

                entry.target.classList.add('is-revealed');
                observer?.unobserve(entry.target);
            }
        },
        {
            /*
             * Fires a little before the element reaches the fold, so the motion
             * is finishing as it arrives rather than starting. Waiting for it to
             * be fully on screen makes every section feel a beat late.
             */
            rootMargin: '0px 0px -12% 0px',
            threshold: 0.01,
        },
    );

    return observer;
}

function prefersStillness(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/** Whether any part of the element is on screen right now. */
function alreadyInView(element: HTMLElement): boolean {
    const box = element.getBoundingClientRect();

    return box.top < window.innerHeight && box.bottom > 0;
}

let failsafe: ReturnType<typeof setTimeout> | undefined;

/**
 * Shows everything still waiting, if the observer never delivered.
 *
 * The entrance is a nicety; the content is the point. An observer that never
 * calls back — an old browser, a bot rendering the page, something unexpected —
 * must not be able to leave the page blank below the fold. After this fires the
 * animation is simply forfeited, which is the right thing to lose.
 *
 * It does nothing once the observer has called back: from then on the observer
 * reveals each element as it arrives, and revealing them early here would spend
 * every entrance on a reader who has not scrolled yet.
 */
function armFailsafe(): void {
    if (failsafe !== undefined) {
        return;
    }

    failsafe = setTimeout(() => {
        /*
         * Cleared so that a page visited again later, with the observer still
         * silent, arms a fresh one rather than being left without.
         */
        failsafe = undefined;

        if (alive) {
            return;
        }

        for (const waiting of document.querySelectorAll(
            '.reveal:not(.is-revealed)',
        )) {
            waiting.classList.add('is-revealed');
        }
    }, 1200);
}

export const vReveal: Directive<HTMLElement, number | undefined> = {
    mounted(
        element: HTMLElement,
        binding: DirectiveBinding<number | undefined>,
    ) {
        /*
         * Nothing to reveal when stillness was asked for: the element keeps its
         * ordinary styles and never gets the class that hides it, so it is
         * simply there rather than fading in instantly.
         */
        if (prefersStillness() || typeof IntersectionObserver === 'undefined') {
            return;
        }

        element.style.setProperty('--reveal-travel', `${TRAVEL}px`);
        element.style.setProperty(
            '--reveal-delay',
            `${(binding.value ?? 0) * STEP_MS}ms`,
        );
        element.classList.add('reveal');
        armFailsafe();

        /*
         * Anything already on screen is revealed on the next frame rather than
         * left to the observer. It covers the page opened deep at an anchor,
         * where the content under the reader is the content that must not be
         * waiting for a callback.
         */
        if (alreadyInView(element)) {
            requestAnimationFrame(() => element.classList.add('is-revealed'));

            return;
        }

        watcher().observe(element);
    },

    unmounted(element: HTMLElement) {
        observer?.unobserve(element);
    },
};
