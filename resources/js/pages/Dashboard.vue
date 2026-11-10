<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    CalendarDays,
    ChevronDown,
    ChevronRight,
    CircleGauge,
    Clock3,
    FileText,
    HandCoins,
    Mail,
    Plus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import ChartFrame from '@/components/charts/ChartFrame.vue';
import ChartTrend from '@/components/charts/ChartTrend.vue';
import DocumentBalanceOverview from '@/components/dashboard/DocumentBalanceOverview.vue';
import type { DocumentOverview } from '@/components/dashboard/DocumentBalanceOverview.vue';
import ReminderDialog from '@/components/ReminderDialog.vue';
import TodayDecisions from '@/components/TodayDecisions.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { axisAmount } from '@/lib/chart-axis';
import { initials } from '@/lib/initials';
import { useCanPrepareDocuments } from '@/lib/navigation';
import { relativeTime } from '@/lib/relative-time';
import { decisionsFor } from '@/lib/today-decisions';
import { onboarding } from '@/routes';
import { show as agtConnection } from '@/routes/agt/connection';
import { index as agtSubmissions } from '@/routes/agt/submissions';
import { show as customerShow } from '@/routes/customers';
import { index as debtsIndex } from '@/routes/debts';
import { index as documentsIndex } from '@/routes/documents';
import { index as importIndex } from '@/routes/imports';
import { create as invoiceCreate } from '@/routes/invoices';
import { security } from '@/routes/settings';
import { index as stockIndex } from '@/routes/stock';

interface CompanyReadiness {
    configured: boolean;
    status: string;
    status_label: string;
}

interface AgtReadiness {
    configured: boolean;
    verified: boolean;
    status: string;
    status_label: string;
}

interface Kpis {
    billed_today_minor: number;
    billed_last_week_minor: number;
    issued_today: number;
    credit_notes_today: number;
    valid_today: number;
    pending_today: number;
    invalid_today: number;
    attention_count: number;
    outstanding_minor: number;
    overdue_minor: number;
    overdue_count: number;
}

interface Review {
    attention_count: number;
    in_flight_count: number;
    overdue_count: number;
    drafts_open: number;
}

interface Collections {
    customers: {
        customer_public_id: string | null;
        name: string;
        outstanding_minor: number;
        overdue_minor: number;
        document_count: number;
        oldest_days_past_due: number;
        buckets: Record<string, number>;
        recently_reminded: boolean;
    }[];
    customer_count: number;
    totals: { key: string; label: string; total_minor: number }[];
    outstanding_minor: number;
}

interface TrendDay {
    date: string;
    billed_minor: number;
}

const props = defineProps<{
    companyReadiness: CompanyReadiness;
    agtReadiness: AgtReadiness;
    now: string;
    currencyCode: string;
    kpis: Kpis | null;
    documentOverview?: DocumentOverview | null;
    /** The latest document that needs a hand, or simply the latest one; null before the first. */
    focus: {
        reason: 'invalid' | 'contingency' | 'overdue' | 'latest';
        document_no: string | null;
        agt_message: string | null;
    } | null;
    review: Review | null;
    collections?: Collections | null;
    trend?: TrendDay[] | null;
    /** Viewers may read the ledger, but reminders are not theirs to prepare. */
    canRemindCustomers: boolean;
    lowStock?: { name: string; quantity: number; unit: string } | null;
}>();

/** The customer whose reminder is being read before it goes out. */
const reminderFor = ref<{ public_id: string; name: string } | null>(null);
const reminderOpen = ref(false);

function openReminder(customer: { public_id: string; name: string }): void {
    reminderFor.value = customer;
    reminderOpen.value = true;
}

/** What needs a decision today, worked out from figures this page already has. */
const decisions = computed(() =>
    decisionsFor(
        {
            ...props,
            collections: props.canRemindCustomers ? props.collections : null,
        },
        {
            fiscal: agtSubmissions.url(),
            documents: documentsIndex.url({ query: { status: 'attention' } }),
            stock: stockIndex.url({ query: { low: '1' } }),
        },
    ),
);

