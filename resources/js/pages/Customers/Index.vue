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
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormError from '@/components/FormError.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { destroy, show, store, update } from '@/routes/customers';
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

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            '/customers',
            { search: value || undefined },
            {
                preserveState: true,
                replace: true,
                only: ['customers', 'filters'],
            },
        );
    }, 300);
});

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

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl space-y-6">
                <PageHeader
                    eyebrow="Clientes"
                    title="Clientes"
                    description="Quem factura com mais frequência. Guarde uma vez e escolha na factura, sem voltar a escrever o NIF."
                >
                    <template #actions>
                        <label class="relative w-full sm:w-72">
                            <span class="sr-only">Procurar clientes</span>
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-zinc-400"
                                aria-hidden="true"
                            />
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Nome, NIF ou email"
                                class="h-10 w-full rounded-full bg-zinc-900/[0.045] pr-4 pl-10 text-sm text-zinc-950 outline-none placeholder:text-zinc-400 focus:bg-white focus:ring-2 focus:ring-brand-950 dark:bg-white/[0.06] dark:text-white dark:focus:bg-zinc-900 dark:focus:ring-zinc-200"
                            />
                        </label>
                        <button
                            v-if="canManage"
                            type="button"
                            class="inline-flex h-10 items-center gap-2 rounded-full bg-accent-400 px-[1.125rem] text-sm font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] focus-ring transition hover:bg-accent-300"
                            @click="openCreate"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Novo cliente
                        </button>
                    </template>
                </PageHeader>

                <div
                    class="overflow-hidden rounded-3xl bg-zinc-900/[0.04] dark:bg-white/[0.04]"
                >
                    <div
                        v-if="customers.data.length === 0"
                        class="px-6 py-16 text-center"
                    >
                        <Users
                            class="mx-auto size-8 text-zinc-300 dark:text-zinc-600"
                            aria-hidden="true"
                        />
                        <p
                            class="mt-4 font-semibold text-zinc-900 dark:text-white"
                        >
                            {{
                                filters.search
                                    ? 'Nenhum cliente corresponde à procura'
                                    : 'Ainda não guardou nenhum cliente'
                            }}
                        </p>
                        <p
                            class="mx-auto mt-1 max-w-sm text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                filters.search
                                    ? 'Tente outro nome ou NIF.'
                                    : 'Pode adicionar um agora ou importar a sua lista a partir do Excel.'
                            }}
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b border-zinc-900/[0.07] text-left dark:border-white/10"
                                >
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Cliente
                                    </th>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        NIF
                                    </th>
                                    <th
                                        class="hidden px-4 py-3 eyebrow text-zinc-500 lg:table-cell"
                                    >
                                        Contacto
                                    </th>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Estado
                                    </th>
                                    <th class="px-4 py-3">
                                        <span class="sr-only">Acções</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-900/[0.06] dark:divide-white/10"
                            >
                                <tr
                                    v-for="customer in customers.data"
                                    :key="customer.public_id"
                                    class="transition hover:bg-zinc-900/[0.025] dark:hover:bg-white/[0.03]"
                                >
                                    <td class="px-4 py-3">
                                        <Link
                                            :href="show.url(customer.public_id)"
                                            class="rounded font-semibold text-zinc-900 underline-offset-4 focus-ring hover:underline dark:text-white"
                                        >
                                            {{ customer.name }}
                                        </Link>
                                        <p
                                            v-if="customer.address_line"
                                            class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ customer.address_line }} ·
                                            {{ customer.country_code }}
                                        </p>
                                    </td>
                                    <td
                                        class="px-4 py-3 font-mono numeric text-zinc-700 dark:text-zinc-300"
                                    >
                                        {{ customer.tax_identification_number }}
                                    </td>
                                    <td
                                        class="hidden px-4 py-3 text-zinc-600 lg:table-cell dark:text-zinc-400"
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
                                                !customer.email &&
                                                !customer.phone
                                            "
                                            class="text-xs text-zinc-400"
                                            >—</span
                                        >
                                    </td>
                                    <td class="px-4 py-3">
                                        <StatusBadge
                                            :label="
                                                customer.is_active
                                                    ? 'Activo'
                                                    : 'Inactivo'
                                            "
                                            :tone="
                                                customer.is_active
                                                    ? 'success'
                                                    : 'neutral'
                                            "
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div
                                            v-if="canManage"
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
                            </tbody>
                        </table>
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
                            <component
                                :is="link.url ? 'button' : 'span'"
                                v-for="link in customers.links"
                                :key="link.label"
                                :class="[
                                    link.active
                                        ? 'bg-brand-950 text-white dark:bg-zinc-100 dark:text-brand-950'
                                        : link.url
                                          ? 'text-zinc-600 hover:bg-zinc-900/[0.05] dark:text-zinc-300 dark:hover:bg-white/5'
                                          : 'text-zinc-300 dark:text-zinc-600',
                                    'inline-flex h-8 min-w-8 items-center justify-center rounded-full px-2.5 text-xs font-semibold',
                                ]"
                                @click="link.url && router.get(link.url)"
                                >{{ pageLabel(link.label) }}</component
                            >
                        </div>
                    </nav>
                </div>
            </div>
        </div>

        <RecordDialog
            :open="dialogOpen"
            :title="editing ? 'Editar cliente' : 'Novo cliente'"
            description="O NIF identifica o cliente na AGT e não pode repetir-se."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label
                            for="customer-name"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Nome ou denominação</label
                        >
                        <input
                            id="customer-name"
                            v-model="form.name"
                            type="text"
                            required
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.name" />
                    </div>

                    <div>
                        <label
                            for="customer-nif"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >NIF</label
                        >
                        <input
                            id="customer-nif"
                            v-model="form.tax_identification_number"
                            type="text"
                            required
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError
                            :message="form.errors.tax_identification_number"
                        />
                    </div>

                    <div>
                        <label
                            for="customer-country"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >País</label
                        >
                        <SelectInput
                            id="customer-country"
                            v-model="form.country_code"
                            class="mt-2"
                            :options="countryOptions"
                        />
                        <FormError :message="form.errors.country_code" />
                    </div>

                    <div class="sm:col-span-2">
                        <label
                            for="customer-address"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Morada
                            <span class="text-zinc-400">(opcional)</span></label
                        >
                        <input
                            id="customer-address"
                            v-model="form.address_line"
                            type="text"
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.address_line" />
                    </div>

                    <div>
                        <label
                            for="customer-email"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Email
                            <span class="text-zinc-400">(opcional)</span></label
                        >
                        <input
                            id="customer-email"
                            v-model="form.email"
                            type="email"
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.email" />
                    </div>

                    <div>
                        <label
                            for="customer-phone"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Telefone
                            <span class="text-zinc-400">(opcional)</span></label
                        >
                        <input
                            id="customer-phone"
                            v-model="form.phone"
                            type="tel"
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.phone" />
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
                        <div>
                            <label
                                for="customer-terms"
                                class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >
                                Prazo de pagamento
                            </label>
                            <div class="mt-2 flex items-center gap-2">
                                <input
                                    id="customer-terms"
                                    v-model.number="form.payment_terms_days"
                                    type="number"
                                    min="0"
                                    max="365"
                                    class="w-24 rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                />
                                <span
                                    class="text-sm text-zinc-500 dark:text-zinc-400"
                                    >dias</span
                                >
                            </div>
                            <p
                                class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                Zero significa pronto pagamento.
                            </p>
                            <FormError
                                :message="form.errors.payment_terms_days"
                            />
                        </div>

                        <div>
                            <label
                                for="customer-limit"
                                class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >
                                Limite de crédito
                                <span class="text-zinc-400">(opcional)</span>
                            </label>
                            <input
                                id="customer-limit"
                                v-model="form.credit_limit"
                                type="text"
                                inputmode="decimal"
                                placeholder="Sem limite"
                                class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />
                            <p
                                class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                Avisamos quando a dívida se aproximar deste
                                valor.
                            </p>
                            <FormError :message="form.errors.credit_limit" />
                        </div>

                        <div class="sm:col-span-2">
                            <label
                                class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >
                                Tabela de preços
                            </label>
                            <div class="mt-2">
                                <SelectInput
                                    v-model="form.price_list"
                                    :options="priceListOptions"
                                    placeholder="Preço do catálogo"
                                />
                            </div>
                            <p
                                class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
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
                            </p>
                            <FormError :message="form.errors.price_list" />
                        </div>

                        <div class="sm:col-span-2">
                            <label
                                class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >
                                Retenção habitual
                            </label>
                            <div
                                class="mt-2 grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem]"
                            >
                                <SelectInput
                                    v-model="form.withholding_type"
                                    :options="withholdingTypeOptions"
                                    placeholder="Não retém"
                                />
                                <input
                                    v-model="form.withholding_rate"
                                    type="text"
                                    inputmode="decimal"
                                    :disabled="form.withholding_type === ''"
                                    placeholder="%"
                                    aria-label="Taxa de retenção em percentagem"
                                    class="block w-full rounded-xl bg-white px-3 py-2.5 text-right numeric text-sm text-zinc-900 focus-ring outline-1 -outline-offset-1 outline-zinc-300 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white/5 dark:text-white dark:outline-white/10"
                                />
                            </div>
                            <p
                                class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                Para clientes que entregam o imposto
                                directamente à AGT — o Estado, grandes
                                contribuintes, bancos. Fica sugerida em cada
                                documento.
                            </p>
                            <FormError
                                :message="form.errors.withholding_type"
                            />
                            <FormError
                                :message="form.errors.withholding_rate"
                            />
                        </div>
                    </div>

                    <label
                        class="mt-4 flex w-fit items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                    >
                        <input
                            v-model="form.auto_send_documents"
                            type="checkbox"
                            class="size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/15 dark:bg-white/5"
                        />
                        Enviar os documentos por email assim que forem emitidos
                    </label>
                </div>

                <label
                    class="flex w-fit items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                >
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/15 dark:bg-white/5"
                    />
                    Disponível ao criar facturas
                </label>

                <div class="flex justify-end gap-3 pt-2">
                    <button
                        type="button"
                        class="rounded-xl px-4 py-2.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                        @click="dialogOpen = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:opacity-60 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Guardar
                    </button>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
