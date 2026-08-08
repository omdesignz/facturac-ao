const storageKey = 'vap-sidebar-collapsed';

export function getStoredSidebarCollapsed(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.localStorage.getItem(storageKey) === 'true';
}

export function storeSidebarCollapsed(collapsed: boolean): void {
    window.localStorage.setItem(storageKey, collapsed ? 'true' : 'false');
}
