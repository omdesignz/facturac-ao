<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle, Plus, Receipt, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import DateInput from '@/components/DateInput.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as quotesIndex } from '@/routes/quotes';
import {
    convert as convertQuote,
    transition as transitionQuote,
    store as storeQuote,
    update as updateQuote,
} from '@/routes/quotes';
import type { SelectOption } from '@/types/select';

interface QuoteLineInput {
    product_code: string | null;
    product_description: string;
    unit_of_measure: string;
    quantity: string;
    unit_price: string;
    discount_rate: string;
    tax_type: string;
    tax_code: string | null;
    tax_percentage: string;
    tax_exemption_code: string | null;
}

interface CustomerOption {
    public_id: string;
    name: string;
    tax_identification_number: string | null;
    country_code: string;
    address_line: string | null;
    agreed_prices: Record<string, string>;
}

interface CatalogueOption {
    public_id: string;
    code: string;
    name: string;
    description: string | null;
    unit_of_measure: string;
    unit_price: string;
    tax_type: string;
    tax_code: string | null;
    tax_percentage: string;
    tax_exemption_code: string | null;
}

const props = defineProps<{
    quote:
        | (Record<string, unknown> & {
              public_id: string;
              reference: string;
              status: string;
              status_label: string;
              is_editable: boolean;
              can_convert: boolean;
              establishment_public_id: string;
              customer_public_id: string | null;
              customer: {
                  name: string;
                  tax_identification_number: string | null;
                  country_code: string;
                  address_line: string | null;
              };
              notes: string | null;
              issue_date: string;
              valid_until: string;
              lines: QuoteLineInput[];
              gross_total_minor: number;
              converted_document: {
                  public_id: string;
                  document_no: string | null;
              } | null;
          })
        | null;
    establishments: SelectOption[];
    customers: CustomerOption[];
    catalogueItems: CatalogueOption[];
    currencyCode: string;
}>();

function emptyLine(): QuoteLineInput {
    return {
        product_code: null,
        product_description: '',
        unit_of_measure: 'UN',
        quantity: '1',
        unit_price: '0.00',
        discount_rate: '0',
        tax_type: 'IVA',
        tax_code: 'NOR',
        tax_percentage: '14.00',
        tax_exemption_code: null,
    };
}

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    establishment_public_id:
        props.quote?.establishment_public_id ??
        String(props.establishments[0]?.value ?? ''),
    customer_public_id: props.quote?.customer_public_id ?? '',
    customer: props.quote?.customer ?? {
        name: '',
        tax_identification_number: '',
        country_code: 'AO',
        address_line: '',
    },
    issue_date: props.quote?.issue_date.slice(0, 10) ?? today,
    valid_until:
        props.quote?.valid_until.slice(0, 10) ??
        new Date(Date.now() + 30 * 86_400_000).toISOString().slice(0, 10),
    notes: props.quote?.notes ?? '',
    lines: props.quote?.lines ?? [emptyLine()],
});

const readOnly = computed(
    () => props.quote !== null && !props.quote.is_editable,
);

const customerOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Cliente ocasional' },
    ...props.customers.map((customer) => ({
        value: customer.public_id,
        label: customer.name,
    })),
]);

const catalogueOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Escrever manualmente' },
    ...props.catalogueItems.map((item) => ({
        value: item.public_id,
        label: `${item.code} — ${item.name}`,
    })),
]);

const selectedCustomer = computed(() =>
    props.customers.find(
        (candidate) => candidate.public_id === form.customer_public_id,
    ),
);

function selectCustomer(): void {
    const customer = selectedCustomer.value;

    form.customer = customer
        ? {
              name: customer.name,
              tax_identification_number: customer.tax_identification_number,
              country_code: customer.country_code,
              address_line: customer.address_line,
          }
        : {
              name: '',
              tax_identification_number: '',
              country_code: 'AO',
              address_line: '',
          };
}

/** Filling a line from the catalogue, at the price agreed with this customer. */
function applyCatalogueItem(line: QuoteLineInput, publicId: string): void {
    const item = props.catalogueItems.find(
        (candidate) => candidate.public_id === publicId,
    );

    if (item === undefined) {
        return;
    }

    line.product_code = item.code;
    line.product_description = item.description ?? item.name;
    line.unit_of_measure = item.unit_of_measure;
    line.unit_price =
        selectedCustomer.value?.agreed_prices[item.public_id] ??
        item.unit_price;
    line.tax_type = item.tax_type;
    line.tax_code = item.tax_code;
    line.tax_percentage = item.tax_percentage;
    line.tax_exemption_code = item.tax_exemption_code;
}

function addLine(): void {
    form.lines.push(emptyLine());
}

function removeLine(index: number): void {
    if (form.lines.length > 1) {
        form.lines.splice(index, 1);
    }
}