const page = usePage();
const user = computed(() => page.props.auth.user);

/** Everything on this page is read in the business's own timezone. */
const TIMEZONE = 'Africa/Luanda';

const nowDate = computed(() => new Date(props.now));

const dateLine = computed(() => {
    const day = new Intl.DateTimeFormat('pt-AO', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: TIMEZONE,
    }).format(nowDate.value);

    return `${day.charAt(0).toUpperCase()}${day.slice(1)}`;
});

/**
 * The setup steps, shown in place of the figures until the company can
 * invoice. Each one links to where it is done.
 */
const readiness = computed(() => [
    {
        label: 'Email confirmado',
        description: 'Confirmámos que o endereço da sua conta é mesmo seu.',
        done: user.value?.email_verified_at !== null,
        href: null,
    },
    {
        label: 'Empresa e estabelecimento',
        description: 'NIF, denominação e o local onde emite as facturas.',
        done: props.companyReadiness.configured,
        href: onboarding.url(),
    },
    {
        label: 'Autenticação em dois passos',
        description: 'Uma segunda prova antes de qualquer operação sensível.',
        done: user.value?.two_factor_enabled ?? false,
        href: security.url(),
    },
    {
        label: 'Credenciais da AGT',
        description: 'As chaves que assinam e entregam as suas facturas.',
        done: props.agtReadiness.configured,
        href: agtConnection.url(),
    },
]);

const readinessDone = computed(
    () => readiness.value.filter((item) => item.done).length,
);

const setupIncomplete = computed(() => !props.companyReadiness.configured);

/** Figures belong on the page once the company can invoice. */
const showFigures = computed(() => !setupIncomplete.value && props.kpis);

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** Whole kwanzas with the locale's grouping; the centimos live on the document. */
function formatAmount(minor: number): string {
    return new Intl.NumberFormat('pt-AO', {
        maximumFractionDigits: 0,
    }).format(Math.round(minor / 100));
}

/** A headline figure: millions shortened to "23,4 M", anything smaller in full. */
function headlineAmount(minor: number): string {
    const value = Math.round(minor / 100);

    if (Math.abs(value) >= 1_000_000) {
        return `${new Intl.NumberFormat('pt-AO', { maximumFractionDigits: 1 }).format(value / 1_000_000)} M`;
    }

    return formatAmount(minor);
}

const currencyLabel = computed(() =>
    props.currencyCode === 'AOA' ? 'Kz' : props.currencyCode,
);

/* ------------------------------------------------------------------ period */

const periods = [
    { days: 7, label: '7 dias' },
    { days: 30, label: '30 dias' },
] as const;

const periodDays = ref<7 | 30>(30);

const periodTrend = computed(() =>
    (props.trend ?? []).slice(-periodDays.value),
);

function total(days: TrendDay[]): number {
    return days.reduce((sum, day) => sum + day.billed_minor, 0);
}

const billedInPeriod = computed(() => total(periodTrend.value));

const billingDays = computed(
    () => periodTrend.value.filter((day) => day.billed_minor > 0).length,
);

const averageLine = computed(() => {
    if (billingDays.value === 0) {
        return `Nada facturado nos últimos ${periodDays.value} dias`;
    }

    return `Média de ${formatAmount(billedInPeriod.value / billingDays.value)} ${currencyLabel.value} nos ${plural(billingDays.value, 'dia', 'dias')} com facturação`;
});

/* ------------------------------------------------------------- collections */

function bucket(
    customer: Collections['customers'][number],
    ...keys: string[]
): number {
    return keys.reduce((sum, key) => sum + (customer.buckets[key] ?? 0), 0);
}

/* ---------------------------------------------------------------- activity */

const activity = computed(
    () => page.props.notifications?.recent.slice(0, 5) ?? [],
);

