<script setup lang="ts">
import {
    Minus,
    Percent,
    Plus,
    ShoppingCart,
    Tag,
    Trash2,
    UserRound,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { confirmAction } from '@/lib/confirm';
import {
    cartLineAmounts,
    formatMinor,
    parseDiscountPercentage,
    parseQuantity,
} from '@/lib/pos';
import type { CartTotals } from '@/lib/pos';
import type { CartLine } from '@/lib/pos-cart';
import {
    buttonGold,
    buttonOutlineSmall,
    buttonQuiet,
    currencyLabel,
    keyCap,
} from '@/lib/pos-ui';
import type { PosCustomerChoice } from '@/types/pos';

const props = defineProps<{
    lines: CartLine[];
    totals: CartTotals;
    customer: PosCustomerChoice;
    finalConsumerName: string;
    currencyCode: string;
    /** False when the establishment has no Factura/Recibo series to issue from. */
    seriesAvailable: boolean;
    /** The bottom sheet has its own title row. */
    hideTitle?: boolean;
}>();

const emit = defineEmits<{
    pickCustomer: [];
    charge: [];
    clear: [];
    step: [itemId: string, delta: number];
    quantity: [itemId: string, quantity: string];
    discount: [itemId: string, percentage: string];
    remove: [itemId: string];
    focusSearch: [];
}>();

const list = ref<HTMLElement | null>(null);
/** The line whose last quantity entry was refused, until it is corrected. */
const invalidQuantity = ref<string | null>(null);
const invalidDiscount = ref<string | null>(null);
/** Lines whose discount field is open. A discounted line is always open. */
const discountOpen = ref<Set<string>>(new Set());

const suffix = computed(() => currencyLabel(props.currencyCode));

const customerLabel = computed(() =>
    props.customer.kind === 'final'
        ? props.finalConsumerName
        : props.customer.name,
);

const customerNif = computed(() =>
    props.customer.kind === 'final' ? null : props.customer.nif,
);

const chargeBlockedReason = computed<string | null>(() => {
    if (props.lines.length === 0) {
        return 'Adicione artigos para cobrar.';
    }

    if (!props.seriesAvailable) {
        return 'Não há série de Factura/Recibo disponível.';
    }

    return null;
});

function lineTotal(line: CartLine): string {
    return formatMinor(cartLineAmounts(line).grossMinor);
}

function quantityText(line: CartLine): string {
    return line.quantity.replace('.', ',');
}

function hasDiscount(line: CartLine): boolean {
    return line.discountPercentage !== '0';
}

function isDiscountOpen(line: CartLine): boolean {
    return hasDiscount(line) || discountOpen.value.has(line.itemId);
}

function quantityId(itemId: string): string {
    return `pos-qty-${itemId}`;
}

function discountId(itemId: string): string {
    return `pos-discount-${itemId}`;
}

/** After a line goes, the keyboard lands on the one above it, else on search. */
function focusAfterRemoval(index: number): void {
    const previous = props.lines[index - 1];
    const next = props.lines[index + 1];
    const target = previous ?? next;

    void nextTick(() => {
        const field =
            target === undefined
                ? null
                : document.getElementById(quantityId(target.itemId));

        if (field instanceof HTMLInputElement) {
            field.focus({ preventScroll: true });
            field.select();

            return;
        }

        emit('focusSearch');
    });
}

function removeLine(line: CartLine): void {
    const index = props.lines.findIndex((row) => row.itemId === line.itemId);

    emit('remove', line.itemId);
    focusAfterRemoval(index);
}

function stepLine(line: CartLine, delta: number): void {
    const index = props.lines.findIndex((row) => row.itemId === line.itemId);
    const removes = delta < 0 && parseQuantity(line.quantity) === '1';

    emit('step', line.itemId, delta);

    if (removes) {
        focusAfterRemoval(index);
    }
}

function commitQuantity(line: CartLine, event: Event): void {
    const field = event.target as HTMLInputElement;
    const quantity = parseQuantity(field.value);

    if (quantity === null) {
        // Put back what the sale holds, and say why, rather than guessing.
        invalidQuantity.value = line.itemId;
        field.value = quantityText(line);

        return;
    }

    invalidQuantity.value = null;
    field.value = quantity.replace('.', ',');

    if (quantity !== line.quantity) {
        emit('quantity', line.itemId, quantity);
    }
}

function leaveQuantity(line: CartLine, event: KeyboardEvent): void {
    const field = event.target as HTMLInputElement;

    if (event.key === 'Enter') {
        event.preventDefault();
        commitQuantity(line, event);
        emit('focusSearch');
    } else if (event.key === 'Escape') {
        field.value = quantityText(line);
        invalidQuantity.value = null;
        emit('focusSearch');
    }
}

function openDiscount(line: CartLine): void {
    discountOpen.value = new Set(discountOpen.value).add(line.itemId);

    void nextTick(() => {
        const field = document.getElementById(discountId(line.itemId));

        if (field instanceof HTMLInputElement) {
            field.focus({ preventScroll: true });
            field.select();
        }
    });
}

function commitDiscount(line: CartLine, event: Event): void {
    const field = event.target as HTMLInputElement;
    const percentage = parseDiscountPercentage(field.value);

    if (percentage === null) {
        invalidDiscount.value = line.itemId;

        return;
    }

    invalidDiscount.value = null;
    field.value = percentage.replace('.', ',');

    if (percentage !== line.discountPercentage) {
        emit('discount', line.itemId, percentage);
    }

    if (percentage === '0') {
        const next = new Set(discountOpen.value);
        next.delete(line.itemId);
        discountOpen.value = next;
    }
}

function leaveDiscount(line: CartLine, event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        event.preventDefault();
        commitDiscount(line, event);

        if (invalidDiscount.value === null) {
            emit('focusSearch');
        }
    } else if (event.key === 'Escape') {
        (event.target as HTMLInputElement).value =
            line.discountPercentage.replace('.', ',');
        invalidDiscount.value = null;
        emit('focusSearch');
    }
}

