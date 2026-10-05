export type StatusTone =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'
    | 'draft'
    | 'contingency';

/**
 * The pill tone for a document's workflow state: its latest AGT submission
 * when there is one, otherwise the document's own status.
 */
export function statusTone(value: string): StatusTone {
    if (value === 'draft') {
        return 'draft';
    }

    if (value === 'valid') {
        return 'success';
    }

    if (['invalid', 'rejected', 'cancelled', 'failed'].includes(value)) {
        return 'danger';
    }

    if (value === 'contingency' || value === 'retrying') {
        return 'warning';
    }

    if (
        ['issued', 'pending', 'sending', 'received', 'processing'].includes(
            value,
        )
    ) {
        return 'info';
    }

    return 'neutral';
}
