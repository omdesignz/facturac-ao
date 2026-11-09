/**
 * A count and its noun, agreed in number: "1 documento", "3 documentos".
 *
 * Never "documento(s)". The count uses the locale's grouping, so 1200 reads
 * "1 200 documentos" like every other figure in the app.
 */
export function plural(count: number, one: string, many: string): string {
    const figure = new Intl.NumberFormat('pt-AO').format(count);

    return `${figure} ${count === 1 ? one : many}`;
}
