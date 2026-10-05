<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleDashed,
    CircleX,
    FileClock,
    Package,
    Timer,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ChartBars from '@/components/charts/ChartBars.vue';
import ChartFrame from '@/components/charts/ChartFrame.vue';
import ChartTrend from '@/components/charts/ChartTrend.vue';
import StatTile from '@/components/charts/StatTile.vue';
import SelectInput from '@/components/SelectInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SelectOption } from '@/types/select';

interface Summary {
    billed_minor: number;
    credited_minor: number;
    paid_minor: number;
    outstanding_minor: number;
    overdue_minor: number;
    overdue_count: number;
    document_count: number;
    tax_minor: number;
    net_minor: number;
}

const props = defineProps<{
    period: {
        key: string;
        label: string;
        comparison_label: string;
        start: string;
        end: string;
    };
    periods: { value: string; label: string }[];
    summary: Summary;
    previousSummary: Summary;
    trend: { label: string; value: number; comparison: number }[];
    topCustomers: { label: string; value: number; detail: string }[];
    typeMix: { label: string; value: number; detail: string; slot: number }[];
    operations: {
        submissions_total: number;
        submissions_accepted: number;
        submissions_failed: number;
        submissions_pending: number;
        acceptance_rate: number | null;
        median_seconds_to_settle: number | null;
        drafts_open: number;
        active_customers: number;
        tracked_items: number;
        stock_value_minor: number;
        stock_low_count: number;
    };
    currencyCode: string;
}>();

const selectedPeriod = ref(props.period.key);

const periodOptions = computed<SelectOption[]>(() => props.periods);

function changePeriod(value: string): void {
    selectedPeriod.value = value;
    router.get(
        '/analises',
        { period: value },
        { preserveState: true, replace: true },
    );
}

