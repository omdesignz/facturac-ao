<script setup lang="ts">
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    CircleAlert,
    CircleDashed,
    Clock3,
    Download,
    FilePenLine,
    Files,
    FileText,
    FilterX,
    LoaderCircle,
    PencilLine,
    Plus,
    Printer,
    Search,
    Send,
    SlidersHorizontal,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DateInput from '@/components/DateInput.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { index as agtSubmissions } from '@/routes/agt/submissions';
import { show as customerShow } from '@/routes/customers';
import {
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

type StatusTone =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'
    | 'draft'
    | 'contingency';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

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

interface FilterOptions {
    statuses: SelectOption<string>[];
    families: SelectOption<string>[];
    types: SelectOption<string>[];
    establishments: SelectOption<string>[];
}

const props = defineProps<{
    documents: {
        data: RegisterDocument[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
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
}>();

const search = ref(props.filters.q);
const status = ref(props.filters.status);
const family = ref(props.filters.family);
const type = ref(props.filters.type);
const establishment = ref(props.filters.establishment);
const from = ref<string | null>(props.filters.from || null);
const to = ref<string | null>(props.filters.to || null);
const sendingDocument = ref<string | null>(null);

const typeOptions = computed<SelectOption<string>[]>(() => [
    { value: '', label: 'Todos os tipos' },
    ...props.options.types,
]);
const establishmentOptions = computed<SelectOption<string>[]>(() => [
    { value: '', label: 'Todos os locais' },
    ...props.options.establishments,
]);
const activeFilterCount = computed(
    () =>
        [
            search.value,
            status.value !== 'all' ? status.value : '',
            family.value !== 'all' ? family.value : '',
            type.value,
            establishment.value,
            from.value,
            to.value,
        ].filter(Boolean).length,
);
const hasDocuments = computed(() => props.summary.total > 0);

const statusCards = computed(() => [
    {
        value: 'all',
        label: 'Todos',
        detail: 'registos fiscais',
        count: props.summary.total,
        icon: Files,
    },
    {
        value: 'draft',
        label: 'Rascunhos',
        detail: 'ainda editáveis',
        count: props.summary.draft,
        icon: FilePenLine,
    },
    {
        value: 'active',
        label: 'Em curso',
        detail: 'entre emissão e AGT',
        count: props.summary.active,
        icon: Clock3,
    },
    {
        value: 'valid',
        label: 'Validados',
        detail: 'aceites pela AGT',
        count: props.summary.valid,
        icon: CheckCircle2,
    },
    {
        value: 'attention',
        label: 'Atenção',
        detail: 'exigem decisão',
        count: props.summary.attention,
        icon: CircleAlert,
    },
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
        value: 'GF',
        label: 'Factura genérica',
        detail: 'Operações agregadas com data por linha',
    },
    {
        value: 'RC',
        label: 'Recibo emitido',
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

function applyFilters(): void {
    if (['FT', 'FR', 'GF'].includes(type.value)) {
        family.value = 'invoice';
    } else if (type.value === 'RC') {
        family.value = 'receipt';
    } else if (['NC', 'ND'].includes(type.value)) {
        family.value = 'adjustment';
    }

    router.get(documentsIndex.url(), query(), {
        preserveState: true,
        replace: true,
        only: ['documents', 'filters'],
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

function statusTone(value: string): StatusTone {
    if (value === 'draft') {
        return 'draft';
    }

    if (value === 'valid') {
        return 'success';
    }

    if (['invalid', 'rejected', 'cancelled', 'failed'].includes(value)) {
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

function statusCardClasses(value: string): string {
    if (status.value === value) {
        return 'border-brand-950 bg-brand-950 text-white shadow-lg shadow-brand-950/10 dark:border-accent-400 dark:bg-accent-400 dark:text-brand-950';
    }

    return 'border-zinc-200/80 bg-white text-zinc-950 hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-md dark:border-white/10 dark:bg-zinc-900 dark:text-white dark:hover:border-white/20';
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

function money(minor: number, currencyCode: string): string {
    return new Intl.NumberFormat('pt-AO', {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100);
}

function formatDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-PT', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(`${value.slice(0, 10)}T12:00:00`));
}

function formatDateTime(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-AO', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Africa/Luanda',
    }).format(new Date(value));
}

function decodeEntities(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&nbsp;/g, ' ')
        .trim();
}

async function sendDocument(document: RegisterDocument): Promise<void> {
    const confirmed = await confirmAction({
        title: `Enviar ${document.document_no ?? 'este documento'}?`,
        message: `Será enviado por email para ${document.delivery_email}.`,
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

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-[100rem] space-y-7">
                <FlashBanner />

                <header
                    class="relative overflow-visible rounded-[1.75rem] bg-brand-950 px-5 py-6 text-white shadow-xl shadow-brand-950/10 sm:px-7 sm:py-8 dark:ring-1 dark:ring-white/10"
                >
                    <div
                        class="pointer-events-none absolute inset-0 overflow-hidden rounded-[inherit]"
                        aria-hidden="true"
                    >
                        <div
                            class="absolute -top-20 right-[-5%] size-72 rounded-full border border-accent-400/20"
                        />
                        <div
                            class="absolute -top-6 right-[8%] size-40 rounded-full border border-accent-400/10"
                        />
                        <div
                            class="absolute right-0 bottom-0 h-px w-2/3 bg-gradient-to-l from-accent-400/80 to-transparent"
                        />
                    </div>

                    <div
                        class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between"
                    >
                        <div>
                            <div
                                class="flex items-center gap-2 text-sm font-semibold text-accent-300"
                            >
                                <Files class="size-4" aria-hidden="true" />
                                Livro fiscal operacional
                            </div>
                            <h1 class="mt-2 text-3xl display sm:text-4xl">
                                Documentos fiscais
                            </h1>
                            <p
                                class="mt-3 max-w-2xl text-sm/6 text-brand-100/75"
                            >
                                Encontre qualquer rascunho, factura, recibo ou
                                nota — da preparação ao resultado final da AGT.
                            </p>
                        </div>

                        <Menu
                            v-if="permissions.create"
                            as="div"
                            class="relative z-20"
                        >
                            <MenuButton
                                class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent-400 px-4 py-3 text-sm font-bold text-brand-950 shadow-lg shadow-black/15 focus-ring-inverted transition hover:bg-accent-300 sm:w-auto"
                            >
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
                                leave-active-class="transition duration-75 ease-in"
                                leave-from-class="scale-100 opacity-100"
                                leave-to-class="scale-95 opacity-0"
                            >
                                <MenuItems
                                    class="absolute right-0 z-30 mt-2 w-full min-w-72 origin-top-right rounded-2xl bg-white p-2 text-zinc-950 shadow-2xl ring-1 ring-black/10 focus:outline-none sm:w-80 dark:bg-zinc-900 dark:text-white dark:ring-white/10"
                                >
                                    <p
                                        class="px-3 pt-2 pb-2 eyebrow text-zinc-400"
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
                                                'flex items-start gap-3 rounded-xl px-3 py-2.5 focus:outline-none',
                                            ]"
                                        >
                                            <span
                                                class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-accent-100 text-xs font-bold text-accent-800 dark:bg-accent-400/15 dark:text-accent-300"
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

                <section aria-labelledby="workflow-summary">
                    <h2 id="workflow-summary" class="sr-only">
                        Resumo por estado
                    </h2>
                    <div
                        class="grid grid-cols-2 gap-3 md:grid-cols-5"
                        role="list"
                    >
                        <button
                            v-for="card in statusCards"
                            :key="card.value"
                            type="button"
                            :aria-pressed="status === card.value"
                            :class="[
                                statusCardClasses(card.value),
                                'group min-h-28 rounded-2xl border p-4 text-left transition duration-200',
                            ]"
                            @click="selectStatus(card.value)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <component
                                    :is="card.icon"
                                    class="size-5 opacity-65"
                                    :stroke-width="1.8"
                                    aria-hidden="true"
                                />
                                <span
                                    class="numeric text-2xl font-semibold tracking-tight"
                                    >{{ card.count }}</span
                                >
                            </div>
                            <p class="mt-3 text-sm font-semibold">
                                {{ card.label }}
                            </p>
                            <p
                                class="mt-0.5 text-xs opacity-60"
                                :class="
                                    status === card.value
                                        ? 'text-current'
                                        : 'text-zinc-500 dark:text-zinc-400'
                                "
                            >
                                {{ card.detail }}
                            </p>
                        </button>
                    </div>
                </section>

                <section class="overflow-visible rounded-2xl surface">
                    <div
                        class="flex flex-col gap-4 border-b border-zinc-100 p-4 sm:p-5 dark:border-white/10"
                    >
                        <div
                            class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between"
                        >
                            <div
                                class="flex gap-1 overflow-x-auto rounded-xl bg-zinc-100 p-1 dark:bg-white/5"
                            >
                                <button
                                    v-for="option in options.families"
                                    :key="option.value"
                                    type="button"
                                    :aria-pressed="family === option.value"
                                    :class="[
                                        family === option.value
                                            ? 'bg-white text-zinc-950 shadow-sm dark:bg-zinc-800 dark:text-white'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                                        'shrink-0 rounded-lg px-3 py-2 text-xs font-semibold focus-ring transition sm:text-sm',
                                    ]"
                                    @click="selectFamily(String(option.value))"
                                >
                                    {{ option.label }}
                                </button>
                            </div>

                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                <span class="numeric font-semibold">{{
                                    documents.total
                                }}</span>
                                {{
                                    documents.total === 1
                                        ? 'resultado'
                                        : 'resultados'
                                }}
                                <span v-if="activeFilterCount > 0">
                                    · {{ activeFilterCount }} filtros activos
                                </span>
                            </p>
                        </div>

                        <form
                            class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(16rem,1.6fr)_minmax(11rem,1fr)_minmax(11rem,1fr)_minmax(11rem,1fr)_auto]"
                            @submit.prevent="applyFilters"
                        >
                            <label class="relative block">
                                <span class="sr-only"
                                    >Pesquisar documentos</span
                                >
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-zinc-400"
                                    aria-hidden="true"
                                />
                                <input
                                    v-model="search"
                                    type="search"
                                    placeholder="Número, cliente ou NIF…"
                                    class="w-full rounded-xl bg-white py-2.5 pr-3 pl-10 text-sm text-zinc-950 outline-1 -outline-offset-1 outline-zinc-300 placeholder:text-zinc-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                />
                            </label>

                            <SelectInput
                                v-model="status"
                                :options="options.statuses"
                                aria-label="Filtrar por estado"
                            />
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

                            <div class="flex gap-2">
                                <button
                                    type="submit"
                                    class="inline-flex min-h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white focus-ring transition hover:bg-zinc-800 xl:flex-none dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200"
                                >
                                    <SlidersHorizontal
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Aplicar
                                </button>
                                <button
                                    v-if="activeFilterCount > 0"
                                    type="button"
                                    class="icon-button text-zinc-500 ring-1 ring-zinc-200 focus-ring transition hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-white"
                                    title="Limpar filtros"
                                    @click="clearFilters"
                                >
                                    <FilterX
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    <span class="sr-only">Limpar filtros</span>
                                </button>
                            </div>
                        </form>

                        <details :open="Boolean(from || to)" class="group">
                            <summary
                                class="flex w-fit cursor-pointer list-none items-center gap-2 rounded-lg text-xs font-semibold text-zinc-500 focus-ring transition hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white"
                            >
                                <CalendarDays
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Intervalo de datas
                                <ChevronDown
                                    class="size-4 transition group-open:rotate-180"
                                    aria-hidden="true"
                                />
                            </summary>
                            <div
                                class="mt-3 grid max-w-xl gap-3 sm:grid-cols-2"
                            >
                                <div>
                                    <label
                                        class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400"
                                        >De</label
                                    >
                                    <DateInput
                                        v-model="from"
                                        aria-label="Data inicial"
                                        :max-date="to ?? undefined"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400"
                                        >Até</label
                                    >
                                    <DateInput
                                        v-model="to"
                                        aria-label="Data final"
                                        :min-date="from ?? undefined"
                                    />
                                </div>
                            </div>
                        </details>
                    </div>

                    <div
                        v-if="documents.data.length === 0"
                        class="flex min-h-80 flex-col items-center justify-center px-5 py-16 text-center"
                    >
                        <span
                            class="grid size-14 place-items-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500"
                        >
                            <FileText class="size-7" aria-hidden="true" />
                        </span>
                        <h2
                            class="mt-4 text-lg display text-zinc-950 dark:text-white"
                        >
                            {{
                                hasDocuments
                                    ? 'Nenhum documento corresponde aos filtros'
                                    : 'O livro fiscal ainda está vazio'
                            }}
                        </h2>
                        <p
                            class="mt-2 max-w-md text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                hasDocuments
                                    ? 'Altere a pesquisa, o estado ou o intervalo para voltar a encontrar o registo.'
                                    : 'O primeiro rascunho ficará aqui e poderá retomá-lo antes de emitir.'
                            }}
                        </p>
                        <button
                            v-if="hasDocuments"
                            type="button"
                            class="mt-5 inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-brand-700 ring-1 ring-zinc-200 focus-ring transition hover:bg-zinc-50 dark:text-brand-300 dark:ring-white/10 dark:hover:bg-white/5"
                            @click="clearFilters"
                        >
                            <FilterX class="size-4" aria-hidden="true" />
                            Limpar filtros
                        </button>
                        <Link
                            v-else-if="permissions.create"
                            :href="createInvoice.url()"
                            class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white focus-ring transition hover:bg-brand-600 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Criar primeiro rascunho
                        </Link>
                    </div>

                    <template v-else>
                        <div
                            class="divide-y divide-zinc-100 md:hidden dark:divide-white/10"
                        >
                            <article
                                v-for="document in documents.data"
                                :key="document.public_id"
                                class="space-y-4 p-4"
                            >
                                <div class="flex items-start gap-3">
                                    <span
                                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-accent-50 text-xs font-bold text-accent-800 ring-1 ring-accent-200/70 dark:bg-accent-400/10 dark:text-accent-300 dark:ring-accent-400/20"
                                    >
                                        {{ document.document_type }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <Link
                                            v-if="document.can_edit"
                                            :href="
                                                editInvoice.url(
                                                    document.public_id,
                                                )
                                            "
                                            class="block truncate font-semibold text-zinc-950 underline-offset-4 focus-ring hover:underline dark:text-white"
                                        >
                                            Rascunho ·
                                            {{ document.customer_name }}
                                        </Link>
                                        <a
                                            v-else-if="document.can_print"
                                            :href="
                                                printDocument.url(
                                                    document.public_id,
                                                )
                                            "
                                            target="_blank"
                                            rel="noopener"
                                            class="block truncate numeric font-semibold text-zinc-950 underline-offset-4 focus-ring hover:underline dark:text-white"
                                        >
                                            {{ document.document_no }}
                                        </a>
                                        <p
                                            v-else
                                            class="truncate font-semibold text-zinc-950 dark:text-white"
                                        >
                                            {{ document.document_type_label }}
                                        </p>
                                        <p
                                            class="mt-1 truncate text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ document.customer_name }} · NIF
                                            {{
                                                document.customer_tax_identification_number
                                            }}
                                        </p>
                                    </div>
                                    <StatusBadge
                                        :tone="
                                            statusTone(document.workflow_status)
                                        "
                                        :pulse="
                                            workflowIsActive(
                                                document.workflow_status,
                                            )
                                        "
                                        :label="document.workflow_label"
                                    />
                                </div>

                                <dl class="grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <dt
                                            class="text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            Data
                                        </dt>
                                        <dd
                                            class="mt-0.5 text-zinc-800 dark:text-zinc-200"
                                        >
                                            {{
                                                formatDate(
                                                    document.document_date,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                    <div class="text-right">
                                        <dt
                                            class="text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            Total
                                        </dt>
                                        <dd
                                            class="mt-0.5 numeric font-semibold text-zinc-950 dark:text-white"
                                        >
                                            {{
                                                money(
                                                    document.gross_total_minor,
                                                    document.currency_code,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                </dl>

                                <div
                                    class="flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-3 dark:border-white/10"
                                >
                                    <Link
                                        v-if="document.can_edit"
                                        :href="
                                            editInvoice.url(document.public_id)
                                        "
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-brand-700 ring-1 ring-zinc-200 focus-ring dark:text-brand-300 dark:ring-white/10"
                                    >
                                        <PencilLine
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        Continuar
                                    </Link>
                                    <a
                                        v-if="document.can_print"
                                        :href="
                                            printDocument.url(
                                                document.public_id,
                                            )
                                        "
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-zinc-600 ring-1 ring-zinc-200 focus-ring dark:text-zinc-300 dark:ring-white/10"
                                    >
                                        <Printer
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        Abrir
                                    </a>
                                    <a
                                        v-if="document.can_print"
                                        :href="
                                            documentPdf.url(document.public_id)
                                        "
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-zinc-600 ring-1 ring-zinc-200 focus-ring dark:text-zinc-300 dark:ring-white/10"
                                    >
                                        <Download
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        PDF
                                    </a>
                                    <button
                                        v-if="document.can_send"
                                        type="button"
                                        :disabled="
                                            sendingDocument ===
                                            document.public_id
                                        "
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-zinc-600 ring-1 ring-zinc-200 focus-ring disabled:opacity-50 dark:text-zinc-300 dark:ring-white/10"
                                        @click="sendDocument(document)"
                                    >
                                        <LoaderCircle
                                            v-if="
                                                sendingDocument ===
                                                document.public_id
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
                                            document.sent_at
                                                ? 'Reenviar'
                                                : 'Enviar'
                                        }}
                                    </button>
                                    <Link
                                        v-if="document.submission"
                                        :href="
                                            agtSubmissions.url({
                                                query: {
                                                    submission:
                                                        document.submission
                                                            .public_id,
                                                },
                                            })
                                        "
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-zinc-600 ring-1 ring-zinc-200 focus-ring dark:text-zinc-300 dark:ring-white/10"
                                    >
                                        <ArrowUpRight
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        AGT
                                    </Link>
                                </div>
                            </article>
                        </div>

                        <div class="hidden overflow-x-auto md:block">
                            <table class="min-w-full text-left text-sm">
                                <thead
                                    class="border-b border-zinc-100 bg-zinc-50/70 dark:border-white/10 dark:bg-white/[0.02]"
                                >
                                    <tr>
                                        <th
                                            class="px-5 py-3 eyebrow text-zinc-500"
                                        >
                                            Documento
                                        </th>
                                        <th
                                            class="px-5 py-3 eyebrow text-zinc-500"
                                        >
                                            Cliente
                                        </th>
                                        <th
                                            class="px-5 py-3 eyebrow text-zinc-500"
                                        >
                                            Data e local
                                        </th>
                                        <th
                                            class="px-5 py-3 eyebrow text-zinc-500"
                                        >
                                            Estado
                                        </th>
                                        <th
                                            class="px-5 py-3 text-right eyebrow text-zinc-500"
                                        >
                                            Total
                                        </th>
                                        <th class="px-4 py-3">
                                            <span class="sr-only">Acções</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-zinc-100 dark:divide-white/10"
                                >
                                    <tr
                                        v-for="document in documents.data"
                                        :key="document.public_id"
                                        class="group transition hover:bg-zinc-50/70 dark:hover:bg-white/[0.025]"
                                    >
                                        <td class="px-5 py-4">
                                            <div class="flex items-start gap-3">
                                                <span
                                                    class="grid size-9 shrink-0 place-items-center rounded-lg bg-accent-50 text-xs font-bold text-accent-800 ring-1 ring-accent-200/70 dark:bg-accent-400/10 dark:text-accent-300 dark:ring-accent-400/20"
                                                >
                                                    {{ document.document_type }}
                                                </span>
                                                <div class="min-w-0">
                                                    <Link
                                                        v-if="document.can_edit"
                                                        :href="
                                                            editInvoice.url(
                                                                document.public_id,
                                                            )
                                                        "
                                                        class="rounded font-semibold text-zinc-950 underline-offset-4 focus-ring hover:underline dark:text-white"
                                                    >
                                                        Rascunho sem número
                                                    </Link>
                                                    <a
                                                        v-else-if="
                                                            document.can_print
                                                        "
                                                        :href="
                                                            printDocument.url(
                                                                document.public_id,
                                                            )
                                                        "
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="rounded numeric font-semibold text-zinc-950 underline-offset-4 focus-ring hover:underline dark:text-white"
                                                    >
                                                        {{
                                                            document.document_no
                                                        }}
                                                    </a>
                                                    <p
                                                        v-else
                                                        class="font-semibold text-zinc-950 dark:text-white"
                                                    >
                                                        {{
                                                            document.document_type_label
                                                        }}
                                                    </p>
                                                    <p
                                                        class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                                    >
                                                        {{
                                                            document.document_type_label
                                                        }}
                                                        <span
                                                            v-if="
                                                                document
                                                                    .submission
                                                                    ?.request_id
                                                            "
                                                            class="numeric"
                                                        >
                                                            · AGT
                                                            {{
                                                                document
                                                                    .submission
                                                                    .request_id
                                                            }}
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="max-w-64 px-5 py-4">
                                            <Link
                                                v-if="
                                                    document.customer_public_id
                                                "
                                                :href="
                                                    customerShow.url(
                                                        document.customer_public_id,
                                                    )
                                                "
                                                class="block truncate rounded font-medium text-zinc-800 underline-offset-4 focus-ring hover:underline dark:text-zinc-200"
                                            >
                                                {{ document.customer_name }}
                                            </Link>
                                            <p
                                                v-else
                                                class="truncate font-medium text-zinc-800 dark:text-zinc-200"
                                            >
                                                {{ document.customer_name }}
                                            </p>
                                            <p
                                                class="mt-1 truncate numeric text-xs text-zinc-500 dark:text-zinc-400"
                                            >
                                                NIF
                                                {{
                                                    document.customer_tax_identification_number
                                                }}
                                            </p>
                                        </td>
                                        <td
                                            class="px-5 py-4 whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                                        >
                                            {{
                                                formatDate(
                                                    document.document_date,
                                                )
                                            }}
                                            <p
                                                class="mt-1 text-xs text-zinc-400 dark:text-zinc-500"
                                            >
                                                {{
                                                    document.establishment.code
                                                }}
                                                ·
                                                {{
                                                    document.establishment.name
                                                }}
                                            </p>
                                        </td>
                                        <td class="px-5 py-4">
                                            <StatusBadge
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
                                                :label="document.workflow_label"
                                            />
                                            <p
                                                v-if="document.workflow_message"
                                                class="mt-1.5 max-w-56 truncate text-xs text-zinc-500 dark:text-zinc-400"
                                                :title="
                                                    document.workflow_message
                                                "
                                            >
                                                {{ document.workflow_message }}
                                            </p>
                                        </td>
                                        <td
                                            class="px-5 py-4 text-right numeric font-semibold whitespace-nowrap text-zinc-950 dark:text-white"
                                        >
                                            {{
                                                money(
                                                    document.gross_total_minor,
                                                    document.currency_code,
                                                )
                                            }}
                                            <p
                                                v-if="document.sent_at"
                                                class="mt-1 text-xs font-normal text-zinc-400 dark:text-zinc-500"
                                                :title="`Último envio ${formatDateTime(document.sent_at)}`"
                                            >
                                                enviado
                                                {{ document.send_count }}×
                                            </p>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div
                                                class="flex items-center justify-end gap-1"
                                            >
                                                <Link
                                                    v-if="document.can_edit"
                                                    :href="
                                                        editInvoice.url(
                                                            document.public_id,
                                                        )
                                                    "
                                                    class="icon-button text-brand-700 focus-ring transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-white/5"
                                                    title="Continuar rascunho"
                                                >
                                                    <PencilLine
                                                        class="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    <span class="sr-only"
                                                        >Continuar
                                                        rascunho</span
                                                    >
                                                </Link>
                                                <a
                                                    v-if="document.can_print"
                                                    :href="
                                                        printDocument.url(
                                                            document.public_id,
                                                        )
                                                    "
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="icon-button text-zinc-500 focus-ring transition hover:bg-zinc-100 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white"
                                                    title="Abrir documento"
                                                >
                                                    <Printer
                                                        class="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    <span class="sr-only"
                                                        >Abrir documento</span
                                                    >
                                                </a>
                                                <a
                                                    v-if="document.can_print"
                                                    :href="
                                                        documentPdf.url(
                                                            document.public_id,
                                                        )
                                                    "
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="icon-button text-zinc-500 focus-ring transition hover:bg-zinc-100 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white"
                                                    title="Abrir PDF"
                                                >
                                                    <Download
                                                        class="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    <span class="sr-only"
                                                        >Abrir PDF</span
                                                    >
                                                </a>
                                                <button
                                                    v-if="document.can_send"
                                                    type="button"
                                                    :disabled="
                                                        sendingDocument ===
                                                        document.public_id
                                                    "
                                                    class="icon-button text-zinc-500 focus-ring transition hover:bg-zinc-100 hover:text-zinc-950 disabled:opacity-50 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white"
                                                    :title="
                                                        document.sent_at
                                                            ? 'Reenviar ao cliente'
                                                            : 'Enviar ao cliente'
                                                    "
                                                    @click="
                                                        sendDocument(document)
                                                    "
                                                >
                                                    <LoaderCircle
                                                        v-if="
                                                            sendingDocument ===
                                                            document.public_id
                                                        "
                                                        class="size-4 animate-spin"
                                                        aria-hidden="true"
                                                    />
                                                    <Send
                                                        v-else
                                                        class="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    <span class="sr-only"
                                                        >Enviar ao cliente</span
                                                    >
                                                </button>
                                                <Link
                                                    v-if="document.submission"
                                                    :href="
                                                        agtSubmissions.url({
                                                            query: {
                                                                submission:
                                                                    document
                                                                        .submission
                                                                        .public_id,
                                                            },
                                                        })
                                                    "
                                                    class="icon-button text-zinc-500 focus-ring transition hover:bg-zinc-100 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white"
                                                    title="Ver transmissão AGT"
                                                >
                                                    <ArrowUpRight
                                                        class="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    <span class="sr-only"
                                                        >Ver transmissão
                                                        AGT</span
                                                    >
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div
                            v-if="documents.links.length > 3"
                            class="flex flex-col gap-3 border-t border-zinc-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10"
                        >
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                <span class="numeric"
                                    >{{ documents.from }}–{{
                                        documents.to
                                    }}</span
                                >
                                de
                                <span class="numeric">{{
                                    documents.total
                                }}</span>
                            </p>

                            <nav
                                class="flex flex-wrap gap-1"
                                aria-label="Paginação"
                            >
                                <Link
                                    v-for="link in documents.links"
                                    :key="link.label"
                                    :href="link.url ?? '#'"
                                    preserve-scroll
                                    :class="[
                                        link.active
                                            ? 'bg-zinc-950 text-white dark:bg-white dark:text-zinc-950'
                                            : 'text-zinc-600 ring-1 ring-zinc-200 dark:text-zinc-400 dark:ring-white/10',
                                        link.url === null
                                            ? 'pointer-events-none opacity-40'
                                            : '',
                                        'rounded-lg px-3 py-1.5 text-sm focus-ring',
                                    ]"
                                >
                                    <span v-text="decodeEntities(link.label)" />
                                </Link>
                            </nav>
                        </div>
                    </template>
                </section>

                <aside
                    class="flex flex-col gap-3 rounded-2xl border border-zinc-200/80 bg-zinc-50 px-4 py-3 text-xs/5 text-zinc-500 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/[0.025] dark:text-zinc-400"
                >
                    <p class="flex items-center gap-2">
                        <CircleDashed
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        A recepção pela AGT não é validação; o estado só fica
                        concluído após a resposta final.
                    </p>
                    <Link
                        :href="agtSubmissions.url()"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg font-semibold text-brand-700 focus-ring hover:underline dark:text-brand-300"
                    >
                        Abrir Monitor AGT
                        <ArrowUpRight class="size-3.5" aria-hidden="true" />
                    </Link>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
