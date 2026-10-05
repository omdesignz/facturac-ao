<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Ban,
    Box,
    CircleCheck,
    FileDown,
    LoaderCircle,
    MapPin,
    PackagePlus,
    Plus,
    Printer,
    Save,
    Trash2,
    Truck,
    UserRound,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DateInput from '@/components/DateInput.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import {
    cancel as cancelTransportDocument,
    index as transportDocumentsIndex,
    issue as issueTransportDocument,
    pdf as transportDocumentPdf,
    print as printTransportDocument,
    store as storeTransportDocument,
    update as updateTransportDocument,
} from '@/routes/transport-documents';
import type { SelectOption } from '@/types/select';

interface AddressInput {
    address: string;
    city: string;
    province: string | null;
    country_code: string;
}

interface RecipientInput {
    name: string;
    tax_identification_number: string;
    country_code: string;
    address: string;
    city: string;
    province: string | null;
}

interface TransporterInput {
    name: string | null;
    tax_identification_number: string | null;
    vehicle_registration: string | null;
}

interface TransportLineInput {
    catalogue_item_public_id: string | null;
    product_code: string;
    product_description: string;
    quantity: string;
    unit_of_measure: string;
    unit_price: string;
    net_amount_minor?: number;
}

interface TransportDocumentForm {
    public_id: string;
    document_no: string | null;
    document_type: string;
    document_type_label: string;
    status: 'draft' | 'issued' | 'cancelled';
    status_label: string;
    is_editable: boolean;
    can_issue: boolean;
    can_cancel: boolean;
    revision: number;
    establishment_public_id: string;
    customer_public_id: string | null;
    movement_date: string;
    movement_start_at: string;
    movement_end_at: string | null;
    recipient: RecipientInput;
    origin: AddressInput;
    destination: AddressInput;
    transporter: TransporterInput;
    gross_weight_kg: string | null;
    package_count: number | null;
    notes: string | null;
    currency_code: string;
    gross_total_minor: number;
    cancellation_reason: string | null;
    issued_at: string | null;
    cancelled_at: string | null;
    lines: TransportLineInput[];
}

interface EstablishmentOption extends SelectOption<string> {
    code: string;
    address: string;
    city: string;
    province: string | null;
    country_code: string;
}

interface CustomerOption {
    public_id: string;
    name: string;
    tax_identification_number: string | null;
    country_code: string;
    address: string;
    city: string;
    province: string | null;
}

interface CatalogueItemOption {
    public_id: string;
    code: string;
    name: string;
    description: string | null;
    unit_of_measure: string;
    unit_price: string;
    tracks_stock: boolean;
}

const props = defineProps<{
    transportDocument: TransportDocumentForm | null;
    types: (SelectOption<string> & { short_label: string })[];
    establishments: EstablishmentOption[];
    customers: CustomerOption[];
    catalogueItems: CatalogueItemOption[];
    currencyCode: string;
}>();

function emptyLine(): TransportLineInput {
    return {
        catalogue_item_public_id: null,
        product_code: '',
        product_description: '',
        quantity: '1.000',
        unit_of_measure: 'UN',
        unit_price: '0.00',
    };
}

function emptyRecipient(): RecipientInput {
    return {
        name: '',
        tax_identification_number: '',
        country_code: 'AO',
        address: '',
        city: '',
        province: '',
    };
}

const now = new Date();
const today = now.toLocaleDateString('sv-SE');
const currentTime = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
const initialEstablishment = props.establishments[0];

