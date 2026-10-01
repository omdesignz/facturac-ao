<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileDown, Printer } from '@lucide/vue';
import { computed } from 'vue';
import { pdf as transportDocumentPdf } from '@/routes/transport-documents';

interface PrintLine {
    line_number: number;
    product_code: string;
    product_description: string;
    quantity: string;
    unit_of_measure: string;
    unit_price: string;
    net_amount_minor: number;
}

const props = defineProps<{
    transportDocument: {
        public_id: string;
        document_no: string;
        document_type_label: string;
        party_label: string;
        status: string;
        status_label: string;
        movement_date: string;
        movement_start_at: string;
        movement_end_at: string | null;
        recipient: {
            name: string;
            tax_identification_number: string;
            address: string;
            city: string;
            province: string | null;
            country_code: string;
        };
        origin: {
            address: string;
            city: string;
            province: string | null;
            country_code: string;
        };
        destination: {
            address: string;
            city: string;
            province: string | null;
            country_code: string;
        };
        transporter: {
            name: string | null;
            tax_identification_number: string | null;
            vehicle_registration: string | null;
        };
        gross_weight_kg: string | null;
        package_count: number | null;
        notes: string | null;
        currency_code: string;
        gross_total_minor: number;
        cancellation_reason: string | null;
        lines: PrintLine[];
        company: {
            legal_name: string;
            trade_name: string | null;
            tax_identification_number: string;
            address: string;
            city: string | null;
            province: string;
        };
        establishment: { name: string; code: string };
        authenticity: {
            software_product_id: string | null;
            software_validation_number: string | null;
            hash_control: string | null;
            digest: string | null;
        };
    };
}>();

