<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, Link2, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';

interface PrintLine {
    line_number: number;
    operation_date: string | null;
    product_code: string | null;
    product_description: string;
    unit_of_measure: string;
    quantity: string;
    unit_price_minor: number;
    discount_rate: string;
    net_amount_minor: number;
    tax_amount_minor: number;
    gross_amount_minor: number;
    tax_rate: string;
    tax_exemption_code: string | null;
}

const props = defineProps<{
    document: {
        public_id: string;
        document_no: string;
        document_type: string;
        document_type_label: string;
        status_label: string;
        agt_accepted: boolean;
        document_date: string;
        due_date: string | null;
        issued_at: string | null;
        currency_code: string;
        notes: string | null;
        payment_method_label: string | null;
        company: {
            legal_name: string;
            trade_name: string | null;
            tax_identification_number: string;
            establishment: string;
            address_line: string;
            municipality: string | null;
            province_code: string;
        };
        customer: {
            name: string;
            tax_identification_number: string;
            address_line: string | null;
            country_code: string;
        };
        lines: PrintLine[];
        tax_summary: {
            rate: string;
            base_minor: number;
            tax_minor: number;
            exemption_code: string | null;
        }[];
        totals: {
            net_minor: number;
            tax_minor: number;
            gross_minor: number;
            settled_minor: number;
        };
        authenticity: {
            software_validation_number: string | null;
            software_product_id: string | null;
            digest: string | null;
            full_digest: string | null;
            qr_svg: string | null;
        };
        support_email: string | null;
    };
    shareUrl: string | null;
}>();

const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function money(minor: number): string {
    return moneyFormatter.format(minor / 100);
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'long' });

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

const outstanding = computed(
    () =>
        props.document.totals.gross_minor - props.document.totals.settled_minor,
);

function printDocument(): void {
    window.print();
}

const copied = ref(false);

async function copyShareLink(): Promise<void> {
    if (props.shareUrl === null) {
        return;
    }

    await navigator.clipboard.writeText(props.shareUrl);
    copied.value = true;
    window.setTimeout(() => {
        copied.value = false;
    }, 2000);
}
</script>