const activityDot: Record<string, string> = {
    success: 'bg-[var(--ui-positive)]',
    critical: 'bg-[var(--ui-critical)]',
    warning: 'bg-[var(--ui-warning)]',
    neutral: 'bg-[var(--ui-ink-3)]',
};

/* ----------------------------------------------------------------- shared */

const card =
    'rounded-xl bg-[var(--surface-raised)] shadow-[var(--ui-card-shadow)]';
const cardTitle = 'text-sm font-semibold text-[var(--ui-ink)]';
const tableHead =
    'border-y border-[var(--ui-line)] px-3 py-2 text-xs font-medium text-[var(--ui-ink-3)]';
const tableCell = 'border-b border-[var(--ui-line)] px-3 numeric';

const canPrepareDocuments = useCanPrepareDocuments();
const monthLabel = computed(() =>
    new Intl.DateTimeFormat('pt-AO', {
        month: 'long',
        timeZone: TIMEZONE,
    }).format(nowDate.value),
);
const periodRows = computed(() =>
    periodTrend.value.map((day) => [
        new Intl.DateTimeFormat('pt-AO', {
            day: 'numeric',
            month: 'short',
            timeZone: TIMEZONE,
        }).format(new Date(`${day.date}T12:00:00+01:00`)),
        `${formatAmount(day.billed_minor)} ${currencyLabel.value}`,
    ]),
);
const chartPoints = computed(() =>
    periodTrend.value.map((day, index) => ({
        label: periodRows.value[index][0],
        value: day.billed_minor,
    })),
);
</script>

