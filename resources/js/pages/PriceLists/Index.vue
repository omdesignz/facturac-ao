<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    LoaderCircle,
    Plus,
    Star,
    Tags,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DataTable from '@/components/DataTable.vue';
import EmptyState from '@/components/EmptyState.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { currencyLabel } from '@/lib/pos-ui';
import {
    index as priceListsIndex,
    store as storeList,
} from '@/routes/price-lists';
import {
    update as updateList,
    destroy as destroyList,
} from '@/routes/price-lists';
import { store as storePrices } from '@/routes/price-lists/prices';

interface ListRow {
    public_id: string;
    name: string;
    description: string | null;
    is_default: boolean;
    is_active: boolean;
    item_count: number;
    customer_count: number;
}

interface PriceRow {
    catalogue_item: string;
    code: string;
    name: string;
    unit_of_measure: string;
    list_price_minor: number;
    unit_price: string;
    note: string | null;
}

const props = defineProps<{
    lists: ListRow[];
    selected: {
        public_id: string;
        name: string;
        description: string | null;
        is_default: boolean;
        is_active: boolean;
        rows: PriceRow[];
        customers: { public_id: string; name: string }[];
    } | null;
    currencyCode: string;
    canManage: boolean;
}>();

const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function money(minor: number): string {
    return `${moneyFormatter.format(minor / 100)} ${currencyLabel(props.currencyCode)}`;
}

/** Parses the typed figure without a float round-trip losing a cêntimo. */
function toMinor(value: string): number | null {
    const trimmed = value.trim();

    if (trimmed === '') {
        return null;
    }

    const [whole, fraction = ''] = trimmed.split('.');

    return Number(`${whole}${fraction.padEnd(2, '0').slice(0, 2)}`);
}

const dialogOpen = ref(false);

const listForm = useForm({
    name: '',
    description: '',
    is_default: false,
    is_active: true,
});

function openDialog(): void {
    listForm.reset();
    listForm.clearErrors();
    dialogOpen.value = true;
}

