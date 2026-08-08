import { readonly, ref } from 'vue';

export interface ConfirmRequest {
    title: string;
    message: string;
    /** Names the action rather than saying "OK" — the button says what it does. */
    confirmLabel: string;
    cancelLabel?: string;
    /** `danger` for anything that destroys or leaves the building. */
    tone?: 'danger' | 'neutral';
}

interface PendingConfirm extends ConfirmRequest {
    resolve: (confirmed: boolean) => void;
}

const pending = ref<PendingConfirm | null>(null);

/**
 * Asks the question in the app's own dialog rather than the browser's.
 *
 * Deliberately promise-shaped like `window.confirm`, so a call site reads the
 * same way it did — the difference is that this one can be styled, translated,
 * and can name the action on its button instead of offering "OK".
 *
 * One host renders it, mounted once in the layout, so no page has to remember
 * to put a dialog in its own template.
 */
export function confirmAction(request: ConfirmRequest): Promise<boolean> {
    // A second question while one is open would silently drop the first
    // caller's promise; answering it "no" leaves that caller unblocked.
    pending.value?.resolve(false);

    return new Promise<boolean>((resolve) => {
        pending.value = { ...request, resolve };
    });
}

/** Used by the host component; not part of the calling API. */
export function usePendingConfirm() {
    function answer(confirmed: boolean): void {
        const request = pending.value;
        pending.value = null;
        request?.resolve(confirmed);
    }

    return { pending: readonly(pending), answer };
}
