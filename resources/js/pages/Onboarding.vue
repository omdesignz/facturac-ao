<script setup lang="ts">
import { Form, Head, router, useForm, usePage } from '@inertiajs/vue3';
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
import AppButton from '@/components/AppButton.vue';
import CompanySetupProgress from '@/components/CompanySetupProgress.vue';
import type { SetupProgress } from '@/components/CompanySetupProgress.vue';
import DateInput from '@/components/DateInput.vue';
import FileInput from '@/components/FileInput.vue';
import FormError from '@/components/FormError.vue';
import FormField from '@/components/FormField.vue';
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

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell space-y-8">
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
                        <AppButton
                            variant="outline"
                            :href="
                                userGuideIndex.url({
                                    query: { chapter: 'empresa' },
                                })
                            "
                        >
                            Guia passo a passo
                        </AppButton>
                        <AppButton variant="outline" :href="dashboard.url()">
                            Voltar ao painel
                        </AppButton>
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
                                <FormField
                                    class="sm:col-span-4"
                                    for="legal-name"
                                    label="Denominação social"
                                    :error="errors.legal_name"
                                    ><input
                                        id="legal-name"
                                        name="legal_name"
                                        type="text"
                                        autocomplete="organization"
                                        required
                                        :value="company.legal_name"
                                        class="block form-input"
                                /></FormField>
                                <FormField
                                    class="sm:col-span-2"
                                    for="tax-id"
                                    label="NIF"
                                    :error="errors.tax_identification_number"
                                    ><input
                                        id="tax-id"
                                        name="tax_identification_number"
                                        type="text"
                                        inputmode="text"
                                        required
                                        :value="
                                            company.tax_identification_number
                                        "
                                        class="block form-input font-mono uppercase"
                                /></FormField>
                                <FormField
                                    class="sm:col-span-3"
                                    for="trade-name"
                                    label="Nome comercial"
                                    optional
                                    :error="errors.trade_name"
                                    ><input
                                        id="trade-name"
                                        name="trade_name"
                                        type="text"
                                        :value="company.trade_name"
                                        class="block form-input"
                                /></FormField>
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
                                            class="form-checkbox mt-1"
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
                                <FormField
                                    class="sm:col-span-3"
                                    for="cae"
                                    label="CAE principal"
                                    :error="errors.main_cae_code"
                                    ><input
                                        id="cae"
                                        name="main_cae_code"
                                        type="text"
                                        required
                                        :value="company.main_cae_code"
                                        class="block form-input font-mono uppercase"
                                        placeholder="Ex.: 62010"
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
                                <FormField
                                    class="sm:col-span-2"
                                    for="establishment-code"
                                    label="Código interno"
                                    :error="errors.establishment_code"
                                    ><input
                                        id="establishment-code"
                                        name="establishment_code"
                                        type="text"
                                        required
                                        :value="company.establishment_code"
                                        class="block form-input font-mono uppercase"
                                /></FormField>
                                <FormField
                                    class="sm:col-span-4"
                                    for="establishment-name"
                                    label="Nome do estabelecimento"
                                    :error="errors.establishment_name"
                                    ><input
                                        id="establishment-name"
                                        name="establishment_name"
                                        type="text"
                                        required
                                        :value="company.establishment_name"
                                        class="block form-input"
                                /></FormField>
                                <FormField
                                    class="sm:col-span-6"
                                    for="address-line"
                                    label="Morada"
                                    :error="errors.address_line"
                                    ><input
                                        id="address-line"
                                        name="address_line"
                                        type="text"
                                        autocomplete="street-address"
                                        required
                                        :value="company.address_line"
                                        class="block form-input"
                                /></FormField>
                                <FormField
                                    class="sm:col-span-4"
                                    for="municipality"
                                    label="Município"
                                    :error="errors.municipality"
                                    ><input
                                        id="municipality"
                                        name="municipality"
                                        type="text"
                                        required
                                        :value="company.municipality"
                                        class="block form-input"
                                /></FormField>
                                <FormField
                                    class="sm:col-span-2"
                                    for="province-code"
                                    label="Província"
                                    :error="errors.province_code"
                                    ><SelectInput
                                        id="province-code"
                                        v-model="provinceCode"
                                        name="province_code"
                                        :options="provinceOptions"
                                        placeholder="Escolha a província"
                                /></FormField>
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
                                <AppButton
                                    variant="ink"
                                    v-if="canUpdate"
                                    type="submit"
                                    :disabled="processing"
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
                                </AppButton>
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
                                                <AppButton
                                                    variant="gold"
                                                    external
                                                    :href="saftUrl"
                                                >
                                                    <Download
                                                        class="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    Gerar SAF-T
                                                </AppButton>
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
                                        <AppButton
                                            variant="gold"
                                            v-if="canUpdateBankDetails"
                                            type="submit"
                                            :disabled="banking.processing"
                                        >
                                            {{
                                                banking.processing
                                                    ? 'A guardar…'
                                                    : 'Guardar dados bancários'
                                            }}
                                        </AppButton>
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
