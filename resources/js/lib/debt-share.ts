/**
 * How the money owed is spread across customers: the few that hold most of it,
 * and one line saying what that means for whom to chase first.
 */
export interface DebtShare {
    name: string;
    /** 0 to 1. */
    share: number;
    percent: number;
}

interface Debtor {
    name: string;
    outstanding_minor: number;
}

/** The largest debtors, in order, with everyone after them folded into one. */
export function debtShares(
    debtors: Debtor[],
    total: number,
    shown = 3,
): DebtShare[] {
    if (total <= 0) {
        return [];
    }

    const ranked = debtors
        .filter((debtor) => debtor.outstanding_minor > 0)
        .sort((a, b) => b.outstanding_minor - a.outstanding_minor);
    const head = ranked.slice(0, shown);
    const rest = ranked
        .slice(shown)
        .reduce((sum, debtor) => sum + debtor.outstanding_minor, 0);

    const shares = head.map((debtor) => ({
        name: debtor.name,
        share: debtor.outstanding_minor / total,
    }));

    if (rest > 0) {
        shares.push({ name: 'Outros', share: rest / total });
    }

    return shares.map((entry) => ({
        ...entry,
        percent: Math.round(entry.share * 100),
    }));
}

/**
 * The debtors a long list opens with. Customers brought here on purpose come
 * instead of the first few, so nobody chosen elsewhere is left folded away.
 */
export function shownDebtors<T extends { customer_public_id: string | null }>(
    debtors: T[],
    expanded: boolean,
    preselected: string[] = [],
    limit = 10,
): T[] {
    if (expanded) {
        return debtors;
    }

    const chosen = debtors.filter(
        (debtor) =>
            debtor.customer_public_id !== null &&
            preselected.includes(debtor.customer_public_id),
    );

    return chosen.length > 0 ? chosen : debtors.slice(0, limit);
}

export function concentrationLine(
    shares: DebtShare[],
    customerCount: number,
): string | null {
    const [first] = shares;

    if (first === undefined) {
        return null;
    }

    if (first.share >= 0.5) {
        return `${first.name} concentra ${first.percent}% do que lhe devem. É aí que um lembrete rende mais.`;
    }

    return `O que lhe devem está repartido por ${customerCount} ${customerCount === 1 ? 'cliente' : 'clientes'}; ${first.name} é o maior, com ${first.percent}%.`;
}
