<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    LoaderCircle,
    Package,
    Pencil,
    Plus,
    Search,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormError from '@/components/FormError.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { destroy, store, update } from '@/routes/catalogue';
import type { SelectOption } from '@/types/select';

interface CatalogueItem {
    public_id: string;
    code: string;
    type: string;
    type_label: string;
    name: string;
    description: string | null;
    unit_of_measure: string;
    unit_price_minor: number;
    unit_price: string;
    currency_code: string;
    tax_type: string;
    tax_code: string | null;
    tax_percentage: string;
    tax_exemption_code: string | null;
    is_active: boolean;
    tracks_stock: boolean;
    reorder_level: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    items: {
        data: CatalogueItem[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { search: string; type: string };
    types: { value: string; label: string }[];
    currencyCode: string;
    canManage: boolean;
}>();

const search = ref(props.filters.search);
const typeFilter = ref(props.filters.type || 'all');
const dialogOpen = ref(false);
const editing = ref<CatalogueItem | null>(null);

const typeFilterOptions = computed<SelectOption[]>(() => [
    { value: 'all', label: 'Todos os tipos' },
    ...props.types.map((type) => ({ value: type.value, label: type.label })),
]);

const typeOptions = computed<SelectOption[]>(() =>
    props.types.map((type) => ({ value: type.value, label: type.label })),
);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function reload(): void {
    router.get(
        '/catalogue',
        {
            search: search.value || undefined,
            type: typeFilter.value === 'all' ? undefined : typeFilter.value,
        },
        { preserveState: true, replace: true, only: ['items', 'filters'] },
    );
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 300);
});

watch(typeFilter, reload);

const form = useForm({
    code: '',
    type: 'service',
    name: '',
    description: '',
    unit_of_measure: 'UN',
    unit_price: '0.00',
    tax_type: 'IVA',
    tax_code: '',
    tax_percentage: '14.00',
    tax_exemption_code: '',
    is_active: true,
    tracks_stock: false,
    reorder_level: '',
});

