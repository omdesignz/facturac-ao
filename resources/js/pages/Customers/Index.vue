<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    LoaderCircle,
    Mail,
    Pencil,
    Phone,
    Plus,
    Search,
    UserRoundX,
    Users,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DataTable from '@/components/DataTable.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormError from '@/components/FormError.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { destroy, index, show, store, update } from '@/routes/customers';
import { index as importsIndex } from '@/routes/imports';
import { index as priceListsIndex } from '@/routes/price-lists';
import type { SelectOption } from '@/types/select';

interface Customer {
    public_id: string;
    name: string;
    tax_identification_number: string;
    country_code: string;
    address_line: string | null;
    email: string | null;
    phone: string | null;
    is_active: boolean;
    payment_terms_days: number;
    credit_limit: string;
    price_list: string;
    auto_send_documents: boolean;
    withholding_type: string;
    withholding_rate: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    customers: {
        data: Customer[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { search: string };
    priceLists: { value: string; label: string }[];
    withholdingTypes: {
        value: string;
        label: string;
        suggested_rate: string;
    }[];
    canManage: boolean;
}>();

/** A blank choice is meaningful here: it means the catalogue price. */
const priceListOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Preço do catálogo' },
    ...props.priceLists,
]);

/** Likewise: blank means this buyer pays the whole invoice. */
const withholdingTypeOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Não retém' },
    ...props.withholdingTypes.map((type) => ({
        value: type.value,
        label: type.label,
    })),
]);

const search = ref(props.filters.search);
const dialogOpen = ref(false);
const editing = ref<Customer | null>(null);

const searchInput = ref<HTMLInputElement | null>(null);
const searching = ref(false);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            index.url(),
            { search: value || undefined },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['customers', 'filters'],
                onStart: () => {
                    searching.value = true;
                },
                onFinish: () => {
                    searching.value = false;
                },
            },
        );
    }, 300);
});

/**
 * A pending search must not fire after the user has gone elsewhere, either
 * because this page is already gone or because a click on another page has
 * started a visit that is still on its way.
 */
const stopWatchingVisits = router.on('before', (event) => {
    if (event.detail.visit.url.pathname !== index.url()) {
        clearTimeout(searchTimer);
    }
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    stopWatchingVisits();
});

function clearSearch(): void {
    search.value = '';
    searchInput.value?.focus();
}

const form = useForm({
    name: '',
    tax_identification_number: '',
    country_code: 'AO',
    address_line: '',
    email: '',
    phone: '',
    is_active: true,
    payment_terms_days: 0,
    credit_limit: '',
    price_list: '',
    auto_send_documents: false,
    withholding_type: '',
    withholding_rate: '',
});

/**
 * Fills in the customary rate when a tax is chosen and none is set.
 *
 * Leaves a rate already typed alone — what was agreed with this buyer beats
 * what is usual.
 */
watch(
    () => form.withholding_type,
    (value) => {
        if (value === '') {
            form.withholding_rate = '';

            return;
        }

        if (form.withholding_rate === '') {
            form.withholding_rate =
                props.withholdingTypes.find((type) => type.value === value)
                    ?.suggested_rate ?? '';
        }
    },
);

const countryOptions = [
    { value: 'AO', label: 'Angola' },
    { value: 'PT', label: 'Portugal' },
    { value: 'BR', label: 'Brasil' },
    { value: 'ZA', label: 'África do Sul' },
    { value: 'CN', label: 'China' },
    { value: 'US', label: 'Estados Unidos' },
];

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(customer: Customer): void {
    editing.value = customer;
    form.clearErrors();
    form.name = customer.name;
    form.tax_identification_number = customer.tax_identification_number;
    form.country_code = customer.country_code;
    form.address_line = customer.address_line ?? '';
    form.email = customer.email ?? '';
    form.phone = customer.phone ?? '';
    form.is_active = customer.is_active;
    form.payment_terms_days = customer.payment_terms_days;
    form.credit_limit = customer.credit_limit;
    form.price_list = customer.price_list;
    form.auto_send_documents = customer.auto_send_documents;
    form.withholding_type = customer.withholding_type;
    form.withholding_rate = customer.withholding_rate;
    dialogOpen.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value === null) {
        form.post(store.url(), options);

        return;
    }

    form.put(update.url(editing.value.public_id), options);
}

/** Laravel's pagination labels arrive as HTML entities (&laquo; Anterior). */
function pageLabel(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&nbsp;/g, ' ')
        .trim();
}

