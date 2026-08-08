<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Calculator,
    Check,
    CircleAlert,
    FilePenLine,
    Info,
    ListChecks,
    LoaderCircle,
    LockKeyhole,
    Plus,
    ReceiptText,
    Save,
    Send,
    ShieldCheck,
    Trash2,
    TriangleAlert,
    UserRound,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DateInput from '@/components/DateInput.vue';
import FormError from '@/components/FormError.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as agtConnection } from '@/routes/agt/connection';
import { issue, store, update } from '@/routes/invoices';
import { security } from '@/routes/settings';
import type { SelectOption as ListboxOption } from '@/types/select';

interface Company {
    legal_name: string;
    trade_name: string | null;
    tax_identification_number: string | null;
    currency_code: string;
}

interface Establishment {
    public_id: string;
    name: string;
    code: string;
    address_line: string;
    is_head_office: boolean;
}

interface DocumentTypeOption {
    value: string;
    label: string;
    is_adjustment: boolean;
    is_feminine: boolean;
    is_receipt: boolean;
    settles_other_documents: boolean;
    requires_lines: boolean;
}

interface SettlementRow {
    document_public_id: string;
    document_no: string;
    amount: string;
}

interface AdjustableDocument {
    public_id: string;
    document_no: string;
    document_date: string;
    customer_name: string;
    customer_public_id: string | null;
    gross_total_minor: number;
    outstanding_minor: number;
}

