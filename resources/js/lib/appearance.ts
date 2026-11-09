import { onBeforeUnmount, onMounted, ref } from 'vue';

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

    const root = document.documentElement;

    // Switching the theme must be one instant repaint. Without this, elements
    // with a colour transition fade while the ones without snap, and the page
    // ghosts through a half-dark state. The rule lives in app.css.
    if (root.classList.contains('dark') !== isDark) {
        root.classList.add('theme-switching');
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                root.classList.remove('theme-switching');
            });
        });
    }

    root.classList.toggle('dark', isDark);
    root.dataset.appearance = appearance;
    window.dispatchEvent(new Event('appearance-changed'));
}

/** Keep every visible theme control in step, including the account menu. */
export function useAppearance() {
    const appearance = ref<Appearance>('system');
    const sync = (): void => {
        appearance.value = getStoredAppearance();
    };

    onMounted(() => {
        sync();
        window.addEventListener('appearance-changed', sync);
        window.addEventListener('storage', sync);
    });
    onBeforeUnmount(() => {
        window.removeEventListener('appearance-changed', sync);
        window.removeEventListener('storage', sync);
    });

    return { appearance, changeAppearance: storeAppearance };
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
