<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageStat from '@/components/PageStat.vue';
import PosCloseShiftDialog from '@/components/pos/PosCloseShiftDialog.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMinor, formatSignedMinor } from '@/lib/pos';
import {
    buttonInk,
    buttonOutline,
    currencyLabel,
    formatClock,
    formatDayAndClock,
    wellSection,
} from '@/lib/pos-ui';
import { index as documentsIndex } from '@/routes/documents';
import { index as sessionsIndex } from '@/routes/pos/sessions';
import type { PosCashMovement, PosSaleRow, PosShiftSummary } from '@/types/pos';

const props = defineProps<{
    session: {
        public_id: string;
        status: 'open' | 'closed';
        status_label: string;
        register_name: string;
        establishment_name: string;
        opened_by_name: string;
        closed_by_name: string | null;
        opened_at: string;
        closed_at: string | null;
        currency_code: string;
        opening_float_minor: number;
        counted_cash_minor: number | null;
        expected_cash_minor: number | null;
        cash_difference_minor: number | null;
        closing_notes: string | null;
        can_close: boolean;
    };
    summary: PosShiftSummary;
    cash_movements: PosCashMovement[];
    sales: PosSaleRow[];
}>();

const closeOpen = ref(false);
const suffix = computed(() => currencyLabel(props.session.currency_code));
const isClosed = computed(() => props.session.status === 'closed');

const expectedMinor = computed(
    () =>
        props.session.expected_cash_minor ?? props.summary.expected_cash_minor,
);

const differenceText = computed(() => {
    const difference = props.session.cash_difference_minor;

    if (difference === null) {
        return '—';
    }

    return difference === 0 ? 'Certo' : formatSignedMinor(difference);
});

const hasDifference = computed(
    () =>
        props.session.cash_difference_minor !== null &&
        props.session.cash_difference_minor !== 0,
);

const drawerRows = computed(() => [
    {
        label: 'Fundo de caixa',
        minor: props.session.opening_float_minor,
        sign: '',
    },
    {
        label: 'Vendas em numerário',
        minor: props.summary.cash_sales_minor,
        sign: '+',
    },
    { label: 'Reforços', minor: props.summary.cash_in_minor, sign: '+' },
    { label: 'Retiradas', minor: props.summary.cash_out_minor, sign: '−' },
]);

function documentHref(sale: PosSaleRow): string {
    return documentsIndex.url({
        query: { documento: sale.document_public_id },
    });
}

function printReport(): void {
    window.print();
}

const linkClass =
    'rounded font-medium underline underline-offset-4 focus-ring hover:text-zinc-950 dark:hover:text-white';
</script>