const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const compactFormatter = new Intl.NumberFormat('pt-AO', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

function money(minor: number): string {
    return `${moneyFormatter.format(minor / 100)} ${props.currencyCode}`;
}

function compactMoney(minor: number): string {
    return `${compactFormatter.format(minor / 100)} ${props.currencyCode}`;
}

function duration(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    if (seconds < 90) {
        return `${seconds} s`;
    }

    const minutes = Math.round(seconds / 60);

    return minutes < 90 ? `${minutes} min` : `${Math.round(minutes / 60)} h`;
}

const trendRows = computed(() =>
    props.trend.map((point) => [
        point.label,
        money(point.value),
        money(point.comparison),
    ]),
);

const customerRows = computed(() =>
    props.topCustomers.map((entry) => [
        entry.label,
        entry.detail,
        money(entry.value),
    ]),
);

const mixRows = computed(() =>
    props.typeMix.map((entry) => [
        entry.label,
        entry.detail,
        money(entry.value),
    ]),
);

/** Status marks always ship with an icon and a label, never colour alone. */
const submissionHealth = computed(() => [
    {
        label: 'Aceites pela AGT',
        value: props.operations.submissions_accepted,
        icon: CircleCheck,
        colour: 'var(--viz-good)',
        text: 'text-emerald-700 dark:text-emerald-400',
    },
    {
        label: 'Em curso',
        value: props.operations.submissions_pending,
        icon: CircleDashed,
        colour: 'var(--viz-warning)',
        text: 'text-amber-700 dark:text-amber-400',
    },
    {
        label: 'Recusadas ou falhadas',
        value: props.operations.submissions_failed,
        icon: CircleX,
        colour: 'var(--viz-critical)',
        text: 'text-rose-700 dark:text-rose-400',
    },
]);
</script>

<template>
    <AppLayout>
        <Head title="Análises" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl space-y-6">
                <header
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p class="eyebrow text-zinc-500 dark:text-zinc-400">
                            Negócio
                        </p>
                        <h1
                            class="mt-2.5 text-[2.125rem] leading-[1.08] display text-zinc-950 dark:text-white"
                        >
                            Análises
                        </h1>
                        <p
                            class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            Cada número é mostrado contra
                            {{ period.comparison_label.toLowerCase() }}, para
                            que a variação signifique alguma coisa.
                        </p>
                    </div>

                    <div class="w-full sm:max-w-[15rem]">
                        <SelectInput
                            :model-value="selectedPeriod"
                            :options="periodOptions"
                            @update:model-value="
                                (value) => changePeriod(String(value))
                            "
                        />
                    </div>
                </header>

                <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Facturado"
                        :value="money(summary.billed_minor)"
                        :current="summary.billed_minor"
                        :previous="previousSummary.billed_minor"
                        :comparison-label="period.comparison_label"
                    />
                    <StatTile
                        label="Recebido"
                        :value="money(summary.paid_minor)"
                        :current="summary.paid_minor"
                        :previous="previousSummary.paid_minor"
                        :comparison-label="period.comparison_label"
                        tone="good"
                    />
                    <StatTile
                        label="IVA liquidado"
                        :value="money(summary.tax_minor)"
                        :current="summary.tax_minor"
                        :previous="previousSummary.tax_minor"
                        :comparison-label="period.comparison_label"
                    />
                    <StatTile
                        label="Documentos"
                        :value="String(summary.document_count)"
                        :current="summary.document_count"
                        :previous="previousSummary.document_count"
                        :comparison-label="period.comparison_label"
                    />
                </section>

                <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Em dívida"
                        :value="money(summary.outstanding_minor)"
                        :current="summary.outstanding_minor"
                        :previous="previousSummary.outstanding_minor"
                        :comparison-label="period.comparison_label"
                        :higher-is-better="false"
                        :tone="
                            summary.outstanding_minor > 0
                                ? 'warning'
                                : 'neutral'
                        "
                    />
                    <StatTile
                        label="Vencido"
                        :value="money(summary.overdue_minor)"
                        :current="summary.overdue_minor"
                        :previous="previousSummary.overdue_minor"
                        :comparison-label="period.comparison_label"
                        :higher-is-better="false"
                        :tone="
                            summary.overdue_minor > 0 ? 'critical' : 'neutral'
                        "
                        :detail="`${summary.overdue_count} documento(s)`"
                    />
                    <StatTile
                        label="Notas de crédito"
                        :value="money(summary.credited_minor)"
                        :current="summary.credited_minor"
                        :previous="previousSummary.credited_minor"
                        :comparison-label="period.comparison_label"
                        :higher-is-better="false"
                    />
                    <StatTile
                        label="Base tributável"
                        :value="money(summary.net_minor)"
                        :current="summary.net_minor"
                        :previous="previousSummary.net_minor"
                        :comparison-label="period.comparison_label"
                    />
                </section>

                <ChartFrame
                    title="Facturação ao longo do período"
                    :subtitle="`${period.label} contra ${period.comparison_label.toLowerCase()}, no mesmo ponto do calendário.`"
                    :columns="[
                        'Período',
                        period.label,
                        period.comparison_label,
                    ]"
                    :rows="trendRows"
                    :empty="summary.document_count === 0"
                    empty-message="Nenhum documento emitido neste período."
                >
                    <template #legend>
                        <div
                            class="flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    class="h-0.5 w-4 rounded-full"
                                    style="background: var(--viz-series-1)"
                                    aria-hidden="true"
                                />
                                {{ period.label }}
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    class="h-0.5 w-4 rounded-full"
                                    style="background: var(--viz-comparison)"
                                    aria-hidden="true"
                                />
                                {{ period.comparison_label }}
                            </span>
                        </div>
                    </template>

                    <ChartTrend
                        :points="trend"
                        :format="money"
                        :series-label="period.label"
                        :comparison-label="period.comparison_label"
                    />
                </ChartFrame>

                <div class="grid gap-6 lg:grid-cols-2">
                    <ChartFrame
                        title="Principais clientes"
                        subtitle="Quem mais facturou neste período."
                        :columns="['Cliente', 'Documentos', 'Facturado']"
                        :rows="customerRows"
                        :empty="topCustomers.length === 0"
                    >
                        <ChartBars
                            :bars="topCustomers"
                            :format="compactMoney"
                        />
                    </ChartFrame>

                    <ChartFrame
                        title="Por tipo de documento"
                        subtitle="Onde está o valor emitido."
                        :columns="['Tipo', 'Quantidade', 'Valor']"
                        :rows="mixRows"
                        :empty="typeMix.length === 0"
                    >
                        <ChartBars
                            :bars="typeMix"
                            :format="compactMoney"
                            categorical
                        />
                    </ChartFrame>
                </div>

                <section class="viz overflow-hidden rounded-2xl surface">
                    <header
                        class="border-b border-zinc-100 p-5 dark:border-white/10"
                    >
                        <h2
                            class="text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Operação
                        </h2>
                        <p
                            class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Se os documentos estão a chegar à AGT, quanto tempo
                            demoram, e o que ficou por fechar.
                        </p>
                    </header>

                    <div class="p-5">
                        <div
                            v-if="operations.submissions_total === 0"
                            class="py-6 text-center text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Nenhuma comunicação à AGT neste período.
                        </div>

                        <ul v-else class="space-y-3">
                            <li
                                v-for="entry in submissionHealth"
                                :key="entry.label"
                                class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1"
                            >
                                <p
                                    class="inline-flex min-w-0 items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300"
                                >
                                    <component
                                        :is="entry.icon"
                                        :class="[entry.text, 'size-4 shrink-0']"
                                        aria-hidden="true"
                                    />
                                    {{ entry.label }}
                                </p>
                                <p
                                    class="numeric text-sm font-medium text-zinc-950 dark:text-white"
                                >
                                    {{ entry.value }}
                                </p>
                                <div
                                    class="col-span-2 h-2 w-full overflow-hidden rounded-full"
                                    :style="{
                                        background:
                                            'color-mix(in srgb, var(--viz-grid) 60%, transparent)',
                                    }"
                                >
                                    <div
                                        class="h-full rounded-full transition-[width] duration-500 ease-out"
                                        :style="{
                                            width: `${(entry.value / Math.max(1, operations.submissions_total)) * 100}%`,
                                            background: entry.colour,
                                        }"
                                    />
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div
                        class="grid gap-px border-t border-zinc-100 bg-zinc-100 sm:grid-cols-2 lg:grid-cols-4 dark:border-white/10 dark:bg-white/10"
                    >
                        <div class="bg-white p-4 dark:bg-zinc-900">
                            <p class="eyebrow text-zinc-500">Aceitação AGT</p>
                            <p
                                class="mt-2 text-xl font-semibold text-zinc-950 lining-nums dark:text-white"
                            >
                                {{
                                    operations.acceptance_rate === null
                                        ? '—'
                                        : `${operations.acceptance_rate}%`
                                }}
                            </p>
                            <p
                                class="mt-1 inline-flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                <Timer class="size-3.5" aria-hidden="true" />
                                mediana
                                {{
                                    duration(
                                        operations.median_seconds_to_settle,
                                    )
                                }}
                            </p>
                        </div>

                        <div class="bg-white p-4 dark:bg-zinc-900">
                            <p class="eyebrow text-zinc-500">
                                Rascunhos por fechar
                            </p>
                            <p
                                class="mt-2 text-xl font-semibold text-zinc-950 lining-nums dark:text-white"
                            >
                                {{ operations.drafts_open }}
                            </p>
                            <p
                                class="mt-1 inline-flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                <FileClock
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                em qualquer data
                            </p>
                        </div>

                        <div class="bg-white p-4 dark:bg-zinc-900">
                            <p class="eyebrow text-zinc-500">
                                Clientes activos
                            </p>
                            <p
                                class="mt-2 text-xl font-semibold text-zinc-950 lining-nums dark:text-white"
                            >
                                {{ operations.active_customers }}
                            </p>
                            <p
                                class="mt-1 inline-flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                <Users class="size-3.5" aria-hidden="true" />
                                na ficha de clientes
                            </p>
                        </div>

                        <div class="bg-white p-4 dark:bg-zinc-900">
                            <p class="eyebrow text-zinc-500">
                                Valor em armazém
                            </p>
                            <p
                                class="mt-2 text-xl font-semibold text-zinc-950 lining-nums dark:text-white"
                            >
                                {{ compactMoney(operations.stock_value_minor) }}
                            </p>
                            <p
                                :class="[
                                    operations.stock_low_count > 0
                                        ? 'text-amber-700 dark:text-amber-400'
                                        : 'text-zinc-500 dark:text-zinc-400',
                                    'mt-1 inline-flex items-center gap-1.5 text-xs',
                                ]"
                            >
                                <Package class="size-3.5" aria-hidden="true" />
                                {{ operations.stock_low_count }} abaixo do
                                mínimo
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
