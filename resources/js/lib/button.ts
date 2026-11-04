/**
 * The app's pill buttons, one recipe per role. `AppButton` is how a page uses
 * them; a surface that must style another element as a button, such as a
 * headless menu trigger, can take the class string directly.
 *
 * Every control is 40px tall and 44px under a coarse pointer.
 */

const base =
    'inline-flex items-center justify-center gap-2 rounded-full text-sm font-semibold whitespace-nowrap focus-ring transition active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-50';

const medium = 'h-10 px-[1.125rem] pointer-coarse:h-11';
const small = 'h-9 px-3.5 pointer-coarse:h-11 text-[0.8125rem]';
const large = 'h-11 px-5';

const gold =
    'bg-accent-400 text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] hover:bg-accent-300';
const ink =
    'bg-brand-950 text-white hover:bg-brand-800 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white';
const outline =
    'bg-white text-zinc-800 ring-1 ring-zinc-900/10 ring-inset hover:bg-zinc-50 dark:bg-transparent dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5';
const quiet =
    'font-medium text-zinc-600 hover:bg-zinc-900/[0.05] hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white';

export type ButtonVariant = 'gold' | 'ink' | 'outline' | 'quiet';
export type ButtonSize = 'sm' | 'md' | 'lg';

const variants: Record<ButtonVariant, string> = { gold, ink, outline, quiet };
const sizes: Record<ButtonSize, string> = {
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
