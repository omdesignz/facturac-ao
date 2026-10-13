<script setup lang="ts">
import { Form, Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Check,
    Download,
    FileCode2,
    Image as ImageIcon,
    LoaderCircle,
    LockKeyhole,
    MapPin,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CompanySetupProgress from '@/components/CompanySetupProgress.vue';
import type { SetupProgress } from '@/components/CompanySetupProgress.vue';
import DateInput from '@/components/DateInput.vue';
import FileInput from '@/components/FileInput.vue';
import FormError from '@/components/FormError.vue';
import PageHeader from '@/components/PageHeader.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { localIsoDate, startOfYearIsoDate } from '@/lib/dates';
import { dashboard } from '@/routes';
import { update as updateBankDetails } from '@/routes/company/bank-details';
import {
    destroy as destroyCompanyLogo,
    show as showCompanyLogo,
    store as storeCompanyLogo,
} from '@/routes/company/logo';
import { index as userGuideIndex } from '@/routes/help/guide';
import { update as onboardingUpdate } from '@/routes/onboarding';
import { exportMethod as saftExport } from '@/routes/saft';

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
    setupProgress: SetupProgress;
    company: CompanyProfile;
    taxRegimes: TaxRegimeOption[];
    provinces: { value: string; label: string }[];
    canUpdate: boolean;
    canUpdateBankDetails: boolean;
    bankDetails: {
        bank_name: string;
        bank_account_holder: string;
        bank_account_number: string;
        bank_iban: string;
    };
    agtConnection: AgtConnectionSummary;
}>();

const banking = useForm({ ...props.bankDetails });
const bankFields = [
    { name: 'bank_name', label: 'Banco', max: 120 },
    { name: 'bank_account_holder', label: 'Titular da conta', max: 255 },
    { name: 'bank_account_number', label: 'Número da conta', max: 64 },
    { name: 'bank_iban', label: 'IBAN', max: 42 },
] as const;

function saveBankDetails(): void {
    banking.put(updateBankDetails.url(), { preserveScroll: true });
}

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
const regimeConfirmed = ref(false);

const provinceCode = ref(props.company.province_code);

const logoInput = ref<InstanceType<typeof FileInput> | null>(null);
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
            logoInput.value?.reset();
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

