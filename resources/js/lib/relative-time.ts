const relativeFormatter = new Intl.RelativeTimeFormat('pt-PT', {
    numeric: 'auto',
});

/** "há 5 minutos" reads better than a timestamp in a list you scan. */
export function relativeTime(value: string | null, now = Date.now()): string {
    if (value === null) {
        return '';
    }

    const seconds = Math.round((Date.parse(value) - now) / 1000);
    const steps: Array<[Intl.RelativeTimeFormatUnit, number]> = [
        ['second', 60],
        ['minute', 60],
        ['hour', 24],
        ['day', 7],
        ['week', 4.35],
        ['month', 12],
    ];

    let amount = seconds;

    for (const [unit, size] of steps) {
        if (Math.abs(amount) < size) {
            return relativeFormatter.format(Math.round(amount), unit);
        }

        amount /= size;
    }

    return relativeFormatter.format(Math.round(amount), 'year');
}