function createList(): void {
    listForm.post(storeList.url(), {
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}

function selectList(publicId: string): void {
    router.get(
        priceListsIndex.url(),
        { tabela: publicId },
        { preserveScroll: true, preserveState: true },
    );
}

/**
 * The prices being edited, held locally so the whole tabela is saved in one
 * go — setting a wholesale list means going down the catalogue, and saving
 * each row alone would make a half-entered tabela the normal state.
 */
const draft = ref<Record<string, string>>({});

watch(
    () => props.selected,
    (selected) => {
        draft.value = Object.fromEntries(
            (selected?.rows ?? []).map((row) => [
                row.catalogue_item,
                row.unit_price,
            ]),
        );
    },
    { immediate: true, deep: false },
);

interface PricePayload {
    catalogue_item: string;
    unit_price: string;
    note: string | null;
}

const pricesForm = useForm({ prices: [] as PricePayload[] });

function savePrices(): void {
    if (props.selected === null) {
        return;
    }

    pricesForm.prices = props.selected.rows.map((row) => ({
        catalogue_item: row.catalogue_item,
        unit_price: draft.value[row.catalogue_item] ?? '',
        note: row.note,
    }));

    pricesForm.post(storePrices.url({ priceList: props.selected.public_id }), {
        preserveScroll: true,
    });
}

/**
 * How far this tabela sits from the catalogue, per article.
 *
 * The figure people are actually deciding when they build a wholesale list is
 * the discount, not the price, so it is worth showing while they type rather
 * than leaving them to work it out.
 */
function difference(row: PriceRow): { label: string; tone: string } | null {
    const typed = toMinor(draft.value[row.catalogue_item] ?? '');

    if (typed === null || row.list_price_minor === 0) {
        return null;
    }

    const delta = ((typed - row.list_price_minor) / row.list_price_minor) * 100;

    if (Math.abs(delta) < 0.05) {
        return {
            label: 'igual',
            tone: 'text-zinc-400 dark:text-zinc-500',
        };
    }

    return {
        label: `${delta > 0 ? '+' : '−'}${Math.abs(delta).toFixed(1)}%`,
        tone:
            delta > 0
                ? 'text-amber-700 dark:text-amber-400'
                : 'text-emerald-700 dark:text-emerald-400',
    };
}

const pricedCount = computed(
    () =>
        Object.values(draft.value).filter((value) => value.trim() !== '')
            .length,
);

/** Building a tabela usually starts as "everything at X% off". */
const bulkDiscount = ref('');

function applyBulkDiscount(): void {
    const percentage = Number(bulkDiscount.value);

    if (
        props.selected === null ||
        Number.isNaN(percentage) ||
        percentage <= 0 ||
        percentage >= 100
    ) {
        return;
    }

    for (const row of props.selected.rows) {
        const discounted = Math.round(
            (row.list_price_minor * (100 - percentage)) / 100,
        );

        draft.value[row.catalogue_item] = (discounted / 100).toFixed(2);
    }
}

async function removeList(): Promise<void> {
    if (props.selected === null) {
        return;
    }

    const confirmed = await confirmAction({
        title: `Remover a tabela “${props.selected.name}”?`,
        message: `Os ${props.selected.customers.length} cliente(s) nela voltam ao preço do catálogo.`,
        confirmLabel: 'Remover tabela',
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroyList.url({ priceList: props.selected.public_id }));
}

function toggleDefault(): void {
    if (props.selected === null) {
        return;
    }

    router.put(updateList.url({ priceList: props.selected.public_id }), {
        name: props.selected.name,
        description: props.selected.description ?? '',
        is_default: !props.selected.is_default,
        is_active: props.selected.is_active,
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Tabelas de preços" />

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Registos"
                    title="Tabelas de preços"
                    description="Um preço combinado com um cliente vale sempre mais que a tabela dele; a tabela vale mais que o preço do artigo."
                >
                    <template #actions>
                        <AppButton
                            variant="gold"
                            v-if="canManage"
                            @click="openDialog"
                            class="w-fit"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Nova tabela
                        </AppButton>
                    </template>
                </PageHeader>

                <EmptyState
                    v-if="lists.length === 0"
                    :icon="Tags"
                    title="Ainda não há tabelas"
                    description="Crie uma para o preço que pratica com um grupo de clientes — revenda, grossista, contrato. Depois basta pôr cada cliente na sua."
                >
                    <AppButton
                        variant="gold"
                        v-if="canManage"
                        @click="openDialog"
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        Criar a primeira tabela
                    </AppButton>
                </EmptyState>

                <div v-else class="grid gap-6 lg:grid-cols-[18rem_1fr]">
                    <nav class="space-y-2" aria-label="Tabelas">
                        <button
                            v-for="list in lists"
                            :key="list.public_id"
                            data-nav-row
                            type="button"
                            class="w-full rounded-2xl px-4 py-3 text-left ring-1 focus-ring transition"
                            :class="
                                selected?.public_id === list.public_id
                                    ? 'bg-white ring-brand-600 dark:bg-white/10 dark:ring-accent-400'
                                    : 'ring-zinc-200 hover:bg-white dark:ring-white/10 dark:hover:bg-white/5'
                            "
                            :aria-current="
                                selected?.public_id === list.public_id
                                    ? 'true'
                                    : undefined
                            "
                            @click="selectList(list.public_id)"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="truncate text-sm font-semibold text-zinc-950 dark:text-white"
                                    >{{ list.name }}</span
                                >
                                <Star
                                    v-if="list.is_default"
                                    class="size-3.5 shrink-0 text-amber-500"
                                    aria-label="Tabela por omissão"
                                />
                            </span>
                            <span
                                class="mt-1 flex items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                <span class="numeric"
                                    >{{ list.item_count }} artigo(s)</span
                                >
                                <span class="numeric"
                                    >{{ list.customer_count }} cliente(s)</span
                                >
                            </span>
                            <span
                                v-if="!list.is_active"
                                class="mt-1 block text-xs text-zinc-400 dark:text-zinc-500"
                                >Inactiva</span
                            >
                        </button>
                    </nav>

                    <section v-if="selected" class="space-y-4">
                        <div
                            class="rounded-xl bg-[var(--surface-raised)] p-5 shadow-[var(--ui-card-shadow)]"
                        >
                            <div
                                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <h2
                                        class="text-lg font-semibold text-zinc-950 dark:text-white"
                                    >
                                        {{ selected.name }}
                                    </h2>
                                    <p
                                        v-if="selected.description"
                                        class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400"
                                    >
                                        {{ selected.description }}
                                    </p>
                                    <p
                                        class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1.5"
                                        >
                                            <Users
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{ selected.customers.length }}
                                            cliente(s) nesta tabela
                                        </span>
                                        <span class="numeric"
                                            >{{ pricedCount }} de
                                            {{ selected.rows.length }} artigos
                                            com preço</span
                                        >
                                    </p>
                                </div>

                                <div
                                    v-if="canManage"
                                    class="flex shrink-0 items-center gap-2"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex h-8 items-center justify-center gap-1.5 rounded-full px-3 text-xs font-semibold ring-1 focus-ring transition ring-inset"
                                        :class="
                                            selected.is_default
                                                ? 'text-amber-700 ring-amber-300 dark:text-amber-300 dark:ring-amber-400/30'
                                                : 'text-zinc-700 ring-zinc-900/10 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/15 dark:hover:bg-white/5'
                                        "
                                        @click="toggleDefault"
                                    >
                                        <Star
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {{
                                            selected.is_default
                                                ? 'Por omissão'
                                                : 'Tornar por omissão'
                                        }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-xl p-2 text-zinc-400 focus-ring transition hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                                        @click="removeList"
                                    >
                                        <span class="sr-only"
                                            >Remover tabela</span
                                        >
                                        <Trash2
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div
                            class="overflow-hidden rounded-xl bg-[var(--surface-raised)] shadow-[var(--ui-card-shadow)]"
                        >
                            <header
                                class="flex flex-col gap-3 border-b border-zinc-900/[0.07] p-5 sm:flex-row sm:items-end sm:justify-between dark:border-white/10"
                            >
                                <div>
                                    <h3
                                        class="text-sm font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Preços
                                    </h3>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Deixe em branco o que não entra nesta
                                        tabela — esses artigos ficam ao preço do
                                        catálogo.
                                    </p>
                                </div>

                                <label
                                    v-if="canManage && selected.rows.length > 0"
                                    class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Desconto sobre o catálogo
                                    <span
                                        class="flex items-center gap-1 rounded-lg ring-1 ring-zinc-200 focus-within:ring-brand-600 dark:ring-white/15"
                                    >
                                        <input
                                            v-model="bulkDiscount"
                                            inputmode="decimal"
                                            placeholder="0"
                                            class="w-14 rounded-lg border-0 bg-transparent px-2 py-1.5 text-right numeric text-sm text-zinc-950 focus:outline-none dark:text-white"
                                            @keydown.enter.prevent="
                                                applyBulkDiscount
                                            "
                                        />
                                        <span class="pr-2 text-zinc-400"
                                            >%</span
                                        >
                                    </span>
                                    <AppButton
                                        variant="outline"
                                        @click="applyBulkDiscount"
                                    >
                                        Aplicar a tudo
                                    </AppButton>
                                </label>
                            </header>

                            <p
                                v-if="selected.rows.length === 0"
                                class="px-5 py-14 text-center text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                Não há artigos activos no catálogo. Crie artigos
                                primeiro e voltam a aparecer aqui.
                            </p>

                            <div v-else class="overflow-x-auto">
                                <DataTable
                                    ><template #head>
                                        <th>Artigo</th>
                                        <th
                                            class="hidden text-right sm:table-cell"
                                        >
                                            Catálogo
                                        </th>
                                        <th class="text-right">Nesta tabela</th>
                                        <th class="text-right">Diferença</th>
                                    </template>
                                    <tr
                                        v-for="row in selected.rows"
                                        :key="row.catalogue_item"
                                    >
                                        <td>
                                            <p
                                                class="font-medium text-zinc-950 dark:text-white"
                                            >
                                                {{ row.name }}
                                            </p>
                                            <p
                                                class="numeric text-xs text-zinc-500 dark:text-zinc-400"
                                            >
                                                {{ row.code }} ·
                                                {{ row.unit_of_measure }}
                                            </p>
                                        </td>
                                        <td
                                            class="hidden text-right numeric whitespace-nowrap text-zinc-500 sm:table-cell dark:text-zinc-400"
                                        >
                                            {{ money(row.list_price_minor) }}
                                        </td>
                                        <td class="text-right">
                                            <input
                                                v-model="
                                                    draft[row.catalogue_item]
                                                "
                                                inputmode="decimal"
                                                placeholder="—"
                                                :disabled="!canManage"
                                                :aria-label="`Preço de ${row.name} nesta tabela`"
                                                class="form-input w-28 form-input-sm text-right numeric text-sm"
                                            />
                                        </td>
                                        <td
                                            class="text-right numeric text-xs whitespace-nowrap"
                                        >
                                            <span
                                                v-if="difference(row)"
                                                :class="difference(row)!.tone"
                                                >{{
                                                    difference(row)!.label
                                                }}</span
                                            >
                                            <span
                                                v-else
                                                class="text-zinc-300 dark:text-zinc-600"
                                                >—</span
                                            >
                                        </td>
                                    </tr>
                                </DataTable>
                            </div>

                            <div
                                v-if="canManage && selected.rows.length > 0"
                                class="flex items-center justify-end gap-3 border-t border-zinc-900/[0.07] p-4 dark:border-white/10"
                            >
                                <AppButton
                                    variant="ink"
                                    :disabled="pricesForm.processing"
                                    @click="savePrices"
                                >
                                    <LoaderCircle
                                        v-if="pricesForm.processing"
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    <Check
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Guardar preços
                                </AppButton>
                            </div>
                        </div>

                        <div
                            v-if="selected.customers.length > 0"
                            class="rounded-xl bg-[var(--surface-raised)] p-5 shadow-[var(--ui-card-shadow)]"
                        >
                            <h3
                                class="text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                Clientes nesta tabela
                            </h3>
                            <p
                                class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                Mude a tabela de um cliente na ficha dele.
                            </p>
                            <ul class="mt-3 flex flex-wrap gap-2">
                                <li
                                    v-for="customer in selected.customers"
                                    :key="customer.public_id"
                                >
                                    <a
                                        :href="`/customers/${customer.public_id}`"
                                        class="inline-flex rounded-lg bg-zinc-100 px-2.5 py-1.5 text-xs font-medium text-zinc-700 focus-ring transition hover:bg-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:hover:bg-white/10"
                                        >{{ customer.name }}</a
                                    >
                                </li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <RecordDialog
            eyebrow="Tabelas de preços"
            :open="dialogOpen"
            title="Nova tabela de preços"
            description="Dê-lhe o nome por que a conhece — revenda, grossista, o nome do contrato."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="createList">
                <FormField
                    for="price-list-name"
                    label="Nome"
                    :error="listForm.errors.name"
                    ><input
                        id="price-list-name"
                        v-model="listForm.name"
                        type="text"
                        required
                        placeholder="Revenda"
                        class="form-input"
                /></FormField>

                <FormField
                    for="price-list-description"
                    label="Descrição"
                    optional
                    :error="listForm.errors.description"
                    ><input
                        id="price-list-description"
                        v-model="listForm.description"
                        type="text"
                        placeholder="Quem compra em quantidade"
                        class="form-input"
                /></FormField>

                <ToggleSwitch v-model="listForm.is_default">
                    <template #label
                        >Usar esta tabela nos clientes novos</template
                    >
                </ToggleSwitch>

                <div class="flex justify-end gap-3 pt-2">
                    <AppButton variant="outline" @click="dialogOpen = false">
                        Cancelar
                    </AppButton>
                    <AppButton
                        variant="ink"
                        type="submit"
                        :disabled="listForm.processing"
                    >
                        <LoaderCircle
                            v-if="listForm.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Criar tabela
                    </AppButton>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
