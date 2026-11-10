<script setup lang="ts">
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Head, Link, router, usePage, InfiniteScroll } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    BellRing,
    Check,
    ChevronDown,
    ChevronUp,
    Copy,
    Download,
    FileMinus,
    FileText,
    ListFilter,
    LoaderCircle,
    PencilLine,
    Plus,
    Printer,
    Search,
    Send,
    UserRound,
    X,
} from '@lucide/vue';
import { useEventListener, useMediaQuery } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DateInput from '@/components/DateInput.vue';
import type { LifecycleStep } from '@/components/DocumentLifecycle.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import LoadMore from '@/components/LoadMore.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { buttonClass } from '@/lib/button';
import { confirmAction } from '@/lib/confirm';
import { statusTone } from '@/lib/document-status';
import { usePaletteActions } from '@/lib/palette-actions';

import { index as agtSubmissions } from '@/routes/agt/submissions';
import { show as customerShow } from '@/routes/customers';
import { index as debtsIndex } from '@/routes/debts';
import {
    bulkPdf,
    index as documentsIndex,
    pdf as documentPdf,
    print as printDocument,
} from '@/routes/documents';
import {
    create as createInvoice,
    edit as editInvoice,
    send as sendInvoice,
} from '@/routes/invoices';
import type { SelectOption } from '@/types/select';

interface RegisterDocument {
    public_id: string;
    document_no: string | null;
    document_type: string;
    document_type_label: string;
    family: 'invoice' | 'receipt' | 'adjustment';
    document_date: string;
    due_date: string | null;
    customer_public_id: string | null;
    customer_name: string;
    customer_tax_identification_number: string;
    establishment: {
        public_id: string;
        name: string;
        code: string;
    };
    gross_total_minor: number;
    currency_code: string;
    balance: {
        outstanding_minor: number;
        label: string;
        tone: 'danger' | 'neutral';
    } | null;
    status: string;
    status_label: string;
    workflow_status: string;
    workflow_label: string;
    workflow_message: string | null;
    submission: {
        public_id: string;
        status: string;
        status_label: string;
        request_id: string | null;
    } | null;
    issued_at: string | null;
    updated_at: string | null;
    sent_at: string | null;
    send_count: number;
    delivery_email: string | null;
    can_edit: boolean;
    can_repeat: boolean;
    can_print: boolean;
    can_send: boolean;
}

interface HistoryEvent {
    type: string;
    label: string;
    tone: 'done' | 'error' | 'pending';
    actor_name: string | null;
    document_no: string | null;
    occurred_at: string;
}

interface SelectedDocument extends RegisterDocument {
    snapshot: {
        type_label: string;
        establishment_name: string | null;
        issuer_name: string | null;
        lines_count: number;
        gross_total_minor: number;
        tax_payable_minor: number;
        outstanding_minor: number;
        due_date: string | null;
        days_past_due: number;
        agt_message: string | null;
        agt_operational: { observed_at: string | null };
        agt_error_codes: string[];
        steps: LifecycleStep[];
    };
    history: HistoryEvent[];
    /** A credit note drafted from this document's lines; null when it could never have one. */
    credit_note: { available: boolean; reason: string | null } | null;
}

interface FilterOptions {
    statuses: SelectOption<string>[];
    families: SelectOption<string>[];
    types: SelectOption<string>[];
    establishments: SelectOption<string>[];
}

const props = defineProps<{
    documents: {
        data: RegisterDocument[];
        total: number;
    };
    summary: {
        total: number;
        draft: number;
        active: number;
        valid: number;
        attention: number;
    };
    filters: {
        q: string;
        status: string;
        family: string;
        type: string;
        establishment: string;
        from: string;
        to: string;
    };
    options: FilterOptions;
    permissions: { create: boolean };
    canRemindCustomers: boolean;
    selected: SelectedDocument | null;
}>();

/* -------------------------------------------- several documents at once */

/** What one download can render; the server holds the same limit. */
const MAX_PDF_DOWNLOAD = 25;

const chosen = ref<string[]>([]);

watch(
    () => props.documents.data,
    (documents) => {
        chosen.value = chosen.value.filter((id) =>
            documents.some((document) => document.public_id === id),
        );
    },
);

function toggleChosen(publicId: string): void {
    chosen.value = chosen.value.includes(publicId)
        ? chosen.value.filter((id) => id !== publicId)
        : [...chosen.value, publicId];
}

const chosenCustomers = computed(() => [
    ...new Set(
        props.documents.data.flatMap((document) =>
            document.customer_public_id !== null &&
            chosen.value.includes(document.public_id)
                ? [document.customer_public_id]
                : [],
        ),
    ),
]);

const downloadChosenUrl = computed(() =>
    bulkPdf.url({ query: { documentos: chosen.value } }),
);

const remindChosenUrl = computed(() =>
    debtsIndex.url({ query: { clientes: chosenCustomers.value } }),
);

const search = ref(props.filters.q);
const status = ref(props.filters.status);
const family = ref(props.filters.family);
const type = ref(props.filters.type);
const establishment = ref(props.filters.establishment);
const from = ref<string | null>(props.filters.from || null);
const to = ref<string | null>(props.filters.to || null);
const sendingDocument = ref<string | null>(null);
const openingDocument = ref<string | null>(null);
const loading = ref(false);
const detailPanel = ref<HTMLElement | null>(null);
const page = usePage();
const showFilters = ref(
    Boolean(
        props.filters.type ||
        props.filters.establishment ||
        props.filters.from ||
        props.filters.to,
    ),
);

const typeOptions = computed<SelectOption<string>[]>(() => [
    { value: '', label: 'Todos os tipos' },
    ...props.options.types,
]);
const establishmentOptions = computed<SelectOption<string>[]>(() => [
    { value: '', label: 'Todos os locais' },
    ...props.options.establishments,
]);
const advancedFilterCount = computed(
    () =>
        [type.value, establishment.value, from.value, to.value].filter(Boolean)
            .length,
);
const activeFilterCount = computed(
    () =>
        [
            search.value,
            status.value !== 'all' ? status.value : '',
            family.value !== 'all' ? family.value : '',
        ].filter(Boolean).length + advancedFilterCount.value,
);
const hasDocuments = computed(() => props.summary.total > 0);

/** The register's states, in the order someone works through them. */
const statusSegments = computed(() => [
    { value: 'all', label: 'Todos', count: props.summary.total },
    { value: 'attention', label: 'Atenção', count: props.summary.attention },
    { value: 'active', label: 'Em curso', count: props.summary.active },
    { value: 'draft', label: 'Rascunhos', count: props.summary.draft },
    { value: 'valid', label: 'Validados', count: props.summary.valid },
]);

const newDocumentTypes = [
    {
        value: 'FT',
        label: 'Factura',
        detail: 'Venda a crédito ou com vencimento',
    },
    {
        value: 'FR',
        label: 'Factura/Recibo',
        detail: 'Venda e pagamento no mesmo acto',
    },
    {
        value: 'TV',
        label: 'Talão de venda',
        detail: 'Venda a pronto pagamento',
    },
    {
        value: 'GF',
        label: 'Factura genérica',
        detail: 'Operações agregadas com data por linha',
    },
    {
        value: 'RG',
        label: 'Recibo',
        detail: 'Liquida facturas anteriores',
    },
    {
        value: 'NC',
        label: 'Nota de crédito',
        detail: 'Reduz ou anula valor facturado',
    },
    {
        value: 'ND',
        label: 'Nota de débito',
        detail: 'Acrescenta valor a um documento',
    },
];

/* ---------------------------------------------------------------- queue */

function query(): Record<string, string> {
    const values: Record<string, string> = {};
    const trimmedSearch = search.value.trim();

    if (trimmedSearch !== '') {
        values.q = trimmedSearch;
    }

    if (status.value !== 'all') {
        values.status = status.value;
    }

    if (family.value !== 'all') {
        values.family = family.value;
    }

    if (type.value !== '') {
        values.type = type.value;
    }

    if (establishment.value !== '') {
        values.establishment = establishment.value;
    }

    if (from.value) {
        values.from = from.value;
    }

    if (to.value) {
        values.to = to.value;
    }

    return values;
}

function startLoading(): void {
    loading.value = true;
}

function finishLoading(): void {
    loading.value = false;
}

