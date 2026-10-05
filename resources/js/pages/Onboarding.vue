<script setup lang="ts">
import { Form, Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Check,
    ChevronRight,
    CircleDashed,
    KeyRound,
    Landmark,
    Download,
    FileCode2,
    Image as ImageIcon,
    LoaderCircle,
    LockKeyhole,
    MapPin,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DateInput from '@/components/DateInput.vue';
import FormError from '@/components/FormError.vue';
import PageHeader from '@/components/PageHeader.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { dashboard } from '@/routes';
import { show as agtConnectionShow } from '@/routes/agt/connection';
import {
    destroy as destroyCompanyLogo,
    show as showCompanyLogo,
    store as storeCompanyLogo,
} from '@/routes/company/logo';
import { update as onboardingUpdate } from '@/routes/onboarding';
import { exportMethod as saftExport } from '@/routes/saft';
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
    province_label: string;
    has_logo: boolean;
    logo_updated_at: string | null;
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
    provinces: { value: string; label: string }[];
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

const taxRegime = ref(props.company.tax_regime);

const provinceCode = ref(props.company.province_code);

const logoInput = ref<HTMLInputElement | null>(null);
const logoForm = useForm<{ logo: File | null }>({ logo: null });

const logoUrl = computed(
    () => `${showCompanyLogo.url()}?v=${props.company.logo_updated_at ?? ''}`,
);

function uploadLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    logoForm.logo = file;
    logoForm.post(storeCompanyLogo.url(), {
        preserveScroll: true,
        onFinish: () => {
            if (logoInput.value) {
                logoInput.value.value = '';
            }
        },
    });
}

async function removeLogo(): Promise<void> {
    const confirmed = await confirmAction({
        title: 'Remover o logótipo?',
        message:
            'Os documentos passam a sair só com o nome da empresa no cabeçalho.',
        confirmLabel: 'Remover logótipo',
    });

    if (confirmed) {
        router.delete(destroyCompanyLogo.url(), { preserveScroll: true });
    }
}

const startOfYear = new Date(new Date().getFullYear(), 0, 1)
    .toISOString()
    .slice(0, 10);
const saftFrom = ref(startOfYear);
const saftTo = ref(new Date().toISOString().slice(0, 10));

const saftUrl = computed(
    () => `${saftExport.url()}?from=${saftFrom.value}&to=${saftTo.value}`,
);

/**
 * A province saved before the list existed, or before the 2024 reform, keeps
 * its own entry.
 *
 * This field used to take a typed code, so some companies hold things like
 * "LU"; and Cuando Cubango existed until it was split. Dropping either on the
 * next save would quietly rewrite an address that is already on documents.
 */
const provinceOptions = computed(() => {
    const current = props.company.province_code;
    const known = props.provinces.map((province) => ({
        value: province.value,
        label: province.label,
    }));

    return current && !known.some((option) => option.value === current)
        ? [
              ...known,
              {
                  value: current,
                  label: `${props.company.province_label} · manter`,
              },
          ]
        : known;
});

