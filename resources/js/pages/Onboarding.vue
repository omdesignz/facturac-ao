<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Check,
    ChevronRight,
    CircleDashed,
    KeyRound,
    Landmark,
    LoaderCircle,
    LockKeyhole,
    MapPin,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import FormError from '@/components/FormError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { show as agtConnectionShow } from '@/routes/agt/connection';
import { update as onboardingUpdate } from '@/routes/onboarding';
import { security } from '@/routes/settings';

interface CompanyProfile {
    legal_name: string;
    trade_name: string;
    tax_identification_number: string;
    tax_regime: string;
    main_cae_code: string;
    status: string;
    status_label: string;
    establishment_code: string;
    establishment_name: string;
    address_line: string;
    municipality: string;
    province_code: string;
}

interface TaxRegimeOption {
    value: string;
    label: string;
}

interface AgtConnectionSummary {
    configured: boolean;
    verified: boolean;
    status_label: string;
}

const props = defineProps<{
    company: CompanyProfile;
    taxRegimes: TaxRegimeOption[];
    canUpdate: boolean;
    agtConnection: AgtConnectionSummary;
}>();

const page = usePage();
const flashSuccess = computed(
    () => (page.props.flash as { success?: string }).success,
);
const profileIsConfigured = computed(
    () =>
        props.company.status === 'configured' ||
        props.company.status === 'homologation' ||
        props.company.status === 'active',
);

const steps = computed(() => [
    { name: 'Conta', detail: 'Email confirmado', status: 'complete' },
    {
        name: 'Empresa',
        detail: 'Identidade e sede',
        status: profileIsConfigured.value ? 'complete' : 'current',
    },
    {
        name: 'Segurança',
        detail: 'MFA recomendado',
        status: page.props.auth.user?.two_factor_enabled
            ? 'complete'
            : profileIsConfigured.value
              ? 'current'
              : 'upcoming',
    },
    {
        name: 'Ligação AGT',
        detail: 'Credenciais e chaves',
        status: props.agtConnection.configured
            ? 'complete'
            : profileIsConfigured.value
              ? 'current'
              : 'upcoming',
    },
    {
        name: 'Homologação',
        detail: 'Antes de produção',
        status: props.agtConnection.verified ? 'complete' : 'upcoming',
    },
]);
</script>