const document = computed(() => props.transportDocument);
const dateFormatter = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'long' });
const dateTimeFormatter = new Intl.DateTimeFormat('pt-PT', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function formatDate(value: string): string {
    return dateFormatter.format(new Date(`${value}T12:00:00`));
}

function formatDateTime(value: string | null): string {
    return value
        ? dateTimeFormatter.format(new Date(value.replace(' ', 'T')))
        : '—';
}

function money(minor: number): string {
    return moneyFormatter.format(minor / 100);
}

function printDocument(): void {
    window.print();
}
</script>

<template>
    <div class="min-h-screen bg-zinc-100 py-8 print:bg-white print:py-0">
        <Head :title="document.document_no" />

        <div
            class="doc-no-print mx-auto mb-6 flex max-w-[210mm] flex-wrap items-center justify-between gap-3 px-4"
        >
            <p class="text-sm text-zinc-600">
                Confirme que a guia acompanha a mercadoria durante o percurso.
            </p>
            <div class="flex items-center gap-2">
                <a
                    :href="transportDocumentPdf.url(document.public_id)"
                    class="inline-flex items-center gap-2 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring hover:bg-zinc-50"
                >
                    <FileDown class="size-4" aria-hidden="true" /> PDF
                </a>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl bg-zinc-950 px-4 py-2 text-sm font-semibold text-white focus-ring hover:bg-zinc-800"
                    @click="printDocument"
                >
                    <Printer class="size-4" aria-hidden="true" /> Imprimir
                </button>
            </div>
        </div>

        <article
            class="doc-sheet relative mx-auto max-w-[210mm] overflow-hidden bg-white px-10 py-10 text-zinc-900 shadow-sm ring-1 ring-zinc-900/10 print:ring-0"
        >
            <div
                v-if="document.status === 'cancelled'"
                class="pointer-events-none absolute inset-x-0 top-[44%] -rotate-12 text-center text-7xl font-bold tracking-[0.25em] text-rose-600/12"
            >
                ANULADO
            </div>

            <header
                class="doc-keep flex items-start justify-between gap-8 border-b-2 border-zinc-900 pb-6"
            >
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
                        {{ document.establishment.name }} ·
                        {{ document.company.address }}<br />{{
                            document.company.city
                        }}
                        · {{ document.company.province }}
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
                        class="mt-2 text-xs font-medium"
                        :class="
                            document.status === 'cancelled'
                                ? 'text-rose-700'
                                : 'text-emerald-700'
                        "
                    >
                        {{ document.status_label }}
                    </p>
                </div>
            </header>

            <section class="doc-keep mt-6 grid grid-cols-2 gap-8 text-xs/5">
                <div>
                    <p class="eyebrow text-zinc-500">
                        {{ document.party_label }}
                    </p>
                    <p class="mt-1.5 font-semibold">
                        {{ document.recipient.name }}
                    </p>
                    <p class="numeric text-zinc-600">
                        NIF {{ document.recipient.tax_identification_number }}
                    </p>
                    <p class="mt-1 text-zinc-600">
                        {{ document.recipient.address }}<br />{{
                            document.recipient.city
                        }}<template v-if="document.recipient.province">
                            · {{ document.recipient.province }}</template
                        >
                        · {{ document.recipient.country_code }}
                    </p>
                </div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 self-start">
                    <dt class="text-zinc-500">Data</dt>
                    <dd class="text-right numeric">
                        {{ formatDate(document.movement_date) }}
                    </dd>
                    <dt class="text-zinc-500">Início</dt>
                    <dd class="text-right numeric">
                        {{ formatDateTime(document.movement_start_at) }}
                    </dd>
                    <template v-if="document.movement_end_at"
                        ><dt class="text-zinc-500">Fim previsto</dt>
                        <dd class="text-right numeric">
                            {{ formatDateTime(document.movement_end_at) }}
                        </dd></template
                    >
                    <dt class="text-zinc-500">Estabelecimento</dt>
                    <dd class="text-right">
                        {{ document.establishment.code }}
                    </dd>
                </dl>
            </section>

            <section
                class="doc-keep mt-6 grid grid-cols-2 gap-6 rounded-xl border border-zinc-300 p-4 text-xs/5"
            >
                <div>
                    <p class="eyebrow text-zinc-500">Origem / carga</p>
                    <p class="mt-2 font-medium">
                        {{ document.origin.address }}
                    </p>
                    <p class="text-zinc-600">
                        {{ document.origin.city
                        }}<template v-if="document.origin.province">
                            · {{ document.origin.province }}</template
                        >
                        · {{ document.origin.country_code }}
                    </p>
                </div>
                <div>
                    <p class="eyebrow text-zinc-500">Destino / descarga</p>
                    <p class="mt-2 font-medium">
                        {{ document.destination.address }}
                    </p>
                    <p class="text-zinc-600">
                        {{ document.destination.city
                        }}<template v-if="document.destination.province">
                            · {{ document.destination.province }}</template
                        >
                        · {{ document.destination.country_code }}
                    </p>
                </div>
            </section>

            <section
                v-if="
                    document.transporter.name ||
                    document.transporter.vehicle_registration ||
                    document.gross_weight_kg ||
                    document.package_count
                "
                class="doc-keep mt-5 grid grid-cols-4 gap-4 border-y border-zinc-200 py-3 text-xs"
            >
                <div>
                    <p class="text-zinc-500">Transportador</p>
                    <p class="mt-1 font-medium">
                        {{ document.transporter.name ?? '—' }}
                    </p>
                </div>
                <div>
                    <p class="text-zinc-500">NIF / matrícula</p>
                    <p class="mt-1 numeric">
                        {{
                            document.transporter.tax_identification_number ??
                            '—'
                        }}<br />{{
                            document.transporter.vehicle_registration ?? '—'
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-zinc-500">Peso bruto</p>
                    <p class="mt-1 numeric">
                        {{
                            document.gross_weight_kg
                                ? `${document.gross_weight_kg} kg`
                                : '—'
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-zinc-500">Volumes</p>
                    <p class="mt-1 numeric">
                        {{ document.package_count ?? '—' }}
                    </p>
                </div>
            </section>

            <table class="mt-7 w-full text-left text-xs">
                <thead>
                    <tr class="border-y border-zinc-300 bg-zinc-50">
                        <th class="py-2 pr-2 font-semibold">#</th>
                        <th class="px-2 py-2 font-semibold">Código</th>
                        <th class="px-2 py-2 font-semibold">Mercadoria</th>
                        <th class="px-2 py-2 text-right font-semibold">
                            Quantidade
                        </th>
                        <th class="px-2 py-2 text-right font-semibold">
                            Preço unit.
                        </th>
                        <th class="py-2 pl-2 text-right font-semibold">
                            Valor
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in document.lines"
                        :key="line.line_number"
                        class="border-b border-zinc-200 align-top"
                    >
                        <td class="py-2 pr-2 numeric text-zinc-500">
                            {{ line.line_number }}
                        </td>
                        <td class="px-2 py-2 numeric">
                            {{ line.product_code }}
                        </td>
                        <td class="px-2 py-2 font-medium">
                            {{ line.product_description }}
                        </td>
                        <td
                            class="px-2 py-2 text-right numeric whitespace-nowrap"
                        >
                            {{ line.quantity }} {{ line.unit_of_measure }}
                        </td>
                        <td
                            class="px-2 py-2 text-right numeric whitespace-nowrap"
                        >
                            {{ line.unit_price }}
                        </td>
                        <td
                            class="py-2 pl-2 text-right numeric font-medium whitespace-nowrap"
                        >
                            {{ money(line.net_amount_minor) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="doc-keep mt-5 flex justify-end">
                <dl class="w-72 border-t-2 border-zinc-900 pt-2 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="font-semibold">Valor da mercadoria</dt>
                        <dd class="numeric font-semibold">
                            {{ money(document.gross_total_minor) }}
                            {{ document.currency_code }}
                        </dd>
                    </div>
                </dl>
            </div>

            <p v-if="document.notes" class="mt-6 text-xs/5 text-zinc-600">
                {{ document.notes }}
            </p>
            <p
                v-if="document.cancellation_reason"
                class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800"
            >
                <strong>Motivo da anulação:</strong>
                {{ document.cancellation_reason }}
            </p>

            <footer
                class="doc-keep mt-10 border-t border-zinc-200 pt-5 text-[0.625rem]/4 text-zinc-500"
            >
                <p
                    v-if="document.authenticity.software_validation_number"
                    class="numeric"
                >
                    Processado por programa validado n.º
                    {{ document.authenticity.software_validation_number }}
                </p>
                <p v-if="document.authenticity.digest" class="mt-1 numeric">
                    Integridade {{ document.authenticity.digest }} ·
                    {{ document.authenticity.hash_control }}
                </p>
                <p class="mt-1">
                    Documento de acompanhamento de mercadorias · SAF-T (AO)
                    MovementOfGoods
                </p>
            </footer>
        </article>
    </div>
</template>