/** Mirrors the server's integer arithmetic so the shown total is the saved one. */
function lineGross(line: QuoteLineInput): number {
    const quantity = Math.round(Number(line.quantity || '0') * 1000);
    const unitPrice = Math.round(Number(line.unit_price || '0') * 100);
    const discount = Math.round(Number(line.discount_rate || '0') * 100);

    const base = Math.trunc((quantity * unitPrice) / 1000);
    const net = base - Math.trunc((base * discount) / 10_000);
    const taxBasisPoints = Math.round(Number(line.tax_percentage || '0') * 100);

    return Math.max(0, net + Math.trunc((net * taxBasisPoints) / 10_000));
}

const grandTotal = computed(() =>
    form.lines.reduce((total, line) => total + lineGross(line), 0),
);

const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function money(minor: number): string {
    return `${moneyFormatter.format(minor / 100)} ${props.currencyCode}`;
}

function submit(): void {
    if (props.quote === null) {
        form.post(storeQuote.url());

        return;
    }

    form.put(updateQuote.url(props.quote.public_id), { preserveScroll: true });
}

function mark(status: 'sent' | 'accepted' | 'rejected'): void {
    if (props.quote === null) {
        return;
    }

    router.put(
        transitionQuote.url(props.quote.public_id),
        { status },
        { preserveScroll: true },
    );
}

function convert(): void {
    if (props.quote === null) {
        return;
    }

    router.post(convertQuote.url(props.quote.public_id));
}

const statusTone: Record<string, 'success' | 'warning' | 'neutral' | 'danger'> =
    {
        draft: 'neutral',
        sent: 'warning',
        accepted: 'success',
        rejected: 'danger',
        expired: 'neutral',
        converted: 'success',
    };
</script>

