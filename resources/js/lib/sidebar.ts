import { ref } from 'vue';

/**
 * Whether the desktop sidebar is the full list or the compact rail of icons.
 * One value for the whole app, remembered on this computer, so the layout and
 * the sidebar always agree and a page change never makes it jump.
 */
const STORAGE_KEY = 'vap-sidebar';

function storedCompact(): boolean {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === 'compact';
    } catch {
        return false;
    }
}

export const sidebarCompact = ref(
    typeof window === 'undefined' ? false : storedCompact(),
);

export function setSidebarCompact(compact: boolean): void {
    sidebarCompact.value = compact;

    try {
        window.localStorage.setItem(STORAGE_KEY, compact ? 'compact' : 'full');
    } catch {
        // Private windows may refuse storage; the choice simply is not kept.
    }
}

export function toggleSidebar(): void {
    setSidebarCompact(!sidebarCompact.value);
}

/** ⌘\ on a Mac, Ctrl+\ elsewhere, as in most desktop apps with a sidebar. */
export function isSidebarShortcut(event: KeyboardEvent): boolean {
    return (
        event.key === '\\' &&
        (event.metaKey || event.ctrlKey) &&
        !event.altKey &&
        !event.shiftKey
    );
}
