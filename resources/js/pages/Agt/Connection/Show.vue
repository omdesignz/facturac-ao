<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    ArrowUpRight,
    BadgeCheck,
    Ban,
    Check,
    CircleDashed,
    Clock3,
    DatabaseZap,
    Fingerprint,
    FilePlus2,
    KeyRound,
    Landmark,
    LoaderCircle,
    LockKeyhole,
    Network,
    RefreshCw,
    ServerCog,
    ShieldCheck,
    ShieldOff,
    TriangleAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import FormError from '@/components/FormError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { update } from '@/routes/agt/connection';
import { store as runConnectionCheck } from '@/routes/agt/connection-checks';
import {
    store as requestSeries,
    sync as syncSeries,
} from '@/routes/agt/series';
import { security } from '@/routes/settings';

interface LegalEntity {
    legal_name: string;
    tax_identification_number: string | null;
    establishment_name: string | null;
}

interface AgtConnection {
    public_id: string | null;
    environment: string;
    environment_label: string;
    endpoint_host: string | null;
    schema_version: string;
    status: string;
    status_label: string;
    has_basic_credentials: boolean;
    credential_identity: string | null;
    product_id: string;
    product_version: string;
    software_validation_number: string;
    establishment_number: string;
    software_key_reference: string;
    software_key_fingerprint: string | null;
    taxpayer_key_reference: string;
    taxpayer_key_fingerprint: string | null;
    configured_at: string | null;
    verified_at: string | null;
}

interface ReadinessItem {
    key: string;
    label: string;
    detail: string;
    done: boolean;
}

interface ConnectionCheck {
    public_id: string;
    operation_label: string;
    status: 'running' | 'passed' | 'failed';
    status_label: string;
    http_status: number | null;
    result_code: string | null;
    error_codes: string[];
    safe_message: string;
    duration_ms: number | null;
    request_fingerprint: string | null;
    started_at: string;
}

interface FiscalSeries {
    public_id: string;
    series_code: string;
    series_year: number;
    document_type: string;
    document_type_label: string;
    status: 'A' | 'U' | 'F';
    status_label: string;
    contingency: string;
    contingency_label: string;
    invoicing_method: string;
    next_number: number;
    last_authorized_number: number;
    remaining_numbers: number;
    synchronized_at: string;
}

interface SeriesRequestOptions {
    document_types: Array<{ value: string; label: string }>;
    years: number[];
    default_document_type: string;
}

const props = defineProps<{
    legalEntity: LegalEntity;
    connection: AgtConnection;
    readiness: { complete: boolean; items: ReadinessItem[] };
    checks: ConnectionCheck[];
    series: FiscalSeries[];
    seriesRequest: SeriesRequestOptions;
    permissions: {
        manage: boolean;
        test: boolean;
        request_series: boolean;
        sync_series: boolean;
    };
    guardrails: {
        production_enabled: boolean;
        mfa_enabled: boolean;
        private_keys_in_database: boolean;
    };
}>();

const page = usePage();
const flashSuccess = computed(() => page.props.flash.success);
const flashError = computed(() => page.props.flash.error);
const completedRequirements = computed(
    () => props.readiness.items.filter((item) => item.done).length,
);

function statusTone(
    status: string,
): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
    if (status === 'verified' || status === 'passed') {
        return 'success';
    }

    if (status === 'failed') {
        return 'danger';
    }

    if (status === 'ready' || status === 'running') {
        return 'info';
    }

    return 'warning';
}

