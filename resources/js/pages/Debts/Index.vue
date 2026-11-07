<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronRight,
    CircleCheck,
    LoaderCircle,
    Mail,
    MessageCircle,
    Phone,
    Send,
    TriangleAlert,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onMounted, reactive, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import CollectionFollowUpQueue from '@/components/CollectionFollowUpQueue.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { agingBucketColours, agingShare } from '@/lib/aging';
import { followUpsForToday } from '@/lib/collection-follow-ups';
import type { CollectionFollowUpEntry } from '@/lib/collection-follow-ups';
import { concentrationLine, debtShares, shownDebtors } from '@/lib/debt-share';
import { initials } from '@/lib/initials';
import {
    canGoByWhatsApp,
    fetchReminderDraft,
    isSendable,
    whatsAppUrl,
} from '@/lib/reminder-draft';
import type { ReminderDraft } from '@/lib/reminder-draft';
import { show as showCustomer } from '@/routes/customers';
import {
    show as showReminder,
    store as storeReminder,
} from '@/routes/debts/reminder';

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
    followUps: CollectionFollowUpEntry[];
    customers: DebtRow[];
    /** Customers chosen on another page, such as the documents list. */
    preselected: string[];
    preselectedSkipped: number;
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

/** "20 nov", read as a calendar day so no timezone can move it. */
function shortDate(value: string): string {
    const day = new Date(`${value.slice(0, 10)}T00:00:00Z`);

    return `${day.getUTCDate()} ${new Intl.DateTimeFormat('pt-PT', { month: 'short', timeZone: 'UTC' }).format(day).replace('.', '')}`;
}

/** "18,8 M" in the legend, where the full figure would crowd the bar. */
function compactAmount(minor: number): string {
    const value = Math.round(minor / 100);
    const format = (amount: number) =>
        new Intl.NumberFormat('pt-AO', { maximumFractionDigits: 1 }).format(
            amount,
        );

    if (value >= 1_000_000) {
        return `${format(value / 1_000_000)} M`;
    }

    if (value >= 1_000) {
        return `${format(value / 1_000)} mil`;
    }

    return format(value);
}

const oldestDays = computed(
    () => props.customers[0]?.oldest_days_past_due ?? 0,
);

/* ----------------------------------------------------------- who owes */

const shares = computed(() =>
    debtShares(props.customers, props.summary.outstanding_minor),
);

/** Late and reachable by name: the ones a reminder can be written for. */
const overdue = computed(() =>
    props.customers.filter(
        (row) => row.customer_public_id !== null && row.overdue_minor > 0,
    ),
);

const notYetDue = computed(() =>
    props.customers.filter((row) => !overdue.value.includes(row)),
);

const everyOverdueShown = ref(false);
const everyInTermShown = ref(false);

const shownOverdue = computed(() =>
    shownDebtors(overdue.value, everyOverdueShown.value, props.preselected),
);

const shownInTerm = computed(() =>
    shownDebtors(notYetDue.value, everyInTermShown.value),
);

/* ---------------------------------------------------- prepared reminders */

type DraftState = ReminderDraft | 'failed';

const drafts = reactive<Record<string, DraftState>>({});
/** Only customers on screen start ticked: a reminder never goes to someone unseen. */
const selected = ref<string[]>(
    shownOverdue.value.flatMap((row) =>
        row.customer_public_id === null ? [] : [row.customer_public_id],
    ),
);
const edited = reactive<Record<string, { subject: string; body: string }>>({});
const editing = ref<string | null>(null);
const sent = ref<string[]>([]);
const sending = ref(false);

/** Each reminder is written as its customer appears, so choosing is reading, not waiting. */
function writeDrafts(): void {
    for (const row of shownOverdue.value) {
        const id = row.customer_public_id;

        if (id === null || drafts[id] !== undefined) {
            continue;
        }

        fetchReminderDraft(showReminder.url(id))
            .then((draft) => {
                drafts[id] = draft;
            })
            .catch(() => {
                drafts[id] = 'failed';
            });
    }
}

onMounted(writeDrafts);

function showEveryOverdue(): void {
    everyOverdueShown.value = true;
    writeDrafts();
}

function draftOf(id: string | null): ReminderDraft | null {
    const draft = id === null ? undefined : drafts[id];

    return draft === undefined || draft === 'failed' ? null : draft;
}

function toggle(id: string): void {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((chosen) => chosen !== id)
        : [...selected.value, id];
}