function formatMoney(minor: number): string {
    return new Intl.NumberFormat('pt-AO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100);
}

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(item: CatalogueItem): void {
    editing.value = item;
    form.clearErrors();
    form.code = item.code;
    form.type = item.type;
    form.name = item.name;
    form.description = item.description ?? '';
    form.unit_of_measure = item.unit_of_measure;
    form.unit_price = item.unit_price;
    form.tax_type = item.tax_type;
    form.tax_code = item.tax_code ?? '';
    form.tax_percentage = item.tax_percentage;
    form.tax_exemption_code = item.tax_exemption_code ?? '';
    form.is_active = item.is_active;
    form.tracks_stock = item.tracks_stock;
    form.reorder_level = item.reorder_level;
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

async function deactivate(item: CatalogueItem): Promise<void> {
    const confirmed = await confirmAction({
        title: `Desactivar ${item.name}?`,
        message:
            'Deixa de aparecer ao criar facturas. As já emitidas continuam a mostrá-lo.',
        confirmLabel: 'Desactivar artigo',
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroy.url(item.public_id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Artigos e serviços" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl space-y-6">
                <header
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p class="eyebrow text-brand-700 dark:text-brand-300">
                            Registos
                        </p>
                        <h1
                            class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                        >
                            Artigos e serviços
                        </h1>
                        <p
                            class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            O que vende, com o preço e o IVA já definidos. Numa
                            factura basta escolher — os valores entram sozinhos.
                        </p>
                    </div>
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex w-fit items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                        @click="openCreate"
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        Novo artigo
                    </button>
                </header>

                <div class="overflow-hidden rounded-2xl surface">
                    <div
                        class="flex flex-col gap-3 border-b border-zinc-100 p-4 sm:flex-row sm:items-center dark:border-white/10"
                    >
                        <label class="relative w-full sm:max-w-xs">
                            <span class="sr-only">Procurar artigos</span>
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-zinc-400"
                                aria-hidden="true"
                            />
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Nome, código ou descrição…"
                                class="block w-full rounded-xl bg-white py-2.5 pr-3 pl-9 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 placeholder:text-zinc-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                            />
                        </label>
                        <SelectInput
                            v-model="typeFilter"
                            class="w-full sm:max-w-[12rem]"
                            aria-label="Filtrar por tipo"
                            :options="typeFilterOptions"
                        />
                        <p
                            class="text-xs text-zinc-500 sm:ml-auto dark:text-zinc-400"
                        >
                            <span class="numeric">{{ items.total }}</span>
                            {{ items.total === 1 ? 'artigo' : 'artigos' }}
                        </p>
                    </div>

                    <div
                        v-if="items.data.length === 0"
                        class="px-6 py-16 text-center"
                    >
                        <Package
                            class="mx-auto size-8 text-zinc-300 dark:text-zinc-600"
                            aria-hidden="true"
                        />
                        <p
                            class="mt-4 font-semibold text-zinc-900 dark:text-white"
                        >
                            {{
                                filters.search || filters.type
                                    ? 'Nenhum artigo corresponde aos filtros'
                                    : 'O catálogo está vazio'
                            }}
                        </p>
                        <p
                            class="mx-auto mt-1 max-w-sm text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                filters.search || filters.type
                                    ? 'Tente outro termo ou limpe o filtro de tipo.'
                                    : 'Guarde o que vende com mais frequência para não repetir preços e IVA em cada factura.'
                            }}
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b border-zinc-100 text-left dark:border-white/10"
                                >
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Artigo
                                    </th>
                                    <th
                                        class="hidden px-4 py-3 eyebrow text-zinc-500 sm:table-cell"
                                    >
                                        Tipo
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right eyebrow text-zinc-500"
                                    >
                                        Preço
                                    </th>
                                    <th
                                        class="hidden px-4 py-3 text-right eyebrow text-zinc-500 md:table-cell"
                                    >
                                        IVA
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
                                class="divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <tr
                                    v-for="item in items.data"
                                    :key="item.public_id"
                                >
                                    <td class="px-4 py-3">
                                        <p
                                            class="font-semibold text-zinc-900 dark:text-white"
                                        >
                                            {{ item.name }}
                                        </p>
                                        <p
                                            class="mt-0.5 font-mono numeric text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ item.code }} ·
                                            {{ item.unit_of_measure }}
                                        </p>
                                    </td>
                                    <td
                                        class="hidden px-4 py-3 text-zinc-600 sm:table-cell dark:text-zinc-400"
                                    >
                                        {{ item.type_label }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right numeric font-semibold text-zinc-900 dark:text-white"
                                    >
                                        {{ formatMoney(item.unit_price_minor) }}
                                        <span
                                            class="text-xs font-normal text-zinc-400"
                                            >{{ item.currency_code }}</span
                                        >
                                    </td>
                                    <td
                                        class="hidden px-4 py-3 text-right numeric text-zinc-600 md:table-cell dark:text-zinc-400"
                                    >
                                        {{ item.tax_percentage }}%
                                    </td>
                                    <td class="px-4 py-3">
                                        <StatusBadge
                                            :label="
                                                item.is_active
                                                    ? 'Activo'
                                                    : 'Inactivo'
                                            "
                                            :tone="
                                                item.is_active
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
                                                class="icon-button text-zinc-500 focus-ring transition hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-white/5 dark:hover:text-white"
                                                @click="openEdit(item)"
                                            >
                                                <span class="sr-only"
                                                    >Editar
                                                    {{ item.name }}</span
                                                >
                                                <Pencil
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                            <button
                                                v-if="item.is_active"
                                                type="button"
                                                class="icon-button text-zinc-500 focus-ring transition hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-400/10 dark:hover:text-rose-400"
                                                @click="deactivate(item)"
                                            >
                                                <span class="sr-only"
                                                    >Desactivar
                                                    {{ item.name }}</span
                                                >
                                                <Archive
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
                        v-if="items.links.length > 3"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 px-4 py-3 dark:border-white/10"
                    >
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="numeric"
                                >{{ items.from }}–{{ items.to }}</span
                            >
                            de <span class="numeric">{{ items.total }}</span>
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <component
                                :is="link.url ? 'button' : 'span'"
                                v-for="link in items.links"
                                :key="link.label"
                                :class="[
                                    link.active
                                        ? 'bg-brand-700 text-white dark:bg-accent-400 dark:text-brand-950'
                                        : link.url
                                          ? 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5'
                                          : 'text-zinc-300 dark:text-zinc-600',
                                    'rounded-lg px-2.5 py-1.5 text-xs font-semibold',
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
            :title="editing ? 'Editar artigo' : 'Novo artigo'"
            description="O código identifica o artigo internamente e nas importações."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-5 sm:grid-cols-6">
                    <div class="sm:col-span-2">
                        <label
                            for="item-code"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Código</label
                        >
                        <input
                            id="item-code"
                            v-model="form.code"
                            type="text"
                            required
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.code" />
                    </div>

                    <div class="sm:col-span-2">
                        <label
                            for="item-type"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Tipo</label
                        >
                        <SelectInput
                            id="item-type"
                            v-model="form.type"
                            class="mt-2"
                            :options="typeOptions"
                        />
                        <FormError :message="form.errors.type" />
                    </div>

                    <div class="sm:col-span-2">
                        <label
                            for="item-unit"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Unidade</label
                        >
                        <input
                            id="item-unit"
                            v-model="form.unit_of_measure"
                            type="text"
                            required
                            placeholder="UN"
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.unit_of_measure" />
                    </div>

                    <div class="sm:col-span-6">
                        <label
                            for="item-name"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Designação</label
                        >
                        <input
                            id="item-name"
                            v-model="form.name"
                            type="text"
                            required
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.name" />
                    </div>

                    <div class="sm:col-span-3">
                        <label
                            for="item-price"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Preço unitário ({{ currencyCode }})</label
                        >
                        <input
                            id="item-price"
                            v-model="form.unit_price"
                            type="text"
                            inputmode="decimal"
                            required
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-right font-mono numeric text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.unit_price" />
                    </div>

                    <div class="sm:col-span-3">
                        <label
                            for="item-tax"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >IVA (%)</label
                        >
                        <input
                            id="item-tax"
                            v-model="form.tax_percentage"
                            type="text"
                            inputmode="decimal"
                            required
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-right font-mono numeric text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.tax_percentage" />
                    </div>

                    <div class="sm:col-span-6">
                        <label
                            for="item-description"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Descrição
                            <span class="text-zinc-400">(opcional)</span></label
                        >
                        <textarea
                            id="item-description"
                            v-model="form.description"
                            rows="2"
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.description" />
                    </div>

                    <div class="sm:col-span-3">
                        <label
                            for="item-exemption"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Código de isenção
                            <span class="text-zinc-400"
                                >(se aplicável)</span
                            ></label
                        >
                        <input
                            id="item-exemption"
                            v-model="form.tax_exemption_code"
                            type="text"
                            class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        />
                        <FormError :message="form.errors.tax_exemption_code" />
                    </div>
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

                <!-- A service has no warehouse balance to draw down, so the
                     option only appears for products. -->
                <div
                    v-if="form.type === 'product'"
                    class="rounded-xl bg-zinc-50 p-4 dark:bg-white/5"
                >
                    <label
                        class="flex w-fit items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                    >
                        <input
                            v-model="form.tracks_stock"
                            type="checkbox"
                            class="size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/15 dark:bg-white/5"
                        />
                        Controlar existências deste artigo
                    </label>
                    <p
                        class="ms-7 mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        Emitir uma factura dá baixa; uma nota de crédito
                        devolve.
                    </p>

                    <div v-if="form.tracks_stock" class="ms-7 mt-4 max-w-xs">
                        <label
                            for="item-reorder"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Nível mínimo
                            <span class="text-zinc-400">(opcional)</span>
                        </label>
                        <input
                            id="item-reorder"
                            v-model="form.reorder_level"
                            type="text"
                            inputmode="decimal"
                            placeholder="0,000"
                            class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                        />
                        <p
                            class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Avisamos quando o saldo chegar a este valor.
                        </p>
                        <FormError :message="form.errors.reorder_level" />
                    </div>
                </div>

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
