import { onBeforeUnmount, ref, watchEffect } from 'vue';
import type { Component } from 'vue';

/** Something the palette can do to what the page has open. */
export interface PaletteAction {
    key: string;
    label: string;
    hint?: string;
    icon: Component;
    run: () => void;
}

/** What the page on screen offers; the palette lists it as "Nesta página". */
export const paletteActions = ref<PaletteAction[]>([]);

/** Offer actions while the calling page is on screen. */
export function usePaletteActions(actions: () => PaletteAction[]): void {
    const stop = watchEffect(() => {
        paletteActions.value = actions();
    });

    onBeforeUnmount(() => {
        stop();
        paletteActions.value = [];
    });
}
