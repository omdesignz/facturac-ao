import { nextTick } from 'vue';

/**
 * After a failed submit, move the keyboard (and the viewport) to the first
 * control the server marked invalid. Call it from `onError`; the controls have
 * to render `aria-invalid="true"` once their error arrives, which is why the
 * focus waits a tick.
 *
 * Returns whether there was anything to focus, so a caller can fall back to
 * something else (a summary, the form itself) when the error is not tied to a
 * single field.
 */
export async function focusFirstInvalid(
    root?: ParentNode | null,
): Promise<boolean> {
    await nextTick();

    const scope = root ?? (typeof document === 'undefined' ? null : document);
    const control = scope?.querySelector<HTMLElement>('[aria-invalid="true"]');

    if (!control) {
        return false;
    }

    control.focus({ preventScroll: true });
    control.scrollIntoView({ block: 'center', behavior: 'auto' });

    return true;
}
