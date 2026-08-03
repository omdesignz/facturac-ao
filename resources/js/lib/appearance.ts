export type Appearance = 'light' | 'dark' | 'system';

const storageKey = 'vap-appearance';

export function getStoredAppearance(): Appearance {
    const storedAppearance = window.localStorage.getItem(storageKey);

    return storedAppearance === 'light' || storedAppearance === 'dark'
        ? storedAppearance
        : 'system';
}

export function applyAppearance(appearance: Appearance): void {
    const prefersDark = window.matchMedia(
        '(prefers-color-scheme: dark)',
    ).matches;
    const isDark =
        appearance === 'dark' || (appearance === 'system' && prefersDark);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.dataset.appearance = appearance;
}

export function storeAppearance(appearance: Appearance): void {
    window.localStorage.setItem(storageKey, appearance);
    applyAppearance(appearance);
}

export function watchSystemAppearance(): void {
    window
        .matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', () => {
            const appearance = getStoredAppearance();

            if (appearance === 'system') {
                applyAppearance(appearance);
            }
        });
}
