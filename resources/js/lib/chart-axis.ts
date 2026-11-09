const compact = new Intl.NumberFormat('pt-AO', { maximumFractionDigits: 1 });

/**
 * An axis tick as a reader here says it: "0", "750", "2,5 mil", "1,2 M".
 * Ticks are already round, so one decimal is all they ever need.
 */
export function axisNumber(value: number): string {
    const size = Math.abs(value);

    if (size >= 1_000_000) {
        return `${compact.format(value / 1_000_000)} M`;
    }

    if (size >= 1_000) {
        return `${compact.format(value / 1_000)} mil`;
    }

    return compact.format(value);
}

/** The same tick for an amount held in cents. */
export function axisAmount(minor: number): string {
    return axisNumber(minor / 100);
}

/**
 * A round ceiling and the ticks under it: steps of 1, 2, 2.5 or 5 times a power
 * of ten, so every gridline lands on a figure a reader recognises and the
 * baseline is always zero.
 */
export function niceTicks(peak: number, intervals: number): number[] {
    if (peak <= 0) {
        return [0];
    }

    const rough = peak / intervals;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step =
        ([1, 2, 2.5, 5, 10].find((factor) => factor * magnitude >= rough) ??
            10) * magnitude;
    const count = Math.max(1, Math.ceil(peak / step - 1e-9));

    return Array.from({ length: count + 1 }, (_, index) => index * step);
}
