import { ref } from 'vue';

/** The key-cap recipe, wherever a key is named on screen. */
export const keyCap =
    'grid h-5 min-w-5 place-items-center rounded-md bg-white px-1.5 font-sans text-xs leading-none font-medium text-zinc-500 shadow-[0_0_0_1px_rgb(23_23_22/0.08)] dark:bg-white/10 dark:text-zinc-300 dark:shadow-none';

/** The shortcuts sheet, opened by "?" and from the palette. */
export const shortcutsSheetOpen = ref(false);

export function detectApplePlatform(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    const platform =
        (navigator as { userAgentData?: { platform?: string } }).userAgentData
            ?.platform ??
        navigator.platform ??
        '';

    return /mac|iphone|ipad|ipod/i.test(platform);
}

export function isTypingInFormField(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    if (
        target instanceof HTMLInputElement &&
        ['checkbox', 'radio'].includes(target.type)
    ) {
        return false;
    }

    return (
        target.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
    );
}

/** A dialog already has the user's attention, and the page behind it is inert. */
export function isDialogOpen(): boolean {
    return (
        document.querySelector('[role="dialog"], [role="alertdialog"]') !== null
    );
}

/** An open menu or list owns the arrow keys and every letter (it type-aheads). */
export function isPopupOpen(): boolean {
    return document.querySelector('[role="menu"], [role="listbox"]') !== null;
}

/** The till itself, not the shift and register lists filed under the same path. */
export function isTillUrl(url: string): boolean {
    return url.split(/[?#]/)[0].replace(/\/$/, '') === '/ponto-de-venda';
}

/** The rows a list has marked for j/k, in the order they are on screen. */
export function navigableRows(): HTMLElement[] {
    return [...document.querySelectorAll<HTMLElement>('[data-nav-row]')];
}

/**
 * The row after the focused one in the given direction. With nothing focused
 * yet, the walk starts from the row the page already has open, if any.
 */
export function nextRow(
    rows: HTMLElement[],
    current: Element | null,
    direction: 1 | -1,
): HTMLElement | null {
    if (rows.length === 0) {
        return null;
    }

    const focused = rows.findIndex((row) => row === current);
    const from =
        focused !== -1
            ? focused
            : rows.findIndex((row) => row.ariaCurrent === 'true');

    if (from === -1) {
        return direction === 1 ? rows[0] : rows[rows.length - 1];
    }

    return rows[from + direction] ?? null;
}
