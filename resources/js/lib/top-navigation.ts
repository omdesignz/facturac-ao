/**
 * The desktop's top bar speaks in five words, not seven places: Hoje, Vender,
 * Cobrar, Registos, Fiscal. Anything that belongs to none of them (setup,
 * account, help, the assistant, the staff console) waits under a sixth, Mais.
 *
 * The pages themselves still come from lib/navigation, so the phone's menu and
 * this bar can never disagree about where a page lives; this only says which
 * word a page sits under.
 */
export type DestinationKey =
    'today' | 'sell' | 'collect' | 'records' | 'fiscal' | 'more';

export interface Destination<T> {
    key: DestinationKey;
    name: string;
    items: T[];
}

/** The URL prefix each page owns, in the order the bar shows them. */
const PLACES: Record<Exclude<DestinationKey, 'more'>, string[]> = {
    today: ['/dashboard'],
    sell: ['/ponto-de-venda', '/orcamentos', '/guias', '/avencas'],
    collect: ['/dividas', '/customers'],
    records: ['/documentos', '/catalogue', '/tabelas-de-precos', '/inventario'],
    fiscal: [
        '/agt/submissions',
        '/agt/connection',
        '/saft',
        '/analises',
        '/relatorios/exportar',
    ],
};

const NAMES: Record<DestinationKey, string> = {
    today: 'Hoje',
    sell: 'Vender',
    collect: 'Cobrar',
    records: 'Registos',
    fiscal: 'Fiscal',
    more: 'Mais',
};

export function destinationsFor<T extends { match: string }>(
    groups: { items: T[] }[],
): Destination<T>[] {
    const all = groups.flatMap((group) => group.items);
    const placed = new Set<T>();

    const destinations: Destination<T>[] = (
        Object.keys(PLACES) as Exclude<DestinationKey, 'more'>[]
    ).map((key) => {
        const items = PLACES[key].flatMap((match) =>
            all.filter((item) => item.match === match),
        );

        items.forEach((item) => placed.add(item));

        return { key, name: NAMES[key], items };
    });

    const rest = all.filter((item) => !placed.has(item));

    return [
        ...destinations,
        { key: 'more' as const, name: NAMES.more, items: rest },
    ].filter((destination) => destination.items.length > 0);
}

/** The destination that owns the page on screen, if any. */
export function activeDestination<T>(
    destinations: Destination<T>[],
    active: T | null,
): DestinationKey | null {
    if (active === null) {
        return null;
    }

    return (
        destinations.find((destination) => destination.items.includes(active))
            ?.key ?? null
    );
}
