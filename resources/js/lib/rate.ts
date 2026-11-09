const rateFormatter = new Intl.NumberFormat('pt-AO', {
    maximumFractionDigits: 2,
});

/**
 * A tax or discount rate as a reader here writes it: "14", "6,5", "0".
 * Never "14.00", whose dot reads as a thousands separator.
 */
export function formatRate(value: string | number): string {
    const rate = Number(value);

    return Number.isFinite(rate) ? rateFormatter.format(rate) : String(value);
}
