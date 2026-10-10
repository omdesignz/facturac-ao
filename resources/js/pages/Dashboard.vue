<script setup lang="ts">
import { Deferred, Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    ChevronRight,
    CircleAlert,
    CircleGauge,
    FileClock,
    FilePen,
    HandCoins,
    Plus,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import DocumentLifecycle from '@/components/DocumentLifecycle.vue';
import type { LifecycleStep } from '@/components/DocumentLifecycle.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { agingBucketColours, agingShare } from '@/lib/aging';
import { onboarding } from '@/routes';
import { show as agtConnection } from '@/routes/agt/connection';
import { index as agtSubmissions } from '@/routes/agt/submissions';
import { show as customerShow } from '@/routes/customers';
import { index as debtsIndex } from '@/routes/debts';
import {
    index as documentsIndex,
    print as documentPrint,
} from '@/routes/documents';
import { index as importIndex } from '@/routes/imports';
import { create as invoiceCreate } from '@/routes/invoices';
import { security } from '@/routes/settings';

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

interface FocusDocument {
    reason: 'invalid' | 'contingency' | 'overdue' | 'latest';
    public_id: string;
    document_no: string | null;
    type_label: string;
    status: string;
    status_label: string;
    customer_name: string;
    customer_tax_identification_number: string;
    establishment_name: string | null;
    issuer_name: string | null;
    lines_count: number;
    gross_total_minor: number;
    tax_payable_minor: number;
    outstanding_minor: number;
    currency_code: string;
    document_date: string;
    due_date: string | null;
    days_past_due: number;
    agt_message: string | null;
    agt_operational: { observed_at: string | null };
    agt_error_codes: string[];
    steps: LifecycleStep[];
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
    }[];
    customer_count: number;
    totals: { key: string; label: string; total_minor: number }[];
    outstanding_minor: number;
}

const props = defineProps<{
    companyReadiness: CompanyReadiness;
    agtReadiness: AgtReadiness;
    now: string;
    currencyCode: string;
    kpis: Kpis | null;
    focus: FocusDocument | null;
    review: Review | null;
    collections?: Collections | null;
}>();

const page = usePage();
const workspace = computed(() => page.props.currentWorkspace);
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
    const time = new Intl.DateTimeFormat('pt-AO', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: TIMEZONE,
    }).format(nowDate.value);

    return `${day} · ${time}`;
});

const greeting = computed(() => {
    const hour = Number(
        new Intl.DateTimeFormat('en-GB', {
            hour: 'numeric',
            hour12: false,
            timeZone: TIMEZONE,
        }).format(nowDate.value),
    );
    const salutation =
        hour < 12 ? 'Bom dia' : hour < 19 ? 'Boa tarde' : 'Boa noite';
    const firstName = user.value?.name?.trim().split(/\s+/)[0];

    return firstName ? `${salutation}, ${firstName}` : salutation;
});

const companyName = computed(
    () =>
        workspace.value?.legal_entity?.trade_name ??
        workspace.value?.legal_entity?.legal_name ??
        workspace.value?.name ??
        'a sua empresa',
);

/**
 * The setup steps, shown in place of the focus document until the company can
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

const headline = computed(() => {
    if (setupIncomplete.value) {
        const left = readiness.value.length - readinessDone.value;

        return `Faltam ${left} ${left === 1 ? 'passo' : 'passos'} para começar a facturar.`;
    }

    if (props.review && props.review.attention_count > 0) {
        return plural(
            props.review.attention_count,
            'documento precisa da sua atenção.',
            'documentos precisam da sua atenção.',
        );
    }

    if (props.review && props.review.overdue_count > 0) {
        return `Tem ${plural(props.review.overdue_count, 'factura vencida', 'facturas vencidas')} por cobrar.`;
    }

    return `Nada precisa da sua atenção hoje na ${companyName.value}.`;
});

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** Whole kwanzas with the locale's grouping; the centimos live on the document. */
function formatAmount(minor: number): string {
    return new Intl.NumberFormat('pt-AO', {
        maximumFractionDigits: 0,
    }).format(Math.round(minor / 100));
}

function formatMoney(minor: number, currency = props.currencyCode): string {
    return new Intl.NumberFormat('pt-AO', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100);
}

function currencyLabel(currency = props.currencyCode): string {
    return currency === 'AOA' ? 'Kz' : currency;
}

function formatObservation(value: string): string {
    return new Intl.DateTimeFormat('pt-AO', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: TIMEZONE,
    }).format(new Date(value));
}

function formatTime(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-AO', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: TIMEZONE,
    }).format(new Date(value));
}

/**
 * A date-only value is a day on the calendar, not a moment: it is read as
 * midnight UTC and printed in UTC so no browser timezone can move the day.
 */
function formatShortDate(value: string | null): string {
    const day = value?.slice(0, 10) ?? '';

    if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-AO', {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    }).format(new Date(`${day}T00:00:00Z`));
}

const lastWeekday = computed(() => {
    const lastWeek = new Date(nowDate.value.getTime() - 7 * 86_400_000);

    return new Intl.DateTimeFormat('pt-AO', {
        weekday: 'long',
        timeZone: TIMEZONE,
    }).format(lastWeek);
});

/** The day's change, or null when last week had nothing to compare with. */
const billedChange = computed(() => {
    if (!props.kpis || props.kpis.billed_last_week_minor <= 0) {
        return null;
    }

    return Math.round(
        ((props.kpis.billed_today_minor - props.kpis.billed_last_week_minor) /
            props.kpis.billed_last_week_minor) *
            100,
    );
});

/* -------------------------------------------------------------- focus card */

