import { computed, ref, toValue, watch } from 'vue';
import type { ComputedRef, MaybeRefOrGetter, Ref } from 'vue';
import {
    addToQuantity,
    cartTotals,
    grossUnitPriceMinor,
    parseDiscountPercentage,
    parseMoneyToMinor,
    parseQuantity,
} from '@/lib/pos';
import type { CartTotals, PricedLine, SaleLinePayload } from '@/lib/pos';
import type {
    PosCatalogueItem,
    PosCustomer,
    PosCustomerChoice,
} from '@/types/pos';

/** What the cashier has decided about one article; everything else is derived. */
interface CartEntry {
    itemId: string;
    quantity: string;
    discountPercentage: string;
}

/** One line of the sale as the screen shows it. */
export interface CartLine extends PricedLine {
    itemId: string;
    name: string;
    code: string;
    unitOfMeasure: string;
    /** The price on this line is the one agreed with the chosen customer. */
    agreed: boolean;
    /** One unit with its tax, for the «qty × price» line. */
    grossUnitPriceMinor: number;
}

interface StoredCart {
    version: 1;
    entries: CartEntry[];
    customer: PosCustomerChoice;
}

export type StepResult =
    { removed: true } | { removed: false; quantity: string };

export interface PosCart {
    lines: ComputedRef<CartLine[]>;
    customer: Ref<PosCustomerChoice>;
    totals: ComputedRef<CartTotals>;
    /** Units on the sale; a fractional quantity counts as one. */
    unitCount: ComputedRef<number>;
    isEmpty: ComputedRef<boolean>;
    /** Quantity on the sale by article, for the tiles' count pills. */
    quantities: ComputedRef<Record<string, string>>;
    add: (itemId: string) => string | null;
    setQuantity: (itemId: string, text: string) => boolean;
    step: (itemId: string, delta: number) => StepResult | null;
    setDiscount: (itemId: string, text: string) => boolean;
    remove: (itemId: string) => void;
    clear: () => void;
    setCustomer: (choice: PosCustomerChoice) => void;
    /** What the server is sent, in the order the cashier sees it. */
    salePayloadLines: () => SaleLinePayload[];
}

function storageKey(sessionId: string): string {
    return `pos-cart:${sessionId}`;
}

function readStored(sessionId: string): StoredCart | null {
    try {
        const raw = window.sessionStorage.getItem(storageKey(sessionId));

        if (raw === null) {
            return null;
        }

        const parsed: unknown = JSON.parse(raw);

        if (
            typeof parsed !== 'object' ||
            parsed === null ||
            (parsed as StoredCart).version !== 1 ||
            !Array.isArray((parsed as StoredCart).entries)
        ) {
            return null;
        }

        return parsed as StoredCart;
    } catch {
        return null;
    }
}

function writeStored(sessionId: string, cart: StoredCart | null): void {
    try {
        if (cart === null) {
            window.sessionStorage.removeItem(storageKey(sessionId));

            return;
        }

        window.sessionStorage.setItem(
            storageKey(sessionId),
            JSON.stringify(cart),
        );
    } catch {
        // Private windows and full storage: the cart simply does not survive a reload.
    }
}

/** A cart left behind by an earlier shift is of no use to this one. */
export function forgetOtherCarts(currentSessionId: string): void {
    try {
        const stale: string[] = [];

        for (let index = 0; index < window.sessionStorage.length; index++) {
            const key = window.sessionStorage.key(index);

            if (
                key !== null &&
                key.startsWith('pos-cart:') &&
                key !== storageKey(currentSessionId)
            ) {
                stale.push(key);
            }
        }

        stale.forEach((key) => window.sessionStorage.removeItem(key));
    } catch {
        // Nothing to clean when storage is unavailable.
    }
}

/** Removes the saved cart of a shift: after a sale, and when the shift ends. */
export function forgetCart(sessionId: string): void {
    writeStored(sessionId, null);
}

function isChoice(value: unknown): value is PosCustomerChoice {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const choice = value as Record<string, unknown>;

    return (
        choice.kind === 'final' ||
        (choice.kind === 'saved' &&
            typeof choice.public_id === 'string' &&
            typeof choice.name === 'string' &&
            typeof choice.nif === 'string') ||
        (choice.kind === 'typed' &&
            typeof choice.name === 'string' &&
            typeof choice.nif === 'string')
    );
}

/**
 * The sale in progress.
 *
 * Only the cashier's decisions are state (which articles, how many, what
 * discount, for whom). Names, prices and tax are read from the catalogue each
 * time, so a restored cart never carries a stale price, and an article that is
 * no longer sold simply drops out.
 *
 * A change here must never animate or move focus: it happens many times a
 * minute, and the cashier's eyes are on the customer.
 */
