<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    ChevronRight,
    CircleAlert,
    Clock3,
    Fingerprint,
    Inbox,
    LoaderCircle,
    RefreshCw,
    Send,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import PageStat from '@/components/PageStat.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, refresh } from '@/routes/agt/submissions';
import type { SelectOption } from '@/types/select';

type SubmissionStatus =
    | 'pending'
    | 'sending'
    | 'retrying'
    | 'received'
    | 'processing'
    | 'valid'
    | 'invalid'
    | 'rejected'
    | 'cancelled'
    | 'failed'
    | 'unknown';

interface Submission {
    public_id: string;
    status: SubmissionStatus;
    status_label: string;
    document_no: string;
    document_type: string;
    customer_name: string;
    gross_total: string;
    currency_code: string;
    request_id: string | null;
    attempt_count: number;
    safe_message: string;
    agt_operational: { observed_at: string | null };
    created_at: string;
    updated_at: string;
}

interface SubmissionAttempt {
    id: number;
    operation: string;
    operation_label: string;
    attempt_number: number;
    http_status: number | null;
    result_code: string | null;
    error_codes: string[];
    safe_message: string;
    request_body_sha256: string;
    response_body_sha256: string | null;
    started_at: string;
}

interface FiscalEvent {
    id: number;
    type: string;
    label: string;
    agt_document_status: string | null;
    safe_context: Record<string, unknown>;
    occurred_at: string;
}

interface SelectedSubmission extends Submission {
    submission_uuid: string;
    schema_version: string;
    request_body_sha256: string;
    last_response_body_sha256: string | null;
    last_http_status: number | null;
    last_result_code: string | null;
    last_error_codes: string[];
    series_code: string | null;
    issue_sequence: number | null;
    issued_at: string | null;
    received_at: string | null;
    completed_at: string | null;
    attempts: SubmissionAttempt[];
    events: FiscalEvent[];
}

const props = defineProps<{
    summary: Record<SubmissionStatus, number>;
    submissions: Submission[];
    selected: SelectedSubmission | null;
    permissions: { refresh: boolean };
    guardrails: {
        raw_payloads_exposed: boolean;
        maximum_visible_records: number;
    };
}>();

type Filter = 'all' | 'active' | 'valid' | 'attention';

const page = usePage();
const statusFilter = ref<Filter>('all');

const statusFilterOptions: SelectOption<Filter>[] = [
    { value: 'all', label: 'Todos' },
    { value: 'active', label: 'Em curso' },
    { value: 'valid', label: 'Validados' },
    { value: 'attention', label: 'Requer atenção' },
];
const flashSuccess = computed(() => page.props.flash.success);
const flashError = computed(() => page.props.flash.error);
const activeStatuses: SubmissionStatus[] = [
    'pending',
    'sending',
    'retrying',
    'received',
    'processing',
];
const attentionStatuses: SubmissionStatus[] = [
    'invalid',
    'rejected',
    'cancelled',
    'failed',
    'unknown',
];

const total = computed(() =>
    Object.values(props.summary).reduce((sum, count) => sum + count, 0),
);
const activeTotal = computed(() =>
    activeStatuses.reduce((sum, status) => sum + props.summary[status], 0),
);
const attentionTotal = computed(() =>
    attentionStatuses.reduce((sum, status) => sum + props.summary[status], 0),
);
const filteredSubmissions = computed(() => {
    if (statusFilter.value === 'all') {
        return props.submissions;
    }

    if (statusFilter.value === 'active') {
        return props.submissions.filter((submission) =>
            activeStatuses.includes(submission.status),
        );
    }

    if (statusFilter.value === 'attention') {
        return props.submissions.filter((submission) =>
            attentionStatuses.includes(submission.status),
        );
    }

    return props.submissions.filter(
        (submission) => submission.status === 'valid',
    );
});

function statusTone(
    status: SubmissionStatus,
): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
    if (status === 'valid') {
        return 'success';
    }

    if (attentionStatuses.includes(status)) {
        return 'danger';
    }

    if (status === 'retrying') {
        return 'warning';
    }

    return activeStatuses.includes(status) ? 'info' : 'neutral';
}

function formatDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-AO', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Africa/Luanda',
    }).format(new Date(value));
}

function formatMoney(value: string, currencyCode: string): string {
    const [whole = '0', decimal = '00'] = value.split('.');
    const amount = new Intl.NumberFormat('pt-AO', {
        maximumFractionDigits: 0,
    }).format(BigInt(whole));
    const symbol = currencyCode === 'AOA' ? 'Kz' : currencyCode;

    return amount + ',' + decimal.padEnd(2, '0').slice(0, 2) + ' ' + symbol;
}

function abbreviate(value: string | null): string {
    if (value === null || value.length <= 24) {
        return value ?? '—';
    }

    return value.slice(0, 12) + '…' + value.slice(-8);
}
</script>

<template>
    <AppLayout>
        <Head title="Monitor AGT" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-[100rem] space-y-7">
                <PageHeader
                    eyebrow="AGT · Monitor"
                    title="Onde está cada factura"
                    description="Acompanhe cada documento desde a fila segura até ao resultado final devolvido pela AGT, sem repetir uma emissão."
                >
                    <template #actions>
                        <Form
                            v-if="permissions.refresh"
                            v-bind="refresh.form()"
                            :options="{ preserveScroll: true }"
                            #default="{ processing }"
                        >
                            <button
                                type="submit"
                                :disabled="processing || activeTotal === 0"
                                class="inline-flex h-10 items-center justify-center gap-2 rounded-full bg-white px-[1.125rem] text-sm font-semibold whitespace-nowrap text-zinc-900 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:hover:bg-white/10"
                            >
                                <LoaderCircle
                                    v-if="processing"
                                    class="size-4 animate-spin"
                                    aria-hidden="true"
                                />
                                <RefreshCw
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Actualizar estados
                            </button>
                        </Form>
                    </template>
                    <template #stats>
                        <PageStat
                            label="Validadas pela AGT"
                            :value="summary.valid"
                        />
                        <PageStat
                            label="Na fila ou em validação"
                            :value="activeTotal"
                        />
                        <PageStat
                            label="Requerem atenção"
                            :value="attentionTotal"
                        >
                            <span
                                :class="
                                    attentionTotal > 0
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : ''
                                "
                                >{{ attentionTotal }}</span
                            >
                        </PageStat>
                        <PageStat
                            label="Registos preservados"
                            :value="total"
                            detail="Todas as entregas ficam guardadas"
                        />
                    </template>
                </PageHeader>

                <div
                    v-if="flashSuccess"
                    class="flex items-center gap-3 rounded-2xl bg-emerald-50 p-4 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20"
                    role="status"
                >
                    <BadgeCheck class="size-5 shrink-0" aria-hidden="true" />
                    {{ flashSuccess }}
                </div>
                <div
                    v-if="flashError"
                    class="flex items-center gap-3 rounded-2xl bg-rose-50 p-4 text-sm font-medium text-rose-800 ring-1 ring-rose-200 dark:bg-rose-400/10 dark:text-rose-300 dark:ring-rose-400/20"
                    role="alert"
                >
                    <CircleAlert class="size-5 shrink-0" aria-hidden="true" />
                    {{ flashError }}
                </div>

                <div
                    class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.45fr)_minmax(22rem,0.75fr)]"
                >
                    <section
                        class="min-w-0 overflow-hidden rounded-2xl surface"
                    >
                        <div class="px-5 py-5 sm:px-6">
                            <div class="sm:flex sm:items-center">
                                <div class="sm:flex-auto">
                                    <h2
                                        class="text-base font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Documentos recentes
                                    </h2>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Até
                                        {{ guardrails.maximum_visible_records }}
                                        entregas recentes, sem expor payloads ou
                                        assinaturas.
                                    </p>
                                </div>
                                <div class="mt-4 sm:mt-0 sm:ml-8">
                                    <label for="status-filter" class="sr-only">
                                        Filtrar por estado
                                    </label>
                                    <SelectInput
                                        id="status-filter"
                                        v-model="statusFilter"
                                        :options="statusFilterOptions"
                                    />
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="filteredSubmissions.length > 0"
                            class="flow-root"
                        >
                            <div
                                class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8"
                            >
                                <div
                                    class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8"
                                >
                                    <table
                                        class="min-w-full divide-y divide-zinc-200 dark:divide-white/10"
                                    >
                                        <thead
                                            class="bg-zinc-50 dark:bg-white/[0.03]"
                                        >
                                            <tr>
                                                <th
                                                    scope="col"
                                                    class="py-3.5 pr-3 pl-5 text-left text-xs font-semibold text-zinc-600 sm:pl-8 dark:text-zinc-300"
                                                >
                                                    Documento
                                                </th>
                                                <th
                                                    scope="col"
                                                    class="hidden px-3 py-3.5 text-left text-xs font-semibold text-zinc-600 md:table-cell dark:text-zinc-300"
                                                >
                                                    Cliente
                                                </th>
                                                <th
                                                    scope="col"
                                                    class="px-3 py-3.5 text-left text-xs font-semibold text-zinc-600 dark:text-zinc-300"
                                                >
                                                    Estado
                                                </th>
                                                <th
                                                    scope="col"
                                                    class="hidden px-3 py-3.5 text-right text-xs font-semibold text-zinc-600 sm:table-cell dark:text-zinc-300"
                                                >
                                                    Total
                                                </th>
                                                <th
                                                    scope="col"
                                                    class="relative py-3.5 pr-5 pl-3 sm:pr-8"
                                                >
                                                    <span class="sr-only"
                                                        >Abrir</span
                                                    >
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody
                                            class="divide-y divide-zinc-100 dark:divide-white/10"
                                        >
                                            <tr
                                                v-for="submission in filteredSubmissions"
                                                :key="submission.public_id"
                                                :class="
                                                    selected?.public_id ===
                                                    submission.public_id
                                                        ? 'bg-brand-50/70 dark:bg-brand-400/[0.06]'
                                                        : ''
                                                "
                                            >
                                                <td
                                                    class="py-4 pr-3 pl-5 sm:pl-8"
                                                >
                                                    <p
                                                        class="text-sm font-semibold whitespace-nowrap text-zinc-950 dark:text-white"
                                                    >
                                                        {{
                                                            submission.document_no
                                                        }}
                                                    </p>
                                                    <p
                                                        class="mt-1 text-xs whitespace-nowrap text-zinc-500 dark:text-zinc-400"
                                                    >
                                                        {{
                                                            formatDate(
                                                                submission.created_at,
                                                            )
                                                        }}
                                                    </p>
                                                </td>
                                                <td
                                                    class="hidden max-w-56 px-3 py-4 text-sm text-zinc-600 md:table-cell dark:text-zinc-300"
                                                >
                                                    <span
                                                        class="block truncate"
                                                    >
                                                        {{
                                                            submission.customer_name
                                                        }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-4">
                                                    <StatusBadge
                                                        :label="
                                                            submission.status_label
                                                        "
                                                        :tone="
                                                            statusTone(
                                                                submission.status,
                                                            )
                                                        "
                                                        :pulse="
                                                            activeStatuses.includes(
                                                                submission.status,
                                                            )
                                                        "
                                                    />
                                                </td>
                                                <td
                                                    class="hidden px-3 py-4 text-right font-mono text-sm font-semibold whitespace-nowrap text-zinc-900 sm:table-cell dark:text-white"
                                                >
                                                    {{
                                                        formatMoney(
                                                            submission.gross_total,
                                                            submission.currency_code,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="py-4 pr-5 pl-3 text-right sm:pr-8"
                                                >
                                                    <Link
                                                        :href="
                                                            index({
                                                                query: {
                                                                    submission:
                                                                        submission.public_id,
                                                                },
                                                            })
                                                        "
                                                        preserve-scroll
                                                        class="inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300"
                                                    >
                                                        Abrir
                                                        <ChevronRight
                                                            class="size-4"
                                                            aria-hidden="true"
                                                        />
                                                    </Link>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div v-else class="px-6 py-16 text-center">
                            <Inbox
                                class="mx-auto size-9 text-zinc-300 dark:text-zinc-600"
                                aria-hidden="true"
                            />
                            <h3
                                class="mt-4 text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                Nenhuma submissão neste filtro
                            </h3>
                            <p
                                class="mx-auto mt-1 max-w-sm text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                Os documentos aparecem aqui assim que uma
                                factura é emitida e colocada na fila AGT.
                            </p>
                        </div>
                    </section>

                    <aside
                        v-if="selected"
                        class="space-y-5 xl:sticky xl:top-24 xl:self-start"
                    >
                        <section
                            class="overflow-hidden rounded-2xl bg-brand-950 text-white shadow-sm ring-1 ring-white/10"
                        >
                            <div class="border-b border-white/10 p-5">
                                <div
                                    class="flex items-start justify-between gap-4"
                                >
                                    <div>
                                        <p class="eyebrow text-brand-100/55">
                                            Entrega seleccionada
                                        </p>
                                        <h2
                                            class="mt-1 numeric text-2xl font-semibold tracking-tight"
                                        >
                                            {{ selected.document_no }}
                                        </h2>
                                    </div>
                                    <StatusBadge
                                        :label="selected.status_label"
                                        :tone="statusTone(selected.status)"
                                        :pulse="
                                            activeStatuses.includes(
                                                selected.status,
                                            )
                                        "
                                    />
                                </div>
                                <p class="mt-4 text-sm/6 text-brand-100/70">
                                    {{ selected.safe_message }}
                                    <span
                                        v-if="
                                            selected.agt_operational.observed_at
                                        "
                                    >
                                        Observação:
                                        {{
                                            formatDate(
                                                selected.agt_operational
                                                    .observed_at,
                                            )
                                        }}.
                                    </span>
                                </p>
                            </div>
                            <dl
                                class="grid grid-cols-2 gap-px bg-white/10 text-sm"
                            >
                                <div class="bg-white/[0.06] p-4">
                                    <dt class="text-xs text-brand-100/55">
                                        Pedido AGT
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono text-xs font-semibold"
                                    >
                                        {{
                                            selected.request_id ?? 'A aguardar'
                                        }}
                                    </dd>
                                </div>
                                <div class="bg-white/[0.06] p-4">
                                    <dt class="text-xs text-brand-100/55">
                                        Tentativas
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ selected.attempt_count }}
                                    </dd>
                                </div>
                                <div class="bg-white/[0.06] p-4">
                                    <dt class="text-xs text-brand-100/55">
                                        Série
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ selected.series_code ?? '—' }}
                                    </dd>
                                </div>
                                <div class="bg-white/[0.06] p-4">
                                    <dt class="text-xs text-brand-100/55">
                                        Contrato
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        AGT {{ selected.schema_version }}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <section class="rounded-2xl surface p-5">
                            <div class="flex items-center gap-2">
                                <Clock3
                                    class="size-4 text-brand-700 dark:text-brand-300"
                                    aria-hidden="true"
                                />
                                <h2
                                    class="text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    Linha temporal fiscal
                                </h2>
                            </div>
                            <ol
                                v-if="selected.events.length > 0"
                                class="mt-5 space-y-0"
                            >
                                <li
                                    v-for="(
                                        event, eventIndex
                                    ) in selected.events"
                                    :key="event.id"
                                    class="relative flex gap-3 pb-5 last:pb-0"
                                >
                                    <span
                                        v-if="
                                            eventIndex <
                                            selected.events.length - 1
                                        "
                                        class="absolute top-6 left-2.5 h-full w-px bg-zinc-200 dark:bg-white/10"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="relative mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-brand-100 text-brand-700 ring-4 ring-white dark:bg-brand-400/15 dark:text-brand-300 dark:ring-zinc-900"
                                    >
                                        <span
                                            class="size-1.5 rounded-full bg-current"
                                        />
                                    </span>
                                    <div class="min-w-0">
                                        <p
                                            class="text-sm font-semibold text-zinc-900 dark:text-white"
                                        >
                                            {{ event.label }}
                                        </p>
                                        <p
                                            class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ formatDate(event.occurred_at) }}
                                        </p>
                                    </div>
                                </li>
                            </ol>
                            <p
                                v-else
                                class="mt-4 text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                A primeira evidência será apresentada após a
                                emissão.
                            </p>
                        </section>

                        <section class="rounded-2xl surface p-5">
                            <div class="flex items-center gap-2">
                                <Send
                                    class="size-4 text-brand-700 dark:text-brand-300"
                                    aria-hidden="true"
                                />
                                <h2
                                    class="text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    Últimas comunicações
                                </h2>
                            </div>
                            <ul
                                v-if="selected.attempts.length > 0"
                                class="mt-4 divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <li
                                    v-for="attempt in selected.attempts"
                                    :key="attempt.id"
                                    class="py-4 first:pt-0 last:pb-0"
                                >
                                    <div
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <div>
                                            <p
                                                class="text-xs font-semibold text-zinc-900 dark:text-white"
                                            >
                                                {{ attempt.operation_label }} ·
                                                #{{ attempt.attempt_number }}
                                            </p>
                                            <p
                                                class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                                            >
                                                {{ attempt.safe_message }}
                                            </p>
                                        </div>
                                        <span
                                            class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            HTTP
                                            {{ attempt.http_status ?? '—' }}
                                        </span>
                                    </div>
                                    <p
                                        v-if="attempt.error_codes.length > 0"
                                        class="mt-2 font-mono text-[0.65rem] text-rose-600 dark:text-rose-400"
                                    >
                                        {{ attempt.error_codes.join(', ') }}
                                    </p>
                                </li>
                            </ul>
                            <p
                                v-else
                                class="mt-4 text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                A entrega ainda não iniciou uma comunicação.
                            </p>
                        </section>

                        <section
                            class="rounded-2xl bg-zinc-50 p-5 ring-1 ring-zinc-200 dark:bg-white/[0.03] dark:ring-white/10"
                        >
                            <div class="flex items-center gap-2">
                                <Fingerprint
                                    class="size-4 text-zinc-500"
                                    aria-hidden="true"
                                />
                                <h2
                                    class="eyebrow text-zinc-600 dark:text-zinc-300"
                                >
                                    Prova de integridade
                                </h2>
                            </div>
                            <dl
                                class="mt-4 space-y-3 font-mono text-[0.65rem] text-zinc-500 dark:text-zinc-400"
                            >
                                <div>
                                    <dt>SUBMISSÃO</dt>
                                    <dd
                                        class="mt-0.5 break-all text-zinc-800 dark:text-zinc-200"
                                    >
                                        {{ selected.submission_uuid }}
                                    </dd>
                                </div>
                                <div>
                                    <dt>SHA-256 PEDIDO</dt>
                                    <dd
                                        class="mt-0.5 text-zinc-800 dark:text-zinc-200"
                                    >
                                        {{
                                            abbreviate(
                                                selected.request_body_sha256,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div v-if="selected.last_response_body_sha256">
                                    <dt>SHA-256 RESPOSTA</dt>
                                    <dd
                                        class="mt-0.5 text-zinc-800 dark:text-zinc-200"
                                    >
                                        {{
                                            abbreviate(
                                                selected.last_response_body_sha256,
                                            )
                                        }}
                                    </dd>
                                </div>
                            </dl>
                            <p
                                class="mt-4 text-xs/5 text-zinc-500 dark:text-zinc-400"
                            >
                                Os bytes assinados permanecem cifrados e não são
                                enviados ao navegador.
                            </p>
                        </section>
                    </aside>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