const form = useForm({
    document_type: props.transportDocument?.document_type ?? 'GR',
    establishment_public_id:
        props.transportDocument?.establishment_public_id ??
        String(initialEstablishment?.value ?? ''),
    customer_public_id: props.transportDocument?.customer_public_id ?? '',
    movement_date: props.transportDocument?.movement_date ?? today,
    movement_start_at:
        props.transportDocument?.movement_start_at ?? `${today} ${currentTime}`,
    movement_end_at: props.transportDocument?.movement_end_at ?? '',
    recipient: props.transportDocument?.recipient ?? emptyRecipient(),
    origin: props.transportDocument?.origin ?? {
        address: initialEstablishment?.address ?? '',
        city: initialEstablishment?.city ?? '',
        province: initialEstablishment?.province ?? '',
        country_code: initialEstablishment?.country_code ?? 'AO',
    },
    destination: props.transportDocument?.destination ?? {
        address: '',
        city: '',
        province: '',
        country_code: 'AO',
    },
    transporter: props.transportDocument?.transporter ?? {
        name: '',
        tax_identification_number: '',
        vehicle_registration: '',
    },
    gross_weight_kg: props.transportDocument?.gross_weight_kg ?? '',
    package_count: props.transportDocument?.package_count ?? null,
    notes: props.transportDocument?.notes ?? '',
    lines: props.transportDocument?.lines ?? [emptyLine()],
});

const readOnly = computed(
    () =>
        props.transportDocument !== null &&
        !props.transportDocument.is_editable,
);
const isReturnNote = computed(() => form.document_type === 'GD');
const customerOptions = computed<SelectOption<string>[]>(() => [
    { value: '', label: 'Destinatário ocasional' },
    ...props.customers.map((customer) => ({
        value: customer.public_id,
        label: customer.name,
        hint: customer.tax_identification_number ?? '',
    })),
]);
const catalogueOptions = computed<SelectOption<string>[]>(() => [
    { value: '', label: 'Linha manual' },
    ...props.catalogueItems.map((item) => ({
        value: item.public_id,
        label: `${item.code} — ${item.name}`,
        hint: item.tracks_stock ? 'Controla inventário' : '',
    })),
]);
const selectedCustomer = computed(() =>
    props.customers.find(
        (customer) => customer.public_id === form.customer_public_id,
    ),
);

function selectCustomer(): void {
    const customer = selectedCustomer.value;

    if (customer === undefined) {
        form.recipient = emptyRecipient();

        return;
    }

    form.recipient = {
        name: customer.name,
        tax_identification_number: customer.tax_identification_number ?? '',
        country_code: customer.country_code,
        address: customer.address,
        city: customer.city,
        province: customer.province,
    };
    form.destination = {
        address: customer.address,
        city: customer.city,
        province: customer.province,
        country_code: customer.country_code,
    };
}

watch(
    () => form.document_type,
    (documentType, previousDocumentType) => {
        if (documentType !== 'GD' || previousDocumentType === 'GD') {
            return;
        }

        form.customer_public_id = '';
        form.recipient = emptyRecipient();
    },
);

function selectEstablishment(): void {
    const selected = props.establishments.find(
        (establishment) => establishment.value === form.establishment_public_id,
    );

    if (selected === undefined) {
        return;
    }

    form.origin = {
        address: selected.address,
        city: selected.city,
        province: selected.province,
        country_code: selected.country_code,
    };
}

function applyCatalogueItem(line: TransportLineInput): void {
    const item = props.catalogueItems.find(
        (candidate) => candidate.public_id === line.catalogue_item_public_id,
    );

    if (item === undefined) {
        return;
    }

    line.product_code = item.code;
    line.product_description = item.description ?? item.name;
    line.unit_of_measure = item.unit_of_measure;
    line.unit_price = item.unit_price;
}

function addLine(): void {
    form.lines.push(emptyLine());
}

function removeLine(index: number): void {
    if (form.lines.length > 1) {
        form.lines.splice(index, 1);
    }
}

function numeric(value: string | number | null): number {
    const parsed = Number(String(value ?? '0').replace(',', '.'));

    return Number.isFinite(parsed) ? parsed : 0;
}

function lineTotalMinor(line: TransportLineInput): number {
    const quantityUnits = Math.round(numeric(line.quantity) * 1000);
    const unitPriceMinor = Math.round(numeric(line.unit_price) * 100);

    return Math.trunc((quantityUnits * unitPriceMinor) / 1000);
}

const totalMinor = computed(() =>
    form.lines.reduce((total, line) => total + lineTotalMinor(line), 0),
);
const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function money(minor: number): string {
    return `${moneyFormatter.format(minor / 100)} ${props.currencyCode}`;
}

