export type StatusTone =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'
    | 'draft'
    | 'contingency';

/**
 * Visual tone for the qualified operational status supplied by the server.
 */
export function statusTone(value: string): StatusTone {
    if (value === 'draft') {
        return 'draft';
    }

    if (value === 'valid') {
        return 'success';
    }

    if (
        ['invalid', 'rejected', 'cancelled', 'failed', 'unknown'].includes(
            value,
        )
    ) {
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
