/**
 * The reminder the server prepares for a customer who is late, and the few
 * words the screens use to describe it. Fetching is here so the debts page, the
 * dashboard and the reminder dialog read the draft one way.
 */
export interface ReminderDraft {
    customer_name: string;
    to: string | null;
    /** The customer's phone as WhatsApp wants it, when one can be opened. */
    whatsapp_to: string | null;
    subject: string;
    tone: 'cordial' | 'firm';
    body: string;
    documents: {
        document_no: string;
        due_date: string;
        days_past_due: number;
        outstanding_minor: number;
    }[];
    total_minor: number;
    currency_code: string;
    last_sent_at: string | null;
}

export async function fetchReminderDraft(url: string): Promise<ReminderDraft> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(String(response.status));
    }

    return (await response.json()) as ReminderDraft;
}

export function toneLabel(tone: ReminderDraft['tone']): string {
    return tone === 'firm' ? 'tom firme' : 'tom cordial';
}

/** A draft can go out only with someone to send it to and something to say. */
export function isSendable(draft: ReminderDraft | null): boolean {
    return draft !== null && draft.to !== null && draft.documents.length > 0;
}

/** Something late to say, and a phone WhatsApp can open. */
export function canGoByWhatsApp(draft: ReminderDraft | null): boolean {
    return (
        draft !== null &&
        draft.whatsapp_to !== null &&
        draft.documents.length > 0
    );
}

/**
 * A chat with the customer and the words typed in, ready for the person to send.
 * Nothing leaves until they press send in WhatsApp.
 */
export function whatsAppUrl(draft: ReminderDraft, text: string): string | null {
    return draft.whatsapp_to === null
        ? null
        : `https://wa.me/${draft.whatsapp_to}?text=${encodeURIComponent(text)}`;
}