export function usePosCart(options: {
    sessionId: string;
    catalogue: MaybeRefOrGetter<PosCatalogueItem[]>;
    customers: MaybeRefOrGetter<PosCustomer[]>;
    agreedPrices: MaybeRefOrGetter<Record<string, Record<string, string>>>;
}): PosCart {
    const catalogueById = computed(
        () =>
            new Map(
                toValue(options.catalogue).map((item) => [
                    item.public_id,
                    item,
                ]),
            ),
    );
    const entries = ref<CartEntry[]>([]);
    const customer = ref<PosCustomerChoice>({ kind: 'final' });

    const stored = readStored(options.sessionId);

    if (stored !== null) {
        entries.value = stored.entries.filter(
            (entry) =>
                catalogueById.value.has(entry.itemId) &&
                parseQuantity(entry.quantity) !== null &&
                parseDiscountPercentage(entry.discountPercentage) !== null,
        );

        if (
            isChoice(stored.customer) &&
            (stored.customer.kind !== 'saved' ||
                toValue(options.customers).some(
                    (candidate) =>
                        candidate.public_id ===
                        (stored.customer as { public_id: string }).public_id,
                ))
        ) {
            customer.value = stored.customer;
        }
    }

    function agreedPriceMinor(itemId: string): number | null {
        if (customer.value.kind !== 'saved') {
            return null;
        }

        const agreed = toValue(options.agreedPrices)[
            customer.value.public_id
        ]?.[itemId];

        return agreed === undefined ? null : parseMoneyToMinor(agreed);
    }

    const lines = computed<CartLine[]>(() =>
        entries.value.flatMap((entry) => {
            const item = catalogueById.value.get(entry.itemId);

            if (item === undefined) {
                return [];
            }

            const agreedMinor = agreedPriceMinor(entry.itemId);
            const unitPriceMinor = agreedMinor ?? item.unit_price_minor;

            return [
                {
                    itemId: item.public_id,
                    name: item.name,
                    code: item.code,
                    unitOfMeasure: item.unit_of_measure,
                    agreed: agreedMinor !== null,
                    unitPriceMinor,
                    quantity: entry.quantity,
                    discountPercentage: entry.discountPercentage,
                    taxPercentage: item.tax_percentage,
                    grossUnitPriceMinor: grossUnitPriceMinor({
                        unitPriceMinor,
                        taxPercentage: item.tax_percentage,
                    }),
                },
            ];
        }),
    );

    const totals = computed(() => cartTotals(lines.value));

    const unitCount = computed(() =>
        lines.value.reduce(
            (sum, line) =>
                sum + (/^\d+$/.test(line.quantity) ? Number(line.quantity) : 1),
            0,
        ),
    );

    function entryOf(itemId: string): CartEntry | undefined {
        return entries.value.find((entry) => entry.itemId === itemId);
    }

    function add(itemId: string): string | null {
        if (!catalogueById.value.has(itemId)) {
            return null;
        }

        const existing = entryOf(itemId);

        if (existing === undefined) {
            entries.value.push({
                itemId,
                quantity: '1',
                discountPercentage: '0',
            });

            return '1';
        }

        const next = addToQuantity(existing.quantity, 1);

        if (next === null) {
            return null;
        }

        existing.quantity = next;

        return next;
    }

    function setQuantity(itemId: string, text: string): boolean {
        const entry = entryOf(itemId);
        const quantity = parseQuantity(text);

        if (entry === undefined || quantity === null) {
            return false;
        }

        entry.quantity = quantity;

        return true;
    }

    function remove(itemId: string): void {
        entries.value = entries.value.filter(
            (entry) => entry.itemId !== itemId,
        );
    }

    function step(itemId: string, delta: number): StepResult | null {
        const entry = entryOf(itemId);

        if (entry === undefined) {
            return null;
        }

        const next = addToQuantity(entry.quantity, delta);

        if (next === null) {
            remove(itemId);

            return { removed: true };
        }

        entry.quantity = next;

        return { removed: false, quantity: next };
    }

    function setDiscount(itemId: string, text: string): boolean {
        const entry = entryOf(itemId);
        const discount = parseDiscountPercentage(text);

        if (entry === undefined || discount === null) {
            return false;
        }

        entry.discountPercentage = discount;

        return true;
    }

    function clear(): void {
        entries.value = [];
        customer.value = { kind: 'final' };
    }

    function setCustomer(choice: PosCustomerChoice): void {
        customer.value = choice;
    }

    watch(
        [entries, customer],
        () => {
            writeStored(
                options.sessionId,
                entries.value.length === 0 && customer.value.kind === 'final'
                    ? null
                    : {
                          version: 1,
                          entries: entries.value,
                          customer: customer.value,
                      },
            );
        },
        { deep: true, flush: 'post' },
    );

    return {
        lines,
        customer,
        totals,
        unitCount,
        isEmpty: computed(() => entries.value.length === 0),
        quantities: computed(() =>
            Object.fromEntries(
                entries.value.map((entry) => [entry.itemId, entry.quantity]),
            ),
        ),
        add,
        setQuantity,
        step,
        setDiscount,
        remove,
        clear,
        setCustomer,
        salePayloadLines: () =>
            lines.value.map((line) => ({
                catalogue_item_public_id: line.itemId,
                quantity: line.quantity,
                discount_percentage: line.discountPercentage,
            })),
    };
}
