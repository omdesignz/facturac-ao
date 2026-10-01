<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Building2,
    Check,
    CircleCheck,
    Download,
    FileCode2,
    FileSignature,
    HandCoins,
    Package,
    ReceiptText,
    RefreshCw,
    ShieldCheck,
    Truck,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DateInput from '@/components/DateInput.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import SelectInput from '@/components/SelectInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { exportMethod as exportSaft, index as saftIndex } from '@/routes/saft';
import type { SelectOption } from '@/types/select';

interface ReadinessCheck {
    key: string;
    label: string;
    ready: boolean;
    blocking: boolean;
    detail: string;
}

const props = defineProps<{
    summary: {
        period: {
            from: string;
            to: string;
            establishment: string | null;
            scope_label: string;
        };
        counts: {
            sales_invoices: number;
            movement_of_goods: number;
            working_documents: number;
            payments: number;
            customers: number;
            suppliers: number;
            products: number;
        };
        readiness: ReadinessCheck[];
        ready: boolean;
        exportable: boolean;
    };
    establishments: SelectOption<string>[];
    schema: { version: string; namespace: string; scope: string };
}>();

const from = ref<string | null>(props.summary.period.from);
const to = ref<string | null>(props.summary.period.to);
const establishment = ref(props.summary.period.establishment ?? '');

const countCards = computed(() => [
    {
        label: 'Facturas e notas',
        detail: 'SalesInvoices',
        value: props.summary.counts.sales_invoices,
        icon: ReceiptText,
    },
    {
        label: 'Guias',
        detail: 'MovementOfGoods',
        value: props.summary.counts.movement_of_goods,
        icon: Truck,
    },
    {
        label: 'Orçamentos enviados',
        detail: 'WorkingDocuments',
        value: props.summary.counts.working_documents,
        icon: FileSignature,
    },
    {
        label: 'Recibos',
        detail: 'Payments',
        value: props.summary.counts.payments,
        icon: HandCoins,
    },
    {
        label: 'Clientes',
        detail: 'MasterFiles',
        value: props.summary.counts.customers,
        icon: Users,
    },
    {
        label: 'Fornecedores',
        detail: 'MasterFiles',
        value: props.summary.counts.suppliers,
        icon: Building2,
    },
    {
        label: 'Artigos',
        detail: 'MasterFiles',
        value: props.summary.counts.products,
        icon: Package,
    },
]);

const downloadUrl = computed(() =>
    exportSaft.url({
        query: {
            from: from.value ?? '',
            to: to.value ?? '',
            ...(establishment.value
                ? { establishment: establishment.value }
                : {}),
        },
    }),
);
const datesReady = computed(() => Boolean(from.value && to.value));
const canDownload = computed(
    () => datesReady.value && props.summary.exportable,
);

