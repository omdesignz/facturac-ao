<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    CircleCheck,
    Download,
    FileCode2,
    FileSignature,
    HandCoins,
    Package,
    ReceiptText,
    RefreshCw,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DateInput from '@/components/DateInput.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormField from '@/components/FormField.vue';
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
            working_documents: number;
            payments: number;
            customers: number;
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
        value: props.summary.counts.sales_invoices,
        icon: ReceiptText,
    },
    {
        label: 'Orçamentos enviados',
        value: props.summary.counts.working_documents,
        icon: FileSignature,
    },
    {
        label: 'Recibos',
        value: props.summary.counts.payments,
        icon: HandCoins,
    },
    {
        label: 'Clientes',
        value: props.summary.counts.customers,
        icon: Users,
    },
    {
        label: 'Artigos',
        value: props.summary.counts.products,
        icon: Package,
    },
]);

function displayDate(isoDate: string): string {
    return isoDate.split('-').reverse().join('/');
}

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

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell space-y-6">
                <FlashBanner />

                <header>
                    <p class="text-[0.8125rem] text-[var(--ui-ink-3)]">
                        Fiscal
                    </p>
                    <h1
                        class="mt-0.5 text-[1.375rem] leading-[1.2] font-[650] tracking-[-0.025em] text-balance text-[var(--ui-ink)]"
                    >
                        SAF-T (AO)
                    </h1>
                    <p
                        class="mt-2 max-w-3xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Reveja o período antes de descarregar. O ficheiro é
                        conferido com o modelo oficial da AGT (versão
                        {{ schema.version }}) antes de chegar até si.
                    </p>
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
                        <FormField for="saft-from" label="Data inicial"
                            ><DateInput
                                id="saft-from"
                                v-model="from"
                                :clearable="false"
                        /></FormField>
                        <FormField for="saft-to" label="Data final"
                            ><DateInput
                                id="saft-to"
                                v-model="to"
                                :clearable="false"
                                :min-date="from ?? undefined"
                        /></FormField>
                        <FormField label="Âmbito"
                            ><SelectInput
                                v-model="establishment"
                                :options="establishments"
                        /></FormField>
                        <AppButton
                            variant="outline"
                            :disabled="!datesReady"
                            @click="inspectPeriod"
                        >
                            <RefreshCw class="size-4" aria-hidden="true" />
                            Rever período
                        </AppButton>
                    </div>
                </section>

                <section
                    class="grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fit,minmax(10rem,1fr))]"
                    aria-label="Conteúdo do ficheiro"
                >
                    <article
                        v-for="card in countCards"
                        :key="card.label"
                        class="rounded-2xl surface p-4"
                    >
                        <component
                            :is="card.icon"
                            class="size-5 text-[var(--ui-ink-3)]"
                            aria-hidden="true"
                        />
                        <p
                            class="mt-3 numeric text-2xl font-semibold text-[var(--ui-ink)] sm:mt-4"
                        >
                            {{ card.value }}
                        </p>
                        <p
                            class="mt-1 text-[0.8125rem] font-medium text-[var(--ui-ink-2)]"
                        >
                            {{ card.label }}
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
                                    Verificações do ficheiro
                                </h2>
                                <p
                                    class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ summary.period.scope_label }} ·
                                    {{ displayDate(summary.period.from) }} a
                                    {{ displayDate(summary.period.to) }}
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
                                        ? 'Verificações concluídas'
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

                    <aside class="h-fit rounded-2xl surface p-5 sm:p-6">
                        <h2 class="font-semibold text-zinc-950 dark:text-white">
                            Ficheiro de facturação
                        </h2>
                        <p
                            class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Inclui os documentos emitidos no período, mesmo os
                            anulados. Rascunhos não fazem parte do ficheiro.
                        </p>
                        <AppButton
                            variant="gold"
                            external
                            v-if="canDownload"
                            :href="downloadUrl"
                            class="mt-5 w-full"
                        >
                            <Download class="size-4" aria-hidden="true" />
                            Descarregar SAF-T (AO)
                        </AppButton>
                        <span
                            v-else
                            aria-disabled="true"
                            class="mt-5 inline-flex h-10 w-full cursor-not-allowed items-center justify-center gap-2 rounded-full bg-zinc-900/[0.06] px-[1.125rem] text-sm font-semibold text-zinc-500 dark:bg-white/10 dark:text-zinc-400"
                        >
                            <Download class="size-4" aria-hidden="true" />
                            Exportação indisponível
                        </span>
                        <p
                            v-if="!summary.exportable"
                            class="mt-3 text-xs/5 text-red-700 dark:text-red-300"
                        >
                            Há documentos incompletos no período. Corrija os
                            registos indicados antes de gerar o ficheiro.
                        </p>
                        <p
                            v-else-if="!summary.ready"
                            class="mt-3 text-xs/5 text-amber-700 dark:text-amber-300"
                        >
                            Pode descarregar para testes. Corrija os avisos
                            antes da entrega oficial à AGT.
                        </p>
                        <dl
                            class="mt-5 space-y-2 border-t border-zinc-100 pt-4 text-xs dark:border-white/10"
                        >
                            <div class="flex justify-between gap-3">
                                <dt class="text-zinc-500 dark:text-zinc-400">
                                    Versão
                                </dt>
                                <dd
                                    class="numeric text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ schema.version }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-zinc-500 dark:text-zinc-400">
                                    Âmbito
                                </dt>
                                <dd class="text-zinc-800 dark:text-zinc-200">
                                    {{ schema.scope }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-zinc-500 dark:text-zinc-400">
                                    Formato
                                </dt>
                                <dd class="text-zinc-800 dark:text-zinc-200">
                                    XML
                                </dd>
                            </div>
                        </dl>
                    </aside>
                </section>

                <section
                    class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm/6 text-sky-900 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-200"
                >
                    <p class="font-semibold">Âmbito e obrigação de submissão</p>
                    <p class="mt-1">
                        Este ficheiro SAF-T de facturação (tipo F) inclui
                        facturas e notas, recibos e orçamentos enviados. As
                        guias de transporte e de remessa não integram esta
                        exportação; continuam disponíveis no módulo de
                        transportes. Este ficheiro não substitui o SAF-T de
                        contabilidade.
                    </p>
                    <p class="[margin-block-start:0.5rem]">
                        Segundo o comunicado da AGT de 20 de Março de 2026, os
                        contribuintes dos Regimes Geral e Simplificado do IVA
                        que emitem facturas electronicamente estão dispensados
                        de submeter o SAF-T de facturação. A aprovação de testes
                        de homologação, por si só, não determina essa dispensa.
                        Pode continuar a descarregar o ficheiro para arquivo e
                        conferência.
                    </p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
