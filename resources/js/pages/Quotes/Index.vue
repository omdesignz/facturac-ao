<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DataTable from '@/components/DataTable.vue';
import EmptyState from '@/components/EmptyState.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TitleBar from '@/components/TitleBar.vue';
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

        <div class="flex min-h-[calc(100dvh-4rem)] flex-col">
            <div class="px-4 empty:hidden sm:px-6"><FlashBanner /></div>

            <TitleBar section="Vender" title="Orçamentos">
                <template #actions>
                    <AppButton
                        variant="gold"
                        size="sm"
                        class="h-[1.875rem] ps-2.5"
                        :href="createQuote.url()"
                    >
                        <Plus
                            class="size-3.5"
                            :stroke-width="2.2"
                            aria-hidden="true"
                        />
                        Novo orçamento
                    </AppButton>
                </template>
            </TitleBar>

            <div
                class="flex min-h-11 [scrollbar-width:none] items-center gap-0.5 overflow-x-auto border-b border-[var(--ui-line)] px-4 py-1.5 sm:px-6"
                role="group"
                aria-label="Filtrar por estado"
            >
                <button
                    v-for="option in filterOptions"
                    :key="String(option.value)"
                    type="button"
                    :aria-pressed="statusFilter === option.value"
                    class="inline-flex h-[1.625rem] shrink-0 items-center rounded-md px-2 text-[0.78125rem] focus-ring transition-colors pointer-coarse:h-9"
                    :class="
                        statusFilter === option.value
                            ? 'bg-[var(--ui-well)] font-semibold text-[var(--ui-ink)]'
                            : 'font-medium text-[var(--ui-ink-2)] hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)]'
                    "
                    @click="changeFilter(String(option.value))"
                >
                    {{ option.label }}
                </button>
            </div>

            <EmptyState
                v-if="quotes.data.length === 0"
                bare
                class="flex-1 justify-center"
                :icon="FileText"
                :title="
                    statusFilter === 'all'
                        ? 'Ainda não há orçamentos'
                        : 'Nenhum orçamento neste filtro'
                "
                description="Uma proposta não é uma factura: o rascunho fica editável e congela quando o enviar. O SAF-T regista-o como documento de trabalho."
            >
                <AppButton variant="gold" size="sm" :href="createQuote.url()">
                    <Plus class="size-3.5" aria-hidden="true" />
                    Novo orçamento
                </AppButton>
            </EmptyState>

            <template v-else>
                <div class="overflow-x-auto">
                    <DataTable edge
                        ><template #head>
                            <th>Referência</th>
                            <th class="max-sm:hidden">Cliente</th>
                            <th class="max-sm:hidden">Validade</th>
                            <th class="max-sm:hidden">Estado</th>
                            <th class="text-right">Total</th>
                        </template>
                        <tr
                            v-for="quote in quotes.data"
                            :key="quote.public_id"
                            class="hover:bg-[var(--ui-wash)]"
                        >
                            <td>
                                <Link
                                    data-nav-row
                                    :href="editQuote.url(quote.public_id)"
                                    class="rounded numeric text-[0.8125rem] font-semibold text-[var(--ui-ink)] focus-ring hover:underline hover:decoration-[var(--ui-line-strong)] hover:underline-offset-2"
                                >
                                    {{ quote.reference }}
                                </Link>
                                <p
                                    v-if="quote.converted_document"
                                    class="mt-px text-xs text-[var(--ui-positive)]"
                                >
                                    Passou a
                                    {{
                                        quote.converted_document.document_no ??
                                        'rascunho'
                                    }}
                                </p>
                                <!-- Phone: the customer, validity and state under the reference. -->
                                <p
                                    class="mt-0.5 truncate text-xs text-[var(--ui-ink-2)] sm:hidden"
                                >
                                    {{ quote.customer_name }}
                                </p>
                                <p
                                    class="mt-1 flex items-center gap-2 text-xs sm:hidden"
                                >
                                    <StatusBadge
                                        :tone="
                                            statusTone[quote.status] ??
                                            'neutral'
                                        "
                                        :label="quote.status_label"
                                    />
                                    <span
                                        class="numeric"
                                        :class="
                                            quote.has_lapsed
                                                ? 'text-[var(--ui-warning)]'
                                                : 'text-[var(--ui-ink-3)]'
                                        "
                                        >até
                                        {{
                                            formatDate(quote.valid_until)
                                        }}</span
                                    >
                                </p>
                            </td>
                            <td
                                class="text-[0.8125rem] text-[var(--ui-ink)] max-sm:hidden"
                            >
                                {{ quote.customer_name }}
                            </td>
                            <td
                                class="numeric text-[0.8125rem] whitespace-nowrap max-sm:hidden"
                                :class="
                                    quote.has_lapsed
                                        ? 'text-[var(--ui-warning)]'
                                        : 'text-[var(--ui-ink-2)]'
                                "
                            >
                                {{ formatDate(quote.valid_until) }}
                            </td>
                            <td class="max-sm:hidden">
                                <StatusBadge
                                    :tone="
                                        statusTone[quote.status] ?? 'neutral'
                                    "
                                    :label="quote.status_label"
                                />
                            </td>
                            <td
                                class="text-right numeric text-[0.8125rem] font-semibold whitespace-nowrap text-[var(--ui-ink)]"
                            >
                                {{ money(quote.gross_total_minor) }}
                            </td>
                        </tr>
                    </DataTable>
                </div>

                <nav
                    v-if="quotes.links.length > 3"
                    class="flex flex-wrap gap-1 px-4 py-3 sm:px-6"
                    aria-label="Paginação"
                >
                    <Link
                        v-for="link in quotes.links"
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
            </template>
        </div>
    </AppLayout>
</template>
