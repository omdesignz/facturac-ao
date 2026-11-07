<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Filter, Plus, Search, Truck } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DataTable from '@/components/DataTable.vue';
import DateInput from '@/components/DateInput.vue';
import EmptyState from '@/components/EmptyState.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import PageStat from '@/components/PageStat.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TitleBar from '@/components/TitleBar.vue';
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

        <div
            class="flex min-h-[calc(100dvh-4rem)] flex-col lg:min-h-[calc(100dvh-3rem)]"
        >
            <div class="px-4 empty:hidden sm:px-6"><FlashBanner /></div>

            <TitleBar section="Vender" title="Guias e transporte">
                <template #actions>
                    <AppButton
                        v-if="permissions.create"
                        variant="gold"
                        size="sm"
                        class="h-[1.875rem] ps-2.5"
                        :href="createTransportDocument.url()"
                    >
                        <Plus
                            class="size-3.5"
                            :stroke-width="2.2"
                            aria-hidden="true"
                        />
                        Nova guia
                    </AppButton>
                </template>
            </TitleBar>

            <dl
                v-if="summary.total > 0"
                class="grid grid-cols-2 gap-y-4 border-b border-[var(--ui-line)] px-4 py-4 sm:flex sm:flex-wrap sm:px-6"
            >
                <PageStat
                    v-for="card in statusCards"
                    :key="card.label"
                    :label="card.label"
                    :value="card.count"
                    :detail="card.detail"
                />
            </dl>

            <section
                v-if="summary.total > 0"
                class="border-b border-[var(--ui-line)] bg-[var(--ui-head)] px-4 py-3 sm:px-6"
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
                            <AppButton variant="ink" type="submit">
                                <Filter class="size-4" aria-hidden="true" />
                                Aplicar
                            </AppButton>
                            <AppButton
                                v-if="activeFilterCount > 0"
                                variant="quiet"
                                @click="clearFilters"
                            >
                                Limpar ({{ activeFilterCount }})
                            </AppButton>
                        </div>
                    </div>
                </form>
            </section>

            <EmptyState
                v-if="documents.data.length === 0"
                bare
                class="flex-1 justify-center"
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

            <section v-else>
                <div class="overflow-x-auto">
                    <DataTable edge
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
                            class="align-top hover:bg-[var(--ui-wash)]"
                        >
                            <td>
                                <Link
                                    data-nav-row
                                    :href="
                                        editTransportDocument.url(
                                            document.public_id,
                                        )
                                    "
                                    class="rounded text-[0.8125rem] font-semibold text-[var(--ui-ink)] focus-ring hover:underline hover:decoration-[var(--ui-line-strong)] hover:underline-offset-2"
                                >
                                    {{
                                        document.document_no ??
                                        `Rascunho · ${document.document_type}`
                                    }}
                                </Link>
                                <p class="mt-px text-xs text-[var(--ui-ink-3)]">
                                    {{ document.document_type_label }} ·
                                    {{ document.establishment.code }}
                                </p>
                            </td>
                            <td>
                                <p
                                    class="text-[0.8125rem] font-medium text-[var(--ui-ink)]"
                                >
                                    {{ document.recipient_name }}
                                </p>
                                <p
                                    class="mt-px numeric text-xs text-[var(--ui-ink-3)]"
                                >
                                    NIF
                                    {{
                                        document.recipient_tax_identification_number
                                    }}
                                </p>
                            </td>
                            <td class="text-[0.8125rem] text-[var(--ui-ink-2)]">
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
                                class="numeric text-[0.8125rem] whitespace-nowrap text-[var(--ui-ink-2)]"
                            >
                                {{ formatDate(document.movement_date) }}
                            </td>
                            <td>
                                <StatusBadge
                                    :label="document.status_label"
                                    :tone="
                                        statusTone[document.status] ?? 'neutral'
                                    "
                                />
                                <a
                                    v-if="document.can_print"
                                    :href="
                                        printTransportDocument.url(
                                            document.public_id,
                                        )
                                    "
                                    class="mt-1.5 block w-fit rounded text-xs font-medium text-[var(--ui-ink-2)] focus-ring hover:text-[var(--ui-ink)] hover:underline"
                                    >Abrir impressão</a
                                >
                            </td>
                            <td
                                class="text-right numeric text-[0.8125rem] font-semibold whitespace-nowrap text-[var(--ui-ink)]"
                            >
                                {{ money(document) }}
                            </td>
                        </tr>
                    </DataTable>
                </div>

                <nav
                    v-if="documents.links.length > 3"
                    class="flex flex-wrap gap-1 px-4 py-3 sm:px-6"
                    aria-label="Paginação"
                >
                    <Link
                        v-for="link in documents.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        :class="[
                            link.active
                                ? 'bg-[var(--ui-well)] font-semibold text-[var(--ui-ink)]'
                                : 'text-[var(--ui-ink-2)] hover:bg-[var(--ui-wash)]',
                            link.url === null
                                ? 'pointer-events-none opacity-40'
                                : '',
                            'rounded-md px-2.5 py-1 text-[0.78125rem] focus-ring',
                        ]"
                    >
                        <span v-text="decodeEntities(link.label)" />
                    </Link>
                </nav>
            </section>
        </div>
    </AppLayout>
</template>