const focusChip = computed(() => {
    switch (props.focus?.reason) {
        case 'invalid':
            return 'Requer atenção · inválida na AGT';
        case 'contingency':
            return 'Em contingência · por comunicar';
        case 'overdue':
            return 'Factura vencida · por cobrar';
        default:
            return 'Último documento · nada precisa de si';
    }
});

function statusPillClasses(focus: FocusDocument): string {
    if (focus.status === 'invalid') {
        return 'bg-rose-50 text-rose-700 dark:bg-rose-400/10 dark:text-rose-300';
    }

    if (focus.status === 'valid') {
        return 'bg-lime-300 text-lime-950';
    }

    if (focus.status === 'contingency') {
        return 'bg-orange-50 text-orange-700 dark:bg-orange-400/10 dark:text-orange-300';
    }

    return 'bg-accent-400 text-brand-950';
}

/* ------------------------------------------------------------------ review */

interface ReviewCause {
    count: number;
    label: string;
}

interface ReviewAction {
    label: string;
    hint: string;
    href: string;
    icon: Component;
}

const reviewCauses = computed<ReviewCause[]>(() => {
    if (!props.review) {
        return [];
    }

    return [
        {
            count: props.review.attention_count,
            label: 'Documentos inválidos ou em contingência',
        },
        {
            count: props.review.overdue_count,
            label: 'Facturas vencidas por cobrar',
        },
        {
            count: props.review.in_flight_count,
            label: 'Documentos ainda em validação na AGT',
        },
        {
            count: props.review.drafts_open,
            label: 'Rascunhos por emitir',
        },
    ].filter((cause) => cause.count > 0);
});

const largestCause = computed(() =>
    Math.max(1, ...reviewCauses.value.map((cause) => cause.count)),
);

const reviewActions = computed<ReviewAction[]>(() => {
    if (!props.review) {
        return [];
    }

    const actions: ReviewAction[] = [];

    if (props.review.attention_count > 0) {
        actions.push({
            label: 'Rever documentos com problemas',
            hint: plural(
                props.review.attention_count,
                'documento',
                'documentos',
            ),
            href: documentsIndex.url({ query: { status: 'attention' } }),
            icon: CircleAlert,
        });
    }

    if (props.review.overdue_count > 0) {
        actions.push({
            label: 'Cobrar facturas vencidas',
            hint: props.kpis
                ? `${formatAmount(props.kpis.overdue_minor)} ${currencyLabel()}`
                : '',
            href: debtsIndex.url(),
            icon: HandCoins,
        });
    }

    if (props.review.in_flight_count > 0) {
        actions.push({
            label: 'Acompanhar entregas à AGT',
            hint: 'Monitor AGT',
            href: agtSubmissions.url(),
            icon: FileClock,
        });
    }

    if (props.review.drafts_open > 0) {
        actions.push({
            label: 'Concluir rascunhos',
            hint: plural(props.review.drafts_open, 'rascunho', 'rascunhos'),
            href: documentsIndex.url({ query: { status: 'draft' } }),
            icon: FilePen,
        });
    }

    return actions.slice(0, 3);
});

const reviewStatement = computed(() => {
    const kpis = props.kpis;

    if (!props.review || !kpis) {
        return '';
    }

    if (reviewCauses.value.length === 0) {
        return kpis.issued_today > 0
            ? 'Os documentos de hoje estão validados e não há facturas vencidas. Está tudo em dia.'
            : 'Não há documentos por corrigir nem facturas vencidas. Está tudo em dia.';
    }

    const parts: string[] = [];

    if (props.review.attention_count > 0) {
        parts.push(
            plural(
                props.review.attention_count,
                'documento precisa de correcção',
                'documentos precisam de correcção',
            ),
        );
    }

    if (props.review.overdue_count > 0) {
        parts.push(
            plural(
                props.review.overdue_count,
                'factura está vencida',
                'facturas estão vencidas',
            ),
        );
    }

    if (parts.length === 0 && props.review.in_flight_count > 0) {
        parts.push(
            plural(
                props.review.in_flight_count,
                'documento aguarda a AGT',
                'documentos aguardam a AGT',
            ),
        );
    }

    if (parts.length === 0) {
        parts.push(
            plural(
                props.review.drafts_open,
                'rascunho espera',
                'rascunhos esperam',
            ),
        );
    }

    const sentence = parts.join(' e ');

    return `${sentence.charAt(0).toUpperCase()}${sentence.slice(1)}.`;
});

/* ------------------------------------------------------------- collections */
</script>

