<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Filter, Plus, Search, Truck } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DataTable from '@/components/DataTable.vue';
import DateInput from '@/components/DateInput.vue';
import EmptyState from '@/components/EmptyState.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageStat from '@/components/PageStat.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    create as createTransportDocument,
    edit as editTransportDocument,
    index as transportDocumentsIndex,
    print as printTransportDocument,
} from '@/routes/transport-documents';
import type { SelectOption } from '@/types/select';

interface TransportDocumentRow {
    public_id: string;
    document_no: string | null;
    document_type: string;
    document_type_label: string;
    status: 'draft' | 'issued' | 'cancelled';
    status_label: string;
    movement_date: string;
    movement_start_at: string;
    recipient_name: string;
    recipient_tax_identification_number: string;
    destination: string;
    vehicle_registration: string | null;
    establishment: { public_id: string; name: string; code: string };
    gross_total_minor: number;
    currency_code: string;
    can_edit: boolean;
    can_print: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    documents: {
        data: TransportDocumentRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    summary: {
        total: number;
        draft: number;
        issued: number;
        cancelled: number;
    };
    filters: {
        q: string;
        status: string;
        type: string;
        establishment: string;
        from: string;
        to: string;
    };
    options: {
        types: SelectOption<string>[];
        establishments: SelectOption<string>[];
    };
    permissions: { create: boolean };
}>();

const search = ref(props.filters.q);
const status = ref(props.filters.status);
const type = ref(props.filters.type);
const establishment = ref(props.filters.establishment);
const from = ref<string | null>(props.filters.from || null);
const to = ref<string | null>(props.filters.to || null);

const statusOptions: SelectOption<string>[] = [
    { value: 'all', label: 'Todos os estados' },
    { value: 'draft', label: 'Rascunho' },
    { value: 'issued', label: 'Emitido' },
    { value: 'cancelled', label: 'Anulado' },
];
const typeOptions = computed<SelectOption<string>[]>(() => [
    { value: '', label: 'Todos os tipos' },
    ...props.options.types,
]);
const establishmentOptions = computed<SelectOption<string>[]>(() => [
    { value: '', label: 'Todos os estabelecimentos' },
    ...props.options.establishments,
]);
const activeFilterCount = computed(
    () =>
        [
            search.value.trim(),
            status.value !== 'all' ? status.value : '',
            type.value,
            establishment.value,
            from.value,
            to.value,
        ].filter(Boolean).length,
);

const statusCards = computed(() => [
    {
        label: 'Total',
        detail: 'movimentos registados',
        count: props.summary.total,
    },
    {
        label: 'Rascunhos',
        detail: 'a preparar',
        count: props.summary.draft,
    },
    {
        label: 'Emitidos',
        detail: 'prontos para circular',
        count: props.summary.issued,
    },
    {
        label: 'Anulados',
        detail: 'mantidos no SAF-T',
        count: props.summary.cancelled,
    },
]);

function applyFilters(): void {
    router.get(
        transportDocumentsIndex.url(),
        {
            q: search.value.trim() || undefined,
            status: status.value === 'all' ? undefined : status.value,
            type: type.value || undefined,
            establishment: establishment.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

function clearFilters(): void {
    search.value = '';
    status.value = 'all';
    type.value = '';
    establishment.value = '';
    from.value = null;
    to.value = null;
    applyFilters();
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium' });
const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function formatDate(value: string): string {
    return dateFormatter.format(new Date(`${value}T12:00:00`));
}

function money(document: TransportDocumentRow): string {
    return `${moneyFormatter.format(document.gross_total_minor / 100)} ${document.currency_code}`;
}

function decodeEntities(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&nbsp;/g, ' ')
        .trim();
}

const statusTone: Record<string, 'success' | 'warning' | 'neutral' | 'danger'> =
    {
        draft: 'warning',
        issued: 'success',
        cancelled: 'danger',
    };
</script>

<template>
    <AppLayout>
        <Head title="Guias e transporte" />

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Vender"
                    title="Guias e transporte"
                    description="Prepare guias de remessa, transporte, activos próprios e devoluções. Depois de emitidas, ficam imutáveis e seguem no SAF-T (AO)."
                >
                    <template #actions>
                        <AppButton
                            variant="gold"
                            v-if="permissions.create"
                            :href="createTransportDocument.url()"
                            class="w-fit"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Nova guia
                        </AppButton>
                    </template>
                    <template #stats>
                        <PageStat
                            v-for="card in statusCards"
                            :key="card.label"
                            :label="card.label"
                            :value="card.count"
                            :detail="card.detail"
                        />
                    </template>
                </PageHeader>

                <section
                    class="rounded-3xl bg-zinc-900/[0.04] p-4 sm:p-5 dark:bg-white/[0.04]"
                    aria-label="Filtros"
                >
                    <form
                        class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(15rem,1.5fr)_repeat(3,minmax(10rem,1fr))]"
                        @submit.prevent="applyFilters"
                    >
                        <label class="field-adorned">
                            <span class="sr-only">Pesquisar guias</span>
                            <Search
                                class="field-adornment-start size-4"
                                aria-hidden="true"
                            />
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Número, destinatário, NIF ou matrícula"
                                class="form-input"
                            />
                        </label>
                        <SelectInput
                            v-model="status"
                            :options="statusOptions"
                            aria-label="Estado"
                        />
                        <SelectInput
                            v-model="type"
                            :options="typeOptions"
                            aria-label="Tipo de guia"
                        />
                        <SelectInput
                            v-model="establishment"
                            :options="establishmentOptions"
                            aria-label="Estabelecimento"
                        />

                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-center md:col-span-2 xl:col-span-4"
                        >
                            <div class="grid flex-1 gap-3 sm:grid-cols-2">
                                <DateInput
                                    v-model="from"
                                    placeholder="Desde"
                                    aria-label="Data inicial"
                                />
                                <DateInput
                                    v-model="to"
                                    placeholder="Até"
                                    aria-label="Data final"
                                />
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="submit"
                                    class="inline-flex items-center gap-2 rounded-xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white focus-ring hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200"
                                >
                                    <Filter class="size-4" aria-hidden="true" />
                                    Aplicar
                                </button>
                                <button
                                    v-if="activeFilterCount > 0"
                                    type="button"
                                    class="rounded-xl px-3 py-2.5 text-sm font-semibold text-zinc-600 focus-ring hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5"
                                    @click="clearFilters"
                                >
                                    Limpar ({{ activeFilterCount }})
                                </button>
                            </div>
                        </div>
                    </form>
                </section>

                <EmptyState
                    v-if="documents.data.length === 0"
                    :icon="Truck"
                    :title="
                        summary.total === 0
                            ? 'Ainda não há guias'
                            : 'Nenhuma guia neste filtro'
                    "
                    :description="
                        summary.total === 0
                            ? 'A primeira guia começa como rascunho e só circula depois de a emitir.'
                            : 'Ajuste os filtros para ver outros movimentos.'
                    "
                />

                <section
                    v-else
                    class="overflow-hidden rounded-3xl bg-zinc-900/[0.04] dark:bg-white/[0.04]"
                >
                    <div class="overflow-x-auto">
                        <DataTable
                            ><template #head>
                                <th>Documento</th>
                                <th>Contraparte</th>
                                <th>Percurso</th>
                                <th>Data</th>
                                <th>Estado</th>
                                <th class="text-right">Valor</th>
                            </template>
                            <tr
                                v-for="document in documents.data"
                                :key="document.public_id"
                                class="align-top"
                            >
                                <td>
                                    <Link
                                        data-nav-row
                                        :href="
                                            editTransportDocument.url(
                                                document.public_id,
                                            )
                                        "
                                        class="rounded font-semibold text-zinc-950 underline-offset-4 focus-ring hover:underline dark:text-white"
                                    >
                                        {{
                                            document.document_no ??
                                            `Rascunho · ${document.document_type}`
                                        }}
                                    </Link>
                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ document.document_type_label }} ·
                                        {{ document.establishment.code }}
                                    </p>
                                </td>
                                <td>
                                    <p
                                        class="font-medium text-zinc-800 dark:text-zinc-200"
                                    >
                                        {{ document.recipient_name }}
                                    </p>
                                    <p
                                        class="mt-1 numeric text-xs text-zinc-500"
                                    >
                                        NIF
                                        {{
                                            document.recipient_tax_identification_number
                                        }}
                                    </p>
                                </td>
                                <td class="text-zinc-600 dark:text-zinc-400">
                                    <p>
                                        {{
                                            document.destination ||
                                            'Destino registado'
                                        }}
                                    </p>
                                    <p
                                        v-if="document.vehicle_registration"
                                        class="mt-1 numeric text-xs"
                                    >
                                        Matrícula
                                        {{ document.vehicle_registration }}
                                    </p>
                                </td>
                                <td
                                    class="whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                                >
                                    {{ formatDate(document.movement_date) }}
                                </td>
                                <td>
                                    <StatusBadge
                                        :label="document.status_label"
                                        :tone="
                                            statusTone[document.status] ??
                                            'neutral'
                                        "
                                    />
                                    <a
                                        v-if="document.can_print"
                                        :href="
                                            printTransportDocument.url(
                                                document.public_id,
                                            )
                                        "
                                        class="mt-2 block w-fit text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300"
                                        >Abrir impressão</a
                                    >
                                </td>
                                <td
                                    class="text-right numeric font-medium whitespace-nowrap text-zinc-950 dark:text-white"
                                >
                                    {{ money(document) }}
                                </td>
                            </tr>
                        </DataTable>
                    </div>

                    <nav
                        v-if="documents.links.length > 3"
                        class="flex flex-wrap gap-1 border-t border-zinc-900/[0.07] p-4 dark:border-white/10"
                        aria-label="Paginação"
                    >
                        <Link
                            v-for="link in documents.links"
                            :key="link.label"
                            :href="link.url ?? '#'"
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
                </section>
            </div>
        </div>
    </AppLayout>
</template>