const prepared = computed(() =>
    overdue.value.flatMap((row) => {
        const id = row.customer_public_id;
        const draft = draftOf(id);

        return id !== null && draft !== null && selected.value.includes(id)
            ? [{ id, row, draft }]
            : [];
    }),
);

const toSend = computed(() =>
    prepared.value.filter(
        (entry) => isSendable(entry.draft) && !sent.value.includes(entry.id),
    ),
);

function startEditing(id: string, draft: ReminderDraft): void {
    edited[id] ??= { subject: draft.subject, body: draft.body };
    editing.value = editing.value === id ? null : id;
}

function wordsFor(entry: { id: string; draft: ReminderDraft }): {
    subject: string;
    body: string;
} {
    return (
        edited[entry.id] ?? {
            subject: entry.draft.subject,
            body: entry.draft.body,
        }
    );
}

/** One at a time, so a refusal on one customer does not hide the others. */
function sendAll(): void {
    const queue = [...toSend.value];

    if (queue.length === 0 || sending.value) {
        return;
    }

    sending.value = true;

    const next = (): void => {
        const entry = queue.shift();

        if (entry === undefined) {
            sending.value = false;

            return;
        }

        router.post(storeReminder.url(entry.id), wordsFor(entry), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                sent.value = [...sent.value, entry.id];
            },
            onFinish: next,
        });
    };

    next();
}

/** Read aloud in place of the bar: every bucket that holds money, with its amount. */
function bucketSummary(row: DebtRow): string {
    return props.buckets
        .filter((bucket) => (row.buckets[bucket.key] ?? 0) > 0)
        .map((bucket) => `${bucket.label}: ${money(row.buckets[bucket.key])}`)
        .join('; ');
}

/* -------------------------------------------------------- what comes next */

const followUpFor = computed(
    () =>
        new Map(
            props.followUps.map((entry) => [entry.customer_public_id, entry]),
        ),
);

const dueFollowUps = computed(() => followUpsForToday(props.followUps));

/** The second line under a customer: how late, and what was last agreed. */
function customerNote(row: DebtRow): string {
    const parts: string[] = [];
    const followUp =
        row.customer_public_id === null
            ? undefined
            : followUpFor.value.get(row.customer_public_id)?.follow_up;
    const draft = draftOf(row.customer_public_id);

    parts.push(
        row.overdue_minor > 0
            ? `Vencida há ${plural(row.oldest_days_past_due, 'dia', 'dias')}`
            : `${plural(row.document_count, 'documento', 'documentos')} no prazo`,
    );

    if (followUp?.promised_payment_on && !followUp.completed) {
        parts.push(
            `prometeu pagar a ${shortDate(followUp.promised_payment_on)}`,
        );
    } else if (draft?.last_sent_at) {
        parts.push(`lembrada a ${shortDate(draft.last_sent_at)}`);
    }

    return parts.join(' · ');
}

interface NextStep {
    label: string;
    icon: Component;
    tone: string;
    href?: string;
}

/** One verb per customer: call when a follow-up is due, otherwise the reminder to send. */
function nextStep(row: DebtRow): NextStep | null {
    const id = row.customer_public_id;
    const followUp = id === null ? undefined : followUpFor.value.get(id);

    if (
        id !== null &&
        followUp &&
        !followUp.follow_up.completed &&
        followUp.follow_up.is_due
    ) {
        return {
            label: 'Seguimento hoje',
            icon: Phone,
            tone: 'bg-[var(--ui-critical-wash)] text-[var(--ui-critical)]',
            href: `${showCustomer.url(id)}#follow-up-heading`,
        };
    }

    if (row.overdue_minor <= 0 || id === null) {
        return null;
    }

    const draft = draftOf(id);

    if (draft !== null && draft.to === null) {
        return {
            label: 'Sem email',
            icon: Mail,
            tone: 'bg-[var(--ui-well)] text-[var(--ui-ink-2)]',
            href: showCustomer.url(id),
        };
    }

    return {
        label: draft?.tone === 'firm' ? 'Lembrete firme' : 'Lembrete cordial',
        icon: Mail,
        tone: 'bg-[var(--ui-gold-wash)] text-[var(--ui-gold-ink)]',
    };
}

/** The avatar warms with the age of the oldest debt. */
function avatarTone(row: DebtRow): string {
    if (row.oldest_days_past_due > 30) {
        return 'bg-[color-mix(in_oklch,var(--ui-warning)_13%,transparent)] text-[var(--ui-warning)]';
    }

    if (row.oldest_days_past_due > 0) {
        return 'bg-[var(--ui-gold-wash)] text-[var(--ui-gold-ink)]';
    }

    return 'bg-[var(--ui-well)] text-[var(--ui-ink-2)]';
}