<template>
    <AppLayout>
        <Head title="Preparar empresa" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-7xl space-y-8">
                <header
                    class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge
                                :label="company.status_label"
                                :tone="
                                    profileIsConfigured ? 'success' : 'warning'
                                "
                            />
                            <StatusBadge label="Dados reais" tone="info" />
                        </div>
                        <h1
                            class="mt-4 font-display text-4xl font-semibold tracking-tight text-zinc-950 sm:text-5xl dark:text-white"
                        >
                            Prepare a empresa<br /><span
                                class="text-brand-700 dark:text-brand-300"
                                >com uma base verificável.</span
                            >
                        </h1>
                        <p
                            class="mt-3 max-w-2xl text-sm/6 text-zinc-600 sm:text-base/7 dark:text-zinc-400"
                        >
                            Estes dados definem o contribuinte e o local
                            emissor. Depois de os confirmar, prepare a ligação
                            AGT no ambiente isolado de homologação.
                        </p>
                    </div>
                    <Link
                        :href="dashboard.url()"
                        class="w-fit rounded-xl px-4 py-2.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 transition hover:bg-white dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                    >
                        Voltar ao painel
                    </Link>
                </header>

                <nav
                    aria-label="Progresso de configuração"
                    class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-zinc-900/5 dark:bg-zinc-900 dark:ring-white/10"
                >
                    <ol
                        role="list"
                        class="grid grid-cols-1 divide-y divide-zinc-100 sm:grid-cols-5 sm:divide-x sm:divide-y-0 dark:divide-white/10"
                    >
                        <li
                            v-for="(step, index) in steps"
                            :key="step.name"
                            class="relative px-4 py-4 sm:px-5"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    :class="[
                                        step.status === 'complete'
                                            ? 'bg-emerald-600 text-white'
                                            : step.status === 'current'
                                              ? 'bg-amber-300 text-brand-950 ring-4 ring-amber-100 dark:ring-amber-400/10'
                                              : 'bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500',
                                        'grid size-8 shrink-0 place-items-center rounded-full text-xs font-bold',
                                    ]"
                                >
                                    <Check
                                        v-if="step.status === 'complete'"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    <span v-else>{{ index + 1 }}</span>
                                </span>
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-sm font-semibold text-zinc-900 dark:text-white"
                                        >{{ step.name }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-zinc-500 dark:text-zinc-400"
                                        >{{ step.detail }}</span
                                    >
                                </span>
                            </div>
                            <ChevronRight
                                v-if="index < steps.length - 1"
                                class="absolute top-1/2 -right-2 z-10 hidden size-4 -translate-y-1/2 text-zinc-300 sm:block dark:text-zinc-700"
                                aria-hidden="true"
                            />
                        </li>
                    </ol>
                </nav>

                <div
                    v-if="flashSuccess"
                    class="flex items-center gap-3 rounded-2xl bg-emerald-50 p-4 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20"
                    role="status"
                >
                    <Check class="size-5 shrink-0" aria-hidden="true" />
                    {{ flashSuccess }}
                </div>

                <div
                    class="grid grid-cols-1 gap-8 xl:grid-cols-[minmax(0,1fr)_22rem]"
                >
                    <Form
                        v-bind="onboardingUpdate.form()"
                        :options="{ preserveScroll: true }"
                        set-defaults-on-success
                        class="space-y-8"
                        #default="{ errors, processing, isDirty }"
                    >
                        <section
                            class="rounded-2xl bg-white shadow-sm ring-1 ring-zinc-900/5 dark:bg-zinc-900 dark:ring-white/10"
                        >
                            <div
                                class="flex gap-4 border-b border-zinc-100 p-5 sm:p-7 dark:border-white/10"
                            >
                                <span
                                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                                >
                                    <Building2
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <h2
                                        class="font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Identidade legal
                                    </h2>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Guardamos o NIF como texto para
                                        preservar zeros iniciais e
                                        identificadores de teste.
                                    </p>
                                </div>
                            </div>

                            <fieldset
                                :disabled="!canUpdate || processing"
                                class="grid grid-cols-1 gap-x-6 gap-y-6 p-5 disabled:opacity-70 sm:grid-cols-6 sm:p-7"
                            >
                                <div class="sm:col-span-4">
                                    <label
                                        for="legal-name"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Denominação social</label
                                    >
                                    <input
                                        id="legal-name"
                                        name="legal_name"
                                        type="text"
                                        autocomplete="organization"
                                        required
                                        :value="company.legal_name"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError :message="errors.legal_name" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label
                                        for="tax-id"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >NIF</label
                                    >
                                    <input
                                        id="tax-id"
                                        name="tax_identification_number"
                                        type="text"
                                        inputmode="text"
                                        required
                                        :value="
                                            company.tax_identification_number
                                        "
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="
                                            errors.tax_identification_number
                                        "
                                    />
                                </div>
                                <div class="sm:col-span-3">
                                    <label
                                        for="trade-name"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Nome comercial
                                        <span class="font-normal text-zinc-400"
                                            >(opcional)</span
                                        ></label
                                    >
                                    <input
                                        id="trade-name"
                                        name="trade_name"
                                        type="text"
                                        :value="company.trade_name"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError :message="errors.trade_name" />
                                </div>
                                <div class="sm:col-span-3">
                                    <label
                                        for="tax-regime"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Regime de IVA</label
                                    >
                                    <select
                                        id="tax-regime"
                                        name="tax_regime"
                                        required
                                        :value="company.tax_regime"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-zinc-900 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    >
                                        <option
                                            v-for="regime in taxRegimes"
                                            :key="regime.value"
                                            :value="regime.value"
                                        >
                                            {{ regime.label }}
                                        </option>
                                    </select>
                                    <FormError :message="errors.tax_regime" />
                                </div>
                                <div class="sm:col-span-3">
                                    <label
                                        for="cae"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >CAE principal</label
                                    >
                                    <input
                                        id="cae"
                                        name="main_cae_code"
                                        type="text"
                                        required
                                        :value="company.main_cae_code"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                        placeholder="Ex.: 62010"
                                    />
                                    <FormError
                                        :message="errors.main_cae_code"
                                    />
                                </div>
                            </fieldset>
                        </section>

                        <section
                            class="rounded-2xl bg-white shadow-sm ring-1 ring-zinc-900/5 dark:bg-zinc-900 dark:ring-white/10"
                        >
                            <div
                                class="flex gap-4 border-b border-zinc-100 p-5 sm:p-7 dark:border-white/10"
                            >
                                <span
                                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-100 text-amber-800 dark:bg-amber-400/10 dark:text-amber-300"
                                >
                                    <MapPin class="size-5" aria-hidden="true" />
                                </span>
                                <div>
                                    <h2
                                        class="font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Estabelecimento principal
                                    </h2>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Será o local emissor por defeito. Outros
                                        estabelecimentos entram numa fase
                                        posterior.
                                    </p>
                                </div>
                            </div>

                            <fieldset
                                :disabled="!canUpdate || processing"
                                class="grid grid-cols-1 gap-x-6 gap-y-6 p-5 disabled:opacity-70 sm:grid-cols-6 sm:p-7"
                            >
                                <div class="sm:col-span-2">
                                    <label
                                        for="establishment-code"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Código interno</label
                                    >
                                    <input
                                        id="establishment-code"
                                        name="establishment_code"
                                        type="text"
                                        required
                                        :value="company.establishment_code"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="errors.establishment_code"
                                    />
                                </div>
                                <div class="sm:col-span-4">
                                    <label
                                        for="establishment-name"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Nome do estabelecimento</label
                                    >
                                    <input
                                        id="establishment-name"
                                        name="establishment_name"
                                        type="text"
                                        required
                                        :value="company.establishment_name"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError
                                        :message="errors.establishment_name"
                                    />
                                </div>
                                <div class="sm:col-span-6">
                                    <label
                                        for="address-line"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Morada</label
                                    >
                                    <input
                                        id="address-line"
                                        name="address_line"
                                        type="text"
                                        autocomplete="street-address"
                                        required
                                        :value="company.address_line"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError :message="errors.address_line" />
                                </div>
                                <div class="sm:col-span-4">
                                    <label
                                        for="municipality"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Município</label
                                    >
                                    <input
                                        id="municipality"
                                        name="municipality"
                                        type="text"
                                        required
                                        :value="company.municipality"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                    />
                                    <FormError :message="errors.municipality" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label
                                        for="province-code"
                                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                                        >Código da província</label
                                    >
                                    <input
                                        id="province-code"
                                        name="province_code"
                                        type="text"
                                        required
                                        :value="company.province_code"
                                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                        placeholder="LU"
                                    />
                                    <FormError
                                        :message="errors.province_code"
                                    />
                                </div>
                            </fieldset>

                            <div
                                class="flex flex-col gap-3 border-t border-zinc-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-7 dark:border-white/10"
                            >
                                <p
                                    v-if="!canUpdate"
                                    class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-300"
                                >
                                    <LockKeyhole
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    O perfil está bloqueado porque a homologação
                                    já começou.
                                </p>
                                <p
                                    v-else
                                    class="text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{
                                        isDirty
                                            ? 'Existem alterações por guardar.'
                                            : 'Todos os campos são guardados num único compromisso.'
                                    }}
                                </p>
                                <button
                                    v-if="canUpdate"
                                    type="submit"
                                    :disabled="processing"
                                    class="flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-brand-500 dark:hover:bg-brand-400"
                                >
                                    <LoaderCircle
                                        v-if="processing"
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    {{
                                        processing
                                            ? 'A guardar…'
                                            : 'Guardar perfil da empresa'
                                    }}
                                </button>
                            </div>
                        </section>
                    </Form>

                    <aside class="space-y-5">
                        <section
                            class="rounded-2xl bg-brand-950 p-5 text-white shadow-sm dark:bg-brand-950 dark:ring-1 dark:ring-white/10"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="grid size-10 place-items-center rounded-xl bg-white/10"
                                >
                                    <ShieldCheck
                                        class="size-5 text-amber-300"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <p class="font-semibold">
                                        Prontidão da conta
                                    </p>
                                    <p class="text-xs text-brand-100/65">
                                        Controlos da Fase 1
                                    </p>
                                </div>
                            </div>

                            <ul class="mt-5 space-y-4 text-sm">
                                <li class="flex gap-3">
                                    <Check
                                        class="mt-0.5 size-4 shrink-0 text-emerald-300"
                                        aria-hidden="true"
                                    />
                                    <span>Email do utilizador confirmado</span>
                                </li>
                                <li class="flex gap-3">
                                    <component
                                        :is="
                                            profileIsConfigured
                                                ? Check
                                                : CircleDashed
                                        "
                                        class="mt-0.5 size-4 shrink-0"
                                        :class="
                                            profileIsConfigured
                                                ? 'text-emerald-300'
                                                : 'text-amber-300'
                                        "
                                        aria-hidden="true"
                                    />
                                    <span>Identidade da empresa e sede</span>
                                </li>
                                <li class="flex gap-3">
                                    <component
                                        :is="
                                            page.props.auth.user
                                                ?.two_factor_enabled
                                                ? Check
                                                : KeyRound
                                        "
                                        class="mt-0.5 size-4 shrink-0"
                                        :class="
                                            page.props.auth.user
                                                ?.two_factor_enabled
                                                ? 'text-emerald-300'
                                                : 'text-amber-300'
                                        "
                                        aria-hidden="true"
                                    />
                                    <span>{{
                                        page.props.auth.user?.two_factor_enabled
                                            ? 'MFA activado'
                                            : 'MFA ainda por activar'
                                    }}</span>
                                </li>
                            </ul>

                            <Link
                                :href="security.url()"
                                class="mt-6 flex w-full items-center justify-center gap-2 rounded-xl bg-white px-3 py-2.5 text-sm font-semibold text-brand-950 transition hover:bg-brand-50"
                            >
                                <KeyRound class="size-4" aria-hidden="true" />
                                Rever segurança
                            </Link>
                        </section>

                        <section
                            class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-900/5 dark:bg-zinc-900 dark:ring-white/10"
                        >
                            <div class="flex items-center gap-3">
                                <Landmark
                                    class="size-5 text-zinc-400"
                                    aria-hidden="true"
                                />
                                <p
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Integração AGT
                                </p>
                            </div>
                            <p
                                class="mt-3 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                As credenciais são cifradas e as chaves privadas
                                permanecem no cofre local. A ligação só consulta
                                séries em homologação nesta fase.
                            </p>
                            <div
                                class="mt-4 rounded-xl bg-zinc-50 p-3 text-xs font-medium text-zinc-500 dark:bg-white/5 dark:text-zinc-400"
                            >
                                Estado: {{ agtConnection.status_label }}
                            </div>
                            <Link
                                v-if="profileIsConfigured"
                                :href="agtConnectionShow.url()"
                                class="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-brand-700 px-3 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-600 dark:bg-brand-500 dark:text-brand-950 dark:hover:bg-brand-400"
                            >
                                <Landmark class="size-4" aria-hidden="true" />
                                Preparar ligação AGT
                            </Link>
                        </section>
                    </aside>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
