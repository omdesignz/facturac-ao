<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronRight,
    CircleAlert,
    FilePenLine,
    ListChecks,
    LoaderCircle,
    LockKeyhole,
    Plus,
    ReceiptText,
    Save,
    Send,
    ShieldCheck,
    Trash2,
    UserRound,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import AppButton from '@/components/AppButton.vue';
import DateInput from '@/components/DateInput.vue';
import DraftRecoveryNotice from '@/components/DraftRecoveryNotice.vue';
import FormError from '@/components/FormError.vue';
import FormField from '@/components/FormField.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { addDaysToIsoDate } from '@/lib/dates';
import { invoiceRecoveryDocument } from '@/lib/draft-recovery';
import { roundFiscalLineAmount } from '@/lib/fiscal-rounding';

import { parseTypedLines } from '@/lib/typed-lines';
import { useDraftRecovery } from '@/lib/use-draft-recovery';
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
    requires_line_operation_date: boolean;
}

interface SettlementRow {
    document_public_id: string;
    document_no: string;
    amount: string;
}

interface AdjustableDocument {
    lines: { line_number: number; product_description: string }[];
    public_id: string;
    document_no: string;
    document_date: string;
    customer_name: string;
    customer_public_id: string | null;
    currency_code: string;
    settleable: boolean;
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
    reference_line_number?: string;
    origin_transport_line_public_id?: string;
    operation_type: string;
    operation_date: string;
    product_code: string;
    product_description: string;
    quantity: string;
    unit_of_measure: string;
    unit_price: string;
    discount_percentage: string;
    tax_treatment: string;
}

interface EditableLine extends DocumentLine {
    reference_line_number: string;
    origin_transport_line_public_id: string;
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
    goods_available_at: string;
    goods_available_location: string;
    currency_code: string;
    exchange_rate: string;
    withholdings: WithholdingRow[];
    withholding_total: string;
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
    recoveryScope: string;
    repeatSource?: { public_id: string; document_no: string };
    company: Company;
    establishments: Establishment[];
    customers: Customer[];
    catalogueItems: CatalogueItem[];
    transportLines: { value: string; label: string; tax_id: string }[];
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
const requiresLineOperationDate = computed(
    () => currentType.value?.requires_line_operation_date ?? false,
);

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
                issued.settleable &&
                issued.currency_code === form.currency_code &&
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
    form.settlements.reduce(
        (total, row) => total + (parseScaled(row.amount, 2) ?? 0n),
        0n,
    ),
);

async function addSettlement(): Promise<void> {
    form.settlements.push({
        document_public_id: '',
        document_no: '',
        amount: '0.00',
    });

    const position = form.settlements.length;

    announce(`Factura ${position} adicionada.`);
    await nextTick();
    focusFirst(`settlement-doc-${position - 1}`);
}

async function removeSettlement(index: number): Promise<void> {
    form.settlements.splice(index, 1);
    announce(`Factura ${index + 1} removida.`);
    await nextTick();
    focusFirst(`remove-settlement-${index}`, 'add-settlement');
}

