<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowLeftRight,
    ArrowUpRight,
    Boxes,
    LoaderCircle,
    PackagePlus,
    Search,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DataTable from '@/components/DataTable.vue';
import EmptyState from '@/components/EmptyState.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageStat from '@/components/PageStat.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { currencyLabel } from '@/lib/pos-ui';
import { index as stockIndex } from '@/routes/stock';
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
        stockIndex.url(),
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
    return `${moneyFormatter.format(minor / 100)} ${currencyLabel(props.summary.currency_code)}`;
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
        <Head title="Inventário" />

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Registos"
                    title="Inventário"
                    description="O que tem em cada estabelecimento. Emitir uma factura dá baixa automaticamente; uma nota de crédito devolve."
                >
                    <template #actions>
                        <AppButton
                            variant="gold"
                            v-if="canManage && trackedItems.length > 0"
                            @click="openDialog"
                            class="w-fit"
                        >
                            <PackagePlus class="size-4" aria-hidden="true" />
                            Registar movimento
                        </AppButton>
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
                        <label class="field-adorned w-full sm:max-w-xs">
                            <span class="sr-only">Procurar artigo</span>
                            <Search
                                class="field-adornment-start size-4"
                                aria-hidden="true"
                            />
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Nome ou código…"
                                class="form-input"
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
                                class="form-checkbox"
                            />
                            Só abaixo do mínimo
                        </label>
                    </div>

                    <EmptyState
                        v-if="levels.length === 0"
                        bare
                        :icon="Boxes"
                        :title="
                            summary.tracked_items === 0
                                ? 'Nenhum artigo controla inventário'
                                : search || establishmentFilter || lowOnly
                                  ? 'Nenhum artigo neste filtro'
                                  : 'Ainda sem movimentos de inventário'
                        "
                        :description="
                            summary.tracked_items === 0
                                ? 'Active o controlo de inventário na ficha do artigo e as existências aparecem aqui.'
                                : search || establishmentFilter || lowOnly
                                  ? 'Mude o filtro para ver os outros artigos.'
                                  : 'Registe a primeira entrada e o artigo aparece aqui, com o saldo de cada estabelecimento.'
                        "
                    />

                    <div v-else class="overflow-x-auto">
                        <DataTable
                            ><template #head>
                                <th>Artigo</th>
                                <th>Estabelecimento</th>
                                <th class="text-right">Quantidade</th>
                                <th class="hidden text-right lg:table-cell">
                                    Custo médio
                                </th>
                                <th class="text-right">Valor</th>
                            </template>
                            <tr v-for="level in levels" :key="level.id">
                                <td>
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
                                <td class="text-zinc-600 dark:text-zinc-400">
                                    {{ level.establishment }}
                                </td>
                                <td class="text-right">
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
                                    class="hidden text-right numeric text-zinc-600 lg:table-cell dark:text-zinc-400"
                                >
                                    {{ money(level.average_cost_minor) }}
                                </td>
                                <td
                                    class="text-right numeric font-medium text-zinc-950 dark:text-white"
                                >
                                    {{ money(level.value_minor) }}
                                </td>
                            </tr>
                        </DataTable>
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

                    <EmptyState
                        v-if="movements.length === 0"
                        bare
                        :icon="ArrowLeftRight"
                        title="Ainda não houve movimentos"
                        description="Cada entrada e saída aparece aqui, incluindo as dos documentos emitidos."
                    />

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
            eyebrow="Inventário"
            :open="dialogOpen"
            title="Registar movimento de inventário"
            description="Entradas, acertos, transferências e quebras. As vendas são registadas automaticamente ao emitir."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        class="sm:col-span-2"
                        label="Artigo"
                        :error="form.errors.catalogue_item"
                        ><div>
                            <SelectInput
                                v-model="form.catalogue_item"
                                :options="itemOptions"
                            /></div
                    ></FormField>

                    <FormField
                        label="Tipo de movimento"
                        :error="form.errors.type"
                        ><div>
                            <SelectInput
                                v-model="form.type"
                                :options="typeOptions"
                            /></div
                    ></FormField>

                    <FormField
                        label="Estabelecimento"
                        :error="form.errors.establishment"
                        ><div>
                            <SelectInput
                                v-model="form.establishment"
                                :options="establishmentOptions"
                            /></div
                    ></FormField>

                    <FormField
                        v-if="selectedType?.is_transfer"
                        class="sm:col-span-2"
                        label="Destino"
                        :error="form.errors.destination_establishment"
                        ><div>
                            <SelectInput
                                v-model="form.destination_establishment"
                                :options="establishmentOptions"
                            /></div
                    ></FormField>

                    <FormField
                        for="movement-quantity"
                        label="Quantidade"
                        :error="form.errors.quantity"
                        ><input
                            id="movement-quantity"
                            v-model="form.quantity"
                            type="text"
                            inputmode="decimal"
                            placeholder="0,000"
                            class="form-input numeric"
                    /></FormField>

                    <FormField
                        v-if="selectedType?.carries_cost"
                        for="movement-cost"
                        label="Custo unitário"
                        :error="form.errors.unit_cost"
                        ><input
                            id="movement-cost"
                            v-model="form.unit_cost"
                            type="text"
                            inputmode="decimal"
                            placeholder="0,00"
                            class="form-input numeric"
                        />
                        <p class="field-hint">
                            Entra na média ponderada que valoriza o armazém.
                        </p></FormField
                    >

                    <FormField
                        class="sm:col-span-2"
                        for="movement-note"
                        label="Nota"
                        optional
                        :error="form.errors.note"
                        ><input
                            id="movement-note"
                            v-model="form.note"
                            type="text"
                            maxlength="500"
                            placeholder="Ex.: guia do fornecedor n.º 88"
                            class="form-input"
                    /></FormField>
                </div>

                <div
                    class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
                >
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
                        Registar
                    </AppButton>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
