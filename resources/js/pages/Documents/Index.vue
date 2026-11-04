<script setup lang="ts">
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Head, Link, router, usePage, InfiniteScroll } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Check,
    ChevronDown,
    Clock3,
    BellRing,
    Download,
    FileText,
    FilterX,
    LoaderCircle,
    PencilLine,
    Plus,
    UserRound,
    Printer,
    Search,
    Send,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DateInput from '@/components/DateInput.vue';
import DocumentLifecycle from '@/components/DocumentLifecycle.vue';
import type { LifecycleStep } from '@/components/DocumentLifecycle.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import LoadMore from '@/components/LoadMore.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { buttonGold } from '@/lib/button';
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
 * Below 80rem the panel sits above the list, so a tap on a row would change
 * something off screen. Bring it into view and move focus into it; from 80rem
 * the panel is beside the list and nothing moves.
 */
watch(
    () => props.selected?.public_id,
    async (publicId) => {
        if (
            publicId === undefined ||
            window.matchMedia('(min-width: 80rem)').matches
        ) {
            return;
        }

        await nextTick();
        detailPanel.value?.scrollIntoView({ block: 'start', behavior: 'auto' });
        detailPanel.value?.focus({ preventScroll: true });
    },
);

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
    maximumFractionDigits: 0,
});
const longDateFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
});
const shortDateFormatter = new Intl.DateTimeFormat('pt-AO', {
    day: 'numeric',
    month: 'short',
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
    return wholeAmountFormatter.format(Math.round(minor / 100));
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

function formatShortDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return shortDateFormatter.format(
        new Date(`${value.slice(0, 10)}T12:00:00`),
    );
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

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell">
                <FlashBanner />

                <!-- ------------------------------------------------ header -->
                <header
                    class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <p
                            class="text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Registos
                        </p>
                        <h1
                            class="mt-2.5 text-page-title leading-[1.08] font-light tracking-[-0.028em] text-zinc-950 dark:text-white"
                        >
                            Documentos
                        </h1>
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5">
                        <form
                            class="field-adorned w-full [--field-adorn-start:2.5rem] sm:w-80"
                            role="search"
                            @submit.prevent="applyFilters"
                        >
                            <Search
                                class="field-adornment-start size-4"
                                aria-hidden="true"
                            />
                            <label for="register-search" class="sr-only"
                                >Procurar documentos</label
                            >
                            <input
                                id="register-search"
                                v-model="search"
                                type="search"
                                placeholder="Número, cliente ou NIF…"
                                autocomplete="off"
                                spellcheck="false"
                                enterkeyhint="search"
                                class="form-search"
                            />
                        </form>

                        <Menu
                            v-if="permissions.create"
                            as="div"
                            class="relative z-20"
                        >
                            <MenuButton :class="buttonGold">
                                <Plus class="size-4" aria-hidden="true" />
                                Novo documento
                                <ChevronDown
                                    class="size-4"
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
                                    class="absolute left-0 z-30 mt-2 w-80 max-w-[calc(100vw-2rem)] origin-top-left menu-panel p-1.5 sm:right-0 sm:left-auto sm:origin-top-right"
                                >
                                    <p
                                        class="px-3 pt-2 pb-2 text-xs font-medium tracking-[0.07em] text-zinc-400 uppercase"
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
                                                    query: {
                                                        type: entry.value,
                                                    },
                                                })
                                            "
                                            :class="[
                                                active
                                                    ? 'bg-zinc-100 dark:bg-white/5'
                                                    : '',
                                                'flex items-start gap-3 menu-item py-2.5 focus:outline-none',
                                            ]"
                                        >
                                            <span
                                                class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-zinc-900/[0.06] font-mono text-xs font-semibold text-zinc-700 dark:bg-white/10 dark:text-zinc-200"
                                            >
                                                {{ entry.value }}
                                            </span>
                                            <span>
                                                <span
                                                    class="block text-sm font-semibold"
                                                    >{{ entry.label }}</span
                                                >
                                                <span
                                                    class="mt-0.5 block text-xs/5 text-zinc-500 dark:text-zinc-400"
                                                    >{{ entry.detail }}</span
                                                >
                                            </span>
                                        </Link>
                                    </MenuItem>
                                </MenuItems>
                            </transition>
                        </Menu>
                    </div>
                </header>

                <!-- ---------------------------------------------- segments -->
                <div
                    class="mt-5 flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b border-zinc-900/10 pb-3 dark:border-white/10"
                >
                    <div
                        class="flex flex-wrap items-center gap-1"
                        role="group"
                        aria-label="Filtrar por estado"
                    >
                        <button
                            v-for="segment in statusSegments"
                            :key="segment.value"
                            type="button"
                            :aria-pressed="status === segment.value"
                            class="inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-[0.8125rem] font-medium focus-ring transition pointer-coarse:h-11"
                            :class="
                                status === segment.value
                                    ? 'bg-brand-950 text-white dark:bg-zinc-100 dark:text-brand-950'
                                    : 'text-zinc-600 hover:bg-zinc-900/[0.05] hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white'
                            "
                            @click="selectStatus(segment.value)"
                        >
                            {{ segment.label }}
                            <span
                                class="numeric"
                                :class="
                                    status === segment.value
                                        ? 'text-white/60 dark:text-brand-950/60'
                                        : segment.value === 'attention' &&
                                            segment.count > 0
                                          ? 'font-semibold text-rose-600 dark:text-rose-400'
                                          : 'text-zinc-400'
                                "
                                >{{ segment.count }}</span
                            >
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-1">
                        <div
                            class="flex flex-wrap items-center gap-1"
                            role="group"
                            aria-label="Família de documentos"
                        >
                            <button
                                v-for="option in options.families"
                                :key="String(option.value)"
                                type="button"
                                :aria-pressed="family === option.value"
                                class="inline-flex h-8 items-center rounded-full px-3 text-[0.8125rem] font-medium focus-ring transition pointer-coarse:h-11"
                                :class="
                                    family === option.value
                                        ? 'bg-zinc-900/[0.06] text-zinc-950 dark:bg-white/10 dark:text-white'
                                        : 'text-zinc-500 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white'
                                "
                                @click="selectFamily(String(option.value))"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <AppButton
                            variant="quiet"
                            size="lg"
                            :aria-expanded="showFilters"
                            aria-controls="register-filters"
                            @click="showFilters = !showFilters"
                        >
                            <SlidersHorizontal
                                class="size-4"
                                aria-hidden="true"
                            />
                            Filtros
                            <span
                                v-if="advancedFilterCount > 0"
                                class="rounded-full bg-brand-950 px-1.5 text-xs font-semibold text-white dark:bg-zinc-100 dark:text-brand-950"
                                >{{ advancedFilterCount }}</span
                            >
                        </AppButton>
                        <AppButton
                            variant="quiet"
                            size="lg"
                            v-if="activeFilterCount > 0"
                            @click="clearFilters"
                        >
                            <FilterX class="size-4" aria-hidden="true" />
                            Limpar
                        </AppButton>
                    </div>
                </div>

                <form
                    v-if="showFilters"
                    id="register-filters"
                    class="mt-4 grid gap-3 rounded-3xl bg-zinc-900/[0.04] p-4 sm:grid-cols-2 xl:grid-cols-[repeat(4,minmax(0,1fr))_auto] dark:bg-white/[0.04]"
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
                    <AppButton variant="ink" size="lg" type="submit">
                        Aplicar
                    </AppButton>
                </form>

                <!-- ------------------------------------------ queue + detail -->
                <div
                    class="mt-4 grid items-start gap-5 xl:grid-cols-[26rem_minmax(0,1fr)]"
                >
                    <section
                        aria-label="Documentos"
                        :aria-busy="loading"
                        class="min-w-0 transition-opacity duration-150 ease-out"
                        :class="loading ? 'opacity-60 delay-150' : ''"
                    >
                        <div
                            v-if="documents.data.length === 0"
                            class="grid place-items-center rounded-3xl bg-zinc-900/[0.04] px-6 py-14 text-center dark:bg-white/[0.04]"
                        >
                            <FileText
                                class="size-6 text-zinc-400"
                                aria-hidden="true"
                            />
                            <h2
                                class="mt-4 text-lg font-normal tracking-[-0.01em] text-zinc-950 dark:text-white"
                            >
                                {{
                                    hasDocuments
                                        ? 'Nenhum documento com estes filtros'
                                        : 'Ainda não há documentos'
                                }}
                            </h2>
                            <p
                                class="mt-1.5 max-w-xs text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    hasDocuments
                                        ? 'Experimente outro estado, família ou intervalo de datas.'
                                        : 'O primeiro documento começa como rascunho. Só segue para a AGT depois de o emitir.'
                                }}
                            </p>
                            <AppButton
                                variant="outline"
                                size="lg"
                                v-if="hasDocuments"
                                @click="clearFilters"
                                class="mt-5"
                            >
                                Limpar filtros
                            </AppButton>
                            <AppButton
                                variant="gold"
                                size="lg"
                                v-else-if="permissions.create"
                                :href="createInvoice.url()"
                                class="mt-5"
                            >
                                <Plus class="size-4" aria-hidden="true" />
                                Criar factura
                            </AppButton>
                        </div>

                        <div v-else>
                            <InfiniteScroll
                                data="documents"
                                :buffer="400"
                                :manual-after="4"
                                class="grid grid-cols-[minmax(0,1fr)] gap-0.5"
                            >
                                <template #next="scroll"
                                    ><LoadMore
                                        :shown="documents.data.length"
                                        :total="documents.total"
                                        :loading="scroll.loading"
                                        :has-more="scroll.hasMore"
                                        :manual-mode="scroll.manualMode"
                                        :fetch="scroll.fetch"
                                /></template>
                                <div
                                    v-for="document in documents.data"
                                    :key="document.public_id"
                                    data-nav-item
                                    class="group flex items-center"
                                >
                                    <label
                                        class="grid w-7 shrink-0 cursor-pointer place-items-center self-stretch transition group-hover:opacity-100 focus-within:opacity-100 pointer-coarse:w-9 pointer-coarse:opacity-100"
                                        :class="
                                            chosen.length > 0
                                                ? 'opacity-100'
                                                : 'opacity-0'
                                        "
                                        :aria-hidden="!document.can_print"
                                    >
                                        <template v-if="document.can_print">
                                            <span class="sr-only"
                                                >Escolher
                                                {{ rowNumber(document) }}</span
                                            >
                                            <input
                                                type="checkbox"
                                                class="form-checkbox"
                                                :checked="
                                                    chosen.includes(
                                                        document.public_id,
                                                    )
                                                "
                                                @change="
                                                    toggleChosen(
                                                        document.public_id,
                                                    )
                                                "
                                            />
                                        </template>
                                    </label>
                                    <Link
                                        data-nav-row="open"
                                        :href="documentUrl(document.public_id)"
                                        preserve-state
                                        preserve-scroll
                                        replace
                                        prefetch
                                        cache-for="5s"
                                        :only="['selected']"
                                        :title="
                                            document.workflow_message ??
                                            undefined
                                        "
                                        :aria-current="
                                            selected?.public_id ===
                                            document.public_id
                                                ? 'true'
                                                : undefined
                                        "
                                        class="block min-w-0 flex-1 rounded-2xl px-3.5 py-2 text-left focus-ring transition pointer-coarse:py-3"
                                        :class="
                                            selected?.public_id ===
                                            document.public_id
                                                ? 'bg-white shadow-[inset_0_0_0_1.5px_var(--color-accent-400),0_10px_26px_-16px_rgb(143_91_0/0.4)] dark:bg-zinc-900'
                                                : 'hover:bg-zinc-900/[0.04] dark:hover:bg-white/[0.05]'
                                        "
                                        @click.capture="
                                            skipReopening(
                                                $event,
                                                document.public_id,
                                            )
                                        "
                                        @start="
                                            startOpening(document.public_id)
                                        "
                                        @finish="
                                            finishOpening(document.public_id)
                                        "
                                    >
                                        <span
                                            class="flex items-baseline justify-between gap-3"
                                        >
                                            <span
                                                :title="document.customer_name"
                                                class="truncate text-sm font-medium text-zinc-950 dark:text-white"
                                                >{{
                                                    document.customer_name
                                                }}</span
                                            >
                                            <span
                                                class="shrink-0 numeric text-sm font-medium text-zinc-950 dark:text-white"
                                                >{{
                                                    wholeAmount(
                                                        document.gross_total_minor,
                                                    )
                                                }}
                                                <span
                                                    class="text-xs font-normal text-zinc-400"
                                                    >{{
                                                        currencyLabel(
                                                            document.currency_code,
                                                        )
                                                    }}</span
                                                ></span
                                            >
                                        </span>
                                        <span
                                            class="mt-0.5 flex items-center justify-between gap-3"
                                        >
                                            <span
                                                class="min-w-0 truncate text-xs text-zinc-500 dark:text-zinc-400"
                                                ><span
                                                    :title="rowNumber(document)"
                                                    class="font-mono"
                                                    >{{
                                                        rowNumber(document)
                                                    }}</span
                                                >
                                                ·
                                                {{
                                                    formatShortDate(
                                                        document.document_date,
                                                    )
                                                }}</span
                                            >
                                            <span
                                                class="flex shrink-0 items-center gap-1.5"
                                            >
                                                <LoaderCircle
                                                    v-if="
                                                        openingDocument ===
                                                        document.public_id
                                                    "
                                                    class="size-3.5 animate-spin-delayed text-zinc-400"
                                                    aria-hidden="true"
                                                />
                                                <StatusBadge
                                                    :label="
                                                        document.workflow_label
                                                    "
                                                    :tone="
                                                        statusTone(
                                                            document.workflow_status,
                                                        )
                                                    "
                                                    :pulse="
                                                        workflowIsActive(
                                                            document.workflow_status,
                                                        )
                                                    "
                                                />
                                            </span>
                                        </span>
                                    </Link>
                                </div>
                            </InfiniteScroll>
                            <div
                                v-if="chosen.length > 0"
                                class="sticky bottom-20 z-10 mt-2 flex flex-wrap items-center gap-2 rounded-2xl bg-white p-2 pl-4 shadow-[0_0_0_1px_rgb(23_23_22/0.08),0_10px_26px_-12px_rgb(23_23_22/0.3)] lg:bottom-3 dark:bg-zinc-900 dark:shadow-[0_0_0_1px_rgb(255_255_255/0.12)]"
                                role="group"
                                aria-label="Acções para os documentos escolhidos"
                            >
                                <p
                                    class="me-auto text-sm font-medium text-zinc-950 dark:text-white"
                                    aria-live="polite"
                                >
                                    {{ chosen.length }}
                                    {{
                                        chosen.length === 1
                                            ? 'escolhido'
                                            : 'escolhidos'
                                    }}
                                </p>
                                <AppButton
                                    variant="outline"
                                    size="sm"
                                    v-if="
                                        canRemindCustomers &&
                                        chosenCustomers.length > 0
                                    "
                                    :href="remindChosenUrl"
                                >
                                    <BellRing
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <span class="sr-only">Preparar</span>
                                    Lembretes
                                </AppButton>
                                <AppButton
                                    variant="outline"
                                    size="sm"
                                    external
                                    v-if="chosen.length <= MAX_PDF_DOWNLOAD"
                                    :href="downloadChosenUrl"
                                >
                                    <Download
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <span class="sr-only">Descarregar</span>
                                    PDF
                                </AppButton>
                                <p
                                    v-else
                                    class="text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    PDF: até {{ MAX_PDF_DOWNLOAD }} de cada vez
                                </p>
                                <AppButton
                                    variant="quiet"
                                    size="sm"
                                    @click="chosen = []"
                                >
                                    Limpar
                                </AppButton>
                            </div>
                        </div>
                    </section>

                    <!-- ---------------------------------------------- detail -->
                    <section
                        v-if="selected"
                        ref="detailPanel"
                        tabindex="-1"
                        class="relative order-first min-w-0 scroll-mt-20 rounded-3xl bg-zinc-900/[0.04] p-6 focus-ring xl:sticky xl:top-24 xl:order-none xl:max-h-[calc(100dvh-7rem)] xl:overflow-y-auto xl:overscroll-contain dark:bg-white/[0.04]"
                        aria-labelledby="document-detail-title"
                    >
                        <div
                            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                        >
                            <div class="min-w-0 max-lg:pe-12">
                                <p
                                    class="text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    {{ selected.snapshot.type_label }} ·
                                    {{ selected.establishment.name }}
                                </p>
                                <h2
                                    id="document-detail-title"
                                    class="mt-2.5 font-mono text-[1.625rem] leading-none font-normal tracking-[-0.01em] text-zinc-950 dark:text-white"
                                >
                                    {{ selected.document_no ?? 'Rascunho' }}
                                </h2>
                                <p
                                    class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
                                >
                                    <Link
                                        v-if="selected.customer_public_id"
                                        :href="
                                            customerShow.url(
                                                selected.customer_public_id,
                                            )
                                        "
                                        class="rounded font-medium text-zinc-950 focus-ring hover:underline dark:text-white"
                                        >{{ selected.customer_name }}</Link
                                    >
                                    <template v-else>{{
                                        selected.customer_name
                                    }}</template>
                                    <template v-if="selected.issued_at">
                                        · emitida a
                                        {{ formatDateTime(selected.issued_at) }}
                                    </template>
                                    <template
                                        v-if="selected.snapshot.issuer_name"
                                    >
                                        por {{ selected.snapshot.issuer_name }}
                                    </template>
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2">
                                <AppButton
                                    variant="outline"
                                    size="lg"
                                    external
                                    v-if="selected.can_print"
                                    :href="documentPdf.url(selected.public_id)"
                                >
                                    <Download
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    PDF
                                </AppButton>
                                <AppButton
                                    variant="outline"
                                    size="lg"
                                    external
                                    v-if="selected.can_print"
                                    :href="
                                        printDocument.url(selected.public_id)
                                    "
                                >
                                    <Printer
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Abrir
                                </AppButton>
                                <AppButton
                                    variant="outline"
                                    size="lg"
                                    v-if="selected.can_send"
                                    :disabled="
                                        sendingDocument === selected.public_id
                                    "
                                    @click="sendDocument(selected)"
                                >
                                    <LoaderCircle
                                        v-if="
                                            sendingDocument ===
                                            selected.public_id
                                        "
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    <Send
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{
                                        selected.sent_at ? 'Reenviar' : 'Enviar'
                                    }}
                                </AppButton>
                                <AppButton
                                    variant="ink"
                                    size="lg"
                                    v-if="selected.can_edit"
                                    :href="editInvoice.url(selected.public_id)"
                                >
                                    <PencilLine
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Continuar rascunho
                                </AppButton>
                                <button
                                    type="button"
                                    class="grid size-[2.125rem] place-items-center rounded-full text-zinc-500 focus-ring transition hover:bg-zinc-900/[0.06] hover:text-zinc-950 max-lg:absolute max-lg:inset-e-4 max-lg:inset-bs-4 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white pointer-coarse:size-11"
                                    @click="closeDocument"
                                >
                                    <X class="size-4" aria-hidden="true" />
                                    <span class="sr-only"
                                        >Fechar documento</span
                                    >
                                </button>
                            </div>
                        </div>

                        <dl
                            class="mt-6 grid grid-cols-2 gap-y-5 border-y border-zinc-900/[0.07] py-5 lg:grid-cols-3 dark:border-white/10"
                        >
                            <div class="pe-4">
                                <dt
                                    class="text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Total
                                </dt>
                                <dd
                                    class="mt-2 numeric text-[1.375rem] leading-none tracking-[-0.025em] text-zinc-950 dark:text-white"
                                >
                                    {{
                                        wholeAmount(
                                            selected.snapshot.gross_total_minor,
                                        )
                                    }}<span
                                        class="ml-1 text-xs tracking-normal text-zinc-400"
                                        >{{
                                            currencyLabel(
                                                selected.currency_code,
                                            )
                                        }}</span
                                    >
                                </dd>
                                <dd
                                    class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{
                                        money(
                                            selected.snapshot.gross_total_minor,
                                            selected.currency_code,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div
                                class="border-s border-zinc-900/[0.07] px-4 dark:border-white/10"
                            >
                                <dt
                                    class="text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    IVA
                                </dt>
                                <dd
                                    class="mt-2 numeric text-[1.375rem] leading-none tracking-[-0.025em] text-zinc-950 dark:text-white"
                                >
                                    {{
                                        wholeAmount(
                                            selected.snapshot.tax_payable_minor,
                                        )
                                    }}
                                </dd>
                                <dd
                                    v-if="selected.snapshot.lines_count > 0"
                                    class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ selected.snapshot.lines_count }}
                                    {{
                                        selected.snapshot.lines_count === 1
                                            ? 'linha'
                                            : 'linhas'
                                    }}
                                </dd>
                            </div>
                            <div
                                class="pe-4 lg:border-s lg:border-zinc-900/[0.07] lg:ps-4 dark:lg:border-white/10"
                            >
                                <dt
                                    class="text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    {{
                                        selected.snapshot.outstanding_minor > 0
                                            ? 'Por receber'
                                            : 'Vencimento'
                                    }}
                                </dt>
                                <dd
                                    class="mt-2 numeric text-[1.375rem] leading-none tracking-[-0.025em]"
                                    :class="
                                        selected.snapshot.days_past_due > 0
                                            ? 'text-rose-600 dark:text-rose-400'
                                            : 'text-zinc-950 dark:text-white'
                                    "
                                >
                                    {{
                                        selected.snapshot.outstanding_minor > 0
                                            ? wholeAmount(
                                                  selected.snapshot
                                                      .outstanding_minor,
                                              )
                                            : formatShortDate(
                                                  selected.snapshot.due_date,
                                              )
                                    }}
                                </dd>
                                <dd
                                    class="mt-1.5 text-xs"
                                    :class="
                                        selected.snapshot.days_past_due > 0
                                            ? 'text-rose-600 dark:text-rose-400'
                                            : 'text-zinc-500 dark:text-zinc-400'
                                    "
                                >
                                    {{
                                        selected.snapshot.days_past_due > 0
                                            ? `vencida há ${selected.snapshot.days_past_due} ${selected.snapshot.days_past_due === 1 ? 'dia' : 'dias'}`
                                            : selected.snapshot.due_date
                                              ? `vence a ${formatDate(selected.snapshot.due_date)}`
                                              : 'sem vencimento'
                                    }}
                                </dd>
                            </div>
                            <div
                                class="col-span-full flex items-center justify-between gap-x-4 gap-y-2 border-t border-zinc-900/[0.07] pt-5 dark:border-white/10"
                            >
                                <dt
                                    class="text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    AGT
                                </dt>
                                <dd
                                    class="flex min-w-0 flex-wrap items-center justify-end gap-x-3 gap-y-1"
                                >
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
                                    <span
                                        v-if="selected.submission?.request_id"
                                        class="min-w-0 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400"
                                        :title="selected.submission.request_id"
                                    >
                                        {{ selected.submission.request_id }}
                                    </span>
                                </dd>
                            </div>
                        </dl>

                        <div
                            v-if="selected.snapshot.agt_message"
                            class="mt-5 rounded-2xl bg-white p-4 shadow-[0_1px_2px_rgb(23_23_22/0.04),0_6px_16px_-8px_rgb(23_23_22/0.12)] dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
                        >
                            <p
                                class="text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                Observação AGT
                            </p>
                            <p
                                class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-300"
                            >
                                {{ selected.snapshot.agt_message }}
                                <span
                                    v-if="
                                        selected.snapshot.agt_operational
                                            .observed_at
                                    "
                                >
                                    Observação:
                                    {{
                                        formatDateTime(
                                            selected.snapshot.agt_operational
                                                .observed_at,
                                        )
                                    }}.
                                </span>
                            </p>
                            <p
                                v-if="
                                    selected.snapshot.agt_error_codes.length > 0
                                "
                                class="mt-2 font-mono text-xs text-zinc-400"
                            >
                                {{
                                    selected.snapshot.agt_error_codes.join(
                                        ' · ',
                                    )
                                }}
                            </p>
                        </div>

                        <DocumentLifecycle
                            class="mt-7"
                            :steps="selected.snapshot.steps"
                        />

                        <div class="mt-6">
                            <div class="flex items-center justify-between">
                                <h3
                                    class="text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Histórico
                                </h3>
                                <Link
                                    v-if="selected.submission"
                                    :href="agtSubmissions.url()"
                                    class="inline-flex items-center gap-1 rounded text-[0.8125rem] font-semibold text-accent-700 focus-ring dark:text-accent-300"
                                >
                                    Monitor AGT
                                    <ArrowUpRight
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </div>
                            <p
                                v-if="selected.history.length === 0"
                                class="mt-3 text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    selected.status === 'draft'
                                        ? 'Um rascunho ainda não tem histórico fiscal: nada foi emitido nem enviado.'
                                        : 'Sem acontecimentos registados.'
                                }}
                            </p>
                            <ol v-else class="mt-3">
                                <li
                                    v-for="(event, index) in selected.history"
                                    :key="`${event.type}-${event.occurred_at}-${index}`"
                                    class="relative grid grid-cols-[1.75rem_minmax(0,1fr)_auto] gap-3 pb-4 last:pb-0"
                                >
                                    <span
                                        v-if="
                                            index < selected.history.length - 1
                                        "
                                        class="absolute top-7 bottom-0 left-[0.84rem] w-px bg-zinc-900/10 dark:bg-white/10"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="relative grid size-7 place-items-center rounded-full"
                                        :class="{
                                            'bg-lime-300 text-lime-950':
                                                event.tone === 'done',
                                            'bg-rose-500 text-white':
                                                event.tone === 'error',
                                            'bg-accent-50 text-accent-700 ring-1 ring-accent-200 ring-inset dark:bg-accent-400/10 dark:text-accent-300 dark:ring-accent-400/20':
                                                event.tone === 'pending',
                                        }"
                                        aria-hidden="true"
                                    >
                                        <Check
                                            v-if="event.tone === 'done'"
                                            class="size-3.5"
                                            :stroke-width="2.5"
                                        />
                                        <X
                                            v-else-if="event.tone === 'error'"
                                            class="size-3.5"
                                            :stroke-width="2.5"
                                        />
                                        <Clock3
                                            v-else
                                            class="size-3.5"
                                            :stroke-width="2.25"
                                        />
                                    </span>
                                    <span class="min-w-0 pt-1">
                                        <span
                                            class="block text-sm font-semibold text-zinc-950 dark:text-white"
                                            >{{ event.label }}</span
                                        >
                                        <span
                                            v-if="eventMeta(event)"
                                            class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400"
                                            >{{ eventMeta(event) }}</span
                                        >
                                    </span>
                                    <span
                                        class="pt-1.5 numeric text-xs text-zinc-500 dark:text-zinc-400"
                                        >{{
                                            formatDateTime(event.occurred_at)
                                        }}</span
                                    >
                                </li>
                            </ol>
                        </div>
                    </section>

                    <!-- Nothing open: on wide screens the panel says what it will hold. -->
                    <section
                        v-else-if="documents.data.length > 0"
                        class="hidden min-h-[24rem] place-items-center rounded-3xl border border-dashed border-zinc-900/15 p-10 text-center xl:grid dark:border-white/15"
                    >
                        <div class="max-w-xs">
                            <FileText
                                class="mx-auto size-6 text-zinc-400"
                                aria-hidden="true"
                            />
                            <p
                                class="mt-4 text-base text-zinc-950 dark:text-white"
                            >
                                Escolha um documento
                            </p>
                            <p
                                class="mt-1.5 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                Vê aqui os totais, o estado na AGT e tudo o que
                                lhe aconteceu, por ordem.
                            </p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