<template>
    <AppLayout>
        <Head title="Painel" />
        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-[100rem]">
                <!-- -------------------------------------------------- header -->
                <header
                    class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <p
                            class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            {{ dateLine }}
                        </p>
                        <h1
                            class="mt-2.5 text-[2.125rem] leading-[1.08] font-normal tracking-[-0.028em] text-zinc-950 dark:text-white"
                        >
                            {{ greeting }}
                        </h1>
                        <p
                            class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            {{ headline }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Link
                            :href="agtConnection.url()"
                            class="inline-flex h-10 items-center gap-2 rounded-full bg-zinc-900/[0.045] pr-4 pl-1.5 text-sm font-medium text-zinc-900 focus-ring transition hover:bg-zinc-900/[0.07] dark:bg-white/[0.06] dark:text-white dark:hover:bg-white/10"
                        >
                            <span
                                class="grid size-7 place-items-center rounded-full bg-brand-950 dark:bg-zinc-100"
                                aria-hidden="true"
                            >
                                <span
                                    class="size-2.5 rounded-full"
                                    :class="
                                        agtReadiness.verified
                                            ? 'bg-accent-400'
                                            : 'bg-zinc-500'
                                    "
                                />
                            </span>
                            {{
                                agtReadiness.verified
                                    ? 'AGT ligada'
                                    : 'AGT por verificar'
                            }}
                            <span
                                v-if="review && review.attention_count > 0"
                                class="text-accent-700 dark:text-accent-300"
                                >· {{ review.attention_count }} por rever</span
                            >
                        </Link>
                        <Link
                            :href="invoiceCreate.url()"
                            class="inline-flex h-10 items-center gap-2 rounded-full bg-accent-400 px-[1.125rem] text-sm font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] focus-ring transition hover:bg-accent-300"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Nova factura
                        </Link>
                    </div>
                </header>

                <!-- ---------------------------------------------------- KPIs -->
                <dl
                    v-if="kpis"
                    class="mt-8 grid grid-cols-1 gap-y-6 min-[30rem]:grid-cols-2 md:grid-cols-3 xl:grid-cols-5"
                >
                    <div class="@container min-w-0 pr-5">
                        <dt
                            class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Facturado hoje
                        </dt>
                        <dd class="mt-3 flex flex-wrap items-center gap-2.5">
                            <span
                                class="min-w-0 numeric text-[clamp(1.5rem,13cqi,2rem)] leading-none font-normal tracking-[-0.035em] [overflow-wrap:anywhere] text-zinc-950 dark:text-white"
                                >{{ formatAmount(kpis.billed_today_minor)
                                }}<span
                                    class="ml-1 text-sm tracking-normal text-zinc-500 dark:text-zinc-400"
                                    >{{ currencyLabel() }}</span
                                ></span
                            >
                            <span
                                v-if="billedChange !== null"
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="
                                    billedChange >= 0
                                        ? 'bg-lime-300 text-lime-950'
                                        : 'bg-rose-50 text-rose-700 dark:bg-rose-400/10 dark:text-rose-300'
                                "
                                >{{ billedChange >= 0 ? '+' : ''
                                }}{{ billedChange }}%</span
                            >
                        </dd>
                        <dd
                            class="mt-2.5 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                kpis.billed_last_week_minor > 0
                                    ? `vs ${formatAmount(kpis.billed_last_week_minor)} ${currencyLabel()} na ${lastWeekday} passada`
                                    : `nada facturado na ${lastWeekday} passada`
                            }}
                        </dd>
                    </div>

                    <div
                        class="@container min-w-0 pr-5 xl:border-l xl:border-zinc-900/10 xl:pl-5 dark:xl:border-white/10"
                    >
                        <dt
                            class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Emitidos hoje
                        </dt>
                        <dd
                            class="mt-3 numeric text-[clamp(1.5rem,13cqi,2rem)] leading-none font-normal tracking-[-0.035em] text-zinc-950 dark:text-white"
                        >
                            {{ kpis.issued_today }}
                        </dd>
                        <dd
                            class="mt-2.5 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                kpis.credit_notes_today > 0
                                    ? plural(
                                          kpis.credit_notes_today,
                                          'nota de crédito',
                                          'notas de crédito',
                                      )
                                    : 'nenhuma nota de crédito'
                            }}
                        </dd>
                    </div>

                    <div
                        class="@container min-w-0 pr-5 xl:border-l xl:border-zinc-900/10 xl:pl-5 dark:xl:border-white/10"
                    >
                        <dt
                            class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Validados pela AGT
                        </dt>
                        <dd class="mt-3 flex flex-wrap items-center gap-2.5">
                            <span
                                class="numeric text-[clamp(1.5rem,13cqi,2rem)] leading-none font-normal tracking-[-0.035em] text-zinc-950 dark:text-white"
                                >{{ kpis.valid_today }}</span
                            >
                            <span
                                v-if="kpis.pending_today > 0"
                                class="rounded-full bg-accent-400 px-2 py-0.5 text-xs font-semibold text-brand-950"
                                >{{ kpis.pending_today }} na fila</span
                            >
                        </dd>
                        <dd
                            class="mt-2.5 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                kpis.invalid_today > 0
                                    ? plural(
                                          kpis.invalid_today,
                                          'inválido hoje',
                                          'inválidos hoje',
                                      )
                                    : 'nenhum inválido hoje'
                            }}
                        </dd>
                    </div>

                    <!--
                        The two cells below are links. The anchor sits in the
                        term and stretches over the whole cell, so the group
                        stays a valid dt/dd pair and the cell shows hover,
                        press and focus as one piece.
                    -->
                    <div
                        class="@container min-w-0 pr-5 xl:border-l xl:border-zinc-900/10 xl:pl-5 dark:xl:border-white/10"
                    >
                        <div
                            class="relative -m-2 rounded-lg p-2 hover:bg-zinc-900/[0.04] active:bg-zinc-900/[0.07] has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-brand-600 dark:hover:bg-white/[0.06] dark:active:bg-white/10 dark:has-[a:focus-visible]:outline-brand-300"
                        >
                            <dt
                                class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                <Link
                                    :href="
                                        documentsIndex.url({
                                            query: { status: 'attention' },
                                        })
                                    "
                                    class="outline-2 outline-transparent after:absolute after:inset-0 after:rounded-lg after:content-['']"
                                    >Requer atenção</Link
                                >
                            </dt>
                            <dd
                                class="mt-3 flex flex-wrap items-center gap-2.5"
                            >
                                <span
                                    class="numeric text-[clamp(1.5rem,13cqi,2rem)] leading-none font-normal tracking-[-0.035em] text-zinc-950 dark:text-white"
                                    >{{ kpis.attention_count }}</span
                                >
                                <span
                                    v-if="kpis.attention_count > 0"
                                    class="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-400/10 dark:text-rose-300"
                                    >a corrigir</span
                                >
                            </dd>
                            <dd
                                class="mt-2.5 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                            >
                                inválidos ou em contingência
                            </dd>
                        </div>
                    </div>

                    <div
                        class="@container min-w-0 pr-5 xl:border-l xl:border-zinc-900/10 xl:pl-5 dark:xl:border-white/10"
                    >
                        <div
                            class="relative -m-2 rounded-lg p-2 hover:bg-zinc-900/[0.04] active:bg-zinc-900/[0.07] has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-brand-600 dark:hover:bg-white/[0.06] dark:active:bg-white/10 dark:has-[a:focus-visible]:outline-brand-300"
                        >
                            <dt
                                class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                <Link
                                    :href="debtsIndex.url()"
                                    class="outline-2 outline-transparent after:absolute after:inset-0 after:rounded-lg after:content-['']"
                                    >Por receber</Link
                                >
                            </dt>
                            <dd
                                class="mt-3 flex flex-wrap items-center gap-2.5"
                            >
                                <span
                                    class="min-w-0 numeric text-[clamp(1.5rem,13cqi,2rem)] leading-none font-normal tracking-[-0.035em] [overflow-wrap:anywhere] text-zinc-950 dark:text-white"
                                    >{{ formatAmount(kpis.outstanding_minor)
                                    }}<span
                                        class="ml-1 text-sm tracking-normal text-zinc-500 dark:text-zinc-400"
                                        >{{ currencyLabel() }}</span
                                    ></span
                                >
                                <span
                                    v-if="kpis.overdue_count > 0"
                                    class="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-400/10 dark:text-rose-300"
                                    >{{
                                        plural(
                                            kpis.overdue_count,
                                            'vencida',
                                            'vencidas',
                                        )
                                    }}</span
                                >
                            </dd>
                            <dd
                                class="mt-2.5 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    kpis.overdue_minor > 0
                                        ? `${formatAmount(kpis.overdue_minor)} ${currencyLabel()} já vencidos`
                                        : 'nada vencido'
                                }}
                            </dd>
                        </div>
                    </div>
                </dl>

                <p
                    class="mt-8 max-w-3xl text-xs/5 text-zinc-500 dark:text-zinc-400"
                >
                    Valores limitados à moeda da empresa. Saldos e recebimentos
                    representam o estado actual das facturas seleccionadas, não
                    um saldo histórico nem o movimento bancário do período.
                </p>

                <!-- --------------------------------------- focus and review -->
                <div
                    class="mt-8 grid grid-cols-[minmax(0,1fr)] gap-5 xl:grid-cols-[minmax(0,1fr)_27.5rem]"
                >
                    <!-- Setup: the checklist leads until the company can invoice. -->
                    <section
                        v-if="setupIncomplete"
                        class="rounded-3xl bg-zinc-900/[0.04] p-6 dark:bg-white/[0.04]"
                        aria-labelledby="setup-title"
                    >
                        <p
                            class="text-[0.6875rem] font-semibold tracking-[0.07em] text-accent-700 uppercase dark:text-accent-300"
                        >
                            Comece por aqui
                        </p>
                        <h2
                            id="setup-title"
                            class="mt-3 text-[1.4375rem] font-normal tracking-[-0.02em] text-zinc-950 dark:text-white"
                        >
                            {{ readinessDone }} de {{ readiness.length }} passos
                            concluídos
                        </h2>
                        <div
                            class="mt-5 h-1.5 overflow-hidden rounded-full bg-zinc-900/[0.07] dark:bg-white/10"
                            role="progressbar"
                            aria-label="Passos concluídos"
                            aria-valuemin="0"
                            :aria-valuemax="readiness.length"
                            :aria-valuenow="readinessDone"
                        >
                            <div
                                class="h-full rounded-full bg-brand-950 transition-[width] duration-200 ease-out dark:bg-zinc-100"
                                :style="{
                                    width: `${(readinessDone / readiness.length) * 100}%`,
                                }"
                            />
                        </div>
                        <ul role="list" class="mt-5 grid gap-2">
                            <li v-for="item in readiness" :key="item.label">
                                <component
                                    :is="item.href && !item.done ? Link : 'div'"
                                    :href="
                                        item.href && !item.done
                                            ? item.href
                                            : undefined
                                    "
                                    class="flex items-center gap-3 rounded-2xl bg-white p-4 shadow-[0_1px_2px_rgb(23_23_22/0.04),0_6px_16px_-8px_rgb(23_23_22/0.1)] dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
                                    :class="
                                        item.href && !item.done
                                            ? 'focus-ring transition hover:bg-zinc-50 dark:hover:bg-zinc-800'
                                            : ''
                                    "
                                >
                                    <BadgeCheck
                                        v-if="item.done"
                                        class="size-5 shrink-0 text-lime-600 dark:text-lime-400"
                                        aria-hidden="true"
                                    />
                                    <CircleGauge
                                        v-else
                                        class="size-5 shrink-0 text-accent-600 dark:text-accent-400"
                                        aria-hidden="true"
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block text-sm font-semibold text-zinc-950 dark:text-white"
                                            ><span class="sr-only">{{
                                                item.done
                                                    ? 'Concluído: '
                                                    : 'Por fazer: '
                                            }}</span
                                            >{{ item.label }}</span
                                        >
                                        <span
                                            class="mt-0.5 block text-xs/5 text-zinc-500 dark:text-zinc-400"
                                            >{{ item.description }}</span
                                        >
                                    </span>
                                    <ChevronRight
                                        v-if="item.href && !item.done"
                                        class="size-4 shrink-0 text-zinc-400"
                                        aria-hidden="true"
                                    />
                                </component>
                            </li>
                        </ul>
                    </section>

                    <!-- Configured, but nothing issued yet. -->
                    <section
                        v-else-if="!focus"
                        class="grid place-items-center rounded-3xl bg-zinc-900/[0.04] p-10 text-center dark:bg-white/[0.04]"
                    >
                        <div class="max-w-md">
                            <h2
                                class="text-[1.4375rem] font-normal tracking-[-0.02em] text-zinc-950 dark:text-white"
                            >
                                Ainda não emitiu nenhum documento
                            </h2>
                            <p
                                class="mt-2 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                A primeira factura começa como rascunho. Confira
                                os totais e só depois emita. Se já tem clientes
                                e artigos noutro programa, traga-os primeiro.
                            </p>
                            <div
                                class="mt-6 flex flex-wrap justify-center gap-2.5"
                            >
                                <Link
                                    :href="invoiceCreate.url()"
                                    class="inline-flex h-10 items-center gap-2 rounded-full bg-accent-400 px-4 text-sm font-semibold text-brand-950 focus-ring transition hover:bg-accent-300"
                                >
                                    <Plus class="size-4" aria-hidden="true" />
                                    Criar factura
                                </Link>
                                <Link
                                    :href="importIndex.url()"
                                    class="inline-flex h-10 items-center rounded-full bg-white px-4 text-sm font-semibold text-zinc-900 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50 dark:bg-zinc-900 dark:text-white dark:ring-white/10"
                                >
                                    Importar dados
                                </Link>
                            </div>
                        </div>
                    </section>

                    <!-- The one document the page leads with. -->
                    <section
                        v-else
                        class="flex flex-col rounded-3xl bg-zinc-900/[0.04] p-6 dark:bg-white/[0.04]"
                        aria-labelledby="focus-title"
                    >
                        <div
                            class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between"
                        >
                            <div class="min-w-0">
                                <span
                                    class="inline-flex h-7 items-center gap-2 rounded-full bg-white pr-3 pl-1 shadow-[0_1px_2px_rgb(23_23_22/0.04),0_6px_16px_-8px_rgb(23_23_22/0.12)] dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
                                >
                                    <span
                                        class="grid size-5 place-items-center rounded-full bg-brand-950 dark:bg-zinc-100"
                                        aria-hidden="true"
                                    >
                                        <span
                                            class="size-[0.4375rem] rounded-full bg-accent-400"
                                        />
                                    </span>
                                    <span
                                        class="text-[0.6875rem] font-semibold tracking-[0.07em] text-accent-700 uppercase dark:text-accent-300"
                                        >{{ focusChip }}</span
                                    >
                                </span>
                                <h2
                                    id="focus-title"
                                    class="mt-4 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[1.4375rem] font-normal tracking-[-0.02em] text-zinc-950 dark:text-white"
                                >
                                    <span class="font-mono text-[1.25rem]">{{
                                        focus.document_no ?? 'Sem número'
                                    }}</span>
                                    <ArrowRight
                                        class="size-5 text-zinc-400"
                                        aria-label="para"
                                    />
                                    <span class="min-w-0 truncate">{{
                                        focus.customer_name
                                    }}</span>
                                    <span
                                        class="rounded-full px-2.5 py-1 text-xs font-semibold tracking-normal"
                                        :class="statusPillClasses(focus)"
                                        >{{ focus.status_label }}</span
                                    >
                                </h2>
                                <p
                                    class="mt-1.5 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ focus.type_label }}
                                    <template v-if="focus.establishment_name"
                                        >·
                                        {{ focus.establishment_name }}</template
                                    >
                                    <template v-if="focus.lines_count > 0"
                                        >·
                                        {{
                                            plural(
                                                focus.lines_count,
                                                'linha',
                                                'linhas',
                                            )
                                        }}</template
                                    >
                                    <template v-if="focus.issuer_name"
                                        >· emitida por
                                        {{ focus.issuer_name }}</template
                                    >
                                </p>
                            </div>

                            <dl
                                class="flex flex-wrap gap-x-9 gap-y-5 lg:shrink-0 lg:text-right"
                            >
                                <div>
                                    <dt
                                        class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                    >
                                        Total
                                    </dt>
                                    <dd
                                        class="mt-2.5 numeric text-[1.75rem] leading-none tracking-[-0.03em] text-zinc-950 dark:text-white"
                                    >
                                        {{
                                            formatAmount(
                                                focus.gross_total_minor,
                                            )
                                        }}<span
                                            class="ml-1 text-[0.8125rem] tracking-normal text-zinc-500 dark:text-zinc-400"
                                            >{{
                                                currencyLabel(
                                                    focus.currency_code,
                                                )
                                            }}</span
                                        >
                                    </dd>
                                    <dd
                                        class="mt-2 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                                    >
                                        IVA
                                        {{
                                            formatAmount(
                                                focus.tax_payable_minor,
                                            )
                                        }}
                                        {{ currencyLabel(focus.currency_code) }}
                                    </dd>
                                </div>
                                <div v-if="focus.outstanding_minor > 0">
                                    <dt
                                        class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                    >
                                        Por receber
                                    </dt>
                                    <dd
                                        class="mt-2.5 numeric text-[1.75rem] leading-none tracking-[-0.03em]"
                                        :class="
                                            focus.days_past_due > 0
                                                ? 'text-rose-600 dark:text-rose-400'
                                                : 'text-zinc-950 dark:text-white'
                                        "
                                    >
                                        {{
                                            formatAmount(
                                                focus.outstanding_minor,
                                            )
                                        }}
                                    </dd>
                                    <dd
                                        class="mt-2 text-[0.8125rem] font-medium"
                                        :class="
                                            focus.days_past_due > 0
                                                ? 'text-rose-600 dark:text-rose-400'
                                                : 'text-zinc-500 dark:text-zinc-400'
                                        "
                                    >
                                        {{
                                            focus.days_past_due > 0
                                                ? `vencida há ${plural(focus.days_past_due, 'dia', 'dias')}`
                                                : `vence a ${formatShortDate(focus.due_date)}`
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <!-- The facts someone checks first when a document is questioned. -->
                        <dl class="mt-6 flex flex-wrap gap-2">
                            <div
                                class="rounded-[0.875rem] bg-white px-3.5 py-2.5 shadow-[0_1px_2px_rgb(23_23_22/0.04),0_6px_16px_-8px_rgb(23_23_22/0.12)] dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
                            >
                                <dt
                                    class="text-[0.625rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    NIF do adquirente
                                </dt>
                                <dd
                                    class="mt-1 font-mono text-[0.8125rem] font-medium text-zinc-950 dark:text-white"
                                >
                                    {{
                                        focus.customer_tax_identification_number
                                    }}
                                </dd>
                            </div>
                            <div
                                class="rounded-[0.875rem] bg-white px-3.5 py-2.5 shadow-[0_1px_2px_rgb(23_23_22/0.04),0_6px_16px_-8px_rgb(23_23_22/0.12)] dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
                            >
                                <dt
                                    class="text-[0.625rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Data do documento
                                </dt>
                                <dd
                                    class="mt-1 text-[0.8125rem] font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{ formatShortDate(focus.document_date) }}
                                </dd>
                            </div>
                            <div
                                v-if="focus.due_date"
                                class="rounded-[0.875rem] bg-white px-3.5 py-2.5 shadow-[0_1px_2px_rgb(23_23_22/0.04),0_6px_16px_-8px_rgb(23_23_22/0.12)] dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
                            >
                                <dt
                                    class="text-[0.625rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Vencimento
                                </dt>
                                <dd
                                    class="mt-1 text-[0.8125rem] font-semibold"
                                    :class="
                                        focus.days_past_due > 0
                                            ? 'text-rose-600 dark:text-rose-400'
                                            : 'text-zinc-950 dark:text-white'
                                    "
                                >
                                    {{ formatShortDate(focus.due_date) }}
                                </dd>
                            </div>
                        </dl>

                        <!--
                            The AGT's own words, verbatim, under a plain
                            explanation of what they mean for the reader.
                        -->
                        <div
                            v-if="focus.agt_message"
                            class="mt-6 rounded-2xl bg-white p-4 shadow-[0_1px_2px_rgb(23_23_22/0.04),0_6px_16px_-8px_rgb(23_23_22/0.12)] dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
                        >
                            <p
                                class="text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                Observação AGT
                            </p>
                            <p
                                class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-300"
                            >
                                {{ focus.agt_message }}
                                <span v-if="focus.agt_operational.observed_at">
                                    Observação:
                                    {{
                                        formatObservation(
                                            focus.agt_operational.observed_at,
                                        )
                                    }}.
                                </span>
                            </p>
                            <p
                                v-if="focus.agt_error_codes.length > 0"
                                class="mt-2 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                {{ focus.agt_error_codes.join(' · ') }}
                            </p>
                        </div>

                        <!-- Lifecycle track: every stage the record has really reached. -->
                        <DocumentLifecycle
                            class="mt-auto min-w-0 pt-10"
                            :steps="focus.steps"
                        />
                        <div class="mt-4 flex flex-wrap gap-2.5">
                            <a
                                :href="documentPrint.url(focus.public_id)"
                                class="inline-flex h-9 items-center rounded-full bg-brand-950 px-4 text-[0.8125rem] font-semibold text-white focus-ring transition hover:bg-brand-800 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
                            >
                                Ver documento
                            </a>
                            <Link
                                v-if="
                                    focus.reason === 'invalid' ||
                                    focus.reason === 'contingency'
                                "
                                :href="agtSubmissions.url()"
                                class="inline-flex h-9 items-center rounded-full bg-white px-4 text-[0.8125rem] font-semibold text-zinc-900 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50 dark:bg-zinc-900 dark:text-white dark:ring-white/10"
                            >
                                Abrir Monitor AGT
                            </Link>
                            <Link
                                v-if="focus.reason === 'overdue'"
                                :href="debtsIndex.url()"
                                class="inline-flex h-9 items-center rounded-full bg-white px-4 text-[0.8125rem] font-semibold text-zinc-900 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50 dark:bg-zinc-900 dark:text-white dark:ring-white/10"
                            >
                                Ver dívidas
                            </Link>
                        </div>
                    </section>

                    <!-- -------------------------------------------- review -->
                    <aside
                        class="flex flex-col rounded-3xl bg-accent-50 p-6 ring-1 ring-accent-200/70 ring-inset dark:bg-accent-400/[0.06] dark:ring-accent-400/15"
                        aria-labelledby="review-title"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="grid size-9 place-items-center rounded-full bg-brand-950 dark:bg-zinc-100"
                                aria-hidden="true"
                            >
                                <span
                                    class="size-3 rounded-full bg-accent-400"
                                />
                            </span>
                            <div>
                                <h2
                                    id="review-title"
                                    class="text-[0.9375rem] font-medium text-zinc-950 dark:text-white"
                                >
                                    {{
                                        review
                                            ? 'Revisão do dia'
                                            : 'A facturar como'
                                    }}
                                </h2>
                                <p
                                    class="mt-0.5 text-[0.625rem] font-semibold tracking-[0.07em] text-accent-700 uppercase dark:text-accent-300"
                                >
                                    {{
                                        review
                                            ? `Actualizado às ${formatTime(now)}`
                                            : (workspace?.role_label ??
                                              'O seu acesso')
                                    }}
                                </p>
                            </div>
                        </div>

                        <template v-if="review">
                            <p
                                class="mt-5 text-[1.3125rem] leading-[1.3] tracking-[-0.02em] text-balance text-zinc-950 dark:text-white"
                            >
                                {{ reviewStatement }}
                            </p>

                            <template v-if="reviewCauses.length > 0">
                                <p
                                    class="mt-5 text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    O que encontrámos
                                </p>
                                <ul role="list" class="mt-1">
                                    <li
                                        v-for="cause in reviewCauses"
                                        :key="cause.label"
                                        class="grid min-h-8 grid-cols-[1.75rem_minmax(0,1fr)] items-center gap-2 py-1 text-[0.8125rem] text-zinc-800 sm:grid-cols-[1.75rem_minmax(0,1fr)_5.75rem] dark:text-zinc-200"
                                    >
                                        <span
                                            class="numeric font-semibold text-accent-700 dark:text-accent-300"
                                            >{{
                                                String(cause.count).padStart(
                                                    2,
                                                    '0',
                                                )
                                            }}</span
                                        >
                                        <span>{{ cause.label }}</span>
                                        <span
                                            class="relative hidden h-[3px] rounded-full bg-accent-700/15 sm:block dark:bg-accent-300/15"
                                            aria-hidden="true"
                                        >
                                            <span
                                                class="absolute inset-y-0 left-0 rounded-full bg-accent-400"
                                                :style="{
                                                    width: `${(cause.count / largestCause) * 100}%`,
                                                }"
                                            />
                                        </span>
                                    </li>
                                </ul>

                                <p
                                    class="mt-5 text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    O que fazer a seguir
                                </p>
                                <ul role="list" class="mt-2 grid gap-1.5">
                                    <li
                                        v-for="action in reviewActions"
                                        :key="action.label"
                                    >
                                        <Link
                                            :href="action.href"
                                            class="group flex min-h-11 items-center gap-2.5 rounded-[0.875rem] bg-white px-3.5 py-2 text-[0.8125rem] font-semibold text-zinc-950 shadow-[0_1px_2px_rgb(23_23_22/0.04),0_6px_16px_-8px_rgb(23_23_22/0.12)] focus-ring transition hover:bg-zinc-50 dark:bg-zinc-900 dark:text-white dark:ring-1 dark:ring-white/10 dark:hover:bg-zinc-800"
                                        >
                                            <component
                                                :is="action.icon"
                                                class="size-4 shrink-0 text-accent-700 dark:text-accent-300"
                                                aria-hidden="true"
                                            />
                                            <span class="min-w-0">{{
                                                action.label
                                            }}</span>
                                            <span
                                                class="shrink-0 font-normal text-zinc-500 dark:text-zinc-400"
                                                >{{ action.hint }}</span
                                            >
                                            <ChevronRight
                                                class="ml-auto size-4 shrink-0 text-zinc-400 transition group-hover:translate-x-0.5 motion-reduce:transition-none"
                                                aria-hidden="true"
                                            />
                                        </Link>
                                    </li>
                                </ul>
                            </template>

                            <div class="mt-auto pt-6">
                                <Link
                                    :href="documentsIndex.url()"
                                    class="inline-flex h-10 items-center gap-2 rounded-full bg-brand-950 px-4 text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
                                >
                                    Ver documentos
                                    <ArrowRight
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </div>
                        </template>

                        <!-- Before the company exists there is nothing to review yet. -->
                        <dl v-else class="mt-6 grid gap-4 text-sm">
                            <div>
                                <dt
                                    class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Espaço
                                </dt>
                                <dd
                                    class="mt-1 font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{ workspace?.name }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Empresa
                                </dt>
                                <dd
                                    class="mt-1 font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{
                                        workspace?.legal_entity?.trade_name ??
                                        workspace?.legal_entity?.legal_name ??
                                        'Por configurar'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    NIF protegido
                                </dt>
                                <dd
                                    class="mt-1 font-mono font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{
                                        workspace?.legal_entity?.masked_nif ??
                                        '—'
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </aside>
                </div>

                <!-- --------------------------------------------- collections -->
                <Deferred v-if="kpis" data="collections">
                    <template #fallback>
                        <div
                            class="mt-5 grid delayed-show grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[22.5rem_minmax(0,1fr)]"
                            aria-hidden="true"
                        >
                            <div
                                class="min-h-[26rem] animate-pulse rounded-3xl bg-zinc-900/[0.04] motion-reduce:animate-none dark:bg-white/[0.04]"
                            />
                            <div
                                class="min-h-[26rem] animate-pulse rounded-3xl bg-zinc-900/[0.04] motion-reduce:animate-none dark:bg-white/[0.04]"
                            />
                        </div>
                    </template>

                    <div
                        v-if="collections && collections.customer_count > 0"
                        class="mt-5 grid animate-[fade-in_150ms_var(--ease-out)_both] grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[22.5rem_minmax(0,1fr)]"
                    >
                        <section
                            class="rounded-3xl bg-zinc-900/[0.04] p-6 dark:bg-white/[0.04]"
                            aria-labelledby="aging-title"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h2
                                        id="aging-title"
                                        class="text-base font-medium text-zinc-950 dark:text-white"
                                    >
                                        Por receber
                                    </h2>
                                    <p
                                        class="mt-1 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                                    >
                                        {{
                                            plural(
                                                collections.customer_count,
                                                'cliente em dívida',
                                                'clientes em dívida',
                                            )
                                        }}
                                    </p>
                                </div>
                                <Link
                                    :href="debtsIndex.url()"
                                    class="-m-2 inline-flex items-center gap-1 rounded p-2 text-[0.8125rem] font-semibold text-accent-700 focus-ring dark:text-accent-300"
                                >
                                    Dívidas
                                    <ArrowRight
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </div>
                            <p
                                class="mt-5 numeric text-[2rem] leading-none tracking-[-0.035em] text-zinc-950 dark:text-white"
                            >
                                {{ formatAmount(collections.outstanding_minor)
                                }}<span
                                    class="ml-1 text-sm tracking-normal text-zinc-500 dark:text-zinc-400"
                                    >{{ currencyLabel() }}</span
                                >
                            </p>
                            <div
                                class="mt-5 flex h-2.5 gap-[3px]"
                                aria-hidden="true"
                            >
                                <span
                                    v-for="bucket in collections.totals.filter(
                                        (item) => item.total_minor > 0,
                                    )"
                                    :key="bucket.key"
                                    class="rounded-[3px]"
                                    :class="agingBucketColours[bucket.key]"
                                    :style="{
                                        width: agingShare(
                                            bucket.total_minor,
                                            collections.outstanding_minor,
                                        ),
                                    }"
                                />
                            </div>
                            <dl class="mt-3">
                                <div
                                    v-for="bucket in collections.totals"
                                    :key="bucket.key"
                                    class="grid h-8 grid-cols-[0.75rem_minmax(0,1fr)_auto] items-center gap-2.5 border-b border-zinc-900/[0.06] text-[0.8125rem] last:border-b-0 dark:border-white/10"
                                >
                                    <span
                                        class="size-2 rounded-[2px]"
                                        :class="agingBucketColours[bucket.key]"
                                        aria-hidden="true"
                                    />
                                    <dt
                                        class="text-zinc-600 dark:text-zinc-300"
                                    >
                                        {{ bucket.label }}
                                    </dt>
                                    <dd
                                        class="numeric font-semibold text-zinc-950 dark:text-white"
                                    >
                                        {{ formatAmount(bucket.total_minor) }}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <section
                            class="rounded-3xl bg-zinc-900/[0.04] p-6 dark:bg-white/[0.04]"
                            aria-labelledby="debtors-title"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h2
                                        id="debtors-title"
                                        class="text-base font-medium text-zinc-950 dark:text-white"
                                    >
                                        Cobranças em curso
                                    </h2>
                                    <p
                                        class="mt-1 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                                    >
                                        Quem deve há mais tempo primeiro
                                    </p>
                                </div>
                            </div>
                            <ul
                                role="list"
                                class="mt-4 grid grid-cols-[minmax(0,1fr)] sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_minmax(7rem,auto)] sm:gap-x-3"
                            >
                                <li
                                    v-for="customer in collections.customers"
                                    :key="
                                        customer.customer_public_id ??
                                        customer.name
                                    "
                                    class="grid grid-cols-1 items-center gap-3 border-t border-zinc-900/[0.06] py-3.5 sm:col-span-3 sm:grid-cols-subgrid dark:border-white/10"
                                >
                                    <div class="min-w-0">
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
                                            class="block truncate rounded text-sm font-semibold text-zinc-950 focus-ring dark:text-white"
                                            >{{ customer.name }}</component
                                        >
                                        <p
                                            class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{
                                                plural(
                                                    customer.document_count,
                                                    'documento',
                                                    'documentos',
                                                )
                                            }}
                                            <template
                                                v-if="
                                                    customer.oldest_days_past_due >
                                                    0
                                                "
                                                >·
                                                <span
                                                    class="text-rose-600 dark:text-rose-400"
                                                    >há
                                                    {{
                                                        plural(
                                                            customer.oldest_days_past_due,
                                                            'dia',
                                                            'dias',
                                                        )
                                                    }}</span
                                                ></template
                                            >
                                        </p>
                                    </div>
                                    <div
                                        class="flex h-2.5 gap-[3px]"
                                        role="img"
                                        :aria-label="`${formatMoney(customer.outstanding_minor)} em dívida, ${formatMoney(customer.overdue_minor)} vencidos`"
                                    >
                                        <span
                                            v-for="bucket in collections.totals"
                                            v-show="
                                                (customer.buckets[bucket.key] ??
                                                    0) > 0
                                            "
                                            :key="bucket.key"
                                            class="rounded-[3px]"
                                            :class="
                                                agingBucketColours[bucket.key]
                                            "
                                            :style="{
                                                width: agingShare(
                                                    customer.buckets[
                                                        bucket.key
                                                    ] ?? 0,
                                                    customer.outstanding_minor,
                                                ),
                                            }"
                                        />
                                    </div>
                                    <p
                                        class="numeric text-sm font-semibold text-zinc-950 sm:text-right dark:text-white"
                                    >
                                        {{
                                            formatAmount(
                                                customer.outstanding_minor,
                                            )
                                        }}
                                        <span
                                            class="font-normal text-zinc-500 dark:text-zinc-400"
                                            >{{ currencyLabel() }}</span
                                        >
                                    </p>
                                </li>
                            </ul>
                        </section>
                    </div>
                    <p
                        v-else-if="collections"
                        class="mt-5 animate-[fade-in_150ms_var(--ease-out)_both] rounded-3xl bg-zinc-900/[0.04] p-6 text-sm text-zinc-500 dark:bg-white/[0.04] dark:text-zinc-400"
                    >
                        Nenhum cliente em dívida neste momento.
                    </p>
                </Deferred>
            </div>
        </div>
    </AppLayout>
</template>
