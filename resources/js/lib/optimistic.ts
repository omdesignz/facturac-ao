/**
 * What the screen shows while the server confirms a low-risk action; Inertia
 * puts the old props back if it does not. Nothing fiscal belongs here: issuing,
 * cancelling or sending must only ever show what the server has confirmed.
 */

interface Listed<T> {
    data: T[];
}

/** The record shown as deactivated in a paginated list. */
export function withRecordInactive<
    T extends { public_id: string; is_active: boolean },
    L extends Listed<T>,
>(list: L, publicId: string): L {
    return {
        ...list,
        data: list.data.map((record) =>
            record.public_id === publicId
                ? { ...record, is_active: false }
                : record,
        ),
    };
}

/** Every listed notification shown as read. */
export function withEveryNotificationRead<
    T extends { read: boolean },
    L extends Listed<T>,
>(list: L): L {
    return {
        ...list,
        data: list.data.map((entry) => ({ ...entry, read: true })),
    };
}

/** The read notifications taken off the list. */
export function withoutReadNotifications<
    T extends { read: boolean },
    L extends Listed<T>,
>(list: L): L {
    return { ...list, data: list.data.filter((entry) => !entry.read) };
}
