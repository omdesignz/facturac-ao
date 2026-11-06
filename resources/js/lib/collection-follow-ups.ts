export interface CollectionFollowUp {
    revision: number;
    next_contact_on: string | null;
    promised_payment_on: string | null;
    note: string | null;
    completed: boolean;
    next_action_on: string | null;
    is_due: boolean;
    updated_at: string | null;
    updated_by: string | null;
}

export interface CollectionFollowUpEntry {
    customer_public_id: string;
    name: string;
    follow_up: CollectionFollowUp;
}

/** Calendar dates arrive without a time zone and must stay on their recorded day. */
export function followUpDate(value: string | null): string {
    return value ? value.split('-').reverse().join('/') : 'Sem data';
}

export function followUpsForToday(
    entries: CollectionFollowUpEntry[],
): CollectionFollowUpEntry[] {
    return entries.filter(
        (entry) => !entry.follow_up.completed && entry.follow_up.is_due,
    );
}
