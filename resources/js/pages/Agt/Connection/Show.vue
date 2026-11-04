<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
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
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import CompanySetupProgress from '@/components/CompanySetupProgress.vue';
import type { SetupProgress } from '@/components/CompanySetupProgress.vue';
import DataTable from '@/components/DataTable.vue';
import FormError from '@/components/FormError.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageStat from '@/components/PageStat.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { connectionRecovery } from '@/lib/connection-recovery';
import { update, associate, show } from '@/routes/agt/connection';
import { store as runConnectionCheck } from '@/routes/agt/connection-checks';
import {
    store as requestSeries,
    sync as syncSeries,
} from '@/routes/agt/series';
import { index as helpIndex } from '@/routes/help';
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
    setupProgress: SetupProgress;
    associationAvailable: boolean;
    legalEntity: LegalEntity;
    connection: AgtConnection;
    readiness: { complete: boolean; items: ReadinessItem[] };
    checks: ConnectionCheck[];
    series: FiscalSeries[];
    seriesRequest: SeriesRequestOptions;
    permissions: {
        manage: boolean;
        manual_setup: boolean;
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

const technicalOpen = ref(false);
const seriesDocumentType = ref(props.seriesRequest.default_document_type);
const seriesYear = ref<number>(props.seriesRequest.years[0] ?? 0);

const seriesDocumentTypeOptions = computed(() =>
    props.seriesRequest.document_types.map((documentType) => ({
        value: documentType.value,
        label: `${documentType.value} · ${documentType.label}`,
    })),
);
const seriesYearOptions = computed(() =>
    props.seriesRequest.years.map((year) => ({
        value: year,
        label: String(year),
    })),
);

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

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell space-y-8">
                <PageHeader
                    eyebrow="Fiscal"
                    title="Ligação à AGT"
                    description="Associe o acesso da sua empresa, verifique a ligação e prepare a série da primeira factura. Não precisa de homologar o software."
                >
                    <template #meta>
                        <StatusBadge
                            :label="connection.status_label"
                            :tone="statusTone(connection.status)"
                            :pulse="connection.status === 'ready'"
                        />
                        <StatusBadge
                            :label="`${connection.environment_label} · testes`"
                            tone="neutral"
                        />
                    </template>
                    <template #stats>
                        <PageStat
                            label="Requisitos"
                            :value="`${completedRequirements}/${readiness.items.length}`"
                            detail="para consultar a AGT em testes"
                        />
                        <PageStat
                            label="Última verificação"
                            :value="formatDate(connection.verified_at)"
                            compact
                        />
                    </template>
                </PageHeader>

                <CompanySetupProgress
                    :progress="setupProgress"
                    on-connection-page
                />
                <section
                    id="taxpayer-association"
                    class="scroll-mt-24 rounded-2xl surface p-5 sm:p-7"
                    aria-labelledby="association-heading"
                >
                    <h2 id="association-heading" class="text-lg font-semibold">
                        Associar o acesso da sua empresa
                    </h2>
                    <p class="mt-2 text-sm/6">
                        {{ legalEntity.legal_name }} · NIF
                        {{ legalEntity.tax_identification_number }}
                    </p>
                    <ol
                        class="mt-4 list-decimal space-y-2 pl-5 text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        <li>
                            Confirme o cadastro e obtenha a chave do
                            contribuinte disponibilizada pela AGT no seu Portal.
                            A palavra-passe de entrada no Portal fica consigo.
                        </li>
                        <li>
                            Peça ao apoio Facturac a preparação da associação
                            para este NIF e ambiente. Combine um procedimento de
                            entrega segura; não envie chaves por email ou
                            mensagens.
                        </li>
                        <li>
                            Quando a preparação estiver disponível, confirme
                            abaixo. A seguir, verifique a ligação e importe as
                            séries.
                        </li>
                    </ol>
                    <p class="mt-3 text-sm/6 text-zinc-500 dark:text-zinc-400">
                        As credenciais API, a chave do software e a homologação
                        são tratadas pelo produtor Facturac. Não são as
                        credenciais pessoais do seu Portal.
                    </p>
                    <p
                        v-if="connection.public_id"
                        class="mt-5 text-sm font-semibold"
                    >
                        Já existe uma ligação guardada. Consulte os requisitos e
                        verifique o acesso abaixo; não precisa de a associar
                        novamente.
                    </p>
                    <p
                        v-else-if="!permissions.manage"
                        class="mt-5 text-sm font-semibold"
                    >
                        Peça ao proprietário ou administrador da empresa que
                        confirme a associação.
                    </p>
                    <AppButton
                        variant="gold"
                        size="lg"
                        v-else-if="!guardrails.mfa_enabled"
                        :href="security.url()"
                        class="mt-5"
                        >Activar protecção de dois factores</AppButton
                    >
                    <Form
                        v-else-if="associationAvailable"
                        v-bind="associate.form()"
                        :options="{ preserveScroll: true }"
                        class="mt-5 space-y-4"
                        #default="{ errors, processing }"
                    >
                        <input
                            type="hidden"
                            name="tax_identification_number"
                            :value="legalEntity.tax_identification_number ?? ''"
                        />
                        <label
                            for="association-confirmed"
                            class="flex items-start gap-3 text-sm/6"
                        >
                            <input
                                id="association-confirmed"
                                type="checkbox"
                                name="association_confirmed"
                                value="1"
                                required
                                class="form-checkbox mt-1"
                                :disabled="processing"
                                :aria-invalid="!!errors.association_confirmed"
                                aria-describedby="association-error"
                            />
                            Autorizo a associação do acesso preparado para esta
                            empresa, em ambiente de testes.
                        </label>
                        <FormError
                            id="association-error"
                            :message="errors.association_confirmed"
                        />
                        <FormError
                            :message="errors.tax_identification_number"
                        />
                        <AppButton
                            variant="ink"
                            size="lg"
                            type="submit"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'A associar…'
                                    : 'Associar o meu acesso'
                            }}
                        </AppButton>
                        <p class="text-xs/5 text-zinc-500 dark:text-zinc-400">
                            Poderá ser pedida a palavra-passe da sua conta
                            Facturac para confirmar esta operação. Não é emitida
                            nenhuma factura.
                        </p>
                    </Form>
                    <p
                        v-else
                        class="mt-5 rounded-xl bg-amber-50 p-4 text-sm/6 text-amber-900 dark:bg-amber-400/10 dark:text-amber-200"
                    >
                        A preparação ainda não está disponível. Contacte o apoio
                        Facturac pelos canais habituais, indicando apenas o nome
                        e o NIF da empresa. Depois actualize esta página. Não
                        cole chaves nem palavras-passe em pedidos de apoio.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <AppButton
                            variant="outline"
                            size="lg"
                            :href="helpIndex.url()"
                            >Contactos de apoio</AppButton
                        >
                        <AppButton
                            variant="outline"
                            size="lg"
                            v-if="!connection.public_id"
                            :href="show.url()"
                            >Actualizar preparação</AppButton
                        >
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

                <details class="rounded-2xl surface p-5">
                    <summary
                        class="cursor-pointer text-sm font-semibold focus-ring"
                    >
                        Detalhes do ambiente e da custódia
                    </summary>
                    <div class="mt-4">
                        <section class="grid gap-4 md:grid-cols-3">
                            <article class="rounded-2xl surface p-5">
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <span
                                        class="grid size-10 place-items-center rounded-xl bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300"
                                    >
                                        <Network
                                            class="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <StatusBadge
                                        label="Só homologação"
                                        tone="info"
                                    />
                                </div>
                                <p
                                    class="mt-5 text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{ connection.endpoint_host }}
                                </p>
                                <p
                                    class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                                >
                                    Produção bloqueada até certificação e
                                    autorização explícita.
                                </p>
                            </article>
                            <article class="rounded-2xl surface p-5">
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <span
                                        class="grid size-10 place-items-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300"
                                    >
                                        <DatabaseZap
                                            class="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <StatusBadge
                                        label="Cifrado"
                                        tone="success"
                                    />
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
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <span
                                        class="grid size-10 place-items-center rounded-xl bg-amber-50 text-amber-800 dark:bg-amber-400/10 dark:text-amber-300"
                                    >
                                        <KeyRound
                                            class="size-5"
                                            aria-hidden="true"
                                        />
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
                    </div>
                </details>

                <div
                    class="grid gap-8"
                    :class="
                        permissions.manual_setup
                            ? 'xl:grid-cols-[minmax(0,1fr)_24rem]'
                            : 'max-w-3xl'
                    "
                >
                    <details
                        v-if="permissions.manual_setup"
                        :open="technicalOpen"
                        class="rounded-2xl surface p-5"
                        @toggle="
                            technicalOpen = (
                                $event.target as HTMLDetailsElement
                            ).open
                        "
                    >
                        <summary
                            class="cursor-pointer text-sm font-semibold focus-ring"
                        >
                            Configuração técnica assistida · apenas com o apoio
                            Facturac
                        </summary>
                        <p class="my-4 text-sm/6 text-zinc-500">
                            O percurso acima usa uma associação preparada. Estes
                            campos são para manutenção técnica acompanhada; não
                            precisa de os preencher para continuar se já tem
                            acesso associado.
                        </p>
                        <Form
                            v-bind="update.form()"
                            :options="{ preserveScroll: true }"
                            :reset-on-success="[
                                'basic_auth_username',
                                'basic_auth_password',
                            ]"
                            :reset-on-error="['basic_auth_password']"
                            class="space-y-8"
                            #default="{
                                errors,
                                processing,
                                recentlySuccessful,
                            }"
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
                                            Credenciais API do produtor
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
                                    :disabled="
                                        !permissions.manage || processing
                                    "
                                    class="grid grid-cols-1 gap-x-6 gap-y-6 p-5 disabled:opacity-65 sm:grid-cols-6 sm:p-7"
                                >
                                    <FormField
                                        class="sm:col-span-3"
                                        for="agt-username"
                                        label="Utilizador AGT"
                                        :error="errors.basic_auth_username"
                                        ><input
                                            id="agt-username"
                                            name="basic_auth_username"
                                            type="text"
                                            autocomplete="off"
                                            :required="
                                                !connection.has_basic_credentials
                                            "
                                            :placeholder="
                                                connection.credential_identity ??
                                                'Utilizador API do produtor'
                                            "
                                            class="block form-input"
                                    /></FormField>
                                    <FormField
                                        class="sm:col-span-3"
                                        for="agt-password"
                                        label="Palavra-passe / token"
                                        :error="errors.basic_auth_password"
                                        ><input
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
                                            class="block form-input"
                                    /></FormField>
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
                                    :disabled="
                                        !permissions.manage || processing
                                    "
                                    class="grid grid-cols-1 gap-x-6 gap-y-6 p-5 disabled:opacity-65 sm:grid-cols-6 sm:p-7"
                                >
                                    <FormField
                                        class="sm:col-span-3"
                                        for="product-id"
                                        label="Nome do produto"
                                        :error="errors.product_id"
                                        ><input
                                            id="product-id"
                                            name="product_id"
                                            type="text"
                                            required
                                            :value="connection.product_id"
                                            placeholder="facturac.ao"
                                            class="block form-input"
                                    /></FormField>
                                    <FormField
                                        class="sm:col-span-3"
                                        for="product-version"
                                        label="Versão"
                                        :error="errors.product_version"
                                        ><input
                                            id="product-version"
                                            name="product_version"
                                            type="text"
                                            required
                                            :value="connection.product_version"
                                            placeholder="1.0.0"
                                            class="block form-input font-mono"
                                    /></FormField>
                                    <FormField
                                        class="sm:col-span-3"
                                        for="validation-number"
                                        label="Número de validação"
                                        :error="
                                            errors.software_validation_number
                                        "
                                        ><input
                                            id="validation-number"
                                            name="software_validation_number"
                                            type="text"
                                            required
                                            :value="
                                                connection.software_validation_number
                                            "
                                            placeholder="Número atribuído ao software"
                                            class="block form-input font-mono"
                                    /></FormField>
                                    <FormField
                                        class="sm:col-span-3"
                                        for="establishment-number"
                                        label="Número do estabelecimento AGT"
                                        :error="errors.establishment_number"
                                        ><input
                                            id="establishment-number"
                                            name="establishment_number"
                                            type="text"
                                            required
                                            :value="
                                                connection.establishment_number
                                            "
                                            placeholder="Identificador da sede na AGT"
                                            class="block form-input font-mono"
                                    /></FormField>
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
                                            Indique nomes relativos no cofre.
                                            Não cole PEM, conteúdo de chave ou
                                            caminhos absolutos.
                                        </p>
                                    </div>
                                </div>
                                <fieldset
                                    :disabled="
                                        !permissions.manage || processing
                                    "
                                    class="grid grid-cols-1 gap-x-6 gap-y-6 p-5 disabled:opacity-65 sm:grid-cols-6 sm:p-7"
                                >
                                    <div class="sm:col-span-3">
                                        <label
                                            for="software-key"
                                            class="field-label"
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
                                            class="mt-2 block form-input font-mono"
                                        />
                                        <FormError
                                            :message="
                                                errors.software_key_reference
                                            "
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
                                            class="field-label"
                                            >Chave privada do
                                            contribuinte</label
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
                                            class="mt-2 block form-input font-mono"
                                        />
                                        <FormError
                                            :message="
                                                errors.taxpayer_key_reference
                                            "
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
                                    <BadgeCheck
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Guardado
                                </span>
                                <AppButton
                                    variant="ink"
                                    size="lg"
                                    type="submit"
                                    :disabled="
                                        !permissions.manage || processing
                                    "
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
                                </AppButton>
                            </div>
                        </Form>
                    </details>

                    <aside class="space-y-6 xl:sticky xl:top-24 xl:self-start">
                        <section
                            id="connection-check"
                            class="scroll-mt-24 rounded-2xl surface p-5"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p
                                        class="eyebrow text-zinc-500 dark:text-zinc-400"
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
                            <p
                                class="mt-4 text-sm/6 text-zinc-600 dark:text-zinc-400"
                                role="status"
                            >
                                {{ connectionRecovery(checks[0]) }}
                            </p>
                            <Form
                                v-if="
                                    permissions.test && guardrails.mfa_enabled
                                "
                                v-bind="runConnectionCheck.form()"
                                :options="{ preserveScroll: true }"
                                class="mt-5"
                                #default="{ processing }"
                            >
                                <AppButton
                                    variant="gold"
                                    type="submit"
                                    :disabled="
                                        !readiness.complete || processing
                                    "
                                    class="w-full focus-ring-inverted"
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
                                    Verificar ligação AGT
                                </AppButton>
                            </Form>
                            <AppButton
                                variant="gold"
                                v-else-if="permissions.test"
                                :href="security.url()"
                                class="mt-5 w-full"
                            >
                                <LockKeyhole
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Activar MFA para continuar
                            </AppButton>
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
                                    Pode repetir a verificação depois de
                                    corrigir os requisitos indicados.
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
                                class="mt-5 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300 pointer-coarse:min-h-11"
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

                <section
                    id="authorized-series"
                    class="scroll-mt-24 overflow-hidden rounded-2xl surface"
                >
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
                                Comece por importar as séries existentes. Se não
                                houver uma série FT adequada, peça uma nova
                                abaixo. Se o pedido demorar ou falhar sem
                                confirmação, importe novamente antes de repetir
                                o pedido.
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
                            <AppButton
                                variant="ink"
                                type="submit"
                                :disabled="processing"
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
                                Importar séries autorizadas
                            </AppButton>
                        </Form>
                        <AppButton
                            variant="gold"
                            v-else-if="permissions.sync_series"
                            :href="security.url()"
                        >
                            <LockKeyhole class="size-4" aria-hidden="true" />
                            Activar MFA
                        </AppButton>
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
                                <SelectInput
                                    id="series-document-type"
                                    v-model="seriesDocumentType"
                                    name="document_type"
                                    required
                                    class="mt-2"
                                    :options="seriesDocumentTypeOptions"
                                    :disabled="processing"
                                    :invalid="!!errors.document_type"
                                />
                                <FormError :message="errors.document_type" />
                            </div>
                            <div>
                                <label
                                    for="series-year"
                                    class="block text-xs font-semibold text-zinc-600 dark:text-zinc-300"
                                >
                                    Ano
                                </label>
                                <SelectInput
                                    id="series-year"
                                    v-model="seriesYear"
                                    name="series_year"
                                    required
                                    class="mt-2"
                                    :options="seriesYearOptions"
                                    :disabled="processing"
                                    :invalid="!!errors.series_year"
                                />
                                <FormError :message="errors.series_year" />
                            </div>
                            <AppButton
                                variant="gold"
                                type="submit"
                                :disabled="processing"
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
                            </AppButton>
                        </div>
                    </Form>

                    <div v-if="series.length > 0" class="flow-root">
                        <div
                            class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8"
                        >
                            <div
                                class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8"
                            >
                                <DataTable
                                    ><template #head>
                                        <th scope="col">Série</th>
                                        <th scope="col">Estado</th>
                                        <th
                                            scope="col"
                                            class="hidden sm:table-cell"
                                        >
                                            Método
                                        </th>
                                        <th scope="col" class="text-right">
                                            Próximo
                                        </th>
                                        <th scope="col" class="text-right">
                                            Disponíveis
                                        </th>
                                    </template>
                                    <tr
                                        v-for="fiscalSeries in series"
                                        :key="fiscalSeries.public_id"
                                    >
                                        <td>
                                            <p
                                                class="text-sm font-semibold whitespace-nowrap text-zinc-950 dark:text-white"
                                            >
                                                {{ fiscalSeries.series_code }}
                                            </p>
                                            <p
                                                class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                            >
                                                {{
                                                    fiscalSeries.document_type_label
                                                }}
                                                ·
                                                {{ fiscalSeries.series_year }}
                                            </p>
                                        </td>
                                        <td>
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
                                            class="hidden text-sm text-zinc-600 sm:table-cell dark:text-zinc-300"
                                        >
                                            <span class="font-mono text-xs">{{
                                                fiscalSeries.invoicing_method
                                            }}</span>
                                            <p
                                                class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                            >
                                                {{
                                                    fiscalSeries.contingency_label
                                                }}
                                            </p>
                                        </td>
                                        <td
                                            class="text-right font-mono text-sm font-semibold text-zinc-900 dark:text-white"
                                        >
                                            {{ fiscalSeries.next_number }}
                                        </td>
                                        <td class="text-right">
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
                                </DataTable>
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
                        <DataTable
                            ><template #head>
                                <th>Verificação</th>
                                <th>Resultado</th>
                                <th>Prova técnica</th>
                                <th class="text-right">Duração</th>
                            </template>
                            <tr v-for="check in checks" :key="check.public_id">
                                <td class="align-top">
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
                                <td class="align-top">
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
                                <td class="align-top">
                                    <dl
                                        class="space-y-1 font-mono text-[0.65rem] text-zinc-500 dark:text-zinc-400"
                                    >
                                        <div class="flex gap-2">
                                            <dt>HTTP</dt>
                                            <dd
                                                class="text-zinc-900 dark:text-white"
                                            >
                                                {{
                                                    check.http_status ?? 'local'
                                                }}
                                            </dd>
                                        </div>
                                        <div class="flex gap-2">
                                            <dt>AGT</dt>
                                            <dd
                                                class="text-zinc-900 dark:text-white"
                                            >
                                                {{ check.result_code ?? '—' }}
                                            </dd>
                                        </div>
                                        <div
                                            v-if="check.request_fingerprint"
                                            class="flex gap-2"
                                        >
                                            <dt>REQ</dt>
                                            <dd>
                                                {{ check.request_fingerprint }}
                                            </dd>
                                        </div>
                                    </dl>
                                </td>
                                <td
                                    class="text-right align-top text-sm whitespace-nowrap text-zinc-600 dark:text-zinc-300"
                                >
                                    {{
                                        check.duration_ms === null
                                            ? '—'
                                            : String(check.duration_ms) + ' ms'
                                    }}
                                </td>
                            </tr>
                        </DataTable>
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
