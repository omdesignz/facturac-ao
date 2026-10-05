<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowUpRight,
    Boxes,
    LoaderCircle,
    PackagePlus,
    Search,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageStat from '@/components/PageStat.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { store as storeMovement } from '@/routes/stock/movements';
import type { SelectOption } from '@/types/select';

interface StockLevelRow {
    id: number;
    item_public_id: string;
    code: string;
    name: string;
    unit_of_measure: string;
    establishment: string;
    establishment_public_id: string;
    quantity: string;
    reorder_level: string | null;
    average_cost_minor: number;
    value_minor: number;
    below_reorder: boolean;
    negative: boolean;
    last_movement_at: string | null;
}

interface MovementRow {
    public_id: string;
    item: string;
    code: string;
    establishment: string;
    type: string;
    type_label: string;
    quantity: string;
    balance_after: string;
    reference: string | null;
    note: string | null;
    actor: string | null;
    automatic: boolean;
    moved_at: string;
}

interface MovementType {
    value: string;
    label: string;
    carries_cost: boolean;
    is_transfer: boolean;
}

const props = defineProps<{
    levels: StockLevelRow[];
    summary: {
        tracked_items: number;
        low_count: number;
        negative_count: number;
        total_value_minor: number;
        currency_code: string;
    };
    filters: { search: string; establishment: string; low: boolean };
    establishments: { value: string; label: string }[];
    trackedItems: { value: string; label: string }[];
    movementTypes: MovementType[];
    movements: MovementRow[];
    canManage: boolean;
}>();

const search = ref(props.filters.search);
const establishmentFilter = ref(props.filters.establishment);
const lowOnly = ref(props.filters.low);
const dialogOpen = ref(false);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function reload(): void {
    router.get(
        '/existencias',
        {
            search: search.value || undefined,
            establishment: establishmentFilter.value || undefined,
            low: lowOnly.value ? 1 : undefined,
        },
        {
            preserveState: true,
            replace: true,
            only: ['levels', 'summary', 'filters'],
        },
    );
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 300);
});

watch([establishmentFilter, lowOnly], reload);

const form = useForm({
    catalogue_item: '',
    establishment: '',
    type: 'purchase',
    quantity: '',
    unit_cost: '',
    destination_establishment: '',
    note: '',
});

const selectedType = computed(() =>
    props.movementTypes.find((type) => type.value === form.type),
);

const itemOptions = computed<SelectOption[]>(() => props.trackedItems);
const establishmentOptions = computed<SelectOption[]>(
    () => props.establishments,
);
const establishmentFilterOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Todos os estabelecimentos' },
    ...props.establishments,
]);
const typeOptions = computed<SelectOption[]>(() =>
    props.movementTypes.map((type) => ({
        value: type.value,
        label: type.label,
    })),
);

function openDialog(): void {
    form.reset();
    form.clearErrors();
    form.catalogue_item = props.trackedItems[0]?.value ?? '';
    form.establishment = props.establishments[0]?.value ?? '';
    form.type = 'purchase';
    dialogOpen.value = true;
}

