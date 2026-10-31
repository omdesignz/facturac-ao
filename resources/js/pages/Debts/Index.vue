<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CircleCheck,
    LoaderCircle,
    Sparkles,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onMounted, reactive, ref } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { agingBucketColours, agingShare } from '@/lib/aging';
import { concentrationLine, debtShares } from '@/lib/debt-share';
import { buttonGold, buttonQuiet } from '@/lib/pos-ui';
import {
    fetchReminderDraft,
    isSendable,
    toneLabel,
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

function shortDate(value: string): string {
    return new Intl.DateTimeFormat('pt-AO', {
        day: 'numeric',
        month: 'long',
    }).format(new Date(value));
}

const oldestDays = computed(
    () => props.customers[0]?.oldest_days_past_due ?? 0,
);

/* ----------------------------------------------------------- who owes */

const shares = computed(() =>
    debtShares(props.customers, props.summary.outstanding_minor),
);

const shareColours = [
    'bg-brand-950 dark:bg-zinc-100',
    'bg-accent-400',
    'bg-zinc-400 dark:bg-zinc-500',
    'bg-zinc-300 dark:bg-zinc-700',
];

/** Late and reachable by name: the ones a reminder can be written for. */
const overdue = computed(() =>
    props.customers.filter(
        (row) => row.customer_public_id !== null && row.overdue_minor > 0,
    ),
);

const notYetDue = computed(() =>
    props.customers.filter((row) => !overdue.value.includes(row)),
);

/* ---------------------------------------------------- prepared reminders */

type DraftState = ReminderDraft | 'failed';

const drafts = reactive<Record<string, DraftState>>({});
const selected = ref<string[]>(
    overdue.value.flatMap((row) =>
        row.customer_public_id === null ? [] : [row.customer_public_id],
    ),
);
const edited = reactive<Record<string, { subject: string; body: string }>>({});
const editing = ref<string | null>(null);
const sent = ref<string[]>([]);
const sending = ref(false);

/** Each reminder is written when the page opens, so choosing is reading, not waiting. */
onMounted(() => {
    for (const row of overdue.value.slice(0, 12)) {
        const id = row.customer_public_id;

        if (id === null) {
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
});

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

const dayPill = (days: number): string =>
    days > 7
        ? 'bg-[#fde7ea] text-[#9a1b30] dark:bg-rose-400/10 dark:text-rose-300'
        : 'bg-[#fef3d6] text-[#7a4a00] dark:bg-accent-400/15 dark:text-accent-300';
</script>

<template>
    <AppLayout>
        <Head title="Dívidas" />
        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="page-shell">
                <FlashBanner />

                <!-- ------------------------------------------ the question -->
                <header
                    class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0">
                        <p
                            class="text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Cobrar
                        </p>
                        <h1
                            class="mt-2.5 text-page-title leading-[1.08] font-light tracking-[-0.028em] text-zinc-950 dark:text-white"
                        >
                            Quem me deve?
                        </h1>
                    </div>
                    <div class="sm:text-right">
                        <p
                            class="text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Por receber
                        </p>
                        <p
                            class="mt-2 numeric text-[2.25rem] leading-none font-light tracking-[-0.035em] text-zinc-950 dark:text-white"
                        >
                            {{ wholeAmount(summary.outstanding_minor)
                            }}<span
                                class="ml-1.5 text-sm tracking-normal text-zinc-500 dark:text-zinc-400"
                                >{{ currencyLabel }}</span
                            >
                        </p>
                        <p
                            class="mt-2 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                        >
                            <span
                                v-if="summary.overdue_minor > 0"
                                class="text-rose-600 dark:text-rose-400"
                                >{{
                                    wholeAmount(summary.overdue_minor)
                                }}
                                vencidos</span
                            ><span v-else>nada vencido</span>
                            <template v-if="oldestDays > 0">
                                · a mais antiga há
                                {{
                                    plural(oldestDays, 'dia', 'dias')
                                }}</template
                            >
                        </p>
                    </div>
                </header>

                <template v-if="shares.length > 0">
                    <div
                        class="mt-6 flex h-4 gap-[3px] overflow-hidden rounded-full"
                        role="img"
                        :aria-label="
                            shares
                                .map(
                                    (entry) =>
                                        `${entry.name} ${entry.percent}%`,
                                )
                                .join('; ')
                        "
                    >
                        <span
                            v-for="(entry, index) in shares"
                            :key="entry.name"
                            class="first:rounded-l-full last:rounded-r-full"
                            :class="shareColours[index] ?? shareColours[3]"
                            :style="{ width: `${entry.share * 100}%` }"
                        />
                    </div>
                    <div
                        class="mt-2.5 flex justify-between gap-4 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                    >
                        <span class="truncate"
                            ><span
                                class="font-semibold text-zinc-950 dark:text-white"
                                >{{ shares[0].name }}</span
                            >
                            {{ shares[0].percent }}%</span
                        >
                        <span v-if="shares.length > 1" class="truncate"
                            ><span
                                class="font-semibold text-zinc-950 dark:text-white"
                                >{{ shares[shares.length - 1].name }}</span
                            >
                            {{ shares[shares.length - 1].percent }}%</span
                        >
                    </div>
                    <p
                        class="mt-4 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        {{ concentrationLine(shares) }}
                    </p>
                </template>

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

                <div
                    v-if="customers.length === 0"
                    class="mt-10 flex flex-col items-center gap-2 rounded-3xl surface py-14 text-center"
                >
                    <CircleCheck
                        class="size-7 text-lime-600 dark:text-lime-400"
                        aria-hidden="true"
                    />
                    <p class="text-sm/6 text-zinc-500 dark:text-zinc-400">
                        Ninguém deve nada de momento.
                    </p>
                </div>

                <div
                    v-else
                    class="mt-10 grid grid-cols-[minmax(0,1fr)] items-start gap-8 lg:grid-cols-[minmax(0,1fr)_26rem]"
                >
                    <!-- -------------------------------- choose whom to remind -->
                    <section aria-labelledby="choose-title" class="min-w-0">
                        <h2
                            id="choose-title"
                            class="text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Escolha a quem lembrar
                        </h2>

                        <p
                            v-if="overdue.length === 0"
                            class="mt-4 rounded-3xl surface p-6 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Nada está vencido. Quando um prazo passar, o
                            lembrete aparece aqui, já escrito.
                        </p>

                        <ul v-else role="list" class="mt-4 grid gap-3">
                            <li
                                v-for="row in overdue"
                                :key="row.customer_public_id ?? row.name"
                                class="rounded-3xl bg-white p-5 transition dark:bg-[#1c1c1e]"
                                :class="
                                    selected.includes(
                                        row.customer_public_id ?? '',
                                    )
                                        ? 'ring-[1.5px] ring-zinc-950 dark:ring-white'
                                        : 'surface'
                                "
                            >
                                <div class="flex items-start gap-3.5">
                                    <input
                                        :id="`remind-${row.customer_public_id}`"
                                        type="checkbox"
                                        class="form-checkbox mt-1"
                                        :checked="
                                            selected.includes(
                                                row.customer_public_id ?? '',
                                            )
                                        "
                                        :disabled="
                                            draftOf(row.customer_public_id) !==
                                                null &&
                                            !isSendable(
                                                draftOf(row.customer_public_id),
                                            )
                                        "
                                        @change="
                                            toggle(row.customer_public_id ?? '')
                                        "
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"
                                        >
                                            <label
                                                :for="`remind-${row.customer_public_id}`"
                                                class="text-lg/7 font-normal tracking-[-0.01em] text-zinc-950 dark:text-white"
                                                >{{ row.name }}</label
                                            >
                                            <p
                                                class="numeric text-xl tracking-[-0.02em] text-zinc-950 dark:text-white"
                                            >
                                                {{
                                                    wholeAmount(
                                                        row.overdue_minor,
                                                    )
                                                }}
                                                <span
                                                    class="text-sm text-zinc-500"
                                                    >{{ currencyLabel }}</span
                                                >
                                            </p>
                                        </div>
                                        <p
                                            class="mt-1 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                                        >
                                            Mais antiga há
                                            {{
                                                plural(
                                                    row.oldest_days_past_due,
                                                    'dia',
                                                    'dias',
                                                )
                                            }}
                                            <template
                                                v-if="
                                                    draftOf(
                                                        row.customer_public_id,
                                                    )
                                                "
                                                >·
                                                {{
                                                    draftOf(
                                                        row.customer_public_id,
                                                    )?.last_sent_at
                                                        ? `último lembrete a ${shortDate(draftOf(row.customer_public_id)!.last_sent_at!)}`
                                                        : 'ainda sem lembrete'
                                                }}</template
                                            >
                                            <StatusBadge
                                                v-if="row.over_limit"
                                                class="ml-2"
                                                label="Acima do limite"
                                                tone="warning"
                                            />
                                        </p>

                                        <ul
                                            v-if="
                                                draftOf(row.customer_public_id)
                                            "
                                            role="list"
                                            class="mt-4 divide-y divide-zinc-900/[0.07] border-t border-zinc-900/[0.07] dark:divide-white/10 dark:border-white/10"
                                        >
                                            <li
                                                v-for="document in draftOf(
                                                    row.customer_public_id,
                                                )!.documents"
                                                :key="document.document_no"
                                                class="flex items-center gap-3 py-2.5 text-sm"
                                            >
                                                <span
                                                    class="font-mono text-[0.8125rem] text-zinc-800 dark:text-zinc-200"
                                                    >{{
                                                        document.document_no
                                                    }}</span
                                                >
                                                <span
                                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                                    :class="
                                                        dayPill(
                                                            document.days_past_due,
                                                        )
                                                    "
                                                    >{{
                                                        document.days_past_due
                                                    }}
                                                    dias</span
                                                >
                                                <span
                                                    class="ml-auto numeric text-zinc-800 dark:text-zinc-200"
                                                    >{{
                                                        wholeAmount(
                                                            document.outstanding_minor,
                                                        )
                                                    }}</span
                                                >
                                            </li>
                                        </ul>
                                        <div
                                            v-else-if="
                                                row.customer_public_id &&
                                                drafts[
                                                    row.customer_public_id
                                                ] !== 'failed'
                                            "
                                            class="mt-4 h-10 rounded-xl bg-zinc-900/[0.05] dark:bg-white/[0.06]"
                                            aria-hidden="true"
                                        />

                                        <div
                                            class="mt-3 flex flex-wrap items-center gap-2"
                                        >
                                            <span
                                                v-if="
                                                    draftOf(
                                                        row.customer_public_id,
                                                    )
                                                "
                                                class="rounded-full bg-zinc-900/[0.06] px-2.5 py-1 text-xs font-medium text-zinc-700 dark:bg-white/10 dark:text-zinc-200"
                                                >Sugestão:
                                                {{
                                                    toneLabel(
                                                        draftOf(
                                                            row.customer_public_id,
                                                        )!.tone,
                                                    )
                                                }}</span
                                            >
                                            <Link
                                                :href="
                                                    showCustomer.url(
                                                        row.customer_public_id ??
                                                            '',
                                                    )
                                                "
                                                class="inline-flex items-center rounded text-xs font-medium text-zinc-500 underline-offset-4 focus-ring hover:text-zinc-950 hover:underline dark:text-zinc-400 dark:hover:text-white pointer-coarse:min-h-11"
                                                >Ver cliente<span
                                                    class="sr-only"
                                                >
                                                    {{ row.name }}</span
                                                ></Link
                                            >
                                        </div>
                                    </div>
                                </div>
                            </li>
                        </ul>

                        <template v-if="notYetDue.length > 0">
                            <h2
                                class="mt-10 text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Ainda no prazo
                            </h2>
                            <ul
                                role="list"
                                class="mt-3 divide-y divide-zinc-900/[0.07] dark:divide-white/10"
                            >
                                <li
                                    v-for="row in notYetDue"
                                    :key="row.customer_public_id ?? row.name"
                                    class="flex flex-wrap items-center gap-x-4 gap-y-1 py-3.5 text-sm"
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
                                        class="min-w-0 truncate rounded font-medium text-zinc-950 focus-ring dark:text-white pointer-coarse:py-3"
                                        :class="
                                            row.customer_public_id
                                                ? 'underline-offset-4 hover:underline'
                                                : ''
                                        "
                                        >{{ row.name }}</component
                                    >
                                    <span
                                        class="text-xs text-zinc-500 dark:text-zinc-400"
                                        >{{
                                            plural(
                                                row.document_count,
                                                'documento',
                                                'documentos',
                                            )
                                        }}
                                        · dentro do prazo</span
                                    >
                                    <StatusBadge
                                        v-if="row.over_limit"
                                        label="Acima do limite"
                                        tone="warning"
                                    />
                                    <span
                                        class="ml-auto numeric text-zinc-800 dark:text-zinc-200"
                                        >{{
                                            wholeAmount(row.outstanding_minor)
                                        }}
                                        <span class="text-zinc-400">{{
                                            currencyLabel
                                        }}</span></span
                                    >
                                </li>
                            </ul>
                        </template>
                    </section>

                    <!-- ------------------------------------- prepared for you -->
                    <aside
                        class="rounded-3xl surface bg-white p-6 lg:sticky lg:top-24 dark:bg-[#1c1c1e]"
                        aria-labelledby="prepared-title"
                    >
                        <p
                            class="flex items-center gap-1.5 text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <Sparkles class="size-3" aria-hidden="true" />
                            Preparado para si
                        </p>
                        <h2
                            id="prepared-title"
                            class="mt-3 text-[1.5rem] font-normal tracking-[-0.02em] text-zinc-950 dark:text-white"
                        >
                            {{
                                prepared.length === 0
                                    ? 'Nenhum lembrete'
                                    : plural(
                                          prepared.length,
                                          'lembrete',
                                          'lembretes',
                                      )
                            }}
                        </h2>
                        <p
                            class="mt-1.5 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                prepared.length === 0
                                    ? 'Escolha um cliente à esquerda e o texto aparece aqui.'
                                    : 'Reveja, mude o tom ou o texto, e só depois envie.'
                            }}
                        </p>

                        <ul
                            v-if="prepared.length > 0"
                            role="list"
                            class="mt-5 grid gap-3"
                        >
                            <li
                                v-for="entry in prepared"
                                :key="entry.id"
                                class="rounded-2xl bg-[#f5f5f7] p-4 dark:bg-white/[0.05]"
                            >
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <p
                                        class="min-w-0 truncate text-sm font-semibold text-zinc-950 dark:text-white"
                                    >
                                        {{ entry.draft.to ?? entry.row.name }}
                                    </p>
                                    <span
                                        v-if="sent.includes(entry.id)"
                                        class="rounded-full bg-lime-300 px-2.5 py-0.5 text-xs font-semibold text-lime-950"
                                        >Enviado</span
                                    >
                                    <span
                                        v-else
                                        class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-zinc-800 dark:bg-white/10 dark:text-zinc-200"
                                        >{{
                                            entry.draft.tone === 'firm'
                                                ? 'Firme'
                                                : 'Cordial'
                                        }}</span
                                    >
                                </div>

                                <p
                                    v-if="entry.draft.to === null"
                                    class="mt-3 rounded-xl bg-[#fde7ea] p-3 text-sm/6 text-[#9a1b30]"
                                    role="alert"
                                >
                                    {{ entry.row.name }} não tem email
                                    registado. Adicione um na ficha do cliente
                                    para poder enviar.
                                </p>

                                <template v-else-if="editing === entry.id">
                                    <label
                                        :for="`subject-${entry.id}`"
                                        class="mt-3 block text-xs font-medium text-zinc-600 dark:text-zinc-300"
                                        >Assunto</label
                                    >
                                    <input
                                        :id="`subject-${entry.id}`"
                                        v-model="edited[entry.id].subject"
                                        type="text"
                                        maxlength="150"
                                        class="mt-1.5 form-input"
                                    />
                                    <label
                                        :for="`body-${entry.id}`"
                                        class="mt-3 block text-xs font-medium text-zinc-600 dark:text-zinc-300"
                                        >Mensagem</label
                                    >
                                    <textarea
                                        :id="`body-${entry.id}`"
                                        v-model="edited[entry.id].body"
                                        rows="9"
                                        maxlength="4000"
                                        class="mt-1.5 form-input"
                                    />
                                </template>

                                <p
                                    v-else
                                    class="mt-3 text-[0.8125rem]/5 whitespace-pre-line text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ wordsFor(entry).body }}
                                </p>

                                <button
                                    v-if="
                                        entry.draft.to !== null &&
                                        !sent.includes(entry.id)
                                    "
                                    type="button"
                                    :class="[buttonQuiet, 'mt-1 -ml-3 h-9']"
                                    @click="startEditing(entry.id, entry.draft)"
                                >
                                    {{
                                        editing === entry.id
                                            ? 'Concluir'
                                            : 'Editar'
                                    }}
                                </button>
                            </li>
                        </ul>

                        <button
                            type="button"
                            :class="[buttonGold, 'mt-5 w-full']"
                            :disabled="toSend.length === 0 || sending"
                            @click="sendAll"
                        >
                            <LoaderCircle
                                v-if="sending"
                                class="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            {{
                                toSend.length === 0
                                    ? 'Enviar lembretes'
                                    : `Enviar ${plural(toSend.length, 'lembrete', 'lembretes')}`
                            }}
                        </button>
                        <p
                            class="mt-3 text-center text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Nada é enviado até confirmar.
                        </p>
                    </aside>
                </div>

                <!-- ------------------------------------------- ageing -->
                <section
                    class="mt-12 rounded-3xl bg-zinc-900/[0.04] p-6 dark:bg-white/[0.04]"
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
                        Quanto de cada escalão está por cobrar. A idade conta a
                        partir do vencimento acordado, não da data da factura.
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
                        <ul class="sr-only">
                            <li v-for="row in customers" :key="row.name">
                                {{ row.name }}: {{ bucketSummary(row) }}
                            </li>
                        </ul>
                    </template>
                    <p
                        v-else
                        class="mt-5 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Nada por receber. Todas as facturas estão liquidadas.
                    </p>
                </section>

                <p class="mt-6 text-xs/5 text-zinc-500 dark:text-zinc-400">
                    Valores e documentos limitados à moeda da empresa; saldos
                    actuais, sem conversão cambial.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