function bucketCell(row: DebtRow, ...keys: string[]): number {
    return keys.reduce((sum, key) => sum + (row.buckets[key] ?? 0), 0);
}

/** Debt over sixty days gets its own column only when someone owes it. */
const hasOldDebt = computed(() =>
    props.totals.some(
        (bucket) =>
            ['d61_90', 'd90_plus'].includes(bucket.key) &&
            bucket.total_minor > 0,
    ),
);

const gridColumns = computed(() =>
    hasOldDebt.value
        ? 'md:grid-cols-[2.75rem_minmax(11rem,1fr)_6.5rem_9.5rem] lg:grid-cols-[2.75rem_minmax(12rem,1fr)_5.5rem_5.5rem_5.5rem_5.5rem_6.5rem_9.5rem]'
        : 'md:grid-cols-[2.75rem_minmax(11rem,1fr)_6.5rem_9.5rem] lg:grid-cols-[2.75rem_minmax(12rem,1fr)_5.75rem_5.75rem_5.75rem_6.75rem_9.5rem]',
);

/** With nothing due today, the team's follow-ups fold to one line until asked for. */
const followUpsOpen = ref(false);

/* ------------------------------------------------------------- composer */

const activeReminder = ref<string | null>(null);

const activeEntry = computed(
    () =>
        prepared.value.find((entry) => entry.id === activeReminder.value) ??
        prepared.value[0] ??
        null,
);

function choose(id: string): void {
    const adding = !selected.value.includes(id);

    toggle(id);

    if (adding) {
        activeReminder.value = id;
    }
}

function whatsAppLink(entry: {
    id: string;
    draft: ReminderDraft;
}): string | null {
    return canGoByWhatsApp(entry.draft)
        ? whatsAppUrl(entry.draft, wordsFor(entry).body)
        : null;
}
</script>