function submit(): void {
    if (props.transportDocument === null) {
        form.post(storeTransportDocument.url(), { preserveScroll: true });

        return;
    }

    form.put(updateTransportDocument.url(props.transportDocument.public_id), {
        preserveScroll: true,
    });
}

async function issueDocument(): Promise<void> {
    if (props.transportDocument === null || form.isDirty) {
        return;
    }

    const confirmed = await confirmAction({
        title: 'Emitir esta guia?',
        message:
            'A emissão atribui o número legal e congela destinatário, percurso e mercadorias. Depois só será possível anular com motivo.',
        confirmLabel: 'Emitir guia',
    });

    if (!confirmed) {
        return;
    }

    router.post(
        issueTransportDocument.url(props.transportDocument.public_id),
        { expected_revision: props.transportDocument.revision },
        { preserveScroll: true },
    );
}

const cancelDialogOpen = ref(false);
const cancelForm = useForm({ reason: '' });

function submitCancellation(): void {
    if (props.transportDocument === null) {
        return;
    }

    cancelForm.post(
        cancelTransportDocument.url(props.transportDocument.public_id),
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelDialogOpen.value = false;
                cancelForm.reset();
            },
        },
    );
}

function fieldError(name: string): string | undefined {
    return (form.errors as Record<string, string>)[name];
}

const statusTone = computed<'success' | 'warning' | 'danger' | 'neutral'>(
    () => {
        if (props.transportDocument?.status === 'issued') {
            return 'success';
        }

        if (props.transportDocument?.status === 'cancelled') {
            return 'danger';
        }

        return 'warning';
    },
);
</script>

