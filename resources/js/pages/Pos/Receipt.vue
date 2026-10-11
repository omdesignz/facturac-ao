<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Printer, X } from '@lucide/vue';
import { nextTick, onMounted } from 'vue';
import { formatMinor, formatQuantity } from '@/lib/pos';
import { buttonInk, buttonOutline } from '@/lib/pos-ui';

/**
 * The till receipt: the fiscal document in 72mm, plus what changed hands.
 *
 * It carries everything `Documents/Print.vue` carries for the same document
 * (issuer, document, customer, lines, tax, totals, payment, the validated
 * software line, the document digest and the QR code, which is the server's
 * own SVG, not regenerated here). A thermal printer has no greys and no
 * backgrounds, so the receipt is black on white throughout.
 */
interface ReceiptLine {
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
        lines: ReceiptLine[];
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
    sale: {
        tendered_minor: number;
        change_minor: number;
        payment_method_label: string;
        register_name: string;
        cashier_name: string;
    };
}>();

const dayFormatter = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'long' });
const clockFormatter = new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
});

/** A calendar date is a day, not an instant: read it without a timezone. */
function formatDay(value: string | null): string {
    if (!value) {
        return '—';
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);

    return dayFormatter.format(
        match
            ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
            : new Date(value),
    );
}

const issuedClock = props.document.issued_at
    ? clockFormatter.format(new Date(props.document.issued_at))
    : null;

const currency =
    props.document.currency_code === 'AOA'
        ? 'Kz'
        : props.document.currency_code;

const outstanding =
    props.document.totals.gross_minor - props.document.totals.settled_minor;

/** Cash is the one method that has money handed over and given back. */
const handedOver =
    props.sale.payment_method_label === 'Numerário' ||
    props.sale.change_minor > 0;

let printed = false;

/**
 * Print once, when the page can look its best: fonts loaded and the QR drawn.
 * The QR is inline SVG, so there is no image to wait for; a frame is enough.
 */
async function printOnce(): Promise<void> {
    if (printed) {
        return;
    }

    printed = true;
    await document.fonts.ready;
    await nextTick();
    await new Promise<void>((resolve) =>
        requestAnimationFrame(() => resolve()),
    );
    window.print();
}

onMounted(() => {
    void printOnce();
});

function print(): void {
    window.print();
}

function close(): void {
    window.close();

    // A window the till did not open cannot close itself; go back instead.
    window.setTimeout(() => {
        if (!window.closed && window.history.length > 1) {
            window.history.back();
        }
    }, 150);
}
</script>