async function deactivate(customer: Customer): Promise<void> {
    const confirmed = await confirmAction({
        title: `Desactivar ${customer.name}?`,
        message:
            'Deixa de aparecer ao criar facturas. As já emitidas continuam a mostrá-lo.',
        confirmLabel: 'Desactivar cliente',
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroy.url(customer.public_id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Clientes" />

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell space-y-6">
                <PageHeader
                    eyebrow="Cobrar"
                    title="Clientes"
                    description="Quem factura com mais frequência. Guarde uma vez e escolha na factura, sem voltar a escrever o NIF."
                >
                    <template #actions>
                        <div
                            class="relative w-full [--field-adorn-end:2.5rem] sm:w-72"
                        >
                            <label
                                class="field-adorned [--field-adorn-start:2.5rem]"
                            >
                                <span class="sr-only">Procurar clientes</span>
                                <Search
                                    class="field-adornment-start size-4"
                                    aria-hidden="true"
                                />
                                <input
                                    ref="searchInput"
                                    v-model="search"
                                    type="search"
                                    autocomplete="off"
                                    spellcheck="false"
                                    enterkeyhint="search"
                                    placeholder="Nome, NIF ou email"
                                    class="form-search"
                                />
                            </label>
                            <button
                                v-if="search"
                                type="button"
                                class="absolute top-1/2 right-0 icon-button -translate-y-1/2 rounded-full text-zinc-500 focus-ring transition hover:text-zinc-900 dark:hover:text-white"
                                @click="clearSearch"
                            >
                                <span class="sr-only">Limpar procura</span>
                                <X class="size-4" aria-hidden="true" />
                            </button>
                        </div>
                        <AppButton
                            variant="gold"
                            v-if="canManage"
                            @click="openCreate"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Novo cliente
                        </AppButton>
                    </template>
                </PageHeader>

                <div
                    class="overflow-hidden rounded-3xl bg-zinc-900/[0.04] dark:bg-white/[0.04]"
                >
                    <EmptyState
                        v-if="customers.data.length === 0"
                        bare
                        :icon="Users"
                        :role="filters.search ? 'status' : undefined"
                        :title="
                            filters.search
                                ? 'Nenhum cliente corresponde à procura'
                                : 'Ainda não guardou nenhum cliente'
                        "
                        :description="
                            filters.search
                                ? 'Tente outro nome ou NIF.'
                                : 'Pode adicionar um agora ou importar a sua lista a partir do Excel.'
                        "
                    >
                        <div
                            class="flex flex-wrap items-center justify-center gap-2.5"
                        >
                            <AppButton
                                variant="outline"
                                v-if="filters.search"
                                @click="clearSearch"
                            >
                                Limpar procura
                            </AppButton>
                            <template v-else>
                                <AppButton
                                    variant="gold"
                                    v-if="canManage"
                                    @click="openCreate"
                                >
                                    <Plus class="size-4" aria-hidden="true" />
                                    Novo cliente
                                </AppButton>
                                <AppButton
                                    variant="outline"
                                    :href="importsIndex.url()"
                                >
                                    Importar
                                </AppButton>
                            </template>
                        </div>
                    </EmptyState>

                    <div
                        v-else
                        class="relative overflow-x-auto overscroll-x-contain"
                        :aria-busy="searching"
                        :class="searching ? 'opacity-60' : ''"
                    >
                        <DataTable
                            ><template #head>
                                <th>Cliente</th>
                                <th class="hidden sm:table-cell">NIF</th>
                                <th class="hidden lg:table-cell">Contacto</th>
                                <th class="hidden md:table-cell">Estado</th>
                                <th>
                                    <span class="sr-only">Acções</span>
                                </th>
                            </template>
                            <tr
                                v-for="customer in customers.data"
                                :key="customer.public_id"
                                class="hover:bg-zinc-900/[0.025] dark:hover:bg-white/[0.03]"
                            >
                                <td>
                                    <Link
                                        data-nav-row
                                        :href="show.url(customer.public_id)"
                                        class="rounded font-semibold [overflow-wrap:anywhere] text-zinc-900 underline-offset-4 focus-ring hover:underline dark:text-white"
                                    >
                                        {{ customer.name }}
                                    </Link>
                                    <p
                                        class="mt-0.5 font-mono numeric text-xs text-zinc-500 sm:hidden dark:text-zinc-400"
                                    >
                                        {{ customer.tax_identification_number }}
                                    </p>
                                    <p
                                        v-if="customer.address_line"
                                        class="mt-0.5 max-w-[16rem] text-xs [overflow-wrap:anywhere] text-zinc-500 dark:text-zinc-400"
                                    >
                                        {{ customer.address_line }} ·
                                        {{ customer.country_code }}
                                    </p>
                                    <StatusBadge
                                        class="mt-1.5 md:hidden"
                                        :label="
                                            customer.is_active
                                                ? 'Activo'
                                                : 'Inactivo'
                                        "
                                        :tone="
                                            customer.is_active
                                                ? 'neutral'
                                                : 'draft'
                                        "
                                    />
                                </td>
                                <td
                                    class="hidden font-mono numeric text-zinc-700 sm:table-cell dark:text-zinc-300"
                                >
                                    {{ customer.tax_identification_number }}
                                </td>
                                <td
                                    class="hidden text-zinc-600 lg:table-cell dark:text-zinc-400"
                                >
                                    <span
                                        v-if="customer.email"
                                        class="flex items-center gap-1.5 text-xs"
                                    >
                                        <Mail
                                            class="size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        {{ customer.email }}
                                    </span>
                                    <span
                                        v-if="customer.phone"
                                        class="mt-1 flex items-center gap-1.5 text-xs"
                                    >
                                        <Phone
                                            class="size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        {{ customer.phone }}
                                    </span>
                                    <span
                                        v-if="
                                            !customer.email && !customer.phone
                                        "
                                        class="text-xs text-zinc-500 dark:text-zinc-400"
                                        >—</span
                                    >
                                </td>
                                <td class="hidden md:table-cell">
                                    <StatusBadge
                                        :label="
                                            customer.is_active
                                                ? 'Activo'
                                                : 'Inactivo'
                                        "
                                        :tone="
                                            customer.is_active
                                                ? 'neutral'
                                                : 'draft'
                                        "
                                    />
                                </td>
                                <td class="text-right">
                                    <div
                                        v-if="canManage"
                                        data-row-actions
                                        class="flex justify-end gap-1"
                                    >
                                        <button
                                            type="button"
                                            class="icon-button rounded-full text-zinc-500 focus-ring transition hover:bg-zinc-900/[0.06] hover:text-zinc-900 dark:hover:bg-white/10 dark:hover:text-white"
                                            @click="openEdit(customer)"
                                        >
                                            <span class="sr-only"
                                                >Editar
                                                {{ customer.name }}</span
                                            >
                                            <Pencil
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </button>
                                        <button
                                            v-if="customer.is_active"
                                            type="button"
                                            class="icon-button rounded-full text-zinc-500 focus-ring transition hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-400/10 dark:hover:text-rose-400"
                                            @click="deactivate(customer)"
                                        >
                                            <span class="sr-only"
                                                >Desactivar
                                                {{ customer.name }}</span
                                            >
                                            <UserRoundX
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </DataTable>
                    </div>

                    <nav
                        v-if="customers.data.length > 0"
                        aria-label="Paginação"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-900/[0.07] px-4 py-3 dark:border-white/10"
                    >
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="numeric"
                                >{{ customers.from }}–{{ customers.to }}</span
                            >
                            de
                            <span class="numeric">{{ customers.total }}</span>
                        </p>
                        <div
                            v-if="customers.links.length > 3"
                            class="flex flex-wrap gap-1"
                        >
                            <template
                                v-for="link in customers.links"
                                :key="link.label"
                            >
                                <Link
                                    v-if="link.url"
                                    :href="link.url"
                                    preserve-scroll
                                    :aria-current="
                                        link.active ? 'page' : undefined
                                    "
                                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-full px-2.5 text-xs font-semibold focus-ring transition"
                                    :class="
                                        link.active
                                            ? 'bg-brand-950 text-white dark:bg-zinc-100 dark:text-brand-950'
                                            : 'text-zinc-600 hover:bg-zinc-900/[0.05] dark:text-zinc-300 dark:hover:bg-white/5'
                                    "
                                    >{{ pageLabel(link.label) }}</Link
                                >
                                <span
                                    v-else
                                    aria-disabled="true"
                                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-full px-2.5 text-xs font-semibold text-zinc-300 dark:text-zinc-600"
                                    >{{ pageLabel(link.label) }}</span
                                >
                            </template>
                        </div>
                    </nav>
                </div>
            </div>
        </div>

        <RecordDialog
            eyebrow="Clientes"
            :open="dialogOpen"
            :title="editing ? 'Editar cliente' : 'Novo cliente'"
            description="O NIF identifica o cliente na AGT e não pode repetir-se."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        class="sm:col-span-2"
                        for="customer-name"
                        label="Nome ou denominação"
                        :error="form.errors.name"
                        error-id="customer-name-error"
                        ><input
                            id="customer-name"
                            v-model="form.name"
                            type="text"
                            required
                            autocomplete="off"
                            class="block form-input"
                            :aria-invalid="
                                form.errors.name ? 'true' : undefined
                            "
                            :aria-describedby="
                                form.errors.name
                                    ? 'customer-name-error'
                                    : undefined
                            "
                    /></FormField>

                    <FormField
                        for="customer-nif"
                        label="NIF"
                        :error="form.errors.tax_identification_number"
                        error-id="customer-nif-error"
                        ><input
                            id="customer-nif"
                            v-model="form.tax_identification_number"
                            type="text"
                            required
                            autocomplete="off"
                            autocapitalize="characters"
                            spellcheck="false"
                            class="block form-input font-mono uppercase"
                            :aria-invalid="
                                form.errors.tax_identification_number
                                    ? 'true'
                                    : undefined
                            "
                            :aria-describedby="
                                form.errors.tax_identification_number
                                    ? 'customer-nif-error'
                                    : undefined
                            "
                    /></FormField>

                    <FormField
                        for="customer-country"
                        label="País"
                        :error="form.errors.country_code"
                        error-id="customer-country-error"
                        ><SelectInput
                            id="customer-country"
                            v-model="form.country_code"
                            :options="countryOptions"
                            :invalid="!!form.errors.country_code"
                            :describedby="
                                form.errors.country_code
                                    ? 'customer-country-error'
                                    : undefined
                            "
                    /></FormField>

                    <div class="sm:col-span-2">
                        <label for="customer-address" class="field-label"
                            >Morada
                            <span class="text-zinc-500 dark:text-zinc-400"
                                >(opcional)</span
                            ></label
                        >
                        <input
                            id="customer-address"
                            v-model="form.address_line"
                            type="text"
                            autocomplete="off"
                            class="mt-2 block form-input"
                            :aria-invalid="
                                form.errors.address_line ? 'true' : undefined
                            "
                            :aria-describedby="
                                form.errors.address_line
                                    ? 'customer-address-error'
                                    : undefined
                            "
                        />
                        <FormError
                            id="customer-address-error"
                            :message="form.errors.address_line"
                        />
                    </div>

                    <div>
                        <label for="customer-email" class="field-label"
                            >Email
                            <span class="text-zinc-500 dark:text-zinc-400"
                                >(opcional)</span
                            ></label
                        >
                        <input
                            id="customer-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="off"
                            autocapitalize="none"
                            spellcheck="false"
                            class="mt-2 block form-input"
                            :aria-invalid="
                                form.errors.email ? 'true' : undefined
                            "
                            :aria-describedby="
                                form.errors.email
                                    ? 'customer-email-error'
                                    : undefined
                            "
                        />
                        <FormError
                            id="customer-email-error"
                            :message="form.errors.email"
                        />
                    </div>

                    <div>
                        <label for="customer-phone" class="field-label"
                            >Telefone
                            <span class="text-zinc-500 dark:text-zinc-400"
                                >(opcional)</span
                            ></label
                        >
                        <input
                            id="customer-phone"
                            v-model="form.phone"
                            type="tel"
                            autocomplete="off"
                            class="mt-2 block form-input"
                            :aria-invalid="
                                form.errors.phone ? 'true' : undefined
                            "
                            :aria-describedby="
                                form.errors.phone
                                    ? 'customer-phone-error'
                                    : undefined
                            "
                        />
                        <FormError
                            id="customer-phone-error"
                            :message="form.errors.phone"
                        />
                    </div>
                </div>

                <div class="rounded-xl bg-zinc-50 p-4 dark:bg-white/5">
                    <p
                        class="text-sm font-medium text-zinc-900 dark:text-zinc-100"
                    >
                        Conta corrente
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        O que foi acordado com este cliente sobre pagar mais
                        tarde. O vencimento das facturas passa a sair daqui.
                    </p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <FormField
                            for="customer-terms"
                            label="Prazo de pagamento"
                            :error="form.errors.payment_terms_days"
                            error-id="customer-terms-error"
                            ><div class="flex items-center gap-2">
                                <input
                                    id="customer-terms"
                                    v-model.number="form.payment_terms_days"
                                    type="number"
                                    min="0"
                                    max="365"
                                    class="form-input w-24! numeric"
                                    :aria-invalid="
                                        form.errors.payment_terms_days
                                            ? 'true'
                                            : undefined
                                    "
                                    :aria-describedby="
                                        form.errors.payment_terms_days
                                            ? 'customer-terms-error'
                                            : undefined
                                    "
                                />
                                <span
                                    class="text-sm text-zinc-500 dark:text-zinc-400"
                                    >dias</span
                                >
                            </div>
                            <p class="field-hint">
                                Zero significa pronto pagamento.
                            </p></FormField
                        >

                        <div>
                            <label for="customer-limit" class="field-label">
                                Limite de crédito
                                <span class="text-zinc-500 dark:text-zinc-400"
                                    >(opcional)</span
                                >
                            </label>
                            <input
                                id="customer-limit"
                                v-model="form.credit_limit"
                                type="text"
                                inputmode="decimal"
                                placeholder="Sem limite"
                                class="mt-2 block form-input numeric"
                                :aria-invalid="
                                    form.errors.credit_limit
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="
                                    form.errors.credit_limit
                                        ? 'customer-limit-error'
                                        : undefined
                                "
                            />
                            <p class="field-hint">
                                Avisamos quando a dívida se aproximar deste
                                valor.
                            </p>
                            <FormError
                                id="customer-limit-error"
                                :message="form.errors.credit_limit"
                            />
                        </div>

                        <FormField
                            class="sm:col-span-2"
                            for="customer-price-list"
                            label="Tabela de preços"
                            :error="form.errors.price_list"
                            error-id="customer-price-list-error"
                            ><div>
                                <SelectInput
                                    id="customer-price-list"
                                    v-model="form.price_list"
                                    :options="priceListOptions"
                                    placeholder="Preço do catálogo"
                                    :invalid="!!form.errors.price_list"
                                    :describedby="
                                        form.errors.price_list
                                            ? 'customer-price-list-error'
                                            : undefined
                                    "
                                />
                            </div>
                            <p class="field-hint">
                                <template v-if="priceLists.length === 0">
                                    Ainda não há tabelas.
                                    <Link
                                        :href="priceListsIndex.url()"
                                        class="font-medium text-brand-700 underline-offset-2 hover:underline dark:text-brand-300"
                                        >Crie uma</Link
                                    >
                                    para praticar o mesmo preço com um grupo de
                                    clientes.
                                </template>
                                <template v-else>
                                    O que combinar só com este cliente vale
                                    sempre mais que a tabela.
                                </template>
                            </p></FormField
                        >

                        <div class="sm:col-span-2">
                            <label
                                for="customer-withholding"
                                class="field-label"
                            >
                                Retenção habitual
                            </label>
                            <div
                                class="mt-2 grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem]"
                            >
                                <SelectInput
                                    id="customer-withholding"
                                    v-model="form.withholding_type"
                                    :options="withholdingTypeOptions"
                                    placeholder="Não retém"
                                    :invalid="!!form.errors.withholding_type"
                                    :describedby="
                                        form.errors.withholding_type
                                            ? 'customer-withholding-error'
                                            : undefined
                                    "
                                />
                                <input
                                    v-model="form.withholding_rate"
                                    type="text"
                                    inputmode="decimal"
                                    :disabled="form.withholding_type === ''"
                                    placeholder="%"
                                    aria-label="Taxa de retenção em percentagem"
                                    class="block form-input text-right numeric"
                                    :aria-invalid="
                                        form.errors.withholding_rate
                                            ? 'true'
                                            : undefined
                                    "
                                    :aria-describedby="
                                        form.errors.withholding_rate
                                            ? 'customer-withholding-rate-error'
                                            : undefined
                                    "
                                />
                            </div>
                            <p class="field-hint">
                                Para clientes que entregam o imposto
                                directamente à AGT — o Estado, grandes
                                contribuintes, bancos. Fica sugerida em cada
                                documento.
                            </p>
                            <FormError
                                id="customer-withholding-error"
                                :message="form.errors.withholding_type"
                            />
                            <FormError
                                id="customer-withholding-rate-error"
                                :message="form.errors.withholding_rate"
                            />
                        </div>
                    </div>

                    <ToggleSwitch
                        v-model="form.auto_send_documents"
                        class="mt-4"
                    >
                        <template #label
                            >Enviar os documentos por email assim que forem
                            emitidos</template
                        >
                    </ToggleSwitch>
                </div>

                <ToggleSwitch v-model="form.is_active">
                    <template #label>Disponível ao criar facturas</template>
                </ToggleSwitch>

                <div class="flex justify-end gap-3 pt-2">
                    <AppButton variant="outline" @click="dialogOpen = false">
                        Cancelar
                    </AppButton>
                    <AppButton
                        variant="ink"
                        type="submit"
                        :disabled="form.processing"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Guardar
                    </AppButton>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