<template>
    <AppLayout>
        <Head :title="quote ? quote.reference : 'Novo orçamento'" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-5xl space-y-6">
                <Link
                    :href="quotesIndex.url()"
                    class="inline-flex items-center gap-2 rounded-lg text-sm font-medium text-zinc-600 focus-ring transition hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Orçamentos
                </Link>

                <FlashBanner />

                <header
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p class="eyebrow text-brand-700 dark:text-brand-300">
                            {{ quote ? 'Orçamento' : 'Nova proposta' }}
                        </p>
                        <h1
                            class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                        >
                            {{ quote ? quote.reference : 'Novo orçamento' }}
                        </h1>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <StatusBadge
                            v-if="quote"
                            :tone="statusTone[quote.status] ?? 'neutral'"
                            :label="quote.status_label"
                        />

                        <button
                            v-if="quote && quote.status === 'draft'"
                            type="button"
                            class="rounded-xl px-3 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                            @click="mark('sent')"
                        >
                            Marcar como enviado
                        </button>
                        <button
                            v-if="quote && quote.status === 'sent'"
                            type="button"
                            class="rounded-xl px-3 py-2 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-300 focus-ring transition hover:bg-emerald-50 dark:text-emerald-300 dark:ring-emerald-400/30 dark:hover:bg-emerald-400/10"
                            @click="mark('accepted')"
                        >
                            Aceite
                        </button>
                        <button
                            v-if="quote && quote.status === 'sent'"
                            type="button"
                            class="rounded-xl px-3 py-2 text-sm font-semibold text-rose-700 ring-1 ring-rose-300 focus-ring transition hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-400/30 dark:hover:bg-rose-400/10"
                            @click="mark('rejected')"
                        >
                            Recusado
                        </button>

                        <button
                            v-if="quote?.can_convert"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                            @click="convert"
                        >
                            <Receipt class="size-4" aria-hidden="true" />
                            Passar a factura
                        </button>
                    </div>
                </header>

                <p
                    v-if="quote?.converted_document"
                    class="rounded-2xl bg-emerald-50 p-4 text-sm/6 text-emerald-900 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-200 dark:ring-emerald-400/20"
                >
                    Este orçamento já deu origem a um rascunho de factura.
                    Reveja e emita a partir de Facturação.
                </p>

                <form class="space-y-6" @submit.prevent="submit">
                    <fieldset :disabled="readOnly" class="space-y-6">
                        <section class="rounded-2xl surface p-5">
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                Cliente e validade
                            </h2>

                            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >Estabelecimento</label
                                    >
                                    <div class="mt-2">
                                        <SelectInput
                                            v-model="
                                                form.establishment_public_id
                                            "
                                            :options="establishments"
                                        />
                                    </div>
                                    <FormError
                                        :message="
                                            form.errors.establishment_public_id
                                        "
                                    />
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >Cliente</label
                                    >
                                    <div class="mt-2">
                                        <SelectInput
                                            v-model="form.customer_public_id"
                                            :options="customerOptions"
                                            @update:model-value="selectCustomer"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label
                                        for="quote-customer-name"
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >Nome no orçamento</label
                                    >
                                    <input
                                        id="quote-customer-name"
                                        v-model="form.customer.name"
                                        type="text"
                                        class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                    />
                                    <FormError
                                        :message="form.errors['customer.name']"
                                    />
                                </div>

                                <div>
                                    <label
                                        for="quote-customer-nif"
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >NIF
                                        <span class="text-zinc-400"
                                            >(opcional)</span
                                        ></label
                                    >
                                    <input
                                        id="quote-customer-nif"
                                        v-model="
                                            form.customer
                                                .tax_identification_number
                                        "
                                        type="text"
                                        class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                    />
                                </div>

                                <div>
                                    <label
                                        for="quote-issue"
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >Data</label
                                    >
                                    <DateInput
                                        id="quote-issue"
                                        v-model="form.issue_date"
                                        class="mt-2"
                                        :clearable="false"
                                        aria-label="Data do orçamento"
                                    />
                                    <FormError
                                        :message="form.errors.issue_date"
                                    />
                                </div>

                                <div>
                                    <label
                                        for="quote-valid"
                                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                        >Válido até</label
                                    >
                                    <DateInput
                                        id="quote-valid"
                                        v-model="form.valid_until"
                                        class="mt-2"
                                        :clearable="false"
                                        :min-date="form.issue_date"
                                        aria-label="Válido até"
                                    />
                                    <FormError
                                        :message="form.errors.valid_until"
                                    />
                                </div>
                            </div>
                        </section>

                        <section class="overflow-hidden rounded-2xl surface">
                            <header
                                class="flex items-center justify-between border-b border-zinc-100 p-5 dark:border-white/10"
                            >
                                <h2
                                    class="text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    Linhas
                                </h2>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                    @click="addLine"
                                >
                                    <Plus class="size-4" aria-hidden="true" />
                                    Linha
                                </button>
                            </header>

                            <ul
                                class="divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <li
                                    v-for="(line, index) in form.lines"
                                    :key="index"
                                    class="grid gap-4 p-5 sm:grid-cols-12"
                                >
                                    <div class="sm:col-span-5">
                                        <label
                                            class="block text-xs font-medium text-zinc-500 dark:text-zinc-400"
                                            >Artigo</label
                                        >
                                        <div class="mt-1.5">
                                            <SelectInput
                                                :model-value="''"
                                                :options="catalogueOptions"
                                                @update:model-value="
                                                    (value) =>
                                                        applyCatalogueItem(
                                                            line,
                                                            String(value),
                                                        )
                                                "
                                            />
                                        </div>
                                        <input
                                            v-model="line.product_description"
                                            type="text"
                                            placeholder="Descrição"
                                            class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                        />
                                        <FormError
                                            :message="
                                                form.errors[
                                                    `lines.${index}.product_description`
                                                ]
                                            "
                                        />
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label
                                            class="block text-xs font-medium text-zinc-500 dark:text-zinc-400"
                                            >Quantidade</label
                                        >
                                        <input
                                            v-model="line.quantity"
                                            type="text"
                                            inputmode="decimal"
                                            class="mt-1.5 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                        />
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label
                                            class="block text-xs font-medium text-zinc-500 dark:text-zinc-400"
                                            >Preço</label
                                        >
                                        <input
                                            v-model="line.unit_price"
                                            type="text"
                                            inputmode="decimal"
                                            class="mt-1.5 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                        />
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label
                                            class="block text-xs font-medium text-zinc-500 dark:text-zinc-400"
                                            >IVA %</label
                                        >
                                        <input
                                            v-model="line.tax_percentage"
                                            type="text"
                                            inputmode="decimal"
                                            class="mt-1.5 w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                        />
                                    </div>

                                    <div
                                        class="flex items-end justify-between gap-2 sm:col-span-1"
                                    >
                                        <span
                                            class="numeric text-sm font-medium text-zinc-950 dark:text-white"
                                            >{{ money(lineGross(line)) }}</span
                                        >
                                        <button
                                            type="button"
                                            class="rounded-lg p-2 text-zinc-400 focus-ring transition hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-400/10 dark:hover:text-rose-400"
                                            @click="removeLine(index)"
                                        >
                                            <span class="sr-only"
                                                >Remover linha</span
                                            >
                                            <Trash2
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </div>
                                </li>
                            </ul>

                            <div
                                class="flex items-center justify-between border-t border-zinc-100 p-5 dark:border-white/10"
                            >
                                <span
                                    class="text-sm text-zinc-500 dark:text-zinc-400"
                                    >Total do orçamento</span
                                >
                                <span
                                    class="numeric text-xl font-semibold text-zinc-950 dark:text-white"
                                    >{{ money(grandTotal) }}</span
                                >
                            </div>
                        </section>

                        <section class="rounded-2xl surface p-5">
                            <label
                                for="quote-notes"
                                class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                >Notas
                                <span class="text-zinc-400"
                                    >(aparecem no orçamento)</span
                                ></label
                            >
                            <textarea
                                id="quote-notes"
                                v-model="form.notes"
                                rows="3"
                                class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />
                        </section>
                    </fieldset>

                    <div v-if="!readOnly" class="flex justify-end">
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
                            {{
                                quote ? 'Guardar alterações' : 'Criar orçamento'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
