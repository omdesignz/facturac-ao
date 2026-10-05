<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheck, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageStat from '@/components/PageStat.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { agingBucketColours, agingShare } from '@/lib/aging';
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

const currencyLabel = computed(() =>
    props.currencyCode === 'AOA' ? 'Kz' : props.currencyCode,
);

function wholeAmount(minor: number): string {
    return new Intl.NumberFormat('pt-AO', {
        maximumFractionDigits: 0,
    }).format(Math.round(minor / 100));
}

function money(minor: number): string {
    return `${new Intl.NumberFormat('pt-AO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100)} ${currencyLabel.value}`;
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

const oldestDays = computed(
    () => props.customers[0]?.oldest_days_past_due ?? 0,
);

/** Read aloud in place of the bar: every bucket that holds money, with its amount. */
function bucketSummary(row: DebtRow): string {
    return props.buckets
        .filter((bucket) => (row.buckets[bucket.key] ?? 0) > 0)
        .map((bucket) => `${bucket.label}: ${money(row.buckets[bucket.key])}`)
        .join('; ');
}
</script>

<template>
    <AppLayout>
        <Head title="Dívidas" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl">
                <FlashBanner />

                <PageHeader
                    eyebrow="Clientes · Dívidas"
                    title="Dívidas de clientes"
                    description="Quanto está por receber e há quanto tempo. A idade conta a partir do vencimento acordado, não da data da factura."
                >
                    <template #stats>
                        <PageStat
                            label="Por receber"
                            :value="`${wholeAmount(summary.outstanding_minor)}`"
                            :detail="
                                plural(
                                    summary.customer_count,
                                    'cliente em dívida',
                                    'clientes em dívida',
                                )
                            "
                        />
                        <PageStat
                            label="Vencido"
                            :value="wholeAmount(summary.overdue_minor)"
                            :detail="
                                summary.overdue_minor > 0
                                    ? 'já passou do prazo'
                                    : 'nada vencido'
                            "
                        >
                            <span
                                :class="
                                    summary.overdue_minor > 0
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : ''
                                "
                                >{{ wholeAmount(summary.overdue_minor) }}</span
                            >
                        </PageStat>
                        <PageStat
                            label="Acima do limite"
                            :value="summary.over_limit_count"
                            detail="limite de crédito acordado"
                        />
                        <PageStat
                            label="Dívida mais antiga"
                            :value="
                                oldestDays > 0
                                    ? plural(oldestDays, 'dia', 'dias')
                                    : '—'
                            "
                            :detail="
                                oldestDays > 0
                                    ? customers[0]?.name
                                    : 'nada em atraso'
                            "
                        />
                    </template>
                </PageHeader>

                <p
                    v-if="summary.over_limit_count > 0"
                    class="mt-6 flex items-start gap-3 rounded-2xl bg-orange-50 p-4 text-sm/6 text-orange-900 dark:bg-orange-400/10 dark:text-orange-200"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0 text-orange-600 dark:text-orange-300"
                        aria-hidden="true"
                    />
                    <span>
                        {{
                            plural(
                                summary.over_limit_count,
                                'cliente deve',
                                'clientes devem',
                            )
                        }}
                        mais do que o limite de crédito acordado. Vale a pena
                        cobrar antes de emitir mais.
                    </span>
                </p>

                <!-- ------------------------------------------- ageing -->
                <section
                    class="mt-6 rounded-3xl bg-zinc-900/[0.04] p-6 dark:bg-white/[0.04]"
                    aria-labelledby="aging-title"
                >
                    <h2
                        id="aging-title"
                        class="text-base font-medium text-zinc-950 dark:text-white"
                    >
                        Antiguidade dos saldos
                    </h2>
                    <p
                        class="mt-1 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                    >
                        Quanto de cada escalão está por cobrar.
                    </p>

                    <template v-if="summary.outstanding_minor > 0">
                        <div class="mt-5 flex h-3 gap-[3px]" aria-hidden="true">
                            <span
                                v-for="bucket in totals.filter(
                                    (item) => item.total_minor > 0,
                                )"
                                :key="bucket.key"
                                class="rounded-[3px]"
                                :class="agingBucketColours[bucket.key]"
                                :style="{
                                    width: agingShare(
                                        bucket.total_minor,
                                        summary.outstanding_minor,
                                    ),
                                }"
                            />
                        </div>
                        <dl
                            class="mt-5 grid gap-x-8 gap-y-4 sm:grid-cols-3 lg:grid-cols-5"
                        >
                            <div v-for="bucket in totals" :key="bucket.key">
                                <dt
                                    class="flex items-center gap-2 text-[0.8125rem] text-zinc-600 dark:text-zinc-300"
                                >
                                    <span
                                        class="size-2 rounded-[2px]"
                                        :class="agingBucketColours[bucket.key]"
                                        aria-hidden="true"
                                    />
                                    {{ bucket.label }}
                                </dt>
                                <dd
                                    class="mt-1.5 numeric text-lg tracking-[-0.02em]"
                                    :class="
                                        bucket.total_minor > 0
                                            ? 'text-zinc-950 dark:text-white'
                                            : 'text-zinc-400 dark:text-zinc-500'
                                    "
                                >
                                    {{ wholeAmount(bucket.total_minor) }}
                                    <span class="text-xs text-zinc-400">{{
                                        currencyLabel
                                    }}</span>
                                </dd>
                            </div>
                        </dl>
                    </template>
                    <p
                        v-else
                        class="mt-5 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Nada por receber. Todas as facturas estão liquidadas.
                    </p>
                </section>

                <!-- ----------------------------------------- by customer -->
                <section
                    class="mt-5 rounded-3xl bg-zinc-900/[0.04] p-6 dark:bg-white/[0.04]"
                    aria-labelledby="customers-title"
                >
                    <h2
                        id="customers-title"
                        class="text-base font-medium text-zinc-950 dark:text-white"
                    >
                        Por cliente
                    </h2>
                    <p
                        class="mt-1 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                    >
                        Quem deve há mais tempo primeiro: a ordem por que se
                        cobra.
                    </p>

                    <div
                        v-if="customers.length === 0"
                        class="flex flex-col items-center gap-2 py-12 text-center"
                    >
                        <CircleCheck
                            class="size-7 text-lime-600 dark:text-lime-400"
                            aria-hidden="true"
                        />
                        <p class="text-sm/6 text-zinc-500 dark:text-zinc-400">
                            Ninguém deve nada de momento.
                        </p>
                    </div>

                    <ul v-else role="list" class="mt-4">
                        <li
                            v-for="row in customers"
                            :key="row.customer_public_id ?? row.name"
                            class="grid grid-cols-1 items-center gap-3 border-t border-zinc-900/[0.06] py-4 sm:grid-cols-[minmax(0,16rem)_minmax(0,1fr)_auto] dark:border-white/10"
                        >
                            <div class="min-w-0">
                                <component
                                    :is="row.customer_public_id ? Link : 'span'"
                                    :href="
                                        row.customer_public_id
                                            ? showCustomer.url(
                                                  row.customer_public_id,
                                              )
                                            : undefined
                                    "
                                    class="block truncate rounded text-sm font-semibold text-zinc-950 focus-ring dark:text-white"
                                    :class="
                                        row.customer_public_id
                                            ? 'underline-offset-4 hover:underline'
                                            : ''
                                    "
                                    >{{ row.name }}</component
                                >
                                <p
                                    class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    <span>{{
                                        plural(
                                            row.document_count,
                                            'documento',
                                            'documentos',
                                        )
                                    }}</span>
                                    <span
                                        :class="
                                            row.oldest_days_past_due > 90
                                                ? 'font-medium text-rose-700 dark:text-rose-400'
                                                : row.oldest_days_past_due > 0
                                                  ? 'text-rose-600 dark:text-rose-400'
                                                  : ''
                                        "
                                        >·
                                        {{
                                            row.oldest_days_past_due > 0
                                                ? `há ${plural(row.oldest_days_past_due, 'dia', 'dias')} em atraso`
                                                : 'dentro do prazo'
                                        }}</span
                                    >
                                    <StatusBadge
                                        v-if="row.over_limit"
                                        label="Acima do limite"
                                        tone="warning"
                                    />
                                </p>
                            </div>

                            <div
                                class="flex h-2.5 gap-[3px]"
                                role="img"
                                :aria-label="bucketSummary(row)"
                            >
                                <span
                                    v-for="bucket in buckets"
                                    v-show="(row.buckets[bucket.key] ?? 0) > 0"
                                    :key="bucket.key"
                                    class="rounded-[3px]"
                                    :class="agingBucketColours[bucket.key]"
                                    :style="{
                                        width: agingShare(
                                            row.buckets[bucket.key] ?? 0,
                                            row.outstanding_minor,
                                        ),
                                    }"
                                />
                            </div>

                            <p class="sm:text-right">
                                <span
                                    class="numeric text-sm font-semibold text-zinc-950 dark:text-white"
                                    >{{ wholeAmount(row.outstanding_minor) }}
                                    <span class="font-normal text-zinc-400">{{
                                        currencyLabel
                                    }}</span></span
                                >
                                <span
                                    v-if="row.overdue_minor > 0"
                                    class="mt-0.5 block numeric text-xs text-rose-600 dark:text-rose-400"
                                    >{{
                                        wholeAmount(row.overdue_minor)
                                    }}
                                    vencidos</span
                                >
                            </p>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
