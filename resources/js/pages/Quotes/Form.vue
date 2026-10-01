<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle, Plus, Receipt, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import DateInput from '@/components/DateInput.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as quotesIndex } from '@/routes/quotes';
import {
    convert as convertQuote,
    transition as transitionQuote,
    store as storeQuote,
    update as updateQuote,
} from '@/routes/quotes';
import type { SelectOption } from '@/types/select';

interface QuoteLineInput {
    product_code: string | null;
    product_description: string;
    unit_of_measure: string;
    quantity: string;
    unit_price: string;
    discount_rate: string;
    tax_type: string;
    tax_code: string | null;
    tax_percentage: string;
    tax_exemption_code: string | null;
}

interface CustomerOption {
    public_id: string;
    name: string;
    tax_identification_number: string | null;
    country_code: string;
    address_line: string | null;
    agreed_prices: Record<string, string>;
}

interface CatalogueOption {
    public_id: string;
    code: string;
    name: string;
    description: string | null;
    unit_of_measure: string;
    unit_price: string;
    tax_type: string;
    tax_code: string | null;
    tax_percentage: string;
    tax_exemption_code: string | null;
}

interface TaxTreatment {
    value: string;
    label: string;
    type: string;
    code: string | null;
    percentage: string;
    exemption_code: string | null;
}

const props = defineProps<{
    quote:
        | (Record<string, unknown> & {
              public_id: string;
              reference: string;
              status: string;
              status_label: string;
              is_editable: boolean;
              can_convert: boolean;
              establishment_public_id: string;
              customer_public_id: string | null;
              customer: {
                  name: string;
                  tax_identification_number: string | null;
                  country_code: string;
                  address_line: string | null;
              };
              notes: string | null;
              issue_date: string;
              valid_until: string;
              lines: QuoteLineInput[];
              gross_total_minor: number;
              converted_document: {
                  public_id: string;
                  document_no: string | null;
              } | null;
          })
        | null;
    establishments: SelectOption[];
    customers: CustomerOption[];
    catalogueItems: CatalogueOption[];
    taxTreatments: TaxTreatment[];
    currencyCode: string;
}>();

function emptyLine(): QuoteLineInput {
    return {
        product_code: null,
        product_description: '',
        unit_of_measure: 'UN',
        quantity: '1',
        unit_price: '0.00',
        discount_rate: '0',
        tax_type: 'IVA',
        tax_code: 'NOR',
        tax_percentage: '14.00',
        tax_exemption_code: null,
    };
}

const todayDate = new Date();
const today = todayDate.toLocaleDateString('sv-SE');
const defaultValidUntil = new Date(todayDate);
defaultValidUntil.setDate(defaultValidUntil.getDate() + 30);

const form = useForm({
    establishment_public_id:
        props.quote?.establishment_public_id ??
        String(props.establishments[0]?.value ?? ''),
    customer_public_id: props.quote?.customer_public_id ?? '',
    customer: props.quote?.customer ?? {
        name: '',
        tax_identification_number: '',
        country_code: 'AO',
        address_line: '',
    },
    issue_date: props.quote?.issue_date.slice(0, 10) ?? today,
    valid_until:
        props.quote?.valid_until.slice(0, 10) ??
        defaultValidUntil.toLocaleDateString('sv-SE'),
    notes: props.quote?.notes ?? '',
    lines: props.quote?.lines ?? [emptyLine()],
});

const readOnly = computed(
    () => props.quote !== null && !props.quote.is_editable,
);

const customerOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Cliente ocasional' },
    ...props.customers.map((customer) => ({
        value: customer.public_id,
        label: customer.name,
    })),
]);

const catalogueOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Escrever manualmente' },
    ...props.catalogueItems.map((item) => ({
        value: item.public_id,
        label: `${item.code} — ${item.name}`,
    })),
]);

const taxTreatmentOptions = computed<SelectOption[]>(() =>
    props.taxTreatments.map((treatment) => ({
        value: treatment.value,
        label: treatment.label,
    })),
);

const selectedCustomer = computed(() =>
    props.customers.find(
        (candidate) => candidate.public_id === form.customer_public_id,
    ),
);

function selectCustomer(): void {
    const customer = selectedCustomer.value;

    form.customer = customer
        ? {
              name: customer.name,
              tax_identification_number: customer.tax_identification_number,
              country_code: customer.country_code,
              address_line: customer.address_line,
          }
        : {
              name: '',
              tax_identification_number: '',
              country_code: 'AO',
              address_line: '',
          };
}

