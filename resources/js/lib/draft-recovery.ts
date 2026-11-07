export type DraftStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

export type RecoveryCopy<T> = {
    version: string;
    savedAt: number;
    data: T;
};

const lifetime = 7 * 24 * 60 * 60 * 1000;

/** Reject damaged copies before their values reach form calculations or controls. */
export function matchesRecoveryShape(
    value: unknown,
    template: unknown,
): boolean {
    if (template === null) {
        return value === null || typeof value === 'string';
    }

    if (Array.isArray(template)) {
        return (
            Array.isArray(value) &&
            value.length <= 1000 &&
            (template.length === 0 ||
                value.every((item) => matchesRecoveryShape(item, template[0])))
        );
    }

    if (typeof template === 'object') {
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            return false;
        }

        const fields = Object.keys(template);

        return (
            fields.length === Object.keys(value).length &&
            fields.every(
                (key) =>
                    Object.hasOwn(value, key) &&
                    matchesRecoveryShape(
                        (value as Record<string, unknown>)[key],
                        (template as Record<string, unknown>)[key],
                    ),
            )
        );
    }

    return (
        typeof value === typeof template ||
        (typeof template === 'string' && value === null)
    );
}

export function recoveryKey(parts: (string | number)[]): string {
    return `draft-recovery:v1:${JSON.stringify(parts)}`;
}

/** Copies are tab-local, account/company scoped, and never contain issuing credentials. */
export function readRecovery<T>(
    storage: DraftStorage,
    key: string,
    now = Date.now(),
): RecoveryCopy<T> | null {
    try {
        const raw = storage.getItem(key);

        if (raw === null) {
            return null;
        }

        const copy = JSON.parse(raw);

        if (
            typeof copy.version !== 'string' ||
            !Number.isFinite(copy.savedAt) ||
            copy.savedAt > now ||
            now - copy.savedAt > lifetime ||
            copy.data === null ||
            typeof copy.data !== 'object' ||
            Array.isArray(copy.data)
        ) {
            storage.removeItem(key);

            return null;
        }

        return copy;
    } catch {
        return null;
    }
}

export function writeRecovery<T>(
    storage: DraftStorage,
    key: string,
    version: string,
    data: T,
): void {
    storage.setItem(
        key,
        JSON.stringify({ version, savedAt: Date.now(), data }),
    );
}

/**
 * Repeating one source, or correcting it, must never replace an unrelated
 * unfinished invoice.
 */
export function invoiceRecoveryDocument(
    publicId: string | null,
    type: string,
    repeatSourceId?: string,
    correctedSourceId?: string,
): string {
    if (publicId !== null) {
        return `invoice:${publicId}`;
    }

    if (repeatSourceId) {
        return `invoice:repeat:${repeatSourceId}`;
    }

    return correctedSourceId
        ? `invoice:correct:${correctedSourceId}`
        : `invoice:${type}`;
}
