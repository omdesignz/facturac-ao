<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ReceiptText } from '@lucide/vue';
import FlashBanner from '@/components/FlashBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMinor, formatSignedMinor } from '@/lib/pos';
import {
    buttonGold,
    buttonOutline,
    currencyLabel,
    formatDayAndClock,
    wellSection,
} from '@/lib/pos-ui';
import { show as posShow } from '@/routes/pos';
import { index as registersIndex } from '@/routes/pos/registers';
import { show as sessionShow } from '@/routes/pos/sessions';
import type { PosPaginationLink } from '@/types/pos';

interface SessionRow {
    public_id: string;
    register_name: string;
    establishment_name: string;
    opened_by_name: string;
    opened_at: string;
    closed_at: string | null;
    status: 'open' | 'closed';
    status_label: string;
    sales_count: number;
    gross_total_minor: number;
    cash_difference_minor: number | null;
}

defineProps<{
    sessions: {
        data: SessionRow[];
        links: PosPaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    currencyCode: string;
    canManage: boolean;
}>();

/** Laravel's pagination labels arrive as HTML entities (&laquo; Anterior). */
function pageLabel(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&nbsp;/g, ' ')
        .trim();
}
</script>

<template>
    <AppLayout>
        <Head title="Turnos" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Vendas · Ponto de venda"
                    title="Turnos"
                    description="Cada turno é uma caixa aberta por alguém, do fundo de caixa ao fecho. Abra um para ver as vendas, a gaveta e a diferença contada."
                >
                    <template #actions>
                        <Link
                            v-if="canManage"
                            :href="registersIndex.url()"
                            :class="buttonOutline"
                        >
                            Caixas
                        </Link>
                        <Link :href="posShow.url()" :class="buttonGold">
                            Ponto de venda
                        </Link>
                    </template>
                </PageHeader>

                <div :class="[wellSection, 'overflow-clip']">
                    <div
                        v-if="sessions.data.length === 0"
                        class="px-6 py-16 text-center"
                    >
                        <ReceiptText
                            class="mx-auto size-8 text-zinc-400 dark:text-zinc-500"
                            aria-hidden="true"
                        />
                        <p
                            class="mt-4 font-semibold text-zinc-900 dark:text-white"
                        >
                            Ainda não há turnos
                        </p>
                        <p
                            class="mx-auto mt-1 max-w-sm text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            Quando abrir uma caixa no ponto de venda, o turno
                            aparece aqui.
                        </p>
                    </div>

                    <div
                        v-else
                        class="relative overflow-x-auto overscroll-x-contain"
                    >
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b border-zinc-900/[0.07] text-start dark:border-white/10"
                                >
                                    <th
                                        scope="col"
                                        class="px-3 py-3 text-start eyebrow text-zinc-600 sm:px-4 dark:text-zinc-400"
                                    >
                                        Caixa
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-3 text-start eyebrow text-zinc-600 sm:table-cell sm:px-4 dark:text-zinc-400"
                                    >
                                        Operador
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-3 text-start eyebrow text-zinc-600 md:table-cell md:px-4 dark:text-zinc-400"
                                    >
                                        Aberto
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-3 text-start eyebrow text-zinc-600 lg:table-cell lg:px-4 dark:text-zinc-400"
                                    >
                                        Fechado
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-3 text-start eyebrow text-zinc-600 md:table-cell md:px-4 dark:text-zinc-400"
                                    >
                                        Estado
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-3 text-end eyebrow text-zinc-600 sm:table-cell sm:px-4 dark:text-zinc-400"
                                    >
                                        Vendas
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-3 text-end eyebrow text-zinc-600 sm:px-4 dark:text-zinc-400"
                                    >
                                        Total
                                        <span class="font-normal normal-case"
                                            >({{
                                                currencyLabel(currencyCode)
                                            }})</span
                                        >
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-3 text-end eyebrow text-zinc-600 lg:table-cell lg:px-4 dark:text-zinc-400"
                                    >
                                        Diferença
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-900/[0.06] dark:divide-white/10"
                            >
                                <tr
                                    v-for="row in sessions.data"
                                    :key="row.public_id"
                                    class="align-top hover:bg-zinc-900/[0.025] dark:hover:bg-white/[0.03]"
                                >
                                    <td class="px-3 py-3 sm:px-4">
                                        <Link
                                            :href="
                                                sessionShow.url(row.public_id)
                                            "
                                            class="rounded font-semibold [overflow-wrap:anywhere] text-zinc-900 underline-offset-4 focus-ring hover:underline dark:text-white"
                                        >
                                            {{ row.register_name }}
                                        </Link>
                                        <p
                                            class="mt-0.5 text-xs [overflow-wrap:anywhere] text-zinc-600 dark:text-zinc-400"
                                        >
                                            {{ row.establishment_name }}
                                        </p>
                                        <p
                                            class="mt-0.5 text-xs [overflow-wrap:anywhere] text-zinc-600 sm:hidden dark:text-zinc-400"
                                        >
                                            {{ row.opened_by_name }}
                                        </p>
                                        <p
                                            class="mt-0.5 numeric text-xs text-zinc-600 md:hidden dark:text-zinc-400"
                                        >
                                            {{ formatDayAndClock(row.opened_at)
                                            }}<template v-if="row.closed_at">
                                                →
                                                {{
                                                    formatDayAndClock(
                                                        row.closed_at,
                                                    )
                                                }}
                                            </template>
                                        </p>
                                        <StatusBadge
                                            class="mt-1.5 md:hidden"
                                            :label="row.status_label"
                                            :tone="
                                                row.status === 'open'
                                                    ? 'info'
                                                    : 'neutral'
                                            "
                                        />
                                        <p
                                            class="mt-1 numeric text-xs text-zinc-600 sm:hidden dark:text-zinc-400"
                                        >
                                            {{ row.sales_count }}
                                            {{
                                                row.sales_count === 1
                                                    ? 'venda'
                                                    : 'vendas'
                                            }}
                                            <template
                                                v-if="
                                                    row.cash_difference_minor !==
                                                        null &&
                                                    row.cash_difference_minor !==
                                                        0
                                                "
                                            >
                                                ·
                                                <span
                                                    class="font-semibold text-rose-700 dark:text-rose-400"
                                                    >{{
                                                        formatSignedMinor(
                                                            row.cash_difference_minor,
                                                        )
                                                    }}</span
                                                >
                                            </template>
                                        </p>
                                    </td>
                                    <td
                                        class="hidden px-3 py-3 [overflow-wrap:anywhere] text-zinc-700 sm:table-cell sm:px-4 dark:text-zinc-300"
                                    >
                                        {{ row.opened_by_name }}
                                    </td>
                                    <td
                                        class="hidden px-3 py-3 numeric text-zinc-700 md:table-cell md:px-4 dark:text-zinc-300"
                                    >
                                        {{ formatDayAndClock(row.opened_at) }}
                                    </td>
                                    <td
                                        class="hidden px-3 py-3 numeric text-zinc-700 lg:table-cell lg:px-4 dark:text-zinc-300"
                                    >
                                        {{ formatDayAndClock(row.closed_at) }}
                                    </td>
                                    <td
                                        class="hidden px-3 py-3 md:table-cell md:px-4"
                                    >
                                        <StatusBadge
                                            :label="row.status_label"
                                            :tone="
                                                row.status === 'open'
                                                    ? 'info'
                                                    : 'neutral'
                                            "
                                        />
                                    </td>
                                    <td
                                        class="hidden px-3 py-3 text-end numeric text-zinc-700 sm:table-cell sm:px-4 dark:text-zinc-300"
                                    >
                                        {{ row.sales_count }}
                                    </td>
                                    <td
                                        class="px-3 py-3 text-end numeric font-semibold whitespace-nowrap text-zinc-950 sm:px-4 dark:text-white"
                                    >
                                        {{ formatMinor(row.gross_total_minor) }}
                                    </td>
                                    <td
                                        class="hidden px-3 py-3 text-end numeric whitespace-nowrap lg:table-cell lg:px-4"
                                        :class="
                                            row.cash_difference_minor !==
                                                null &&
                                            row.cash_difference_minor !== 0
                                                ? 'font-semibold text-rose-700 dark:text-rose-400'
                                                : 'text-zinc-600 dark:text-zinc-400'
                                        "
                                    >
                                        <template
                                            v-if="
                                                row.cash_difference_minor ===
                                                null
                                            "
                                            >—</template
                                        >
                                        <template v-else>{{
                                            formatSignedMinor(
                                                row.cash_difference_minor,
                                            )
                                        }}</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <nav
                        v-if="sessions.data.length > 0"
                        aria-label="Paginação"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-900/[0.07] px-4 py-3 dark:border-white/10"
                    >
                        <p class="text-xs text-zinc-600 dark:text-zinc-400">
                            <span class="numeric"
                                >{{ sessions.from }}–{{ sessions.to }}</span
                            >
                            de
                            <span class="numeric">{{ sessions.total }}</span>
                        </p>
                        <div
                            v-if="sessions.links.length > 3"
                            class="flex flex-wrap gap-1"
                        >
                            <template
                                v-for="link in sessions.links"
                                :key="link.label"
                            >
                                <Link
                                    v-if="link.url"
                                    :href="link.url"
                                    preserve-scroll
                                    :aria-current="
                                        link.active ? 'page' : undefined
                                    "
                                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-full px-2.5 text-xs font-semibold focus-ring transition pointer-coarse:h-11 pointer-coarse:min-w-11"
                                    :class="
                                        link.active
                                            ? 'bg-brand-950 text-white dark:bg-zinc-100 dark:text-brand-950'
                                            : 'text-zinc-600 hover:bg-zinc-900/[0.05] dark:text-zinc-300 dark:hover:bg-white/5'
                                    "
                                    >{{ pageLabel(link.label) }}</Link
                                >
                                <span
                                    v-else
                                    aria-disabled="true"
                                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-full px-2.5 text-xs font-semibold text-zinc-400 dark:text-zinc-500 pointer-coarse:h-11"
                                    >{{ pageLabel(link.label) }}</span
                                >
                            </template>
                        </div>
                    </nav>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
