import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { CSSProperties, Ref } from 'vue';

export interface FloatingPanelOptions {
    /** The control the panel hangs from. */
    anchor: Ref<HTMLElement | null>;
    /** The panel itself, once it is rendered (it measures it to choose a side). */
    panel: Ref<HTMLElement | null>;
    open: Ref<boolean>;
    /** The narrowest the panel may be, in px. It is never narrower than the anchor. */
    minWidth?: number;
    /** How tall the panel is expected to be before it has been measured, in px. */
    estimatedHeight?: number;
    /** Space between the anchor and the panel, in px. */
    gap?: number;
    /**
     * `anchor` makes the panel as wide as the anchor (a list of choices);
     * `content` leaves its own width alone (a calendar), and only keeps it
     * inside the viewport.
     */
    width?: 'anchor' | 'content';
}

const VIEWPORT_MARGIN = 8;

/**
 * Positions a panel that is teleported to <body> next to its anchor.
 *
 * Rendering a list in place meant any ancestor with `overflow-hidden` (most of
 * the surfaces) clipped it, and any ancestor stacking context could bury it, so
 * the select and the date picker teleport theirs. This keeps it where it
 * belongs: below the anchor, or above when there is no room below; never wider
 * than the viewport (a phone is 320px); never taller than the room it has.
 *
 * It listens to scroll (including inner scroll containers) and resize only
 * while the panel is open.
 */
export function useFloatingPanel(options: FloatingPanelOptions) {
    const style = ref<CSSProperties>({
        position: 'fixed',
        insetBlockStart: '0px',
        insetInlineStart: '0px',
    });
    const placement = ref<'down' | 'up'>('down');
    let frame = 0;

    function place(): void {
        const anchor = options.anchor.value;

        if (!anchor || typeof window === 'undefined') {
            return;
        }

        const rect = anchor.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = window.innerHeight;
        const gap = options.gap ?? 6;
        const ownWidth = options.width === 'content';
        const width = Math.min(
            ownWidth
                ? (options.panel.value?.offsetWidth ?? options.minWidth ?? 0)
                : Math.max(rect.width, options.minWidth ?? 0),
            viewportWidth - VIEWPORT_MARGIN * 2,
        );
        const left = Math.min(
            Math.max(rect.left, VIEWPORT_MARGIN),
            viewportWidth - VIEWPORT_MARGIN - width,
        );
        const spaceBelow = viewportHeight - rect.bottom - gap - VIEWPORT_MARGIN;
        const spaceAbove = rect.top - gap - VIEWPORT_MARGIN;
        const measured = options.panel.value?.scrollHeight ?? 0;
        const wanted = measured > 0 ? measured : (options.estimatedHeight ?? 0);

        placement.value =
            spaceBelow >= wanted || spaceBelow >= spaceAbove ? 'down' : 'up';

        const room = Math.max(
            placement.value === 'down' ? spaceBelow : spaceAbove,
            128,
        );

        style.value =
            placement.value === 'down'
                ? {
                      position: 'fixed',
                      insetBlockStart: `${rect.bottom + gap}px`,
                      insetInlineStart: `${left}px`,
                      ...(ownWidth ? {} : { inlineSize: `${width}px` }),
                      maxBlockSize: `${room}px`,
                  }
                : {
                      position: 'fixed',
                      insetBlockStart: `${rect.top - gap}px`,
                      insetInlineStart: `${left}px`,
                      ...(ownWidth ? {} : { inlineSize: `${width}px` }),
                      maxBlockSize: `${room}px`,
                      translate: '0 -100%',
                  };
    }

    function schedule(event?: Event): void {
        // A scroll inside the panel (its own list) is not the page moving.
        if (
            event?.target instanceof Node &&
            options.panel.value?.contains(event.target)
        ) {
            return;
        }

        if (frame !== 0) {
            return;
        }

        frame = requestAnimationFrame(() => {
            frame = 0;
            place();
        });
    }

    function listen(active: boolean): void {
        const method = active ? 'addEventListener' : 'removeEventListener';

        // `true` captures scrolls on inner containers, not just the window.
        window[method]('scroll', schedule, { capture: true, passive: true });
        window[method]('resize', schedule, { passive: true });
    }

    watch(options.open, (open) => {
        if (open) {
            listen(true);
            // Once rendered, so the panel can be measured before it paints.
            void nextTick(place);

            return;
        }

        listen(false);
    });

    onBeforeUnmount(() => {
        if (typeof window === 'undefined') {
            return;
        }

        listen(false);
        cancelAnimationFrame(frame);
    });

    return { style, placement, place };
}
