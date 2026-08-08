<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheck, Gauge, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import ChartBars from '@/components/charts/ChartBars.vue';
import ChartFrame from '@/components/charts/ChartFrame.vue';
import StatTile from '@/components/charts/StatTile.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as showCustomer } from '@/routes/customers';

interface DebtRow {
    customer_public_id: string | null;
    name: string;
    credit_limit_minor: number | null;
    payment_terms_days: number;
    outstanding_minor: number;
    overdue_minor: number;
    document_count: number;
    oldest_days_past_due: number;
    over_limit: boolean;
    buckets: Record<string, number>;
}

const props = defineProps<{
    customers: DebtRow[];
    totals: { key: string; label: string; total_minor: number }[];
    buckets: { key: string; label: string }[];
    summary: {
        outstanding_minor: number;
        overdue_minor: number;
        customer_count: number;
        over_limit_count: number;
    };
    currencyCode: string;
}>();

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

/**
 * Ordinal buckets take one hue that darkens with age: the order is the
 * information, so the colour should carry it rather than spend five identities.
 */
const bucketBars = computed(() =>
    props.totals
        .filter((bucket) => bucket.total_minor > 0)
        .map((bucket) => ({
            label: bucket.label,
            value: bucket.total_minor,
        })),
);

const bucketRows = computed(() =>
    props.totals.map((bucket) => [bucket.label, money(bucket.total_minor)]),
);

function ageTone(days: number): string {
    if (days > 90) {
        return 'text-rose-700 dark:text-rose-400';
    }

    if (days > 30) {
        return 'text-amber-700 dark:text-amber-400';
    }

    return 'text-zinc-600 dark:text-zinc-400';
}
</script>

<template>
    <AppLayout>
        <Head title="Dívidas" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl space-y-6">
                <FlashBanner />

                <header>
                    <p class="eyebrow text-brand-700 dark:text-brand-300">
                        Conta corrente
                    </p>
                    <h1
                        class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                    >
                        Dívidas de clientes
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Quanto está por receber e há quanto tempo. A idade conta
                        a partir do vencimento acordado, não da data da factura.
                    </p>
                </header>

                <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Por receber"
                        :value="money(summary.outstanding_minor)"
                        :detail="`${summary.customer_count} cliente(s)`"
                    />
                    <StatTile
                        label="Vencido"
                        :value="money(summary.overdue_minor)"
                        :tone="
                            summary.overdue_minor > 0 ? 'critical' : 'neutral'
                        "
                    />
                    <StatTile
                        label="Acima do limite"
                        :value="String(summary.over_limit_count)"
                        :tone="
                            summary.over_limit_count > 0 ? 'warning' : 'neutral'
                        "
                        detail="clientes"
                    />
                    <StatTile
                        label="Dívida mais antiga"
                        :value="
                            customers.length === 0
                                ? '—'
                                : `${customers[0].oldest_days_past_due} dias`
                        "
                        :tone="
                            (customers[0]?.oldest_days_past_due ?? 0) > 90
                                ? 'critical'
                                : 'neutral'
                        "
                    />
                </section>

                <ChartFrame
                    title="Antiguidade dos saldos"
                    subtitle="Quanto de cada escalão está por cobrar."
                    :columns="['Escalão', 'Por receber']"
                    :rows="bucketRows"
                    :empty="summary.outstanding_minor === 0"
                    empty-message="Nada por receber. Todas as facturas estão liquidadas."
                >
                    <ChartBars :bars="bucketBars" :format="compactMoney" />
                </ChartFrame>

                <section class="overflow-hidden rounded-2xl surface">
                    <header
                        class="border-b border-zinc-100 p-5 dark:border-white/10"
                    >
                        <h2
                            class="text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Por cliente
                        </h2>
                        <p
                            class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Ordenado pela dívida mais antiga — a ordem por que
                            se cobra.
                        </p>
                    </header>

                    <div
                        v-if="customers.length === 0"
                        class="flex flex-col items-center gap-2 px-5 py-14 text-center"
                    >
                        <CircleCheck
                            class="size-8 text-emerald-500"
                            aria-hidden="true"
                        />
                        <p class="text-sm/6 text-zinc-500 dark:text-zinc-400">
                            Ninguém deve nada de momento.
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-100 dark:border-white/10"
                            >
                                <tr>
                                    <th class="px-5 py-3 eyebrow text-zinc-500">
                                        Cliente
                                    </th>
                                    <th
                                        v-for="bucket in buckets"
                                        :key="bucket.key"
                                        class="hidden px-3 py-3 text-right eyebrow text-zinc-500 lg:table-cell"
                                    >
                                        {{ bucket.label }}
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
                                <tr v-for="row in customers" :key="row.name">
                                    <td class="px-5 py-3">
                                        <component
                                            :is="
                                                row.customer_public_id
                                                    ? Link
                                                    : 'span'
                                            "
                                            :href="
                                                row.customer_public_id
                                                    ? showCustomer.url(
                                                          row.customer_public_id,
                                                      )
                                                    : undefined
                                            "
                                            class="rounded font-medium text-zinc-950 underline-offset-4 focus-ring hover:underline dark:text-white"
                                        >
                                            {{ row.name }}
                                        </component>
                                        <p class="text-xs">
                                            <span
                                                :class="
                                                    ageTone(
                                                        row.oldest_days_past_due,
                                                    )
                                                "
                                            >
                                                {{
                                                    row.oldest_days_past_due > 0
                                                        ? `${row.oldest_days_past_due} dias em atraso`
                                                        : 'Dentro do prazo'
                                                }}
                                            </span>
                                            <span
                                                v-if="row.over_limit"
                                                class="ms-2 inline-flex items-center gap-1 font-semibold text-rose-700 dark:text-rose-400"
                                            >
                                                <Gauge
                                                    class="size-3"
                                                    aria-hidden="true"
                                                />
                                                acima do limite
                                            </span>
                                        </p>
                                    </td>

                                    <td
                                        v-for="bucket in buckets"
                                        :key="bucket.key"
                                        class="hidden px-3 py-3 text-right numeric whitespace-nowrap lg:table-cell"
                                        :class="
                                            (row.buckets[bucket.key] ?? 0) > 0
                                                ? 'text-zinc-700 dark:text-zinc-300'
                                                : 'text-zinc-300 dark:text-zinc-600'
                                        "
                                    >
                                        {{
                                            (row.buckets[bucket.key] ?? 0) > 0
                                                ? compactMoney(
                                                      row.buckets[bucket.key],
                                                  )
                                                : '—'
                                        }}
                                    </td>

                                    <td
                                        class="px-5 py-3 text-right numeric font-medium whitespace-nowrap text-zinc-950 dark:text-white"
                                    >
                                        {{ money(row.outstanding_minor) }}
                                        <span
                                            v-if="row.overdue_minor > 0"
                                            class="block text-xs font-normal text-rose-700 dark:text-rose-400"
                                        >
                                            {{ money(row.overdue_minor) }}
                                            vencido
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <p
                    v-if="summary.over_limit_count > 0"
                    class="flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <span>
                        {{ summary.over_limit_count }} cliente(s) devem mais do
                        que o limite de crédito acordado. Vale a pena cobrar
                        antes de emitir mais.
                    </span>
                </p>
            </div>
        </div>
    </AppLayout>
</template>