<template>
    <div class="min-h-screen bg-zinc-100 py-8 print:bg-white print:py-0">
        <Head :title="document.document_no" />

        <!-- Not part of the document: the controls that produce it. -->
        <div
            class="doc-no-print mx-auto mb-6 flex max-w-[210mm] flex-wrap items-center justify-between gap-3 px-4"
        >
            <p class="text-sm text-zinc-600">
                Guarde como PDF a partir da janela de impressão.
            </p>
            <div class="flex items-center gap-2">
                <button
                    v-if="shareUrl"
                    type="button"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-full bg-white px-[1.125rem] text-sm font-semibold text-zinc-800 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50"
                    @click="copyShareLink"
                >
                    <Check v-if="copied" class="size-4" aria-hidden="true" />
                    <Link2 v-else class="size-4" aria-hidden="true" />
                    {{ copied ? 'Copiado' : 'Copiar ligação' }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl bg-zinc-950 px-4 py-2 text-sm font-semibold text-white focus-ring transition hover:bg-zinc-800"
                    @click="printDocument"
                >
                    <Printer class="size-4" aria-hidden="true" />
                    Imprimir ou guardar em PDF
                </button>
            </div>
        </div>

        <article
            class="doc-sheet mx-auto max-w-[210mm] bg-white px-10 py-10 text-zinc-900 shadow-sm ring-1 ring-zinc-900/10 print:ring-0"
        >
            <header class="doc-keep flex items-start justify-between gap-8">
                <div>
                    <p class="text-base font-semibold">
                        {{
                            document.company.trade_name ??
                            document.company.legal_name
                        }}
                    </p>
                    <p class="mt-0.5 text-xs/5 text-zinc-600">
                        {{ document.company.legal_name }}
                    </p>
                    <p class="mt-2 numeric text-xs/5 text-zinc-600">
                        NIF {{ document.company.tax_identification_number }}
                    </p>
                    <p class="mt-2 text-xs/5 text-zinc-600">
                        {{ document.company.establishment }} ·
                        {{ document.company.address_line }}<br />
                        <template v-if="document.company.municipality"
                            >{{ document.company.municipality }} · </template
                        >{{ document.company.province_code }}
                    </p>
                </div>

                <div class="text-right">
                    <p class="eyebrow text-zinc-500">
                        {{ document.document_type_label }}
                    </p>
                    <p class="mt-1 numeric text-2xl font-semibold">
                        {{ document.document_no }}
                    </p>
                    <p
                        v-if="document.agt_accepted"
                        class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-emerald-600/20"
                    >
                        <Check class="size-3" aria-hidden="true" />
                        Aceite pela AGT
                    </p>
                    <p v-else class="mt-2 text-xs text-zinc-500">
                        {{ document.status_label }}
                    </p>
                </div>
            </header>

            <section
                class="doc-keep mt-8 grid grid-cols-2 gap-8 border-t border-zinc-200 pt-6 text-xs/5"
            >
                <div>
                    <p class="eyebrow text-zinc-500">Cliente</p>
                    <p class="mt-1.5 font-semibold">
                        {{ document.customer.name }}
                    </p>
                    <p class="numeric text-zinc-600">
                        NIF {{ document.customer.tax_identification_number }}
                    </p>
                    <p
                        v-if="document.customer.address_line"
                        class="text-zinc-600"
                    >
                        {{ document.customer.address_line }}
                    </p>
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 self-start">
                    <dt class="text-zinc-500">Data</dt>
                    <dd class="text-right numeric">
                        {{ formatDate(document.document_date) }}
                    </dd>
                    <template v-if="document.due_date">
                        <dt class="text-zinc-500">Vencimento</dt>
                        <dd class="text-right numeric">
                            {{ formatDate(document.due_date) }}
                        </dd>
                    </template>
                    <template v-if="document.payment_method_label">
                        <dt class="text-zinc-500">Pagamento</dt>
                        <dd class="text-right">
                            {{ document.payment_method_label }}
                        </dd>
                    </template>
                    <dt class="text-zinc-500">Moeda</dt>
                    <dd class="text-right numeric">
                        {{ document.currency_code }}
                    </dd>
                </dl>
            </section>

            <table class="mt-8 w-full text-left text-xs">
                <thead>
                    <tr class="border-y border-zinc-300 bg-zinc-50">
                        <th class="py-2 pr-3 font-semibold">Descrição</th>
                        <th class="px-2 py-2 text-right font-semibold">Qtd.</th>
                        <th class="px-2 py-2 text-right font-semibold">
                            Preço
                        </th>
                        <th class="px-2 py-2 text-right font-semibold">
                            Desc.
                        </th>
                        <th class="px-2 py-2 text-right font-semibold">IVA</th>
                        <th class="py-2 pl-2 text-right font-semibold">
                            Total
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in document.lines"
                        :key="line.line_number"
                        class="border-b border-zinc-200 align-top"
                    >
                        <td class="py-2 pr-3">
                            <p class="font-medium">
                                {{ line.product_description }}
                            </p>
                            <p
                                v-if="line.product_code"
                                class="numeric text-zinc-500"
                            >
                                {{ line.product_code }}
                            </p>
                            <p v-if="line.operation_date" class="text-zinc-500">
                                Operação · {{ formatDate(line.operation_date) }}
                            </p>
                            <p
                                v-if="line.tax_exemption_code"
                                class="text-zinc-500"
                            >
                                Isento · {{ line.tax_exemption_code }}
                            </p>
                        </td>
                        <td
                            class="px-2 py-2 text-right numeric whitespace-nowrap"
                        >
                            {{ line.quantity }} {{ line.unit_of_measure }}
                        </td>
                        <td
                            class="px-2 py-2 text-right numeric whitespace-nowrap"
                        >
                            {{ money(line.unit_price_minor) }}
                        </td>
                        <td
                            class="px-2 py-2 text-right numeric whitespace-nowrap"
                        >
                            {{ line.discount_rate }}%
                        </td>
                        <td
                            class="px-2 py-2 text-right numeric whitespace-nowrap"
                        >
                            {{ line.tax_rate }}%
                        </td>
                        <td
                            class="py-2 pl-2 text-right numeric font-medium whitespace-nowrap"
                        >
                            {{ money(line.gross_amount_minor) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <section class="doc-keep mt-6 flex justify-between gap-8">
                <table
                    v-if="document.tax_summary.length > 0"
                    class="text-left text-xs"
                >
                    <caption class="pb-1.5 text-left eyebrow text-zinc-500">
                        Resumo de imposto
                    </caption>
                    <thead>
                        <tr class="border-b border-zinc-300">
                            <th class="py-1.5 pr-4 font-semibold">Taxa</th>
                            <th class="px-4 py-1.5 text-right font-semibold">
                                Incidência
                            </th>
                            <th class="py-1.5 pl-4 text-right font-semibold">
                                Imposto
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in document.tax_summary"
                            :key="`${row.rate}-${row.exemption_code ?? ''}`"
                            class="border-b border-zinc-200"
                        >
                            <td class="py-1.5 pr-4 numeric">
                                {{ row.rate }}%
                                <span
                                    v-if="row.exemption_code"
                                    class="text-zinc-500"
                                    >· {{ row.exemption_code }}</span
                                >
                            </td>
                            <td class="px-4 py-1.5 text-right numeric">
                                {{ money(row.base_minor) }}
                            </td>
                            <td class="py-1.5 pl-4 text-right numeric">
                                {{ money(row.tax_minor) }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <dl class="w-64 shrink-0 text-xs">
                    <div class="flex justify-between py-1.5">
                        <dt class="text-zinc-600">Subtotal</dt>
                        <dd class="numeric">
                            {{ money(document.totals.net_minor) }}
                        </dd>
                    </div>
                    <div class="flex justify-between py-1.5">
                        <dt class="text-zinc-600">Imposto</dt>
                        <dd class="numeric">
                            {{ money(document.totals.tax_minor) }}
                        </dd>
                    </div>
                    <div
                        class="mt-1 flex justify-between border-t-2 border-zinc-900 py-2 text-sm font-semibold"
                    >
                        <dt>Total</dt>
                        <dd class="numeric">
                            {{ money(document.totals.gross_minor) }}
                            {{ document.currency_code }}
                        </dd>
                    </div>
                    <template v-if="document.totals.settled_minor > 0">
                        <div class="flex justify-between py-1.5">
                            <dt class="text-zinc-600">Pago</dt>
                            <dd class="numeric">
                                {{ money(document.totals.settled_minor) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between border-t border-zinc-300 py-1.5 font-semibold"
                        >
                            <dt>Em dívida</dt>
                            <dd class="numeric">{{ money(outstanding) }}</dd>
                        </div>
                    </template>
                </dl>
            </section>

            <p v-if="document.notes" class="mt-6 text-xs/5 text-zinc-600">
                {{ document.notes }}
            </p>

            <footer
                class="doc-keep mt-10 flex items-end justify-between gap-8 border-t border-zinc-200 pt-6"
            >
                <div class="text-[0.625rem]/4 text-zinc-500">
                    <p
                        v-if="document.authenticity.software_validation_number"
                        class="numeric"
                    >
                        Processado por programa validado n.º
                        {{
                            document.authenticity.software_validation_number
                        }}/AGT
                    </p>
                    <p v-if="document.authenticity.digest" class="mt-1 numeric">
                        Documento
                        <span class="font-semibold">{{
                            document.authenticity.digest
                        }}</span>
                        — confirme com o emissor em caso de dúvida.
                    </p>
                    <p v-if="document.support_email" class="mt-1">
                        {{ document.support_email }}
                    </p>
                    <AppLogo :tagline="false" class="mt-3 text-xs opacity-60" />
                </div>

                <div
                    v-if="document.authenticity.qr_svg"
                    class="shrink-0 text-center"
                >
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <div
                        class="size-[30mm] [&>svg]:size-full"
                        v-html="document.authenticity.qr_svg"
                    />
                    <p class="mt-1 text-[0.5625rem] text-zinc-500">Verificar</p>
                </div>
            </footer>
        </article>
    </div>
</template>
