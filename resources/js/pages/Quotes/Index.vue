<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
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
    return `${moneyFormatter.format(minor / 100)} ${props.currencyCode}`;
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

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl space-y-6">
                <FlashBanner />

                <header
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p class="eyebrow text-brand-700 dark:text-brand-300">
                            Antes da factura
                        </p>
                        <h1
                            class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                        >
                            Orçamentos
                        </h1>
                        <p
                            class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            Uma proposta não é um documento fiscal: fica
                            editável, não leva série e só chega à AGT quando a
                            transformar em factura.
                        </p>
                    </div>

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

                        <Link
                            :href="createQuote.url()"
                            class="inline-flex w-fit items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Novo orçamento
                        </Link>
                    </div>
                </header>

                <div
                    v-if="quotes.data.length === 0"
                    class="flex flex-col items-center gap-2 rounded-2xl surface px-4 py-16 text-center"
                >
                    <FileText
                        class="size-8 text-zinc-300 dark:text-zinc-600"
                        aria-hidden="true"
                    />
                    <p class="text-sm/6 text-zinc-500 dark:text-zinc-400">
                        Ainda não há orçamentos neste filtro.
                    </p>
                </div>

                <div v-else class="overflow-hidden rounded-2xl surface">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-100 dark:border-white/10"
                            >
                                <tr>
                                    <th class="px-5 py-3 eyebrow text-zinc-500">
                                        Referência
                                    </th>
                                    <th class="px-5 py-3 eyebrow text-zinc-500">
                                        Cliente
                                    </th>
                                    <th class="px-5 py-3 eyebrow text-zinc-500">
                                        Validade
                                    </th>
                                    <th class="px-5 py-3 eyebrow text-zinc-500">
                                        Estado
                                    </th>
                                    <th
                                        class="px-5 py-3 text-right eyebrow text-zinc-500"
                                    >
                                        Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <tr
                                    v-for="quote in quotes.data"
                                    :key="quote.public_id"
                                >
                                    <td class="px-5 py-3">
                                        <Link
                                            :href="
                                                editQuote.url(quote.public_id)
                                            "
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
                                    <td
                                        class="px-5 py-3 text-zinc-700 dark:text-zinc-300"
                                    >
                                        {{ quote.customer_name }}
                                    </td>
                                    <td
                                        class="px-5 py-3 whitespace-nowrap"
                                        :class="
                                            quote.has_lapsed
                                                ? 'text-amber-700 dark:text-amber-400'
                                                : 'text-zinc-600 dark:text-zinc-400'
                                        "
                                    >
                                        {{ formatDate(quote.valid_until) }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <StatusBadge
                                            :tone="
                                                statusTone[quote.status] ??
                                                'neutral'
                                            "
                                            :label="quote.status_label"
                                        />
                                    </td>
                                    <td
                                        class="px-5 py-3 text-right numeric font-medium whitespace-nowrap text-zinc-950 dark:text-white"
                                    >
                                        {{ money(quote.gross_total_minor) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <nav
                        v-if="quotes.links.length > 3"
                        class="flex flex-wrap gap-1 border-t border-zinc-100 p-4 dark:border-white/10"
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
