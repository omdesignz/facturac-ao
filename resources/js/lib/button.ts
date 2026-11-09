/**
 * The app's buttons, one recipe per role. `AppButton` is how a page uses them;
 * a surface that must style another element as a button, such as a headless
 * menu trigger, can take the class string directly.
 *
 * Desktop controls are 36px tall with an 8px corner, crisp at the edge rather
 * than soft; under a coarse pointer every one grows to 44px.
 */

const base =
    'btn inline-flex items-center justify-center gap-2 whitespace-nowrap focus-ring transition disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-50';

const medium = 'h-9 rounded-lg px-3.5 text-[0.8125rem] pointer-coarse:h-11';
const small = 'h-8 rounded-lg px-3 text-[0.8125rem] pointer-coarse:h-11';
/** Row actions inside a list or card, where a 32px button would shout. */
const extraSmall = 'h-[1.625rem] px-2.5 rounded-md text-xs pointer-coarse:h-11';
const large = 'h-10 rounded-lg px-4 text-sm pointer-coarse:h-11';

const ink =
    'bg-[var(--ui-ink)] font-semibold text-[var(--surface-raised)] shadow-[inset_0_1px_0_rgb(255_255_255/0.12)] hover:bg-[color-mix(in_oklch,var(--ui-ink)_90%,var(--surface-raised))] dark:shadow-none';
/** Existing create/charge callers share the selected design's ink emphasis. */
const gold = ink;
const outline =
    'bg-[var(--surface-raised)] font-medium text-zinc-900 shadow-[var(--ui-control-shadow)] hover:bg-[var(--ui-wash)] dark:text-zinc-100 dark:hover:bg-[#18181b]';
const quiet =
    'font-medium text-zinc-600 hover:bg-zinc-900/[0.05] hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white';

export type ButtonVariant = 'gold' | 'ink' | 'outline' | 'quiet';
export type ButtonSize = 'xs' | 'sm' | 'md' | 'lg';

const variants: Record<ButtonVariant, string> = { gold, ink, outline, quiet };
const sizes: Record<ButtonSize, string> = {
    xs: extraSmall,
    sm: small,
    md: medium,
    lg: large,
};

/**
 * Ink carries the primary action. The gold variant remains a compatibility
 * name for create/charge callers; the gold colour is reserved for focus,
 * selection and data. Outline is secondary, quiet is a text action.
 */
export function buttonClass(
    variant: ButtonVariant = 'outline',
    size: ButtonSize = 'md',
): string {
    return `${base} ${sizes[size]} ${variants[variant]}`;
}

export const buttonGold = buttonClass('gold');
export const buttonInk = buttonClass('ink');
export const buttonOutline = buttonClass('outline');
export const buttonOutlineSmall = buttonClass('outline', 'sm');
export const buttonQuiet = buttonClass('quiet');