function submit(): void {
    form.post(storeMovement.url(), {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}

const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function money(minor: number): string {
    return `${moneyFormatter.format(minor / 100)} ${props.summary.currency_code}`;
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

function isInbound(quantity: string): boolean {
    return !quantity.startsWith('-');
}
</script>

<template>
    <AppLayout>
        <Head title="Existências" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Artigos · Existências"
                    title="Existências"
                    description="O que tem em cada estabelecimento. Emitir uma factura dá baixa automaticamente; uma nota de crédito devolve."
                >
                    <template #actions>
                        <button
                            v-if="canManage && trackedItems.length > 0"
                            type="button"
                            class="inline-flex h-10 w-fit items-center gap-2 rounded-full bg-accent-400 px-[1.125rem] text-sm font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] focus-ring transition hover:bg-accent-300 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="openDialog"
                        >
                            <PackagePlus class="size-4" aria-hidden="true" />
                            Registar movimento
                        </button>
                    </template>
                    <template #stats>
                        <PageStat
                            label="Artigos seguidos"
                            :value="summary.tracked_items"
                        />
                        <PageStat
                            label="Valor em armazém"
                            :value="money(summary.total_value_minor)"
                        />
                        <PageStat
                            label="Abaixo do mínimo"
                            :value="summary.low_count"
                        >
                            <span
                                :class="
                                    summary.low_count > 0
                                        ? 'text-orange-600 dark:text-orange-400'
                                        : ''
                                "
                                >{{ summary.low_count }}</span
                            >
                        </PageStat>
                        <PageStat
                            label="Saldo negativo"
                            :value="summary.negative_count"
                        >
                            <span
                                :class="
                                    summary.negative_count > 0
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : ''
                                "
                                >{{ summary.negative_count }}</span
                            >
                        </PageStat>
                    </template>
                </PageHeader>

                <div
                    v-if="summary.negative_count > 0"
                    class="flex items-start gap-3 rounded-2xl bg-rose-50 p-4 text-sm/6 text-rose-900 ring-1 ring-rose-200 dark:bg-rose-400/10 dark:text-rose-200 dark:ring-rose-400/20"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        Há artigos com saldo negativo — vendeu mais do que tinha
                        registado. Corrija com um saldo inicial ou um acerto de
                        inventário; a facturação não é bloqueada por isto.
                    </p>
                </div>

                <div
                    class="overflow-hidden rounded-3xl bg-zinc-900/[0.04] dark:bg-white/[0.04]"
                >
                    <div
                        class="flex flex-col gap-3 border-b border-zinc-900/[0.07] p-4 sm:flex-row sm:items-center dark:border-white/10"
                    >
                        <label class="relative w-full sm:max-w-xs">
                            <span class="sr-only">Procurar artigo</span>
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-zinc-400"
                                aria-hidden="true"
                            />
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Nome ou código…"
                                class="w-full rounded-xl border-0 bg-zinc-50 py-2.5 pr-3 pl-9 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset placeholder:text-zinc-400 dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />
                        </label>

                        <div class="w-full sm:max-w-[16rem]">
                            <SelectInput
                                v-model="establishmentFilter"
                                :options="establishmentFilterOptions"
                            />
                        </div>

                        <label
                            class="flex items-center gap-2 text-sm text-zinc-700 sm:ms-auto dark:text-zinc-300"
                        >
                            <input
                                v-model="lowOnly"
                                type="checkbox"
                                class="size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/20"
                            />
                            Só abaixo do mínimo
                        </label>
                    </div>

                    <div
                        v-if="levels.length === 0"
                        class="flex flex-col items-center gap-2 px-4 py-16 text-center"
                    >
                        <Boxes
                            class="size-8 text-zinc-300 dark:text-zinc-600"
                            aria-hidden="true"
                        />
                        <p class="text-sm/6 text-zinc-500 dark:text-zinc-400">
                            {{
                                summary.tracked_items === 0
                                    ? 'Nenhum artigo controla existências. Active o controlo na ficha do artigo.'
                                    : 'Sem existências para este filtro.'
                            }}
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-900/[0.07] dark:border-white/10"
                            >
                                <tr>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Artigo
                                    </th>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Estabelecimento
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right eyebrow text-zinc-500"
                                    >
                                        Quantidade
                                    </th>
                                    <th
                                        class="hidden px-4 py-3 text-right eyebrow text-zinc-500 lg:table-cell"
                                    >
                                        Custo médio
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right eyebrow text-zinc-500"
                                    >
                                        Valor
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-900/[0.06] dark:divide-white/10"
                            >
                                <tr v-for="level in levels" :key="level.id">
                                    <td class="px-4 py-3">
                                        <p
                                            class="font-medium text-zinc-950 dark:text-white"
                                        >
                                            {{ level.name }}
                                        </p>
                                        <p
                                            class="numeric text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ level.code }}
                                        </p>
                                    </td>
                                    <td
                                        class="px-4 py-3 text-zinc-600 dark:text-zinc-400"
                                    >
                                        {{ level.establishment }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <span
                                            :class="[
                                                level.negative
                                                    ? 'text-rose-700 dark:text-rose-300'
                                                    : level.below_reorder
                                                      ? 'text-amber-700 dark:text-amber-300'
                                                      : 'text-zinc-950 dark:text-white',
                                                'numeric font-medium',
                                            ]"
                                        >
                                            {{ level.quantity }}
                                        </span>
                                        <span
                                            class="ms-1 text-xs text-zinc-500 dark:text-zinc-400"
                                            >{{ level.unit_of_measure }}</span
                                        >
                                        <p
                                            v-if="level.reorder_level"
                                            class="text-xs text-zinc-400 dark:text-zinc-500"
                                        >
                                            mín. {{ level.reorder_level }}
                                        </p>
                                    </td>
                                    <td
                                        class="hidden px-4 py-3 text-right numeric text-zinc-600 lg:table-cell dark:text-zinc-400"
                                    >
                                        {{ money(level.average_cost_minor) }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right numeric font-medium text-zinc-950 dark:text-white"
                                    >
                                        {{ money(level.value_minor) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <section
                    class="overflow-hidden rounded-3xl bg-zinc-900/[0.04] dark:bg-white/[0.04]"
                >
                    <header
                        class="border-b border-zinc-900/[0.07] p-4 dark:border-white/10"
                    >
                        <h2
                            class="text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Últimos movimentos
                        </h2>
                        <p
                            class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Cada entrada e saída, incluindo as automáticas dos
                            documentos emitidos.
                        </p>
                    </header>

                    <div
                        v-if="movements.length === 0"
                        class="px-4 py-12 text-center text-sm/6 text-zinc-500 dark:text-zinc-400"
                    >
                        Ainda não houve movimentos.
                    </div>

                    <ul
                        v-else
                        class="divide-y divide-zinc-900/[0.06] dark:divide-white/10"
                    >
                        <li
                            v-for="movement in movements"
                            :key="movement.public_id"
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                        >
                            <component
                                :is="
                                    isInbound(movement.quantity)
                                        ? ArrowUpRight
                                        : ArrowDownRight
                                "
                                :class="[
                                    isInbound(movement.quantity)
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-rose-600 dark:text-rose-400',
                                    'size-4 shrink-0',
                                ]"
                                aria-hidden="true"
                            />

                            <span
                                class="numeric font-medium text-zinc-950 dark:text-white"
                            >
                                {{ movement.quantity }}
                            </span>

                            <span
                                class="min-w-0 flex-1 truncate text-zinc-700 dark:text-zinc-300"
                            >
                                {{ movement.item }}
                                <span class="text-zinc-400 dark:text-zinc-500"
                                    >· {{ movement.establishment }}</span
                                >
                            </span>

                            <span
                                class="text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                {{ movement.type_label }}
                                <span v-if="movement.reference"
                                    >· {{ movement.reference }}</span
                                >
                            </span>

                            <span
                                class="numeric text-xs text-zinc-400 dark:text-zinc-500"
                            >
                                saldo {{ movement.balance_after }}
                            </span>

                            <span
                                class="text-xs text-zinc-400 dark:text-zinc-500"
                            >
                                {{ formatDate(movement.moved_at) }}
                            </span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <RecordDialog
            :open="dialogOpen"
            title="Registar movimento de existências"
            description="Entradas, acertos, transferências e quebras. As vendas são registadas automaticamente ao emitir."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Artigo
                        </label>
                        <div class="mt-2">
                            <SelectInput
                                v-model="form.catalogue_item"
                                :options="itemOptions"
                            />
                        </div>
                        <FormError :message="form.errors.catalogue_item" />
                    </div>

                    <div>
                        <label
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Tipo de movimento
                        </label>
                        <div class="mt-2">
                            <SelectInput
                                v-model="form.type"
                                :options="typeOptions"
                            />
                        </div>
                        <FormError :message="form.errors.type" />
                    </div>

                    <div>
                        <label
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Estabelecimento
                        </label>
                        <div class="mt-2">
                            <SelectInput
                                v-model="form.establishment"
                                :options="establishmentOptions"
                            />
                        </div>
                        <FormError :message="form.errors.establishment" />
                    </div>

                    <div v-if="selectedType?.is_transfer" class="sm:col-span-2">
                        <label
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Destino
                        </label>
                        <div class="mt-2">
                            <SelectInput
                                v-model="form.destination_establishment"
                                :options="establishmentOptions"
                            />
                        </div>
                        <FormError
                            :message="form.errors.destination_establishment"
                        />
                    </div>

                    <div>
                        <label
                            for="movement-quantity"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Quantidade
                        </label>
                        <input
                            id="movement-quantity"
                            v-model="form.quantity"
                            type="text"
                            inputmode="decimal"
                            placeholder="0,000"
                            class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                        />
                        <FormError :message="form.errors.quantity" />
                    </div>

                    <div v-if="selectedType?.carries_cost">
                        <label
                            for="movement-cost"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Custo unitário
                        </label>
                        <input
                            id="movement-cost"
                            v-model="form.unit_cost"
                            type="text"
                            inputmode="decimal"
                            placeholder="0,00"
                            class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                        />
                        <p
                            class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Entra na média ponderada que valoriza o armazém.
                        </p>
                        <FormError :message="form.errors.unit_cost" />
                    </div>

                    <div class="sm:col-span-2">
                        <label
                            for="movement-note"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Nota
                            <span class="text-zinc-400">(opcional)</span>
                        </label>
                        <input
                            id="movement-note"
                            v-model="form.note"
                            type="text"
                            maxlength="500"
                            placeholder="Ex.: guia do fornecedor n.º 88"
                            class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                        />
                        <FormError :message="form.errors.note" />
                    </div>
                </div>

                <div
                    class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
                >
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
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Registar
                    </button>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
