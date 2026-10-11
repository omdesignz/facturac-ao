<script setup lang="ts">
import { computed } from 'vue';
import RecordDialog from '@/components/RecordDialog.vue';
import { formatMinor } from '@/lib/pos';
import { currencyLabel, formatClock } from '@/lib/pos-ui';
import { index as documentsIndex } from '@/routes/documents';
import type { PosCashMovement, PosSaleRow, PosTillSummary } from '@/types/pos';

const props = defineProps<{
    open: boolean;
    registerName: string;
    openedAt: string;
    openingFloatMinor: number;
    summary: PosTillSummary;
    cashMovements: PosCashMovement[];
    recentSales: PosSaleRow[];
    currencyCode: string;
}>();

const emit = defineEmits<{ close: []; afterLeave: [] }>();

const suffix = computed(() => currencyLabel(props.currencyCode));

/**
 * The drawer's arithmetic, one row per term, so the cashier can see where the
 * expected figure comes from rather than trusting a total.
 */
const drawerRows = computed(() => [
    { label: 'Fundo de caixa', minor: props.openingFloatMinor, sign: '' },
    {
        label: 'Vendas em numerário',
        minor: props.summary.cash_sales_minor,
        sign: '+',
    },
    { label: 'Reforços', minor: props.summary.cash_in_minor, sign: '+' },
    { label: 'Retiradas', minor: props.summary.cash_out_minor, sign: '−' },
]);

function documentHref(sale: PosSaleRow): string {
    return documentsIndex.url({
        query: { documento: sale.document_public_id },
    });
}

const linkClass =
    'rounded font-medium underline underline-offset-4 focus-ring hover:text-zinc-950 dark:hover:text-white';
</script>