<template>
    <AppLayout>
        <Head title="Hoje" />
        <div
            class="mx-auto flex max-w-[90rem] flex-col gap-5 px-4 pt-6 pb-10 sm:px-6"
        >
            <header class="flex flex-wrap items-center gap-4">
                <div class="flex min-w-0 flex-1 items-baseline gap-3">
                    <h1
                        class="text-page-title leading-[1.2] font-[650] tracking-[-0.025em]"
                    >
                        Hoje
                    </h1>
                    <p class="text-xs text-[var(--ui-ink-3)]">{{ dateLine }}</p>
                </div>
                <AppButton
                    v-if="canPrepareDocuments"
                    variant="ink"
                    :href="invoiceCreate.url()"
                    ><Plus class="size-4" aria-hidden="true" />Nova
                    factura</AppButton
                >
            </header>

            <div
                v-if="showFigures && kpis"
                class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))] overflow-clip rounded-xl bg-[var(--surface-raised)] shadow-[var(--ui-card-shadow)]"
            >
                <Link
                    :href="
                        documentsIndex.url({
                            query: {
                                from: now.slice(0, 10),
                                to: now.slice(0, 10),
                            },
                        })
                    "
                    class="flex flex-col gap-2 p-4 focus-ring hover:bg-[var(--ui-head)] active:bg-[var(--ui-wash)]"
                >
                    <span
                        class="flex items-center gap-2 text-xs text-[var(--ui-ink-2)]"
                        ><FileText
                            class="size-3.5"
                            aria-hidden="true"
                        />Facturado hoje</span
                    >
                    <span
                        class="numeric text-stat leading-none font-[650] tracking-[-0.03em]"
                        >{{ headlineAmount(kpis.billed_today_minor) }}
                        <span class="text-sm font-medium">{{
                            currencyLabel
                        }}</span></span
                    >
                    <span class="text-xs text-[var(--ui-ink-3)]">{{
                        plural(kpis.issued_today, 'documento', 'documentos')
                    }}</span>
                </Link>
                <Link
                    :href="debtsIndex.url()"
                    class="flex flex-col gap-2 border-s border-[var(--ui-line)] p-4 focus-ring hover:bg-[var(--ui-head)] active:bg-[var(--ui-wash)]"
                >
                    <span
                        class="flex items-center gap-2 text-xs text-[var(--ui-ink-2)]"
                        ><HandCoins class="size-3.5" aria-hidden="true" />Por
                        receber</span
                    >
                    <span
                        class="numeric text-stat leading-none font-[650] tracking-[-0.03em]"
                        >{{ headlineAmount(kpis.outstanding_minor) }}
                        <span class="text-sm font-medium">{{
                            currencyLabel
                        }}</span></span
                    >
                    <span class="text-xs text-[var(--ui-ink-3)]">{{
                        collections
                            ? plural(
                                  collections.customer_count,
                                  'cliente',
                                  'clientes',
                              )
                            : 'Saldo actual'
                    }}</span>
                </Link>
                <Link
                    :href="
                        documentsIndex.url({
                            query: {
                                from: documentOverview?.from,
                                to: documentOverview?.to,
                            },
                        })
                    "
                    class="flex flex-col gap-2 border-s border-[var(--ui-line)] p-4 focus-ring hover:bg-[var(--ui-head)] active:bg-[var(--ui-wash)]"
                >
                    <span
                        class="flex items-center gap-2 text-xs text-[var(--ui-ink-2)]"
                        ><CalendarDays
                            class="size-3.5"
                            aria-hidden="true"
                        />Vendas emitidas este mês</span
                    >
                    <span
                        class="numeric text-stat leading-none font-[650] tracking-[-0.03em]"
                        >{{ documentOverview?.issued ?? '—' }}</span
                    >
                    <span
                        class="text-xs text-[var(--ui-ink-3)] first-letter:uppercase"
                        >{{ monthLabel }}</span
                    >
                </Link>
                <Link
                    :href="debtsIndex.url()"
                    class="flex flex-col gap-2 border-s border-[var(--ui-line)] p-4 focus-ring hover:bg-[var(--ui-head)] active:bg-[var(--ui-wash)]"
                >
                    <span
                        class="flex items-center gap-2 text-xs text-[var(--ui-ink-2)]"
                        ><Clock3 class="size-3.5" aria-hidden="true" />Em
                        atraso</span
                    >
                    <span
                        class="numeric text-stat leading-none font-[650] tracking-[-0.03em]"
                        >{{ headlineAmount(kpis.overdue_minor) }}
                        <span class="text-sm font-medium">{{
                            currencyLabel
                        }}</span></span
                    >
                    <span class="text-xs text-[var(--ui-ink-3)]"
                        >{{
                            plural(
                                kpis.overdue_count,
                                'documento',
                                'documentos',
                            )
                        }}, de todos os meses</span
                    >
                </Link>
            </div>

            <div class="flex flex-wrap gap-4">
                <section
                    v-if="setupIncomplete"
                    aria-labelledby="setup-title"
                    :class="[
                        card,
                        'flex min-w-0 flex-[999_1_33.75rem] flex-col gap-4 px-5 pt-[1.125rem] pb-5 max-sm:flex-none',
                    ]"
                >
                    <div class="flex items-start gap-4">
                        <div class="flex flex-1 flex-col gap-1">
                            <h2 id="setup-title" :class="cardTitle">
                                Prepare a empresa para facturar
                            </h2>
                            <p class="text-[0.78125rem] text-[var(--ui-ink-3)]">
                                {{ readinessDone }} de
                                {{ readiness.length }} passos concluídos
                            </p>
                        </div>
                    </div>
                    <div
                        class="h-1.5 overflow-clip rounded-full bg-[var(--ui-well)]"
                        role="progressbar"
                        aria-label="Passos concluídos"
                        aria-valuemin="0"
                        :aria-valuemax="readiness.length"
                        :aria-valuenow="readinessDone"
                    >
                        <div
                            class="h-full rounded-full bg-accent-400 motion-safe:transition-[width] motion-safe:duration-200"
                            :style="{
                                width: `${(readinessDone / readiness.length) * 100}%`,
                            }"
                        />
                    </div>
                    <ul
                        role="list"
                        class="-mx-5 border-t border-[var(--ui-line)]"
                    >
                        <li
                            v-for="item in readiness"
                            :key="item.label"
                            class="border-b border-[var(--ui-line)] last:border-b-0"
                        >
                            <component
                                :is="item.href && !item.done ? Link : 'div'"
                                :href="
                                    item.href && !item.done
                                        ? item.href
                                        : undefined
                                "
                                class="flex items-center gap-3 px-5 py-3"
                                :class="
                                    item.href && !item.done
                                        ? 'focus-ring -outline-offset-2 hover:bg-[var(--ui-wash)]'
                                        : ''
                                "
                            >
                                <BadgeCheck
                                    v-if="item.done"
                                    class="size-[1.125rem] shrink-0 text-[var(--ui-positive)]"
                                    aria-hidden="true"
                                />
                                <CircleGauge
                                    v-else
                                    class="size-[1.125rem] shrink-0 text-[var(--ui-gold-ink)]"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block text-[0.8125rem] font-semibold"
                                        :class="
                                            item.done
                                                ? 'text-[var(--ui-ink-2)]'
                                                : 'text-[var(--ui-ink)]'
                                        "
                                        ><span class="sr-only">{{
                                            item.done
                                                ? 'Concluído: '
                                                : 'Por fazer: '
                                        }}</span
                                        >{{ item.label }}</span
                                    >
                                    <span
                                        class="mt-0.5 block text-xs text-[var(--ui-ink-3)]"
                                        >{{ item.description }}</span
                                    >
                                </span>
                                <ChevronRight
                                    v-if="item.href && !item.done"
                                    class="size-4 shrink-0 text-[var(--ui-ink-3)]"
                                    aria-hidden="true"
                                />
                            </component>
                        </li>
                    </ul>
                </section>

                <section
                    v-else-if="!focus"
                    :class="[
                        card,
                        'grid min-w-0 flex-[999_1_33.75rem] place-items-center px-6 py-12 text-center max-sm:flex-none',
                    ]"
                >
                    <div class="max-w-md">
                        <h2 :class="cardTitle">
                            Ainda não emitiu nenhum documento
                        </h2>
                        <p
                            class="mt-1.5 text-[0.8125rem]/5 text-[var(--ui-ink-3)]"
                        >
                            A primeira factura começa como rascunho. Confira os
                            totais e só depois emita. Se já tem clientes e
                            artigos noutro programa, traga-os primeiro.
                        </p>
                        <div
                            v-if="canPrepareDocuments"
                            class="mt-5 flex flex-wrap justify-center gap-2"
                        >
                            <AppButton
                                variant="gold"
                                size="sm"
                                :href="invoiceCreate.url()"
                            >
                                Criar factura
                            </AppButton>
                            <AppButton
                                variant="outline"
                                size="sm"
                                :href="importIndex.url()"
                            >
                                Importar dados
                            </AppButton>
                        </div>
                    </div>
                </section>
                <DocumentBalanceOverview
                    v-if="showFigures && focus && documentOverview"
                    class="flex-[1.5_1_33.75rem]"
                    :summary="documentOverview"
                    :period="monthLabel"
                    :href="
                        documentsIndex.url({
                            query: {
                                from: documentOverview.from,
                                to: documentOverview.to,
                            },
                        })
                    "
                />
                <TodayDecisions
                    class="flex-[1_1_21.25rem]"
                    :decisions="decisions"
                    :currency-label="currencyLabel"
                    :format-amount="formatAmount"
                    @remind="openReminder"
                />
            </div>

            <div v-if="showFigures && focus" class="flex flex-wrap gap-4">
                <ChartFrame
                    class="min-w-0 flex-[1.5_1_33.75rem]"
                    title="Facturação diária"
                    :subtitle="trend ? averageLine : 'A carregar a facturação…'"
                    :columns="['Dia', 'Facturado']"
                    :rows="periodRows"
                >
                    <template #legend>
                        <div
                            role="group"
                            aria-label="Período do gráfico"
                            class="flex rounded-lg bg-[var(--ui-wash)] p-1"
                        >
                            <button
                                v-for="period in periods"
                                :key="period.days"
                                type="button"
                                :aria-pressed="periodDays === period.days"
                                class="h-7 rounded px-2 text-xs focus-ring"
                                :class="
                                    periodDays === period.days
                                        ? 'bg-[var(--surface-raised)] font-semibold shadow-[var(--ui-control-shadow)]'
                                        : 'text-[var(--ui-ink-2)]'
                                "
                                @click="periodDays = period.days"
                            >
                                {{ period.label }}
                            </button>
                        </div>
                    </template>
                    <ChartTrend
                        v-if="trend"
                        :points="chartPoints"
                        :format="
                            (value) => `${formatAmount(value)} ${currencyLabel}`
                        "
                        :tick-format="axisAmount"
                        series-label="Facturado"
                        :height="200"
                        :area="false"
                        highlight-last
                    />
                    <div
                        v-else
                        class="h-[200px] animate-pulse rounded-lg bg-[var(--ui-wash)] motion-reduce:animate-none"
                        role="status"
                        aria-label="A carregar facturação"
                    />
                </ChartFrame>
                <section
                    aria-labelledby="activity-title"
                    :class="[
                        card,
                        'flex-[1_1_21.25rem] px-[1.125rem] pt-4 pb-1.5',
                    ]"
                >
                    <h2 id="activity-title" :class="[cardTitle, 'mbe-3']">
                        Actividade
                    </h2>
                    <p
                        v-if="activity.length === 0"
                        class="pb-3.5 text-[0.8125rem] text-[var(--ui-ink-3)]"
                    >
                        O que acontecer na empresa aparece aqui: pagamentos,
                        validações da AGT e lembretes.
                    </p>
                    <ol v-else role="list">
                        <li
                            v-for="(entry, index) in activity"
                            :key="entry.id"
                            class="flex gap-3"
                        >
                            <span
                                class="flex w-2 flex-col items-center pbs-[0.3125rem]"
                                aria-hidden="true"
                            >
                                <span
                                    class="size-2 rounded-full ring-[3px] ring-[var(--surface-raised)]"
                                    :class="
                                        activityDot[entry.tone] ??
                                        activityDot.neutral
                                    "
                                />
                                <span
                                    v-if="index < activity.length - 1"
                                    class="mbs-1 w-px flex-1 bg-[var(--ui-line)]"
                                />
                            </span>
                            <span
                                class="flex min-w-0 flex-1 flex-col gap-0.5 pbe-3.5"
                            >
                                <component
                                    :is="entry.url ? Link : 'span'"
                                    :href="entry.url ?? undefined"
                                    class="rounded text-[0.8125rem]/[1.4] text-[var(--ui-ink)]"
                                    :class="
                                        entry.url
                                            ? 'focus-ring hover:underline hover:decoration-[var(--ui-line-strong)] hover:underline-offset-2'
                                            : ''
                                    "
                                    >{{ entry.title }}</component
                                >
                                <span
                                    class="text-[0.71875rem] text-[var(--ui-ink-3)] first-letter:uppercase"
                                    >{{ relativeTime(entry.created_at) }}</span
                                >
                            </span>
                        </li>
                    </ol>
                </section>
            </div>

            <details
                v-if="showFigures"
                class="overflow-clip rounded-xl bg-[var(--surface-raised)] shadow-[var(--ui-card-shadow)]"
            >
                <summary
                    class="flex cursor-pointer items-center justify-between gap-3 p-5 text-sm font-semibold focus-ring hover:bg-[var(--ui-head)] active:bg-[var(--ui-wash)]"
                >
                    Extracto de clientes
                    <ChevronDown class="size-4" aria-hidden="true" />
                </summary>
                <section
                    aria-labelledby="owe-title"
                    :class="[
                        'min-w-0 flex-[999_1_33.75rem] overflow-clip max-sm:flex-none',
                    ]"
                >
                    <div class="flex items-center px-[1.125rem] pt-4 pb-3">
                        <h2 id="owe-title" :class="[cardTitle, 'flex-1']">
                            Quem me deve
                        </h2>
                        <Link
                            :href="debtsIndex.url()"
                            class="inline-flex items-center gap-1 rounded text-[0.78125rem] font-medium text-[var(--ui-ink-2)] focus-ring hover:text-[var(--ui-ink)]"
                            >Ver tudo<ChevronRight
                                class="size-3.5"
                                aria-hidden="true"
                        /></Link>
                    </div>

                    <div
                        v-if="collections === undefined"
                        class="flex flex-col border-t border-[var(--ui-line)]"
                        aria-hidden="true"
                    >
                        <div
                            v-for="row in 4"
                            :key="row"
                            class="flex h-12 items-center gap-3 border-b border-[var(--ui-line)] px-[1.125rem] last:border-b-0"
                        >
                            <span
                                class="size-6 animate-pulse rounded-full bg-[var(--ui-well)]"
                            />
                            <span
                                class="h-3 w-40 animate-pulse rounded bg-[var(--ui-well)]"
                            />
                            <span class="flex-1" />
                            <span
                                class="h-3 w-20 animate-pulse rounded bg-[var(--ui-well)]"
                            />
                        </div>
                    </div>

                    <p
                        v-else-if="
                            !collections || collections.customer_count === 0
                        "
                        class="border-t border-[var(--ui-line)] px-[1.125rem] py-5 text-[0.8125rem] text-[var(--ui-ink-3)]"
                    >
                        Nenhum cliente em dívida neste momento.
                    </p>

                    <table
                        v-else
                        class="w-full border-collapse text-[0.8125rem]"
                    >
                        <thead class="bg-[var(--ui-head)]">
                            <tr class="text-end">
                                <th
                                    scope="col"
                                    :class="[
                                        tableHead,
                                        'ps-[1.125rem] text-start',
                                    ]"
                                >
                                    Cliente
                                </th>
                                <th
                                    scope="col"
                                    :class="[tableHead, 'max-md:hidden']"
                                >
                                    A vencer
                                </th>
                                <th
                                    scope="col"
                                    :class="[tableHead, 'max-md:hidden']"
                                >
                                    1–30 dias
                                </th>
                                <th
                                    scope="col"
                                    :class="[tableHead, 'max-md:hidden']"
                                >
                                    31–60 dias
                                </th>
                                <th
                                    scope="col"
                                    :class="[tableHead, 'max-md:hidden']"
                                >
                                    Mais de 60
                                </th>
                                <th scope="col" :class="tableHead">Total</th>
                                <th
                                    v-if="canRemindCustomers"
                                    scope="col"
                                    :class="[tableHead, 'w-[4.75rem] pe-3']"
                                >
                                    <span class="sr-only">Lembrete</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="customer in collections.customers"
                                :key="
                                    customer.customer_public_id ?? customer.name
                                "
                                class="group/row text-end hover:bg-[var(--ui-wash)]"
                            >
                                <td
                                    class="h-12 border-b border-[var(--ui-line)] ps-[1.125rem] pe-3 text-start group-last/row:border-b-0"
                                >
                                    <component
                                        :is="
                                            customer.customer_public_id
                                                ? Link
                                                : 'span'
                                        "
                                        :href="
                                            customer.customer_public_id
                                                ? customerShow.url(
                                                      customer.customer_public_id,
                                                  )
                                                : undefined
                                        "
                                        class="flex min-w-0 items-center gap-2.5 rounded focus-ring"
                                    >
                                        <span
                                            class="grid size-6 shrink-0 place-items-center rounded-full bg-[var(--ui-well)] text-[0.59375rem] font-bold text-[var(--ui-ink-2)]"
                                            aria-hidden="true"
                                            >{{ initials(customer.name) }}</span
                                        >
                                        <span
                                            class="truncate font-[550] text-[var(--ui-ink)]"
                                            >{{ customer.name }}</span
                                        >
                                    </component>
                                </td>
                                <td
                                    :class="[
                                        tableCell,
                                        'text-[var(--ui-ink-2)] group-last/row:border-b-0 max-md:hidden',
                                    ]"
                                >
                                    {{
                                        bucket(customer, 'current')
                                            ? formatAmount(
                                                  bucket(customer, 'current'),
                                              )
                                            : '—'
                                    }}
                                </td>
                                <td
                                    :class="[
                                        tableCell,
                                        'group-last/row:border-b-0 max-md:hidden',
                                        bucket(customer, 'd1_30')
                                            ? 'text-[var(--ui-gold-ink)]'
                                            : 'text-[var(--ui-ink-3)]',
                                    ]"
                                >
                                    {{
                                        bucket(customer, 'd1_30')
                                            ? formatAmount(
                                                  bucket(customer, 'd1_30'),
                                              )
                                            : '—'
                                    }}
                                </td>
                                <td
                                    :class="[
                                        tableCell,
                                        'group-last/row:border-b-0 max-md:hidden',
                                        bucket(customer, 'd31_60')
                                            ? 'text-[var(--ui-warning)]'
                                            : 'text-[var(--ui-ink-3)]',
                                    ]"
                                >
                                    {{
                                        bucket(customer, 'd31_60')
                                            ? formatAmount(
                                                  bucket(customer, 'd31_60'),
                                              )
                                            : '—'
                                    }}
                                </td>
                                <td
                                    :class="[
                                        tableCell,
                                        'group-last/row:border-b-0 max-md:hidden',
                                        bucket(customer, 'd61_90', 'd90_plus')
                                            ? 'text-[var(--ui-critical)]'
                                            : 'text-[var(--ui-ink-3)]',
                                    ]"
                                >
                                    {{
                                        bucket(customer, 'd61_90', 'd90_plus')
                                            ? formatAmount(
                                                  bucket(
                                                      customer,
                                                      'd61_90',
                                                      'd90_plus',
                                                  ),
                                              )
                                            : '—'
                                    }}
                                </td>
                                <td
                                    :class="[
                                        tableCell,
                                        'font-semibold text-[var(--ui-ink)] group-last/row:border-b-0',
                                        canRemindCustomers
                                            ? ''
                                            : 'pe-[1.125rem]',
                                    ]"
                                >
                                    {{
                                        formatAmount(customer.outstanding_minor)
                                    }}
                                </td>
                                <td
                                    v-if="canRemindCustomers"
                                    class="border-b border-[var(--ui-line)] pe-3 group-last/row:border-b-0"
                                >
                                    <button
                                        v-if="
                                            customer.customer_public_id &&
                                            customer.overdue_minor > 0 &&
                                            !customer.recently_reminded
                                        "
                                        type="button"
                                        class="group/remind relative ms-auto grid size-7 place-items-center rounded-md text-[var(--ui-ink-3)] opacity-0 focus-ring group-hover/row:opacity-100 hover:bg-[var(--ui-well)] hover:text-[var(--ui-ink)] focus-visible:opacity-100 pointer-coarse:opacity-100"
                                        :aria-label="`Preparar lembrete para ${customer.name}`"
                                        @click="
                                            openReminder({
                                                public_id:
                                                    customer.customer_public_id,
                                                name: customer.name,
                                            })
                                        "
                                    >
                                        <Mail
                                            class="size-[0.9375rem]"
                                            aria-hidden="true"
                                        />
                                    </button>
                                    <span
                                        v-else-if="customer.recently_reminded"
                                        class="block text-end text-[0.6875rem] text-[var(--ui-ink-3)]"
                                        >Lembrado</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </details>
            <p
                v-if="showFigures"
                class="max-w-3xl text-xs/5 text-[var(--ui-ink-3)]"
            >
                Valores na moeda da empresa. Os saldos mostram o estado actual
                das facturas, não um saldo histórico nem o movimento bancário do
                período.
            </p>
        </div>
        <ReminderDialog
            :open="reminderOpen"
            :customer="reminderFor"
            @close="reminderOpen = false"
        />
    </AppLayout>
</template>