<template>
    <AppLayout>
        <Head :title="transportDocument?.document_no ?? 'Nova guia'" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-7xl space-y-6">
                <FlashBanner />

                <header
                    class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <Link
                            :href="transportDocumentsIndex.url()"
                            class="inline-flex items-center gap-2 rounded text-sm font-semibold text-zinc-600 focus-ring hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white"
                        >
                            <ArrowLeft class="size-4" aria-hidden="true" />
                            Guias e transporte
                        </Link>
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <h1
                                class="text-3xl display text-zinc-950 dark:text-white"
                            >
                                {{
                                    transportDocument?.document_no ??
                                    'Nova guia'
                                }}
                            </h1>
                            <StatusBadge
                                v-if="transportDocument"
                                :label="transportDocument.status_label"
                                :tone="statusTone"
                            />
                        </div>
                        <p
                            class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            A guia acompanha a mercadoria desde a origem ao
                            destino. Confirme datas, moradas e quantidades antes
                            de emitir.
                        </p>
                    </div>

                    <div
                        v-if="transportDocument?.document_no"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <a
                            :href="
                                printTransportDocument.url(
                                    transportDocument.public_id,
                                )
                            "
                            target="_blank"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-full px-[1.125rem] text-sm font-semibold text-zinc-800 ring-1 ring-zinc-900/10 focus-ring ring-inset hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                        >
                            <Printer class="size-4" aria-hidden="true" />
                            Imprimir
                        </a>
                        <a
                            :href="
                                transportDocumentPdf.url(
                                    transportDocument.public_id,
                                )
                            "
                            target="_blank"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-full px-[1.125rem] text-sm font-semibold text-zinc-800 ring-1 ring-zinc-900/10 focus-ring ring-inset hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                        >
                            <FileDown class="size-4" aria-hidden="true" />
                            PDF
                        </a>
                    </div>
                </header>

                <div
                    v-if="transportDocument?.status === 'cancelled'"
                    class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200"
                >
                    <p class="font-semibold">Documento anulado</p>
                    <p class="mt-1">
                        {{ transportDocument.cancellation_reason }}
                    </p>
                </div>

                <form class="space-y-6" @submit.prevent="submit">
                    <fieldset
                        :disabled="readOnly"
                        class="space-y-6 disabled:opacity-90"
                    >
                        <section class="rounded-2xl surface p-5 sm:p-6">
                            <div class="flex items-start gap-3">
                                <Truck
                                    class="mt-0.5 size-5 text-brand-600 dark:text-brand-300"
                                    aria-hidden="true"
                                />
                                <div>
                                    <h2
                                        class="font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Documento e horário
                                    </h2>
                                    <p
                                        class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                    >
                                        O início do transporte deve ocorrer na
                                        data indicada.
                                    </p>
                                </div>
                            </div>

                            <div
                                class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5"
                            >
                                <div>
                                    <label
                                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                        >Tipo de guia</label
                                    >
                                    <SelectInput
                                        v-model="form.document_type"
                                        :options="types"
                                        :disabled="readOnly"
                                    />
                                    <FormError
                                        :message="fieldError('document_type')"
                                    />
                                </div>
                                <div class="xl:col-span-2">
                                    <label
                                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                        >Estabelecimento emissor</label
                                    >
                                    <SelectInput
                                        v-model="form.establishment_public_id"
                                        :options="establishments"
                                        :disabled="readOnly"
                                        @change="selectEstablishment"
                                    />
                                    <FormError
                                        :message="
                                            fieldError(
                                                'establishment_public_id',
                                            )
                                        "
                                    />
                                </div>
                                <div>
                                    <label
                                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                        >Data do movimento</label
                                    >
                                    <DateInput
                                        v-model="form.movement_date"
                                        :disabled="readOnly"
                                        :clearable="false"
                                    />
                                    <FormError
                                        :message="fieldError('movement_date')"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                        >Início</label
                                    >
                                    <DateInput
                                        v-model="form.movement_start_at"
                                        with-time
                                        :disabled="readOnly"
                                        :clearable="false"
                                    />
                                    <FormError
                                        :message="
                                            fieldError('movement_start_at')
                                        "
                                    />
                                </div>
                                <div
                                    class="sm:col-span-2 xl:col-span-2 xl:col-start-4"
                                >
                                    <label
                                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                        >Fim previsto
                                        <span class="font-normal text-zinc-400"
                                            >(opcional)</span
                                        ></label
                                    >
                                    <DateInput
                                        v-model="form.movement_end_at"
                                        with-time
                                        :disabled="readOnly"
                                    />
                                    <FormError
                                        :message="fieldError('movement_end_at')"
                                    />
                                </div>
                            </div>
                        </section>

                        <section class="grid gap-6 xl:grid-cols-2">
                            <article class="rounded-2xl surface p-5 sm:p-6">
                                <div class="flex items-start gap-3">
                                    <UserRound
                                        class="mt-0.5 size-5 text-brand-600 dark:text-brand-300"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <h2
                                            class="font-semibold text-zinc-950 dark:text-white"
                                        >
                                            {{
                                                isReturnNote
                                                    ? 'Fornecedor da devolução'
                                                    : 'Destinatário'
                                            }}
                                        </h2>
                                        <p
                                            class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                        >
                                            <template v-if="isReturnNote">
                                                Registe o fornecedor a quem os
                                                bens serão devolvidos. O SAF-T
                                                irá identificá-lo como
                                                SupplierID.
                                            </template>
                                            <template v-else>
                                                Seleccione um cliente ou registe
                                                os dados desta entrega.
                                            </template>
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-5 space-y-4">
                                    <div v-if="!isReturnNote">
                                        <label
                                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                            >Cliente guardado</label
                                        >
                                        <SelectInput
                                            v-model="form.customer_public_id"
                                            :options="customerOptions"
                                            :disabled="readOnly"
                                            @change="selectCustomer"
                                        />
                                        <FormError
                                            :message="
                                                fieldError('customer_public_id')
                                            "
                                        />
                                    </div>
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <label class="block sm:col-span-2">
                                            <span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >Nome ou razão social</span
                                            >
                                            <input
                                                v-model="form.recipient.name"
                                                type="text"
                                                class="form-input"
                                            />
                                            <FormError
                                                :message="
                                                    fieldError('recipient.name')
                                                "
                                            />
                                        </label>
                                        <label class="block">
                                            <span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >NIF</span
                                            >
                                            <input
                                                v-model="
                                                    form.recipient
                                                        .tax_identification_number
                                                "
                                                type="text"
                                                class="form-input numeric"
                                            />
                                            <FormError
                                                :message="
                                                    fieldError(
                                                        'recipient.tax_identification_number',
                                                    )
                                                "
                                            />
                                        </label>
                                        <label class="block">
                                            <span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >País</span
                                            >
                                            <input
                                                v-model="
                                                    form.recipient.country_code
                                                "
                                                type="text"
                                                maxlength="2"
                                                class="form-input uppercase"
                                            />
                                        </label>
                                    </div>
                                </div>
                            </article>

                            <article class="rounded-2xl surface p-5 sm:p-6">
                                <div class="flex items-start gap-3">
                                    <MapPin
                                        class="mt-0.5 size-5 text-brand-600 dark:text-brand-300"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <h2
                                            class="font-semibold text-zinc-950 dark:text-white"
                                        >
                                            {{
                                                isReturnNote
                                                    ? 'Morada do fornecedor'
                                                    : 'Morada do destinatário'
                                            }}
                                        </h2>
                                        <p
                                            class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                        >
                                            Esta morada identifica a
                                            contraparte, mesmo quando o destino
                                            físico é outro.
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                    <label class="block sm:col-span-2">
                                        <span
                                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                            >Morada</span
                                        >
                                        <input
                                            v-model="form.recipient.address"
                                            type="text"
                                            class="form-input"
                                        />
                                        <FormError
                                            :message="
                                                fieldError('recipient.address')
                                            "
                                        />
                                    </label>
                                    <label class="block">
                                        <span
                                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                            >Localidade</span
                                        >
                                        <input
                                            v-model="form.recipient.city"
                                            type="text"
                                            class="form-input"
                                        />
                                        <FormError
                                            :message="
                                                fieldError('recipient.city')
                                            "
                                        />
                                    </label>
                                    <label class="block">
                                        <span
                                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                            >Província</span
                                        >
                                        <input
                                            v-model="form.recipient.province"
                                            type="text"
                                            class="form-input"
                                        />
                                    </label>
                                </div>
                            </article>
                        </section>

                        <section class="rounded-2xl surface p-5 sm:p-6">
                            <div class="flex items-start gap-3">
                                <MapPin
                                    class="mt-0.5 size-5 text-brand-600 dark:text-brand-300"
                                    aria-hidden="true"
                                />
                                <div>
                                    <h2
                                        class="font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Percurso
                                    </h2>
                                    <p
                                        class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                    >
                                        Registe o ponto exacto de carga e o
                                        local onde a mercadoria será entregue.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 grid gap-6 lg:grid-cols-2">
                                <div
                                    class="rounded-xl border border-zinc-200 p-4 dark:border-white/10"
                                >
                                    <p class="eyebrow text-zinc-500">
                                        Origem / carga
                                    </p>
                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        <label class="block sm:col-span-2"
                                            ><span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >Morada</span
                                            ><input
                                                v-model="form.origin.address"
                                                type="text"
                                                class="form-input" /><FormError
                                                :message="
                                                    fieldError('origin.address')
                                                "
                                        /></label>
                                        <label class="block"
                                            ><span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >Localidade</span
                                            ><input
                                                v-model="form.origin.city"
                                                type="text"
                                                class="form-input" /><FormError
                                                :message="
                                                    fieldError('origin.city')
                                                "
                                        /></label>
                                        <label class="block"
                                            ><span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >Província</span
                                            ><input
                                                v-model="form.origin.province"
                                                type="text"
                                                class="form-input"
                                        /></label>
                                        <label class="block"
                                            ><span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >País</span
                                            ><input
                                                v-model="
                                                    form.origin.country_code
                                                "
                                                type="text"
                                                maxlength="2"
                                                class="form-input uppercase"
                                        /></label>
                                    </div>
                                </div>
                                <div
                                    class="rounded-xl border border-zinc-200 p-4 dark:border-white/10"
                                >
                                    <p class="eyebrow text-zinc-500">
                                        Destino / descarga
                                    </p>
                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        <label class="block sm:col-span-2"
                                            ><span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >Morada</span
                                            ><input
                                                v-model="
                                                    form.destination.address
                                                "
                                                type="text"
                                                class="form-input" /><FormError
                                                :message="
                                                    fieldError(
                                                        'destination.address',
                                                    )
                                                "
                                        /></label>
                                        <label class="block"
                                            ><span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >Localidade</span
                                            ><input
                                                v-model="form.destination.city"
                                                type="text"
                                                class="form-input" /><FormError
                                                :message="
                                                    fieldError(
                                                        'destination.city',
                                                    )
                                                "
                                        /></label>
                                        <label class="block"
                                            ><span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >Província</span
                                            ><input
                                                v-model="
                                                    form.destination.province
                                                "
                                                type="text"
                                                class="form-input"
                                        /></label>
                                        <label class="block"
                                            ><span
                                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                >País</span
                                            ><input
                                                v-model="
                                                    form.destination
                                                        .country_code
                                                "
                                                type="text"
                                                maxlength="2"
                                                class="form-input uppercase"
                                        /></label>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-2xl surface p-5 sm:p-6">
                            <div
                                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div class="flex items-start gap-3">
                                    <Box
                                        class="mt-0.5 size-5 text-brand-600 dark:text-brand-300"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <h2
                                            class="font-semibold text-zinc-950 dark:text-white"
                                        >
                                            Mercadorias
                                        </h2>
                                        <p
                                            class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                        >
                                            Quantidade e unidade são
                                            obrigatórias; o valor unitário
                                            alimenta o SAF-T.
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex h-10 items-center justify-center gap-2 rounded-full px-[1.125rem] text-sm font-semibold text-zinc-800 ring-1 ring-zinc-900/10 focus-ring ring-inset hover:bg-zinc-50 disabled:hidden dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                    @click="addLine"
                                >
                                    <Plus class="size-4" aria-hidden="true" />
                                    Nova linha
                                </button>
                            </div>

                            <div class="mt-5 space-y-3">
                                <article
                                    v-for="(line, index) in form.lines"
                                    :key="index"
                                    class="rounded-xl border border-zinc-200 p-4 dark:border-white/10"
                                >
                                    <div class="grid gap-4 lg:grid-cols-12">
                                        <div class="lg:col-span-4">
                                            <label
                                                class="mb-1.5 block text-xs font-semibold text-zinc-600 dark:text-zinc-400"
                                                >Artigo</label
                                            >
                                            <SelectInput
                                                :model-value="
                                                    line.catalogue_item_public_id ??
                                                    ''
                                                "
                                                :options="catalogueOptions"
                                                size="sm"
                                                :disabled="readOnly"
                                                @update:model-value="
                                                    line.catalogue_item_public_id =
                                                        String($event) || null
                                                "
                                                @change="
                                                    applyCatalogueItem(line)
                                                "
                                            />
                                        </div>
                                        <label class="block lg:col-span-2"
                                            ><span
                                                class="mb-1.5 block text-xs font-semibold text-zinc-600 dark:text-zinc-400"
                                                >Código</span
                                            ><input
                                                v-model="line.product_code"
                                                type="text"
                                                class="form-input form-input-sm numeric" /><FormError
                                                :message="
                                                    fieldError(
                                                        `lines.${index}.product_code`,
                                                    )
                                                "
                                        /></label>
                                        <label class="block lg:col-span-3"
                                            ><span
                                                class="mb-1.5 block text-xs font-semibold text-zinc-600 dark:text-zinc-400"
                                                >Descrição</span
                                            ><input
                                                v-model="
                                                    line.product_description
                                                "
                                                type="text"
                                                class="form-input form-input-sm" /><FormError
                                                :message="
                                                    fieldError(
                                                        `lines.${index}.product_description`,
                                                    )
                                                "
                                        /></label>
                                        <label class="block lg:col-span-1"
                                            ><span
                                                class="mb-1.5 block text-xs font-semibold text-zinc-600 dark:text-zinc-400"
                                                >Qtd.</span
                                            ><input
                                                v-model="line.quantity"
                                                inputmode="decimal"
                                                class="form-input form-input-sm text-right numeric" /><FormError
                                                :message="
                                                    fieldError(
                                                        `lines.${index}.quantity`,
                                                    )
                                                "
                                        /></label>
                                        <label class="block lg:col-span-1"
                                            ><span
                                                class="mb-1.5 block text-xs font-semibold text-zinc-600 dark:text-zinc-400"
                                                >Un.</span
                                            ><input
                                                v-model="line.unit_of_measure"
                                                type="text"
                                                class="form-input form-input-sm text-center uppercase"
                                        /></label>
                                        <div
                                            class="flex items-end justify-end lg:col-span-1"
                                        >
                                            <button
                                                type="button"
                                                class="icon-button text-zinc-400 focus-ring hover:bg-rose-50 hover:text-rose-700 disabled:hidden dark:hover:bg-rose-500/10 dark:hover:text-rose-300"
                                                :disabled="
                                                    form.lines.length === 1
                                                "
                                                aria-label="Remover linha"
                                                @click="removeLine(index)"
                                            >
                                                <Trash2
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </div>
                                        <label
                                            class="block lg:col-span-2 lg:col-start-9"
                                            ><span
                                                class="mb-1.5 block text-xs font-semibold text-zinc-600 dark:text-zinc-400"
                                                >Preço unitário</span
                                            ><input
                                                v-model="line.unit_price"
                                                inputmode="decimal"
                                                class="form-input form-input-sm text-right numeric" /><FormError
                                                :message="
                                                    fieldError(
                                                        `lines.${index}.unit_price`,
                                                    )
                                                "
                                        /></label>
                                        <div class="text-right lg:col-span-2">
                                            <p
                                                class="mb-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-400"
                                            >
                                                Valor da linha
                                            </p>
                                            <p
                                                class="numeric text-sm font-semibold text-zinc-950 dark:text-white"
                                            >
                                                {{
                                                    money(lineTotalMinor(line))
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </article>
                            </div>
                            <FormError :message="fieldError('lines')" />

                            <div
                                class="mt-5 flex justify-end border-t border-zinc-100 pt-5 dark:border-white/10"
                            >
                                <dl class="w-full max-w-sm">
                                    <div
                                        class="flex items-baseline justify-between gap-4"
                                    >
                                        <dt
                                            class="text-sm font-medium text-zinc-600 dark:text-zinc-400"
                                        >
                                            Valor total da mercadoria
                                        </dt>
                                        <dd
                                            class="numeric text-xl font-semibold text-zinc-950 dark:text-white"
                                        >
                                            {{ money(totalMinor) }}
                                        </dd>
                                    </div>
                                    <p
                                        class="mt-1 text-right text-xs text-zinc-500"
                                    >
                                        Sem incidência fiscal; este é um
                                        documento de movimento.
                                    </p>
                                </dl>
                            </div>
                        </section>

                        <section class="grid gap-6 xl:grid-cols-2">
                            <article class="rounded-2xl surface p-5 sm:p-6">
                                <div class="flex items-start gap-3">
                                    <PackagePlus
                                        class="mt-0.5 size-5 text-brand-600 dark:text-brand-300"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <h2
                                            class="font-semibold text-zinc-950 dark:text-white"
                                        >
                                            Transporte e carga
                                        </h2>
                                        <p
                                            class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                        >
                                            Dados úteis para identificar o
                                            veículo e a carga.
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                    <label class="block sm:col-span-2"
                                        ><span
                                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                            >Transportador</span
                                        ><input
                                            v-model="form.transporter.name"
                                            type="text"
                                            class="form-input"
                                    /></label>
                                    <label class="block"
                                        ><span
                                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                            >NIF do transportador</span
                                        ><input
                                            v-model="
                                                form.transporter
                                                    .tax_identification_number
                                            "
                                            type="text"
                                            class="form-input numeric"
                                    /></label>
                                    <label class="block"
                                        ><span
                                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                            >Matrícula</span
                                        ><input
                                            v-model="
                                                form.transporter
                                                    .vehicle_registration
                                            "
                                            type="text"
                                            class="form-input numeric uppercase"
                                    /></label>
                                    <label class="block"
                                        ><span
                                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                            >Peso bruto (kg)</span
                                        ><input
                                            v-model="form.gross_weight_kg"
                                            inputmode="decimal"
                                            class="form-input numeric"
                                    /></label>
                                    <label class="block"
                                        ><span
                                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                            >Volumes</span
                                        ><input
                                            v-model="form.package_count"
                                            type="number"
                                            min="0"
                                            class="form-input numeric"
                                    /></label>
                                </div>
                            </article>
                            <article class="rounded-2xl surface p-5 sm:p-6">
                                <label class="block"
                                    ><span
                                        class="mb-1.5 block font-semibold text-zinc-950 dark:text-white"
                                        >Observações</span
                                    ><textarea
                                        v-model="form.notes"
                                        rows="7"
                                        class="form-input resize-y"
                                        placeholder="Instruções de entrega, referência interna ou condição da mercadoria."
                                    />
                                </label>
                                <p class="mt-2 text-xs text-zinc-500">
                                    As observações ficam congeladas com a
                                    emissão.
                                </p>
                            </article>
                        </section>
                    </fieldset>

                    <section
                        class="sticky bottom-4 z-20 rounded-2xl bg-white/95 p-4 shadow-xl ring-1 ring-zinc-900/10 backdrop-blur dark:bg-zinc-900/95 dark:ring-white/10"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div
                                class="text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                <template v-if="readOnly"
                                    >Documento legal congelado após
                                    emissão.</template
                                >
                                <template
                                    v-else-if="
                                        form.isDirty && transportDocument
                                    "
                                    >Guarde as alterações antes de
                                    emitir.</template
                                >
                                <template v-else
                                    >Revise o percurso e as quantidades antes de
                                    emitir.</template
                                >
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    v-if="transportDocument?.can_cancel"
                                    type="button"
                                    class="inline-flex h-10 items-center justify-center gap-2 rounded-full px-[1.125rem] text-sm font-semibold text-rose-700 ring-1 ring-rose-200 focus-ring ring-inset hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-500/20 dark:hover:bg-rose-500/10"
                                    @click="cancelDialogOpen = true"
                                >
                                    <Ban
                                        class="size-4"
                                        aria-hidden="true"
                                    />Anular
                                </button>
                                <button
                                    v-if="!readOnly"
                                    type="submit"
                                    :disabled="form.processing"
                                    class="inline-flex h-10 items-center justify-center gap-2 rounded-full px-[1.125rem] text-sm font-semibold text-zinc-800 ring-1 ring-zinc-900/10 focus-ring ring-inset hover:bg-zinc-50 disabled:opacity-60 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                >
                                    <LoaderCircle
                                        v-if="form.processing"
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    /><Save
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />{{
                                        transportDocument
                                            ? 'Guardar alterações'
                                            : 'Guardar rascunho'
                                    }}
                                </button>
                                <button
                                    v-if="transportDocument?.can_issue"
                                    type="button"
                                    :disabled="form.isDirty"
                                    class="inline-flex h-10 items-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
                                    @click="issueDocument"
                                >
                                    <CircleCheck
                                        class="size-4"
                                        aria-hidden="true"
                                    />Emitir guia
                                </button>
                            </div>
                        </div>
                    </section>
                </form>
            </div>
        </div>

        <RecordDialog
            eyebrow="Guias e transporte"
            :open="cancelDialogOpen"
            title="Anular guia"
            description="A guia continuará no registo e no SAF-T com estado anulado."
            @close="cancelDialogOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submitCancellation">
                <label class="block"
                    ><span
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                        >Motivo da anulação</span
                    ><textarea
                        v-model="cancelForm.reason"
                        rows="3"
                        maxlength="50"
                        class="form-input resize-none"
                        placeholder="Ex.: Transporte cancelado pelo destinatário" /><FormError
                        :message="cancelForm.errors.reason"
                /></label>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-xl px-3.5 py-2.5 text-sm font-semibold text-zinc-600 focus-ring hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5"
                        @click="cancelDialogOpen = false"
                    >
                        Manter guia</button
                    ><button
                        type="submit"
                        :disabled="cancelForm.processing"
                        class="inline-flex items-center gap-2 rounded-xl bg-rose-700 px-4 py-2.5 text-sm font-semibold text-white focus-ring hover:bg-rose-600 disabled:opacity-60"
                    >
                        <LoaderCircle
                            v-if="cancelForm.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        /><Ban
                            v-else
                            class="size-4"
                            aria-hidden="true"
                        />Confirmar anulação
                    </button>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