/** Filling a line from the catalogue, at the price agreed with this customer. */
function applyCatalogueItem(line: QuoteLineInput, publicId: string): void {
    const item = props.catalogueItems.find(
        (candidate) => candidate.public_id === publicId,
    );

    if (item === undefined) {
        return;
    }

    line.product_code = item.code;
    line.product_description = item.description ?? item.name;
    line.unit_of_measure = item.unit_of_measure;
    line.unit_price =
        selectedCustomer.value?.agreed_prices[item.public_id] ??
        item.unit_price;
    line.tax_type = item.tax_type;
    line.tax_code = item.tax_code;
    line.tax_percentage = item.tax_percentage;
    line.tax_exemption_code = item.tax_exemption_code;
}

function catalogueItemValue(line: QuoteLineInput): string {
    return (
        props.catalogueItems.find((item) => item.code === line.product_code)
            ?.public_id ?? ''
    );
}

function taxTreatmentValue(line: QuoteLineInput): string {
    const percentage = parseScaled(line.tax_percentage || '0', 2);

    return (
        props.taxTreatments.find(
            (treatment) =>
                treatment.type === line.tax_type &&
                treatment.code === line.tax_code &&
                treatment.exemption_code === line.tax_exemption_code &&
                parseScaled(treatment.percentage, 2) === percentage,
        )?.value ??
        props.taxTreatments.find(
            (treatment) =>
                line.tax_code === null &&
                treatment.exemption_code === line.tax_exemption_code &&
                parseScaled(treatment.percentage, 2) === percentage,
        )?.value ??
        props.taxTreatments[0]?.value ??
        ''
    );
}

function applyTaxTreatment(line: QuoteLineInput, value: string): void {
    const treatment = props.taxTreatments.find(
        (candidate) => candidate.value === value,
    );

    if (treatment === undefined) {
        return;
    }

    line.tax_type = treatment.type;
    line.tax_code = treatment.code;
    line.tax_percentage = treatment.percentage;
    line.tax_exemption_code = treatment.exemption_code;
}

function addLine(): void {
    form.lines.push(emptyLine());
}

function removeLine(index: number): void {
    if (form.lines.length > 1) {
        form.lines.splice(index, 1);
    }
}

interface QuoteLineAmounts {
    base: bigint;
    discount: bigint;
    net: bigint;
    tax: bigint;
    gross: bigint;
}

function parseScaled(value: string, scale: number): bigint | null {
    const match = value
        .trim()
        .replace(',', '.')
        .match(/^(0|[1-9]\d*)(?:\.(\d+))?$/);

    if (match === null || (match[2]?.length ?? 0) > scale) {
        return null;
    }

    const fraction = (match[2] ?? '').padEnd(scale, '0');

    return BigInt(match[1]) * 10n ** BigInt(scale) + BigInt(fraction || '0');
}

function roundHalfUp(numerator: bigint, denominator: bigint): bigint {
    return (numerator + denominator / 2n) / denominator;
}

function roundUp(numerator: bigint, denominator: bigint): bigint {
    return numerator === 0n ? 0n : (numerator + denominator - 1n) / denominator;
}

/** Mirrors the server's integer arithmetic so every shown total is the saved one. */
function lineAmounts(line: QuoteLineInput): QuoteLineAmounts {
    const quantity = parseScaled(line.quantity || '0', 4) ?? 0n;
    const unitPrice = parseScaled(line.unit_price || '0', 2) ?? 0n;
    const discountRate = parseScaled(line.discount_rate || '0', 2) ?? 0n;
    const taxBasisPoints = parseScaled(line.tax_percentage || '0', 2) ?? 0n;
    const boundedDiscount = discountRate > 10_000n ? 10_000n : discountRate;
    const base = roundHalfUp(unitPrice * quantity, 10_000n);
    const net = roundHalfUp(
        unitPrice * (10_000n - boundedDiscount) * quantity,
        100_000_000n,
    );
    const discount = base - net;
    const tax = roundUp(net * taxBasisPoints, 10_000n);

    return {
        base,
        discount,
        net,
        tax,
        gross: net + tax,
    };
}

const quoteTotals = computed(() =>
    form.lines.reduce(
        (total, line) => {
            const amounts = lineAmounts(line);

            return {
                base: total.base + amounts.base,
                discount: total.discount + amounts.discount,
                net: total.net + amounts.net,
                tax: total.tax + amounts.tax,
                gross: total.gross + amounts.gross,
            };
        },
        { base: 0n, discount: 0n, net: 0n, tax: 0n, gross: 0n },
    ),
);

