<script setup lang="ts">
import { Head, router, useForm, InfiniteScroll } from '@inertiajs/vue3';
import {
    Archive,
    LoaderCircle,
    Package,
    Pencil,
    Plus,
    ScanBarcode,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DataTable from '@/components/DataTable.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormError from '@/components/FormError.vue';
import FormField from '@/components/FormField.vue';
import LoadMore from '@/components/LoadMore.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SearchField from '@/components/SearchField.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TitleBar from '@/components/TitleBar.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { withRecordInactive } from '@/lib/optimistic';
import { currencyLabel } from '@/lib/pos-ui';
import { destroy, store, update } from '@/routes/catalogue';
import type { SelectOption } from '@/types/select';

interface CatalogueItem {
    public_id: string;
    code: string;
    barcode: string | null;
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

const props = defineProps<{
    items: {
        data: CatalogueItem[];
        total: number;
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
        {
            preserveState: true,
            replace: true,
            only: ['items', 'filters'],
            reset: ['items'],
        },
    );
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 300);
});

watch(typeFilter, reload);

const form = useForm({
    code: '',
    barcode: '',
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
    form.barcode = item.barcode ?? '';
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

    router
        .optimistic<{ items: { data: CatalogueItem[] } }>((pageProps) => ({
            items: withRecordInactive(pageProps.items, item.public_id),
        }))
        .delete(destroy.url(item.public_id), {
            preserveScroll: true,
            only: ['flash'],
        });
}
</script>

<template>
    <AppLayout>
        <Head title="Artigos e serviços" />

        <div class="flex min-h-[calc(100dvh-4rem)] flex-col">
            <TitleBar section="Registos" title="Artigos e serviços">
                <template #meta>
                    <span class="ms-1 numeric text-xs text-[var(--ui-ink-3)]">{{
                        items.total
                    }}</span>
                </template>
                <template #actions>
                    <SearchField
                        v-model="search"
                        label="Procurar artigos"
                        placeholder="Nome, código ou descrição"
                    />
                    <AppButton
                        v-if="canManage"
                        variant="gold"
                        size="sm"
                        class="h-[1.875rem] ps-2.5"
                        @click="openCreate"
                    >
                        <Plus
                            class="size-3.5"
                            :stroke-width="2.2"
                            aria-hidden="true"
                        />
                        Novo artigo
                    </AppButton>
                </template>
            </TitleBar>

            <div
                class="flex min-h-11 [scrollbar-width:none] items-center gap-0.5 overflow-x-auto border-b border-[var(--ui-line)] px-4 py-1.5 sm:px-6"
                role="group"
                aria-label="Filtrar por tipo"
            >
                <button
                    v-for="option in typeFilterOptions"
                    :key="String(option.value)"
                    type="button"
                    :aria-pressed="typeFilter === option.value"
                    class="inline-flex h-[1.625rem] shrink-0 items-center rounded-md px-2 text-[0.78125rem] focus-ring transition-colors pointer-coarse:h-9"
                    :class="
                        typeFilter === option.value
                            ? 'bg-[var(--ui-well)] font-semibold text-[var(--ui-ink)]'
                            : 'font-medium text-[var(--ui-ink-2)] hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)]'
                    "
                    @click="typeFilter = String(option.value)"
                >
                    {{ option.value === 'all' ? 'Todos' : option.label }}
                </button>
            </div>

            <EmptyState
                v-if="items.data.length === 0"
                bare
                class="flex-1 justify-center"
                :icon="Package"
                :title="
                    filters.search || filters.type
                        ? 'Nenhum artigo corresponde aos filtros'
                        : 'O catálogo está vazio'
                "
                :description="
                    filters.search || filters.type
                        ? 'Tente outro termo ou outro tipo.'
                        : 'Guarde o que vende com o preço e o IVA. Numa factura basta escolher e os valores entram sozinhos.'
                "
            >
                <AppButton
                    v-if="canManage && !filters.search && !filters.type"
                    variant="gold"
                    size="sm"
                    @click="openCreate"
                >
                    <Plus class="size-3.5" aria-hidden="true" />
                    Novo artigo
                </AppButton>
            </EmptyState>

            <div v-else class="data-table-host overflow-x-auto">
                <InfiniteScroll
                    data="items"
                    items-element="#items-rows"
                    :buffer="400"
                    :manual-after="4"
                >
                    <template #next="scroll"
                        ><LoadMore
                            :shown="items.data.length"
                            :total="items.total"
                            :loading="scroll.loading"
                            :has-more="scroll.hasMore"
                            :manual-mode="scroll.manualMode"
                            :fetch="scroll.fetch"
                    /></template>
                    <DataTable body-id="items-rows" edge
                        ><template #head>
                            <th>Artigo</th>
                            <th class="hidden sm:table-cell">Tipo</th>
                            <th class="text-right">Preço</th>
                            <th class="hidden text-right md:table-cell">IVA</th>
                            <th class="hidden sm:table-cell">Estado</th>
                            <th>
                                <span class="sr-only">Acções</span>
                            </th>
                        </template>
                        <tr
                            v-for="item in items.data"
                            :key="item.public_id"
                            class="hover:bg-[var(--ui-wash)]"
                        >
                            <td>
                                <p
                                    class="text-[0.8125rem] font-semibold [overflow-wrap:anywhere] text-[var(--ui-ink)]"
                                >
                                    {{ item.name }}
                                </p>
                                <p
                                    class="mt-px numeric text-xs text-[var(--ui-ink-3)]"
                                >
                                    {{ item.code }} ·
                                    {{ item.unit_of_measure }}
                                    <span class="sm:hidden">
                                        ·
                                        {{
                                            item.is_active
                                                ? 'Activo'
                                                : 'Inactivo'
                                        }}</span
                                    >
                                </p>
                                <p
                                    v-if="item.barcode"
                                    class="mt-px flex items-center gap-1 numeric text-xs text-[var(--ui-ink-3)]"
                                >
                                    <ScanBarcode
                                        class="size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {{ item.barcode }}
                                </p>
                            </td>
                            <td
                                class="hidden text-[0.8125rem] text-[var(--ui-ink-2)] sm:table-cell"
                            >
                                {{ item.type_label }}
                            </td>
                            <td
                                class="text-right numeric text-[0.8125rem] font-semibold whitespace-nowrap text-[var(--ui-ink)]"
                            >
                                {{ formatMoney(item.unit_price_minor) }}
                                <span
                                    class="text-xs font-normal text-[var(--ui-ink-3)]"
                                    >{{
                                        item.currency_code === 'AOA'
                                            ? 'Kz'
                                            : item.currency_code
                                    }}</span
                                >
                            </td>
                            <td
                                class="hidden text-right numeric text-[0.8125rem] text-[var(--ui-ink-2)] md:table-cell"
                            >
                                {{ Number(item.tax_percentage) }}%
                            </td>
                            <td class="hidden sm:table-cell">
                                <StatusBadge
                                    :label="
                                        item.is_active ? 'Activo' : 'Inactivo'
                                    "
                                    :tone="item.is_active ? 'neutral' : 'draft'"
                                />
                            </td>
                            <td class="text-right">
                                <div
                                    v-if="canManage"
                                    data-row-actions
                                    class="flex flex-col items-end justify-end gap-0.5 sm:flex-row"
                                >
                                    <button
                                        data-nav-row
                                        type="button"
                                        class="grid size-7 place-items-center rounded-md text-[var(--ui-ink-3)] focus-ring hover:bg-[var(--ui-well)] hover:text-[var(--ui-ink)] pointer-coarse:size-11"
                                        @click="openEdit(item)"
                                    >
                                        <span class="sr-only"
                                            >Editar {{ item.name }}</span
                                        >
                                        <Pencil
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                    </button>
                                    <button
                                        v-if="item.is_active"
                                        type="button"
                                        class="grid size-7 place-items-center rounded-md text-[var(--ui-ink-3)] focus-ring hover:bg-[var(--ui-critical-wash)] hover:text-[var(--ui-critical)] pointer-coarse:size-11"
                                        @click="deactivate(item)"
                                    >
                                        <span class="sr-only"
                                            >Desactivar {{ item.name }}</span
                                        >
                                        <Archive
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </DataTable>
                </InfiniteScroll>
            </div>
        </div>

        <RecordDialog
            eyebrow="Artigos e serviços"
            :open="dialogOpen"
            :title="editing ? 'Editar artigo' : 'Novo artigo'"
            description="O código identifica o artigo internamente e nas importações."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-5 sm:grid-cols-6">
                    <FormField
                        class="sm:col-span-2"
                        for="item-code"
                        label="Código"
                        :error="form.errors.code"
                        ><input
                            id="item-code"
                            v-model="form.code"
                            type="text"
                            required
                            class="block form-input font-mono uppercase"
                    /></FormField>

                    <FormField
                        class="sm:col-span-2"
                        for="item-type"
                        label="Tipo"
                        :error="form.errors.type"
                        ><SelectInput
                            id="item-type"
                            v-model="form.type"
                            :options="typeOptions"
                    /></FormField>

                    <FormField
                        class="sm:col-span-2"
                        for="item-unit"
                        label="Unidade"
                        :error="form.errors.unit_of_measure"
                        ><input
                            id="item-unit"
                            v-model="form.unit_of_measure"
                            type="text"
                            required
                            placeholder="UN"
                            class="block form-input uppercase"
                    /></FormField>

                    <FormField
                        class="sm:col-span-6"
                        for="item-name"
                        label="Designação"
                        :error="form.errors.name"
                        ><input
                            id="item-name"
                            v-model="form.name"
                            type="text"
                            required
                            class="block form-input"
                    /></FormField>

                    <div class="sm:col-span-3">
                        <label for="item-price" class="field-label"
                            >Preço unitário ({{
                                currencyLabel(currencyCode)
                            }})</label
                        >
                        <input
                            id="item-price"
                            v-model="form.unit_price"
                            type="text"
                            inputmode="decimal"
                            required
                            class="mt-2 block form-input text-right numeric"
                        />
                        <FormError :message="form.errors.unit_price" />
                    </div>

                    <FormField
                        class="sm:col-span-3"
                        for="item-tax"
                        label="IVA (%)"
                        :error="form.errors.tax_percentage"
                        ><input
                            id="item-tax"
                            v-model="form.tax_percentage"
                            type="text"
                            inputmode="decimal"
                            required
                            class="block form-input text-right numeric"
                    /></FormField>

                    <FormField
                        class="sm:col-span-6"
                        for="item-description"
                        label="Descrição"
                        optional
                        :error="form.errors.description"
                    >
                        <textarea
                            id="item-description"
                            v-model="form.description"
                            rows="2"
                            class="block form-input [--form-rows:2]"
                        />
                    </FormField>

                    <div class="sm:col-span-3">
                        <label for="item-exemption" class="field-label"
                            >Código de isenção
                            <span class="text-zinc-400"
                                >(se aplicável)</span
                            ></label
                        >
                        <input
                            id="item-exemption"
                            v-model="form.tax_exemption_code"
                            type="text"
                            class="mt-2 block form-input font-mono uppercase"
                        />
                        <FormError :message="form.errors.tax_exemption_code" />
                    </div>

                    <FormField
                        class="sm:col-span-3"
                        for="item-barcode"
                        label="Código de barras"
                        optional
                        :error="form.errors.barcode"
                        error-id="item-barcode-error"
                        ><input
                            id="item-barcode"
                            v-model="form.barcode"
                            type="text"
                            autocapitalize="characters"
                            spellcheck="false"
                            autocomplete="off"
                            maxlength="64"
                            class="block form-input font-mono"
                            :aria-invalid="
                                form.errors.barcode ? 'true' : undefined
                            "
                            :aria-describedby="
                                form.errors.barcode
                                    ? 'item-barcode-hint item-barcode-error'
                                    : 'item-barcode-hint'
                            "
                            @keydown.enter.prevent
                        />
                        <p id="item-barcode-hint" class="field-hint">
                            Leia o código com o leitor ou escreva-o.
                        </p></FormField
                    >
                </div>

                <ToggleSwitch v-model="form.is_active">
                    <template #label>Disponível ao criar facturas</template>
                </ToggleSwitch>

                <!-- A service has no warehouse balance to draw down, so the
                     option only appears for products. -->
                <div
                    v-if="form.type === 'product'"
                    class="rounded-xl bg-zinc-50 p-4 dark:bg-white/5"
                >
                    <ToggleSwitch v-model="form.tracks_stock">
                        <template #label
                            >Controlar o inventário deste artigo</template
                        >
                        <template #description
                            >Emitir uma factura dá baixa; uma nota de crédito
                            devolve.</template
                        >
                    </ToggleSwitch>

                    <FormField
                        v-if="form.tracks_stock"
                        class="mt-4 max-w-xs"
                        for="item-reorder"
                        label="Nível mínimo"
                        optional
                        :error="form.errors.reorder_level"
                        ><input
                            id="item-reorder"
                            v-model="form.reorder_level"
                            type="text"
                            inputmode="decimal"
                            placeholder="0,000"
                            class="form-input numeric"
                        />
                        <p class="field-hint">
                            Avisamos quando o saldo chegar a este valor.
                        </p></FormField
                    >
                </div>

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