function applyFilters(): void {
    if (['FT', 'FR', 'GF'].includes(type.value)) {
        family.value = 'invoice';
    } else if (['RC', 'RG', 'TV'].includes(type.value)) {
        family.value = 'receipt';
    } else if (['NC', 'ND'].includes(type.value)) {
        family.value = 'adjustment';
    }

    // A new filter starts a new list, so the open document closes with it.
    router.get(documentsIndex.url(), query(), {
        preserveState: true,
        replace: true,
        only: ['documents', 'filters', 'selected'],
        reset: ['documents'],
        onStart: startLoading,
        onFinish: finishLoading,
    });
}

function selectStatus(value: string): void {
    status.value = value;
    applyFilters();
}

function selectFamily(value: string): void {
    family.value = value;
    type.value = '';
    applyFilters();
}

function clearFilters(): void {
    search.value = '';
    status.value = 'all';
    family.value = 'all';
    type.value = '';
    establishment.value = '';
    from.value = null;
    to.value = null;
    applyFilters();
}

/**
 * The page of the list being looked at. Opening or closing a document keeps
 * it in the address; changing a filter does not, because a new filter starts
 * a new list.
 */
const carriedPage = computed<Record<string, string>>(() => {
    const value = new URL(page.url, 'http://localhost').searchParams.get(
        'page',
    );

    const carried: Record<string, string> = {};

    if (value !== null && value !== '1') {
        carried.page = value;
    }

    return carried;
});

/**
 * The address that opens a document in the detail panel.
 *
 * Only the panel is reloaded: the list stays exactly where it was, and the
 * document's id goes into the address so the view can be shared or reloaded.
 * Being a real address, it is also what a middle-click or copy-link gets.
 */
function documentUrl(publicId: string): string {
    return documentsIndex.url({
        query: { ...query(), ...carriedPage.value, documento: publicId },
    });
}

function rowNumber(document: RegisterDocument): string {
    return document.document_no ?? `Rascunho · ${document.document_type_label}`;
}

const gridColumns =
    'md:grid-cols-[2.75rem_minmax(6.5rem,0.7fr)_minmax(7rem,1fr)_4.5rem_7.5rem_7.75rem_minmax(10rem,1.5fr)]';

/** The row is a real link; a plain click is handled in the page, not reloaded. */
function skipReopening(event: MouseEvent, publicId: string): void {
    const modified =
        event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;

    if (props.selected?.public_id === publicId && !modified) {
        event.preventDefault();
    }
}

function startOpening(publicId: string): void {
    openingDocument.value = publicId;
}

function finishOpening(publicId: string): void {
    if (openingDocument.value === publicId) {
        openingDocument.value = null;
    }
}

/**
 * Below 80rem the panel slides over the list instead of sitting beside it, so
 * focus moves into it; from 80rem it is beside the list and focus stays on the
 * row, so j and k keep walking the list.
 */
watch(
    () => props.selected?.public_id,
    async (publicId) => {
        inspectorTab.value = 'state';

        if (
            publicId === undefined ||
            window.matchMedia('(min-width: 80rem)').matches
        ) {
            return;
        }

        await nextTick();
        detailPanel.value?.focus({ preventScroll: true });
    },
);

/** Escape closes the open document, unless a menu or dialog has it first. */
useEventListener('keydown', (event: KeyboardEvent) => {
    if (
        event.key !== 'Escape' ||
        event.defaultPrevented ||
        !props.selected ||
        document.querySelector('[role="dialog"], [role="menu"]') !== null
    ) {
        return;
    }

    closeDocument();
});

/* ------------------------------------------------------------- inspector */

const inspectorTab = ref<'state' | 'history' | 'details'>('state');

/**
 * From 80rem the panel sits beside the list. Narrower, it slides over
 * everything, so it is moved to the page's root, above the app's own bars.
 */
const inspectorBeside = useMediaQuery('(min-width: 80rem)');

const inspectorTabs = [
    { value: 'state', label: 'Estado' },
    { value: 'history', label: 'Histórico' },
    { value: 'details', label: 'Detalhes' },
] as const;

/** The documents either side of the open one, in the list as it is loaded. */
const neighbours = computed(() => {
    const index = props.documents.data.findIndex(
        (document) => document.public_id === props.selected?.public_id,
    );

    return {
        previous: index > 0 ? props.documents.data[index - 1] : null,
        next:
            index >= 0 && index < props.documents.data.length - 1
                ? props.documents.data[index + 1]
                : null,
    };
});

/** What is still owed on the open document, as a word rather than a figure. */
const settlement = computed(() => {
    const snapshot = props.selected?.snapshot;

    if (
        props.selected?.status === 'draft' ||
        !snapshot ||
        snapshot.outstanding_minor <= 0
    ) {
        return null;
    }

    if (snapshot.days_past_due > 0) {
        return {
            label: `Vencida há ${snapshot.days_past_due} ${snapshot.days_past_due === 1 ? 'dia' : 'dias'}`,
            tone: 'danger' as const,
        };
    }

    return {
        label: snapshot.due_date
            ? `Por receber · vence ${formatShortDate(snapshot.due_date)}`
            : 'Por receber',
        tone: 'neutral' as const,
    };
});

const stepDot: Record<LifecycleStep['state'], string> = {
    done: 'bg-[var(--ui-positive)] text-[var(--surface-raised)]',
    error: 'bg-[var(--ui-critical)] text-[var(--surface-raised)]',
    current:
        'bg-transparent shadow-[inset_0_0_0_1.5px_var(--color-accent-400)]',
    todo: 'bg-transparent shadow-[inset_0_0_0_1.5px_var(--ui-line-strong)]',
};

/** "Hoje, 22:31" for today, "6 nov, 22:31" before. */
function stepTime(step: LifecycleStep): string {
    if (step.key === 'paid') {
        return step.detail ?? (step.state === 'done' ? 'Liquidada' : '—');
    }

    if (step.at === null) {
        return step.detail ?? 'Ainda não';
    }

    const when = new Date(step.at);
    const day = dayKey(when);
    const time = new Intl.DateTimeFormat('pt-AO', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: TIMEZONE,
    }).format(when);

    return day === todayKey.value
        ? `Hoje, ${time}`
        : `${formatShortDate(day)}, ${time}`;
}

/* ---------------------------------------------------------- date groups */

const TIMEZONE = 'Africa/Luanda';

function dayKey(value: Date): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: TIMEZONE }).format(
        value,
    );
}

const todayKey = computed(() => dayKey(new Date()));
const yesterdayKey = computed(() => dayKey(new Date(Date.now() - 86_400_000)));

const monthFormatter = new Intl.DateTimeFormat('pt-PT', {
    month: 'long',
    timeZone: 'UTC',
});

function groupLabel(date: string): string {
    const day = date.slice(0, 10);

    if (day === todayKey.value) {
        return 'Hoje';
    }

    if (day === yesterdayKey.value) {
        return 'Ontem';
    }

    const month = monthFormatter.format(
        new Date(`${day.slice(0, 7)}-01T00:00:00Z`),
    );
    const year = day.slice(0, 4);
    const label = `${month.charAt(0).toUpperCase()}${month.slice(1)}`;

    return year === todayKey.value.slice(0, 4) ? label : `${label} de ${year}`;
}

/** The list with a heading wherever the day, or the month, changes. */
const rows = computed(() => {
    const result: (
        | { kind: 'group'; key: string; label: string }
        | { kind: 'document'; key: string; document: RegisterDocument }
    )[] = [];
    let current = '';

    for (const document of props.documents.data) {
        const label = groupLabel(document.document_date);

        if (label !== current) {
            current = label;
            result.push({ kind: 'group', key: `group-${label}`, label });
        }

        result.push({
            kind: 'document',
            key: document.public_id,
            document,
        });
    }

    return result;
});

/** The document type's code, the same chip in the list and the panel. */
function typeCode(document: RegisterDocument): string {
    return document.document_type;
}

/**
 * "FT26/449" from "FT FT26/449": the chip beside it already says FT, so the
 * series and number are what the eye needs. The full number stays the
 * accessible name and the tooltip.
 */
function shortNumber(document: RegisterDocument): string {
    return document.document_no?.replace(/^\S+\s+/, '') ?? 'Rascunho';
}

function closeDocument(): void {
    router.get(
        documentsIndex.url(),
        { ...query(), ...carriedPage.value },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['selected'],
        },
    );
}

function workflowIsActive(value: string): boolean {
    return [
        'pending',
        'sending',
        'retrying',
        'received',
        'processing',
    ].includes(value);
}