const integerFormatter = new Intl.NumberFormat('pt-AO', {
    maximumFractionDigits: 0,
});
const decimalSeparator =
    new Intl.NumberFormat('pt-AO')
        .formatToParts(1.1)
        .find((part) => part.type === 'decimal')?.value ?? ',';

function money(minor: bigint): string {
    const whole = minor / 100n;
    const fraction = (minor % 100n).toString().padStart(2, '0');

    return `${integerFormatter.format(whole)}${decimalSeparator}${fraction} ${props.currencyCode}`;
}

function lineError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[
        'lines.' + index + '.' + field
    ];
}

function submit(): void {
    if (props.quote === null) {
        form.post(storeQuote.url());

        return;
    }

    form.put(updateQuote.url(props.quote.public_id), { preserveScroll: true });
}

function mark(status: 'sent' | 'accepted' | 'rejected'): void {
    if (props.quote === null) {
        return;
    }

    router.put(
        transitionQuote.url(props.quote.public_id),
        { status },
        { preserveScroll: true },
    );
}

function convert(): void {
    if (props.quote === null) {
        return;
    }

    router.post(convertQuote.url(props.quote.public_id));
}

const statusTone: Record<string, 'success' | 'warning' | 'neutral' | 'danger'> =
    {
        draft: 'neutral',
        sent: 'warning',
        accepted: 'success',
        rejected: 'danger',
        expired: 'neutral',
        converted: 'success',
    };
</script>

