<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DataTable from '@/components/DataTable.vue';
import EmptyState from '@/components/EmptyState.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { currencyLabel } from '@/lib/pos-ui';
import { create as createQuote, edit as editQuote } from '@/routes/quotes';
import type { SelectOption } from '@/types/select';

interface QuoteRow {
    public_id: string;
    reference: string;
    status: string;
    status_label: string;
    is_editable: boolean;
    can_convert: boolean;
    customer_name: string;
    customer_public_id: string | null;
    issue_date: string;
    valid_until: string;
    has_lapsed: boolean;
    gross_total_minor: number;
    converted_document: {
        public_id: string;
        document_no: string | null;
    } | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    quotes: { data: QuoteRow[]; links: PaginationLink[]; total: number };
    filters: { status: string };
    statuses: { value: string; label: string }[];
    currencyCode: string;
}>();

const statusFilter = ref(props.filters.status);

const filterOptions = computed<SelectOption[]>(() => [
    { value: 'open', label: 'Em aberto' },
    { value: 'all', label: 'Todos' },
    ...props.statuses,
]);

function changeFilter(value: string): void {
    statusFilter.value = value;
    router.get(
        '/orcamentos',
        { status: value },
        { preserveState: true, replace: true, only: ['quotes', 'filters'] },
    );
}

const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function money(minor: number): string {
    return `${moneyFormatter.format(minor / 100)} ${currencyLabel(props.currencyCode)}`;
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium' });

function formatDate(value: string): string {
    return dateFormatter.format(new Date(value));
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

/** Laravel emits &laquo;/&raquo; entities for the previous and next links. */
function decodeEntities(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&nbsp;/g, ' ')
        .trim();
}
</script>

<template>
    <AppLayout>
        <Head title="Orçamentos" />

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Vender"
                    title="Orçamentos"
                    description="Uma proposta não é uma factura: o rascunho fica editável e congela quando o enviar. O SAF-T regista o orçamento como documento de trabalho."
                >
                    <template #actions>
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="w-full sm:w-44">
                                <SelectInput
                                    :model-value="statusFilter"
                                    :options="filterOptions"
                                    @update:model-value="
                                        (value) => changeFilter(String(value))
                                    "
                                />
                            </div>

                            <AppButton
                                variant="gold"
                                :href="createQuote.url()"
                                class="w-fit"
                            >
                                <Plus class="size-4" aria-hidden="true" />
                                Novo orçamento
                            </AppButton>
                        </div>
                    </template>
                </PageHeader>

                <EmptyState
                    v-if="quotes.data.length === 0"
                    :icon="FileText"
                    title="Nenhum orçamento neste filtro"
                    description="Mude o filtro ou crie um novo orçamento."
                />

                <div
                    v-else
                    class="overflow-hidden rounded-xl bg-[var(--surface-raised)] shadow-[var(--ui-card-shadow)]"
                >
                    <div class="overflow-x-auto">
                        <DataTable
                            ><template #head>
                                <th>Referência</th>
                                <th>Cliente</th>
                                <th>Validade</th>
                                <th>Estado</th>
                                <th class="text-right">Total</th>
                            </template>
                            <tr
                                v-for="quote in quotes.data"
                                :key="quote.public_id"
                            >
                                <td>
                                    <Link
                                        data-nav-row
                                        :href="editQuote.url(quote.public_id)"
                                        class="rounded numeric font-medium text-zinc-950 underline-offset-4 focus-ring hover:underline dark:text-white"
                                    >
                                        {{ quote.reference }}
                                    </Link>
                                    <p
                                        v-if="quote.converted_document"
                                        class="text-xs text-emerald-700 dark:text-emerald-400"
                                    >
                                        →
                                        {{
                                            quote.converted_document
                                                .document_no ?? 'rascunho'
                                        }}
                                    </p>
                                </td>
                                <td class="text-zinc-700 dark:text-zinc-300">
                                    {{ quote.customer_name }}
                                </td>
                                <td
                                    class="whitespace-nowrap"
                                    :class="
                                        quote.has_lapsed
                                            ? 'text-amber-700 dark:text-amber-400'
                                            : 'text-zinc-600 dark:text-zinc-400'
                                    "
                                >
                                    {{ formatDate(quote.valid_until) }}
                                </td>
                                <td>
                                    <StatusBadge
                                        :tone="
                                            statusTone[quote.status] ??
                                            'neutral'
                                        "
                                        :label="quote.status_label"
                                    />
                                </td>
                                <td
                                    class="text-right numeric font-medium whitespace-nowrap text-zinc-950 dark:text-white"
                                >
                                    {{ money(quote.gross_total_minor) }}
                                </td>
                            </tr>
                        </DataTable>
                    </div>

                    <nav
                        v-if="quotes.links.length > 3"
                        class="flex flex-wrap gap-1 border-t border-zinc-900/[0.07] p-4 dark:border-white/10"
                        aria-label="Paginação"
                    >
                        <Link
                            v-for="link in quotes.links"
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
                </div>
            </div>
        </div>
    </AppLayout>
</template>
