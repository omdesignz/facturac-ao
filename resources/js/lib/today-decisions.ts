/**
 * "Hoje" on a phone leads with what needs a decision, one verb per card:
 * fix the document the AGT sent back, remind the customer who is late, reorder
 * what is running out. This works out which of those apply, from figures the
 * dashboard already has, so the cards can never disagree with the page.
 */
export interface DecisionInput {
    focus: {
        reason: 'invalid' | 'contingency' | 'overdue' | 'latest';
        document_no: string | null;
        agt_message: string | null;
    } | null;
    collections:
        | {
              customers: {
                  customer_public_id: string | null;
                  name: string;
                  overdue_minor: number;
                  oldest_days_past_due: number;
              }[];
          }
        | null
        | undefined;
    lowStock:
        { name: string; quantity: number; unit: string } | null | undefined;
}

export interface Decision {
    key: 'fiscal' | 'collection' | 'stock';
    kicker: string;
    headline: string;
    detail: string | null;
    action: string;
    /** `remind` opens the reminder for a customer; `link` goes to `href`. */
    kind: 'link' | 'remind';
    href?: string;
    customer?: { public_id: string; name: string };
    /** The overdue amount, in minor units, for a collection card. */
    amountMinor?: number;
}

export function decisionsFor(
    input: DecisionInput,
    hrefs: { fiscal: string; stock: string },
): Decision[] {
    const decisions: Decision[] = [];
    const { focus, collections, lowStock } = input;

    if (
        focus !== null &&
        (focus.reason === 'invalid' || focus.reason === 'contingency')
    ) {
        const number = focus.document_no ?? 'Um documento';

        decisions.push({
            key: 'fiscal',
            kicker: 'Fiscal',
            headline:
                focus.reason === 'invalid'
                    ? `${number} foi devolvida pela AGT.`
                    : `${number} está em contingência.`,
            detail: focus.agt_message,
            action: 'Corrigir',
            kind: 'link',
            href: hrefs.fiscal,
        });
    }

    const debtor = collections?.customers.find(
        (customer) =>
            customer.customer_public_id !== null && customer.overdue_minor > 0,
    );

    if (debtor !== undefined && debtor.customer_public_id !== null) {
        decisions.push({
            key: 'collection',
            kicker: 'Cobrança',
            headline: `${debtor.name} deve`,
            detail:
                debtor.oldest_days_past_due > 0
                    ? `há ${debtor.oldest_days_past_due} ${debtor.oldest_days_past_due === 1 ? 'dia' : 'dias'}. Lembrete preparado. Nada é enviado até confirmar.`
                    : 'Lembrete preparado. Nada é enviado até confirmar.',
            action: 'Rever e enviar',
            kind: 'remind',
            customer: {
                public_id: debtor.customer_public_id,
                name: debtor.name,
            },
            amountMinor: debtor.overdue_minor,
        });
    }

    if (lowStock !== null && lowStock !== undefined) {
        decisions.push({
            key: 'stock',
            kicker: 'Stock',
            headline: `${lowStock.name}: restam ${lowStock.quantity} ${lowStock.unit}.`,
            detail: null,
            action: 'Ver stock',
            kind: 'link',
            href: hrefs.stock,
        });
    }

    return decisions;
}
