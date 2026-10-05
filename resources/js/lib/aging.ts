/**
 * Ageing colours, from current to the oldest debt: ink, gold, orange, red.
 *
 * The order is the information, so the colour runs one way with age rather
 * than giving each bucket its own identity. Shared by the dashboard and the
 * debts page so the same bucket is always the same colour.
 */
export const agingBucketColours: Record<string, string> = {
    current: 'bg-brand-950 dark:bg-zinc-200',
    d1_30: 'bg-accent-400',
    d31_60: 'bg-orange-500',
    d61_90: 'bg-rose-500',
    d90_plus: 'bg-rose-700',
};

/** A bucket's share of a total, as a CSS width. */
export function agingShare(value: number, total: number): string {
    return `${total > 0 ? (value / total) * 100 : 0}%`;
}