<template>
    <AppLayout>
        <Head title="Quem me deve" />
        <div
            class="flex min-h-[calc(100dvh-4rem)] flex-col lg:min-h-[calc(100dvh-3rem)]"
        >
            <div class="px-4 empty:hidden sm:px-6"><FlashBanner /></div>

            <!-- ------------------------------------------------ title bar -->
            <header
                class="flex h-[3.25rem] items-center gap-2 border-b border-[var(--ui-line)] px-4 sm:px-6"
            >
                <span class="text-[0.8125rem] text-[var(--ui-ink-3)]"
                    >Cobrar</span
                >
                <ChevronRight
                    class="size-3.5 text-[var(--ui-ink-3)]"
                    aria-hidden="true"
                />
                <h1 class="text-[0.8125rem] font-semibold text-[var(--ui-ink)]">
                    Quem me deve
                </h1>
            </header>

            <!-- --------------------------------------------- the figures -->
            <div
                class="flex flex-wrap items-stretch border-b border-[var(--ui-line)]"
            >
                <div
                    class="flex flex-[1_1_12.5rem] flex-col gap-1.5 border-[var(--ui-line)] px-4 py-[1.125rem] max-sm:border-b sm:border-e sm:px-6"
                >
                    <span class="text-xs text-[var(--ui-ink-3)]"
                        >Por receber</span
                    >
                    <span class="flex items-baseline gap-1">
                        <span
                            class="numeric text-2xl/none font-[650] tracking-[-0.03em] text-[var(--ui-ink)]"
                            >{{ wholeAmount(summary.outstanding_minor) }}</span
                        >
                        <span
                            class="text-xs font-semibold text-[var(--ui-ink-3)]"
                            >{{ currencyLabel }}</span
                        >
                    </span>
                    <span class="text-xs text-[var(--ui-ink-3)]">{{
                        plural(summary.customer_count, 'cliente', 'clientes')
                    }}</span>
                </div>
                <div
                    class="flex flex-[1_1_12.5rem] flex-col gap-1.5 border-[var(--ui-line)] px-4 py-[1.125rem] max-sm:border-b sm:border-e sm:px-6"
                >
                    <span class="text-xs text-[var(--ui-ink-3)]"
                        >Em atraso</span
                    >
                    <span class="flex items-baseline gap-1">
                        <span
                            class="numeric text-2xl/none font-[650] tracking-[-0.03em]"
                            :class="
                                summary.overdue_minor > 0
                                    ? 'text-[var(--ui-critical)]'
                                    : 'text-[var(--ui-ink)]'
                            "
                            >{{ wholeAmount(summary.overdue_minor) }}</span
                        >
                        <span
                            class="text-xs font-semibold text-[var(--ui-ink-3)]"
                            >{{ currencyLabel }}</span
                        >
                    </span>
                    <span class="text-xs text-[var(--ui-ink-3)]">{{
                        oldestDays > 0
                            ? `${plural(overdue.length, 'cliente', 'clientes')} · a mais antiga há ${plural(oldestDays, 'dia', 'dias')}`
                            : 'Nada vencido'
                    }}</span>
                </div>
                <div
                    class="flex flex-[2_1_23.75rem] flex-col justify-center gap-2.5 px-4 py-[1.125rem] sm:px-6"
                >
                    <template v-if="summary.outstanding_minor > 0">
                        <div
                            class="flex h-2 gap-0.5 overflow-clip rounded"
                            aria-hidden="true"
                        >
                            <span
                                v-for="bucket in totals.filter(
                                    (item) => item.total_minor > 0,
                                )"
                                :key="bucket.key"
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
                            class="flex flex-wrap gap-x-[1.125rem] gap-y-1 text-xs text-[var(--ui-ink-2)]"
                        >
                            <div
                                v-for="bucket in totals.filter(
                                    (item) => item.total_minor > 0,
                                )"
                                :key="bucket.key"
                                class="flex items-center gap-1.5"
                            >
                                <dt class="flex items-center gap-1.5">
                                    <span
                                        class="size-2 rounded-full"
                                        :class="agingBucketColours[bucket.key]"
                                        aria-hidden="true"
                                    />{{ bucket.label }}
                                </dt>
                                <dd
                                    class="numeric font-semibold text-[var(--ui-ink)]"
                                    :title="money(bucket.total_minor)"
                                >
                                    {{ compactAmount(bucket.total_minor) }}
                                </dd>
                            </div>
                        </dl>
                    </template>
                    <p v-else class="text-[0.8125rem] text-[var(--ui-ink-3)]">
                        Nada por receber. Todas as facturas estão liquidadas.
                    </p>
                </div>
            </div>

            <p
                v-if="summary.over_limit_count > 0"
                class="flex items-center gap-2 border-b border-[var(--ui-line)] bg-[color-mix(in_oklch,var(--ui-warning)_8%,transparent)] px-4 py-2.5 text-[0.8125rem] text-[var(--ui-ink)] sm:px-6"
            >
                <TriangleAlert
                    class="size-3.5 shrink-0 text-[var(--ui-warning)]"
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
                    mais do que o limite de crédito acordado. Vale a pena cobrar
                    antes de emitir mais.
                </span>
            </p>

            <div
                v-if="dueFollowUps.length > 0 || followUpsOpen"
                class="border-b border-[var(--ui-line)] px-4 py-4 sm:px-6"
            >
                <CollectionFollowUpQueue :entries="followUps" />
            </div>
            <p
                v-else-if="followUps.length > 0"
                class="flex items-center gap-2 border-b border-[var(--ui-line)] px-4 py-2 text-xs text-[var(--ui-ink-3)] sm:px-6"
            >
                <Phone class="size-3.5" aria-hidden="true" />
                {{
                    plural(
                        followUps.length,
                        'seguimento em aberto',
                        'seguimentos em aberto',
                    )
                }}, nenhum para hoje.
                <AppButton
                    variant="quiet"
                    size="xs"
                    class="px-1.5"
                    @click="followUpsOpen = true"
                    >Ver</AppButton
                >
            </p>

            <!-- ------------------------------------------- table + composer -->
            <div
                v-if="customers.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-2 py-20 text-center"
            >
                <CircleCheck
                    class="size-5 text-[var(--ui-positive)]"
                    aria-hidden="true"
                />
                <p class="text-sm font-semibold text-[var(--ui-ink)]">
                    Ninguém deve nada de momento
                </p>
                <p class="text-[0.8125rem] text-[var(--ui-ink-3)]">
                    Quando um prazo passar, o lembrete aparece aqui, já escrito.
                </p>
            </div>

            <div v-else class="flex flex-1 flex-col items-stretch xl:flex-row">
                <section aria-labelledby="debtors-title" class="min-w-0 flex-1">
                    <h2 id="debtors-title" class="sr-only">
                        Clientes com saldo em aberto
                    </h2>
                    <p
                        v-if="preselectedSkipped > 0"
                        class="border-b border-[var(--ui-line)] px-4 py-2.5 text-[0.8125rem] text-[var(--ui-ink-3)] sm:px-6"
                    >
                        {{
                            plural(
                                preselectedSkipped,
                                'cliente escolhido não tem',
                                'clientes escolhidos não têm',
                            )
                        }}
                        nada vencido, por isso não
                        {{ preselectedSkipped === 1 ? 'aparece' : 'aparecem' }}
                        entre os que pode lembrar.
                    </p>

                    <div
                        :class="[
                            gridColumns,
                            'grid h-[2.125rem] items-center border-b border-[var(--ui-line)] pe-4 text-end text-xs font-medium text-[var(--ui-ink-3)] max-md:hidden sm:pe-6',
                        ]"
                        aria-hidden="true"
                    >
                        <span />
                        <span class="px-2 text-start">Cliente</span>
                        <span class="px-2 max-lg:hidden">A vencer</span>
                        <span class="px-2 max-lg:hidden">1–30</span>
                        <span class="px-2 max-lg:hidden">31–60</span>
                        <span v-if="hasOldDebt" class="px-2 max-lg:hidden"
                            >Mais de 60</span
                        >
                        <span class="px-2">Total</span>
                        <span class="ps-4 text-start">A seguir</span>
                    </div>

                    <template
                        v-for="group in [
                            {
                                key: 'overdue',
                                label: 'Em atraso',
                                rows: shownOverdue,
                                all: overdue,
                            },
                            {
                                key: 'term',
                                label: 'Dentro do prazo',
                                rows: shownInTerm,
                                all: notYetDue,
                            },
                        ]"
                        :key="group.key"
                    >
                        <template v-if="group.all.length > 0">
                            <div
                                class="flex h-8 items-center gap-1.5 border-b border-[var(--ui-line)] bg-[var(--ui-head)] px-4 text-xs font-semibold text-[var(--ui-ink-2)] sm:px-6"
                            >
                                <ChevronDown
                                    class="size-3"
                                    :stroke-width="2.2"
                                    aria-hidden="true"
                                />
                                {{ group.label }}
                                <span
                                    class="numeric font-medium text-[var(--ui-ink-3)]"
                                    >{{ group.all.length }}</span
                                >
                            </div>
                            <ul role="list">
                                <li
                                    v-for="row in group.rows"
                                    :key="row.customer_public_id ?? row.name"
                                    :class="[
                                        gridColumns,
                                        'flex items-center border-b border-[var(--ui-line)] pe-4 text-[0.8125rem] sm:pe-6 md:grid md:h-14',
                                        row.customer_public_id &&
                                        selected.includes(
                                            row.customer_public_id,
                                        )
                                            ? 'bg-[var(--ui-select)]'
                                            : 'hover:bg-[var(--ui-wash)]',
                                    ]"
                                >
                                    <label
                                        v-if="
                                            group.key === 'overdue' &&
                                            row.customer_public_id
                                        "
                                        class="grid w-11 shrink-0 cursor-pointer place-items-center self-stretch ps-1 md:w-auto md:ps-2.5"
                                    >
                                        <span class="sr-only"
                                            >Lembrar {{ row.name }}</span
                                        >
                                        <input
                                            type="checkbox"
                                            class="form-checkbox"
                                            :checked="
                                                selected.includes(
                                                    row.customer_public_id,
                                                )
                                            "
                                            :disabled="
                                                draftOf(
                                                    row.customer_public_id,
                                                ) !== null &&
                                                !isSendable(
                                                    draftOf(
                                                        row.customer_public_id,
                                                    ),
                                                )
                                            "
                                            @change="
                                                choose(row.customer_public_id)
                                            "
                                        />
                                    </label>
                                    <span
                                        v-else
                                        class="w-11 shrink-0 md:w-auto"
                                    />

                                    <span
                                        class="flex min-w-0 flex-1 items-center gap-2.5 px-2 py-2.5 md:py-0"
                                    >
                                        <span
                                            class="grid size-7 shrink-0 place-items-center rounded-full text-[0.65625rem] font-bold"
                                            :class="avatarTone(row)"
                                            aria-hidden="true"
                                            >{{ initials(row.name) }}</span
                                        >
                                        <span
                                            class="flex min-w-0 flex-col gap-px"
                                        >
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
                                                class="w-fit max-w-full truncate rounded font-semibold text-[var(--ui-ink)]"
                                                :class="
                                                    row.customer_public_id
                                                        ? 'focus-ring hover:underline hover:decoration-[var(--ui-line-strong)] hover:underline-offset-2'
                                                        : ''
                                                "
                                                >{{ row.name }}</component
                                            >
                                            <span
                                                class="truncate text-xs text-[var(--ui-ink-3)]"
                                                >{{ customerNote(row)
                                                }}<span
                                                    v-if="row.over_limit"
                                                    class="text-[var(--ui-warning)]"
                                                >
                                                    · acima do limite</span
                                                ></span
                                            >
                                        </span>
                                    </span>

                                    <span
                                        class="px-2 text-end numeric text-[var(--ui-ink-2)] max-lg:hidden"
                                        >{{
                                            bucketCell(row, 'current')
                                                ? wholeAmount(
                                                      bucketCell(
                                                          row,
                                                          'current',
                                                      ),
                                                  )
                                                : '—'
                                        }}</span
                                    >
                                    <span
                                        class="px-2 text-end numeric max-lg:hidden"
                                        :class="
                                            bucketCell(row, 'd1_30')
                                                ? 'font-[550] text-[var(--ui-gold-ink)]'
                                                : 'text-[var(--ui-ink-3)]'
                                        "
                                        >{{
                                            bucketCell(row, 'd1_30')
                                                ? wholeAmount(
                                                      bucketCell(row, 'd1_30'),
                                                  )
                                                : '—'
                                        }}</span
                                    >
                                    <span
                                        class="px-2 text-end numeric max-lg:hidden"
                                        :class="
                                            bucketCell(row, 'd31_60')
                                                ? 'font-[550] text-[var(--ui-warning)]'
                                                : 'text-[var(--ui-ink-3)]'
                                        "
                                        >{{
                                            bucketCell(row, 'd31_60')
                                                ? wholeAmount(
                                                      bucketCell(row, 'd31_60'),
                                                  )
                                                : '—'
                                        }}</span
                                    >
                                    <span
                                        v-if="hasOldDebt"
                                        class="px-2 text-end numeric max-lg:hidden"
                                        :class="
                                            bucketCell(
                                                row,
                                                'd61_90',
                                                'd90_plus',
                                            )
                                                ? 'font-[550] text-[var(--ui-critical)]'
                                                : 'text-[var(--ui-ink-3)]'
                                        "
                                        >{{
                                            bucketCell(
                                                row,
                                                'd61_90',
                                                'd90_plus',
                                            )
                                                ? wholeAmount(
                                                      bucketCell(
                                                          row,
                                                          'd61_90',
                                                          'd90_plus',
                                                      ),
                                                  )
                                                : '—'
                                        }}</span
                                    >
                                    <span
                                        class="shrink-0 px-2 text-end numeric font-semibold text-[var(--ui-ink)]"
                                        :title="bucketSummary(row)"
                                        >{{
                                            wholeAmount(row.outstanding_minor)
                                        }}</span
                                    >
                                    <span
                                        class="min-w-0 ps-4 pe-0 max-md:hidden"
                                    >
                                        <component
                                            :is="
                                                nextStep(row)?.href
                                                    ? Link
                                                    : 'span'
                                            "
                                            v-if="nextStep(row)"
                                            :href="nextStep(row)?.href"
                                            class="inline-flex h-6 max-w-full items-center gap-1.5 rounded-md ps-[0.4375rem] pe-2 text-xs font-[550] whitespace-nowrap"
                                            :class="[
                                                nextStep(row)!.tone,
                                                nextStep(row)?.href
                                                    ? 'focus-ring'
                                                    : '',
                                            ]"
                                        >
                                            <component
                                                :is="nextStep(row)!.icon"
                                                class="size-[0.8125rem] shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span class="truncate">{{
                                                nextStep(row)!.label
                                            }}</span>
                                        </component>
                                        <span
                                            v-else
                                            class="text-[var(--ui-ink-3)]"
                                            >—</span
                                        >
                                    </span>
                                </li>
                            </ul>
                            <div
                                v-if="group.rows.length < group.all.length"
                                class="flex items-center gap-3 border-b border-[var(--ui-line)] px-4 py-2 sm:px-6"
                            >
                                <span class="text-xs text-[var(--ui-ink-3)]">{{
                                    group.key === 'overdue' &&
                                    preselected.length > 0
                                        ? 'Os clientes que escolheu.'
                                        : `${group.rows.length} de ${group.all.length}`
                                }}</span>
                                <AppButton
                                    variant="quiet"
                                    size="xs"
                                    @click="
                                        group.key === 'overdue'
                                            ? showEveryOverdue()
                                            : (everyInTermShown = true)
                                    "
                                >
                                    Ver todos
                                </AppButton>
                            </div>
                        </template>
                    </template>

                    <p
                        v-if="shares.length > 1"
                        class="px-4 pt-4 text-xs/5 text-[var(--ui-ink-3)] sm:px-6"
                    >
                        {{ concentrationLine(shares, summary.customer_count) }}
                    </p>
                    <p
                        class="px-4 pt-2 pb-6 text-xs/5 text-[var(--ui-ink-3)] sm:px-6"
                    >
                        Valores na moeda da empresa, saldos actuais, sem
                        conversão cambial. A idade conta a partir do vencimento
                        acordado.
                    </p>
                    <ul class="sr-only">
                        <li v-for="row in customers" :key="row.name">
                            {{ row.name }}: {{ bucketSummary(row) }}
                        </li>
                    </ul>
                </section>

                <!-- ------------------------------------------ the composer -->
                <aside
                    aria-labelledby="prepared-title"
                    class="flex flex-col border-[var(--ui-line)] max-xl:border-t xl:sticky xl:inset-bs-12 xl:h-[calc(100dvh-3rem)] xl:w-[22.5rem] xl:shrink-0 xl:border-s"
                >
                    <div class="flex flex-col gap-3 px-5 pt-4">
                        <div class="flex items-center gap-2">
                            <h2
                                id="prepared-title"
                                class="flex-1 text-sm font-semibold text-[var(--ui-ink)]"
                            >
                                Lembretes
                            </h2>
                            <span
                                v-if="prepared.length > 0"
                                class="text-xs text-[var(--ui-ink-3)]"
                                >{{
                                    plural(
                                        prepared.length,
                                        'cliente',
                                        'clientes',
                                    )
                                }}</span
                            >
                        </div>
                        <div
                            v-if="prepared.length > 1"
                            role="tablist"
                            aria-label="Lembrete a rever"
                            class="-mx-5 flex [scrollbar-width:none] gap-4 overflow-x-auto border-b border-[var(--ui-line)] px-5"
                        >
                            <button
                                v-for="entry in prepared"
                                :key="entry.id"
                                type="button"
                                role="tab"
                                :aria-selected="activeEntry?.id === entry.id"
                                class="-mbe-px flex shrink-0 items-center gap-1.5 rounded-t py-2 text-[0.78125rem] focus-ring"
                                :class="
                                    activeEntry?.id === entry.id
                                        ? 'font-semibold text-[var(--ui-ink)] shadow-[inset_0_-2px_0_var(--ui-ink)]'
                                        : 'font-medium text-[var(--ui-ink-3)] hover:text-[var(--ui-ink)]'
                                "
                                @click="activeReminder = entry.id"
                            >
                                <CircleCheck
                                    v-if="sent.includes(entry.id)"
                                    class="size-3 text-[var(--ui-positive)]"
                                    aria-label="Enviado"
                                />
                                {{ entry.row.name }}
                            </button>
                        </div>
                        <div
                            v-else
                            class="-mx-5 border-b border-[var(--ui-line)]"
                        />
                    </div>

                    <div
                        class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto overscroll-contain px-5 py-3.5"
                    >
                        <div
                            v-if="activeEntry === null"
                            class="flex flex-1 flex-col items-center justify-center gap-1.5 py-10 text-center"
                        >
                            <Mail
                                class="size-5 text-[var(--ui-ink-3)]"
                                aria-hidden="true"
                            />
                            <p
                                class="text-[0.8125rem] font-semibold text-[var(--ui-ink)]"
                            >
                                {{
                                    overdue.length === 0
                                        ? 'Nada para lembrar'
                                        : 'Escolha a quem lembrar'
                                }}
                            </p>
                            <p
                                class="max-w-[16rem] text-xs/5 text-[var(--ui-ink-3)]"
                            >
                                {{
                                    overdue.length === 0
                                        ? 'Quando um prazo passar, o lembrete aparece aqui, já escrito.'
                                        : 'Marque um cliente em atraso e o lembrete aparece aqui, já escrito, para rever.'
                                }}
                            </p>
                        </div>

                        <template v-else>
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex h-6 items-center rounded-md bg-[var(--ui-well)] px-2 text-xs font-[550] text-[var(--ui-ink-2)]"
                                    >{{
                                        activeEntry.draft.tone === 'firm'
                                            ? 'Tom firme'
                                            : 'Tom cordial'
                                    }}</span
                                >
                                <span
                                    v-if="sent.includes(activeEntry.id)"
                                    class="inline-flex h-6 items-center gap-1 rounded-md bg-[var(--ui-positive-wash)] px-2 text-xs font-[550] text-[var(--ui-positive)]"
                                    ><CircleCheck
                                        class="size-3"
                                        aria-hidden="true"
                                    />Enviado</span
                                >
                                <span class="flex-1" />
                                <a
                                    v-if="whatsAppLink(activeEntry)"
                                    :href="whatsAppLink(activeEntry)!"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex h-6 items-center gap-1 rounded-md px-1.5 text-xs font-medium text-[var(--ui-ink-2)] focus-ring hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)]"
                                    ><MessageCircle
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />WhatsApp</a
                                >
                                <button
                                    v-if="
                                        activeEntry.draft.to !== null &&
                                        !sent.includes(activeEntry.id)
                                    "
                                    type="button"
                                    class="inline-flex h-6 items-center rounded-md px-1.5 text-xs font-medium text-[var(--ui-ink-2)] focus-ring hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)]"
                                    @click="
                                        startEditing(
                                            activeEntry.id,
                                            activeEntry.draft,
                                        )
                                    "
                                >
                                    {{
                                        editing === activeEntry.id
                                            ? 'Concluir'
                                            : 'Editar'
                                    }}
                                </button>
                            </div>

                            <p
                                v-if="activeEntry.draft.to === null"
                                class="rounded-lg bg-[var(--ui-critical-wash)] px-3 py-2.5 text-[0.8125rem]/5 text-[var(--ui-ink)]"
                                role="alert"
                            >
                                {{ activeEntry.row.name }} não tem email
                                registado. Adicione um na ficha do cliente para
                                poder enviar.
                            </p>

                            <div
                                v-else
                                class="flex flex-col rounded-[10px] bg-[var(--surface-raised)] shadow-[var(--ui-card-shadow)]"
                            >
                                <div
                                    class="flex flex-col gap-1.5 border-b border-[var(--ui-line)] px-3.5 py-2.5 text-[0.78125rem]"
                                >
                                    <span class="flex gap-2"
                                        ><span
                                            class="w-14 shrink-0 text-[var(--ui-ink-3)]"
                                            >Para</span
                                        ><span
                                            class="min-w-0 truncate text-[var(--ui-ink)]"
                                            >{{ activeEntry.draft.to }}</span
                                        ></span
                                    >
                                    <span
                                        v-if="editing !== activeEntry.id"
                                        class="flex gap-2"
                                        ><span
                                            class="w-14 shrink-0 text-[var(--ui-ink-3)]"
                                            >Assunto</span
                                        ><span
                                            class="min-w-0 font-[550] text-[var(--ui-ink)]"
                                            >{{
                                                wordsFor(activeEntry).subject
                                            }}</span
                                        ></span
                                    >
                                </div>

                                <div
                                    v-if="editing === activeEntry.id"
                                    class="flex flex-col gap-2 p-3.5"
                                >
                                    <label
                                        :for="`subject-${activeEntry.id}`"
                                        class="text-xs font-medium text-[var(--ui-ink-2)]"
                                        >Assunto</label
                                    >
                                    <input
                                        :id="`subject-${activeEntry.id}`"
                                        v-model="edited[activeEntry.id].subject"
                                        type="text"
                                        maxlength="150"
                                        class="form-input"
                                    />
                                    <label
                                        :for="`body-${activeEntry.id}`"
                                        class="mt-1 text-xs font-medium text-[var(--ui-ink-2)]"
                                        >Mensagem</label
                                    >
                                    <textarea
                                        :id="`body-${activeEntry.id}`"
                                        v-model="edited[activeEntry.id].body"
                                        rows="10"
                                        maxlength="4000"
                                        class="form-input"
                                    />
                                </div>
                                <div v-else class="flex flex-col gap-2.5 p-3.5">
                                    <p
                                        class="text-[0.8125rem]/[1.55] whitespace-pre-line text-[var(--ui-ink)]"
                                    >
                                        {{ wordsFor(activeEntry).body }}
                                    </p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div
                        class="flex items-center gap-2 border-t border-[var(--ui-line)] px-5 pt-3.5 pb-5"
                    >
                        <span
                            class="flex-1 text-[0.71875rem]/[1.4] text-[var(--ui-ink-3)]"
                            >Só sai quando enviar. Fica registado em cada
                            cliente.</span
                        >
                        <AppButton
                            variant="gold"
                            size="sm"
                            :disabled="toSend.length === 0 || sending"
                            @click="sendAll"
                        >
                            <LoaderCircle
                                v-if="sending"
                                class="size-3.5 animate-spin"
                                aria-hidden="true"
                            />
                            <Send v-else class="size-3.5" aria-hidden="true" />
                            {{
                                toSend.length === 0
                                    ? 'Enviar'
                                    : `Enviar ${toSend.length}`
                            }}
                        </AppButton>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
