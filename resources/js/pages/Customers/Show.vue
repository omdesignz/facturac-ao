<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarClock,
    CircleCheck,
    Clock,
    Gauge,
    LoaderCircle,
    Mail,
    MapPin,
    Phone,
    Plus,
    FileText,
    Send,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ChartBars from '@/components/charts/ChartBars.vue';
import ChartFrame from '@/components/charts/ChartFrame.vue';
import ChartTrend from '@/components/charts/ChartTrend.vue';
import StatTile from '@/components/charts/StatTile.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { index as customersIndex } from '@/routes/customers';
import {
    destroy as destroyPrice,
    store as storePrice,
} from '@/routes/customers/prices';
import { print as printDocument } from '@/routes/documents';
import { send as sendInvoice } from '@/routes/invoices';
import { index as priceListsIndex } from '@/routes/price-lists';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface DocumentRow {
    public_id: string;
    document_no: string | null;
    document_type: string;
    document_type_label: string;
    document_date: string;
    due_date: string | null;
    status: string;
    status_label: string;
    gross_total_minor: number;
    paid_minor: number;
    outstanding_minor: number;
    is_billable: boolean;
    is_credit: boolean;
    overdue: boolean;
    currency_code: string;
    can_send: boolean;
    sent_at: string | null;
    send_count: number;
}