function inspectPeriod(): void {
    if (!datesReady.value) {
        return;
    }

    router.get(
        saftIndex.url(),
        {
            from: from.value,
            to: to.value,
            establishment: establishment.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}
</script>

<template>
    <AppLayout>
        <Head title="SAF-T (AO)" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-7xl space-y-6">
                <FlashBanner />

                <header
                    class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <p class="eyebrow text-brand-700 dark:text-brand-300">
                            Ficheiro normalizado de auditoria
                        </p>
                        <h1
                            class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                        >
                            SAF-T (AO)
                        </h1>
                        <p
                            class="mt-2 max-w-3xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            Reveja o conteúdo fiscal do período antes de
                            descarregar. O ficheiro é validado localmente contra
                            o esquema AGT {{ schema.version }} antes de sair da
                            aplicação.
                        </p>
                    </div>
                    <div
                        class="flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20"
                    >
                        <ShieldCheck class="size-4" aria-hidden="true" />
                        Validação XSD sem rede
                    </div>
                </header>

                <section class="rounded-2xl surface p-5 sm:p-6">
                    <div class="flex items-start gap-3">
                        <FileCode2
                            class="mt-0.5 size-5 text-brand-600 dark:text-brand-300"
                            aria-hidden="true"
                        />
                        <div>
                            <h2
                                class="font-semibold text-zinc-950 dark:text-white"
                            >
                                Preparar exportação
                            </h2>
                            <p
                                class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                Um SAF-T pertence a um único exercício. Pode
                                limitar o ficheiro a um estabelecimento.
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-[1fr_1fr_1.5fr_auto] xl:items-end"
                    >
                        <div>
                            <label
                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >Data inicial</label
                            >
                            <DateInput v-model="from" :clearable="false" />
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >Data final</label
                            >
                            <DateInput
                                v-model="to"
                                :clearable="false"
                                :min-date="from ?? undefined"
                            />
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >Âmbito</label
                            >
                            <SelectInput
                                v-model="establishment"
                                :options="establishments"
                            />
                        </div>
                        <button
                            type="button"
                            :disabled="!datesReady"
                            class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring hover:bg-zinc-50 disabled:opacity-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                            @click="inspectPeriod"
                        >
                            <RefreshCw class="size-4" aria-hidden="true" />
                            Rever período
                        </button>
                    </div>
                </section>

                <section
                    class="grid gap-3 sm:grid-cols-2 xl:grid-cols-7"
                    aria-label="Conteúdo do ficheiro"
                >
                    <article
                        v-for="card in countCards"
                        :key="card.label"
                        class="rounded-2xl surface p-4"
                    >
                        <component
                            :is="card.icon"
                            class="size-5 text-brand-600 dark:text-brand-300"
                            aria-hidden="true"
                        />
                        <p
                            class="mt-4 numeric text-2xl font-semibold text-zinc-950 dark:text-white"
                        >
                            {{ card.value }}
                        </p>
                        <p
                            class="mt-1 text-sm font-medium text-zinc-700 dark:text-zinc-300"
                        >
                            {{ card.label }}
                        </p>
                        <p
                            class="mt-1 text-[0.6875rem] text-zinc-500 dark:text-zinc-400"
                        >
                            {{ card.detail }}
                        </p>
                    </article>
                </section>

                <section class="grid gap-6 xl:grid-cols-[1.35fr_0.65fr]">
                    <article class="rounded-2xl surface p-5 sm:p-6">
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div>
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Verificações antes da entrega
                                </h2>
                                <p
                                    class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ summary.period.scope_label }} ·
                                    {{ summary.period.from }} a
                                    {{ summary.period.to }}
                                </p>
                            </div>
                            <span
                                class="inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="
                                    summary.ready
                                        ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300'
                                        : !summary.exportable
                                          ? 'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-300'
                                          : 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300'
                                "
                            >
                                <CircleCheck
                                    v-if="summary.ready"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <AlertTriangle
                                    v-else
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{
                                    summary.ready
                                        ? 'Pronto para entrega'
                                        : !summary.exportable
                                          ? 'Exportação bloqueada'
                                          : 'Preparação incompleta'
                                }}
                            </span>
                        </div>

                        <ul
                            class="mt-5 divide-y divide-zinc-100 dark:divide-white/10"
                        >
                            <li
                                v-for="check in summary.readiness"
                                :key="check.key"
                                class="flex gap-3 py-4 first:pt-0 last:pb-0"
                            >
                                <span
                                    class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full"
                                    :class="
                                        check.ready
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                            : check.blocking
                                              ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'
                                              : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                                    "
                                >
                                    <Check
                                        v-if="check.ready"
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <AlertTriangle
                                        v-else
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <p
                                        class="text-sm font-semibold text-zinc-800 dark:text-zinc-200"
                                    >
                                        {{ check.label }}
                                    </p>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        {{ check.detail }}
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </article>

                    <aside
                        class="rounded-2xl bg-brand-950 p-6 text-white shadow-sm dark:ring-1 dark:ring-white/10"
                    >
                        <p class="eyebrow text-accent-300">
                            Ficheiro de facturação
                        </p>
                        <h2 class="mt-3 text-xl display">
                            Descarregar XML validado
                        </h2>
                        <p class="mt-3 text-sm/6 text-zinc-300">
                            Inclui os documentos emitidos no período, mesmo os
                            anulados. Rascunhos não fazem parte do ficheiro.
                        </p>
                        <a
                            v-if="canDownload"
                            :href="downloadUrl"
                            class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent-400 px-4 py-3 text-sm font-semibold text-brand-950 focus-ring-inverted hover:bg-accent-300"
                        >
                            <Download class="size-4" aria-hidden="true" />
                            Descarregar SAF-T (AO)
                        </a>
                        <span
                            v-else
                            aria-disabled="true"
                            class="mt-6 inline-flex w-full cursor-not-allowed items-center justify-center gap-2 rounded-xl bg-zinc-700 px-4 py-3 text-sm font-semibold text-zinc-400"
                        >
                            <Download class="size-4" aria-hidden="true" />
                            Exportação indisponível
                        </span>
                        <p
                            v-if="!summary.exportable"
                            class="mt-3 text-xs/5 text-red-200"
                        >
                            Há documentos incompletos no período. Corrija os
                            registos indicados antes de gerar o XML.
                        </p>
                        <p
                            v-else-if="!summary.ready"
                            class="mt-3 text-xs/5 text-amber-200"
                        >
                            Pode descarregar para testes. Corrija os avisos
                            antes da entrega oficial à AGT.
                        </p>
                        <dl
                            class="mt-6 space-y-2 border-t border-white/10 pt-5 text-xs"
                        >
                            <div class="flex justify-between gap-3">
                                <dt class="text-zinc-400">Versão</dt>
                                <dd class="numeric">{{ schema.version }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-zinc-400">Âmbito</dt>
                                <dd>{{ schema.scope }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-zinc-400">Formato</dt>
                                <dd>XML UTF-8</dd>
                            </div>
                        </dl>
                    </aside>
                </section>

                <section
                    class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm/6 text-sky-900 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-200"
                >
                    <p class="font-semibold">Sobre o âmbito contabilístico</p>
                    <p class="mt-1">
                        Este módulo exporta os registos que a aplicação detém:
                        facturação, recibos, orçamentos e movimentos de
                        mercadorias. O diário contabilístico e o plano de contas
                        exigem dados de contabilidade geral e não são inventados
                        a partir das facturas.
                    </p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
