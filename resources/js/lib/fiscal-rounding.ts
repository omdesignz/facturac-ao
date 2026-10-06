/** Schema 2.0 truncates credits and rounds debit amounts up to the next cent. */
export function roundFiscalLineAmount(
    numerator: bigint,
    denominator: bigint,
    documentType: string,
): bigint {
    return documentType === 'NC'
        ? (numerator + denominator - 1n) / denominator
        : numerator / denominator;
}