/** Every invoice this customer still owes on, allocated at its full balance. */
const outstandingForCustomer = computed(() =>
    props.adjustableDocuments.filter(
        (issued) =>
            issued.settleable &&
            issued.currency_code === form.currency_code &&
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

function editableLine(
    line?: DocumentLine,
    defaultOperationDate: string = today,
): EditableLine {
    nextLineKey.value += 1;

    return {
        key: `line-${nextLineKey.value}`,
        reference_line_number: line?.reference_line_number ?? '',
        origin_transport_line_public_id:
            line?.origin_transport_line_public_id ?? '',
        catalogue_public_id:
            props.catalogueItems.find(
                (item) => item.code === line?.product_code,
            )?.public_id ?? '',
        operation_type: line?.operation_type ?? 'TB',
        operation_date: line?.operation_date ?? defaultOperationDate,
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
    revision: props.document.revision,
    document_type: props.document.document_type,
    document_date: props.document.document_date,
    due_date: props.document.due_date ?? '',
    goods_available_at: props.document.goods_available_at ?? '',
    goods_available_location: props.document.goods_available_location ?? '',
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
const recovery = useDraftRecovery({
    scope: () => [
        page.props.auth.user?.id ?? '',
        page.props.currentWorkspace?.public_id ?? '',
        props.recoveryScope,
    ],
    document: () =>
        invoiceRecoveryDocument(
            props.document.public_id,
            props.document.document_type,
            props.repeatSource?.public_id,
        ),
    version: () => String(props.document.revision),
    data: () => {
        return Object.fromEntries(
            Object.entries(form.data()).filter(([key]) => key !== 'revision'),
        );
    },
    restore: (data) => {
        for (const key of Object.keys(form.data())) {
            if (key !== 'revision' && Object.hasOwn(data, key)) {
                Object.assign(form, { [key]: data[key as keyof typeof data] });
            }
        }

        nextLineKey.value += form.lines.length;
        form.lines.forEach((line) => {
            line.key = `line-${++nextLineKey.value}`;
        });
    },
});

const issueDialogOpen = ref(false);

/**
 * Most documents go out with the defaults, so these fields wait behind a line
 * saying what they hold. They stay in the page, and open by themselves when the
 * document type needs more or the server has something to say about one.
 */
const documentDetailsOpen = ref(false);

const DOCUMENT_DETAIL_FIELDS = [
    'document_type',
    'references_document_public_id',
    'adjustment_reason',
    'payment_method',
    'payment_date',
    'establishment_public_id',
    'currency_code',
    'exchange_rate',
    'document_date',
    'due_date',
    'goods_available_at',
    'goods_available_location',
] as const;

const documentDetailsRequired = computed(
    () =>
        isAdjustment.value ||
        isReceipt.value ||
        isForeignCurrency.value ||
        DOCUMENT_DETAIL_FIELDS.some((field) => Boolean(form.errors[field])),
);

const showDocumentDetails = computed(
    () => documentDetailsOpen.value || documentDetailsRequired.value,
);

function shortIsoDate(isoDate: string): string {
    return isoDate.slice(0, 10).split('-').reverse().join('/');
}

const documentDetailsSummary = computed(() =>
    [
        `${form.document_type} · ${documentTypeLabel.value}`,
        establishmentOptions.value.find(
            (option) => option.value === form.establishment_public_id,
        )?.label,
        form.document_date ? shortIsoDate(form.document_date) : null,
        form.due_date ? `vence ${shortIsoDate(form.due_date)}` : null,
    ]
        .filter(Boolean)
        .join(' · '),
);

/** Mod+S keeps the draft, wherever the cursor is; the browser's save has no use here. */
function saveOnShortcut(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key === 's') {
        event.preventDefault();
        submit();
    }
}

onMounted(() => window.addEventListener('keydown', saveOnShortcut));
onBeforeUnmount(() => window.removeEventListener('keydown', saveOnShortcut));
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
const referenceLineOptions = computed<ListboxOption[]>(() =>
    (
        props.adjustableDocuments.find(
            (document) =>
                document.public_id === form.references_document_public_id,
        )?.lines ?? []
    ).map((line) => ({
        value: String(line.line_number),
        label: `${line.line_number} · ${line.product_description}`,
    })),
);

watch(
    () => form.references_document_public_id,
    () => {
        if (recovery.restoring.value) {
            return;
        }

        form.lines.forEach((line) => {
            line.reference_line_number = '';
        });
    },
);

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
/** "este recibo", "esta factura": the document types are not all feminine. */
const issueDemonstrative = computed(() =>
    /^recibo/i.test(documentTypeLabel.value) ? 'este' : 'esta',
);

/** "Factura para X." without doubling the stop when X ends in "Lda." */
const issueAddressee = computed(() =>
    `${documentTypeLabel.value} para ${form.customer.name.trim() || 'o cliente'}`.replace(
        /\.?$/,
        '.',
    ),
);

/** The number the series will assign, spelled as the server composes it. */
const nextDocumentNumber = computed(() => {
    const series = selectedFiscalSeries.value;

    return series === undefined
        ? null
        : `${form.document_type} ${series.series_code.trim().toUpperCase()}/${series.next_number}`;
});

/** What becomes permanent the moment the document is issued. */
const issueFacts = computed(() => {
    const establishment = props.establishments.find(
        (candidate) => candidate.public_id === form.establishment_public_id,
    );
    const customer = [
        form.customer.name,
        form.customer.tax_identification_number
            ? `NIF ${form.customer.tax_identification_number}`
            : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return [
        {
            label: 'Série',
            value: [
                selectedFiscalSeries.value?.series_code,
                establishment?.name,
            ]
                .filter(Boolean)
                .join(' · '),
            numeric: false,
        },
        { label: 'Cliente', value: customer || '—', numeric: false },
        { label: 'IVA', value: formatMoney(totals.value.tax), numeric: true },
        {
            label: 'Total',
            value: formatMoney(totals.value.gross),
            numeric: true,
        },
    ];
});

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

watch(
    requiresLineOperationDate,
    (isRequired) => {
        if (!isRequired) {
            return;
        }

        form.lines.forEach((line) => {
            if (line.operation_date === '') {
                line.operation_date = form.document_date;
            }
        });
    },
    { immediate: true },
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
    const base = roundFiscalLineAmount(
        unitPrice * quantity,
        10_000n,
        form.document_type,
    );
    const net = roundFiscalLineAmount(
        unitPrice * (10_000n - boundedDiscount) * quantity,
        100_000_000n,
        form.document_type,
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
const hasSavedReceiptTotals = computed(
    () => props.document.public_id !== null && !form.isDirty,
);
const totals = computed(() => {
    if (settlesOtherDocuments.value) {
        return {
            settlement: 0n,
            net: hasSavedReceiptTotals.value
                ? (parseScaled(props.document.totals.net, 2) ?? 0n)
                : 0n,
            tax: hasSavedReceiptTotals.value
                ? (parseScaled(props.document.totals.tax, 2) ?? 0n)
                : 0n,
            gross: settlementTotal.value,
        };
    }

    return lineCalculations.value.reduce(
        (total, line) => ({
            settlement: total.settlement + line.settlement,
            net: total.net + line.net,
            tax: total.tax + line.tax,
            gross: total.gross + line.gross,
        }),
        { settlement: 0n, net: 0n, tax: 0n, gross: 0n },
    );
});

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
    settlesOtherDocuments.value
        ? hasSavedReceiptTotals.value
            ? (parseScaled(props.document.withholding_total, 2) ?? 0n)
            : 0n
        : withholdingPreview.value.reduce(
              (total, row) => total + row.amount,
              0n,
          ),
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

    // A non-breaking space keeps «Kz» on the same line as its figure.
    return `${integerFormatter.format(whole)}${decimalSeparator}${fraction}\u00A0Kz`;
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

async function addWithholding(): Promise<void> {
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

    const position = form.withholdings.length;

    announce(`Retenção ${position} adicionada.`);
    await nextTick();
    focusFirst(`withholding-type-${position - 1}`);
}

async function removeWithholding(index: number): Promise<void> {
    form.withholdings.splice(index, 1);
    announce(`Retenção ${index + 1} removida.`);
    await nextTick();
    focusFirst(`remove-withholding-${index}`, 'add-withholding');
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
    const due = addDaysToIsoDate(form.document_date, days);

    if (due === null) {
        return;
    }

    form.due_date = due;
}

function useManualCustomer(): void {
    form.customer_public_id = '';
    selectCustomer();
}

/**
 * The ids a line's first field can have: the card layout (below `xl`) and the
 * table each render their own copy, and only one of them is on screen.
 */
function firstFieldIds(key: string): string[] {
    return [
        `mobile-catalogue-${key}`,
        `mobile-code-${key}`,
        `catalogue-${key}`,
        `code-${key}`,
    ];
}

/* ------------------------------------------------------ typing a sale */

/**
 * "2 óleo, 1 arroz, 20 cesto a 950": the lines of a sale typed as a sentence,
 * matched against the catalogue. Only a line that matched one article is added;
 * the rest are shown back, for a person to type again or pick by hand.
 */
const typedSale = ref('');
const typedLines = computed(() =>
    parseTypedLines(typedSale.value, props.catalogueItems),
);
const typedSaleReady = computed(
    () =>
        typedLines.value.length > 0 &&
        typedLines.value.every((line) => line.item !== null),
);

function addTypedSale(): void {
    if (!typedSaleReady.value) {
        return;
    }

    // The form opens on one empty line; typing a sale replaces it.
    const [first] = form.lines;

    if (
        form.lines.length === 1 &&
        first !== undefined &&
        first.catalogue_public_id === '' &&
        first.product_description === '' &&
        Number(first.unit_price) === 0
    ) {
        form.lines.splice(0, 1);
    }

    for (const typed of typedLines.value) {
        if (typed.item === null) {
            continue;
        }

        const line = editableLine(undefined, form.document_date);

        line.catalogue_public_id = typed.item.public_id;
        applyCatalogueItem(line, typed.item.public_id);
        line.quantity = typed.quantity;

        if (typed.unitPrice !== null) {
            line.unit_price = typed.unitPrice;
        }

        form.lines.push(line);
    }

    announce(
        `${lineCountLabel(typedLines.value.length)} a partir do que escreveu. ${lineCountLabel(form.lines.length)}.`,
    );
    typedSale.value = '';
}

async function addLine(): Promise<void> {
    const line = editableLine(undefined, form.document_date);

    form.lines.push(line);
    announce(
        `Linha ${form.lines.length} adicionada. ${lineCountLabel(form.lines.length)}.`,
    );
    await nextTick();
    focusFirst(...firstFieldIds(line.key));
}

async function removeLine(index: number): Promise<void> {
    if (form.lines.length === 1) {
        return;
    }

    form.lines.splice(index, 1);
    announce(
        `Linha ${index + 1} removida. ${lineCountLabel(form.lines.length)}.`,
    );
    await nextTick();

    const next = form.lines[index];

    focusFirst(
        ...(next === undefined
            ? []
            : [`mobile-remove-line-${next.key}`, `remove-line-${next.key}`]),
        'add-line-end',
    );
}

function lineCountLabel(count: number): string {
    return count === 1
        ? '1 linha no documento'
        : `${count} linhas no documento`;
}

/* ------------------------------------------------- focus and announcements */

/** Spoken politely by the status region at the top of the form. */
const announcement = ref('');

function announce(message: string): void {
    announcement.value = message;
}

function isOnScreen(element: HTMLElement): boolean {
    return element.getClientRects().length > 0;
}

/** The control inside an id: Headless UI may put the id on a wrapper. */
function focusTarget(element: HTMLElement | null): HTMLElement | null {
    if (element === null) {
        return null;
    }

    const target = element.matches('input, select, textarea, button')
        ? element
        : element.querySelector<HTMLElement>('input, select, textarea, button');

    if (
        target === null ||
        !isOnScreen(target) ||
        (target as HTMLButtonElement).disabled
    ) {
        return null;
    }

    return target;
}

/** Focuses the first of these ids that is on screen and enabled. */
function focusFirst(...ids: string[]): boolean {
    for (const id of ids) {
        const target = focusTarget(document.getElementById(id));

        if (target !== null) {
            target.focus();

            return true;
        }
    }

    return false;
}

const errorBanner = ref<HTMLElement | null>(null);

/**
 * After a failed save, puts the user in front of the problem: the first
 * invalid control that is actually on screen (the card layout and the table
 * both exist in the DOM, one of them hidden), else the error banner.
 */
async function showFirstProblem(): Promise<void> {
    await nextTick();

    const invalid = Array.from(
        document.querySelectorAll<HTMLElement>('[aria-invalid="true"]'),
    )
        .map((element) => focusTarget(element))
        .find((element) => element !== null);

    const target = invalid ?? errorBanner.value;

    if (target === null || target === undefined) {
        return;
    }

    target.focus({ preventScroll: true });
    target.scrollIntoView({ block: 'center', behavior: 'auto' });
}

/** `invalid` for a field: true only while the server has an error for it. */
function invalid(...paths: string[]): true | undefined {
    return paths.some((path) => errorFor(path) !== undefined)
        ? true
        : undefined;
}

/** The id of the message that explains a field's error. */
function errorId(path: string, scope = ''): string {
    return `${scope}error-${path.replace(/[^a-z0-9]+/gi, '-')}`;
}

/** The ids of whichever of these errors are showing, for `aria-describedby`. */
function describedBy(scope: string, ...paths: string[]): string | undefined {
    const ids = paths
        .filter((path) => errorFor(path) !== undefined)
        .map((path) => errorId(path, scope));

    return ids.length > 0 ? ids.join(' ') : undefined;
}

/* ------------------------------------------------------- unsaved changes */

const unsavedChangesWarning =
    'Tem alterações que ainda não foram guardadas. Se sair desta página, perdem-se.';

/** Visits this page started itself (save, issue): they must never ask. */
const ownVisits = new WeakSet<object>();

function markOwnVisit(visit: object): void {
    ownVisits.add(visit);
}

function hasUnsavedChanges(): boolean {
    return form.isDirty && !form.processing && !issueForm.processing;
}

function warnBeforeUnload(event: BeforeUnloadEvent): void {
    if (!hasUnsavedChanges()) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
}

let removeVisitGuard: (() => void) | null = null;

onMounted(() => {
    window.addEventListener('beforeunload', warnBeforeUnload);

    /*
     * Inertia cancels a visit when a listener returns false, and it has to be
     * answered at once, so this uses the browser's own confirm() rather than
     * the app's promise-shaped dialog.
     */
    removeVisitGuard = router.on('before', (event) => {
        const visit = event.detail.visit;

        if (
            ownVisits.has(visit) ||
            visit.prefetch ||
            visit.async ||
            visit.preserveState === true ||
            !hasUnsavedChanges()
        ) {
            return;
        }

        const stayOnPage =
            visit.url.pathname === window.location.pathname &&
            (visit.only.length > 0 || visit.except.length > 0);

        if (stayOnPage) {
            return;
        }

        return window.confirm(unsavedChangesWarning);
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', warnBeforeUnload);
    removeVisitGuard?.();
    removeVisitGuard = null;
});

function submit(): void {
    if (recovery.pending.value !== null) {
        return;
    }

    const submitted = JSON.parse(JSON.stringify(form.data()));
    recovery.beforeSave();
    form.transform((data) => ({
        ...data,
        due_date: data.due_date || null,
        goods_available_at: data.goods_available_at || null,
        goods_available_location: data.goods_available_location || null,
        customer_public_id: data.customer_public_id || null,
        withholdings: requiresLines.value ? data.withholdings : [],
        lines: requiresLines.value
            ? data.lines.map((line) => {
                  const treatment =
                      props.taxTreatments.find(
                          (option) => option.value === line.tax_treatment,
                      ) ?? props.taxTreatments[0];

                  return {
                      reference_line_number: isAdjustment.value
                          ? line.reference_line_number || null
                          : null,
                      origin_transport_line_public_id:
                          line.origin_transport_line_public_id || null,
                      operation_type: line.operation_type,
                      operation_date: requiresLineOperationDate.value
                          ? line.operation_date
                          : null,
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
              })
            : [],
    })).submit(
        props.document.public_id === null
            ? store()
            : update(props.document.public_id),
        {
            preserveScroll: true,
            onBefore: markOwnVisit,
            onSuccess: () => {
                if (page.props.flash.error) {
                    return;
                }

                form.revision = props.document.revision;
                form.defaults({
                    ...submitted,
                    revision: props.document.revision,
                });
                recovery.saved();
            },
            onError: () => {
                void showFirstProblem();
            },
            onFinish: recovery.finished,
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
        onBefore: markOwnVisit,
        onSuccess: () => {
            issueDialogOpen.value = false;
            recovery.discard();
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

        <div
            v-if="recovery.pending.value || recovery.message.value"
            class="px-4 pt-4 sm:px-6"
        >
            <DraftRecoveryNotice
                :available="recovery.pending.value !== null"
                :conflict="recovery.hasConflict.value"
                :message="recovery.message.value"
                @restore="recovery.restore"
                @discard="recovery.discard"
            />
        </div>

        <div v-if="repeatSource" class="px-4 pt-4 sm:px-6">
            <p
                class="rounded-xl border border-zinc-200 p-4 text-sm text-zinc-600 dark:border-zinc-800 dark:text-zinc-300"
                role="status"
            >
                Nova factura a partir de {{ repeatSource.document_no }}. Os
                artigos foram identificados pelo código. Reveja o cliente, as
                quantidades e os preços e impostos actuais. Descontos, retenções
                e pagamentos anteriores não foram copiados.
            </p>
        </div>

        <form
            :inert="recovery.pending.value !== null"
            class="flex min-h-[calc(100dvh-4rem)] flex-col lg:min-h-[calc(100dvh-3rem)]"
            @submit.prevent="submit"
        >
            <p
                class="sr-only"
                role="status"
                aria-live="polite"
                aria-atomic="true"
            >
                {{ announcement }}
            </p>

            <!-- ------------------------------------------------ title bar -->
            <header
                class="sticky inset-bs-16 z-20 flex min-h-[3.25rem] flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-[var(--ui-line)] bg-[var(--surface-page)] px-4 py-2 sm:px-6 lg:inset-bs-12"
            >
                <span class="text-[0.8125rem] text-[var(--ui-ink-3)]"
                    >Vender</span
                >
                <ChevronRight
                    class="size-3.5 text-[var(--ui-ink-3)]"
                    aria-hidden="true"
                />
                <h1 class="text-[0.8125rem] font-semibold text-[var(--ui-ink)]">
                    {{
                        document.public_id === null
                            ? newDocumentLabel
                            : `Editar ${documentTypeLabel.toLowerCase()}`
                    }}
                </h1>
                <StatusBadge class="ms-1.5" label="Rascunho" tone="draft" />
                <StatusBadge
                    v-if="isAdjustment && document.references_document_no"
                    :label="`Corrige ${document.references_document_no}`"
                    tone="warning"
                />
                <span
                    v-if="document.revision > 0"
                    class="text-xs text-[var(--ui-ink-3)]"
                >
                    Revisão {{ document.revision }}
                </span>
                <span class="flex-1" />
                <span class="text-xs text-[var(--ui-ink-3)] max-md:hidden"
                    >Guardar não atribui número nem envia nada à AGT.</span
                >
                <AppButton
                    variant="outline"
                    size="sm"
                    type="submit"
                    :aria-busy="form.processing"
                    :disabled="form.processing || establishments.length === 0"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-3.5 animate-spin"
                        aria-hidden="true"
                    />
                    <Save v-else class="size-3.5" aria-hidden="true" />
                    Guardar rascunho
                </AppButton>
            </header>

            <div class="flex flex-1 flex-col 2xl:flex-row 2xl:items-start">
                <!-- The document, on paper, on the desk. -->
                <div
                    class="min-w-0 self-stretch bg-[var(--ui-desk)] px-2 pt-4 sm:px-8 sm:pt-8 2xl:flex-1 2xl:pb-8"
                >
                    <div
                        class="mx-auto flex max-w-[60rem] flex-col rounded-md bg-[var(--surface-raised)] pb-6 shadow-[var(--ui-paper-shadow)]"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-6 px-5 pt-8 pb-6 sm:px-6 sm:pt-9"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="grid size-10 shrink-0 place-items-center rounded-[9px] bg-[#161616] dark:shadow-[inset_0_0_0_1px_rgb(255_255_255/0.1)]"
                                    aria-hidden="true"
                                >
                                    <span
                                        class="size-2.5 rounded-full bg-accent-400"
                                    />
                                </span>
                                <span class="flex flex-col gap-0.5">
                                    <span
                                        class="text-sm font-[650] tracking-[-0.01em] text-[var(--ui-ink)]"
                                        >{{
                                            company.trade_name ??
                                            company.legal_name
                                        }}</span
                                    >
                                    <span
                                        v-if="company.tax_identification_number"
                                        class="text-[0.71875rem] text-[var(--ui-ink-3)]"
                                        >NIF
                                        {{
                                            company.tax_identification_number
                                        }}</span
                                    >
                                </span>
                            </div>
                            <div class="flex flex-col items-end gap-0.5">
                                <span
                                    class="text-[0.71875rem] font-semibold text-[var(--ui-ink-3)]"
                                    >{{ documentTypeLabel }}</span
                                >
                                <span
                                    class="text-[1.375rem] font-[650] tracking-[-0.02em] text-[var(--ui-ink-3)]"
                                    >Sem número</span
                                >
                                <span
                                    class="text-[0.6875rem] text-[var(--ui-ink-3)]"
                                    >Atribuído ao emitir</span
                                >
                            </div>
                        </div>

                        <div
                            v-if="flashSuccess"
                            class="mx-5 mb-5 flex items-start gap-3 rounded-lg bg-[var(--ui-positive-wash)] p-3.5 text-[0.8125rem] text-[var(--ui-positive)] sm:mx-6"
                            role="status"
                        >
                            <Check
                                class="mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <div>
                                <p class="font-semibold">Rascunho seguro</p>
                                <p class="mt-0.5 opacity-80">
                                    {{ flashSuccess }}
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="flashError || hasErrors"
                            ref="errorBanner"
                            tabindex="-1"
                            class="mx-5 mb-5 scroll-mt-32 rounded-lg bg-[var(--ui-critical-wash)] p-3.5 text-[0.8125rem] text-[var(--ui-critical)] focus-ring sm:mx-6"
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
                                            form.errors.revision ??
                                            'O rascunho não foi guardado porque há dados incompletos ou incompatíveis.'
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            class="grid"
                            :class="
                                showDocumentDetails ? '2xl:grid-cols-2' : ''
                            "
                        >
                            <section class="paper-block">
                                <div
                                    class="flex items-center justify-between gap-3 px-5 sm:px-6"
                                    :class="
                                        showDocumentDetails
                                            ? 'border-b border-[var(--ui-line)] py-5'
                                            : 'py-3'
                                    "
                                >
                                    <SectionHeading :icon="ReceiptText">
                                        Dados do documento
                                        <template #description>
                                            {{ documentDetailsSummary }}
                                        </template></SectionHeading
                                    >
                                    <AppButton
                                        variant="outline"
                                        size="lg"
                                        v-if="!documentDetailsRequired"
                                        :aria-expanded="showDocumentDetails"
                                        aria-controls="document-details"
                                        @click="
                                            documentDetailsOpen =
                                                !documentDetailsOpen
                                        "
                                        class="shrink-0"
                                    >
                                        {{
                                            documentDetailsOpen
                                                ? 'Esconder'
                                                : 'Alterar'
                                        }}
                                    </AppButton>
                                </div>

                                <div
                                    v-show="showDocumentDetails"
                                    id="document-details"
                                    class="grid grid-cols-1 gap-6 p-5 sm:grid-cols-6 sm:p-6"
                                >
                                    <FormField
                                        class="sm:col-span-3"
                                        for="document-type"
                                        label="Tipo de documento"
                                        :error="form.errors.document_type"
                                        :error-id="errorId('document_type')"
                                        ><SelectInput
                                            id="document-type"
                                            :invalid="invalid('document_type')"
                                            :describedby="
                                                describedBy('', 'document_type')
                                            "
                                            v-model="form.document_type"
                                            :options="documentTypeOptions"
                                    /></FormField>

                                    <FormField
                                        v-if="isAdjustment"
                                        class="sm:col-span-3"
                                        for="references-document"
                                        label="Documento corrigido"
                                        :error="
                                            form.errors
                                                .references_document_public_id
                                        "
                                        :error-id="
                                            errorId(
                                                'references_document_public_id',
                                            )
                                        "
                                        ><p
                                            v-if="
                                                adjustableDocuments.length === 0
                                            "
                                            class="rounded-xl bg-amber-50 p-3 text-xs/5 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                                        >
                                            Ainda não há facturas emitidas para
                                            corrigir. Só é possível emitir uma
                                            nota depois de a factura original
                                            ter seguido para a AGT.
                                        </p>
                                        <SelectInput
                                            v-else
                                            id="references-document"
                                            :invalid="
                                                invalid(
                                                    'references_document_public_id',
                                                )
                                            "
                                            :describedby="
                                                describedBy(
                                                    '',
                                                    'references_document_public_id',
                                                )
                                            "
                                            v-model="
                                                form.references_document_public_id
                                            "
                                            class="mt-2"
                                            placeholder="Escolher a factura…"
                                            :options="adjustableOptions"
                                            @change="
                                                applyReferencedDocument($event)
                                            "
                                    /></FormField>

                                    <div
                                        v-if="isAdjustment"
                                        class="sm:col-span-6"
                                    >
                                        <label
                                            for="adjustment-reason"
                                            class="field-label"
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
                                            :aria-invalid="
                                                invalid('adjustment_reason')
                                            "
                                            :aria-describedby="
                                                describedBy(
                                                    '',
                                                    'adjustment_reason',
                                                )
                                            "
                                            v-model="form.adjustment_reason"
                                            type="text"
                                            maxlength="200"
                                            placeholder="Ex.: devolução parcial da mercadoria"
                                            class="mt-2 block form-input"
                                        />
                                        <p class="field-hint">
                                            A AGT exige um motivo em cada
                                            documento de correcção.
                                        </p>
                                        <FormError
                                            :id="errorId('adjustment_reason')"
                                            :message="
                                                form.errors.adjustment_reason
                                            "
                                        />
                                    </div>

                                    <FormField
                                        v-if="isReceipt"
                                        class="sm:col-span-3"
                                        for="payment-method"
                                        label="Meio de pagamento"
                                        :error="form.errors.payment_method"
                                        :error-id="errorId('payment_method')"
                                        ><SelectInput
                                            id="payment-method"
                                            :invalid="invalid('payment_method')"
                                            :describedby="
                                                describedBy(
                                                    '',
                                                    'payment_method',
                                                )
                                            "
                                            v-model="form.payment_method"
                                            :options="paymentMethodOptions"
                                    /></FormField>

                                    <FormField
                                        v-if="isReceipt"
                                        class="sm:col-span-3"
                                        for="payment-date"
                                        label="Data do pagamento"
                                        :error="form.errors.payment_date"
                                        :error-id="errorId('payment_date')"
                                        ><DateInput
                                            id="payment-date"
                                            :invalid="invalid('payment_date')"
                                            :describedby="
                                                describedBy('', 'payment_date')
                                            "
                                            v-model="form.payment_date"
                                            :clearable="false"
                                            :max-date="today"
                                            aria-label="Data do pagamento"
                                    /></FormField>

                                    <FormField
                                        class="sm:col-span-3"
                                        for="establishment"
                                        label="Local de emissão"
                                        :error="
                                            form.errors.establishment_public_id
                                        "
                                        :error-id="
                                            errorId('establishment_public_id')
                                        "
                                        ><SelectInput
                                            id="establishment"
                                            :invalid="
                                                invalid(
                                                    'establishment_public_id',
                                                )
                                            "
                                            :describedby="
                                                describedBy(
                                                    '',
                                                    'establishment_public_id',
                                                )
                                            "
                                            v-model="
                                                form.establishment_public_id
                                            "
                                            placeholder="Seleccione um local"
                                            :options="establishmentOptions"
                                    /></FormField>

                                    <FormField
                                        class="sm:col-span-3"
                                        for="currency"
                                        label="Moeda"
                                        :error="form.errors.currency_code"
                                        :error-id="errorId('currency_code')"
                                        ><SelectInput
                                            id="currency"
                                            :invalid="invalid('currency_code')"
                                            :describedby="
                                                describedBy('', 'currency_code')
                                            "
                                            v-model="form.currency_code"
                                            :options="currencyOptions"
                                    /></FormField>

                                    <FormField
                                        v-if="isForeignCurrency"
                                        class="sm:col-span-3"
                                        for="exchange-rate"
                                        label="Câmbio"
                                        :error="form.errors.exchange_rate"
                                        :error-id="errorId('exchange_rate')"
                                        ><input
                                            id="exchange-rate"
                                            :aria-invalid="
                                                invalid('exchange_rate')
                                            "
                                            :aria-describedby="
                                                describedBy('', 'exchange_rate')
                                            "
                                            v-model="form.exchange_rate"
                                            inputmode="decimal"
                                            class="block form-input numeric"
                                        />
                                        <p class="field-hint">
                                            Kwanzas por 1
                                            {{ form.currency_code }}. É este o
                                            valor que a AGT recebe convertido.
                                        </p></FormField
                                    >

                                    <FormField
                                        class="sm:col-span-3"
                                        for="document-date"
                                        label="Data do documento"
                                        :error="form.errors.document_date"
                                        :error-id="errorId('document_date')"
                                        ><DateInput
                                            id="document-date"
                                            :invalid="invalid('document_date')"
                                            :describedby="
                                                describedBy('', 'document_date')
                                            "
                                            v-model="form.document_date"
                                            :clearable="false"
                                            :max-date="today"
                                            aria-label="Data do documento"
                                    /></FormField>

                                    <FormField
                                        class="sm:col-span-3"
                                        for="due-date"
                                        label="Data de vencimento"
                                        optional
                                        :error="form.errors.due_date"
                                        :error-id="errorId('due_date')"
                                        ><DateInput
                                            id="due-date"
                                            :invalid="invalid('due_date')"
                                            :describedby="
                                                describedBy('', 'due_date')
                                            "
                                            v-model="form.due_date"
                                            :min-date="form.document_date"
                                            aria-label="Data de vencimento"
                                    /></FormField>
                                    <FormField
                                        v-if="
                                            !['RG', 'NC'].includes(
                                                form.document_type,
                                            )
                                        "
                                        class="sm:col-span-3"
                                        for="goods-available-at"
                                        label="Disponibilização dos bens / serviços"
                                        :error="form.errors.goods_available_at"
                                        ><DateInput
                                            id="goods-available-at"
                                            v-model="form.goods_available_at"
                                            mode="datetime"
                                            describedby="availability-help"
                                        />
                                        <p
                                            id="availability-help"
                                            class="mt-1 text-xs text-zinc-500"
                                        >
                                            Data e hora da operação, em Angola.
                                            Pode diferir da emissão.
                                        </p></FormField
                                    >
                                    <FormField
                                        v-if="
                                            !['RG', 'NC'].includes(
                                                form.document_type,
                                            )
                                        "
                                        class="sm:col-span-3"
                                        for="goods-available-location"
                                        label="Local de disponibilização"
                                        :error="
                                            form.errors.goods_available_location
                                        "
                                        ><input
                                            id="goods-available-location"
                                            v-model="
                                                form.goods_available_location
                                            "
                                            type="text"
                                            maxlength="255"
                                            class="block form-input"
                                    /></FormField>
                                </div>
                            </section>

                            <section class="@container paper-block">
                                <div
                                    class="flex flex-col gap-4 border-b border-[var(--ui-line)] px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-6"
                                >
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="grid size-8 shrink-0 place-items-center rounded-lg bg-[var(--ui-well)] text-[var(--ui-ink-2)]"
                                        >
                                            <UserRound
                                                class="size-5"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <div>
                                            <h2
                                                class="text-sm font-semibold text-[var(--ui-ink)]"
                                            >
                                                Cliente
                                            </h2>
                                            <p
                                                class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                            >
                                                O nome e o NIF ficam registados
                                                no rascunho.
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
                                    class="grid grid-cols-1 gap-6 p-5 sm:grid-cols-6 sm:p-6 @4xl:grid-cols-12 @4xl:gap-4"
                                >
                                    <FormField
                                        class="sm:col-span-6 @4xl:col-span-4"
                                        for="saved-customer"
                                        label="Procurar nos clientes guardados"
                                        :error="form.errors.customer_public_id"
                                        :error-id="
                                            errorId('customer_public_id')
                                        "
                                        ><SelectInput
                                            id="saved-customer"
                                            :invalid="
                                                invalid('customer_public_id')
                                            "
                                            :describedby="
                                                describedBy(
                                                    '',
                                                    'customer_public_id',
                                                )
                                            "
                                            v-model="form.customer_public_id"
                                            :options="customerOptions"
                                            @change="selectCustomer"
                                    /></FormField>

                                    <FormField
                                        class="sm:col-span-4 @4xl:col-span-5"
                                        for="customer-name"
                                        label="Nome ou denominação"
                                        :error="errorFor('customer.name')"
                                        :error-id="errorId('customer.name')"
                                        ><input
                                            id="customer-name"
                                            :aria-invalid="
                                                invalid('customer.name')
                                            "
                                            :aria-describedby="
                                                describedBy('', 'customer.name')
                                            "
                                            v-model="form.customer.name"
                                            :readonly="
                                                Boolean(selectedCustomer)
                                            "
                                            autocomplete="off"
                                            class="block form-input"
                                    /></FormField>

                                    <FormField
                                        class="sm:col-span-2 @4xl:col-span-3"
                                        for="customer-nif"
                                        label="NIF"
                                        :error="
                                            errorFor(
                                                'customer.tax_identification_number',
                                            )
                                        "
                                        :error-id="
                                            errorId(
                                                'customer.tax_identification_number',
                                            )
                                        "
                                        ><input
                                            id="customer-nif"
                                            autocomplete="off"
                                            autocapitalize="characters"
                                            spellcheck="false"
                                            :aria-invalid="
                                                invalid(
                                                    'customer.tax_identification_number',
                                                )
                                            "
                                            :aria-describedby="
                                                describedBy(
                                                    '',
                                                    'customer.tax_identification_number',
                                                )
                                            "
                                            v-model="
                                                form.customer
                                                    .tax_identification_number
                                            "
                                            :readonly="
                                                Boolean(selectedCustomer)
                                            "
                                            class="block form-input font-mono uppercase"
                                    /></FormField>

                                    <FormField
                                        class="sm:col-span-2 @4xl:col-span-2"
                                        for="customer-country"
                                        label="País"
                                        :error="
                                            errorFor('customer.country_code')
                                        "
                                        :error-id="
                                            errorId('customer.country_code')
                                        "
                                        ><input
                                            id="customer-country"
                                            autocomplete="off"
                                            autocapitalize="characters"
                                            :aria-invalid="
                                                invalid('customer.country_code')
                                            "
                                            :aria-describedby="
                                                describedBy(
                                                    '',
                                                    'customer.country_code',
                                                )
                                            "
                                            v-model="form.customer.country_code"
                                            :readonly="
                                                Boolean(selectedCustomer)
                                            "
                                            maxlength="2"
                                            class="block form-input font-mono uppercase"
                                    /></FormField>

                                    <FormField
                                        class="sm:col-span-4 @4xl:col-span-10"
                                        for="customer-address"
                                        label="Morada"
                                        :error="
                                            errorFor('customer.address_line')
                                        "
                                        :error-id="
                                            errorId('customer.address_line')
                                        "
                                        ><input
                                            id="customer-address"
                                            :aria-invalid="
                                                invalid('customer.address_line')
                                            "
                                            :aria-describedby="
                                                describedBy(
                                                    '',
                                                    'customer.address_line',
                                                )
                                            "
                                            v-model="form.customer.address_line"
                                            :readonly="
                                                Boolean(selectedCustomer)
                                            "
                                            autocomplete="off"
                                            class="block form-input"
                                    /></FormField>
                                </div>
                            </section>
                        </div>

                        <!-- A standalone receipt carries no goods: it pays off invoices. -->
                        <section
                            v-if="settlesOtherDocuments"
                            class="paper-block"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-4 border-b border-[var(--ui-line)] px-5 py-5 sm:px-6"
                            >
                                <div>
                                    <h2
                                        class="text-sm font-semibold text-[var(--ui-ink)]"
                                    >
                                        Facturas liquidadas
                                    </h2>
                                    <p
                                        class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        Escolha o que este recibo paga. O valor
                                        não pode exceder o que ainda falta em
                                        cada factura.
                                    </p>
                                </div>
                                <div
                                    class="flex shrink-0 flex-wrap items-center gap-2"
                                >
                                    <AppButton
                                        variant="outline"
                                        v-if="outstandingForCustomer.length > 1"
                                        @click="settleEverythingOutstanding"
                                    >
                                        <ListChecks
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Liquidar tudo ({{
                                            outstandingForCustomer.length
                                        }})
                                    </AppButton>
                                    <AppButton
                                        variant="outline"
                                        id="add-settlement"
                                        :disabled="
                                            settleableOptions.length === 0
                                        "
                                        @click="addSettlement"
                                    >
                                        <Plus
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Adicionar factura
                                    </AppButton>
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
                                    <FormField
                                        :for="`settlement-doc-${index}`"
                                        label="Factura"
                                        :error="
                                            errorFor(
                                                `settlements.${index}.document_public_id`,
                                            )
                                        "
                                        :error-id="
                                            errorId(
                                                `settlements.${index}.document_public_id`,
                                            )
                                        "
                                        ><SelectInput
                                            :id="`settlement-doc-${index}`"
                                            :invalid="
                                                invalid(
                                                    `settlements.${index}.document_public_id`,
                                                )
                                            "
                                            :describedby="
                                                describedBy(
                                                    '',
                                                    `settlements.${index}.document_public_id`,
                                                )
                                            "
                                            v-model="row.document_public_id"
                                            placeholder="Escolher a factura…"
                                            :options="settleableOptions"
                                            @change="
                                                applySettlementDocument(
                                                    index,
                                                    $event,
                                                )
                                            "
                                    /></FormField>

                                    <FormField
                                        :for="`settlement-amount-${index}`"
                                        label="Valor liquidado"
                                        :error="
                                            errorFor(
                                                `settlements.${index}.amount`,
                                            )
                                        "
                                        :error-id="
                                            errorId(
                                                `settlements.${index}.amount`,
                                            )
                                        "
                                        ><input
                                            :id="`settlement-amount-${index}`"
                                            :aria-invalid="
                                                invalid(
                                                    `settlements.${index}.amount`,
                                                )
                                            "
                                            :aria-describedby="
                                                describedBy(
                                                    '',
                                                    `settlements.${index}.amount`,
                                                )
                                            "
                                            v-model="row.amount"
                                            type="text"
                                            inputmode="decimal"
                                            class="block form-input text-right font-mono numeric"
                                    /></FormField>

                                    <button
                                        :id="`remove-settlement-${index}`"
                                        type="button"
                                        class="icon-button justify-self-start text-zinc-500 focus-ring hover:bg-rose-50 hover:text-rose-700 sm:justify-self-auto dark:hover:bg-rose-400/10 dark:hover:text-rose-400"
                                        @click="removeSettlement(index)"
                                    >
                                        <span class="sr-only"
                                            >Remover factura
                                            {{ index + 1 }}</span
                                        >
                                        <Trash2
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </li>
                            </ul>

                            <div
                                v-if="form.settlements.length > 0"
                                class="flex items-center justify-between border-t border-[var(--ui-line)] px-5 py-4 sm:px-6"
                            >
                                <span
                                    class="text-sm font-medium text-zinc-600 dark:text-zinc-300"
                                    >Total do recibo</span
                                >
                                <span
                                    class="numeric text-lg font-semibold text-zinc-950 dark:text-white"
                                    >{{
                                        formatMoney(BigInt(settlementTotal))
                                    }}</span
                                >
                            </div>
                            <FormError
                                :id="errorId('settlements')"
                                :message="form.errors.settlements"
                            />
                        </section>

                        <FormError
                            id="error-lines"
                            :message="form.errors.lines"
                        />

                        <section v-if="requiresLines" class="paper-block">
                            <div
                                class="flex items-center justify-between gap-4 border-b border-[var(--ui-line)] px-5 py-5 sm:px-6"
                            >
                                <div>
                                    <h2
                                        class="text-sm font-semibold text-[var(--ui-ink)]"
                                    >
                                        Bens e serviços
                                    </h2>
                                    <p
                                        class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        Quantidades, descontos e impostos são
                                        calculados por linha.
                                    </p>
                                    <p
                                        v-if="requiresLineOperationDate"
                                        class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300"
                                    >
                                        A AGT exige a data da operação em cada
                                        linha de uma factura genérica.
                                    </p>
                                </div>
                                <AppButton
                                    variant="outline"
                                    id="add-line"
                                    @click="addLine"
                                    class="shrink-0"
                                >
                                    <Plus class="size-4" aria-hidden="true" />
                                    <span class="hidden sm:inline"
                                        >Adicionar linha</span
                                    >
                                    <span class="sm:hidden">Adicionar</span>
                                </AppButton>
                            </div>

                            <FormField
                                v-if="catalogueItems.length > 0"
                                class="border-b border-[var(--ui-line)] px-5 py-4 sm:px-6"
                                for="typed-sale"
                                label="Escreva o que vendeu"
                                ><div class="flex gap-2">
                                    <input
                                        id="typed-sale"
                                        v-model="typedSale"
                                        type="text"
                                        autocomplete="off"
                                        spellcheck="false"
                                        placeholder="2 óleo, 1 arroz, 20 cesto a 950"
                                        class="form-input"
                                        @keydown.enter.prevent="addTypedSale"
                                    />
                                    <AppButton
                                        variant="outline"
                                        :disabled="!typedSaleReady"
                                        @click="addTypedSale"
                                    >
                                        Adicionar
                                    </AppButton>
                                </div>
                                <ul
                                    v-if="typedLines.length > 0"
                                    class="mt-3 flex flex-wrap gap-2"
                                    aria-label="O que percebemos"
                                >
                                    <li
                                        v-for="(typed, index) in typedLines"
                                        :key="index"
                                        :class="[
                                            'inline-flex min-h-7 items-center rounded-full px-3 py-1 text-xs font-medium',
                                            typed.item
                                                ? 'bg-lime-300 text-lime-950'
                                                : 'bg-[#fde7ea] text-[#9a1b30]',
                                        ]"
                                    >
                                        <template v-if="typed.item">
                                            {{ typed.quantity }} ×
                                            {{ typed.item.name }}
                                            <span
                                                v-if="typed.unitPrice"
                                                class="ms-1"
                                                >a {{ typed.unitPrice }}</span
                                            >
                                        </template>
                                        <template v-else>
                                            «{{ typed.text }}»: não encontrado,
                                            ou há mais de um artigo assim
                                        </template>
                                    </li>
                                </ul></FormField
                            >

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
                                            :id="`mobile-remove-line-${line.key}`"
                                            type="button"
                                            :disabled="form.lines.length === 1"
                                            class="icon-button text-zinc-400 focus-ring transition hover:bg-rose-50 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-25 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
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
                                    </div>

                                    <FormError
                                        :id="errorId(`lines.${index}`, 'm-')"
                                        :message="errorFor(`lines.${index}`)"
                                    />

                                    <FormField
                                        v-if="catalogueItems.length > 0"
                                        :for="`mobile-catalogue-${line.key}`"
                                        label="Do catálogo"
                                        ><SelectInput
                                            :id="`mobile-catalogue-${line.key}`"
                                            v-model="line.catalogue_public_id"
                                            placeholder="Escolher do catálogo…"
                                            :options="catalogueOptions"
                                            @change="
                                                applyCatalogueItem(line, $event)
                                            "
                                    /></FormField>

                                    <div
                                        class="grid grid-cols-1 gap-4 sm:grid-cols-3"
                                    >
                                        <FormField
                                            class="sm:col-span-1"
                                            :for="`mobile-code-${line.key}`"
                                            label="Código"
                                            :error="
                                                errorFor(
                                                    `lines.${index}.product_code`,
                                                )
                                            "
                                            :error-id="
                                                errorId(
                                                    `lines.${index}.product_code`,
                                                    'm-',
                                                )
                                            "
                                            ><input
                                                :id="`mobile-code-${line.key}`"
                                                :aria-invalid="
                                                    invalid(
                                                        `lines.${index}.product_code`,
                                                    )
                                                "
                                                :aria-describedby="
                                                    describedBy(
                                                        'm-',
                                                        `lines.${index}.product_code`,
                                                    )
                                                "
                                                v-model="line.product_code"
                                                class="block form-input font-mono"
                                        /></FormField>
                                        <FormField
                                            class="sm:col-span-2"
                                            :for="`mobile-description-${line.key}`"
                                            label="Descrição"
                                            :error="
                                                errorFor(
                                                    `lines.${index}.product_description`,
                                                )
                                            "
                                            :error-id="
                                                errorId(
                                                    `lines.${index}.product_description`,
                                                    'm-',
                                                )
                                            "
                                            ><input
                                                :id="`mobile-description-${line.key}`"
                                                :aria-invalid="
                                                    invalid(
                                                        `lines.${index}.product_description`,
                                                    )
                                                "
                                                :aria-describedby="
                                                    describedBy(
                                                        'm-',
                                                        `lines.${index}.product_description`,
                                                    )
                                                "
                                                v-model="
                                                    line.product_description
                                                "
                                                class="block form-input"
                                        /></FormField>
                                    </div>

                                    <FormField
                                        :for="`mobile-operation-${line.key}`"
                                        label="Tipo de operação"
                                        :error="
                                            errorFor(
                                                `lines.${index}.operation_type`,
                                            )
                                        "
                                        :error-id="
                                            errorId(
                                                `lines.${index}.operation_type`,
                                                'm-',
                                            )
                                        "
                                        ><SelectInput
                                            :id="`mobile-operation-${line.key}`"
                                            :invalid="
                                                invalid(
                                                    `lines.${index}.operation_type`,
                                                )
                                            "
                                            :describedby="
                                                describedBy(
                                                    'm-',
                                                    `lines.${index}.operation_type`,
                                                )
                                            "
                                            v-model="line.operation_type"
                                            :options="operationTypeOptions"
                                    /></FormField>

                                    <FormField
                                        v-if="requiresLineOperationDate"
                                        :for="`mobile-operation-date-${line.key}`"
                                        label="Data da operação"
                                        :error="
                                            errorFor(
                                                `lines.${index}.operation_date`,
                                            )
                                        "
                                        :error-id="
                                            errorId(
                                                `lines.${index}.operation_date`,
                                                'm-',
                                            )
                                        "
                                        ><DateInput
                                            :id="`mobile-operation-date-${line.key}`"
                                            :invalid="
                                                invalid(
                                                    `lines.${index}.operation_date`,
                                                )
                                            "
                                            :describedby="
                                                describedBy(
                                                    'm-',
                                                    `lines.${index}.operation_date`,
                                                )
                                            "
                                            v-model="line.operation_date"
                                            :clearable="false"
                                            :max-date="form.document_date"
                                            :aria-label="`Data da operação da linha ${index + 1}`"
                                    /></FormField>

                                    <div class="grid grid-cols-2 gap-4">
                                        <FormField
                                            :for="`mobile-quantity-${line.key}`"
                                            label="Quantidade"
                                            :error="
                                                errorFor(
                                                    `lines.${index}.quantity`,
                                                )
                                            "
                                            :error-id="
                                                errorId(
                                                    `lines.${index}.quantity`,
                                                    'm-',
                                                )
                                            "
                                            ><input
                                                :id="`mobile-quantity-${line.key}`"
                                                :aria-invalid="
                                                    invalid(
                                                        `lines.${index}.quantity`,
                                                    )
                                                "
                                                :aria-describedby="
                                                    describedBy(
                                                        'm-',
                                                        `lines.${index}.quantity`,
                                                    )
                                                "
                                                v-model="line.quantity"
                                                type="text"
                                                inputmode="decimal"
                                                class="block form-input text-right font-mono"
                                        /></FormField>
                                        <FormField
                                            :for="`mobile-unit-${line.key}`"
                                            label="Unidade"
                                            :error="
                                                errorFor(
                                                    `lines.${index}.unit_of_measure`,
                                                )
                                            "
                                            :error-id="
                                                errorId(
                                                    `lines.${index}.unit_of_measure`,
                                                    'm-',
                                                )
                                            "
                                            ><input
                                                :id="`mobile-unit-${line.key}`"
                                                :aria-invalid="
                                                    invalid(
                                                        `lines.${index}.unit_of_measure`,
                                                    )
                                                "
                                                :aria-describedby="
                                                    describedBy(
                                                        'm-',
                                                        `lines.${index}.unit_of_measure`,
                                                    )
                                                "
                                                v-model="line.unit_of_measure"
                                                class="block form-input"
                                        /></FormField>
                                        <FormField
                                            :for="`mobile-price-${line.key}`"
                                            label="Preço unitário"
                                            :error="
                                                errorFor(
                                                    `lines.${index}.unit_price`,
                                                )
                                            "
                                            :error-id="
                                                errorId(
                                                    `lines.${index}.unit_price`,
                                                    'm-',
                                                )
                                            "
                                            ><div
                                                class="field-adorned [--field-adorn-end:2.25rem]"
                                            >
                                                <input
                                                    :id="`mobile-price-${line.key}`"
                                                    :aria-invalid="
                                                        invalid(
                                                            `lines.${index}.unit_price`,
                                                        )
                                                    "
                                                    :aria-describedby="
                                                        describedBy(
                                                            'm-',
                                                            `lines.${index}.unit_price`,
                                                        )
                                                    "
                                                    v-model="line.unit_price"
                                                    type="text"
                                                    inputmode="decimal"
                                                    class="block form-input form-input-sm text-right font-mono text-sm"
                                                />
                                                <span
                                                    class="field-adornment-end"
                                                    >Kz</span
                                                >
                                            </div></FormField
                                        >
                                        <FormField
                                            :for="`mobile-discount-${line.key}`"
                                            label="Desconto"
                                            :error="
                                                errorFor(
                                                    `lines.${index}.discount_percentage`,
                                                )
                                            "
                                            :error-id="
                                                errorId(
                                                    `lines.${index}.discount_percentage`,
                                                    'm-',
                                                )
                                            "
                                            ><div
                                                class="field-adorned [--field-adorn-end:2rem]"
                                            >
                                                <input
                                                    :id="`mobile-discount-${line.key}`"
                                                    :aria-invalid="
                                                        invalid(
                                                            `lines.${index}.discount_percentage`,
                                                        )
                                                    "
                                                    :aria-describedby="
                                                        describedBy(
                                                            'm-',
                                                            `lines.${index}.discount_percentage`,
                                                        )
                                                    "
                                                    v-model="
                                                        line.discount_percentage
                                                    "
                                                    type="text"
                                                    inputmode="decimal"
                                                    class="block form-input form-input-sm text-right font-mono text-sm"
                                                />
                                                <span
                                                    class="field-adornment-end"
                                                    >%</span
                                                >
                                            </div></FormField
                                        >
                                    </div>

                                    <div>
                                        <label
                                            :for="`mobile-tax-${line.key}`"
                                            class="field-label"
                                        >
                                            Tratamento fiscal
                                        </label>
                                        <SelectInput
                                            :id="`mobile-tax-${line.key}`"
                                            :invalid="
                                                invalid(
                                                    `lines.${index}.tax`,
                                                    `lines.${index}.tax.percentage`,
                                                    `lines.${index}.tax.exemption_code`,
                                                )
                                            "
                                            :describedby="
                                                describedBy(
                                                    'm-',
                                                    `lines.${index}.tax`,
                                                    `lines.${index}.tax.percentage`,
                                                    `lines.${index}.tax.exemption_code`,
                                                )
                                            "
                                            v-model="line.tax_treatment"
                                            class="mt-2"
                                            :options="taxTreatmentOptions"
                                        />
                                        <FormError
                                            :id="
                                                errorId(
                                                    `lines.${index}.tax`,
                                                    'm-',
                                                )
                                            "
                                            :message="
                                                errorFor(`lines.${index}.tax`)
                                            "
                                        />
                                        <FormError
                                            :id="
                                                errorId(
                                                    `lines.${index}.tax.percentage`,
                                                    'm-',
                                                )
                                            "
                                            :message="
                                                errorFor(
                                                    `lines.${index}.tax.percentage`,
                                                )
                                            "
                                        />
                                        <FormError
                                            :id="
                                                errorId(
                                                    `lines.${index}.tax.exemption_code`,
                                                    'm-',
                                                )
                                            "
                                            :message="
                                                errorFor(
                                                    `lines.${index}.tax.exemption_code`,
                                                )
                                            "
                                        />
                                    </div>

                                    <dl
                                        class="grid grid-cols-2 gap-3 rounded-xl bg-stone-50 p-4 text-xs sm:grid-cols-3 dark:bg-white/[0.03]"
                                    >
                                        <div>
                                            <dt
                                                class="text-zinc-500 dark:text-zinc-400"
                                            >
                                                Líquido
                                            </dt>
                                            <dd
                                                class="mt-1 font-mono numeric font-semibold whitespace-nowrap text-zinc-950 dark:text-white"
                                            >
                                                {{
                                                    formatMoney(
                                                        lineCalculations[index]
                                                            ?.net ?? 0n,
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
                                                class="mt-1 font-mono numeric font-semibold whitespace-nowrap text-zinc-950 dark:text-white"
                                            >
                                                {{
                                                    formatMoney(
                                                        lineCalculations[index]
                                                            ?.tax ?? 0n,
                                                    )
                                                }}
                                            </dd>
                                        </div>
                                        <div
                                            class="col-span-2 sm:col-span-1 sm:text-right"
                                        >
                                            <dt
                                                class="text-zinc-500 dark:text-zinc-400"
                                            >
                                                Total
                                            </dt>
                                            <dd
                                                class="mt-1 font-mono numeric font-bold whitespace-nowrap text-brand-700 dark:text-brand-300"
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
                                    <thead
                                        class="bg-stone-50 dark:bg-white/[0.03]"
                                    >
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
                                            <th
                                                class="w-14 py-3 pr-5 pl-3 sm:pr-6"
                                            >
                                                <span class="sr-only"
                                                    >Acções</span
                                                >
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
                                                    v-if="
                                                        catalogueItems.length >
                                                        0
                                                    "
                                                    v-model="
                                                        line.catalogue_public_id
                                                    "
                                                    class="mb-2"
                                                    size="sm"
                                                    placeholder="Escolher do catálogo…"
                                                    :id="`catalogue-${line.key}`"
                                                    :aria-label="`Artigo do catálogo para a linha ${index + 1}`"
                                                    :options="catalogueOptions"
                                                    @change="
                                                        applyCatalogueItem(
                                                            line,
                                                            $event,
                                                        )
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
                                                            :aria-invalid="
                                                                invalid(
                                                                    `lines.${index}`,
                                                                    `lines.${index}.product_code`,
                                                                )
                                                            "
                                                            :aria-describedby="
                                                                describedBy(
                                                                    '',
                                                                    `lines.${index}`,
                                                                    `lines.${index}.product_code`,
                                                                )
                                                            "
                                                            v-model="
                                                                line.product_code
                                                            "
                                                            placeholder="Código"
                                                            class="block form-input form-input-sm font-mono"
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
                                                            :aria-invalid="
                                                                invalid(
                                                                    `lines.${index}.product_description`,
                                                                )
                                                            "
                                                            :aria-describedby="
                                                                describedBy(
                                                                    '',
                                                                    `lines.${index}.product_description`,
                                                                )
                                                            "
                                                            v-model="
                                                                line.product_description
                                                            "
                                                            placeholder="Descrição do artigo ou serviço"
                                                            class="block form-input form-input-sm text-sm"
                                                        />
                                                    </div>
                                                </div>
                                                <SelectInput
                                                    v-model="
                                                        line.operation_type
                                                    "
                                                    class="mt-2"
                                                    size="sm"
                                                    :aria-label="`Tipo de operação da linha ${index + 1}`"
                                                    :invalid="
                                                        invalid(
                                                            `lines.${index}.operation_type`,
                                                        )
                                                    "
                                                    :describedby="
                                                        describedBy(
                                                            '',
                                                            `lines.${index}.operation_type`,
                                                        )
                                                    "
                                                    :options="
                                                        operationTypeOptions
                                                    "
                                                />
                                                <div
                                                    v-if="
                                                        requiresLineOperationDate
                                                    "
                                                    class="mt-2"
                                                >
                                                    <label
                                                        :for="`operation-date-${line.key}`"
                                                        class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400"
                                                    >
                                                        Data da operação
                                                    </label>
                                                    <DateInput
                                                        :id="`operation-date-${line.key}`"
                                                        :invalid="
                                                            invalid(
                                                                `lines.${index}.operation_date`,
                                                            )
                                                        "
                                                        :describedby="
                                                            describedBy(
                                                                '',
                                                                `lines.${index}.operation_date`,
                                                            )
                                                        "
                                                        v-model="
                                                            line.operation_date
                                                        "
                                                        :clearable="false"
                                                        :max-date="
                                                            form.document_date
                                                        "
                                                        :aria-label="`Data da operação da linha ${index + 1}`"
                                                    />
                                                </div>
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}`,
                                                        )
                                                    "
                                                />
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.product_code`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}.product_code`,
                                                        )
                                                    "
                                                />
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.product_description`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}.product_description`,
                                                        )
                                                    "
                                                />
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.operation_type`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}.operation_type`,
                                                        )
                                                    "
                                                />
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.operation_date`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}.operation_date`,
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
                                                    :aria-invalid="
                                                        invalid(
                                                            `lines.${index}.quantity`,
                                                        )
                                                    "
                                                    :aria-describedby="
                                                        describedBy(
                                                            '',
                                                            `lines.${index}.quantity`,
                                                        )
                                                    "
                                                    class="block form-input form-input-sm text-right font-mono text-sm"
                                                />
                                                <input
                                                    v-model="
                                                        line.unit_of_measure
                                                    "
                                                    :aria-label="`Unidade da linha ${index + 1}`"
                                                    :aria-invalid="
                                                        invalid(
                                                            `lines.${index}.unit_of_measure`,
                                                        )
                                                    "
                                                    :aria-describedby="
                                                        describedBy(
                                                            '',
                                                            `lines.${index}.unit_of_measure`,
                                                        )
                                                    "
                                                    placeholder="un"
                                                    class="mt-2 block form-input form-input-sm"
                                                />
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.quantity`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}.quantity`,
                                                        )
                                                    "
                                                />
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.unit_of_measure`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}.unit_of_measure`,
                                                        )
                                                    "
                                                />
                                            </td>
                                            <td class="px-3 py-4">
                                                <div
                                                    class="field-adorned [--field-adorn-end:2rem]"
                                                >
                                                    <input
                                                        v-model="
                                                            line.unit_price
                                                        "
                                                        type="text"
                                                        inputmode="decimal"
                                                        :aria-label="`Preço unitário da linha ${index + 1}`"
                                                        :aria-invalid="
                                                            invalid(
                                                                `lines.${index}.unit_price`,
                                                            )
                                                        "
                                                        :aria-describedby="
                                                            describedBy(
                                                                '',
                                                                `lines.${index}.unit_price`,
                                                            )
                                                        "
                                                        class="block form-input form-input-sm text-right font-mono text-sm"
                                                    />
                                                    <span
                                                        class="field-adornment-end"
                                                        >Kz</span
                                                    >
                                                </div>
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.unit_price`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}.unit_price`,
                                                        )
                                                    "
                                                />
                                            </td>
                                            <td class="px-3 py-4">
                                                <div
                                                    class="field-adorned [--field-adorn-end:1.75rem]"
                                                >
                                                    <input
                                                        v-model="
                                                            line.discount_percentage
                                                        "
                                                        type="text"
                                                        inputmode="decimal"
                                                        :aria-label="`Desconto da linha ${index + 1}`"
                                                        :aria-invalid="
                                                            invalid(
                                                                `lines.${index}.discount_percentage`,
                                                            )
                                                        "
                                                        :aria-describedby="
                                                            describedBy(
                                                                '',
                                                                `lines.${index}.discount_percentage`,
                                                            )
                                                        "
                                                        class="block form-input form-input-sm text-right font-mono text-sm"
                                                    />
                                                    <span
                                                        class="field-adornment-end"
                                                        >%</span
                                                    >
                                                </div>
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.discount_percentage`,
                                                        )
                                                    "
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
                                                    :invalid="
                                                        invalid(
                                                            `lines.${index}.tax`,
                                                            `lines.${index}.tax.percentage`,
                                                            `lines.${index}.tax.exemption_code`,
                                                        )
                                                    "
                                                    :describedby="
                                                        describedBy(
                                                            '',
                                                            `lines.${index}.tax`,
                                                            `lines.${index}.tax.percentage`,
                                                            `lines.${index}.tax.exemption_code`,
                                                        )
                                                    "
                                                    :options="
                                                        taxTreatmentOptions
                                                    "
                                                />
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.tax`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}.tax`,
                                                        )
                                                    "
                                                />
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.tax.percentage`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}.tax.percentage`,
                                                        )
                                                    "
                                                />
                                                <FormError
                                                    :id="
                                                        errorId(
                                                            `lines.${index}.tax.exemption_code`,
                                                        )
                                                    "
                                                    :message="
                                                        errorFor(
                                                            `lines.${index}.tax.exemption_code`,
                                                        )
                                                    "
                                                />
                                            </td>
                                            <td class="px-3 py-4 text-right">
                                                <p
                                                    class="font-mono numeric text-sm font-semibold whitespace-nowrap text-zinc-950 dark:text-white"
                                                >
                                                    {{
                                                        formatMoney(
                                                            lineCalculations[
                                                                index
                                                            ]?.gross ?? 0n,
                                                        )
                                                    }}
                                                </p>
                                                <p
                                                    class="mt-1 numeric text-xs whitespace-nowrap text-zinc-500 dark:text-zinc-400"
                                                >
                                                    IVA
                                                    {{
                                                        formatMoney(
                                                            lineCalculations[
                                                                index
                                                            ]?.tax ?? 0n,
                                                        )
                                                    }}
                                                </p>
                                            </td>
                                            <td
                                                class="py-4 pr-5 pl-3 text-right sm:pr-6"
                                            >
                                                <button
                                                    :id="`remove-line-${line.key}`"
                                                    type="button"
                                                    :disabled="
                                                        form.lines.length === 1
                                                    "
                                                    class="icon-button text-zinc-400 focus-ring transition hover:bg-rose-50 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-25 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
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
                                    <tfoot
                                        class="bg-stone-50 dark:bg-white/[0.03]"
                                    >
                                        <tr>
                                            <th
                                                colspan="5"
                                                class="px-5 py-3 text-right text-sm font-semibold text-zinc-700 sm:px-6 dark:text-zinc-300"
                                            >
                                                Total do documento
                                            </th>
                                            <td
                                                class="px-3 py-3 text-right font-mono numeric text-sm font-bold whitespace-nowrap text-zinc-950 dark:text-white"
                                            >
                                                {{ formatMoney(totals.gross) }}
                                            </td>
                                            <td />
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <div
                                v-if="isAdjustment"
                                class="space-y-4 border-t border-[var(--ui-line)] px-5 py-4 sm:px-6"
                            >
                                <h3 class="text-sm font-semibold">
                                    Linhas do documento original
                                </h3>
                                <p class="text-sm text-zinc-500">
                                    Indique que linha do documento original está
                                    a corrigir em cada linha desta nota.
                                </p>
                                <div
                                    v-for="(line, index) in form.lines"
                                    :key="line.key"
                                >
                                    <label
                                        :for="`reference-line-${line.key}`"
                                        class="text-sm font-medium"
                                        >Linha {{ index + 1 }} ·
                                        {{
                                            line.product_description ||
                                            'Artigo ou serviço'
                                        }}</label
                                    >
                                    <SelectInput
                                        :id="`reference-line-${line.key}`"
                                        v-model="line.reference_line_number"
                                        :options="referenceLineOptions"
                                        placeholder="Seleccione a linha original"
                                        class="mt-2"
                                        :aria-describedby="`reference-line-error-${line.key}`"
                                    />
                                    <FormError
                                        :id="`reference-line-error-${line.key}`"
                                        :message="
                                            form.errors[
                                                `lines.${index}.reference_line_number`
                                            ]
                                        "
                                    />
                                </div>
                            </div>

                            <div
                                class="border-t border-[var(--ui-line)] px-5 py-4 sm:px-6"
                            >
                                <AppButton
                                    variant="outline"
                                    id="add-line-end"
                                    @click="addLine"
                                >
                                    <Plus class="size-4" aria-hidden="true" />
                                    Adicionar linha
                                </AppButton>
                            </div>
                            <details
                                v-if="
                                    requiresLines &&
                                    [
                                        'FT',
                                        'FR',
                                        'FA',
                                        'FG',
                                        'GF',
                                        'TV',
                                    ].includes(form.document_type)
                                "
                                class="border-t border-[var(--ui-line)] px-5 py-4 sm:px-6"
                            >
                                <summary
                                    class="cursor-pointer text-sm font-semibold"
                                >
                                    Guias de origem dos artigos
                                </summary>
                                <p class="mt-2 text-sm text-zinc-500">
                                    Associe os artigos às guias que está a
                                    facturar, mesmo que parcialmente.
                                </p>
                                <label
                                    v-for="(line, index) in form.lines"
                                    :key="line.key"
                                    class="mt-3 block"
                                >
                                    <span class="mb-1 block text-sm"
                                        >{{ index + 1 }} ·
                                        {{
                                            line.product_description || 'Artigo'
                                        }}</span
                                    >
                                    <SelectInput
                                        v-model="
                                            line.origin_transport_line_public_id
                                        "
                                        :options="[
                                            {
                                                value: '',
                                                label: 'Sem guia de origem',
                                            },
                                            ...transportLines.filter(
                                                (option) =>
                                                    option.tax_id ===
                                                        form.customer
                                                            .tax_identification_number ||
                                                    option.value ===
                                                        line.origin_transport_line_public_id,
                                            ),
                                        ]"
                                    />
                                    <FormError
                                        :message="
                                            errorFor(
                                                `lines.${index}.origin_transport_line_public_id`,
                                            )
                                        "
                                    />
                                </label>
                            </details>
                        </section>

                        <!--
                    Optional, and empty for almost every document: only some
                    buyers — the State, large taxpayers, banks — keep tax back
                    and pay it to the AGT themselves.
                -->
                        <section v-if="requiresLines" class="paper-block">
                            <div
                                class="flex flex-wrap items-center justify-between gap-4 border-b border-[var(--ui-line)] px-5 py-5 sm:px-6"
                            >
                                <div>
                                    <h2
                                        class="text-sm font-semibold text-[var(--ui-ink)]"
                                    >
                                        Retenção na fonte e cativação
                                    </h2>
                                    <p
                                        class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        Imposto que o adquirente retém e entrega
                                        directamente à AGT. Informativo: não
                                        altera o total do documento.
                                    </p>
                                </div>
                                <AppButton
                                    variant="outline"
                                    id="add-withholding"
                                    :disabled="
                                        form.withholdings.length >=
                                        withholdingTypes.length
                                    "
                                    @click="addWithholding"
                                    class="shrink-0"
                                >
                                    <Plus class="size-4" aria-hidden="true" />
                                    Adicionar retenção
                                </AppButton>
                            </div>

                            <p
                                v-if="form.withholdings.length === 0"
                                class="px-5 py-6 text-sm text-zinc-500 sm:px-6 dark:text-zinc-400"
                            >
                                Nada retido. A maioria dos clientes paga o total
                                da factura; adicione uma retenção só quando o
                                adquirente a praticar.
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
                                    <FormField
                                        :for="`withholding-type-${index}`"
                                        label="Imposto retido"
                                        :error="
                                            errorFor(
                                                `withholdings.${index}.type`,
                                            )
                                        "
                                        :error-id="
                                            errorId(
                                                `withholdings.${index}.type`,
                                            )
                                        "
                                        ><SelectInput
                                            :id="`withholding-type-${index}`"
                                            :invalid="
                                                invalid(
                                                    `withholdings.${index}.type`,
                                                )
                                            "
                                            :describedby="
                                                describedBy(
                                                    '',
                                                    `withholdings.${index}.type`,
                                                )
                                            "
                                            :model-value="row.type"
                                            :options="withholdingTypeOptions"
                                            @update:model-value="
                                                (value: string) =>
                                                    changeWithholdingType(
                                                        index,
                                                        value,
                                                    )
                                            "
                                    /></FormField>

                                    <FormField
                                        :for="`withholding-rate-${index}`"
                                        label="Taxa (%)"
                                        :error="
                                            errorFor(
                                                `withholdings.${index}.rate_percentage`,
                                            )
                                        "
                                        :error-id="
                                            errorId(
                                                `withholdings.${index}.rate_percentage`,
                                            )
                                        "
                                        ><input
                                            :id="`withholding-rate-${index}`"
                                            :aria-invalid="
                                                invalid(
                                                    `withholdings.${index}.rate_percentage`,
                                                )
                                            "
                                            :aria-describedby="
                                                describedBy(
                                                    '',
                                                    `withholdings.${index}.rate_percentage`,
                                                )
                                            "
                                            v-model="row.rate_percentage"
                                            type="text"
                                            inputmode="decimal"
                                            class="block form-input text-right font-mono numeric"
                                    /></FormField>

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
                                                    withholdingPreview[index]
                                                        ?.amount ?? 0n,
                                                )
                                            }}
                                        </span>
                                        <span
                                            class="block text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            sobre
                                            {{
                                                formatMoney(
                                                    withholdingPreview[index]
                                                        ?.base ?? 0n,
                                                )
                                            }}
                                        </span>
                                    </div>

                                    <button
                                        :id="`remove-withholding-${index}`"
                                        type="button"
                                        class="icon-button justify-self-start text-zinc-500 focus-ring hover:bg-rose-50 hover:text-rose-700 sm:justify-self-auto dark:hover:bg-rose-400/10 dark:hover:text-rose-400"
                                        @click="removeWithholding(index)"
                                    >
                                        <span class="sr-only"
                                            >Remover retenção
                                            {{ index + 1 }}</span
                                        >
                                        <Trash2
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </li>
                            </ul>
                            <FormError
                                :id="errorId('withholdings')"
                                :message="form.errors.withholdings"
                            />
                        </section>

                        <section class="px-5 pt-6 paper-block sm:px-6">
                            <label
                                for="notes"
                                class="block text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                Observações
                                <span
                                    class="font-normal text-zinc-500 dark:text-zinc-400"
                                    >(opcional)</span
                                >
                            </label>
                            <textarea
                                id="notes"
                                :aria-invalid="invalid('notes')"
                                :aria-describedby="describedBy('', 'notes')"
                                v-model="form.notes"
                                rows="3"
                                placeholder="Instruções de entrega ou referência interna…"
                                class="mt-2 block form-input [--form-resize:vertical]"
                            />
                            <FormError
                                :id="errorId('notes')"
                                :message="form.errors.notes"
                            />
                        </section>
                    </div>
                </div>

                <aside
                    class="flex-1 bg-[var(--ui-desk)] px-2 pt-4 pb-8 sm:px-8 2xl:sticky 2xl:inset-bs-[6.25rem] 2xl:h-[calc(100dvh-6.25rem)] 2xl:w-[22.5rem] 2xl:flex-none 2xl:shrink-0 2xl:overflow-y-auto 2xl:overscroll-contain 2xl:bg-transparent 2xl:p-0 2xl:shadow-[inset_1px_0_0_var(--ui-line)]"
                    aria-label="Totais e emissão"
                >
                    <div
                        class="mx-auto flex max-w-[60rem] flex-col rounded-md bg-[var(--surface-raised)] shadow-[var(--ui-paper-shadow)] sm:flex-row 2xl:h-full 2xl:max-w-none 2xl:flex-col 2xl:rounded-none 2xl:bg-transparent 2xl:shadow-none"
                    >
                        <section
                            class="flex flex-1 flex-col gap-2 px-5 py-5 sm:border-e sm:border-[var(--ui-line)] 2xl:flex-none 2xl:border-e-0 2xl:border-b"
                            aria-labelledby="totals-title"
                        >
                            <h2
                                id="totals-title"
                                class="text-xs font-semibold text-[var(--ui-ink-3)]"
                            >
                                Totais em {{ form.currency_code }}
                            </h2>
                            <dl class="flex flex-col gap-2 text-[0.8125rem]">
                                <div
                                    v-if="
                                        !settlesOtherDocuments ||
                                        hasSavedReceiptTotals
                                    "
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-[var(--ui-ink-2)]">
                                        Subtotal
                                    </dt>
                                    <dd class="numeric text-[var(--ui-ink)]">
                                        {{ formatMoney(totals.net) }}
                                    </dd>
                                </div>
                                <div
                                    v-if="requiresLines"
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-[var(--ui-ink-2)]">
                                        Descontos
                                    </dt>
                                    <dd class="numeric text-[var(--ui-ink)]">
                                        − {{ formatMoney(totals.settlement) }}
                                    </dd>
                                </div>
                                <div
                                    v-if="
                                        !settlesOtherDocuments ||
                                        hasSavedReceiptTotals
                                    "
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-[var(--ui-ink-2)]">
                                        Impostos
                                    </dt>
                                    <dd class="numeric text-[var(--ui-ink)]">
                                        {{ formatMoney(totals.tax) }}
                                    </dd>
                                </div>
                                <div
                                    class="mt-1.5 flex items-baseline justify-between gap-4 border-t border-[var(--ui-ink)] pt-3"
                                >
                                    <dt
                                        class="font-semibold text-[var(--ui-ink)]"
                                    >
                                        {{
                                            settlesOtherDocuments
                                                ? 'Total do recibo'
                                                : 'Total a receber'
                                        }}
                                    </dt>
                                    <dd
                                        class="numeric text-2xl/none font-[650] tracking-[-0.03em] text-[var(--ui-ink)]"
                                    >
                                        {{ formatMoney(totals.gross) }}
                                    </dd>
                                </div>
                                <div
                                    v-if="isForeignCurrency"
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt class="text-[var(--ui-ink-2)]">
                                        Equivalente em AOA
                                    </dt>
                                    <dd class="numeric text-[var(--ui-ink)]">
                                        {{ formatMoney(grossInKwanzas) }}
                                    </dd>
                                </div>
                                <div
                                    v-if="withholdingTotal > 0n"
                                    class="flex items-center justify-between gap-4 border-t border-[var(--ui-line)] pt-2"
                                >
                                    <dt class="text-[var(--ui-ink-2)]">
                                        <span class="block"
                                            >Retido pelo adquirente</span
                                        >
                                        <span
                                            class="block text-xs text-[var(--ui-ink-3)]"
                                            >Entregue por ele à AGT</span
                                        >
                                    </dt>
                                    <dd class="numeric text-[var(--ui-ink)]">
                                        {{ formatMoney(withholdingTotal) }}
                                    </dd>
                                </div>
                            </dl>
                            <p
                                v-if="
                                    settlesOtherDocuments &&
                                    !hasSavedReceiptTotals
                                "
                                class="text-xs/5 text-[var(--ui-ink-3)]"
                            >
                                Guarde o recibo para calcular a repartição do
                                IVA e das retenções dos documentos de origem.
                            </p>
                            <p
                                class="text-[0.71875rem]/5 text-[var(--ui-ink-3)]"
                            >
                                Pré-visualização. Os valores finais são
                                recalculados no servidor antes de serem
                                guardados.
                            </p>
                        </section>

                        <span class="hidden flex-1 2xl:block" />

                        <section
                            class="flex flex-1 flex-col gap-3 border-t border-[var(--ui-line)] px-5 py-5 sm:border-t-0 2xl:flex-none 2xl:border-t"
                            aria-labelledby="issue-title"
                        >
                            <div>
                                <h2
                                    id="issue-title"
                                    class="text-[0.8125rem] font-semibold text-[var(--ui-ink)]"
                                >
                                    Emissão fiscal
                                </h2>
                                <p
                                    class="mt-0.5 text-xs/5 text-[var(--ui-ink-3)]"
                                >
                                    Atribui o número, assina e coloca a entrega
                                    na fila da AGT numa só operação.
                                </p>
                            </div>

                            <div v-if="compatibleSeries.length > 0">
                                <label
                                    for="fiscal-series"
                                    class="block text-xs font-medium text-[var(--ui-ink-2)]"
                                >
                                    Série autorizada
                                </label>
                                <SelectInput
                                    id="fiscal-series"
                                    v-model="issueForm.series_public_id"
                                    class="mt-1.5"
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
                                class="rounded-lg bg-[color-mix(in_oklch,var(--ui-warning)_10%,transparent)] px-3 py-2.5 text-xs/5 text-[var(--ui-ink)]"
                            >
                                {{ issueBlockReason }}
                            </p>

                            <Link
                                v-if="!guardrails.mfa_enabled"
                                :href="security.url()"
                                class="inline-flex w-fit items-center gap-1.5 rounded text-xs font-semibold text-[var(--ui-gold-ink)] focus-ring hover:underline pointer-coarse:min-h-11"
                            >
                                <LockKeyhole
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Configurar a verificação em dois passos
                            </Link>
                            <Link
                                v-else-if="
                                    document.public_id !== null &&
                                    compatibleSeries.length === 0
                                "
                                :href="agtConnection.url()"
                                class="inline-flex w-fit items-center gap-1.5 rounded text-xs font-semibold text-[var(--ui-gold-ink)] focus-ring hover:underline"
                            >
                                <ShieldCheck
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Sincronizar séries AGT
                            </Link>

                            <AppButton
                                variant="gold"
                                :disabled="!canOpenIssue"
                                class="w-full"
                                @click="openIssueDialog"
                            >
                                <Send class="size-4" aria-hidden="true" />
                                Emitir e enviar à AGT
                            </AppButton>
                            <AppButton
                                variant="quiet"
                                size="sm"
                                type="submit"
                                :aria-busy="form.processing"
                                :disabled="
                                    form.processing ||
                                    establishments.length === 0
                                "
                                class="w-full"
                            >
                                <LoaderCircle
                                    v-if="form.processing"
                                    class="size-3.5 animate-spin"
                                    aria-hidden="true"
                                />
                                <FilePenLine
                                    v-else
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Guardar rascunho
                            </AppButton>
                            <p
                                class="flex items-center justify-center gap-1.5 text-center text-[0.71875rem] text-[var(--ui-ink-3)]"
                            >
                                <LockKeyhole
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                Depois de emitido, o documento não se apaga.
                            </p>
                        </section>
                    </div>
                </aside>
            </div>
        </form>

        <Teleport to="body">
            <TransitionRoot as="template" :show="issueDialogOpen">
                <Dialog class="relative z-50" @close="closeIssueDialog">
                    <TransitionChild
                        as="template"
                        enter="ease-out duration-200"
                        enter-from="opacity-0"
                        enter-to="opacity-100"
                        leave="ease-out duration-150"
                        leave-from="opacity-100"
                        leave-to="opacity-0"
                    >
                        <div
                            class="fixed inset-0 dialog-scrim transition-opacity"
                        />
                    </TransitionChild>

                    <div
                        class="fixed inset-0 z-10 overflow-y-auto overscroll-contain"
                    >
                        <div
                            class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0"
                        >
                            <TransitionChild
                                as="template"
                                enter="ease-out duration-200"
                                enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                                enter-to="opacity-100 translate-y-0 sm:scale-100"
                                leave="ease-out duration-150"
                                leave-from="opacity-100 translate-y-0 sm:scale-100"
                                leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                            >
                                <DialogPanel
                                    class="relative w-full max-w-[36.5rem] dialog-panel p-6 text-left transition-[opacity,translate,scale] sm:p-7"
                                >
                                    <!--
                                        The irreversible step, shown as a before
                                        and after: what the document is now,
                                        what it becomes, and the facts that are
                                        fixed from here on.
                                    -->
                                    <form @submit.prevent="confirmIssue">
                                        <span
                                            class="inline-flex h-[1.625rem] items-center gap-1.5 rounded-full bg-accent-50 pr-2.5 pl-1 text-[0.78rem] font-semibold text-accent-800 ring-1 ring-accent-200 ring-inset dark:bg-accent-400/10 dark:text-accent-300 dark:ring-accent-400/20"
                                        >
                                            <span
                                                class="grid size-[1.125rem] place-items-center rounded-full bg-brand-950 dark:bg-zinc-100"
                                                aria-hidden="true"
                                            >
                                                <span
                                                    class="size-1.5 rounded-full bg-accent-400"
                                                />
                                            </span>
                                            Verificado antes de emitir
                                        </span>
                                        <DialogTitle
                                            as="h2"
                                            class="mt-4 text-[1.6875rem] leading-[1.12] font-normal tracking-[-0.025em] text-zinc-950 dark:text-white"
                                        >
                                            Emitir {{ issueDemonstrative }}
                                            {{
                                                documentTypeLabel.toLowerCase()
                                            }}?
                                        </DialogTitle>
                                        <p
                                            class="mt-2.5 text-[0.9rem]/6 text-zinc-600 dark:text-zinc-400"
                                        >
                                            {{ issueAddressee }} Depois de
                                            emitido, o documento já não pode ser
                                            alterado: uma correcção faz-se com
                                            uma nota de crédito. O servidor
                                            recalcula todos os valores antes de
                                            atribuir o número.
                                        </p>

                                        <div
                                            class="mt-5 grid gap-3 sm:grid-cols-2"
                                        >
                                            <div
                                                class="rounded-[1.125rem] bg-zinc-900/[0.04] px-4 py-4 dark:bg-white/[0.04]"
                                            >
                                                <p
                                                    class="text-[0.8rem] text-zinc-600 dark:text-zinc-400"
                                                >
                                                    Agora · Rascunho
                                                </p>
                                                <p
                                                    class="mt-2.5 numeric text-[1.5rem] leading-none tracking-[-0.025em] text-zinc-950 dark:text-white"
                                                >
                                                    {{
                                                        formatMoney(
                                                            totals.gross,
                                                        )
                                                    }}
                                                </p>
                                                <span
                                                    class="mt-3 inline-flex rounded-full bg-zinc-200/80 px-2 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-white/10 dark:text-zinc-300"
                                                    >Pode alterar</span
                                                >
                                            </div>
                                            <div
                                                class="rounded-[1.125rem] bg-accent-50 px-4 py-4 ring-[1.5px] ring-accent-400 ring-inset dark:bg-accent-400/[0.07]"
                                            >
                                                <p
                                                    class="text-[0.8rem] text-zinc-600 dark:text-zinc-400"
                                                >
                                                    Depois · Emitido
                                                </p>
                                                <p
                                                    class="mt-2.5 truncate font-mono text-[1.3rem] leading-none tracking-[-0.01em] text-zinc-950 dark:text-white"
                                                >
                                                    {{
                                                        nextDocumentNumber ??
                                                        '—'
                                                    }}
                                                </p>
                                                <span
                                                    class="mt-3 inline-flex rounded-full bg-lime-300 px-2 py-0.5 text-xs font-semibold text-lime-950"
                                                    >Assinado e enviado à
                                                    AGT</span
                                                >
                                            </div>
                                        </div>

                                        <dl class="mt-3.5">
                                            <div
                                                v-for="fact in issueFacts"
                                                :key="fact.label"
                                                class="flex min-h-[2.875rem] items-center justify-between gap-5 border-b border-zinc-900/[0.07] py-2 text-[0.85rem]"
                                            >
                                                <dt
                                                    class="text-zinc-500 dark:text-zinc-400"
                                                >
                                                    {{ fact.label }}
                                                </dt>
                                                <dd
                                                    class="text-right font-medium text-zinc-950 dark:text-white"
                                                    :class="{
                                                        numeric: fact.numeric,
                                                    }"
                                                >
                                                    {{ fact.value }}
                                                </dd>
                                            </div>
                                        </dl>

                                        <p
                                            class="mt-4 flex items-center gap-2.5 rounded-[0.875rem] bg-zinc-900/[0.04] px-3.5 py-2.5 text-[0.78rem] text-zinc-600 dark:bg-white/[0.04] dark:text-zinc-400"
                                        >
                                            <ShieldCheck
                                                class="size-4 shrink-0"
                                                aria-hidden="true"
                                            />
                                            Fica no histórico com o seu nome, a
                                            hora e a assinatura do documento.
                                        </p>

                                        <div
                                            v-if="
                                                issueForm.errors
                                                    .series_public_id ||
                                                issueForm.errors.revision
                                            "
                                            class="mt-4 rounded-[0.875rem] bg-rose-50 p-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-400/10 dark:text-rose-300 dark:ring-rose-400/20"
                                            role="alert"
                                        >
                                            {{
                                                issueForm.errors
                                                    .series_public_id ??
                                                issueForm.errors.revision
                                            }}
                                        </div>

                                        <div
                                            class="mt-5 flex flex-col-reverse gap-2.5 sm:flex-row sm:items-center"
                                        >
                                            <AppButton
                                                variant="outline"
                                                :disabled="issueForm.processing"
                                                @click="closeIssueDialog"
                                            >
                                                Voltar e rever
                                            </AppButton>
                                            <span
                                                class="hidden flex-1 sm:block"
                                            />
                                            <AppButton
                                                variant="ink"
                                                type="submit"
                                                :aria-busy="
                                                    issueForm.processing
                                                "
                                                :disabled="issueForm.processing"
                                            >
                                                <LoaderCircle
                                                    v-if="issueForm.processing"
                                                    class="size-4 animate-spin"
                                                    aria-hidden="true"
                                                />
                                                <Check
                                                    v-else
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                Confirmar emissão
                                            </AppButton>
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