<template>
    <RecordDialog
        :open="open"
        eyebrow="Turno"
        title="Resumo do turno"
        :description="`${registerName} · aberto às ${formatClock(openedAt)}`"
        @close="emit('close')"
        @after-leave="emit('afterLeave')"
    >
        <div class="space-y-6">
            <dl class="grid grid-cols-2 gap-4">
                <div>
                    <dt class="eyebrow text-zinc-500 dark:text-zinc-400">
                        Vendas
                    </dt>
                    <dd
                        class="mt-2 numeric text-stat leading-none tracking-[-0.03em] text-zinc-950 dark:text-white"
                    >
                        {{ summary.sales_count }}
                    </dd>
                </div>
                <div>
                    <dt class="eyebrow text-zinc-500 dark:text-zinc-400">
                        Total
                    </dt>
                    <dd
                        class="mt-2 numeric text-stat leading-none tracking-[-0.03em] text-zinc-950 dark:text-white"
                    >
                        {{ formatMinor(summary.gross_total_minor)
                        }}<span
                            class="ms-1 text-xs font-normal tracking-normal text-zinc-500 dark:text-zinc-400"
                            >{{ suffix }}</span
                        >
                    </dd>
                </div>
            </dl>

            <section aria-labelledby="pos-summary-methods">
                <h3
                    id="pos-summary-methods"
                    class="mb-2 eyebrow text-zinc-500 dark:text-zinc-400"
                >
                    Por meio de pagamento
                </h3>
                <p
                    v-if="summary.by_method.length === 0"
                    class="text-sm text-zinc-600 dark:text-zinc-400"
                >
                    Ainda não houve vendas neste turno.
                </p>
                <ul
                    v-else
                    class="divide-y divide-zinc-900/[0.07] rounded-2xl bg-zinc-900/[0.04] px-4 dark:divide-white/10 dark:bg-white/[0.04]"
                >
                    <li
                        v-for="row in summary.by_method"
                        :key="row.method"
                        class="flex items-baseline justify-between gap-4 py-2.5 text-sm"
                    >
                        <span class="min-w-0 text-zinc-800 dark:text-zinc-200"
                            >{{ row.label }}
                            <span
                                class="numeric text-zinc-500 dark:text-zinc-400"
                                >· {{ row.count }}</span
                            ></span
                        >
                        <span
                            class="shrink-0 numeric font-medium text-zinc-950 dark:text-white"
                            >{{ formatMinor(row.total_minor) }}</span
                        >
                    </li>
                </ul>
            </section>

            <section aria-labelledby="pos-summary-drawer">
                <h3
                    id="pos-summary-drawer"
                    class="mb-2 eyebrow text-zinc-500 dark:text-zinc-400"
                >
                    Numerário esperado na gaveta
                </h3>
                <dl
                    class="divide-y divide-zinc-900/[0.07] rounded-2xl bg-zinc-900/[0.04] px-4 dark:divide-white/10 dark:bg-white/[0.04]"
                >
                    <div
                        v-for="row in drawerRows"
                        :key="row.label"
                        class="flex items-baseline justify-between gap-4 py-2.5 text-sm"
                    >
                        <dt class="text-zinc-700 dark:text-zinc-300">
                            {{ row.label }}
                        </dt>
                        <dd class="numeric text-zinc-950 dark:text-white">
                            {{ row.sign }}{{ formatMinor(row.minor) }}
                        </dd>
                    </div>
                    <div
                        class="flex items-baseline justify-between gap-4 py-3 text-base font-semibold"
                    >
                        <dt class="text-zinc-950 dark:text-white">Esperado</dt>
                        <dd class="numeric text-zinc-950 dark:text-white">
                            {{ formatMinor(summary.expected_cash_minor)
                            }}<span
                                class="ms-1 text-xs font-normal text-zinc-500 dark:text-zinc-400"
                                >{{ suffix }}</span
                            >
                        </dd>
                    </div>
                </dl>
            </section>

            <section
                v-if="cashMovements.length > 0"
                aria-labelledby="pos-summary-movements"
            >
                <h3
                    id="pos-summary-movements"
                    class="mb-2 eyebrow text-zinc-500 dark:text-zinc-400"
                >
                    Movimentos de caixa
                </h3>
                <ul
                    class="divide-y divide-zinc-900/[0.07] rounded-2xl bg-zinc-900/[0.04] px-4 dark:divide-white/10 dark:bg-white/[0.04]"
                >
                    <li
                        v-for="movement in cashMovements"
                        :key="movement.public_id"
                        class="flex items-baseline justify-between gap-4 py-2.5 text-sm"
                    >
                        <span class="min-w-0 text-zinc-800 dark:text-zinc-200">
                            <span class="font-medium">{{
                                movement.type_label
                            }}</span>
                            ·
                            <span class="[overflow-wrap:anywhere]">{{
                                movement.reason
                            }}</span>
                            <span
                                class="ms-1 numeric text-zinc-500 dark:text-zinc-400"
                                >{{ formatClock(movement.created_at) }}</span
                            >
                        </span>
                        <span
                            class="shrink-0 numeric font-medium text-zinc-950 dark:text-white"
                            >{{ movement.type === 'out' ? '−' : '+'
                            }}{{ formatMinor(movement.amount_minor) }}</span
                        >
                    </li>
                </ul>
            </section>

            <section
                v-if="recentSales.length > 0"
                aria-labelledby="pos-summary-sales"
            >
                <h3
                    id="pos-summary-sales"
                    class="mb-2 eyebrow text-zinc-500 dark:text-zinc-400"
                >
                    Últimas vendas
                </h3>
                <ul
                    class="divide-y divide-zinc-900/[0.07] rounded-2xl bg-zinc-900/[0.04] px-4 dark:divide-white/10 dark:bg-white/[0.04]"
                >
                    <li
                        v-for="sale in recentSales"
                        :key="sale.public_id"
                        class="flex items-start justify-between gap-4 py-3 text-sm"
                    >
                        <div class="min-w-0">
                            <p
                                class="font-mono numeric text-zinc-950 dark:text-white"
                            >
                                <a
                                    v-if="sale.document_no"
                                    :href="documentHref(sale)"
                                    target="_blank"
                                    rel="noopener"
                                    :class="linkClass"
                                    >{{ sale.document_no
                                    }}<span class="sr-only">
                                        (abre noutro separador)</span
                                    ></a
                                >
                                <span v-else>—</span>
                            </p>
                            <p
                                class="mt-0.5 text-xs [overflow-wrap:anywhere] text-zinc-600 dark:text-zinc-400"
                            >
                                {{ formatClock(sale.issued_at) }} ·
                                {{ sale.customer_name }} ·
                                {{ sale.payment_method_label }}
                            </p>
                        </div>
                        <div class="shrink-0 text-end">
                            <p
                                class="numeric font-medium text-zinc-950 dark:text-white"
                            >
                                {{ formatMinor(sale.total_minor) }}
                            </p>
                            <a
                                :href="sale.receipt_url"
                                target="_blank"
                                rel="noopener"
                                :class="[
                                    linkClass,
                                    'tap-target mt-0.5 inline-block text-xs text-zinc-600 dark:text-zinc-400',
                                ]"
                                >Talão<span class="sr-only">
                                    de
                                    {{ sale.document_no ?? 'esta venda' }}</span
                                ></a
                            >
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </RecordDialog>
</template>
