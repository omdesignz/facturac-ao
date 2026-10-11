/**
 * The class recipes the point of sale shares, copied from the pills on
 * `pages/Documents/Index.vue` and `pages/Invoices/Create.vue` so the till
 * reads as the same product. Kept as strings because a screen with forty
 * buttons would otherwise carry forty copies; Tailwind scans this file too.
 *
 * Every control is 40px tall and 44px under a coarse pointer.
 */

const base =
    'inline-flex items-center justify-center gap-2 rounded-full text-sm font-semibold whitespace-nowrap focus-ring transition disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-50';

/** Create, charge, send. The only gold on a screen. */
export const buttonGold = `${base} h-10 px-[1.125rem] pointer-coarse:h-11 bg-accent-400 text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] hover:bg-accent-300`;

/** An irreversible confirmation. */
export const buttonInk = `${base} h-10 px-[1.125rem] pointer-coarse:h-11 bg-brand-950 text-white hover:bg-brand-800 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white`;

/** Everything secondary. */
export const buttonOutline = `${base} h-10 px-[1.125rem] pointer-coarse:h-11 bg-white text-zinc-800 ring-1 ring-zinc-900/10 ring-inset hover:bg-zinc-50 dark:bg-transparent dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5`;

/** The same as the outline pill, one step smaller, for a strip of actions. */
export const buttonOutlineSmall = `${base} h-9 px-3.5 pointer-coarse:h-11 text-[0.8125rem] bg-white text-zinc-800 ring-1 ring-zinc-900/10 ring-inset hover:bg-zinc-50 dark:bg-transparent dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5`;

/** A quiet text button under a primary action. */
export const buttonQuiet = `${base} h-10 px-3 pointer-coarse:h-11 font-medium text-zinc-600 hover:bg-zinc-900/[0.05] hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white`;

/** A text field in the till's own scale; large fields add their own size. */
export const fieldClass = 'form-input';

/** The wells every surface is made of. */
export const wellSection =
    'rounded-3xl bg-zinc-900/[0.04] dark:bg-white/[0.04]';
export const wellItem = 'rounded-2xl bg-zinc-900/[0.04] dark:bg-white/[0.04]';

/** The key-cap recipe of the header's shortcut hint. */
export const keyCap =
    'grid h-5 min-w-5 place-items-center rounded-md bg-white px-1.5 font-sans text-[0.6875rem] leading-none font-medium text-zinc-500 shadow-[0_0_0_1px_rgb(23_23_22/0.08)] dark:bg-white/10 dark:text-zinc-300 dark:shadow-none';

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