const saftFrom = ref(startOfYearIsoDate());
const saftTo = ref(localIsoDate());

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
                            :href="
                                userGuideIndex.url({
                                    query: { chapter: 'empresa' },
                                })
                            "
                            class="inline-flex min-h-10 items-center rounded-full px-4 text-sm font-semibold ring-1 ring-zinc-900/10 focus-ring active:opacity-75 dark:ring-white/15"
                        >
                            Guia passo a passo
                        </Link>
                        <Link
                            :href="dashboard.url()"
                            class="inline-flex h-10 items-center rounded-full bg-white px-[1.125rem] text-sm font-semibold text-zinc-900 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:hover:bg-white/10"
                        >
                            Voltar ao painel
                        </Link>
                    </template>
                </PageHeader>

                <CompanySetupProgress
                    :progress="setupProgress"
                    on-company-page
                />

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
                        id="company-profile"
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
                                    <label for="legal-name" class="field-label"
                                        >Denominação social</label
                                    >
                                    <input
                                        id="legal-name"
                                        name="legal_name"
                                        type="text"
                                        autocomplete="organization"
                                        required
                                        :value="company.legal_name"
                                        class="mt-2 block form-input"
                                    />
                                    <FormError :message="errors.legal_name" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="tax-id" class="field-label"
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
                                        class="mt-2 block form-input font-mono uppercase"
                                    />
                                    <FormError
                                        :message="
                                            errors.tax_identification_number
                                        "
                                    />
                                </div>
                                <div class="sm:col-span-3">
                                    <label for="trade-name" class="field-label"
                                        >Nome comercial
                                        <span
                                            class="font-normal text-zinc-500 dark:text-zinc-400"
                                            >(opcional)</span
                                        ></label
                                    >
                                    <input
                                        id="trade-name"
                                        name="trade_name"
                                        type="text"
                                        :value="company.trade_name"
                                        class="mt-2 block form-input"
                                    />
                                    <FormError :message="errors.trade_name" />
                                </div>
                                <div class="sm:col-span-3">
                                    <label for="tax-regime" class="field-label"
                                        >Regime de IVA</label
                                    >
                                    <SelectInput
                                        id="tax-regime"
                                        v-model="taxRegime"
                                        name="tax_regime"
                                        class="mt-2"
                                        :options="taxRegimeOptions"
                                        placeholder="Escolha o regime do seu cadastro"
                                        @update:model-value="
                                            regimeConfirmed = false
                                        "
                                    />
                                    <FormError :message="errors.tax_regime" />
                                    <p
                                        class="mt-2 text-xs/5 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Consulte o seu cadastro no Portal do
                                        Contribuinte. Não escolha pelo valor de
                                        IVA que pretende cobrar; em caso de
                                        dúvida, confirme com a AGT ou com o
                                        contabilista.
                                    </p>
                                    <label
                                        class="mt-3 flex items-start gap-3 text-sm/6"
                                        for="tax-regime-confirmed"
                                    >
                                        <input
                                            id="tax-regime-confirmed"
                                            v-model="regimeConfirmed"
                                            name="tax_regime_confirmed"
                                            type="checkbox"
                                            value="1"
                                            required
                                            class="mt-1 size-4 shrink-0"
                                            :aria-invalid="
                                                !!errors.tax_regime_confirmed
                                            "
                                            aria-describedby="tax-regime-confirmed-error"
                                        />
                                        Confirmo que este é o regime do meu
                                        cadastro fiscal.
                                    </label>
                                    <FormError
                                        id="tax-regime-confirmed-error"
                                        :message="errors.tax_regime_confirmed"
                                    />
                                </div>
                                <div class="sm:col-span-3">
                                    <label for="cae" class="field-label"
                                        >CAE principal</label
                                    >
                                    <input
                                        id="cae"
                                        name="main_cae_code"
                                        type="text"
                                        required
                                        :value="company.main_cae_code"
                                        class="mt-2 block form-input font-mono uppercase"
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
                                        class="field-label"
                                        >Código interno</label
                                    >
                                    <input
                                        id="establishment-code"
                                        name="establishment_code"
                                        type="text"
                                        required
                                        :value="company.establishment_code"
                                        class="mt-2 block form-input font-mono uppercase"
                                    />
                                    <FormError
                                        :message="errors.establishment_code"
                                    />
                                </div>
                                <div class="sm:col-span-4">
                                    <label
                                        for="establishment-name"
                                        class="field-label"
                                        >Nome do estabelecimento</label
                                    >
                                    <input
                                        id="establishment-name"
                                        name="establishment_name"
                                        type="text"
                                        required
                                        :value="company.establishment_name"
                                        class="mt-2 block form-input"
                                    />
                                    <FormError
                                        :message="errors.establishment_name"
                                    />
                                </div>
                                <div class="sm:col-span-6">
                                    <label
                                        for="address-line"
                                        class="field-label"
                                        >Morada</label
                                    >
                                    <input
                                        id="address-line"
                                        name="address_line"
                                        type="text"
                                        autocomplete="street-address"
                                        required
                                        :value="company.address_line"
                                        class="mt-2 block form-input"
                                    />
                                    <FormError :message="errors.address_line" />
                                </div>
                                <div class="sm:col-span-4">
                                    <label
                                        for="municipality"
                                        class="field-label"
                                        >Município</label
                                    >
                                    <input
                                        id="municipality"
                                        name="municipality"
                                        type="text"
                                        required
                                        :value="company.municipality"
                                        class="mt-2 block form-input"
                                    />
                                    <FormError :message="errors.municipality" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label
                                        for="province-code"
                                        class="field-label"
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
                                    Os dados fiscais estão protegidos após a
                                    associação do acesso à AGT. Peça apoio para
                                    corrigir o cadastro.
                                </p>
                                <p
                                    v-else
                                    class="text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{
                                        isDirty
                                            ? 'Existem alterações por guardar.'
                                            : 'Confirme os dados e guarde para continuar.'
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
                    <aside class="self-start">
                        <details class="rounded-2xl surface p-5">
                            <summary
                                class="cursor-pointer text-sm font-semibold focus-ring"
                            >
                                Logótipo, banco e arquivo · opcionais
                            </summary>
                            <p
                                class="mt-3 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                Pode tratar destas opções mais tarde. Não
                                impedem a preparação do acesso à AGT.
                            </p>
                            <div class="mt-5 space-y-5">
                                <section class="rounded-2xl surface p-5 sm:p-7">
                                    <div class="flex gap-4">
                                        <span
                                            class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                                        >
                                            <ImageIcon
                                                class="size-5"
                                                aria-hidden="true"
                                            />
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
                                                Aparece no cabeçalho das
                                                facturas e recibos. PNG ou JPEG
                                                até 2 MB — cabe numa caixa de 45
                                                × 18 mm, por isso uma marca
                                                larga sai melhor que uma alta.
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

                                                <FileInput
                                                    ref="logoInput"
                                                    aria-label="Escolher o logótipo"
                                                    accept="image/png,image/jpeg"
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
                                            <FormError
                                                :message="logoForm.errors.logo"
                                            />
                                        </div>
                                    </div>
                                </section>

                                <section class="rounded-2xl surface p-5 sm:p-7">
                                    <div class="flex gap-4">
                                        <span
                                            class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                                        >
                                            <FileCode2
                                                class="size-5"
                                                aria-hidden="true"
                                            />
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
                                                Exportação para arquivo e
                                                revisão contabilística. Escolha
                                                o período e guarde o XML.
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

                                <section
                                    class="rounded-2xl surface p-5 sm:p-7"
                                    aria-labelledby="bank-details-heading"
                                >
                                    <h2
                                        id="bank-details-heading"
                                        class="font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Dados bancários
                                    </h2>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Aparecem nas facturas e notas de débito
                                        por pagar. Indique o IBAN ou o número da
                                        conta. Alterações aplicam-se apenas aos
                                        próximos documentos.
                                    </p>
                                    <form
                                        class="mt-5 space-y-4"
                                        @submit.prevent="saveBankDetails"
                                    >
                                        <div
                                            v-for="field in bankFields"
                                            :key="field.name"
                                        >
                                            <label
                                                :for="field.name"
                                                class="block text-sm font-medium"
                                                >{{ field.label }}</label
                                            >
                                            <input
                                                :id="field.name"
                                                v-model="banking[field.name]"
                                                :name="field.name"
                                                :maxlength="field.max"
                                                :disabled="
                                                    !canUpdateBankDetails
                                                "
                                                type="text"
                                                class="mt-2 block form-input"
                                                :aria-invalid="
                                                    !!banking.errors[field.name]
                                                "
                                                :aria-describedby="
                                                    banking.errors[field.name]
                                                        ? `${field.name}-error`
                                                        : undefined
                                                "
                                            />
                                            <FormError
                                                :id="`${field.name}-error`"
                                                :message="
                                                    banking.errors[field.name]
                                                "
                                            />
                                        </div>
                                        <button
                                            v-if="canUpdateBankDetails"
                                            type="submit"
                                            :disabled="banking.processing"
                                            class="inline-flex min-h-10 items-center rounded-full bg-accent-400 px-5 text-sm font-semibold text-brand-950 focus-ring hover:bg-accent-300 active:bg-accent-500 disabled:opacity-50"
                                        >
                                            {{
                                                banking.processing
                                                    ? 'A guardar…'
                                                    : 'Guardar dados bancários'
                                            }}
                                        </button>
                                        <p v-else class="text-sm text-zinc-500">
                                            Um administrador pode configurar os
                                            dados bancários depois de guardar o
                                            perfil da empresa.
                                        </p>
                                    </form>
                                </section>
                            </div>
                        </details>
                    </aside>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
