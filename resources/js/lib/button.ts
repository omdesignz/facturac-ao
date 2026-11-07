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

const gold =
    'bg-accent-400 font-semibold text-[#1a1407] shadow-[inset_0_1px_0_rgb(255_255_255/0.4),0_0_0_1px_#db9520,0_1px_2px_rgb(0_0_0/0.15)] hover:bg-[#fbbd4b]';
const ink =
    'bg-brand-950 font-semibold text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.12)] hover:bg-brand-800 dark:bg-zinc-100 dark:text-brand-950 dark:shadow-none dark:hover:bg-white';
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
 * Gold is create, charge or send, and the only gold on a screen. Ink is an
 * irreversible confirmation or a primary action elsewhere, outline everything
 * secondary, quiet a text button under a primary action.
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