function seriesTone(
    status: FiscalSeries['status'],
): 'success' | 'warning' | 'neutral' {
    if (status === 'U') {
        return 'success';
    }

    return status === 'A' ? 'warning' : 'neutral';
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
</script>

<template>
    <AppLayout>
        <Head title="Ligação AGT" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-[100rem] space-y-8">
                <section
                    class="relative overflow-hidden rounded-3xl bg-brand-950 px-6 py-7 text-white shadow-[0_28px_80px_-45px_rgba(8,40,32,0.9)] sm:px-8 sm:py-9"
                >
                    <div
                        class="absolute -top-32 -right-20 size-96 rounded-full bg-sky-300/10 blur-3xl"
                        aria-hidden="true"
                    />
                    <div
                        class="relative flex flex-col justify-between gap-8 xl:flex-row xl:items-end"
                    >
                        <div class="max-w-3xl">
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    :label="connection.status_label"
                                    :tone="statusTone(connection.status)"
                                    :pulse="connection.status === 'ready'"
                                />
                                <span
                                    class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-xs font-semibold text-brand-100/75"
                                >
                                    {{ connection.environment_label }} · schema
                                    {{ connection.schema_version }}
                                </span>
                            </div>
                            <h1
                                class="mt-5 text-4xl leading-[1.05] display sm:text-5xl"
                            >
                                A sua ligação à AGT.<br />
                                <span class="text-accent-400"
                                    >Testar antes de valer.</span
                                >
                            </h1>
                            <p
                                class="mt-4 max-w-2xl text-sm/6 text-brand-100/70 sm:text-base/7"
                            >
                                Configure as credenciais, confirme a custódia
                                das chaves e sincronize as séries fiscais
                                autorizadas. A emissão continua separada e exige
                                uma confirmação explícita.
                            </p>
                        </div>
                        <div
                            class="grid min-w-[18rem] grid-cols-2 gap-px overflow-hidden rounded-2xl bg-white/10 ring-1 ring-white/10"
                        >
                            <div class="bg-white/[0.06] p-4">
                                <p class="text-xs text-brand-100/60">
                                    Requisitos
                                </p>
                                <p
                                    class="mt-1 numeric text-2xl font-semibold tracking-tight"
                                >
                                    {{ completedRequirements }}/{{
                                        readiness.items.length
                                    }}
                                </p>
                            </div>
                            <div class="bg-white/[0.06] p-4">
                                <p class="text-xs text-brand-100/60">
                                    Última verificação
                                </p>
                                <p class="mt-1 text-sm font-semibold">
                                    {{ formatDate(connection.verified_at) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <div
                    v-if="flashSuccess"
                    class="flex items-center gap-3 rounded-2xl bg-emerald-50 p-4 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20"
                    role="status"
                >
                    <Check class="size-5 shrink-0" aria-hidden="true" />
                    {{ flashSuccess }}
                </div>
                <div
                    v-if="flashError"
                    class="flex items-center gap-3 rounded-2xl bg-rose-50 p-4 text-sm font-medium text-rose-800 ring-1 ring-rose-200 dark:bg-rose-400/10 dark:text-rose-300 dark:ring-rose-400/20"
                    role="alert"
                >
                    <TriangleAlert class="size-5 shrink-0" aria-hidden="true" />
                    {{ flashError }}
                </div>

                <section class="grid gap-4 md:grid-cols-3">
                    <article class="rounded-2xl surface p-5">
                        <div class="flex items-center justify-between gap-3">
                            <span
                                class="grid size-10 place-items-center rounded-xl bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300"
                            >
                                <Network class="size-5" aria-hidden="true" />
                            </span>
                            <StatusBadge label="Só homologação" tone="info" />
                        </div>
                        <p
                            class="mt-5 text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            {{ connection.endpoint_host }}
                        </p>
                        <p
                            class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                        >
                            Produção bloqueada até certificação e autorização
                            explícita.
                        </p>
                    </article>
                    <article class="rounded-2xl surface p-5">
                        <div class="flex items-center justify-between gap-3">
                            <span
                                class="grid size-10 place-items-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300"
                            >
                                <DatabaseZap
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <StatusBadge label="Cifrado" tone="success" />
                        </div>
                        <p
                            class="mt-5 text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Credenciais protegidas
                        </p>
                        <p
                            class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                connection.credential_identity ??
                                'Ainda não configuradas'
                            }}
                        </p>
                    </article>
                    <article class="rounded-2xl surface p-5">
                        <div class="flex items-center justify-between gap-3">
                            <span
                                class="grid size-10 place-items-center rounded-xl bg-amber-50 text-amber-800 dark:bg-amber-400/10 dark:text-amber-300"
                            >
                                <KeyRound class="size-5" aria-hidden="true" />
                            </span>
                            <StatusBadge
                                label="Fora da base de dados"
                                tone="warning"
                            />
                        </div>
                        <p
                            class="mt-5 text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Chaves sob custódia local
                        </p>
                        <p
                            class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                        >
                            Apenas referências e impressões SHA-256 são
                            persistidas.
                        </p>
                    </article>
                </section>

                <div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_24rem]">
                    <Form
                        v-bind="update.form()"
                        :options="{ preserveScroll: true }"
                        :reset-on-success="[
                            'basic_auth_username',
                            'basic_auth_password',
                        ]"
                        :reset-on-error="['basic_auth_password']"
                        class="space-y-8"
                        #default="{ errors, processing, recentlySuccessful }"
                    >
                        <section class="rounded-2xl surface">
                            <div
                                class="flex gap-4 border-b border-zinc-100 p-5 sm:p-7 dark:border-white/10"
                            >
                                <span
                                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                                >
                                    <LockKeyhole
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <h2
                                        class="font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Acesso ao serviço AGT
                                    </h2>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        A palavra-passe nunca regressa ao
                                        navegador. Deixe os campos em branco
                                        para conservar as credenciais
                                        existentes.
                                    </p>
                                </div>
                            </div>
                            <fieldset
                                :disabled="!permissions.manage || processing"
                                class="grid grid-cols-1 gap-x-6 gap-y-6 p-5 disabled:opacity-65 sm:grid-cols-6 sm:p-7"
                            >
                                <div class="sm:col-span-3">
                                    <label
                                        for="agt-username"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Utilizador AGT</label
                                    >
                                    <input
                                        id="agt-username"
                                        name="basic_auth_username"
                                        type="text"
                                        autocomplete="off"
                                        :required="
                                            !connection.has_basic_credentials
                                        "
                                        :placeholder="
                                            connection.credential_identity ??
                                            'Utilizador de homologação'
                                        "
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 placeholder:text-zinc-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="errors.basic_auth_username"
                                    />
                                </div>
                                <div class="sm:col-span-3">
                                    <label
                                        for="agt-password"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Palavra-passe / token</label
                                    >
                                    <input
                                        id="agt-password"
                                        name="basic_auth_password"
                                        type="password"
                                        autocomplete="new-password"
                                        :required="
                                            !connection.has_basic_credentials
                                        "
                                        :placeholder="
                                            connection.has_basic_credentials
                                                ? '••••••••••••••••'
                                                : 'Credencial fornecida pela AGT'
                                        "
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 placeholder:text-zinc-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="errors.basic_auth_password"
                                    />
                                </div>
                            </fieldset>
                        </section>

                        <section class="rounded-2xl surface">
                            <div
                                class="flex gap-4 border-b border-zinc-100 p-5 sm:p-7 dark:border-white/10"
                            >
                                <span
                                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300"
                                >
                                    <ServerCog
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <h2
                                        class="font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Identidade do software
                                    </h2>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Dados assinados no contrato AGT e
                                        identificador do estabelecimento
                                        emissor.
                                    </p>
                                </div>
                            </div>
                            <fieldset
                                :disabled="!permissions.manage || processing"
                                class="grid grid-cols-1 gap-x-6 gap-y-6 p-5 disabled:opacity-65 sm:grid-cols-6 sm:p-7"
                            >
                                <div class="sm:col-span-3">
                                    <label
                                        for="product-id"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Nome do produto</label
                                    >
                                    <input
                                        id="product-id"
                                        name="product_id"
                                        type="text"
                                        required
                                        :value="connection.product_id"
                                        placeholder="facturac.ao"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError :message="errors.product_id" />
                                </div>
                                <div class="sm:col-span-3">
                                    <label
                                        for="product-version"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Versão</label
                                    >
                                    <input
                                        id="product-version"
                                        name="product_version"
                                        type="text"
                                        required
                                        :value="connection.product_version"
                                        placeholder="1.0.0"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="errors.product_version"
                                    />
                                </div>
                                <div class="sm:col-span-3">
                                    <label
                                        for="validation-number"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Número de validação</label
                                    >
                                    <input
                                        id="validation-number"
                                        name="software_validation_number"
                                        type="text"
                                        required
                                        :value="
                                            connection.software_validation_number
                                        "
                                        placeholder="Número atribuído ao software"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="
                                            errors.software_validation_number
                                        "
                                    />
                                </div>
                                <div class="sm:col-span-3">
                                    <label
                                        for="establishment-number"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Número do estabelecimento AGT</label
                                    >
                                    <input
                                        id="establishment-number"
                                        name="establishment_number"
                                        type="text"
                                        required
                                        :value="connection.establishment_number"
                                        placeholder="Identificador da sede na AGT"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="errors.establishment_number"
                                    />
                                </div>
                            </fieldset>
                        </section>

                        <section class="rounded-2xl surface">
                            <div
                                class="flex gap-4 border-b border-zinc-100 p-5 sm:p-7 dark:border-white/10"
                            >
                                <span
                                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-100 text-amber-800 dark:bg-amber-400/10 dark:text-amber-300"
                                >
                                    <Fingerprint
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <h2
                                        class="font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Referências das chaves
                                    </h2>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Indique nomes relativos no cofre. Não
                                        cole PEM, conteúdo de chave ou caminhos
                                        absolutos.
                                    </p>
                                </div>
                            </div>
                            <fieldset
                                :disabled="!permissions.manage || processing"
                                class="grid grid-cols-1 gap-x-6 gap-y-6 p-5 disabled:opacity-65 sm:grid-cols-6 sm:p-7"
                            >
                                <div class="sm:col-span-3">
                                    <label
                                        for="software-key"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Chave privada do software</label
                                    >
                                    <input
                                        id="software-key"
                                        name="software_key_reference"
                                        type="text"
                                        required
                                        :value="
                                            connection.software_key_reference
                                        "
                                        placeholder="software/vap-hml"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="errors.software_key_reference"
                                    />
                                    <p
                                        v-if="
                                            connection.software_key_fingerprint
                                        "
                                        class="mt-2 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        SHA-256
                                        {{
                                            connection.software_key_fingerprint
                                        }}
                                    </p>
                                </div>
                                <div class="sm:col-span-3">
                                    <label
                                        for="taxpayer-key"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Chave privada do contribuinte</label
                                    >
                                    <input
                                        id="taxpayer-key"
                                        name="taxpayer_key_reference"
                                        type="text"
                                        required
                                        :value="
                                            connection.taxpayer_key_reference
                                        "
                                        placeholder="taxpayer/nif-hml"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="errors.taxpayer_key_reference"
                                    />
                                    <p
                                        v-if="
                                            connection.taxpayer_key_fingerprint
                                        "
                                        class="mt-2 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        SHA-256
                                        {{
                                            connection.taxpayer_key_fingerprint
                                        }}
                                    </p>
                                </div>
                            </fieldset>
                        </section>

                        <div
                            class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end"
                        >
                            <span
                                v-if="recentlySuccessful"
                                class="inline-flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-300"
                            >
                                <BadgeCheck class="size-4" aria-hidden="true" />
                                Guardado
                            </span>
                            <button
                                type="submit"
                                :disabled="!permissions.manage || processing"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-5 py-3 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-brand-500 dark:text-brand-950 dark:hover:bg-brand-400"
                            >
                                <LoaderCircle
                                    v-if="processing"
                                    class="size-4 animate-spin"
                                    aria-hidden="true"
                                />
                                <ShieldCheck
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Guardar configuração
                            </button>
                        </div>
                    </Form>

                    <aside class="space-y-6 xl:sticky xl:top-24 xl:self-start">
                        <section class="rounded-2xl surface p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p
                                        class="eyebrow text-brand-700 dark:text-brand-300"
                                    >
                                        Antes de ligar
                                    </p>
                                    <h2
                                        class="mt-1 font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Verificação local
                                    </h2>
                                </div>
                                <span
                                    class="numeric text-2xl font-semibold tracking-tight text-brand-700 dark:text-brand-300"
                                >
                                    {{ completedRequirements }}/{{
                                        readiness.items.length
                                    }}
                                </span>
                            </div>
                            <ul
                                role="list"
                                class="mt-5 divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <li
                                    v-for="item in readiness.items"
                                    :key="item.key"
                                    class="flex gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <BadgeCheck
                                        v-if="item.done"
                                        class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                        aria-hidden="true"
                                    />
                                    <CircleDashed
                                        v-else
                                        class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <p
                                            class="text-sm font-semibold text-zinc-900 dark:text-white"
                                        >
                                            {{ item.label }}
                                        </p>
                                        <p
                                            class="mt-0.5 text-xs/5 text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ item.detail }}
                                        </p>
                                    </div>
                                </li>
                            </ul>
                            <Form
                                v-if="
                                    permissions.test && guardrails.mfa_enabled
                                "
                                v-bind="runConnectionCheck.form()"
                                :options="{ preserveScroll: true }"
                                class="mt-5"
                                #default="{ processing }"
                            >
                                <button
                                    type="submit"
                                    :disabled="
                                        !readiness.complete || processing
                                    "
                                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent-400 px-4 py-3 text-sm font-semibold text-brand-950 shadow-sm focus-ring-inverted transition hover:bg-accent-300 disabled:cursor-not-allowed disabled:opacity-50"
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
                                    Testar ligação AGT
                                </button>
                            </Form>
                            <Link
                                v-else-if="permissions.test"
                                :href="security.url()"
                                class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent-400 px-4 py-3 text-sm font-semibold text-brand-950 transition hover:bg-accent-300"
                            >
                                <LockKeyhole
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Activar MFA para continuar
                            </Link>
                            <p
                                v-else
                                class="mt-5 rounded-xl bg-zinc-50 p-3 text-xs/5 text-zinc-500 dark:bg-white/5 dark:text-zinc-400"
                            >
                                O seu papel permite consulta, mas não alteração
                                ou teste desta ligação.
                            </p>
                        </section>

                        <section
                            class="rounded-2xl bg-brand-50 p-5 ring-1 ring-brand-200/70 dark:bg-brand-400/[0.06] dark:ring-brand-400/15"
                        >
                            <div
                                class="flex items-center gap-2 text-sm font-semibold text-brand-950 dark:text-white"
                            >
                                <ShieldOff
                                    class="size-4 text-brand-700 dark:text-brand-300"
                                    aria-hidden="true"
                                />
                                Barreiras activas
                            </div>
                            <ul
                                role="list"
                                class="mt-4 space-y-3 text-xs/5 text-brand-900/70 dark:text-brand-100/65"
                            >
                                <li class="flex gap-2">
                                    <Ban
                                        class="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Nenhuma factura é emitida a partir desta
                                    página.
                                </li>
                                <li class="flex gap-2">
                                    <Ban
                                        class="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Cada repetição de entrega reutiliza
                                    exactamente os mesmos bytes assinados.
                                </li>
                                <li class="flex gap-2">
                                    <Ban
                                        class="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    A produção abre depois de a AGT validar a
                                    homologação.
                                </li>
                            </ul>
                            <a
                                href="https://portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica/1/index.html"
                                target="_blank"
                                rel="noreferrer"
                                class="mt-5 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300"
                            >
                                Documentação oficial
                                <ArrowUpRight
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                            </a>
                        </section>
                    </aside>
                </div>

                <section class="overflow-hidden rounded-2xl surface">
                    <div
                        class="flex flex-col gap-4 border-b border-zinc-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7 dark:border-white/10"
                    >
                        <div>
                            <div class="flex items-center gap-2">
                                <DatabaseZap
                                    class="size-5 text-brand-700 dark:text-brand-300"
                                    aria-hidden="true"
                                />
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Séries autorizadas
                                </h2>
                            </div>
                            <p
                                class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                O próximo número local nunca recua quando a
                                lista é novamente sincronizada.
                            </p>
                        </div>
                        <Form
                            v-if="
                                permissions.sync_series &&
                                guardrails.mfa_enabled
                            "
                            v-bind="syncSeries.form()"
                            :options="{ preserveScroll: true }"
                            #default="{ processing }"
                        >
                            <button
                                type="submit"
                                :disabled="processing"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-3.5 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-brand-500 dark:hover:bg-brand-400"
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
                                Sincronizar séries
                            </button>
                        </Form>
                        <Link
                            v-else-if="permissions.sync_series"
                            :href="security.url()"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-accent-400 px-3.5 py-2.5 text-sm font-semibold text-brand-950 transition hover:bg-accent-300"
                        >
                            <LockKeyhole class="size-4" aria-hidden="true" />
                            Activar MFA
                        </Link>
                    </div>

                    <Form
                        v-if="
                            permissions.request_series && guardrails.mfa_enabled
                        "
                        v-bind="requestSeries.form()"
                        :options="{ preserveScroll: true }"
                        class="border-b border-zinc-100 bg-zinc-50/70 px-5 py-5 sm:px-7 dark:border-white/10 dark:bg-white/[0.025]"
                        #default="{ errors, processing }"
                    >
                        <div
                            class="grid gap-5 lg:grid-cols-[minmax(14rem,1fr)_minmax(11rem,16rem)_minmax(8rem,11rem)_auto] lg:items-end"
                        >
                            <div class="flex gap-3">
                                <span
                                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-accent-100 text-brand-800 dark:bg-accent-300/10 dark:text-accent-300"
                                >
                                    <FilePlus2
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <p
                                        class="text-sm font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Pedir uma nova série
                                    </p>
                                    <p
                                        class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Cria numeração fiscal na AGT de
                                        homologação e importa-a de seguida.
                                    </p>
                                </div>
                            </div>
                            <div>
                                <label
                                    for="series-document-type"
                                    class="block text-xs font-semibold text-zinc-600 dark:text-zinc-300"
                                >
                                    Tipo de documento
                                </label>
                                <select
                                    id="series-document-type"
                                    name="document_type"
                                    required
                                    :disabled="processing"
                                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 disabled:opacity-60 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                >
                                    <option
                                        v-for="documentType in seriesRequest.document_types"
                                        :key="documentType.value"
                                        :value="documentType.value"
                                        :selected="
                                            documentType.value ===
                                            seriesRequest.default_document_type
                                        "
                                    >
                                        {{ documentType.value }} ·
                                        {{ documentType.label }}
                                    </option>
                                </select>
                                <FormError :message="errors.document_type" />
                            </div>
                            <div>
                                <label
                                    for="series-year"
                                    class="block text-xs font-semibold text-zinc-600 dark:text-zinc-300"
                                >
                                    Ano
                                </label>
                                <select
                                    id="series-year"
                                    name="series_year"
                                    required
                                    :disabled="processing"
                                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 disabled:opacity-60 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                >
                                    <option
                                        v-for="seriesYear in seriesRequest.years"
                                        :key="seriesYear"
                                        :value="seriesYear"
                                    >
                                        {{ seriesYear }}
                                    </option>
                                </select>
                                <FormError :message="errors.series_year" />
                            </div>
                            <button
                                type="submit"
                                :disabled="processing"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-accent-400 px-4 py-2.5 text-sm font-semibold text-brand-950 shadow-sm focus-ring transition hover:bg-accent-300 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-300 dark:hover:bg-accent-200"
                            >
                                <LoaderCircle
                                    v-if="processing"
                                    class="size-4 animate-spin"
                                    aria-hidden="true"
                                />
                                <FilePlus2
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Solicitar série
                            </button>
                        </div>
                    </Form>

                    <div v-if="series.length > 0" class="flow-root">
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
                                                Série
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-3.5 text-left text-xs font-semibold text-zinc-600 dark:text-zinc-300"
                                            >
                                                Estado
                                            </th>
                                            <th
                                                scope="col"
                                                class="hidden px-3 py-3.5 text-left text-xs font-semibold text-zinc-600 sm:table-cell dark:text-zinc-300"
                                            >
                                                Método
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-3.5 text-right text-xs font-semibold text-zinc-600 dark:text-zinc-300"
                                            >
                                                Próximo
                                            </th>
                                            <th
                                                scope="col"
                                                class="py-3.5 pr-5 pl-3 text-right text-xs font-semibold text-zinc-600 sm:pr-8 dark:text-zinc-300"
                                            >
                                                Disponíveis
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-zinc-100 dark:divide-white/10"
                                    >
                                        <tr
                                            v-for="fiscalSeries in series"
                                            :key="fiscalSeries.public_id"
                                        >
                                            <td class="py-4 pr-3 pl-5 sm:pl-8">
                                                <p
                                                    class="text-sm font-semibold whitespace-nowrap text-zinc-950 dark:text-white"
                                                >
                                                    {{
                                                        fiscalSeries.series_code
                                                    }}
                                                </p>
                                                <p
                                                    class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                                >
                                                    {{
                                                        fiscalSeries.document_type_label
                                                    }}
                                                    ·
                                                    {{
                                                        fiscalSeries.series_year
                                                    }}
                                                </p>
                                            </td>
                                            <td class="px-3 py-4">
                                                <StatusBadge
                                                    :label="
                                                        fiscalSeries.status_label
                                                    "
                                                    :tone="
                                                        seriesTone(
                                                            fiscalSeries.status,
                                                        )
                                                    "
                                                />
                                            </td>
                                            <td
                                                class="hidden px-3 py-4 text-sm text-zinc-600 sm:table-cell dark:text-zinc-300"
                                            >
                                                <span
                                                    class="font-mono text-xs"
                                                    >{{
                                                        fiscalSeries.invoicing_method
                                                    }}</span
                                                >
                                                <p
                                                    class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                                >
                                                    {{
                                                        fiscalSeries.contingency_label
                                                    }}
                                                </p>
                                            </td>
                                            <td
                                                class="px-3 py-4 text-right font-mono text-sm font-semibold text-zinc-900 dark:text-white"
                                            >
                                                {{ fiscalSeries.next_number }}
                                            </td>
                                            <td
                                                class="py-4 pr-5 pl-3 text-right sm:pr-8"
                                            >
                                                <p
                                                    class="font-mono text-sm font-semibold text-zinc-900 dark:text-white"
                                                >
                                                    {{
                                                        fiscalSeries.remaining_numbers
                                                    }}
                                                </p>
                                                <p
                                                    class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                                >
                                                    sincronizada
                                                    {{
                                                        formatDate(
                                                            fiscalSeries.synchronized_at,
                                                        )
                                                    }}
                                                </p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-6 py-14 text-center">
                        <DatabaseZap
                            class="mx-auto size-9 text-zinc-300 dark:text-zinc-600"
                            aria-hidden="true"
                        />
                        <h3
                            class="mt-4 text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Nenhuma série sincronizada
                        </h3>
                        <p
                            class="mx-auto mt-1 max-w-md text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Verifique primeiro a ligação. Depois importe da AGT
                            as séries autorizadas para esta empresa.
                        </p>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl surface">
                    <div
                        class="flex flex-col gap-3 border-b border-zinc-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7 dark:border-white/10"
                    >
                        <div>
                            <div class="flex items-center gap-2">
                                <Activity
                                    class="size-5 text-brand-700 dark:text-brand-300"
                                    aria-hidden="true"
                                />
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Evidências de ligação
                                </h2>
                            </div>
                            <p
                                class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                Resumos técnicos sem credenciais, PEM ou
                                payloads em claro.
                            </p>
                        </div>
                        <StatusBadge
                            :label="
                                String(checks.length) +
                                (checks.length === 1
                                    ? ' verificação'
                                    : ' verificações')
                            "
                            tone="neutral"
                        />
                    </div>
                    <div v-if="checks.length > 0" class="overflow-x-auto">
                        <table
                            class="min-w-full divide-y divide-zinc-200 dark:divide-white/10"
                        >
                            <thead class="bg-zinc-50 dark:bg-white/[0.03]">
                                <tr>
                                    <th
                                        class="py-3.5 pr-3 pl-5 text-left text-xs font-semibold text-zinc-600 sm:pl-7 dark:text-zinc-300"
                                    >
                                        Verificação
                                    </th>
                                    <th
                                        class="px-3 py-3.5 text-left text-xs font-semibold text-zinc-600 dark:text-zinc-300"
                                    >
                                        Resultado
                                    </th>
                                    <th
                                        class="px-3 py-3.5 text-left text-xs font-semibold text-zinc-600 dark:text-zinc-300"
                                    >
                                        Prova técnica
                                    </th>
                                    <th
                                        class="py-3.5 pr-5 pl-3 text-right text-xs font-semibold text-zinc-600 sm:pr-7 dark:text-zinc-300"
                                    >
                                        Duração
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <tr
                                    v-for="check in checks"
                                    :key="check.public_id"
                                >
                                    <td
                                        class="py-4 pr-3 pl-5 align-top sm:pl-7"
                                    >
                                        <p
                                            class="text-sm font-semibold whitespace-nowrap text-zinc-900 dark:text-white"
                                        >
                                            {{ check.operation_label }}
                                        </p>
                                        <p
                                            class="mt-1 flex items-center gap-1.5 text-xs whitespace-nowrap text-zinc-500 dark:text-zinc-400"
                                        >
                                            <Clock3
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{ formatDate(check.started_at) }}
                                        </p>
                                    </td>
                                    <td class="px-3 py-4 align-top">
                                        <StatusBadge
                                            :label="check.status_label"
                                            :tone="statusTone(check.status)"
                                            :pulse="check.status === 'running'"
                                        />
                                        <p
                                            class="mt-2 max-w-sm text-xs/5 text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ check.safe_message }}
                                        </p>
                                        <p
                                            v-if="check.error_codes.length > 0"
                                            class="mt-1 font-mono text-[0.65rem] text-rose-600 dark:text-rose-400"
                                        >
                                            {{ check.error_codes.join(', ') }}
                                        </p>
                                    </td>
                                    <td class="px-3 py-4 align-top">
                                        <dl
                                            class="space-y-1 font-mono text-[0.65rem] text-zinc-500 dark:text-zinc-400"
                                        >
                                            <div class="flex gap-2">
                                                <dt>HTTP</dt>
                                                <dd
                                                    class="text-zinc-900 dark:text-white"
                                                >
                                                    {{
                                                        check.http_status ??
                                                        'local'
                                                    }}
                                                </dd>
                                            </div>
                                            <div class="flex gap-2">
                                                <dt>AGT</dt>
                                                <dd
                                                    class="text-zinc-900 dark:text-white"
                                                >
                                                    {{
                                                        check.result_code ?? '—'
                                                    }}
                                                </dd>
                                            </div>
                                            <div
                                                v-if="check.request_fingerprint"
                                                class="flex gap-2"
                                            >
                                                <dt>REQ</dt>
                                                <dd>
                                                    {{
                                                        check.request_fingerprint
                                                    }}
                                                </dd>
                                            </div>
                                        </dl>
                                    </td>
                                    <td
                                        class="py-4 pr-5 pl-3 text-right align-top text-sm whitespace-nowrap text-zinc-600 sm:pr-7 dark:text-zinc-300"
                                    >
                                        {{
                                            check.duration_ms === null
                                                ? '—'
                                                : String(check.duration_ms) +
                                                  ' ms'
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="px-6 py-14 text-center">
                        <Landmark
                            class="mx-auto size-8 text-zinc-300 dark:text-zinc-600"
                            aria-hidden="true"
                        />
                        <h3
                            class="mt-4 text-sm font-semibold text-zinc-900 dark:text-white"
                        >
                            Nenhuma verificação executada
                        </h3>
                        <p
                            class="mx-auto mt-1 max-w-md text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Conclua os requisitos locais. O primeiro teste
                            criará uma evidência segura desta ligação.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