<template>
    <AppLayout>
        <Head :title="`Turno · ${session.register_name}`" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div id="pos-report" class="mx-auto max-w-5xl space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Vendas · Turnos"
                    :title="`Turno · ${session.register_name}`"
                    :description="`${session.establishment_name} · aberto por ${session.opened_by_name} em ${formatDayAndClock(session.opened_at)}${session.closed_at ? `, fechado${session.closed_by_name ? ` por ${session.closed_by_name}` : ''} em ${formatDayAndClock(session.closed_at)}` : ''}`"
                >
                    <template #meta>
                        <StatusBadge
                            :label="session.status_label"
                            :tone="isClosed ? 'neutral' : 'info'"
                        />
                    </template>
                    <template #actions>
                        <Link
                            :href="sessionsIndex.url()"
                            :class="[buttonOutline, 'print:hidden']"
                        >
                            <ArrowLeft class="size-4" aria-hidden="true" />
                            Turnos
                        </Link>
                        <button
                            type="button"
                            :class="[buttonOutline, 'print:hidden']"
                            @click="printReport"
                        >
                            <Printer class="size-4" aria-hidden="true" />
                            Imprimir
                        </button>
                        <button
                            v-if="session.can_close"
                            type="button"
                            :class="[buttonInk, 'print:hidden']"
                            @click="closeOpen = true"
                        >
                            Fechar caixa
                        </button>
                    </template>
                    <template #stats>
                        <PageStat label="Vendas" :value="summary.sales_count" />
                        <PageStat
                            label="Total"
                            :value="`${formatMinor(summary.gross_total_minor)} ${suffix}`"
                        />
                        <PageStat
                            label="Numerário esperado"
                            :value="`${formatMinor(expectedMinor)} ${suffix}`"
                        />
                        <PageStat
                            label="Diferença"
                            :value="differenceText"
                            :detail="
                                isClosed
                                    ? undefined
                                    : 'Conta-se ao fechar a caixa'
                            "
                        />
                    </template>
                </PageHeader>

                <section
                    aria-labelledby="pos-report-methods"
                    :class="[wellSection, 'p-5 sm:p-6']"
                >
                    <h2
                        id="pos-report-methods"
                        class="eyebrow text-zinc-600 dark:text-zinc-400"
                    >
                        Por meio de pagamento
                    </h2>
                    <p
                        v-if="summary.by_method.length === 0"
                        class="mt-3 text-sm text-zinc-600 dark:text-zinc-400"
                    >
                        Não houve vendas neste turno.
                    </p>
                    <dl
                        v-else
                        class="mt-3 divide-y divide-zinc-900/[0.07] dark:divide-white/10"
                    >
                        <div
                            v-for="row in summary.by_method"
                            :key="row.method"
                            class="flex items-baseline justify-between gap-4 py-2.5 text-sm"
                        >
                            <dt
                                class="min-w-0 text-zinc-800 dark:text-zinc-200"
                            >
                                {{ row.label }}
                                <span
                                    class="numeric text-zinc-600 dark:text-zinc-400"
                                    >· {{ row.count }}
                                    {{
                                        row.count === 1 ? 'venda' : 'vendas'
                                    }}</span
                                >
                            </dt>
                            <dd
                                class="shrink-0 numeric font-medium text-zinc-950 dark:text-white"
                            >
                                {{ formatMinor(row.total_minor) }}
                            </dd>
                        </div>
                        <div
                            v-if="summary.first_document_no"
                            class="flex items-baseline justify-between gap-4 py-2.5 text-sm"
                        >
                            <dt class="text-zinc-700 dark:text-zinc-300">
                                Primeiro e último documento
                            </dt>
                            <dd
                                class="text-end font-mono numeric text-zinc-950 dark:text-white"
                            >
                                {{ summary.first_document_no }}
                                <template
                                    v-if="
                                        summary.last_document_no &&
                                        summary.last_document_no !==
                                            summary.first_document_no
                                    "
                                >
                                    – {{ summary.last_document_no }}
                                </template>
                            </dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between gap-4 py-2.5 text-sm text-zinc-700 dark:text-zinc-300"
                        >
                            <dt>Ilíquido · Imposto</dt>
                            <dd class="numeric text-zinc-950 dark:text-white">
                                {{ formatMinor(summary.net_total_minor) }} ·
                                {{ formatMinor(summary.tax_total_minor) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section
                    aria-labelledby="pos-report-drawer"
                    :class="[wellSection, 'p-5 sm:p-6']"
                >
                    <h2
                        id="pos-report-drawer"
                        class="eyebrow text-zinc-600 dark:text-zinc-400"
                    >
                        Gaveta
                    </h2>
                    <dl
                        class="mt-3 divide-y divide-zinc-900/[0.07] dark:divide-white/10"
                    >
                        <div
                            v-for="row in drawerRows"
                            :key="row.label"
                            class="flex items-baseline justify-between gap-4 py-2.5 text-sm"
                        >
                            <dt class="text-zinc-700 dark:text-zinc-300">
                                {{ row.label }}
                            </dt>
                            <dd class="numeric text-zinc-950 dark:text-white">
                                {{ row.sign }}{{ formatMinor(row.minor) }}
                            </dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between gap-4 py-3 text-base font-semibold"
                        >
                            <dt class="text-zinc-950 dark:text-white">
                                Esperado
                            </dt>
                            <dd class="numeric text-zinc-950 dark:text-white">
                                {{ formatMinor(expectedMinor)
                                }}<span
                                    class="ms-1 text-xs font-normal text-zinc-600 dark:text-zinc-400"
                                    >{{ suffix }}</span
                                >
                            </dd>
                        </div>
                        <template v-if="isClosed">
                            <div
                                class="flex items-baseline justify-between gap-4 py-2.5 text-sm"
                            >
                                <dt class="text-zinc-700 dark:text-zinc-300">
                                    Contado
                                </dt>
                                <dd
                                    class="numeric text-zinc-950 dark:text-white"
                                >
                                    {{
                                        session.counted_cash_minor === null
                                            ? '—'
                                            : formatMinor(
                                                  session.counted_cash_minor,
                                              )
                                    }}
                                </dd>
                            </div>
                            <div
                                class="flex items-baseline justify-between gap-4 py-2.5 text-sm"
                            >
                                <dt class="text-zinc-700 dark:text-zinc-300">
                                    Diferença
                                </dt>
                                <dd
                                    class="numeric font-semibold"
                                    :class="
                                        hasDifference
                                            ? 'text-rose-700 dark:text-rose-400'
                                            : 'text-zinc-950 dark:text-white'
                                    "
                                >
                                    {{ differenceText }}
                                    <span
                                        v-if="hasDifference"
                                        class="font-normal"
                                    >
                                        ({{
                                            (session.cash_difference_minor ??
                                                0) > 0
                                                ? 'sobram'
                                                : 'faltam'
                                        }})
                                    </span>
                                </dd>
                            </div>
                        </template>
                    </dl>
                    <p
                        v-if="session.closing_notes"
                        class="mt-3 text-sm/6 [overflow-wrap:anywhere] text-zinc-700 dark:text-zinc-300"
                    >
                        <span class="font-medium text-zinc-950 dark:text-white"
                            >Notas do fecho:</span
                        >
                        {{ session.closing_notes }}
                    </p>
                </section>

                <section
                    v-if="cash_movements.length > 0"
                    aria-labelledby="pos-report-movements"
                    :class="[wellSection, 'p-5 sm:p-6']"
                >
                    <h2
                        id="pos-report-movements"
                        class="eyebrow text-zinc-600 dark:text-zinc-400"
                    >
                        Movimentos de caixa
                    </h2>
                    <ul
                        class="mt-3 divide-y divide-zinc-900/[0.07] dark:divide-white/10"
                    >
                        <li
                            v-for="movement in cash_movements"
                            :key="movement.public_id"
                            class="flex items-baseline justify-between gap-4 py-2.5 text-sm"
                        >
                            <span
                                class="min-w-0 text-zinc-800 dark:text-zinc-200"
                            >
                                <span class="font-medium">{{
                                    movement.type_label
                                }}</span>
                                ·
                                <span class="[overflow-wrap:anywhere]">{{
                                    movement.reason
                                }}</span>
                                <span
                                    class="numeric text-zinc-600 dark:text-zinc-400"
                                >
                                    ·
                                    {{ formatClock(movement.created_at) }}</span
                                >
                            </span>
                            <span
                                class="shrink-0 numeric font-medium text-zinc-950 dark:text-white"
                                >{{ movement.type === 'out' ? '−' : '+'
                                }}{{ formatMinor(movement.amount_minor) }}</span
                            >
                        </li>
                    </ul>
                </section>

                <section
                    aria-labelledby="pos-report-sales"
                    :class="[wellSection, 'overflow-clip']"
                >
                    <h2
                        id="pos-report-sales"
                        class="px-5 pt-5 eyebrow text-zinc-600 sm:px-6 sm:pt-6 dark:text-zinc-400"
                    >
                        Vendas
                    </h2>
                    <p
                        v-if="sales.length === 0"
                        class="px-5 py-5 text-sm text-zinc-600 sm:px-6 dark:text-zinc-400"
                    >
                        Não houve vendas neste turno.
                    </p>
                    <div
                        v-else
                        class="mt-3 overflow-x-auto overscroll-x-contain"
                    >
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b border-zinc-900/[0.07] dark:border-white/10"
                                >
                                    <th
                                        scope="col"
                                        class="px-3 py-2.5 text-start eyebrow text-zinc-600 sm:px-4 dark:text-zinc-400"
                                    >
                                        Documento
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-2.5 text-start eyebrow text-zinc-600 sm:table-cell sm:px-4 dark:text-zinc-400"
                                    >
                                        Hora
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-2.5 text-start eyebrow text-zinc-600 md:table-cell md:px-4 dark:text-zinc-400"
                                    >
                                        Cliente
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-2.5 text-start eyebrow text-zinc-600 md:table-cell md:px-4 dark:text-zinc-400"
                                    >
                                        Meio
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2.5 text-end eyebrow text-zinc-600 sm:px-4 dark:text-zinc-400"
                                    >
                                        Total
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2.5 text-end eyebrow text-zinc-600 sm:px-4 dark:text-zinc-400"
                                    >
                                        <span class="sr-only">Talão</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-900/[0.06] dark:divide-white/10"
                            >
                                <tr
                                    v-for="sale in sales"
                                    :key="sale.public_id"
                                    class="align-top"
                                >
                                    <td class="px-3 py-2.5 sm:px-4">
                                        <Link
                                            v-if="sale.document_no"
                                            :href="documentHref(sale)"
                                            class="font-mono numeric"
                                            :class="linkClass"
                                            >{{ sale.document_no }}</Link
                                        >
                                        <span v-else>—</span>
                                        <p
                                            class="mt-0.5 text-xs [overflow-wrap:anywhere] text-zinc-600 sm:hidden dark:text-zinc-400"
                                        >
                                            {{ formatClock(sale.issued_at) }} ·
                                            {{ sale.customer_name }} ·
                                            {{ sale.payment_method_label }}
                                        </p>
                                        <p
                                            class="mt-0.5 hidden text-xs [overflow-wrap:anywhere] text-zinc-600 sm:block md:hidden dark:text-zinc-400"
                                        >
                                            {{ sale.customer_name }} ·
                                            {{ sale.payment_method_label }}
                                        </p>
                                    </td>
                                    <td
                                        class="hidden px-3 py-2.5 numeric text-zinc-700 sm:table-cell sm:px-4 dark:text-zinc-300"
                                    >
                                        {{ formatClock(sale.issued_at) }}
                                    </td>
                                    <td
                                        class="hidden px-3 py-2.5 [overflow-wrap:anywhere] text-zinc-700 md:table-cell md:px-4 dark:text-zinc-300"
                                    >
                                        {{ sale.customer_name }}
                                    </td>
                                    <td
                                        class="hidden px-3 py-2.5 text-zinc-700 md:table-cell md:px-4 dark:text-zinc-300"
                                    >
                                        {{ sale.payment_method_label }}
                                    </td>
                                    <td
                                        class="px-3 py-2.5 text-end numeric font-semibold whitespace-nowrap text-zinc-950 sm:px-4 dark:text-white"
                                    >
                                        {{ formatMinor(sale.total_minor) }}
                                    </td>
                                    <td
                                        class="px-3 py-2.5 text-end whitespace-nowrap sm:px-4 print:hidden"
                                    >
                                        <a
                                            :href="sale.receipt_url"
                                            target="_blank"
                                            rel="noopener"
                                            class="tap-target text-xs text-zinc-700 dark:text-zinc-300"
                                            :class="linkClass"
                                            >Talão<span class="sr-only">
                                                de
                                                {{
                                                    sale.document_no ??
                                                    'esta venda'
                                                }}
                                                (abre noutro separador)</span
                                            ></a
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <PosCloseShiftDialog
            :open="closeOpen"
            :session-id="session.public_id"
            :register-name="session.register_name"
            :expected-cash-minor="expectedMinor"
            :currency-code="session.currency_code"
            :has-unfinished-sale="false"
            @close="closeOpen = false"
        />
    </AppLayout>
</template>

<style>
/*
 * Printing the shift report. The page sits inside the application's shell
 * (rail, header, banners), and the report is the only part that belongs on
 * paper. Rather than rely on how deep the layout nests, everything that is
 * neither an ancestor of the report nor inside it is dropped, and the
 * ancestors lose their offsets. It only applies while the report is on
 * screen, so no other page's printout changes.
 */
@media print {
    @page {
        size: A4;
        margin: 14mm 12mm;
    }

    body:has(#pos-report)
        *:not(:has(#pos-report)):not(#pos-report):not(#pos-report *):not(
            head *
        ) {
        display: none !important;
    }

    body:has(#pos-report) *:has(#pos-report) {
        position: static !important;
        padding: 0 !important;
        margin: 0 !important;
        min-height: 0 !important;
        background: #fff !important;
    }

    body:has(#pos-report),
    html:has(#pos-report) {
        background: #fff !important;
    }

    #pos-report,
    #pos-report * {
        color: #000 !important;
        background: transparent !important;
        box-shadow: none !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    #pos-report section {
        break-inside: avoid;
        border: 1px solid #999;
        border-radius: 0;
    }

    #pos-report section:has(table) {
        break-inside: auto;
    }

    #pos-report tr {
        break-inside: avoid;
    }

    #pos-report thead {
        display: table-header-group;
    }

    #pos-report .overflow-x-auto {
        overflow: visible !important;
    }
}
</style>