/* Formatters are built once: the list formats several figures per row. */
const currencyFormatters = new Map<string, Intl.NumberFormat>();
const wholeAmountFormatter = new Intl.NumberFormat('pt-AO', {
    maximumFractionDigits: 2,
});
const longDateFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
});
const shortMonthFormatter = new Intl.DateTimeFormat('pt-PT', {
    month: 'short',
    timeZone: 'UTC',
});
const dateTimeFormatter = new Intl.DateTimeFormat('pt-AO', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Africa/Luanda',
});

function money(minor: number, currencyCode: string): string {
    let formatter = currencyFormatters.get(currencyCode);

    if (formatter === undefined) {
        formatter = new Intl.NumberFormat('pt-AO', {
            style: 'currency',
            currency: currencyCode,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        currencyFormatters.set(currencyCode, formatter);
    }

    return formatter.format(minor / 100);
}

function wholeAmount(minor: number): string {
    return wholeAmountFormatter.format(minor / 100);
}

function currencyLabel(currencyCode: string): string {
    return currencyCode === 'AOA' ? 'Kz' : currencyCode;
}

function formatDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return longDateFormatter.format(new Date(`${value.slice(0, 10)}T12:00:00`));
}

/** "6 nov": the locale's own short date is "6/11", which reads as a fraction in a column. */
function formatShortDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const day = new Date(`${value.slice(0, 10)}T00:00:00Z`);

    return `${day.getUTCDate()} ${shortMonthFormatter.format(day).replace('.', '')}`;
}

function formatDateTime(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return dateTimeFormatter.format(new Date(value));
}