<template>
    <div
        class="receipt-page min-h-dvh bg-zinc-200 px-3 py-6 text-black dark:bg-zinc-800 print:bg-white print:p-0"
    >
        <Head :title="document.document_no" />

        <div
            class="no-print mx-auto mb-4 flex w-[72mm] max-w-full items-center justify-between gap-2"
        >
            <button type="button" :class="buttonInk" @click="print">
                <Printer class="size-4" aria-hidden="true" />
                Imprimir
            </button>
            <button type="button" :class="buttonOutline" @click="close">
                <X class="size-4" aria-hidden="true" />
                Fechar
            </button>
        </div>

        <article
            class="receipt mx-auto w-[72mm] max-w-full bg-white px-[3mm] py-[4mm] font-mono text-[11px]/[1.4] text-black shadow-sm print:p-0 print:shadow-none"
        >
            <header class="text-center">
                <p class="text-[13px]/[1.3] font-semibold break-words">
                    {{
                        document.company.trade_name ??
                        document.company.legal_name
                    }}
                </p>
                <p v-if="document.company.trade_name" class="break-words">
                    {{ document.company.legal_name }}
                </p>
                <p>NIF {{ document.company.tax_identification_number }}</p>
                <p class="break-words">
                    {{ document.company.establishment }} ·
                    {{ document.company.address_line }}
                </p>
                <p class="break-words">
                    <template v-if="document.company.municipality"
                        >{{ document.company.municipality }} · </template
                    >{{ document.company.province_code }}
                </p>
            </header>

            <hr class="rc-rule" />

            <section class="text-center">
                <p class="uppercase">{{ document.document_type_label }}</p>
                <p class="text-[13px]/[1.3] font-semibold break-words">
                    {{ document.document_no }}
                </p>
                <p v-if="document.agt_accepted">Aceite pela AGT</p>
                <p v-else>{{ document.status_label }}</p>
            </section>

            <dl class="rc-pairs mt-2">
                <div>
                    <dt>Data</dt>
                    <dd>
                        {{ formatDay(document.document_date)
                        }}<template v-if="issuedClock">
                            · {{ issuedClock }}</template
                        >
                    </dd>
                </div>
                <div v-if="document.due_date">
                    <dt>Vencimento</dt>
                    <dd>{{ formatDay(document.due_date) }}</dd>
                </div>
                <div v-if="document.payment_method_label">
                    <dt>Pagamento</dt>
                    <dd>{{ document.payment_method_label }}</dd>
                </div>
                <div>
                    <dt>Moeda</dt>
                    <dd>{{ document.currency_code }}</dd>
                </div>
                <div>
                    <dt>Caixa</dt>
                    <dd>{{ sale.register_name }}</dd>
                </div>
                <div>
                    <dt>Operador</dt>
                    <dd>{{ sale.cashier_name }}</dd>
                </div>
            </dl>

            <hr class="rc-rule" />

            <section>
                <p class="font-semibold">Cliente</p>
                <p class="break-words">{{ document.customer.name }}</p>
                <p>NIF {{ document.customer.tax_identification_number }}</p>
                <p v-if="document.customer.address_line" class="break-words">
                    {{ document.customer.address_line }}
                </p>
            </section>

            <hr class="rc-rule" />

            <ol class="space-y-2" aria-label="Linhas">
                <li v-for="line in document.lines" :key="line.line_number">
                    <p class="font-semibold break-words">
                        {{ line.product_description }}
                    </p>
                    <p v-if="line.product_code" class="break-words text-[#333]">
                        {{ line.product_code }}
                    </p>
                    <p v-if="line.operation_date" class="text-[#333]">
                        Operação · {{ formatDay(line.operation_date) }}
                    </p>
                    <p v-if="line.tax_exemption_code" class="text-[#333]">
                        Isento · {{ line.tax_exemption_code }}
                    </p>
                    <div class="rc-row">
                        <span class="min-w-0"
                            >{{ formatQuantity(line.quantity) }}
                            {{ line.unit_of_measure }} ×
                            {{ formatMinor(line.unit_price_minor) }}</span
                        >
                        <span class="shrink-0 font-semibold">{{
                            formatMinor(line.gross_amount_minor)
                        }}</span>
                    </div>
                    <p class="text-[#333]">
                        Preço s/ IVA · IVA {{ line.tax_rate }}%<template
                            v-if="Number(line.discount_rate) > 0"
                        >
                            · Desc. {{ line.discount_rate }}%</template
                        >
                    </p>
                </li>
            </ol>

            <hr class="rc-rule" />

            <section v-if="document.tax_summary.length > 0">
                <p class="font-semibold">Resumo de imposto</p>
                <table class="mt-0.5 w-full text-start">
                    <thead>
                        <tr>
                            <th class="pe-1 text-start font-normal">Taxa</th>
                            <th class="px-1 text-end font-normal">
                                Incidência
                            </th>
                            <th class="ps-1 text-end font-normal">Imposto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in document.tax_summary"
                            :key="`${row.rate}-${row.exemption_code ?? ''}`"
                        >
                            <td class="pe-1">
                                {{ row.rate }}%<template
                                    v-if="row.exemption_code"
                                >
                                    · {{ row.exemption_code }}</template
                                >
                            </td>
                            <td class="px-1 text-end">
                                {{ formatMinor(row.base_minor) }}
                            </td>
                            <td class="ps-1 text-end">
                                {{ formatMinor(row.tax_minor) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <dl class="mt-2">
                <div class="rc-row">
                    <dt>Subtotal</dt>
                    <dd>{{ formatMinor(document.totals.net_minor) }}</dd>
                </div>
                <div class="rc-row">
                    <dt>Imposto</dt>
                    <dd>{{ formatMinor(document.totals.tax_minor) }}</dd>
                </div>
                <div
                    class="rc-row mt-1 border-t-2 border-black pt-1 text-[14px]/[1.3] font-semibold"
                >
                    <dt>Total</dt>
                    <dd>
                        {{ formatMinor(document.totals.gross_minor) }}
                        {{ currency }}
                    </dd>
                </div>
                <template v-if="document.totals.settled_minor > 0">
                    <div class="rc-row">
                        <dt>Pago</dt>
                        <dd>
                            {{ formatMinor(document.totals.settled_minor) }}
                        </dd>
                    </div>
                    <div v-if="outstanding > 0" class="rc-row font-semibold">
                        <dt>Em dívida</dt>
                        <dd>{{ formatMinor(outstanding) }}</dd>
                    </div>
                </template>
            </dl>

            <dl class="mt-2">
                <div class="rc-row">
                    <dt>Meio de pagamento</dt>
                    <dd>{{ sale.payment_method_label }}</dd>
                </div>
                <template v-if="handedOver">
                    <div class="rc-row">
                        <dt>Recebido</dt>
                        <dd>{{ formatMinor(sale.tendered_minor) }}</dd>
                    </div>
                    <div class="rc-row font-semibold">
                        <dt>Troco</dt>
                        <dd>{{ formatMinor(sale.change_minor) }}</dd>
                    </div>
                </template>
            </dl>

            <p v-if="document.notes" class="mt-2 break-words">
                {{ document.notes }}
            </p>

            <hr class="rc-rule" />

            <footer class="text-center text-[10px]/[1.4]">
                <p v-if="document.authenticity.software_validation_number">
                    Processado por programa validado n.º
                    {{ document.authenticity.software_validation_number }}/AGT
                </p>
                <p v-if="document.authenticity.digest" class="mt-1 break-words">
                    Documento
                    <span class="font-semibold">{{
                        document.authenticity.digest
                    }}</span>
                    — confirme com o emissor em caso de dúvida.
                </p>
                <p v-if="document.support_email" class="mt-1 break-words">
                    {{ document.support_email }}
                </p>

                <div v-if="document.authenticity.qr_svg" class="mt-3">
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <div
                        class="mx-auto size-[30mm] [&>svg]:size-full"
                        v-html="document.authenticity.qr_svg"
                    />
                    <p class="mt-1">Verificar</p>
                </div>
            </footer>
        </article>
    </div>
</template>

<style>
/*
 * An 80mm roll. The page is as long as the receipt, with a 3mm margin all
 * round, so the 72mm column fills what the printer can reach.
 */
@page {
    size: 80mm auto;
    margin: 3mm;
}

html,
body {
    background: white;
}

.receipt dl > div > dt,
.receipt dl > div > dd {
    margin: 0;
}

.receipt .rc-rule {
    margin-block: 0.4rem;
    border: 0;
    border-top: 1px dashed #000;
}

.receipt .rc-row {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
}

.receipt .rc-row > :last-child {
    text-align: end;
    font-variant-numeric: tabular-nums;
}

.receipt .rc-pairs > div {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
}

.receipt .rc-pairs > div > dd {
    text-align: end;
}

.receipt tr,
.receipt li {
    break-inside: avoid;
}

@media print {
    html,
    body {
        background: #fff !important;
    }

    .receipt-page {
        min-height: 0 !important;
        background: #fff !important;
    }

    .receipt,
    .receipt * {
        color: #000 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .receipt {
        width: 72mm !important;
        max-width: none !important;
    }

    .no-print {
        display: none !important;
    }
}
</style>
