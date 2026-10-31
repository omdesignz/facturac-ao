/**
 * Typing a sale instead of building it: "2 oleo 1l, 1 arroz, 20 cesto a 950".
 *
 * Each segment is a quantity, a name, and optionally a price. The name is
 * matched against the catalogue the company already has, ignoring accents and
 * case; anything that cannot be matched with confidence is left for a person,
 * never guessed. Nothing here touches a document: it only reads text.
 */
export interface TypedCatalogueItem {
    public_id: string;
    code: string;
    name: string;
    unit_price: string;
}

export interface TypedLine {
    /** What was typed for this line, trimmed, for showing back. */
    text: string;
    /** Decimal string with a dot, as the form stores it. */
    quantity: string;
    item: TypedCatalogueItem | null;
    /** A price typed after "a" or "@", with a dot, or null to use the catalogue's. */
    unitPrice: string | null;
}

/** Lower case, accents and punctuation gone, so "Óleo, 1L" meets "oleo 1l". */
export function normalise(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9.,\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/** A comma inside a number is a decimal point, not a separator. */
const SEPARATORS = /\s*[;\n]+\s*|,(?!\d)\s*/;

const QUANTITY_FIRST =
    /^(\d+(?:[.,]\d+)?)\s*(?:[x×]\s*|(?:un|unid|unidades?)\.?\s+|\s+)(.+)$/i;

const TRAILING_PRICE = /^(.*?)\s+(?:a|@)\s*(\d+(?:[.,]\d+)?)\s*(?:kz)?\s*$/i;

function decimal(value: string): string {
    return value.replace(',', '.');
}

function tokensOf(value: string): string[] {
    return normalise(value)
        .split(' ')
        .filter((token) => token !== '');
}

/**
 * How well an item answers a query: every typed word must start a word of the
 * name or of the code, and the fewer words the name has beyond those, the
 * better. A perfect name is best of all. Null when a word is not found.
 */
function score(item: TypedCatalogueItem, query: string): number | null {
    const wanted = tokensOf(query);

    if (wanted.length === 0) {
        return null;
    }

    const nameTokens = tokensOf(item.name);
    const codeTokens = tokensOf(item.code);
    const pool = [...nameTokens, ...codeTokens];

    const found = wanted.every((word) =>
        pool.some((token) => token.startsWith(word)),
    );

    if (!found) {
        return null;
    }

    if (normalise(item.name) === normalise(query)) {
        return 0;
    }

    return 1 + Math.max(0, nameTokens.length - wanted.length);
}

export function matchItem(
    query: string,
    items: TypedCatalogueItem[],
): TypedCatalogueItem | null {
    const ranked = items
        .map((item) => ({ item, points: score(item, query) }))
        .filter(
            (
                candidate,
            ): candidate is { item: TypedCatalogueItem; points: number } =>
                candidate.points !== null,
        )
        .sort(
            (a, b) =>
                a.points - b.points || a.item.name.length - b.item.name.length,
        );

    const [best, second] = ranked;

    if (best === undefined) {
        return null;
    }

    // Two items that answer equally well is a question, not an answer.
    if (second !== undefined && second.points === best.points) {
        return null;
    }

    return best.item;
}

export function parseTypedLines(
    text: string,
    items: TypedCatalogueItem[],
): TypedLine[] {
    return text
        .split(SEPARATORS)
        .map((segment) => segment.trim())
        .filter((segment) => segment !== '')
        .map((segment): TypedLine => {
            let rest = segment;
            let quantity = '1';
            let unitPrice: string | null = null;

            const priced = TRAILING_PRICE.exec(rest);

            if (priced !== null) {
                rest = priced[1] ?? rest;
                unitPrice = decimal(priced[2] ?? '');
            }

            const counted = QUANTITY_FIRST.exec(rest);

            if (counted !== null) {
                quantity = decimal(counted[1] ?? '1');
                rest = counted[2] ?? rest;
            }

            return {
                text: segment,
                quantity,
                item: matchItem(rest, items),
                unitPrice,
            };
        });
}
