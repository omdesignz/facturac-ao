/**
 * Screens that take the whole phone: the till, and a document being made. The
 * tab bar steps aside so their own total and action stay under the thumb.
 */
const FOCUSED_PREFIXES = ['/ponto-de-venda', '/documents/invoices/create'];

const FOCUSED_SUFFIXES = ['/nova', '/novo'];

export function isFocusedPath(path: string): boolean {
    return (
        FOCUSED_PREFIXES.some(
            (prefix) => path === prefix || path.startsWith(`${prefix}/`),
        ) || FOCUSED_SUFFIXES.some((suffix) => path.endsWith(suffix))
    );
}