const taxRegimeOptions = computed(() =>
    props.taxRegimes.map((regime) => ({
        value: regime.value,
        label: regime.label,
    })),
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
                <PageHeader
                    eyebrow="Configuração · Preparar empresa"
                    title="Fale-nos da sua empresa"
                    description="É este NIF e esta morada que vão sair impressos em cada factura. Depois de confirmar, ligamos a sua conta à AGT."
                >
                    <template #meta>
                        <StatusBadge
                            :label="company.status_label"
                            :tone="profileIsConfigured ? 'success' : 'warning'"
                        />
                    </template>
                    <template #actions>
                        <Link
                            :href="dashboard.url()"
                            class="inline-flex h-10 items-center rounded-full bg-white px-[1.125rem] text-sm font-semibold text-zinc-900 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:hover:bg-white/10"
                        >
                            Voltar ao painel
                        </Link>
                    </template>
                </PageHeader>

                <nav
                    aria-label="Progresso de configuração"
                    class="overflow-hidden rounded-2xl surface"
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
                                              ? 'bg-accent-400 text-brand-950 ring-4 ring-amber-100 dark:ring-amber-400/10'
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
                        <section class="rounded-2xl surface">
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
                                    <SelectInput
                                        id="tax-regime"
                                        v-model="taxRegime"
                                        name="tax_regime"
                                        class="mt-2"
                                        :options="taxRegimeOptions"
                                    />
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

                        <section class="rounded-2xl surface">
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
                                        É daqui que saem as suas facturas. Pode
                                        acrescentar mais estabelecimentos quando
                                        precisar.
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
                                        >Província</label
                                    >
                                    <SelectInput
                                        id="province-code"
                                        v-model="provinceCode"
                                        name="province_code"
                                        class="mt-2"
                                        :options="provinceOptions"
                                        placeholder="Escolha a província"
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
                                    class="inline-flex h-10 items-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
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

                    <section class="rounded-2xl surface p-5 sm:p-7">
                        <div class="flex gap-4">
                            <span
                                class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                            >
                                <ImageIcon class="size-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Logótipo
                                </h2>
                                <p
                                    class="mt-1 max-w-xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    Aparece no cabeçalho das facturas e recibos.
                                    PNG ou JPEG até 2 MB — cabe numa caixa de 45
                                    × 18 mm, por isso uma marca larga sai melhor
                                    que uma alta.
                                </p>

                                <div
                                    class="mt-4 flex flex-wrap items-center gap-4"
                                >
                                    <img
                                        v-if="company.has_logo"
                                        :src="logoUrl"
                                        alt="Logótipo actual"
                                        class="h-12 w-auto rounded-lg bg-white object-contain p-1 ring-1 ring-zinc-200 dark:ring-white/10"
                                    />

                                    <input
                                        ref="logoInput"
                                        type="file"
                                        accept="image/png,image/jpeg"
                                        class="block text-sm text-zinc-600 file:mr-3 file:rounded-xl file:border-0 file:bg-zinc-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white dark:text-zinc-400 dark:file:bg-white dark:file:text-zinc-950"
                                        @change="uploadLogo"
                                    />

                                    <button
                                        v-if="company.has_logo"
                                        type="button"
                                        class="rounded-xl px-3 py-2 text-sm font-semibold text-zinc-500 focus-ring transition hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                                        @click="removeLogo"
                                    >
                                        Remover
                                    </button>
                                </div>
                                <FormError :message="logoForm.errors.logo" />
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl surface p-5 sm:p-7">
                        <div class="flex gap-4">
                            <span
                                class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                            >
                                <FileCode2 class="size-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Ficheiro SAF-T (AO)
                                </h2>
                                <p
                                    class="mt-1 max-w-xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    O ficheiro de auditoria que a AGT pede.
                                    Escolha o período e guarde o XML.
                                </p>

                                <div
                                    class="mt-4 flex flex-wrap items-end gap-3"
                                >
                                    <div class="w-40">
                                        <label
                                            class="block text-xs font-medium text-zinc-700 dark:text-zinc-300"
                                            >De</label
                                        >
                                        <DateInput
                                            v-model="saftFrom"
                                            class="mt-1.5"
                                            :clearable="false"
                                            aria-label="Início do período SAF-T"
                                        />
                                    </div>
                                    <div class="w-40">
                                        <label
                                            class="block text-xs font-medium text-zinc-700 dark:text-zinc-300"
                                            >Até</label
                                        >
                                        <DateInput
                                            v-model="saftTo"
                                            class="mt-1.5"
                                            :clearable="false"
                                            :min-date="saftFrom"
                                            aria-label="Fim do período SAF-T"
                                        />
                                    </div>
                                    <a
                                        :href="saftUrl"
                                        class="inline-flex h-10 items-center gap-2 rounded-full bg-accent-400 px-[1.125rem] text-sm font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] focus-ring transition hover:bg-accent-300 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <Download
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Gerar SAF-T
                                    </a>
                                </div>
                            </div>
                        </div>
                    </section>

                    <aside class="space-y-5">
                        <section
                            class="rounded-2xl bg-brand-950 p-5 text-white shadow-sm dark:bg-brand-950 dark:ring-1 dark:ring-white/10"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="grid size-10 place-items-center rounded-xl bg-white/10"
                                >
                                    <ShieldCheck
                                        class="size-5 text-accent-400"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <p class="font-semibold">
                                        O que já está feito
                                    </p>
                                    <p class="text-xs text-brand-100/65">
                                        Antes de emitir a primeira factura
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
                                                : 'text-accent-400'
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
                                                : 'text-accent-400'
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

                        <section class="rounded-2xl surface p-5">
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
                                As credenciais ficam cifradas e as chaves
                                privadas nunca saem do cofre. Enquanto estiver
                                em homologação, só consultamos as séries desse
                                ambiente.
                            </p>
                            <div
                                class="mt-4 rounded-xl bg-zinc-50 p-3 text-xs font-medium text-zinc-500 dark:bg-white/5 dark:text-zinc-400"
                            >
                                Estado: {{ agtConnection.status_label }}
                            </div>
                            <Link
                                v-if="profileIsConfigured"
                                :href="agtConnectionShow.url()"
                                class="flex h-10 w-full items-center justify-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
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