function selectAll(event: FocusEvent): void {
    (event.target as HTMLInputElement).select();
}

async function clearSale(): Promise<void> {
    if (props.lines.length > 2) {
        const confirmed = await confirmAction({
            title: 'Limpar a venda?',
            message: `Tira as ${props.lines.length} linhas da venda. Nada foi cobrado.`,
            confirmLabel: 'Limpar venda',
        });

        if (!confirmed) {
            return;
        }
    }

    emit('clear');
    emit('focusSearch');
}

function charge(): void {
    if (chargeBlockedReason.value === null) {
        emit('charge');
    }
}

/** Keeps the line just added in view; the list never animates. */
function revealLine(itemId: string): void {
    void nextTick(() => {
        list.value
            ?.querySelector<HTMLElement>(`[data-line="${CSS.escape(itemId)}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    });
}

defineExpose({ revealLine });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center justify-between gap-3 px-4 pt-4 sm:px-5">
            <h2
                v-if="!hideTitle"
                class="text-base font-semibold text-zinc-950 dark:text-white"
            >
                Venda
            </h2>
            <button
                type="button"
                :class="[buttonOutlineSmall, 'max-w-[75%] min-w-0']"
                @click="emit('pickCustomer')"
            >
                <UserRound class="size-4 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ customerLabel }}</span>
                <span
                    v-if="customerNif"
                    class="shrink-0 font-mono text-xs font-normal text-zinc-500 dark:text-zinc-400"
                    >{{ customerNif }}</span
                >
                <span class="sr-only">. Mudar o cliente</span>
            </button>
        </div>

        <ul
            ref="list"
            class="mt-2 min-h-0 flex-1 divide-y divide-zinc-900/[0.07] overflow-y-auto overscroll-contain px-4 sm:px-5 lg:min-h-48 dark:divide-white/10"
            aria-label="Linhas da venda"
        >
            <li
                v-if="lines.length === 0"
                class="grid h-full min-h-40 place-items-center py-8 text-center"
            >
                <div>
                    <ShoppingCart
                        class="mx-auto size-7 text-zinc-400 dark:text-zinc-500"
                        aria-hidden="true"
                    />
                    <p
                        class="mx-auto mt-3 max-w-56 text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Leia um código de barras ou escolha um artigo.
                    </p>
                </div>
            </li>

            <li
                v-for="line in lines"
                :key="line.itemId"
                :data-line="line.itemId"
                class="py-3"
            >
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-sm/5 font-medium [overflow-wrap:anywhere] text-zinc-950 dark:text-white"
                        >
                            {{ line.name }}
                        </p>
                        <p
                            class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 numeric text-xs text-zinc-600 dark:text-zinc-400"
                        >
                            <span
                                >{{ quantityText(line) }} ×
                                {{
                                    formatMinor(line.grossUnitPriceMinor)
                                }}</span
                            >
                            <span
                                v-if="hasDiscount(line)"
                                class="rounded-full bg-zinc-900/[0.07] px-1.5 text-[0.6875rem] font-semibold text-zinc-800 dark:bg-white/10 dark:text-zinc-200"
                                >−{{
                                    line.discountPercentage.replace('.', ',')
                                }}%</span
                            >
                            <span
                                v-if="line.agreed"
                                class="inline-flex items-center gap-1"
                            >
                                <Tag class="size-3" aria-hidden="true" />
                                Preço acordado
                            </span>
                        </p>
                    </div>
                    <p
                        class="shrink-0 text-end numeric text-sm/5 font-semibold text-zinc-950 dark:text-white"
                    >
                        {{ lineTotal(line) }}
                    </p>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1">
                    <div
                        class="flex items-center rounded-full bg-white ring-1 ring-zinc-900/10 ring-inset dark:bg-transparent dark:ring-white/15"
                        role="group"
                        :aria-label="`Quantidade de ${line.name}`"
                    >
                        <button
                            type="button"
                            class="icon-button rounded-full text-zinc-700 focus-ring hover:bg-zinc-900/[0.06] dark:text-zinc-300 dark:hover:bg-white/10"
                            @click="stepLine(line, -1)"
                        >
                            <span class="sr-only">
                                {{
                                    line.quantity === '1'
                                        ? `Tirar ${line.name}`
                                        : `Menos um ${line.name}`
                                }}
                            </span>
                            <Minus class="size-4" aria-hidden="true" />
                        </button>
                        <input
                            :id="quantityId(line.itemId)"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            spellcheck="false"
                            :value="quantityText(line)"
                            :aria-label="`Quantidade de ${line.name}`"
                            :aria-invalid="
                                invalidQuantity === line.itemId
                                    ? 'true'
                                    : undefined
                            "
                            :aria-describedby="
                                invalidQuantity === line.itemId
                                    ? `pos-qty-error-${line.itemId}`
                                    : undefined
                            "
                            class="h-10 w-14 rounded-md bg-transparent text-center numeric text-sm font-medium text-zinc-950 outline-hidden focus-visible:ring-2 focus-visible:ring-brand-950 dark:text-white dark:focus-visible:ring-zinc-200 pointer-coarse:h-11"
                            @focus="selectAll"
                            @change="commitQuantity(line, $event)"
                            @keydown="leaveQuantity(line, $event)"
                        />
                        <button
                            type="button"
                            class="icon-button rounded-full text-zinc-700 focus-ring hover:bg-zinc-900/[0.06] dark:text-zinc-300 dark:hover:bg-white/10"
                            @click="stepLine(line, 1)"
                        >
                            <span class="sr-only">Mais um {{ line.name }}</span>
                            <Plus class="size-4" aria-hidden="true" />
                        </button>
                    </div>

                    <button
                        v-if="!isDiscountOpen(line)"
                        type="button"
                        class="inline-flex h-10 items-center gap-1.5 rounded-full px-3 text-[0.8125rem] font-medium text-zinc-600 focus-ring hover:bg-zinc-900/[0.05] hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white pointer-coarse:h-11"
                        @click="openDiscount(line)"
                    >
                        <Percent class="size-3.5" aria-hidden="true" />
                        Desconto
                        <span class="sr-only">em {{ line.name }}</span>
                    </button>
                    <div v-else class="flex items-center gap-1.5">
                        <label
                            :for="discountId(line.itemId)"
                            class="text-[0.8125rem] font-medium text-zinc-600 dark:text-zinc-300"
                            >Desconto
                            <span class="sr-only"
                                >em {{ line.name }}</span
                            ></label
                        >
                        <div class="relative">
                            <input
                                :id="discountId(line.itemId)"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                spellcheck="false"
                                :value="
                                    line.discountPercentage.replace('.', ',')
                                "
                                :aria-invalid="
                                    invalidDiscount === line.itemId
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="
                                    invalidDiscount === line.itemId
                                        ? `pos-discount-error-${line.itemId}`
                                        : undefined
                                "
                                class="h-10 w-20 rounded-full bg-white ps-3 pe-7 text-end numeric text-sm text-zinc-950 ring-1 ring-zinc-900/10 outline-hidden ring-inset focus:ring-2 focus:ring-brand-950 dark:bg-transparent dark:text-white dark:ring-white/15 dark:focus:ring-zinc-200 pointer-coarse:h-11"
                                @focus="selectAll"
                                @change="commitDiscount(line, $event)"
                                @keydown="leaveDiscount(line, $event)"
                            />
                            <span
                                class="pointer-events-none absolute inset-y-0 inset-e-3 grid place-items-center text-xs text-zinc-500 dark:text-zinc-400"
                                aria-hidden="true"
                                >%</span
                            >
                        </div>
                    </div>

                    <button
                        type="button"
                        class="ms-auto icon-button rounded-full text-zinc-500 focus-ring hover:bg-rose-50 hover:text-rose-700 dark:text-zinc-400 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                        @click="removeLine(line)"
                    >
                        <span class="sr-only">Remover {{ line.name }}</span>
                        <Trash2 class="size-4" aria-hidden="true" />
                    </button>
                </div>

                <p
                    v-if="invalidQuantity === line.itemId"
                    :id="`pos-qty-error-${line.itemId}`"
                    class="mt-1.5 text-xs font-medium text-rose-700 dark:text-rose-400"
                    role="alert"
                >
                    A quantidade tem de ser superior a zero, com até quatro
                    casas decimais.
                </p>
                <p
                    v-if="invalidDiscount === line.itemId"
                    :id="`pos-discount-error-${line.itemId}`"
                    class="mt-1.5 text-xs font-medium text-rose-700 dark:text-rose-400"
                    role="alert"
                >
                    O desconto vai de 0 a 100, com até duas casas decimais.
                </p>
            </li>
        </ul>

        <div
            class="border-t border-zinc-900/[0.07] px-4 pt-4 pb-4 sm:px-5 dark:border-white/10"
        >
            <dl class="space-y-1.5 numeric text-sm">
                <div
                    v-if="totals.settlementMinor > 0"
                    class="flex items-baseline justify-between gap-4 text-zinc-600 dark:text-zinc-400"
                >
                    <dt>Descontos</dt>
                    <dd>−{{ formatMinor(totals.settlementMinor) }}</dd>
                </div>
                <div
                    class="flex items-baseline justify-between gap-4 text-zinc-700 dark:text-zinc-300"
                >
                    <dt>Ilíquido</dt>
                    <dd>{{ formatMinor(totals.netMinor) }}</dd>
                </div>
                <div
                    v-for="row in totals.taxByRate"
                    :key="row.percentage"
                    class="flex items-baseline justify-between gap-4 text-zinc-700 dark:text-zinc-300"
                >
                    <dt>
                        {{
                            row.percentage === '0'
                                ? 'Isento de IVA'
                                : `IVA ${row.percentage.replace('.', ',')}%`
                        }}
                    </dt>
                    <dd>{{ formatMinor(row.taxMinor) }}</dd>
                </div>
                <div
                    class="flex items-baseline justify-between gap-4 border-t border-zinc-900/[0.07] pt-3 dark:border-white/10"
                >
                    <dt
                        class="text-base font-semibold text-zinc-950 dark:text-white"
                    >
                        Total
                    </dt>
                    <dd
                        class="text-[1.75rem] leading-none font-semibold tracking-[-0.02em] text-zinc-950 dark:text-white"
                    >
                        {{ formatMinor(totals.grossMinor)
                        }}<span
                            class="ms-1 text-xs font-normal tracking-normal text-zinc-500 dark:text-zinc-400"
                            >{{ suffix }}</span
                        >
                    </dd>
                </div>
            </dl>

            <button
                type="button"
                :aria-disabled="
                    chargeBlockedReason !== null ? 'true' : undefined
                "
                :aria-describedby="
                    chargeBlockedReason !== null
                        ? 'pos-charge-reason'
                        : undefined
                "
                :class="[
                    buttonGold,
                    'mt-4 h-12 w-full text-base pointer-coarse:h-14',
                ]"
                @click="charge"
            >
                Cobrar {{ formatMinor(totals.grossMinor) }} {{ suffix }}
                <kbd
                    :class="[
                        keyCap,
                        'ms-1 hidden bg-white/60 pointer-fine:grid',
                    ]"
                    aria-hidden="true"
                    >F9</kbd
                >
            </button>
            <p
                v-if="chargeBlockedReason !== null"
                id="pos-charge-reason"
                class="mt-2 text-center text-sm text-zinc-600 dark:text-zinc-400"
            >
                {{ chargeBlockedReason }}
            </p>

            <div class="mt-2 flex justify-center">
                <button
                    type="button"
                    :disabled="lines.length === 0"
                    :class="buttonQuiet"
                    @click="clearSale"
                >
                    Limpar venda
                </button>
            </div>
        </div>
    </div>
</template>
