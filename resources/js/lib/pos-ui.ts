/**
 * The class recipes the point of sale shares. Buttons live in `lib/button.ts`,
 * re-exported here for the screens that style another element as one.
 */

export {
    buttonGold,
    buttonInk,
    buttonOutline,
    buttonOutlineSmall,
    buttonQuiet,
} from '@/lib/button';

/** A text field in the till's own scale; large fields add their own size. */
export const fieldClass = 'form-input';

/** The wells every surface is made of. */
export const wellSection =
    'rounded-3xl bg-zinc-900/[0.04] dark:bg-white/[0.04]';
export const wellItem = 'rounded-2xl bg-zinc-900/[0.04] dark:bg-white/[0.04]';

/** The key-cap recipe of the header's shortcut hint. */
export const keyCap =
    'grid h-5 min-w-5 place-items-center rounded-md bg-white px-1.5 font-sans text-xs leading-none font-medium text-zinc-500 shadow-[0_0_0_1px_rgb(23_23_22/0.08)] dark:bg-white/10 dark:text-zinc-300 dark:shadow-none';

/** The small muted suffix after a figure. */
export const currencySuffix =
    'ms-1 text-xs font-normal text-zinc-500 dark:text-zinc-400';

const clockFormatter = new Intl.DateTimeFormat('pt-AO', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});

const dayFormatter = new Intl.DateTimeFormat('pt-AO', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

/** «09:12», on the reader's own clock. */
export function formatClock(iso: string | null): string {
    return iso === null ? '—' : clockFormatter.format(new Date(iso));
}

/** «10 de out. de 2026 · 09:12». */
export function formatDayAndClock(iso: string | null): string {
    return iso === null
        ? '—'
        : `${dayFormatter.format(new Date(iso))} · ${clockFormatter.format(new Date(iso))}`;
}

/** The currency as it is written next to a figure: AOA is «Kz». */
export function currencyLabel(code: string): string {
    return code === 'AOA' ? 'Kz' : code;
}