const props = defineProps<{
    customer: {
        public_id: string;
        name: string;
        tax_identification_number: string;
        country_code: string;
        address_line: string | null;
        email: string | null;
        phone: string | null;
        is_active: boolean;
        created_at: string | null;
        payment_terms_days: number;
        credit_limit_minor: number | null;
        price_list: { public_id: string; name: string } | null;
        auto_send_documents: boolean;
    };
    agreedPrices: {
        id: number;
        item: string;
        code: string;
        unit_price_minor: number;
        list_price_minor: number;
        note: string | null;
    }[];
    catalogueOptions: { value: string; label: string }[];
    statement: {
        public_id: string;
        document_no: string | null;
        document_type_label: string;
        document_date: string;
        due_date: string | null;
        movement_minor: number;
        balance_minor: number;
        days_past_due: number;
    }[];
    summary: {
        billed_minor: number;
        credited_minor: number;
        paid_minor: number;
        outstanding_minor: number;
        overdue_minor: number;
        overdue_count: number;
        document_count: number;
        tax_minor: number;
        net_minor: number;
        average_days_to_settle: number | null;
        first_document_at: string | null;
        last_document_at: string | null;
    };
    trend: { label: string; value: number; comparison: number }[];
    documents: {
        data: DocumentRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    typeMix: { label: string; value: number; detail: string; slot: number }[];
    currencyCode: string;
}>();

const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const compactFormatter = new Intl.NumberFormat('pt-AO', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

function money(minor: number): string {
    return `${moneyFormatter.format(minor / 100)} ${props.currencyCode}`;
}

function compactMoney(minor: number): string {
    return `${compactFormatter.format(minor / 100)} ${props.currencyCode}`;
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium' });

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

/** Owing more than was agreed is worth saying plainly, not burying in a figure. */
const overLimit = computed(
    () =>
        props.customer.credit_limit_minor !== null &&
        props.summary.outstanding_minor > props.customer.credit_limit_minor,
);

/** How much of what was billed has actually come in. */
const collectionRate = computed(() => {
    const billed = props.summary.billed_minor - props.summary.credited_minor;

    return billed <= 0
        ? null
        : Math.round((props.summary.paid_minor / billed) * 100);
});

const trendRows = computed(() =>
    props.trend.map((point) => [
        point.label,
        money(point.value),
        money(point.comparison),
    ]),
);

const mixRows = computed(() =>
    props.typeMix.map((entry) => [
        entry.label,
        entry.detail,
        money(entry.value),
    ]),
);

/** Laravel emits &laquo;/&raquo; entities for the previous and next links. */
function decodeEntities(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&nbsp;/g, ' ')
        .trim();
}

const priceForm = useForm({
    catalogue_item: '',
    unit_price: '',
    note: '',
});

function addPrice(): void {
    priceForm.post(storePrice.url({ customer: props.customer.public_id }), {
        preserveScroll: true,
        onSuccess: () => priceForm.reset(),
    });
}

const sendingDocument = ref<string | null>(null);

/**
 * Emails an already-issued document to the customer.
 *
 * Confirmed first because it leaves the building: the customer reads whatever
 * this sends, and an accidental second copy of an invoice reads as a chase.
 */
async function sendDocument(
    publicId: string,
    documentNo: string | null,
): Promise<void> {
    const confirmed = await confirmAction({
        title: `Enviar ${documentNo ?? 'este documento'}?`,
        message: `Segue por email para ${customerEmail.value}. Um segundo envio da mesma factura lê-se como cobrança.`,
        confirmLabel: 'Enviar agora',
        tone: 'neutral',
    });

    if (!confirmed) {
        return;
    }

    sendingDocument.value = publicId;

    router.post(
        sendInvoice.url({ fiscalDocument: publicId }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                sendingDocument.value = null;
            },
        },
    );
}

const customerEmail = computed(() => props.customer.email ?? 'o cliente');

async function removePrice(id: number, item: string): Promise<void> {
    const confirmed = await confirmAction({
        title: `Remover o preço acordado de ${item}?`,
        message: 'Este cliente volta a pagar o preço da tabela em que está.',
        confirmLabel: 'Remover preço',
    });

    if (!confirmed) {
        return;
    }

    router.delete(
        destroyPrice.url({ customer: props.customer.public_id, price: id }),
        { preserveScroll: true },
    );
}

const statusTone: Record<string, 'success' | 'warning' | 'neutral' | 'danger'> =
    {
        draft: 'neutral',
        issued: 'warning',
        received: 'warning',
        processing: 'warning',
        valid: 'success',
        invalid: 'danger',
        contingency: 'warning',
    };
</script>

<template>
    <AppLayout>
        <Head :title="customer.name" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl space-y-6">
                <Link
                    :href="customersIndex.url()"
                    class="inline-flex items-center gap-2 rounded-lg text-sm font-medium text-zinc-600 focus-ring transition hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Clientes
                </Link>

                <FlashBanner />

                <header
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div class="min-w-0">
                        <p class="eyebrow text-brand-700 dark:text-brand-300">
                            Cliente
                        </p>
                        <h1
                            class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                        >
                            {{ customer.name }}
                        </h1>
                        <p
                            class="mt-2 numeric text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            NIF {{ customer.tax_identification_number }} ·
                            {{ customer.country_code }}
                        </p>
                    </div>

                    <StatusBadge
                        :tone="customer.is_active ? 'success' : 'neutral'"
                        :label="customer.is_active ? 'Activo' : 'Inactivo'"
                    />
                </header>

                <section
                    class="flex flex-wrap gap-x-6 gap-y-2 rounded-2xl surface p-4 text-sm"
                >
                    <a
                        v-if="customer.email"
                        :href="`mailto:${customer.email}`"
                        class="inline-flex items-center gap-2 rounded text-zinc-700 focus-ring hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white"
                    >
                        <Mail class="size-4 text-zinc-400" aria-hidden="true" />
                        {{ customer.email }}
                    </a>
                    <a
                        v-if="customer.phone"
                        :href="`tel:${customer.phone}`"
                        class="inline-flex items-center gap-2 rounded text-zinc-700 focus-ring hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white"
                    >
                        <Phone
                            class="size-4 text-zinc-400"
                            aria-hidden="true"
                        />
                        {{ customer.phone }}
                    </a>
                    <span
                        v-if="customer.address_line"
                        class="inline-flex items-center gap-2 text-zinc-700 dark:text-zinc-300"
                    >
                        <MapPin
                            class="size-4 text-zinc-400"
                            aria-hidden="true"
                        />
                        {{ customer.address_line }}
                    </span>
                    <span
                        class="inline-flex items-center gap-2 text-zinc-500 dark:text-zinc-400"
                    >
                        <Clock
                            class="size-4 text-zinc-400"
                            aria-hidden="true"
                        />
                        Cliente desde {{ formatDate(customer.created_at) }}
                    </span>

                    <span
                        class="inline-flex items-center gap-2 text-zinc-700 dark:text-zinc-300"
                    >
                        <CalendarClock
                            class="size-4 text-zinc-400"
                            aria-hidden="true"
                        />
                        {{
                            customer.payment_terms_days === 0
                                ? 'Pronto pagamento'
                                : `Paga a ${customer.payment_terms_days} dias`
                        }}
                    </span>

                    <span
                        v-if="customer.credit_limit_minor !== null"
                        :class="[
                            overLimit
                                ? 'text-rose-700 dark:text-rose-400'
                                : 'text-zinc-700 dark:text-zinc-300',
                            'inline-flex items-center gap-2',
                        ]"
                    >
                        <Gauge
                            class="size-4 text-zinc-400"
                            aria-hidden="true"
                        />
                        Limite {{ money(customer.credit_limit_minor) }}
                    </span>

                    <span
                        v-if="customer.auto_send_documents"
                        class="inline-flex items-center gap-2 text-zinc-700 dark:text-zinc-300"
                    >
                        <Send class="size-4 text-zinc-400" aria-hidden="true" />
                        Documentos enviados por email
                    </span>
                </section>

                <div
                    v-if="overLimit"
                    class="flex items-start gap-3 rounded-2xl bg-rose-50 p-4 text-sm/6 text-rose-900 ring-1 ring-rose-200 dark:bg-rose-400/10 dark:text-rose-200 dark:ring-rose-400/20"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        A dívida deste cliente ultrapassa o limite de crédito
                        acordado. Considere cobrar antes de emitir mais.
                    </p>
                </div>

                <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Facturado"
                        :value="money(summary.billed_minor)"
                        :detail="`${summary.document_count} documento(s)`"
                    />
                    <StatTile
                        label="Recebido"
                        :value="money(summary.paid_minor)"
                        tone="good"
                        :detail="
                            collectionRate === null
                                ? undefined
                                : `${collectionRate}% do facturado`
                        "
                    />
                    <StatTile
                        label="Em dívida"
                        :value="money(summary.outstanding_minor)"
                        :tone="
                            summary.outstanding_minor > 0
                                ? 'warning'
                                : 'neutral'
                        "
                        :detail="
                            summary.credited_minor > 0
                                ? `${money(summary.credited_minor)} creditado`
                                : undefined
                        "
                    />
                    <StatTile
                        label="Vencido"
                        :value="money(summary.overdue_minor)"
                        :tone="
                            summary.overdue_minor > 0 ? 'critical' : 'neutral'
                        "
                        :detail="`${summary.overdue_count} documento(s)`"
                    />
                </section>

                <div
                    v-if="summary.overdue_minor > 0"
                    class="flex items-start gap-3 rounded-2xl bg-rose-50 p-4 text-sm/6 text-rose-900 ring-1 ring-rose-200 dark:bg-rose-400/10 dark:text-rose-200 dark:ring-rose-400/20"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        {{ money(summary.overdue_minor) }} em
                        {{ summary.overdue_count }} documento(s) já passou da
                        data de vencimento.
                    </p>
                </div>

                <ChartFrame
                    title="Facturação mensal"
                    subtitle="Últimos 12 meses, contra os mesmos meses do ano anterior."
                    :columns="['Mês', 'Este ano', 'Ano anterior']"
                    :rows="trendRows"
                    :empty="summary.document_count === 0"
                    empty-message="Ainda não emitiu documentos a este cliente."
                >
                    <template #legend>
                        <div
                            class="flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    class="h-0.5 w-4 rounded-full"
                                    style="background: var(--viz-series-1)"
                                    aria-hidden="true"
                                />
                                Este ano
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    class="h-0.5 w-4 rounded-full"
                                    style="background: var(--viz-comparison)"
                                    aria-hidden="true"
                                />
                                Ano anterior
                            </span>
                        </div>
                    </template>

                    <ChartTrend
                        :points="trend"
                        :format="money"
                        series-label="Este ano"
                        comparison-label="Ano anterior"
                    />
                </ChartFrame>

                <section
                    v-if="statement.length > 0"
                    class="viz overflow-hidden rounded-2xl surface"
                >
                    <header
                        class="border-b border-zinc-100 p-5 dark:border-white/10"
                    >
                        <h2
                            class="text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Conta corrente
                        </h2>
                        <p
                            class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Cada movimento e o saldo depois dele, do mais antigo
                            para o mais recente.
                        </p>
                    </header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-100 dark:border-white/10"
                            >
                                <tr>
                                    <th class="px-5 py-3 eyebrow text-zinc-500">
                                        Documento
                                    </th>
                                    <th class="px-5 py-3 eyebrow text-zinc-500">
                                        Data
                                    </th>
                                    <th
                                        class="px-5 py-3 text-right eyebrow text-zinc-500"
                                    >
                                        Movimento
                                    </th>
                                    <th
                                        class="px-5 py-3 text-right eyebrow text-zinc-500"
                                    >
                                        Saldo
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <tr
                                    v-for="entry in statement"
                                    :key="entry.public_id"
                                >
                                    <td class="px-5 py-3">
                                        <p
                                            class="numeric font-medium text-zinc-950 dark:text-white"
                                        >
                                            {{ entry.document_no ?? '—' }}
                                        </p>
                                        <p
                                            class="text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ entry.document_type_label }}
                                        </p>
                                    </td>
                                    <td
                                        class="px-5 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                                    >
                                        {{ formatDate(entry.document_date) }}
                                        <p
                                            v-if="entry.days_past_due > 0"
                                            class="text-xs text-rose-700 dark:text-rose-400"
                                        >
                                            {{ entry.days_past_due }} dias em
                                            atraso
                                        </p>
                                    </td>
                                    <td
                                        class="px-5 py-3 text-right numeric whitespace-nowrap"
                                        :class="
                                            entry.movement_minor < 0
                                                ? 'text-emerald-700 dark:text-emerald-400'
                                                : 'text-zinc-950 dark:text-white'
                                        "
                                    >
                                        {{ entry.movement_minor < 0 ? '−' : '+'
                                        }}{{
                                            money(
                                                Math.abs(entry.movement_minor),
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-5 py-3 text-right numeric font-medium whitespace-nowrap text-zinc-950 dark:text-white"
                                    >
                                        {{ money(entry.balance_minor) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="viz overflow-hidden rounded-2xl surface">
                    <header
                        class="border-b border-zinc-100 p-5 dark:border-white/10"
                    >
                        <h2
                            class="text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Preços acordados
                        </h2>
                        <p
                            class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            <template v-if="customer.price_list">
                                Valem mais que a tabela
                                <Link
                                    :href="
                                        priceListsIndex.url({
                                            query: {
                                                tabela: customer.price_list
                                                    .public_id,
                                            },
                                        })
                                    "
                                    class="font-medium text-brand-700 underline-offset-2 hover:underline dark:text-brand-300"
                                    >{{ customer.price_list.name }}</Link
                                >, em que este cliente está.
                            </template>
                            <template v-else>
                                Substituem o preço do catálogo quando facturar a
                                este cliente. Não está em nenhuma
                                <Link
                                    :href="priceListsIndex.url()"
                                    class="font-medium text-brand-700 underline-offset-2 hover:underline dark:text-brand-300"
                                    >tabela de preços</Link
                                >.
                            </template>
                        </p>
                    </header>

                    <ul
                        v-if="agreedPrices.length > 0"
                        class="divide-y divide-zinc-100 dark:divide-white/10"
                    >
                        <li
                            v-for="price in agreedPrices"
                            :key="price.id"
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 p-4"
                        >
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate text-sm font-medium text-zinc-950 dark:text-white"
                                >
                                    {{ price.item }}
                                </p>
                                <p
                                    class="numeric text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ price.code
                                    }}<span v-if="price.note">
                                        · {{ price.note }}</span
                                    >
                                </p>
                            </div>

                            <p class="text-right">
                                <span
                                    class="numeric text-sm font-medium text-zinc-950 dark:text-white"
                                    >{{ money(price.unit_price_minor) }}</span
                                >
                                <span
                                    v-if="
                                        price.list_price_minor !==
                                        price.unit_price_minor
                                    "
                                    class="block numeric text-xs text-zinc-400 line-through dark:text-zinc-500"
                                    >{{ money(price.list_price_minor) }}</span
                                >
                            </p>

                            <button
                                type="button"
                                class="icon-button text-zinc-400 focus-ring transition hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-400/10 dark:hover:text-rose-400"
                                :title="`Remover o preço acordado de ${price.item}`"
                                @click="removePrice(price.id, price.item)"
                            >
                                <span class="sr-only">Remover</span>
                                <Trash2 class="size-4" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>

                    <p
                        v-else
                        class="px-5 py-10 text-center text-sm/6 text-zinc-500 dark:text-zinc-400"
                    >
                        Sem preços acordados. Este cliente paga o preço de
                        tabela.
                    </p>

                    <form
                        v-if="catalogueOptions.length > 0"
                        class="flex flex-col gap-3 border-t border-zinc-100 p-4 sm:flex-row sm:items-end dark:border-white/10"
                        @submit.prevent="addPrice"
                    >
                        <div class="min-w-0 flex-1">
                            <label
                                class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                >Artigo</label
                            >
                            <div class="mt-2">
                                <SelectInput
                                    v-model="priceForm.catalogue_item"
                                    :options="catalogueOptions"
                                />
                            </div>
                            <FormError
                                :message="priceForm.errors.catalogue_item"
                            />
                        </div>

                        <div class="sm:w-40">
                            <label
                                for="agreed-price"
                                class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                >Preço</label
                            >
                            <input
                                id="agreed-price"
                                v-model="priceForm.unit_price"
                                type="text"
                                inputmode="decimal"
                                placeholder="0,00"
                                class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />
                            <FormError :message="priceForm.errors.unit_price" />
                        </div>

                        <button
                            type="submit"
                            :disabled="priceForm.processing"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:opacity-60 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                        >
                            <LoaderCircle
                                v-if="priceForm.processing"
                                class="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            <Plus v-else class="size-4" aria-hidden="true" />
                            Acordar
                        </button>
                    </form>
                </section>

                <ChartFrame
                    v-if="typeMix.length > 0"
                    title="Por tipo de documento"
                    subtitle="Valor total emitido a este cliente, por tipo."
                    :columns="['Tipo', 'Quantidade', 'Valor']"
                    :rows="mixRows"
                >
                    <ChartBars
                        :bars="typeMix"
                        :format="compactMoney"
                        categorical
                    />
                </ChartFrame>

                <section class="viz overflow-hidden rounded-2xl surface">
                    <header
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 p-5 dark:border-white/10"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                Documentos emitidos
                            </h2>
                            <p
                                class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                Cada documento com o que já foi pago e o que
                                falta.
                            </p>
                        </div>

                        <p
                            v-if="summary.average_days_to_settle !== null"
                            class="inline-flex items-center gap-2 rounded-lg bg-zinc-50 px-3 py-1.5 text-xs font-medium text-zinc-600 dark:bg-white/5 dark:text-zinc-300"
                        >
                            <CircleCheck
                                class="size-3.5 text-emerald-600 dark:text-emerald-400"
                                aria-hidden="true"
                            />
                            Paga em média em
                            {{ summary.average_days_to_settle }} dia(s)
                        </p>
                    </header>

                    <div
                        v-if="documents.total === 0"
                        class="px-5 py-14 text-center text-sm/6 text-zinc-500 dark:text-zinc-400"
                    >
                        Ainda não há documentos emitidos a este cliente.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-100 dark:border-white/10"
                            >
                                <tr>
                                    <th class="px-5 py-3 eyebrow text-zinc-500">
                                        Documento
                                    </th>
                                    <th class="px-5 py-3 eyebrow text-zinc-500">
                                        Data
                                    </th>
                                    <th class="px-5 py-3 eyebrow text-zinc-500">
                                        Estado
                                    </th>
                                    <th
                                        class="px-5 py-3 text-right eyebrow text-zinc-500"
                                    >
                                        Total
                                    </th>
                                    <th
                                        class="hidden px-5 py-3 text-right eyebrow text-zinc-500 sm:table-cell"
                                    >
                                        Pago
                                    </th>
                                    <th
                                        class="px-5 py-3 text-right eyebrow text-zinc-500"
                                    >
                                        Em dívida
                                    </th>
                                    <th class="px-5 py-3">
                                        <span class="sr-only">Enviar</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <tr
                                    v-for="document in documents.data"
                                    :key="document.public_id"
                                >
                                    <td class="px-5 py-3">
                                        <a
                                            v-if="document.can_send"
                                            :href="
                                                printDocument.url({
                                                    fiscalDocument:
                                                        document.public_id,
                                                })
                                            "
                                            target="_blank"
                                            rel="noopener"
                                            class="inline-flex items-center gap-1.5 numeric font-medium text-brand-700 underline-offset-2 focus-ring hover:underline dark:text-brand-300"
                                        >
                                            {{ document.document_no }}
                                            <FileText
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                        </a>
                                        <p
                                            v-else
                                            class="numeric font-medium text-zinc-950 dark:text-white"
                                        >
                                            {{ document.document_no ?? '—' }}
                                        </p>
                                        <p
                                            class="text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ document.document_type_label }}
                                        </p>
                                    </td>
                                    <td
                                        class="px-5 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                                    >
                                        {{ formatDate(document.document_date) }}
                                        <p
                                            v-if="document.due_date"
                                            :class="[
                                                document.overdue
                                                    ? 'text-rose-700 dark:text-rose-400'
                                                    : 'text-zinc-400 dark:text-zinc-500',
                                                'text-xs',
                                            ]"
                                        >
                                            vence
                                            {{ formatDate(document.due_date) }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-3">
                                        <StatusBadge
                                            :tone="
                                                statusTone[document.status] ??
                                                'neutral'
                                            "
                                            :label="document.status_label"
                                        />
                                    </td>
                                    <td
                                        class="px-5 py-3 text-right numeric whitespace-nowrap"
                                        :class="
                                            document.is_credit
                                                ? 'text-zinc-500 dark:text-zinc-400'
                                                : 'text-zinc-950 dark:text-white'
                                        "
                                    >
                                        {{ document.is_credit ? '−' : ''
                                        }}{{
                                            money(document.gross_total_minor)
                                        }}
                                    </td>
                                    <td
                                        class="hidden px-5 py-3 text-right numeric whitespace-nowrap text-zinc-600 sm:table-cell dark:text-zinc-400"
                                    >
                                        {{
                                            document.is_billable
                                                ? money(document.paid_minor)
                                                : '—'
                                        }}
                                    </td>
                                    <td
                                        class="px-5 py-3 text-right numeric whitespace-nowrap"
                                        :class="
                                            document.overdue
                                                ? 'font-medium text-rose-700 dark:text-rose-400'
                                                : document.outstanding_minor > 0
                                                  ? 'text-amber-700 dark:text-amber-400'
                                                  : 'text-zinc-400 dark:text-zinc-500'
                                        "
                                    >
                                        {{
                                            document.is_billable
                                                ? money(
                                                      document.outstanding_minor,
                                                  )
                                                : '—'
                                        }}
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <button
                                            v-if="document.can_send"
                                            type="button"
                                            :disabled="
                                                sendingDocument ===
                                                document.public_id
                                            "
                                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-zinc-600 ring-1 ring-zinc-200 focus-ring transition hover:bg-zinc-50 disabled:opacity-50 dark:text-zinc-300 dark:ring-white/15 dark:hover:bg-white/5"
                                            :title="
                                                document.sent_at
                                                    ? `Último envio a ${formatDate(document.sent_at)} · ${document.send_count}×`
                                                    : 'Ainda não foi enviado'
                                            "
                                            @click="
                                                sendDocument(
                                                    document.public_id,
                                                    document.document_no,
                                                )
                                            "
                                        >
                                            <LoaderCircle
                                                v-if="
                                                    sendingDocument ===
                                                    document.public_id
                                                "
                                                class="size-3.5 animate-spin"
                                                aria-hidden="true"
                                            />
                                            <Send
                                                v-else
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{
                                                document.sent_at
                                                    ? 'Reenviar'
                                                    : 'Enviar'
                                            }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="documents.links.length > 3"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 p-4 dark:border-white/10"
                    >
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ documents.from }}–{{ documents.to }} de
                            {{ documents.total }}
                        </p>

                        <nav
                            class="flex flex-wrap gap-1"
                            aria-label="Paginação"
                        >
                            <Link
                                v-for="link in documents.links"
                                :key="link.label"
                                :href="link.url ?? '#'"
                                preserve-scroll
                                :class="[
                                    link.active
                                        ? 'bg-zinc-950 text-white dark:bg-white dark:text-zinc-950'
                                        : 'text-zinc-600 ring-1 ring-zinc-200 dark:text-zinc-400 dark:ring-white/10',
                                    link.url === null
                                        ? 'pointer-events-none opacity-40'
                                        : '',
                                    'rounded-lg px-3 py-1.5 text-sm focus-ring',
                                ]"
                            >
                                <span v-text="decodeEntities(link.label)" />
                            </Link>
                        </nav>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