interface CatalogueItem {
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

interface Customer {
    public_id: string;
    name: string;
    tax_identification_number: string;
    country_code: string;
    address_line: string | null;
    payment_terms_days: number;
    agreed_prices: Record<string, string>;
    withholding: WithholdingRow | null;
}

interface WithholdingRow {
    type: string;
    rate_percentage: string;
}

interface WithholdingTypeOption {
    value: string;
    label: string;
    tax: string;
    suggested_rate: string;
}

interface SelectOption {
    value: string;
    label: string;
}

interface TaxTreatment extends SelectOption {
    type: string;
    code: string | null;
    percentage: string;
    exemption_code: string | null;
}

interface DocumentLine {
    operation_type: string;
    product_code: string;
    product_description: string;
    quantity: string;
    unit_of_measure: string;
    unit_price: string;
    discount_percentage: string;
    tax_treatment: string;
}

interface EditableLine extends DocumentLine {
    key: string;
    catalogue_public_id: string;
}

interface DraftDocument {
    public_id: string | null;
    revision: number;
    status: string;
    document_no: string | null;
    document_type: string;
    document_date: string;
    due_date: string | null;
    currency_code: string;
    exchange_rate: string;
    withholdings: WithholdingRow[];
    establishment_public_id: string | null;
    customer_public_id: string | null;
    customer: {
        name: string;
        tax_identification_number: string;
        country_code: string;
        address_line: string;
    };
    notes: string;
    references_document_public_id: string | null;
    references_document_no: string | null;
    adjustment_reason: string;
    payment_method: string;
    payment_date: string;
    settlements: SettlementRow[];
    lines: DocumentLine[];
    totals: {
        settlement: string;
        net: string;
        tax: string;
        gross: string;
    };
}

interface FiscalSeries {
    public_id: string;
    series_code: string;
    series_year: number;
    document_type: string;
    document_type_label: string;
    establishment_public_id: string;
    next_number: number;
    remaining_numbers: number;
}

const props = defineProps<{
    company: Company;
    establishments: Establishment[];
    customers: Customer[];
    catalogueItems: CatalogueItem[];
    documentTypes: DocumentTypeOption[];
    adjustableDocuments: AdjustableDocument[];
    paymentMethods: { value: string; label: string }[];
    currencies: string[];
    withholdingTypes: WithholdingTypeOption[];
    operationTypes: SelectOption[];
    taxTreatments: TaxTreatment[];
    eligibleSeries: FiscalSeries[];
    document: DraftDocument;
    permissions: { issue: boolean };
    guardrails: {
        draft_only: boolean;
        schema_version: string;
        number_assigned: boolean;
        mfa_enabled: boolean;
    };
}>();

const today = new Date().toLocaleDateString('sv-SE');

const establishmentOptions = computed<ListboxOption[]>(() =>
    props.establishments.map((establishment) => ({
        value: establishment.public_id,
        label: establishment.name,
        hint: establishment.code,
    })),
);

const customerOptions = computed<ListboxOption[]>(() => [
    { value: '', label: 'Novo cliente / consumidor' },
    ...props.customers.map((customer) => ({
        value: customer.public_id,
        label: customer.name,
        hint: `NIF ${customer.tax_identification_number}`,
    })),
]);

const documentTypeOptions = computed<ListboxOption[]>(() =>
    props.documentTypes.map((type) => ({
        value: type.value,
        label: `${type.value} · ${type.label}`,
    })),
);

const isAdjustment = computed(
    () =>
        props.documentTypes.find((type) => type.value === form.document_type)
            ?.is_adjustment ?? false,
);

const documentTypeLabel = computed(
    () =>
        props.documentTypes.find((type) => type.value === form.document_type)
            ?.label ?? 'Factura',
);

const currentType = computed(() =>
    props.documentTypes.find((type) => type.value === form.document_type),
);

const isReceipt = computed(() => currentType.value?.is_receipt ?? false);
const settlesOtherDocuments = computed(
    () => currentType.value?.settles_other_documents ?? false,
);
const requiresLines = computed(() => currentType.value?.requires_lines ?? true);

const paymentMethodOptions = computed<ListboxOption[]>(() =>
    props.paymentMethods.map((method) => ({
        value: method.value,
        label: method.label,
    })),
);

/** Invoices still owing money, excluding any this receipt already lists. */
const settleableOptions = computed<ListboxOption[]>(() => {
    const taken = new Set(
        form.settlements.map((row) => row.document_public_id).filter(Boolean),
    );

    return props.adjustableDocuments
        .filter(
            (issued) =>
                issued.outstanding_minor > 0 &&
                !taken.has(issued.public_id) &&
                (form.customer_public_id === '' ||
                    issued.customer_public_id === form.customer_public_id),
        )
        .map((issued) => ({
            value: issued.public_id,
            label: issued.document_no,
            hint: `${issued.customer_name} · falta ${formatMoney(BigInt(issued.outstanding_minor))}`,
        }));
});

const settlementTotal = computed(() =>
    form.settlements.reduce((total, row) => {
        const amount = Number.parseFloat(row.amount.replace(',', '.'));

        return total + (Number.isFinite(amount) ? Math.round(amount * 100) : 0);
    }, 0),
);

function addSettlement(): void {
    form.settlements.push({
        document_public_id: '',
        document_no: '',
        amount: '0.00',
    });
}

function removeSettlement(index: number): void {
    form.settlements.splice(index, 1);
}

/** Every invoice this customer still owes on, allocated at its full balance. */
const outstandingForCustomer = computed(() =>
    props.adjustableDocuments.filter(
        (issued) =>
            issued.outstanding_minor > 0 &&
            form.customer_public_id !== '' &&
            issued.customer_public_id === form.customer_public_id,
    ),
);

/**
 * Fills the receipt with everything this customer owes.
 *
 * The common case for a standalone receipt is a customer paying off several
 * invoices at once; doing that a row at a time is the kind of manual work that
 * produces allocation mistakes.
 */
function settleEverythingOutstanding(): void {
    form.settlements = outstandingForCustomer.value.map((issued) => ({
        document_public_id: issued.public_id,
        document_no: issued.document_no,
        amount: (issued.outstanding_minor / 100).toFixed(2),
    }));
}

/** Default the amount to whatever the chosen invoice still owes. */
function applySettlementDocument(index: number, publicId: string): void {
    const issued = props.adjustableDocuments.find(
        (candidate) => candidate.public_id === publicId,
    );
    const row = form.settlements[index];

    if (issued === undefined || row === undefined) {
        return;
    }

    row.document_no = issued.document_no;
    row.amount = (issued.outstanding_minor / 100).toFixed(2);

    if (issued.customer_public_id !== null) {
        form.customer_public_id = issued.customer_public_id;
        selectCustomer();
    }
}

/** «Nova factura» / «Nova nota de crédito», but «Novo recibo». */
const newDocumentLabel = computed(() => {
    const type = props.documentTypes.find(
        (candidate) => candidate.value === form.document_type,
    );

    return `${type?.is_feminine === false ? 'Novo' : 'Nova'} ${(
        type?.label ?? 'factura'
    ).toLowerCase()}`;
});

const adjustableOptions = computed<ListboxOption[]>(() =>
    props.adjustableDocuments.map((issued) => ({
        value: issued.public_id,
        label: issued.document_no,
        hint: `${issued.customer_name} · ${formatMoney(BigInt(issued.gross_total_minor))}`,
    })),
);

/**
 * Correcting a document means invoicing the same client, so adopt its customer
 * snapshot when one is chosen.
 */
function applyReferencedDocument(publicId: string): void {
    const issued = props.adjustableDocuments.find(
        (candidate) => candidate.public_id === publicId,
    );

    if (issued === undefined || issued.customer_public_id === null) {
        return;
    }

    form.customer_public_id = issued.customer_public_id;
    selectCustomer();
}

/**
 * The hint quotes the price the line will actually take, which for a customer
 * with an agreed price is not the one on the tabela.
 */
const catalogueOptions = computed<ListboxOption[]>(() => [
    { value: '', label: 'Escrever manualmente' },
    ...props.catalogueItems.map((item) => {
        const agreed = selectedCustomer.value?.agreed_prices[item.public_id];

        return {
            value: item.public_id,
            label: item.name,
            hint: `${item.code} · ${agreed ?? item.unit_price} ${props.company.currency_code}${agreed ? ' (acordado)' : ''}`,
        };
    }),
]);

/**
 * Picking a cataloged article fills the line from the register, so the price
 * and the IVA come from one place instead of being retyped per invoice.
 */
function applyCatalogueItem(line: EditableLine, publicId: string): void {
    const item = props.catalogueItems.find(
        (candidate) => candidate.public_id === publicId,
    );

    if (item === undefined) {
        return;
    }

    line.product_code = item.code;
    line.product_description = item.description ?? item.name;
    line.unit_of_measure = item.unit_of_measure;
    // What was agreed with this customer wins over the catalogue's list price.
    line.unit_price =
        selectedCustomer.value?.agreed_prices[item.public_id] ??
        item.unit_price;
    line.tax_treatment =
        props.taxTreatments.find(
            (treatment) =>
                treatment.percentage === item.tax_percentage &&
                treatment.type === item.tax_type,
        )?.value ?? line.tax_treatment;
}

const operationTypeOptions = computed<ListboxOption[]>(() =>
    props.operationTypes.map((option) => ({
        value: option.value,
        label: `${option.value} · ${option.label}`,
    })),
);

const taxTreatmentOptions = computed<ListboxOption[]>(() =>
    props.taxTreatments.map((treatment) => ({
        value: treatment.value,
        label: treatment.label,
    })),
);

const page = usePage();
const nextLineKey = ref(0);
const flashSuccess = computed(() => page.props.flash.success);
const flashError = computed(() => page.props.flash.error);

function editableLine(line?: DocumentLine): EditableLine {
    nextLineKey.value += 1;

    return {
        key: `line-${nextLineKey.value}`,
        catalogue_public_id: '',
        operation_type: line?.operation_type ?? 'TB',
        product_code: line?.product_code ?? '',
        product_description: line?.product_description ?? '',
        quantity: line?.quantity ?? '1',
        unit_of_measure: line?.unit_of_measure ?? 'un',
        unit_price: line?.unit_price ?? '0',
        discount_percentage: line?.discount_percentage ?? '0',
        tax_treatment: line?.tax_treatment ?? 'IVA_NOR_14',
    };
}

const form = useForm({
    document_type: props.document.document_type,
    document_date: props.document.document_date,
    due_date: props.document.due_date ?? '',
    currency_code: props.document.currency_code,
    exchange_rate: props.document.exchange_rate,
    withholdings: props.document.withholdings.map((row) => ({ ...row })),
    establishment_public_id:
        props.document.establishment_public_id ??
        props.establishments[0]?.public_id ??
        '',
    customer_public_id: props.document.customer_public_id ?? '',
    customer: { ...props.document.customer },
    notes: props.document.notes,
    references_document_public_id:
        props.document.references_document_public_id ?? '',
    adjustment_reason: props.document.adjustment_reason ?? '',
    payment_method: props.document.payment_method,
    payment_date: props.document.payment_date,
    settlements: props.document.settlements.map((row) => ({ ...row })),
    lines: props.document.lines.map((line) => editableLine(line)),
});
const issueDialogOpen = ref(false);
const issueForm = useForm({
    revision: props.document.revision,
    series_public_id: '',
});

const hasErrors = computed(() => Object.keys(form.errors).length > 0);
const selectedCustomer = computed(() =>
    props.customers.find(
        (customer) => customer.public_id === form.customer_public_id,
    ),
);
const compatibleSeries = computed(() => {
    const year = Number(form.document_date.slice(0, 4));

    return props.eligibleSeries.filter(
        (series) =>
            series.establishment_public_id === form.establishment_public_id &&
            series.document_type === form.document_type &&
            series.series_year === year,
    );
});
const seriesOptions = computed<ListboxOption[]>(() =>
    compatibleSeries.value.map((series) => ({
        value: series.public_id,
        label: series.series_code,
        hint: `Próximo ${series.next_number} · ${series.remaining_numbers} disponíveis`,
    })),
);

const selectedFiscalSeries = computed(() =>
    compatibleSeries.value.find(
        (series) => series.public_id === issueForm.series_public_id,
    ),
);
const canOpenIssue = computed(
    () =>
        props.document.public_id !== null &&
        props.permissions.issue &&
        props.guardrails.mfa_enabled &&
        compatibleSeries.value.length > 0 &&
        issueForm.series_public_id !== '' &&
        !form.isDirty &&
        !form.processing,
);
const issueBlockReason = computed(() => {
    if (props.document.public_id === null) {
        return 'Guarde primeiro o rascunho.';
    }

    if (!props.permissions.issue) {
        return 'O seu papel não permite emitir este documento.';
    }

    if (!props.guardrails.mfa_enabled) {
        return 'Active a autenticação multifactor para emitir.';
    }

    if (compatibleSeries.value.length === 0) {
        return 'Não existe uma série AGT compatível com o local, tipo e ano.';
    }

    if (form.isDirty) {
        return 'Guarde as alterações antes de emitir.';
    }

    return null;
});

watch(
    compatibleSeries,
    (series) => {
        if (
            !series.some(
                (candidate) =>
                    candidate.public_id === issueForm.series_public_id,
            )
        ) {
            issueForm.series_public_id = series[0]?.public_id ?? '';
        }
    },
    { immediate: true },
);

watch(
    () => props.document.revision,
    (revision) => {
        issueForm.revision = revision;
    },
);

interface LineCalculation {
    base: bigint;
    settlement: bigint;
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

function calculateLine(line: EditableLine): LineCalculation {
    const quantity = parseScaled(line.quantity, 4) ?? 0n;
    const unitPrice = parseScaled(line.unit_price, 2) ?? 0n;
    const discount = parseScaled(line.discount_percentage, 2) ?? 0n;
    const treatment = props.taxTreatments.find(
        (option) => option.value === line.tax_treatment,
    );
    const taxRate = parseScaled(treatment?.percentage ?? '0', 2) ?? 0n;
    const boundedDiscount = discount > 10_000n ? 10_000n : discount;
    const base = roundHalfUp(unitPrice * quantity, 10_000n);
    const net = roundHalfUp(
        unitPrice * (10_000n - boundedDiscount) * quantity,
        100_000_000n,
    );
    const settlement = base - net;
    const tax = roundUp(net * taxRate, 10_000n);

    return {
        base,
        settlement,
        net,
        tax,
        gross: net + tax,
    };
}

const lineCalculations = computed(() => form.lines.map(calculateLine));
const totals = computed(() =>
    lineCalculations.value.reduce(
        (total, line) => ({
            settlement: total.settlement + line.settlement,
            net: total.net + line.net,
            tax: total.tax + line.tax,
            gross: total.gross + line.gross,
        }),
        { settlement: 0n, net: 0n, tax: 0n, gross: 0n },
    ),
);

const currencyOptions = computed<ListboxOption[]>(() =>
    props.currencies.map((code) => ({ value: code, label: code })),
);
const withholdingTypeOptions = computed<ListboxOption[]>(() =>
    props.withholdingTypes.map((option) => ({
        value: option.value,
        label: option.label,
    })),
);
const isForeignCurrency = computed(() => form.currency_code !== 'AOA');

/**
 * What each withholding comes to, shown as the rate is typed.
 *
 * Mirrors the server's rule rather than guessing at it: captive VAT is charged
 * on the tax, retention on the value of the supply. The server still works out
 * the figure that is stored — this only saves the issuer from finding out what
 * the buyer will keep back after the document is saved.
 */
const withholdingPreview = computed(() =>
    form.withholdings.map((row) => {
        const rate = parseScaled(row.rate_percentage, 2) ?? 0n;
        const base =
            row.type === 'IVA-CATIVO' ? totals.value.tax : totals.value.net;

        return {
            base,
            amount: roundHalfUp(base * rate, 10_000n),
        };
    }),
);
const withholdingTotal = computed(() =>
    withholdingPreview.value.reduce((total, row) => total + row.amount, 0n),
);
const grossInKwanzas = computed(() =>
    roundHalfUp(
        totals.value.gross * (parseScaled(form.exchange_rate, 6) ?? 0n),
        1_000_000n,
    ),
);

const integerFormatter = new Intl.NumberFormat('pt-AO', {
    maximumFractionDigits: 0,
});
const decimalSeparator =
    new Intl.NumberFormat('pt-AO')
        .formatToParts(1.1)
        .find((part) => part.type === 'decimal')?.value ?? ',';

function formatMoney(minorUnits: bigint): string {
    const whole = minorUnits / 100n;
    const fraction = (minorUnits % 100n).toString().padStart(2, '0');

    return `${integerFormatter.format(whole)}${decimalSeparator}${fraction} Kz`;
}

function errorFor(path: string): string | undefined {
    return (form.errors as Record<string, string>)[path];
}

function selectCustomer(): void {
    const customer = selectedCustomer.value;

    if (customer === undefined) {
        form.customer = {
            name: '',
            tax_identification_number: '',
            country_code: 'AO',
            address_line: '',
        };

        return;
    }

    form.customer = {
        name: customer.name,
        tax_identification_number: customer.tax_identification_number,
        country_code: customer.country_code,
        address_line: customer.address_line ?? '',
    };

    applyPaymentTerms(customer.payment_terms_days);
    applyWithholding(customer.withholding);
}

/**
 * Offers this buyer's usual withholding, without overruling the document.
 *
 * Only fills an empty list. Once someone has set the retention on this document
 * by hand, changing the customer must not quietly undo it — the figure on a
 * fiscal document is the issuer's statement, not a lookup.
 */
function applyWithholding(withholding: WithholdingRow | null): void {
    if (withholding === null || form.withholdings.length > 0) {
        return;
    }

    form.withholdings = [{ ...withholding }];
}

function addWithholding(): void {
    const used = new Set(form.withholdings.map((row) => row.type));
    const next = props.withholdingTypes.find(
        (option) => !used.has(option.value),
    );

    if (next === undefined) {
        return;
    }

    form.withholdings.push({
        type: next.value,
        rate_percentage: next.suggested_rate,
    });
}

function removeWithholding(index: number): void {
    form.withholdings.splice(index, 1);
}

/**
 * Moves the rate to the one customary for the tax just chosen.
 *
 * Only when the row still holds the previous tax's suggestion: a rate someone
 * typed is theirs to keep.
 */
function changeWithholdingType(index: number, value: string): void {
    const row = form.withholdings[index];

    if (row === undefined) {
        return;
    }

    const previous = props.withholdingTypes.find(
        (option) => option.value === row.type,
    );
    const next = props.withholdingTypes.find(
        (option) => option.value === value,
    );

    if (
        next !== undefined &&
        row.rate_percentage === previous?.suggested_rate
    ) {
        row.rate_percentage = next.suggested_rate;
    }

    row.type = value;
}

/**
 * Sets the due date from what was agreed with this customer.
 *
 * Terms of zero mean payment on delivery, which is a due date of the issue day
 * itself — leaving it blank would make the document permanently un-overdue.
 */
function applyPaymentTerms(days: number): void {
    const issued = new Date(`${form.document_date}T00:00:00`);

    if (Number.isNaN(issued.getTime())) {
        return;
    }

    issued.setDate(issued.getDate() + days);
    form.due_date = issued.toISOString().slice(0, 10);
}

function useManualCustomer(): void {
    form.customer_public_id = '';
    selectCustomer();
}

function addLine(): void {
    form.lines.push(editableLine());
}

function removeLine(index: number): void {
    if (form.lines.length === 1) {
        return;
    }

    form.lines.splice(index, 1);
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        due_date: data.due_date || null,
        customer_public_id: data.customer_public_id || null,
        lines: data.lines.map((line) => {
            const treatment =
                props.taxTreatments.find(
                    (option) => option.value === line.tax_treatment,
                ) ?? props.taxTreatments[0];

            return {
                operation_type: line.operation_type,
                product_code: line.product_code,
                product_description: line.product_description,
                quantity: line.quantity,
                unit_of_measure: line.unit_of_measure,
                unit_price: line.unit_price,
                discount_percentage: line.discount_percentage,
                tax: {
                    type: treatment?.type ?? 'IVA',
                    code: treatment?.code ?? 'NOR',
                    percentage: treatment?.percentage ?? '14',
                    exemption_code: treatment?.exemption_code ?? null,
                },
            };
        }),
    })).submit(
        props.document.public_id === null
            ? store()
            : update(props.document.public_id),
        {
            preserveScroll: true,
            onSuccess: () => form.defaults(),
        },
    );
}