<template>
    <AppLayout>
        <Head :title="quote ? quote.reference : 'Novo orçamento'" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-5xl space-y-6">
                <Link
                    :href="quotesIndex.url()"
                    class="inline-flex items-center gap-2 rounded-lg text-sm font-medium text-zinc-600 focus-ring transition hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Orçamentos
                </Link>

                <FlashBanner />

                <header
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p class="eyebrow text-brand-700 dark:text-brand-300">
                            {{ quote ? 'Orçamento' : 'Nova proposta' }}
                        </p>
                        <h1
                            class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                        >
                            {{ quote ? quote.reference : 'Novo orçamento' }}
                        </h1>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <StatusBadge
                            v-if="quote"
                            :tone="statusTone[quote.status] ?? 'neutral'"
                            :label="quote.status_label"
                        />

                        <button
                            v-if="quote && quote.status === 'draft'"
                            type="button"
                            class="rounded-xl px-3 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                            @click="mark('sent')"
                        >
                            Marcar como enviado
                        </button>
                        <button
                            v-if="quote && quote.status === 'sent'"
                            type="button"
                            class="rounded-xl px-3 py-2 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-300 focus-ring transition hover:bg-emerald-50 dark:text-emerald-300 dark:ring-emerald-400/30 dark:hover:bg-emerald-400/10"
                            @click="mark('accepted')"
                        >
                            Aceite
                        </button>
                        <button
                            v-if="quote && quote.status === 'sent'"
                            type="button"
                            class="rounded-xl px-3 py-2 text-sm font-semibold text-rose-700 ring-1 ring-rose-300 focus-ring transition hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-400/30 dark:hover:bg-rose-400/10"
                            @click="mark('rejected')"
                        >
                            Recusado
                        </button>

                        <button
                            v-if="quote?.can_convert"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                            @click="convert"
                        >
                            <Receipt class="size-4" aria-hidden="true" />
                            Passar a factura
                        </button>
                    </div>
                </header>

                <p
                    v-if="quote?.converted_document"
                    class="rounded-2xl bg-emerald-50 p-4 text-sm/6 text-emerald-900 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-200 dark:ring-emerald-400/20"
                >
                    Este orçamento já deu origem a um rascunho de factura.
                    Reveja e emita a partir de Facturação.
                </p>

                <form class="space-y-6" @submit.prevent="submit">
                    <fieldset :disabled="readOnly" class="space-y-6">
                        <section class="rounded-2xl surface p-5">
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                Cliente e validade
                            </h2>

                            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >Estabelecimento</label
                                    >
                                    <div class="mt-2">
                                        <SelectInput
                                            v-model="
                                                form.establishment_public_id
                                            "
                                            :options="establishments"
                                        />
                                    </div>
                                    <FormError
                                        :message="
                                            form.errors.establishment_public_id
                                        "
                                    />
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >Cliente</label
                                    >
                                    <div class="mt-2">
                                        <SelectInput
                                            v-model="form.customer_public_id"
                                            :options="customerOptions"
                                            @update:model-value="selectCustomer"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label
                                        for="quote-customer-name"
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >Nome no orçamento</label
                                    >
                                    <input
                                        id="quote-customer-name"
                                        v-model="form.customer.name"
                                        type="text"
                                        class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                    />
                                    <FormError
                                        :message="form.errors['customer.name']"
                                    />
                                </div>

                                <div>
                                    <label
                                        for="quote-customer-nif"
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >NIF
                                        <span class="text-zinc-400"
                                            >(opcional)</span
                                        ></label
                                    >
                                    <input
                                        id="quote-customer-nif"
                                        v-model="
                                            form.customer
                                                .tax_identification_number
                                        "
                                        type="text"
                                        class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                    />
                                </div>

                                <div>
                                    <label
                                        for="quote-issue"
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >Data</label
                                    >
                                    <DateInput
                                        id="quote-issue"
                                        v-model="form.issue_date"
                                        class="mt-2"
                                        :clearable="false"
                                        aria-label="Data do orçamento"
                                    />
                                    <FormError
                                        :message="form.errors.issue_date"
                                    />
                                </div>

                                <div>
                                    <label
                                        for="quote-valid"
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >Válido até</label
                                    >
                                    <DateInput
                                        id="quote-valid"
                                        v-model="form.valid_until"
                                        class="mt-2"
                                        :clearable="false"
                                        :min-date="form.issue_date"
                                        aria-label="Válido até"
                                    />
                                    <FormError
                                        :message="form.errors.valid_until"
                                    />
                                </div>
                            </div>
                        </section>

                        <section class="overflow-hidden rounded-2xl surface">
                            <header
                                class="flex items-center justify-between border-b border-zinc-100 p-5 dark:border-white/10"
                            >
                                <h2
                                    class="text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    Linhas
                                </h2>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                    @click="addLine"
                                >
                                    <Plus class="size-4" aria-hidden="true" />
                                    Linha
                                </button>
                            </header>

                            <ul
                                class="divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <li
                                    v-for="(line, index) in form.lines"
                                    :key="index"
                                    class="grid gap-4 p-5 sm:grid-cols-12"
                                >
                                    <div class="sm:col-span-12 lg:col-span-4">
                                        <label
                                            class="block text-xs font-medium text-zinc-500 dark:text-zinc-400"
                                            >Artigo</label
                                        >
                                        <div class="mt-1.5">
                                            <SelectInput
                                                :model-value="
                                                    catalogueItemValue(line)
                                                "
                                                :options="catalogueOptions"
                                                @update:model-value="
                                                    (value) =>
                                                        applyCatalogueItem(
                                                            line,
                                                            String(value),
                                                        )
                                                "
                                            />
                                        </div>
                                        <input
                                            v-model="line.product_description"
                                            type="text"
                                            placeholder="Descrição"
                                            class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                        />
                                        <FormError
                                            :message="
                                                form.errors[
                                                    `lines.${index}.product_description`
                                                ]
                                            "
                                        />
                                    </div>

                                    <div class="sm:col-span-3 lg:col-span-1">
                                        <label
                                            class="block text-xs font-medium text-zinc-500 dark:text-zinc-400"
                                            >Quantidade</label
                                        >
                                        <input
                                            v-model="line.quantity"
                                            type="text"
                                            inputmode="decimal"
                                            class="mt-1.5 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                        />
                                        <FormError
                                            :message="
                                                lineError(index, 'quantity')
                                            "
                                        />
                                    </div>

                                    <div class="sm:col-span-3 lg:col-span-2">
                                        <label
                                            class="block text-xs font-medium text-zinc-500 dark:text-zinc-400"
                                            >Preço</label
                                        >
                                        <input
                                            v-model="line.unit_price"
                                            type="text"
                                            inputmode="decimal"
                                            class="mt-1.5 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                        />
                                        <FormError
                                            :message="
                                                lineError(index, 'unit_price')
                                            "
                                        />
                                    </div>

                                    <div class="sm:col-span-3 lg:col-span-1">
                                        <label
                                            class="block text-xs font-medium text-zinc-500 dark:text-zinc-400"
                                            >Desconto %</label
                                        >
                                        <input
                                            v-model="line.discount_rate"
                                            type="text"
                                            inputmode="decimal"
                                            class="mt-1.5 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                        />
                                        <FormError
                                            :message="
                                                lineError(
                                                    index,
                                                    'discount_rate',
                                                )
                                            "
                                        />
                                    </div>

                                    <div class="sm:col-span-6 lg:col-span-2">
                                        <label
                                            class="block text-xs font-medium text-zinc-500 dark:text-zinc-400"
                                            >Tratamento fiscal</label
                                        >
                                        <div class="mt-1.5">
                                            <SelectInput
                                                :model-value="
                                                    taxTreatmentValue(line)
                                                "
                                                :options="taxTreatmentOptions"
                                                @update:model-value="
                                                    (value) =>
                                                        applyTaxTreatment(
                                                            line,
                                                            String(value),
                                                        )
                                                "
                                            />
                                        </div>
                                        <FormError
                                            :message="
                                                lineError(
                                                    index,
                                                    'tax_percentage',
                                                )
                                            "
                                        />
                                    </div>

                                    <div
                                        class="flex items-end justify-between gap-3 sm:col-span-12 lg:col-span-2"
                                    >
                                        <div>
                                            <span
                                                class="block text-xs text-zinc-500 dark:text-zinc-400"
                                                >Total</span
                                            >
                                            <span
                                                class="numeric text-sm font-semibold text-zinc-950 dark:text-white"
                                                >{{
                                                    money(
                                                        lineAmounts(line).gross,
                                                    )
                                                }}</span
                                            >
                                            <span
                                                v-if="
                                                    lineAmounts(line).discount >
                                                    0n
                                                "
                                                class="mt-1 block numeric text-xs text-emerald-700 dark:text-emerald-300"
                                            >
                                                −{{
                                                    money(
                                                        lineAmounts(line)
                                                            .discount,
                                                    )
                                                }}
                                            </span>
                                        </div>
                                        <button
                                            type="button"
                                            class="icon-button text-zinc-400 focus-ring transition hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-400/10 dark:hover:text-rose-400"
                                            @click="removeLine(index)"
                                        >
                                            <span class="sr-only"
                                                >Remover linha</span
                                            >
                                            <Trash2
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </div>
                                </li>
                            </ul>

                            <div
                                class="flex justify-end border-t border-zinc-100 bg-stone-50/70 p-5 dark:border-white/10 dark:bg-white/[0.02]"
                            >
                                <dl class="w-full max-w-sm space-y-2 text-sm">
                                    <div class="flex justify-between gap-6">
                                        <dt
                                            class="text-zinc-500 dark:text-zinc-400"
                                        >
                                            Subtotal
                                        </dt>
                                        <dd
                                            class="numeric text-zinc-700 dark:text-zinc-200"
                                        >
                                            {{ money(quoteTotals.base) }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between gap-6">
                                        <dt
                                            class="text-zinc-500 dark:text-zinc-400"
                                        >
                                            Descontos
                                        </dt>
                                        <dd
                                            class="numeric text-emerald-700 dark:text-emerald-300"
                                        >
                                            −{{ money(quoteTotals.discount) }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between gap-6">
                                        <dt
                                            class="text-zinc-500 dark:text-zinc-400"
                                        >
                                            Líquido
                                        </dt>
                                        <dd
                                            class="numeric text-zinc-700 dark:text-zinc-200"
                                        >
                                            {{ money(quoteTotals.net) }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between gap-6">
                                        <dt
                                            class="text-zinc-500 dark:text-zinc-400"
                                        >
                                            IVA
                                        </dt>
                                        <dd
                                            class="numeric text-zinc-700 dark:text-zinc-200"
                                        >
                                            {{ money(quoteTotals.tax) }}
                                        </dd>
                                    </div>
                                    <div
                                        class="flex justify-between gap-6 border-t border-zinc-200 pt-3 dark:border-white/10"
                                    >
                                        <dt
                                            class="font-semibold text-zinc-950 dark:text-white"
                                        >
                                            Total do orçamento
                                        </dt>
                                        <dd
                                            class="numeric text-xl font-semibold text-zinc-950 dark:text-white"
                                        >
                                            {{ money(quoteTotals.gross) }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </section>

                        <section class="rounded-2xl surface p-5">
                            <label
                                for="quote-notes"
                                class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                >Notas
                                <span class="text-zinc-400"
                                    >(aparecem no orçamento)</span
                                ></label
                            >
                            <textarea
                                id="quote-notes"
                                v-model="form.notes"
                                rows="3"
                                class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />
                        </section>
                    </fieldset>

                    <div v-if="!readOnly" class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:opacity-60 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                        >
                            <LoaderCircle
                                v-if="form.processing"
                                class="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            {{
                                quote ? 'Guardar alterações' : 'Criar orçamento'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