/** "FT 2026/148 · por Ana Manuel": whichever of the two the event carries. */
function eventMeta(event: HistoryEvent): string {
    return [
        event.document_no,
        event.actor_name ? `por ${event.actor_name}` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

/** The open document's own buttons, reachable from the palette. */
usePaletteActions(() => {
    const document = props.selected;

    if (!document) {
        return [];
    }

    const number = document.document_no ?? 'Rascunho';
    const actions = [];

    if (document.can_edit) {
        actions.push({
            key: 'edit',
            label: 'Continuar rascunho',
            hint: number,
            icon: PencilLine,
            run: () => router.visit(editInvoice.url(document.public_id)),
        });
    }

    if (document.can_send) {
        actions.push({
            key: 'send',
            label: document.sent_at
                ? 'Reenviar ao cliente'
                : 'Enviar ao cliente',
            hint: number,
            icon: Send,
            run: () => void sendDocument(document),
        });
    }

    if (document.can_print) {
        actions.push(
            {
                key: 'pdf',
                label: 'Descarregar PDF',
                hint: number,
                icon: Download,
                run: () => {
                    window.location.assign(documentPdf.url(document.public_id));
                },
            },
            {
                key: 'print',
                label: 'Abrir para imprimir',
                hint: number,
                icon: Printer,
                run: () => {
                    window.location.assign(
                        printDocument.url(document.public_id),
                    );
                },
            },
        );
    }

    if (document.credit_note?.available) {
        const correctionUrl = createInvoice.url({
            query: { corrigir: document.public_id },
        });

        actions.push({
            key: 'credit-note',
            label: 'Corrigir com nota de crédito',
            hint: number,
            icon: FileMinus,
            run: () => router.visit(correctionUrl),
        });
    }

    if (document.customer_public_id) {
        const customerUrl = customerShow.url(document.customer_public_id);

        actions.push({
            key: 'customer',
            label: 'Ver cliente',
            hint: document.customer_name,
            icon: UserRound,
            run: () => router.visit(customerUrl),
        });
    }

    return actions;
});

async function sendDocument(document: RegisterDocument): Promise<void> {
    const confirmed = await confirmAction({
        title: `Enviar ${document.document_no ?? 'este documento'}?`,
        message: document.delivery_email
            ? `Será enviado por email para ${document.delivery_email}.`
            : 'Será enviado por email ao cliente.',
        confirmLabel: document.sent_at ? 'Reenviar agora' : 'Enviar agora',
        tone: 'neutral',
    });

    if (!confirmed) {
        return;
    }

    sendingDocument.value = document.public_id;
    router.post(
        sendInvoice.url({ fiscalDocument: document.public_id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                sendingDocument.value = null;
            },
        },
    );
}
</script>

<template>
    <AppLayout>
        <Head title="Documentos fiscais" />

        <div class="flex min-h-[calc(100dvh-4rem)] flex-col">
            <div class="px-4 empty:hidden sm:px-6"><FlashBanner /></div>

            <!-- ------------------------------------------------ title bar -->
            <header
                class="flex flex-wrap items-center gap-x-3 gap-y-4 px-4 pt-6 pb-5 sm:px-6"
            >
                <h1 class="text-[0.8125rem] font-semibold text-[var(--ui-ink)]">
                    Documentos
                </h1>
                <nav
                    aria-label="Filtrar por estado"
                    class="-mx-4 flex min-w-0 [scrollbar-width:none] gap-0.5 overflow-x-auto px-4 max-md:order-last max-md:w-[calc(100%+2rem)] max-md:[mask-image:linear-gradient(to_right,#000_82%,transparent)] md:mx-0 md:ms-2 md:px-0"
                >
                    <button
                        v-for="segment in statusSegments"
                        :key="segment.value"
                        type="button"
                        :aria-pressed="status === segment.value"
                        class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-[0.8125rem] focus-ring transition-colors pointer-coarse:h-11"
                        :class="
                            status === segment.value
                                ? 'bg-[var(--surface-raised)] font-semibold text-[var(--ui-ink)] shadow-[var(--ui-control-shadow)]'
                                : 'font-medium text-[var(--ui-ink-2)] hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)]'
                        "
                        @click="selectStatus(segment.value)"
                    >
                        {{ segment.label }}
                        <span
                            class="numeric text-[0.71875rem] font-medium"
                            :class="
                                segment.value === 'attention' &&
                                segment.count > 0
                                    ? 'text-[var(--ui-critical)]'
                                    : segment.value === 'active' &&
                                        segment.count > 0
                                      ? 'text-[var(--ui-gold-ink)]'
                                      : 'text-[var(--ui-ink-3)]'
                            "
                            >{{ segment.count }}</span
                        >
                    </button>
                </nav>
                <span class="flex-1" />
                <form
                    role="search"
                    class="flex h-9 items-center gap-2 rounded-lg bg-[var(--surface-raised)] px-2.5 text-[var(--ui-ink-3)] shadow-[var(--ui-control-shadow)] focus-within:shadow-[0_0_0_1px_var(--color-accent-400),0_0_0_4px_var(--ui-halo)] max-md:order-last max-md:w-full md:w-56 pointer-coarse:h-11"
                    @submit.prevent="applyFilters"
                >
                    <Search class="size-3.5 shrink-0" aria-hidden="true" />
                    <label for="register-search" class="sr-only"
                        >Procurar documentos</label
                    >
                    <input
                        id="register-search"
                        v-model="search"
                        type="search"
                        placeholder="Número, cliente ou NIF"
                        autocomplete="off"
                        spellcheck="false"
                        enterkeyhint="search"
                        class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[0.78125rem] font-medium text-[var(--ui-ink)] outline-none placeholder:text-[var(--ui-ink-3)] [&::-webkit-search-cancel-button]:hidden"
                    />
                </form>

                <Menu v-if="permissions.create" as="div" class="relative z-20">
                    <MenuButton
                        :class="[
                            buttonClass('gold', 'sm'),
                            'h-[1.875rem] ps-2.5',
                        ]"
                    >
                        <Plus
                            class="size-3.5"
                            :stroke-width="2.2"
                            aria-hidden="true"
                        />
                        Novo
                        <ChevronDown
                            class="-me-0.5 size-3.5 opacity-70"
                            aria-hidden="true"
                        />
                    </MenuButton>

                    <transition
                        enter-active-class="transition duration-100 ease-out"
                        enter-from-class="scale-95 opacity-0"
                        enter-to-class="scale-100 opacity-100"
                        leave-active-class="transition duration-75 ease-out"
                        leave-from-class="scale-100 opacity-100"
                        leave-to-class="scale-95 opacity-0"
                    >
                        <MenuItems
                            class="absolute inset-e-0 z-30 mt-1.5 w-72 max-w-[calc(100vw-2rem)] origin-top-right menu-panel p-1"
                        >
                            <p
                                class="px-2.5 pt-1.5 pb-1 text-xs font-medium text-[var(--ui-ink-3)]"
                            >
                                O que vai emitir?
                            </p>
                            <MenuItem
                                v-for="entry in newDocumentTypes"
                                :key="entry.value"
                                v-slot="{ active }"
                            >
                                <Link
                                    :href="
                                        createInvoice.url({
                                            query: { type: entry.value },
                                        })
                                    "
                                    :class="[
                                        active ? 'bg-[var(--ui-wash)]' : '',
                                        'flex items-center gap-2.5 rounded-md px-2.5 py-2 focus:outline-none',
                                    ]"
                                >
                                    <span
                                        class="grid h-[1.125rem] w-[1.375rem] shrink-0 place-items-center rounded bg-[var(--ui-well)] text-[0.59375rem] font-bold text-[var(--ui-ink-2)]"
                                    >
                                        {{ entry.value }}
                                    </span>
                                    <span class="min-w-0">
                                        <span
                                            class="block text-[0.8125rem] font-medium text-[var(--ui-ink)]"
                                            >{{ entry.label }}</span
                                        >
                                        <span
                                            class="block text-xs text-[var(--ui-ink-3)]"
                                            >{{ entry.detail }}</span
                                        >
                                    </span>
                                </Link>
                            </MenuItem>
                        </MenuItems>
                    </transition>
                </Menu>
            </header>

            <!-- ---------------------------------------------- filter bar -->
            <div
                class="flex min-h-11 [scrollbar-width:none] items-center gap-1.5 overflow-x-auto border-b border-[var(--ui-line)] px-4 py-1.5 sm:flex-wrap sm:px-6"
            >
                <button
                    type="button"
                    :aria-expanded="showFilters"
                    aria-controls="register-filters"
                    class="inline-flex h-[1.625rem] items-center gap-1.5 rounded-md px-2 text-[0.78125rem] font-medium focus-ring transition-colors hover:bg-[var(--ui-wash)] pointer-coarse:h-9"
                    :class="
                        showFilters
                            ? 'bg-[var(--ui-wash)] text-[var(--ui-ink)]'
                            : 'text-[var(--ui-ink-2)] hover:text-[var(--ui-ink)]'
                    "
                    @click="showFilters = !showFilters"
                >
                    <ListFilter class="size-3.5" aria-hidden="true" />
                    Filtrar
                    <span
                        v-if="advancedFilterCount > 0"
                        class="grid h-4 min-w-4 place-items-center rounded bg-accent-400 px-1 text-[0.65625rem] font-bold text-[#1a1407]"
                        >{{ advancedFilterCount }}</span
                    >
                </button>
                <span
                    class="mx-1 h-4 w-px bg-[var(--ui-line-strong)]"
                    aria-hidden="true"
                />
                <div
                    class="flex shrink-0 items-center gap-0.5 sm:flex-wrap"
                    role="group"
                    aria-label="Família de documentos"
                >
                    <button
                        v-for="option in options.families"
                        :key="String(option.value)"
                        type="button"
                        :aria-pressed="family === option.value"
                        class="inline-flex h-[1.625rem] items-center rounded-md px-2 text-[0.78125rem] focus-ring transition-colors pointer-coarse:h-9"
                        :class="
                            family === option.value
                                ? 'bg-[var(--ui-well)] font-semibold text-[var(--ui-ink)]'
                                : 'font-medium text-[var(--ui-ink-2)] hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)]'
                        "
                        @click="selectFamily(String(option.value))"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <button
                    v-if="activeFilterCount > 0"
                    type="button"
                    class="inline-flex h-[1.625rem] items-center gap-1 rounded-md px-2 text-[0.78125rem] font-medium text-[var(--ui-ink-3)] focus-ring hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)] pointer-coarse:h-9"
                    @click="clearFilters"
                >
                    <X class="size-3" :stroke-width="2.4" aria-hidden="true" />
                    Limpar
                </button>
                <span class="flex-1" />
                <span
                    class="shrink-0 numeric text-xs text-[var(--ui-ink-3)] max-sm:hidden"
                    aria-live="polite"
                    >{{ documents.total }}
                    {{
                        documents.total === 1 ? 'documento' : 'documentos'
                    }}</span
                >
            </div>

            <form
                v-if="showFilters"
                id="register-filters"
                class="grid gap-2 border-b border-[var(--ui-line)] bg-[var(--ui-head)] px-4 py-3 sm:grid-cols-2 sm:px-6 xl:grid-cols-[repeat(4,minmax(0,13rem))_auto] xl:justify-start"
                @submit.prevent="applyFilters"
            >
                <SelectInput
                    v-model="type"
                    :options="typeOptions"
                    aria-label="Filtrar por tipo"
                />
                <SelectInput
                    v-model="establishment"
                    :options="establishmentOptions"
                    aria-label="Filtrar por estabelecimento"
                />
                <DateInput
                    v-model="from"
                    aria-label="Data inicial"
                    :max-date="to ?? undefined"
                />
                <DateInput
                    v-model="to"
                    aria-label="Data final"
                    :min-date="from ?? undefined"
                />
                <AppButton variant="ink" type="submit">Aplicar</AppButton>
            </form>

            <!-- ------------------------------------------ list + inspector -->
            <div class="flex min-h-0 flex-1 items-start gap-4 p-4 sm:p-6">
                <section
                    aria-label="Documentos"
                    :aria-busy="loading"
                    class="relative min-w-0 flex-1 rounded-xl bg-[var(--surface-raised)] shadow-[var(--ui-card-shadow)] transition-opacity duration-150 ease-out"
                    :class="loading ? 'opacity-60 delay-150' : ''"
                >
                    <div
                        v-if="documents.data.length === 0"
                        class="grid place-items-center px-6 py-20 text-center"
                    >
                        <FileText
                            class="size-5 text-[var(--ui-ink-3)]"
                            aria-hidden="true"
                        />
                        <h2
                            class="mt-3 text-sm font-semibold text-[var(--ui-ink)]"
                        >
                            {{
                                hasDocuments
                                    ? 'Nenhum documento com estes filtros'
                                    : 'Ainda não há documentos'
                            }}
                        </h2>
                        <p
                            class="mt-1 max-w-xs text-[0.8125rem]/5 text-[var(--ui-ink-3)]"
                        >
                            {{
                                hasDocuments
                                    ? 'Experimente outro estado, família ou intervalo de datas.'
                                    : 'O primeiro documento começa como rascunho. Só segue para a AGT depois de o emitir.'
                            }}
                        </p>
                        <AppButton
                            v-if="hasDocuments"
                            variant="outline"
                            size="sm"
                            class="mt-4"
                            @click="clearFilters"
                        >
                            Limpar filtros
                        </AppButton>
                        <AppButton
                            v-else-if="permissions.create"
                            variant="gold"
                            size="sm"
                            :href="createInvoice.url()"
                            class="mt-4"
                        >
                            Criar factura
                        </AppButton>
                    </div>

                    <template v-else>
                        <InfiniteScroll
                            data="documents"
                            :buffer="400"
                            :manual-after="4"
                            class="overflow-x-auto rounded-xl"
                        >
                            <div
                                :class="[
                                    gridColumns,
                                    'grid h-[2.125rem] items-center border-b border-[var(--ui-line)] pe-4 text-xs font-medium text-[var(--ui-ink-3)] max-md:hidden sm:pe-6',
                                ]"
                                aria-hidden="true"
                            >
                                <span />
                                <span class="px-2">Documento</span>
                                <span class="px-2">Cliente</span>
                                <span class="px-2">Data</span>
                                <span class="px-2 text-end">Total</span>
                                <span class="px-2">Cobrança</span>
                                <span class="ps-5 pe-2">AGT</span>
                            </div>
                            <template #next="scroll"
                                ><LoadMore
                                    :shown="documents.data.length"
                                    :total="documents.total"
                                    :loading="scroll.loading"
                                    :has-more="scroll.hasMore"
                                    :manual-mode="scroll.manualMode"
                                    :fetch="scroll.fetch"
                            /></template>
                            <template v-for="row in rows" :key="row.key">
                                <div
                                    v-if="row.kind === 'group'"
                                    class="flex h-8 items-center gap-1.5 border-b border-[var(--ui-line)] bg-[var(--ui-head)] px-4 text-xs font-semibold text-[var(--ui-ink-2)] sm:px-6"
                                >
                                    <ChevronDown
                                        class="size-3"
                                        :stroke-width="2.2"
                                        aria-hidden="true"
                                    />
                                    {{ row.label }}
                                </div>
                                <div
                                    v-else
                                    data-nav-item
                                    :class="[
                                        gridColumns,
                                        'group/row relative flex items-center border-b border-[var(--ui-line)] md:grid',
                                        chosen.includes(row.document.public_id)
                                            ? 'bg-[var(--ui-select)]'
                                            : selected?.public_id ===
                                                row.document.public_id
                                              ? 'bg-[var(--ui-wash)]'
                                              : 'hover:bg-[var(--ui-wash)]',
                                    ]"
                                >
                                    <span
                                        v-if="
                                            selected?.public_id ===
                                            row.document.public_id
                                        "
                                        class="absolute inset-y-0 inset-s-0 w-0.5 bg-accent-400"
                                        aria-hidden="true"
                                    />
                                    <label
                                        class="grid w-10 shrink-0 cursor-pointer place-items-center self-stretch ps-1 md:w-auto md:ps-2.5 pointer-coarse:w-11"
                                        :class="
                                            chosen.length > 0
                                                ? ''
                                                : 'max-md:hidden md:opacity-0 md:group-hover/row:opacity-100 md:focus-within:opacity-100 md:pointer-coarse:opacity-100'
                                        "
                                        :aria-hidden="!row.document.can_print"
                                    >
                                        <template v-if="row.document.can_print">
                                            <span class="sr-only"
                                                >Escolher
                                                {{
                                                    rowNumber(row.document)
                                                }}</span
                                            >
                                            <input
                                                type="checkbox"
                                                class="form-checkbox size-4"
                                                :checked="
                                                    chosen.includes(
                                                        row.document.public_id,
                                                    )
                                                "
                                                @change="
                                                    toggleChosen(
                                                        row.document.public_id,
                                                    )
                                                "
                                            />
                                        </template>
                                    </label>
                                    <Link
                                        data-nav-row="open"
                                        :href="
                                            documentUrl(row.document.public_id)
                                        "
                                        preserve-state
                                        preserve-scroll
                                        replace
                                        prefetch
                                        cache-for="5s"
                                        :only="['selected']"
                                        :title="
                                            row.document.workflow_message ??
                                            undefined
                                        "
                                        :aria-current="
                                            selected?.public_id ===
                                            row.document.public_id
                                                ? 'true'
                                                : undefined
                                        "
                                        class="min-w-0 flex-1 py-2.5 pe-4 text-[0.8125rem] outline-none focus-visible:bg-[var(--ui-wash)] focus-visible:shadow-[inset_0_0_0_2px_var(--color-accent-400)] max-md:block max-md:ps-4 sm:pe-6 md:col-span-6 md:grid md:h-11 md:grid-cols-subgrid md:items-center md:py-0"
                                        @click.capture="
                                            skipReopening(
                                                $event,
                                                row.document.public_id,
                                            )
                                        "
                                        @start="
                                            startOpening(row.document.public_id)
                                        "
                                        @finish="
                                            finishOpening(
                                                row.document.public_id,
                                            )
                                        "
                                    >
                                        <!-- Phone: two lines, the customer and the amount first. -->
                                        <span class="contents md:hidden">
                                            <span
                                                class="flex items-baseline justify-between gap-3"
                                            >
                                                <span
                                                    class="truncate font-medium text-[var(--ui-ink)]"
                                                    >{{
                                                        row.document
                                                            .customer_name
                                                    }}</span
                                                >
                                                <span
                                                    class="shrink-0 numeric font-semibold"
                                                    :class="
                                                        row.document.family ===
                                                        'adjustment'
                                                            ? 'text-[var(--ui-critical)]'
                                                            : 'text-[var(--ui-ink)]'
                                                    "
                                                    >{{
                                                        row.document.family ===
                                                        'adjustment'
                                                            ? '−'
                                                            : ''
                                                    }}{{
                                                        wholeAmount(
                                                            row.document
                                                                .gross_total_minor,
                                                        )
                                                    }}
                                                    <span
                                                        class="text-xs font-normal text-[var(--ui-ink-3)]"
                                                        >{{
                                                            currencyLabel(
                                                                row.document
                                                                    .currency_code,
                                                            )
                                                        }}</span
                                                    ></span
                                                >
                                            </span>
                                            <span
                                                class="mt-1 flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                                            >
                                                <span
                                                    class="min-w-0 text-xs text-[var(--ui-ink-3)]"
                                                    >{{
                                                        rowNumber(row.document)
                                                    }}
                                                    ·
                                                    {{
                                                        formatShortDate(
                                                            row.document
                                                                .document_date,
                                                        )
                                                    }}</span
                                                >
                                                <StatusBadge
                                                    :label="
                                                        row.document
                                                            .workflow_label
                                                    "
                                                    :tone="
                                                        statusTone(
                                                            row.document
                                                                .workflow_status,
                                                        )
                                                    "
                                                />
                                            </span>
                                            <span
                                                v-if="row.document.balance"
                                                class="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--ui-ink-2)]"
                                            >
                                                <span
                                                    >Cobrança:
                                                    {{
                                                        row.document.balance
                                                            .label
                                                    }}</span
                                                >
                                                <span
                                                    v-if="
                                                        row.document.balance
                                                            .outstanding_minor >
                                                            0 &&
                                                        row.document.balance
                                                            .outstanding_minor !==
                                                            row.document
                                                                .gross_total_minor
                                                    "
                                                    class="numeric"
                                                    >{{
                                                        money(
                                                            row.document.balance
                                                                .outstanding_minor,
                                                            row.document
                                                                .currency_code,
                                                        )
                                                    }}</span
                                                >
                                            </span>
                                        </span>

                                        <!-- Desktop: one row, one column per fact. -->
                                        <span
                                            class="flex min-w-0 items-center gap-2 px-2 max-md:hidden"
                                        >
                                            <span
                                                class="grid h-[1.125rem] w-[1.375rem] shrink-0 place-items-center rounded bg-[var(--ui-well)] text-[0.59375rem] font-bold text-[var(--ui-ink-2)]"
                                                aria-hidden="true"
                                                >{{
                                                    typeCode(row.document)
                                                }}</span
                                            >
                                            <span
                                                class="truncate font-[550] text-[var(--ui-ink)]"
                                                :class="
                                                    row.document.document_no
                                                        ? ''
                                                        : 'text-[var(--ui-ink-3)]'
                                                "
                                                :title="rowNumber(row.document)"
                                                >{{
                                                    shortNumber(row.document)
                                                }}</span
                                            >
                                            <LoaderCircle
                                                v-if="
                                                    openingDocument ===
                                                    row.document.public_id
                                                "
                                                class="size-3 shrink-0 animate-spin-delayed text-[var(--ui-ink-3)]"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <span
                                            class="truncate px-2 text-[var(--ui-ink)] max-md:hidden"
                                            :title="row.document.customer_name"
                                            >{{
                                                row.document.customer_name
                                            }}</span
                                        >
                                        <span
                                            class="px-2 numeric text-[var(--ui-ink-2)] max-md:hidden"
                                            >{{
                                                formatShortDate(
                                                    row.document.document_date,
                                                )
                                            }}</span
                                        >
                                        <span
                                            class="px-2 text-end numeric font-semibold max-md:hidden"
                                            :class="
                                                row.document.family ===
                                                'adjustment'
                                                    ? 'text-[var(--ui-critical)]'
                                                    : 'text-[var(--ui-ink)]'
                                            "
                                            >{{
                                                row.document.family ===
                                                'adjustment'
                                                    ? '−'
                                                    : ''
                                            }}{{
                                                wholeAmount(
                                                    row.document
                                                        .gross_total_minor,
                                                )
                                            }}
                                            <span
                                                class="text-xs font-normal text-[var(--ui-ink-3)]"
                                                >{{
                                                    currencyLabel(
                                                        row.document
                                                            .currency_code,
                                                    )
                                                }}</span
                                            ></span
                                        >
                                        <span
                                            class="min-w-0 px-2 max-md:hidden"
                                        >
                                            <StatusBadge
                                                v-if="row.document.balance"
                                                :label="
                                                    row.document.balance.label
                                                "
                                                :tone="
                                                    row.document.balance.tone
                                                "
                                            />
                                            <span
                                                v-else
                                                class="text-[var(--ui-ink-3)]"
                                                >—<span class="sr-only">
                                                    Não se aplica à
                                                    cobrança</span
                                                ></span
                                            >
                                        </span>
                                        <span
                                            class="min-w-0 overflow-clip ps-5 pe-2 max-md:hidden"
                                        >
                                            <StatusBadge
                                                class="max-w-full"
                                                :label="
                                                    row.document.workflow_label
                                                "
                                                :tone="
                                                    statusTone(
                                                        row.document
                                                            .workflow_status,
                                                    )
                                                "
                                            />
                                        </span>
                                    </Link>
                                </div>
                            </template>
                        </InfiniteScroll>

                        <div
                            v-if="chosen.length > 0"
                            role="toolbar"
                            :aria-label="`${chosen.length} ${chosen.length === 1 ? 'documento escolhido' : 'documentos escolhidos'}`"
                            class="sticky inset-be-20 z-10 mx-auto my-3 flex w-fit max-w-[calc(100%-2rem)] items-center rounded-[10px] bg-[var(--ui-pop)] py-1 ps-3 pe-1 text-[0.78125rem] whitespace-nowrap shadow-[var(--ui-pop-shadow)] lg:inset-be-6"
                        >
                            <span
                                class="flex items-center gap-2 pe-2.5 font-semibold text-[var(--ui-ink)] shadow-[inset_-1px_0_0_var(--ui-line-strong)]"
                                aria-live="polite"
                            >
                                <span
                                    class="grid h-[1.125rem] min-w-[1.125rem] place-items-center rounded-[5px] bg-accent-400 px-1 numeric text-[0.6875rem] font-bold text-[#1a1407]"
                                    >{{ chosen.length }}</span
                                >
                                {{
                                    chosen.length === 1
                                        ? 'escolhido'
                                        : 'escolhidos'
                                }}
                            </span>
                            <Link
                                v-if="
                                    canRemindCustomers &&
                                    chosenCustomers.length > 0
                                "
                                :href="remindChosenUrl"
                                class="ms-1 inline-flex h-[1.875rem] items-center gap-1.5 rounded-[7px] px-2.5 font-[550] text-[var(--ui-ink)] focus-ring hover:bg-[var(--ui-wash)]"
                            >
                                <BellRing class="size-3.5" aria-hidden="true" />
                                Lembrar
                            </Link>
                            <a
                                v-if="chosen.length <= MAX_PDF_DOWNLOAD"
                                :href="downloadChosenUrl"
                                class="inline-flex h-[1.875rem] items-center gap-1.5 rounded-[7px] px-2.5 font-[550] text-[var(--ui-ink)] focus-ring hover:bg-[var(--ui-wash)]"
                            >
                                <Download class="size-3.5" aria-hidden="true" />
                                PDF
                            </a>
                            <span
                                v-else
                                class="px-2.5 text-xs text-[var(--ui-ink-3)]"
                                >PDF: até {{ MAX_PDF_DOWNLOAD }} de cada
                                vez</span
                            >
                            <button
                                type="button"
                                class="grid size-[1.875rem] place-items-center rounded-[7px] text-[var(--ui-ink-3)] focus-ring hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)]"
                                @click="chosen = []"
                            >
                                <X
                                    class="size-3.5"
                                    :stroke-width="2.2"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">Limpar escolha</span>
                            </button>
                        </div>
                    </template>
                </section>

                <!-- --------------------------------------------- inspector -->
                <Teleport to="body" :disabled="inspectorBeside">
                    <div
                        v-if="selected"
                        class="fixed inset-0 z-40 bg-black/20 xl:hidden dark:bg-black/50"
                        aria-hidden="true"
                        @click="closeDocument"
                    />
                    <aside
                        v-if="selected"
                        ref="detailPanel"
                        tabindex="-1"
                        :aria-labelledby="'document-detail-title'"
                        class="fixed inset-y-0 inset-e-0 z-50 flex w-full flex-col overflow-y-auto overscroll-contain bg-[var(--surface-raised)] shadow-[var(--ui-pop-shadow)] outline-none sm:w-[21rem] xl:sticky xl:inset-bs-20 xl:z-auto xl:max-h-[calc(100dvh-6rem)] xl:shrink-0 xl:rounded-xl xl:shadow-[var(--ui-card-shadow)]"
                    >
                        <div
                            class="sticky inset-bs-0 z-10 flex h-11 shrink-0 items-center gap-1 border-b border-[var(--ui-line)] bg-[var(--surface-raised)] ps-5 pe-3 xl:bg-[var(--surface-page)]"
                        >
                            <span
                                class="flex min-w-0 flex-1 items-center gap-2 text-[0.78125rem] text-[var(--ui-ink-2)]"
                            >
                                <span
                                    class="grid h-[1.125rem] w-[1.375rem] shrink-0 place-items-center rounded bg-[var(--ui-well)] text-[0.59375rem] font-bold"
                                    aria-hidden="true"
                                    >{{ typeCode(selected) }}</span
                                >
                                <span
                                    id="document-detail-title"
                                    class="truncate font-medium text-[var(--ui-ink)]"
                                    >{{ shortNumber(selected) }}</span
                                >
                            </span>
                            <component
                                :is="neighbours.previous ? Link : 'span'"
                                :href="
                                    neighbours.previous
                                        ? documentUrl(
                                              neighbours.previous.public_id,
                                          )
                                        : undefined
                                "
                                preserve-state
                                preserve-scroll
                                replace
                                :only="['selected']"
                                class="grid size-7 place-items-center rounded-md text-[var(--ui-ink-2)] focus-ring"
                                :class="
                                    neighbours.previous
                                        ? 'hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)]'
                                        : 'opacity-40'
                                "
                                :aria-disabled="!neighbours.previous"
                                title="Documento anterior (k)"
                            >
                                <ChevronUp
                                    class="size-[0.9375rem]"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">Documento anterior</span>
                            </component>
                            <component
                                :is="neighbours.next ? Link : 'span'"
                                :href="
                                    neighbours.next
                                        ? documentUrl(neighbours.next.public_id)
                                        : undefined
                                "
                                preserve-state
                                preserve-scroll
                                replace
                                :only="['selected']"
                                class="grid size-7 place-items-center rounded-md text-[var(--ui-ink-2)] focus-ring"
                                :class="
                                    neighbours.next
                                        ? 'hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)]'
                                        : 'opacity-40'
                                "
                                :aria-disabled="!neighbours.next"
                                title="Documento seguinte (j)"
                            >
                                <ChevronDown
                                    class="size-[0.9375rem]"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">Documento seguinte</span>
                            </component>
                            <button
                                type="button"
                                class="grid size-7 place-items-center rounded-md text-[var(--ui-ink-2)] focus-ring hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)] pointer-coarse:size-11"
                                title="Fechar (Esc)"
                                @click="closeDocument"
                            >
                                <X
                                    class="size-3.5"
                                    :stroke-width="2.2"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">Fechar documento</span>
                            </button>
                        </div>

                        <div class="flex flex-col gap-4 p-5 pbe-8">
                            <div class="flex flex-col gap-1.5">
                                <component
                                    :is="
                                        selected.customer_public_id
                                            ? Link
                                            : 'span'
                                    "
                                    :href="
                                        selected.customer_public_id
                                            ? customerShow.url(
                                                  selected.customer_public_id,
                                              )
                                            : undefined
                                    "
                                    class="w-fit rounded text-[0.9375rem] font-semibold text-[var(--ui-ink)]"
                                    :class="
                                        selected.customer_public_id
                                            ? 'focus-ring hover:underline hover:decoration-[var(--ui-line-strong)] hover:underline-offset-2'
                                            : ''
                                    "
                                    >{{ selected.customer_name }}</component
                                >
                                <p class="flex items-baseline gap-1">
                                    <span
                                        class="numeric text-[1.75rem] leading-[1.15] font-[650] tracking-[-0.03em] text-[var(--ui-ink)]"
                                        >{{
                                            wholeAmount(
                                                selected.snapshot
                                                    .gross_total_minor,
                                            )
                                        }}</span
                                    >
                                    <span
                                        class="text-xs font-semibold text-[var(--ui-ink-3)]"
                                        >{{
                                            currencyLabel(
                                                selected.currency_code,
                                            )
                                        }}</span
                                    >
                                </p>
                                <p class="flex flex-wrap gap-1.5 pbs-0.5">
                                    <StatusBadge
                                        :label="selected.workflow_label"
                                        :tone="
                                            statusTone(selected.workflow_status)
                                        "
                                        :pulse="
                                            workflowIsActive(
                                                selected.workflow_status,
                                            )
                                        "
                                    />
                                    <StatusBadge
                                        v-if="settlement"
                                        :label="settlement.label"
                                        :tone="settlement.tone"
                                    />
                                </p>
                            </div>

                            <div
                                v-if="
                                    selected.can_edit ||
                                    selected.can_send ||
                                    selected.can_print
                                "
                                class="grid grid-cols-2 gap-2"
                            >
                                <AppButton
                                    v-if="selected.can_edit"
                                    variant="gold"
                                    size="sm"
                                    class="col-span-2"
                                    :href="editInvoice.url(selected.public_id)"
                                >
                                    <PencilLine
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Continuar rascunho
                                </AppButton>
                                <template v-else>
                                    <AppButton
                                        v-if="selected.can_send"
                                        variant="gold"
                                        size="sm"
                                        :class="
                                            selected.can_print
                                                ? ''
                                                : 'col-span-2'
                                        "
                                        :disabled="
                                            sendingDocument ===
                                            selected.public_id
                                        "
                                        @click="sendDocument(selected)"
                                    >
                                        <LoaderCircle
                                            v-if="
                                                sendingDocument ===
                                                selected.public_id
                                            "
                                            class="size-3.5 animate-spin"
                                            aria-hidden="true"
                                        />
                                        <Send
                                            v-else
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {{
                                            selected.sent_at
                                                ? 'Reenviar ao cliente'
                                                : 'Enviar ao cliente'
                                        }}
                                    </AppButton>
                                    <AppButton
                                        v-if="selected.can_print"
                                        variant="outline"
                                        size="sm"
                                        external
                                        :class="
                                            selected.can_send
                                                ? ''
                                                : 'col-span-2'
                                        "
                                        :href="
                                            documentPdf.url(selected.public_id)
                                        "
                                    >
                                        <Download
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        PDF
                                    </AppButton>
                                </template>
                            </div>

                            <div
                                role="tablist"
                                aria-label="Sobre este documento"
                                class="flex gap-4 border-b border-[var(--ui-line)]"
                            >
                                <button
                                    v-for="tab in inspectorTabs"
                                    :id="`inspector-tab-${tab.value}`"
                                    :key="tab.value"
                                    type="button"
                                    role="tab"
                                    :aria-selected="inspectorTab === tab.value"
                                    aria-controls="inspector-panel"
                                    :tabindex="
                                        inspectorTab === tab.value ? 0 : -1
                                    "
                                    class="-mbe-px rounded-t py-2 text-[0.78125rem] focus-ring"
                                    :class="
                                        inspectorTab === tab.value
                                            ? 'font-semibold text-[var(--ui-ink)] shadow-[inset_0_-2px_0_var(--ui-ink)]'
                                            : 'font-medium text-[var(--ui-ink-3)] hover:text-[var(--ui-ink)]'
                                    "
                                    @click="inspectorTab = tab.value"
                                >
                                    {{ tab.label }}
                                </button>
                            </div>

                            <div
                                id="inspector-panel"
                                role="tabpanel"
                                :aria-labelledby="`inspector-tab-${inspectorTab}`"
                            >
                                <!-- Estado: every stage the record has really reached. -->
                                <template v-if="inspectorTab === 'state'">
                                    <ol aria-label="Estado do documento">
                                        <li
                                            v-for="(step, index) in selected
                                                .snapshot.steps"
                                            :key="step.key"
                                            class="flex gap-3"
                                            :aria-current="
                                                step.state === 'current'
                                                    ? 'step'
                                                    : undefined
                                            "
                                        >
                                            <span
                                                class="flex w-3.5 flex-col items-center pbs-0.5"
                                                aria-hidden="true"
                                            >
                                                <span
                                                    class="grid size-3.5 shrink-0 place-items-center rounded-full"
                                                    :class="stepDot[step.state]"
                                                >
                                                    <Check
                                                        v-if="
                                                            step.state ===
                                                            'done'
                                                        "
                                                        class="size-2"
                                                        :stroke-width="4.5"
                                                    />
                                                    <X
                                                        v-else-if="
                                                            step.state ===
                                                            'error'
                                                        "
                                                        class="size-2"
                                                        :stroke-width="4.5"
                                                    />
                                                </span>
                                                <span
                                                    v-if="
                                                        index <
                                                        selected.snapshot.steps
                                                            .length -
                                                            1
                                                    "
                                                    class="my-1 w-[1.5px] flex-1"
                                                    :class="
                                                        selected.snapshot.steps[
                                                            index + 1
                                                        ].state === 'todo'
                                                            ? 'bg-[var(--ui-line-strong)]'
                                                            : 'bg-[var(--ui-positive)]'
                                                    "
                                                />
                                            </span>
                                            <span
                                                class="flex min-w-0 flex-1 flex-col gap-px pbe-3.5"
                                            >
                                                <span
                                                    class="text-[0.8125rem] font-[550]"
                                                    :class="
                                                        step.state === 'todo'
                                                            ? 'text-[var(--ui-ink-3)]'
                                                            : step.state ===
                                                                'error'
                                                              ? 'text-[var(--ui-critical)]'
                                                              : 'text-[var(--ui-ink)]'
                                                    "
                                                    >{{ step.label }}</span
                                                >
                                                <span
                                                    class="text-xs text-[var(--ui-ink-3)] first-letter:uppercase"
                                                    >{{ stepTime(step) }}</span
                                                >
                                            </span>
                                        </li>
                                    </ol>

                                    <div
                                        v-if="selected.snapshot.agt_message"
                                        class="mbs-1 rounded-lg px-3 py-2.5"
                                        :class="
                                            statusTone(
                                                selected.workflow_status,
                                            ) === 'danger'
                                                ? 'bg-[var(--ui-critical-wash)]'
                                                : 'bg-[var(--ui-well)]'
                                        "
                                    >
                                        <p
                                            class="text-xs font-semibold"
                                            :class="
                                                statusTone(
                                                    selected.workflow_status,
                                                ) === 'danger'
                                                    ? 'text-[var(--ui-critical)]'
                                                    : 'text-[var(--ui-ink-2)]'
                                            "
                                        >
                                            Observação da AGT
                                        </p>
                                        <p
                                            class="mt-0.5 text-[0.8125rem]/5 text-[var(--ui-ink)]"
                                        >
                                            {{ selected.snapshot.agt_message }}
                                        </p>
                                        <p
                                            v-if="
                                                selected.snapshot
                                                    .agt_error_codes.length >
                                                    0 ||
                                                selected.snapshot
                                                    .agt_operational.observed_at
                                            "
                                            class="mt-1 text-xs text-[var(--ui-ink-3)]"
                                        >
                                            <span class="font-mono">{{
                                                selected.snapshot.agt_error_codes.join(
                                                    ' · ',
                                                )
                                            }}</span>
                                            <template
                                                v-if="
                                                    selected.snapshot
                                                        .agt_operational
                                                        .observed_at
                                                "
                                            >
                                                {{
                                                    selected.snapshot
                                                        .agt_error_codes
                                                        .length > 0
                                                        ? ' · '
                                                        : ''
                                                }}Observada a
                                                {{
                                                    formatDateTime(
                                                        selected.snapshot
                                                            .agt_operational
                                                            .observed_at,
                                                    )
                                                }}
                                            </template>
                                        </p>
                                    </div>
                                </template>

                                <!-- Histórico: who did what, and when. -->
                                <template
                                    v-else-if="inspectorTab === 'history'"
                                >
                                    <p
                                        v-if="selected.history.length === 0"
                                        class="text-[0.8125rem]/5 text-[var(--ui-ink-3)]"
                                    >
                                        {{
                                            selected.status === 'draft'
                                                ? 'Um rascunho ainda não tem histórico fiscal: nada foi emitido nem enviado.'
                                                : 'Sem acontecimentos registados.'
                                        }}
                                    </p>
                                    <ol v-else>
                                        <li
                                            v-for="(
                                                event, index
                                            ) in selected.history"
                                            :key="`${event.type}-${event.occurred_at}-${index}`"
                                            class="flex gap-3"
                                        >
                                            <span
                                                class="flex w-3.5 flex-col items-center pbs-[0.3125rem]"
                                                aria-hidden="true"
                                            >
                                                <span
                                                    class="size-2 shrink-0 rounded-full"
                                                    :class="{
                                                        'bg-[var(--ui-positive)]':
                                                            event.tone ===
                                                            'done',
                                                        'bg-[var(--ui-critical)]':
                                                            event.tone ===
                                                            'error',
                                                        'bg-accent-400':
                                                            event.tone ===
                                                            'pending',
                                                    }"
                                                />
                                                <span
                                                    v-if="
                                                        index <
                                                        selected.history
                                                            .length -
                                                            1
                                                    "
                                                    class="mt-1 w-px flex-1 bg-[var(--ui-line)]"
                                                />
                                            </span>
                                            <span
                                                class="flex min-w-0 flex-1 flex-col gap-px pbe-3.5"
                                            >
                                                <span
                                                    class="text-[0.8125rem] font-[550] text-[var(--ui-ink)]"
                                                    >{{ event.label }}</span
                                                >
                                                <span
                                                    class="text-xs text-[var(--ui-ink-3)]"
                                                    >{{
                                                        formatDateTime(
                                                            event.occurred_at,
                                                        )
                                                    }}<template
                                                        v-if="eventMeta(event)"
                                                    >
                                                        ·
                                                        {{
                                                            eventMeta(event)
                                                        }}</template
                                                    ></span
                                                >
                                            </span>
                                        </li>
                                    </ol>
                                </template>

                                <!-- Detalhes: the facts someone checks when a document is questioned. -->
                                <dl
                                    v-else
                                    class="grid grid-cols-[minmax(0,7rem)_minmax(0,1fr)] gap-x-3 gap-y-2.5 text-[0.8125rem]"
                                >
                                    <dt class="text-[var(--ui-ink-3)]">Tipo</dt>
                                    <dd class="text-[var(--ui-ink)]">
                                        {{ selected.snapshot.type_label }}
                                    </dd>
                                    <dt class="text-[var(--ui-ink-3)]">
                                        Local
                                    </dt>
                                    <dd class="text-[var(--ui-ink)]">
                                        {{ selected.establishment.name }}
                                    </dd>
                                    <dt class="text-[var(--ui-ink-3)]">NIF</dt>
                                    <dd class="numeric text-[var(--ui-ink)]">
                                        {{
                                            selected.customer_tax_identification_number
                                        }}
                                    </dd>
                                    <dt class="text-[var(--ui-ink-3)]">Data</dt>
                                    <dd class="text-[var(--ui-ink)]">
                                        {{ formatDate(selected.document_date) }}
                                    </dd>
                                    <template v-if="selected.issued_at">
                                        <dt class="text-[var(--ui-ink-3)]">
                                            Emitida
                                        </dt>
                                        <dd class="text-[var(--ui-ink)]">
                                            {{
                                                formatDateTime(
                                                    selected.issued_at,
                                                )
                                            }}
                                            <span
                                                v-if="
                                                    selected.snapshot
                                                        .issuer_name
                                                "
                                                class="block text-xs text-[var(--ui-ink-3)]"
                                                >por
                                                {{
                                                    selected.snapshot
                                                        .issuer_name
                                                }}</span
                                            >
                                        </dd>
                                    </template>
                                    <dt class="text-[var(--ui-ink-3)]">
                                        Vencimento
                                    </dt>
                                    <dd class="text-[var(--ui-ink)]">
                                        {{
                                            selected.snapshot.due_date
                                                ? formatDate(
                                                      selected.snapshot
                                                          .due_date,
                                                  )
                                                : 'Sem vencimento'
                                        }}
                                    </dd>
                                    <dt class="text-[var(--ui-ink-3)]">
                                        Total
                                    </dt>
                                    <dd class="numeric text-[var(--ui-ink)]">
                                        {{
                                            money(
                                                selected.snapshot
                                                    .gross_total_minor,
                                                selected.currency_code,
                                            )
                                        }}
                                    </dd>
                                    <dt class="text-[var(--ui-ink-3)]">IVA</dt>
                                    <dd class="numeric text-[var(--ui-ink)]">
                                        {{
                                            money(
                                                selected.snapshot
                                                    .tax_payable_minor,
                                                selected.currency_code,
                                            )
                                        }}
                                        <span
                                            v-if="
                                                selected.snapshot.lines_count >
                                                0
                                            "
                                            class="block text-xs text-[var(--ui-ink-3)]"
                                            >em
                                            {{ selected.snapshot.lines_count }}
                                            {{
                                                selected.snapshot
                                                    .lines_count === 1
                                                    ? 'linha'
                                                    : 'linhas'
                                            }}</span
                                        >
                                    </dd>
                                    <template
                                        v-if="
                                            selected.status !== 'draft' &&
                                            selected.snapshot
                                                .outstanding_minor > 0
                                        "
                                    >
                                        <dt class="text-[var(--ui-ink-3)]">
                                            Por receber
                                        </dt>
                                        <dd
                                            class="numeric"
                                            :class="
                                                selected.snapshot
                                                    .days_past_due > 0
                                                    ? 'text-[var(--ui-critical)]'
                                                    : 'text-[var(--ui-ink)]'
                                            "
                                        >
                                            {{
                                                money(
                                                    selected.snapshot
                                                        .outstanding_minor,
                                                    selected.currency_code,
                                                )
                                            }}
                                        </dd>
                                    </template>
                                    <template v-if="selected.sent_at">
                                        <dt class="text-[var(--ui-ink-3)]">
                                            Enviada
                                        </dt>
                                        <dd
                                            class="min-w-0 text-[var(--ui-ink)]"
                                        >
                                            {{
                                                formatDateTime(selected.sent_at)
                                            }}
                                            <span
                                                v-if="selected.delivery_email"
                                                class="block truncate text-xs text-[var(--ui-ink-3)]"
                                                >para
                                                {{
                                                    selected.delivery_email
                                                }}</span
                                            >
                                        </dd>
                                    </template>
                                    <template
                                        v-if="selected.submission?.request_id"
                                    >
                                        <dt class="text-[var(--ui-ink-3)]">
                                            Pedido AGT
                                        </dt>
                                        <dd
                                            class="min-w-0 truncate font-mono text-xs text-[var(--ui-ink-2)]"
                                            :title="
                                                selected.submission.request_id
                                            "
                                        >
                                            {{ selected.submission.request_id }}
                                        </dd>
                                    </template>
                                </dl>
                            </div>

                            <div
                                class="flex flex-col border-t border-[var(--ui-line)] pbs-2"
                            >
                                <Link
                                    v-if="selected.credit_note?.available"
                                    :href="
                                        createInvoice.url({
                                            query: {
                                                corrigir: selected.public_id,
                                            },
                                        })
                                    "
                                    class="flex h-8 items-center gap-2 rounded-md text-[0.78125rem] text-[var(--ui-ink-2)] focus-ring hover:text-[var(--ui-ink)]"
                                >
                                    <FileMinus
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Corrigir com nota de crédito
                                </Link>
                                <p
                                    v-else-if="selected.credit_note?.reason"
                                    class="flex items-start gap-2 py-1.5 text-[0.78125rem] text-[var(--ui-ink-3)]"
                                >
                                    <FileMinus
                                        class="mt-0.5 size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span
                                        >Nota de crédito:
                                        {{ selected.credit_note.reason }}</span
                                    >
                                </p>
                                <Link
                                    v-if="selected.can_repeat"
                                    :href="
                                        createInvoice.url({
                                            query: {
                                                repeat: selected.public_id,
                                            },
                                        })
                                    "
                                    class="flex h-8 items-center gap-2 rounded-md text-[0.78125rem] text-[var(--ui-ink-2)] focus-ring hover:text-[var(--ui-ink)]"
                                >
                                    <Copy class="size-3.5" aria-hidden="true" />
                                    Usar como base para novo documento
                                </Link>
                                <a
                                    v-if="selected.can_print"
                                    :href="
                                        printDocument.url(selected.public_id)
                                    "
                                    class="flex h-8 items-center gap-2 rounded-md text-[0.78125rem] text-[var(--ui-ink-2)] focus-ring hover:text-[var(--ui-ink)]"
                                >
                                    <Printer
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Abrir para imprimir
                                </a>
                                <Link
                                    v-if="selected.customer_public_id"
                                    :href="
                                        customerShow.url(
                                            selected.customer_public_id,
                                        )
                                    "
                                    class="flex h-8 items-center gap-2 rounded-md text-[0.78125rem] text-[var(--ui-ink-2)] focus-ring hover:text-[var(--ui-ink)]"
                                >
                                    <UserRound
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Ver cliente
                                </Link>
                                <Link
                                    v-if="selected.submission"
                                    :href="agtSubmissions.url()"
                                    class="flex h-8 items-center gap-2 rounded-md text-[0.78125rem] text-[var(--ui-ink-2)] focus-ring hover:text-[var(--ui-ink)]"
                                >
                                    <ArrowUpRight
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Acompanhar no Monitor AGT
                                </Link>
                            </div>
                        </div>
                    </aside>
                </Teleport>
            </div>
        </div>
    </AppLayout>
</template>