function openIssueDialog(): void {
    if (!canOpenIssue.value) {
        return;
    }

    issueForm.clearErrors();
    issueDialogOpen.value = true;
}

function closeIssueDialog(): void {
    if (!issueForm.processing) {
        issueDialogOpen.value = false;
    }
}

function confirmIssue(): void {
    if (props.document.public_id === null) {
        return;
    }

    issueForm.revision = props.document.revision;
    issueForm.submit(issue(props.document.public_id), {
        preserveScroll: true,
        onSuccess: () => {
            issueDialogOpen.value = false;
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head
            :title="
                document.public_id === null
                    ? newDocumentLabel
                    : `Editar ${documentTypeLabel.toLowerCase()}`
            "
        />

        <form
            class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10"
            @submit.prevent="submit"
        >
            <div class="mx-auto max-w-[100rem] space-y-7">
                <header
                    class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between"
                >
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge label="Rascunho" tone="draft" />
                            <StatusBadge
                                v-if="
                                    isAdjustment &&
                                    document.references_document_no
                                "
                                :label="`Corrige ${document.references_document_no}`"
                                tone="warning"
                            />
                            <StatusBadge
                                :label="`Contrato AGT ${guardrails.schema_version}`"
                                tone="info"
                            />
                            <span
                                v-if="document.revision > 0"
                                class="text-xs font-semibold text-zinc-500 dark:text-zinc-400"
                            >
                                Revisão {{ document.revision }}
                            </span>
                        </div>
                        <h1
                            class="mt-4 text-4xl display text-zinc-950 sm:text-5xl dark:text-white"
                        >
                            {{
                                document.public_id === null
                                    ? newDocumentLabel
                                    : `Editar ${documentTypeLabel.toLowerCase()}`
                            }}
                        </h1>
                        <p
                            class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            Prepare e confira os dados. Guardar não atribui
                            número, não assina e não envia nada à AGT.
                        </p>
                    </div>
                    <button
                        type="submit"
                        :disabled="
                            form.processing || establishments.length === 0
                        "
                        class="inline-flex w-fit items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-brand-500 dark:hover:bg-brand-400"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        <Save v-else class="size-4" aria-hidden="true" />
                        {{
                            form.processing ? 'A guardar…' : 'Guardar rascunho'
                        }}
                    </button>
                </header>

                <div
                    v-if="flashSuccess"
                    class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300"
                    role="status"
                >
                    <Check class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                    <div>
                        <p class="font-semibold">Rascunho seguro</p>
                        <p class="mt-0.5 opacity-80">{{ flashSuccess }}</p>
                    </div>
                </div>

                <div
                    v-if="flashError || hasErrors"
                    class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-400/20 dark:bg-rose-400/10 dark:text-rose-300"
                    role="alert"
                >
                    <div class="flex gap-3">
                        <CircleAlert
                            class="mt-0.5 size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <div>
                            <p class="font-semibold">
                                Revise os campos assinalados
                            </p>
                            <p class="mt-0.5 opacity-80">
                                {{
                                    flashError ??
                                    'O rascunho não foi guardado porque há dados incompletos ou incompatíveis.'
                                }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 xl:grid-cols-2">
                    <section class="overflow-hidden rounded-2xl surface">
                        <div
                            class="flex items-center gap-3 border-b border-zinc-100 px-5 py-5 sm:px-6 dark:border-white/10"
                        >
                            <span
                                class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                            >
                                <ReceiptText
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <div>
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Dados do documento
                                </h2>
                                <p
                                    class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ form.document_type }} ·
                                    {{ documentTypeLabel }} em Kwanza
                                </p>
                            </div>
                        </div>

                        <div
                            class="grid grid-cols-1 gap-6 p-5 sm:grid-cols-6 sm:p-6"
                        >
                            <div class="sm:col-span-3">
                                <label
                                    for="document-type"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Tipo de documento
                                </label>
                                <SelectInput
                                    id="document-type"
                                    v-model="form.document_type"
                                    class="mt-2"
                                    :options="documentTypeOptions"
                                />
                                <FormError
                                    :message="form.errors.document_type"
                                />
                            </div>

                            <div v-if="isAdjustment" class="sm:col-span-3">
                                <label
                                    for="references-document"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Documento corrigido
                                </label>
                                <p
                                    v-if="adjustableDocuments.length === 0"
                                    class="mt-2 rounded-xl bg-amber-50 p-3 text-xs/5 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                                >
                                    Ainda não há facturas emitidas para
                                    corrigir. Só é possível emitir uma nota
                                    depois de a factura original ter seguido
                                    para a AGT.
                                </p>
                                <SelectInput
                                    v-else
                                    id="references-document"
                                    v-model="form.references_document_public_id"
                                    class="mt-2"
                                    placeholder="Escolher a factura…"
                                    :options="adjustableOptions"
                                    @change="applyReferencedDocument($event)"
                                />
                                <FormError
                                    :message="
                                        form.errors
                                            .references_document_public_id
                                    "
                                />
                            </div>

                            <div v-if="isAdjustment" class="sm:col-span-6">
                                <label
                                    for="adjustment-reason"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Motivo da
                                    {{
                                        form.document_type === 'NC'
                                            ? 'nota de crédito'
                                            : 'nota de débito'
                                    }}
                                </label>
                                <input
                                    id="adjustment-reason"
                                    v-model="form.adjustment_reason"
                                    type="text"
                                    maxlength="200"
                                    placeholder="Ex.: devolução parcial da mercadoria"
                                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 placeholder:text-zinc-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:placeholder:text-zinc-500 dark:focus:outline-brand-400"
                                />
                                <p
                                    class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    A AGT exige um motivo em cada documento de
                                    correcção.
                                </p>
                                <FormError
                                    :message="form.errors.adjustment_reason"
                                />
                            </div>

                            <div v-if="isReceipt" class="sm:col-span-3">
                                <label
                                    for="payment-method"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Meio de pagamento
                                </label>
                                <SelectInput
                                    id="payment-method"
                                    v-model="form.payment_method"
                                    class="mt-2"
                                    :options="paymentMethodOptions"
                                />
                                <FormError
                                    :message="form.errors.payment_method"
                                />
                            </div>

                            <div v-if="isReceipt" class="sm:col-span-3">
                                <label
                                    for="payment-date"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Data do pagamento
                                </label>
                                <DateInput
                                    id="payment-date"
                                    v-model="form.payment_date"
                                    class="mt-2"
                                    :clearable="false"
                                    :max-date="today"
                                    aria-label="Data do pagamento"
                                />
                                <FormError
                                    :message="form.errors.payment_date"
                                />
                            </div>

                            <div class="sm:col-span-3">
                                <label
                                    for="establishment"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Local de emissão
                                </label>
                                <SelectInput
                                    id="establishment"
                                    v-model="form.establishment_public_id"
                                    class="mt-2"
                                    placeholder="Seleccione um local"
                                    :options="establishmentOptions"
                                />
                                <FormError
                                    :message="
                                        form.errors.establishment_public_id
                                    "
                                />
                            </div>

                            <div class="sm:col-span-3">
                                <label
                                    for="currency"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Moeda
                                </label>
                                <SelectInput
                                    id="currency"
                                    v-model="form.currency_code"
                                    class="mt-2"
                                    :options="currencyOptions"
                                />
                                <FormError
                                    :message="form.errors.currency_code"
                                />
                            </div>

                            <div v-if="isForeignCurrency" class="sm:col-span-3">
                                <label
                                    for="exchange-rate"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Câmbio
                                </label>
                                <input
                                    id="exchange-rate"
                                    v-model="form.exchange_rate"
                                    inputmode="decimal"
                                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 numeric text-sm text-zinc-900 focus-ring outline-1 -outline-offset-1 outline-zinc-200 dark:bg-white/[0.03] dark:text-white dark:outline-white/10"
                                />
                                <p
                                    class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Kwanzas por 1 {{ form.currency_code }}. É
                                    este o valor que a AGT recebe convertido.
                                </p>
                                <FormError
                                    :message="form.errors.exchange_rate"
                                />
                            </div>

                            <div class="sm:col-span-3">
                                <label
                                    for="document-date"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Data do documento
                                </label>
                                <DateInput
                                    id="document-date"
                                    v-model="form.document_date"
                                    class="mt-2"
                                    :clearable="false"
                                    :max-date="today"
                                    aria-label="Data do documento"
                                />
                                <FormError
                                    :message="form.errors.document_date"
                                />
                            </div>

                            <div class="sm:col-span-3">
                                <label
                                    for="due-date"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Data de vencimento
                                    <span class="font-normal text-zinc-400"
                                        >(opcional)</span
                                    >
                                </label>
                                <DateInput
                                    id="due-date"
                                    v-model="form.due_date"
                                    class="mt-2"
                                    :min-date="form.document_date"
                                    aria-label="Data de vencimento"
                                />
                                <FormError :message="form.errors.due_date" />
                            </div>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-2xl surface">
                        <div
                            class="flex flex-col gap-4 border-b border-zinc-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-white/10"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300"
                                >
                                    <UserRound
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <h2
                                        class="font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Cliente
                                    </h2>
                                    <p
                                        class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        O nome e o NIF ficam registados no
                                        rascunho.
                                    </p>
                                </div>
                            </div>
                            <button
                                v-if="selectedCustomer"
                                type="button"
                                class="text-sm font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                                @click="useManualCustomer"
                            >
                                Inserir outro cliente
                            </button>
                        </div>

                        <div
                            class="grid grid-cols-1 gap-6 p-5 sm:grid-cols-6 sm:p-6"
                        >
                            <div class="sm:col-span-6">
                                <label
                                    for="saved-customer"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Procurar nos clientes guardados
                                </label>
                                <SelectInput
                                    id="saved-customer"
                                    v-model="form.customer_public_id"
                                    class="mt-2"
                                    :options="customerOptions"
                                    @change="selectCustomer"
                                />
                                <FormError
                                    :message="form.errors.customer_public_id"
                                />
                            </div>

                            <div class="sm:col-span-4">
                                <label
                                    for="customer-name"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Nome ou denominação
                                </label>
                                <input
                                    id="customer-name"
                                    v-model="form.customer.name"
                                    :readonly="Boolean(selectedCustomer)"
                                    autocomplete="organization"
                                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 read-only:bg-zinc-50 read-only:text-zinc-600 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:read-only:bg-white/[0.03] dark:read-only:text-zinc-300 dark:focus:outline-brand-400"
                                />
                                <FormError
                                    :message="errorFor('customer.name')"
                                />
                            </div>

                            <div class="sm:col-span-2">
                                <label
                                    for="customer-nif"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    NIF
                                </label>
                                <input
                                    id="customer-nif"
                                    v-model="
                                        form.customer.tax_identification_number
                                    "
                                    :readonly="Boolean(selectedCustomer)"
                                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 read-only:bg-zinc-50 read-only:text-zinc-600 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:read-only:bg-white/[0.03] dark:read-only:text-zinc-300 dark:focus:outline-brand-400"
                                />
                                <FormError
                                    :message="
                                        errorFor(
                                            'customer.tax_identification_number',
                                        )
                                    "
                                />
                            </div>

                            <div class="sm:col-span-2">
                                <label
                                    for="customer-country"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    País
                                </label>
                                <input
                                    id="customer-country"
                                    v-model="form.customer.country_code"
                                    :readonly="Boolean(selectedCustomer)"
                                    maxlength="2"
                                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 read-only:bg-zinc-50 read-only:text-zinc-600 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:read-only:bg-white/[0.03] dark:read-only:text-zinc-300 dark:focus:outline-brand-400"
                                />
                                <FormError
                                    :message="errorFor('customer.country_code')"
                                />
                            </div>

                            <div class="sm:col-span-4">
                                <label
                                    for="customer-address"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Morada
                                </label>
                                <input
                                    id="customer-address"
                                    v-model="form.customer.address_line"
                                    :readonly="Boolean(selectedCustomer)"
                                    autocomplete="street-address"
                                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 read-only:bg-zinc-50 read-only:text-zinc-600 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:read-only:bg-white/[0.03] dark:read-only:text-zinc-300 dark:focus:outline-brand-400"
                                />
                                <FormError
                                    :message="errorFor('customer.address_line')"
                                />
                            </div>
                        </div>
                    </section>
                </div>

                <!-- A standalone receipt carries no goods: it pays off invoices. -->
                <section
                    v-if="settlesOtherDocuments"
                    class="overflow-hidden rounded-2xl surface"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-100 px-5 py-5 sm:px-6 dark:border-white/10"
                    >
                        <div>
                            <h2
                                class="font-semibold text-zinc-950 dark:text-white"
                            >
                                Facturas liquidadas
                            </h2>
                            <p
                                class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                Escolha o que este recibo paga. O valor não pode
                                exceder o que ainda falta em cada factura.
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <button
                                v-if="outstandingForCustomer.length > 1"
                                type="button"
                                class="inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                @click="settleEverythingOutstanding"
                            >
                                <ListChecks class="size-4" aria-hidden="true" />
                                Liquidar tudo ({{
                                    outstandingForCustomer.length
                                }})
                            </button>
                            <button
                                type="button"
                                :disabled="settleableOptions.length === 0"
                                class="inline-flex items-center gap-2 rounded-xl bg-zinc-950 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200"
                                @click="addSettlement"
                            >
                                <Plus class="size-4" aria-hidden="true" />
                                Adicionar factura
                            </button>
                        </div>
                    </div>

                    <p
                        v-if="form.settlements.length === 0"
                        class="px-5 py-10 text-center text-sm/6 text-zinc-500 sm:px-6 dark:text-zinc-400"
                    >
                        {{
                            adjustableDocuments.length === 0
                                ? 'Ainda não há facturas emitidas para liquidar.'
                                : 'Nenhuma factura escolhida. Adicione a primeira para começar.'
                        }}
                    </p>

                    <ul
                        v-else
                        role="list"
                        class="divide-y divide-zinc-100 dark:divide-white/10"
                    >
                        <li
                            v-for="(row, index) in form.settlements"
                            :key="index"
                            class="grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end sm:px-6"
                        >
                            <div>
                                <label
                                    :for="`settlement-doc-${index}`"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Factura
                                </label>
                                <SelectInput
                                    :id="`settlement-doc-${index}`"
                                    v-model="row.document_public_id"
                                    class="mt-2"
                                    placeholder="Escolher a factura…"
                                    :options="settleableOptions"
                                    @change="
                                        applySettlementDocument(index, $event)
                                    "
                                />
                                <FormError
                                    :message="
                                        errorFor(
                                            `settlements.${index}.document_public_id`,
                                        )
                                    "
                                />
                            </div>

                            <div>
                                <label
                                    :for="`settlement-amount-${index}`"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Valor liquidado
                                </label>
                                <input
                                    :id="`settlement-amount-${index}`"
                                    v-model="row.amount"
                                    type="text"
                                    inputmode="decimal"
                                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-right font-mono numeric text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                />
                                <FormError
                                    :message="
                                        errorFor(`settlements.${index}.amount`)
                                    "
                                />
                            </div>

                            <button
                                type="button"
                                class="justify-self-start rounded-xl p-2.5 text-zinc-500 focus-ring transition hover:bg-rose-50 hover:text-rose-700 sm:justify-self-auto dark:hover:bg-rose-400/10 dark:hover:text-rose-400"
                                @click="removeSettlement(index)"
                            >
                                <span class="sr-only"
                                    >Remover factura {{ index + 1 }}</span
                                >
                                <Trash2 class="size-4" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>

                    <div
                        v-if="form.settlements.length > 0"
                        class="flex items-center justify-between border-t border-zinc-100 px-5 py-4 sm:px-6 dark:border-white/10"
                    >
                        <span
                            class="text-sm font-medium text-zinc-600 dark:text-zinc-300"
                            >Total do recibo</span
                        >
                        <span
                            class="numeric text-lg font-semibold text-zinc-950 dark:text-white"
                            >{{ formatMoney(BigInt(settlementTotal)) }}</span
                        >
                    </div>
                    <FormError :message="form.errors.settlements" />
                </section>

                <!--
                    Optional, and empty for almost every document: only some
                    buyers — the State, large taxpayers, banks — keep tax back
                    and pay it to the AGT themselves.
                -->
                <section
                    v-if="requiresLines"
                    class="overflow-hidden rounded-2xl surface"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-100 px-5 py-5 sm:px-6 dark:border-white/10"
                    >
                        <div>
                            <h2
                                class="font-semibold text-zinc-950 dark:text-white"
                            >
                                Retenção na fonte e cativação
                            </h2>
                            <p
                                class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                Imposto que o adquirente retém e entrega
                                directamente à AGT. Informativo: não altera o
                                total do documento.
                            </p>
                        </div>
                        <button
                            type="button"
                            :disabled="
                                form.withholdings.length >=
                                withholdingTypes.length
                            "
                            class="inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                            @click="addWithholding"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Adicionar retenção
                        </button>
                    </div>

                    <p
                        v-if="form.withholdings.length === 0"
                        class="px-5 py-6 text-sm text-zinc-500 sm:px-6 dark:text-zinc-400"
                    >
                        Nada retido. A maioria dos clientes paga o total da
                        factura; adicione uma retenção só quando o adquirente a
                        praticar.
                    </p>

                    <ul
                        v-else
                        class="divide-y divide-zinc-100 dark:divide-white/10"
                    >
                        <li
                            v-for="(row, index) in form.withholdings"
                            :key="index"
                            class="grid gap-4 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_9rem_minmax(0,10rem)_auto] sm:items-end sm:px-6"
                        >
                            <div>
                                <label
                                    :for="`withholding-type-${index}`"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Imposto retido
                                </label>
                                <SelectInput
                                    :id="`withholding-type-${index}`"
                                    :model-value="row.type"
                                    class="mt-2"
                                    :options="withholdingTypeOptions"
                                    @update:model-value="
                                        (value: string) =>
                                            changeWithholdingType(index, value)
                                    "
                                />
                                <FormError
                                    :message="
                                        errorFor(`withholdings.${index}.type`)
                                    "
                                />
                            </div>

                            <div>
                                <label
                                    :for="`withholding-rate-${index}`"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Taxa (%)
                                </label>
                                <input
                                    :id="`withholding-rate-${index}`"
                                    v-model="row.rate_percentage"
                                    type="text"
                                    inputmode="decimal"
                                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-right font-mono numeric text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                />
                                <FormError
                                    :message="
                                        errorFor(
                                            `withholdings.${index}.rate_percentage`,
                                        )
                                    "
                                />
                            </div>

                            <div class="sm:text-right">
                                <span
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Valor retido
                                </span>
                                <span
                                    class="mt-2 block py-2.5 numeric text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{
                                        formatMoney(
                                            withholdingPreview[index]?.amount ??
                                                0n,
                                        )
                                    }}
                                </span>
                                <span
                                    class="block text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    sobre
                                    {{
                                        formatMoney(
                                            withholdingPreview[index]?.base ??
                                                0n,
                                        )
                                    }}
                                </span>
                            </div>

                            <button
                                type="button"
                                class="justify-self-start rounded-xl p-2.5 text-zinc-500 focus-ring transition hover:bg-rose-50 hover:text-rose-700 sm:justify-self-auto dark:hover:bg-rose-400/10 dark:hover:text-rose-400"
                                @click="removeWithholding(index)"
                            >
                                <span class="sr-only"
                                    >Remover retenção {{ index + 1 }}</span
                                >
                                <Trash2 class="size-4" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                    <FormError :message="form.errors.withholdings" />
                </section>

                <section
                    v-if="requiresLines"
                    class="overflow-hidden rounded-2xl surface"
                >
                    <div
                        class="flex items-center justify-between gap-4 border-b border-zinc-100 px-5 py-5 sm:px-6 dark:border-white/10"
                    >
                        <div>
                            <h2
                                class="font-semibold text-zinc-950 dark:text-white"
                            >
                                Bens e serviços
                            </h2>
                            <p
                                class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                Quantidades, descontos e impostos são calculados
                                por linha.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-zinc-950 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200"
                            @click="addLine"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            <span class="hidden sm:inline"
                                >Adicionar linha</span
                            >
                            <span class="sm:hidden">Adicionar</span>
                        </button>
                    </div>

                    <div
                        class="divide-y divide-zinc-100 xl:hidden dark:divide-white/10"
                    >
                        <article
                            v-for="(line, index) in form.lines"
                            :key="`mobile-${line.key}`"
                            class="space-y-5 p-5"
                        >
                            <div
                                class="flex items-center justify-between gap-4"
                            >
                                <p
                                    class="eyebrow text-zinc-500 dark:text-zinc-400"
                                >
                                    Linha {{ index + 1 }}
                                </p>
                                <button
                                    type="button"
                                    :disabled="form.lines.length === 1"
                                    class="rounded-lg p-2 text-zinc-400 transition hover:bg-rose-50 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-25 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                                    @click="removeLine(index)"
                                >
                                    <span class="sr-only"
                                        >Remover linha {{ index + 1 }}</span
                                    >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </button>
                            </div>

                            <div v-if="catalogueItems.length > 0">
                                <label
                                    :for="`mobile-catalogue-${line.key}`"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Do catálogo
                                </label>
                                <SelectInput
                                    :id="`mobile-catalogue-${line.key}`"
                                    v-model="line.catalogue_public_id"
                                    class="mt-2"
                                    placeholder="Escolher do catálogo…"
                                    :options="catalogueOptions"
                                    @change="applyCatalogueItem(line, $event)"
                                />
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div class="sm:col-span-1">
                                    <label
                                        :for="`mobile-code-${line.key}`"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                    >
                                        Código
                                    </label>
                                    <input
                                        :id="`mobile-code-${line.key}`"
                                        v-model="line.product_code"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="
                                            errorFor(
                                                `lines.${index}.product_code`,
                                            )
                                        "
                                    />
                                </div>
                                <div class="sm:col-span-2">
                                    <label
                                        :for="`mobile-description-${line.key}`"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                    >
                                        Descrição
                                    </label>
                                    <input
                                        :id="`mobile-description-${line.key}`"
                                        v-model="line.product_description"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="
                                            errorFor(
                                                `lines.${index}.product_description`,
                                            )
                                        "
                                    />
                                </div>
                            </div>

                            <div>
                                <label
                                    :for="`mobile-operation-${line.key}`"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Tipo de operação
                                </label>
                                <SelectInput
                                    :id="`mobile-operation-${line.key}`"
                                    v-model="line.operation_type"
                                    class="mt-2"
                                    :options="operationTypeOptions"
                                />
                                <FormError
                                    :message="
                                        errorFor(
                                            `lines.${index}.operation_type`,
                                        )
                                    "
                                />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label
                                        :for="`mobile-quantity-${line.key}`"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                    >
                                        Quantidade
                                    </label>
                                    <input
                                        :id="`mobile-quantity-${line.key}`"
                                        v-model="line.quantity"
                                        type="text"
                                        inputmode="decimal"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-right font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="
                                            errorFor(`lines.${index}.quantity`)
                                        "
                                    />
                                </div>
                                <div>
                                    <label
                                        :for="`mobile-unit-${line.key}`"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                    >
                                        Unidade
                                    </label>
                                    <input
                                        :id="`mobile-unit-${line.key}`"
                                        v-model="line.unit_of_measure"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="
                                            errorFor(
                                                `lines.${index}.unit_of_measure`,
                                            )
                                        "
                                    />
                                </div>
                                <div>
                                    <label
                                        :for="`mobile-price-${line.key}`"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                    >
                                        Preço unitário
                                    </label>
                                    <div class="relative mt-2">
                                        <input
                                            :id="`mobile-price-${line.key}`"
                                            v-model="line.unit_price"
                                            type="text"
                                            inputmode="decimal"
                                            class="block w-full rounded-xl bg-white py-2.5 pr-9 pl-3 text-right font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                        />
                                        <span
                                            class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-zinc-400"
                                            >Kz</span
                                        >
                                    </div>
                                    <FormError
                                        :message="
                                            errorFor(
                                                `lines.${index}.unit_price`,
                                            )
                                        "
                                    />
                                </div>
                                <div>
                                    <label
                                        :for="`mobile-discount-${line.key}`"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                    >
                                        Desconto
                                    </label>
                                    <div class="relative mt-2">
                                        <input
                                            :id="`mobile-discount-${line.key}`"
                                            v-model="line.discount_percentage"
                                            type="text"
                                            inputmode="decimal"
                                            class="block w-full rounded-xl bg-white py-2.5 pr-8 pl-3 text-right font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                        />
                                        <span
                                            class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-zinc-400"
                                            >%</span
                                        >
                                    </div>
                                    <FormError
                                        :message="
                                            errorFor(
                                                `lines.${index}.discount_percentage`,
                                            )
                                        "
                                    />
                                </div>
                            </div>

                            <div>
                                <label
                                    :for="`mobile-tax-${line.key}`"
                                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                                >
                                    Tratamento fiscal
                                </label>
                                <SelectInput
                                    :id="`mobile-tax-${line.key}`"
                                    v-model="line.tax_treatment"
                                    class="mt-2"
                                    :options="taxTreatmentOptions"
                                />
                                <FormError
                                    :message="
                                        errorFor(
                                            `lines.${index}.tax.percentage`,
                                        ) ??
                                        errorFor(
                                            `lines.${index}.tax.exemption_code`,
                                        )
                                    "
                                />
                            </div>

                            <dl
                                class="grid grid-cols-3 gap-3 rounded-xl bg-stone-50 p-4 text-xs dark:bg-white/[0.03]"
                            >
                                <div>
                                    <dt
                                        class="text-zinc-500 dark:text-zinc-400"
                                    >
                                        Líquido
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono font-semibold text-zinc-950 dark:text-white"
                                    >
                                        {{
                                            formatMoney(
                                                lineCalculations[index]?.net ??
                                                    0n,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-zinc-500 dark:text-zinc-400"
                                    >
                                        Imposto
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono font-semibold text-zinc-950 dark:text-white"
                                    >
                                        {{
                                            formatMoney(
                                                lineCalculations[index]?.tax ??
                                                    0n,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div class="text-right">
                                    <dt
                                        class="text-zinc-500 dark:text-zinc-400"
                                    >
                                        Total
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono font-bold text-brand-700 dark:text-brand-300"
                                    >
                                        {{
                                            formatMoney(
                                                lineCalculations[index]
                                                    ?.gross ?? 0n,
                                            )
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </article>
                    </div>

                    <div class="hidden overflow-x-auto xl:block">
                        <table
                            class="w-full min-w-[58rem] divide-y divide-zinc-200 dark:divide-white/10"
                        >
                            <thead class="bg-stone-50 dark:bg-white/[0.03]">
                                <tr>
                                    <th
                                        class="min-w-[16rem] py-3 pr-3 pl-5 text-left eyebrow text-zinc-500 sm:pl-6"
                                    >
                                        Artigo ou serviço
                                    </th>
                                    <th
                                        class="w-24 px-3 py-3 text-left eyebrow text-zinc-500"
                                    >
                                        Quantidade
                                    </th>
                                    <th
                                        class="w-36 px-3 py-3 text-left eyebrow text-zinc-500"
                                    >
                                        Preço unitário
                                    </th>
                                    <th
                                        class="w-28 px-3 py-3 text-left eyebrow text-zinc-500"
                                    >
                                        Desconto
                                    </th>
                                    <th
                                        class="w-52 px-3 py-3 text-left eyebrow text-zinc-500"
                                    >
                                        Imposto
                                    </th>
                                    <th
                                        class="w-32 px-3 py-3 text-right eyebrow text-zinc-500"
                                    >
                                        Total
                                    </th>
                                    <th class="w-14 py-3 pr-5 pl-3 sm:pr-6">
                                        <span class="sr-only">Acções</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <tr
                                    v-for="(line, index) in form.lines"
                                    :key="line.key"
                                    class="align-top"
                                >
                                    <td class="py-4 pr-3 pl-5 sm:pl-6">
                                        <SelectInput
                                            v-if="catalogueItems.length > 0"
                                            v-model="line.catalogue_public_id"
                                            class="mb-2"
                                            size="sm"
                                            placeholder="Escolher do catálogo…"
                                            :aria-label="`Artigo do catálogo para a linha ${index + 1}`"
                                            :options="catalogueOptions"
                                            @change="
                                                applyCatalogueItem(line, $event)
                                            "
                                        />
                                        <div
                                            class="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-2"
                                        >
                                            <div>
                                                <label
                                                    :for="`code-${line.key}`"
                                                    class="sr-only"
                                                    >Código</label
                                                >
                                                <input
                                                    :id="`code-${line.key}`"
                                                    v-model="line.product_code"
                                                    placeholder="Código"
                                                    class="block w-full rounded-lg bg-white px-2.5 py-2 font-mono text-xs text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                                />
                                            </div>
                                            <div>
                                                <label
                                                    :for="`description-${line.key}`"
                                                    class="sr-only"
                                                    >Descrição</label
                                                >
                                                <input
                                                    :id="`description-${line.key}`"
                                                    v-model="
                                                        line.product_description
                                                    "
                                                    placeholder="Descrição do artigo ou serviço"
                                                    class="block w-full rounded-lg bg-white px-2.5 py-2 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                                />
                                            </div>
                                        </div>
                                        <SelectInput
                                            v-model="line.operation_type"
                                            class="mt-2"
                                            size="sm"
                                            :aria-label="`Tipo de operação da linha ${index + 1}`"
                                            :options="operationTypeOptions"
                                        />
                                        <FormError
                                            :message="
                                                errorFor(`lines.${index}`)
                                            "
                                        />
                                        <FormError
                                            :message="
                                                errorFor(
                                                    `lines.${index}.product_code`,
                                                )
                                            "
                                        />
                                        <FormError
                                            :message="
                                                errorFor(
                                                    `lines.${index}.product_description`,
                                                )
                                            "
                                        />
                                        <FormError
                                            :message="
                                                errorFor(
                                                    `lines.${index}.operation_type`,
                                                )
                                            "
                                        />
                                    </td>
                                    <td class="px-3 py-4">
                                        <input
                                            v-model="line.quantity"
                                            type="text"
                                            inputmode="decimal"
                                            :aria-label="`Quantidade da linha ${index + 1}`"
                                            class="block w-full rounded-lg bg-white px-2.5 py-2 text-right font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                        />
                                        <input
                                            v-model="line.unit_of_measure"
                                            :aria-label="`Unidade da linha ${index + 1}`"
                                            placeholder="un"
                                            class="mt-2 block w-full rounded-lg bg-white px-2.5 py-2 text-xs text-zinc-700 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-zinc-200 dark:outline-white/10 dark:focus:outline-brand-400"
                                        />
                                        <FormError
                                            :message="
                                                errorFor(
                                                    `lines.${index}.quantity`,
                                                )
                                            "
                                        />
                                        <FormError
                                            :message="
                                                errorFor(
                                                    `lines.${index}.unit_of_measure`,
                                                )
                                            "
                                        />
                                    </td>
                                    <td class="px-3 py-4">
                                        <div class="relative">
                                            <input
                                                v-model="line.unit_price"
                                                type="text"
                                                inputmode="decimal"
                                                :aria-label="`Preço unitário da linha ${index + 1}`"
                                                class="block w-full rounded-lg bg-white py-2 pr-8 pl-2.5 text-right font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                            />
                                            <span
                                                class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-xs text-zinc-400"
                                                >Kz</span
                                            >
                                        </div>
                                        <FormError
                                            :message="
                                                errorFor(
                                                    `lines.${index}.unit_price`,
                                                )
                                            "
                                        />
                                    </td>
                                    <td class="px-3 py-4">
                                        <div class="relative">
                                            <input
                                                v-model="
                                                    line.discount_percentage
                                                "
                                                type="text"
                                                inputmode="decimal"
                                                :aria-label="`Desconto da linha ${index + 1}`"
                                                class="block w-full rounded-lg bg-white py-2 pr-7 pl-2.5 text-right font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                            />
                                            <span
                                                class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-xs text-zinc-400"
                                                >%</span
                                            >
                                        </div>
                                        <FormError
                                            :message="
                                                errorFor(
                                                    `lines.${index}.discount_percentage`,
                                                )
                                            "
                                        />
                                    </td>
                                    <td class="px-3 py-4">
                                        <SelectInput
                                            v-model="line.tax_treatment"
                                            size="sm"
                                            :aria-label="`Tratamento fiscal da linha ${index + 1}`"
                                            :options="taxTreatmentOptions"
                                        />
                                        <FormError
                                            :message="
                                                errorFor(`lines.${index}.tax`)
                                            "
                                        />
                                        <FormError
                                            :message="
                                                errorFor(
                                                    `lines.${index}.tax.percentage`,
                                                )
                                            "
                                        />
                                        <FormError
                                            :message="
                                                errorFor(
                                                    `lines.${index}.tax.exemption_code`,
                                                )
                                            "
                                        />
                                    </td>
                                    <td class="px-3 py-4 text-right">
                                        <p
                                            class="font-mono text-sm font-semibold text-zinc-950 dark:text-white"
                                        >
                                            {{
                                                formatMoney(
                                                    lineCalculations[index]
                                                        ?.gross ?? 0n,
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            IVA
                                            {{
                                                formatMoney(
                                                    lineCalculations[index]
                                                        ?.tax ?? 0n,
                                                )
                                            }}
                                        </p>
                                    </td>
                                    <td
                                        class="py-4 pr-5 pl-3 text-right sm:pr-6"
                                    >
                                        <button
                                            type="button"
                                            :disabled="form.lines.length === 1"
                                            class="rounded-lg p-2 text-zinc-400 transition hover:bg-rose-50 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-25 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                                            @click="removeLine(index)"
                                        >
                                            <span class="sr-only"
                                                >Remover linha
                                                {{ index + 1 }}</span
                                            >
                                            <Trash2
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-stone-50 dark:bg-white/[0.03]">
                                <tr>
                                    <th
                                        colspan="5"
                                        class="px-5 py-3 text-right text-sm font-semibold text-zinc-700 sm:px-6 dark:text-zinc-300"
                                    >
                                        Total do documento
                                    </th>
                                    <td
                                        class="px-3 py-3 text-right font-mono text-sm font-bold text-zinc-950 dark:text-white"
                                    >
                                        {{ formatMoney(totals.gross) }}
                                    </td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_23rem]">
                    <div class="min-w-0 space-y-6">
                        <section class="rounded-2xl surface p-5 sm:p-6">
                            <label
                                for="notes"
                                class="block text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                Observações
                                <span class="font-normal text-zinc-400"
                                    >(opcional)</span
                                >
                            </label>
                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="3"
                                placeholder="Instruções de entrega ou referência interna…"
                                class="mt-2 block w-full resize-y rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 placeholder:text-zinc-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:placeholder:text-zinc-500 dark:focus:outline-brand-400"
                            />
                            <FormError :message="form.errors.notes" />
                        </section>
                    </div>

                    <aside class="space-y-5 xl:sticky xl:top-24 xl:self-start">
                        <section
                            class="overflow-hidden rounded-2xl bg-brand-950 text-white shadow-[0_24px_70px_-38px_rgba(8,40,32,0.9)] ring-1 ring-white/10"
                        >
                            <div class="border-b border-white/10 px-5 py-5">
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <div>
                                        <p class="eyebrow text-brand-100/60">
                                            Resumo
                                        </p>
                                        <h2 class="mt-1 text-2xl display">
                                            Totais em {{ form.currency_code }}
                                        </h2>
                                    </div>
                                    <Calculator
                                        class="size-6 text-accent-400"
                                        aria-hidden="true"
                                    />
                                </div>
                            </div>
                            <dl class="space-y-3 px-5 py-5 text-sm">
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-brand-100/65">Subtotal</dt>
                                    <dd class="font-mono font-medium">
                                        {{ formatMoney(totals.net) }}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-brand-100/65">Descontos</dt>
                                    <dd class="font-mono font-medium">
                                        − {{ formatMoney(totals.settlement) }}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-brand-100/65">Impostos</dt>
                                    <dd class="font-mono font-medium">
                                        {{ formatMoney(totals.tax) }}
                                    </dd>
                                </div>
                                <div
                                    class="mt-4 flex items-end justify-between gap-4 border-t border-white/10 pt-4"
                                >
                                    <dt>
                                        <span class="block font-semibold"
                                            >Total a pagar</span
                                        >
                                        <span
                                            class="mt-0.5 block text-xs text-brand-100/55"
                                            >Pré-visualização</span
                                        >
                                    </dt>
                                    <dd
                                        class="numeric text-2xl font-semibold tracking-tight text-accent-400"
                                    >
                                        {{ formatMoney(totals.gross) }}
                                    </dd>
                                </div>
                                <div
                                    v-if="isForeignCurrency"
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-brand-100/65">
                                        Equivalente em AOA
                                    </dt>
                                    <dd class="font-mono font-medium">
                                        {{ formatMoney(grossInKwanzas) }}
                                    </dd>
                                </div>
                                <div
                                    v-if="withholdingTotal > 0n"
                                    class="flex items-center justify-between gap-4 border-t border-white/10 pt-3"
                                >
                                    <dt class="text-brand-100/65">
                                        <span class="block"
                                            >Retido pelo adquirente</span
                                        >
                                        <span
                                            class="mt-0.5 block text-xs text-brand-100/45"
                                            >Entregue por ele à AGT</span
                                        >
                                    </dt>
                                    <dd class="font-mono font-medium">
                                        {{ formatMoney(withholdingTotal) }}
                                    </dd>
                                </div>
                            </dl>
                            <div
                                class="bg-white/[0.06] px-5 py-4 text-xs/5 text-brand-100/65"
                            >
                                Os valores finais são sempre recalculados no
                                servidor antes de serem guardados.
                            </div>
                        </section>

                        <section class="rounded-2xl surface p-5">
                            <div class="flex gap-3">
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300"
                                >
                                    <ShieldCheck
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <h2
                                        class="text-sm font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Guardas fiscais activas
                                    </h2>
                                    <ul
                                        class="mt-3 space-y-2 text-xs/5 text-zinc-600 dark:text-zinc-400"
                                    >
                                        <li class="flex gap-2">
                                            <Check
                                                class="mt-0.5 size-3.5 shrink-0 text-emerald-600"
                                                aria-hidden="true"
                                            />
                                            Valores monetários sem ponto
                                            flutuante
                                        </li>
                                        <li class="flex gap-2">
                                            <Check
                                                class="mt-0.5 size-3.5 shrink-0 text-emerald-600"
                                                aria-hidden="true"
                                            />
                                            Imposto arredondado por excesso ao
                                            cêntimo
                                        </li>
                                        <li class="flex gap-2">
                                            <Check
                                                class="mt-0.5 size-3.5 shrink-0 text-emerald-600"
                                                aria-hidden="true"
                                            />
                                            Isolamento por empresa e entidade
                                            fiscal
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-2xl surface p-5">
                            <div class="flex items-start gap-3">
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                                >
                                    <Send class="size-4.5" aria-hidden="true" />
                                </span>
                                <div>
                                    <h2
                                        class="text-sm font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Emissão fiscal
                                    </h2>
                                    <p
                                        class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Atribui o número, assina e coloca a
                                        entrega na fila AGT numa só operação.
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="compatibleSeries.length > 0"
                                class="mt-4"
                            >
                                <label
                                    for="fiscal-series"
                                    class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300"
                                >
                                    Série autorizada
                                </label>
                                <SelectInput
                                    id="fiscal-series"
                                    v-model="issueForm.series_public_id"
                                    class="mt-2"
                                    :options="seriesOptions"
                                />
                                <FormError
                                    :message="
                                        issueForm.errors.series_public_id ??
                                        issueForm.errors.revision
                                    "
                                />
                            </div>

                            <p
                                v-if="issueBlockReason"
                                class="mt-4 rounded-xl bg-amber-50 p-3 text-xs/5 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                            >
                                {{ issueBlockReason }}
                            </p>

                            <Link
                                v-if="!guardrails.mfa_enabled"
                                :href="security.url()"
                                class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300"
                            >
                                <LockKeyhole
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Configurar MFA
                            </Link>
                            <Link
                                v-else-if="
                                    document.public_id !== null &&
                                    compatibleSeries.length === 0
                                "
                                :href="agtConnection.url()"
                                class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300"
                            >
                                <ShieldCheck
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Sincronizar séries AGT
                            </Link>

                            <button
                                type="button"
                                :disabled="!canOpenIssue"
                                class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent-400 px-4 py-3 text-sm font-semibold text-brand-950 shadow-sm focus-ring-inverted transition hover:bg-accent-300 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="openIssueDialog"
                            >
                                <Send class="size-4" aria-hidden="true" />
                                Emitir e enviar à AGT
                            </button>
                        </section>

                        <section
                            class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200"
                        >
                            <div class="flex gap-3">
                                <Info
                                    class="mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <div>
                                    <h2 class="text-sm font-semibold">
                                        Ainda não é uma factura emitida
                                    </h2>
                                    <p class="mt-1 text-xs/5 opacity-80">
                                        O rascunho não tem número fiscal,
                                        assinatura JWS, QR fiscal nem
                                        confirmação da AGT.
                                    </p>
                                </div>
                            </div>
                        </section>

                        <button
                            type="submit"
                            :disabled="
                                form.processing || establishments.length === 0
                            "
                            class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-3 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-brand-500 dark:hover:bg-brand-400"
                        >
                            <LoaderCircle
                                v-if="form.processing"
                                class="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            <FilePenLine
                                v-else
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{
                                form.processing
                                    ? 'A guardar…'
                                    : 'Guardar como rascunho'
                            }}
                        </button>

                        <div
                            class="flex items-center justify-center gap-2 text-center text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            <Building2 class="size-3.5" aria-hidden="true" />
                            {{ company.trade_name ?? company.legal_name }} · NIF
                            {{ company.tax_identification_number }}
                        </div>
                    </aside>
                </div>
            </div>
        </form>

        <Teleport to="body">
            <TransitionRoot as="template" :show="issueDialogOpen">
                <Dialog class="relative z-50" @close="closeIssueDialog">
                    <TransitionChild
                        as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0"
                        enter-to="opacity-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100"
                        leave-to="opacity-0"
                    >
                        <div
                            class="fixed inset-0 bg-zinc-950/70 backdrop-blur-sm transition-opacity"
                        />
                    </TransitionChild>

                    <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                        <div
                            class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0"
                        >
                            <TransitionChild
                                as="template"
                                enter="ease-out duration-300"
                                enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                                enter-to="opacity-100 translate-y-0 sm:scale-100"
                                leave="ease-in duration-200"
                                leave-from="opacity-100 translate-y-0 sm:scale-100"
                                leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                            >
                                <DialogPanel
                                    class="relative w-full max-w-lg transform overflow-hidden rounded-2xl bg-white text-left shadow-xl ring-1 ring-zinc-900/5 transition-all dark:bg-zinc-900 dark:ring-white/10"
                                >
                                    <form @submit.prevent="confirmIssue">
                                        <div class="px-5 pt-6 pb-5 sm:p-6">
                                            <div
                                                class="flex size-11 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300"
                                            >
                                                <TriangleAlert
                                                    class="size-5"
                                                    aria-hidden="true"
                                                />
                                            </div>
                                            <DialogTitle
                                                as="h2"
                                                class="mt-4 text-base font-semibold text-zinc-950 dark:text-white"
                                            >
                                                Emitir esta factura?
                                            </DialogTitle>
                                            <p
                                                class="mt-2 text-sm/6 text-zinc-600 dark:text-zinc-400"
                                            >
                                                Esta confirmação é irreversível.
                                                O servidor recalculará todos os
                                                valores antes de atribuir o
                                                próximo número da série.
                                            </p>
                                            <dl
                                                class="mt-5 divide-y divide-zinc-100 rounded-xl bg-zinc-50 px-4 text-sm ring-1 ring-zinc-200 dark:divide-white/10 dark:bg-white/[0.03] dark:ring-white/10"
                                            >
                                                <div
                                                    class="flex items-center justify-between gap-4 py-3"
                                                >
                                                    <dt
                                                        class="text-zinc-500 dark:text-zinc-400"
                                                    >
                                                        Série
                                                    </dt>
                                                    <dd
                                                        class="font-semibold text-zinc-950 dark:text-white"
                                                    >
                                                        {{
                                                            selectedFiscalSeries?.series_code ??
                                                            '—'
                                                        }}
                                                    </dd>
                                                </div>
                                                <div
                                                    class="flex items-center justify-between gap-4 py-3"
                                                >
                                                    <dt
                                                        class="text-zinc-500 dark:text-zinc-400"
                                                    >
                                                        Próximo número
                                                    </dt>
                                                    <dd
                                                        class="font-mono font-semibold text-zinc-950 dark:text-white"
                                                    >
                                                        {{
                                                            selectedFiscalSeries?.next_number ??
                                                            '—'
                                                        }}
                                                    </dd>
                                                </div>
                                                <div
                                                    class="flex items-center justify-between gap-4 py-3"
                                                >
                                                    <dt
                                                        class="text-zinc-500 dark:text-zinc-400"
                                                    >
                                                        Total
                                                    </dt>
                                                    <dd
                                                        class="font-mono font-semibold text-zinc-950 dark:text-white"
                                                    >
                                                        {{
                                                            formatMoney(
                                                                totals.gross,
                                                            )
                                                        }}
                                                    </dd>
                                                </div>
                                            </dl>
                                            <div
                                                v-if="
                                                    issueForm.errors
                                                        .series_public_id ||
                                                    issueForm.errors.revision
                                                "
                                                class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-400/10 dark:text-rose-300 dark:ring-rose-400/20"
                                                role="alert"
                                            >
                                                {{
                                                    issueForm.errors
                                                        .series_public_id ??
                                                    issueForm.errors.revision
                                                }}
                                            </div>
                                        </div>
                                        <div
                                            class="flex flex-col-reverse gap-3 bg-zinc-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6 dark:bg-white/[0.03]"
                                        >
                                            <button
                                                type="button"
                                                :disabled="issueForm.processing"
                                                class="inline-flex justify-center rounded-xl bg-white px-3.5 py-2.5 text-sm font-semibold text-zinc-900 shadow-sm ring-1 ring-zinc-300 transition hover:bg-zinc-50 disabled:opacity-50 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:hover:bg-white/10"
                                                @click="closeIssueDialog"
                                            >
                                                Voltar e rever
                                            </button>
                                            <button
                                                type="submit"
                                                :disabled="issueForm.processing"
                                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-3.5 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-brand-500 dark:hover:bg-brand-400"
                                            >
                                                <LoaderCircle
                                                    v-if="issueForm.processing"
                                                    class="size-4 animate-spin"
                                                    aria-hidden="true"
                                                />
                                                <Send
                                                    v-else
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    issueForm.processing
                                                        ? 'A emitir…'
                                                        : 'Confirmar emissão'
                                                }}
                                            </button>
                                        </div>
                                    </form>
                                </DialogPanel>
                            </TransitionChild>
                        </div>
                    </div>
                </Dialog>
            </TransitionRoot>
        </Teleport>
    </AppLayout>
</template>
