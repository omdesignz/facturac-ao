<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';

export interface DocumentOverview {
    from: string;
    to: string;
    cleared: number;
    current: number;
    overdue: number;
    drafts: number;
    issued: number;
    total: number;
}

const props = defineProps<{
    summary: DocumentOverview;
    period: string;
    href: string;
}>();

const groups = computed(() => [
    {
        key: 'cleared',
        label: 'Sem saldo',
        count: props.summary.cleared,
        colour: 'var(--color-accent-400)',
    },
    {
        key: 'current',
        label: 'Por vencer',
        count: props.summary.current,
        colour: 'var(--color-accent-200)',
    },
    {
        key: 'overdue',
        label: 'Em atraso',
        count: props.summary.overdue,
        colour: 'var(--ui-critical)',
    },
    {
        key: 'drafts',
        label: 'Rascunhos',
        count: props.summary.drafts,
        colour: 'var(--ui-ink-3)',
    },
]);

/** A bounded plot, with one common scale across the four groups. */
const capacity = 80;
const unit = computed(() =>
    Math.max(
        1,
        Math.ceil(
            Math.max(...groups.value.map((group) => group.count)) / capacity,
        ),
    ),
);
const number = (value: number): string =>
    new Intl.NumberFormat('pt-AO').format(value);
</script>

<template>
    <section
        class="flex min-w-0 flex-col gap-5 rounded-xl bg-[var(--surface-raised)] p-5 shadow-[var(--ui-card-shadow)]"
        aria-labelledby="document-overview-title"
    >
        <header
            class="flex flex-wrap items-center gap-2 border-b border-[var(--ui-line)] pb-4"
        >
            <h2 id="document-overview-title" class="text-sm font-semibold">
                Documentos de venda de {{ period }}
            </h2>
            <span class="numeric text-xs text-[var(--ui-ink-3)]">{{
                number(summary.total)
            }}</span>
            <Link
                :href="href"
                class="ms-auto inline-flex min-h-8 items-center gap-1 rounded-lg px-2 text-xs font-medium text-[var(--ui-ink-2)] focus-ring hover:bg-[var(--ui-wash)] active:bg-[var(--ui-well)]"
            >
                Ver documentos
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Link>
        </header>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-5 sm:grid-cols-4">
            <div
                v-for="group in groups"
                :key="group.key"
                class="flex min-w-0 flex-col gap-4"
            >
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <dt class="flex items-center gap-1.5 text-xs font-medium">
                        <span
                            class="size-1.5 shrink-0 rounded-full sm:hidden"
                            :style="{ background: group.colour }"
                            aria-hidden="true"
                        />
                        {{ group.label }}
                    </dt>
                    <dd class="numeric text-sm text-[var(--ui-ink-2)]">
                        {{ number(group.count) }}
                    </dd>
                </div>
                <div
                    class="hidden grid-cols-10 justify-items-start gap-x-1 gap-y-1.5 sm:grid"
                    aria-hidden="true"
                >
                    <span
                        v-for="dot in capacity"
                        :key="dot"
                        class="size-[5px] rounded-full"
                        :style="{
                            background:
                                dot <= Math.ceil(group.count / unit)
                                    ? group.colour
                                    : 'var(--ui-line-strong)',
                            opacity:
                                dot <= Math.ceil(group.count / unit) ? 1 : 0.45,
                        }"
                    />
                </div>
            </div>
        </dl>

        <div class="flex flex-col gap-1 text-xs/5 text-[var(--ui-ink-3)]">
            <p v-if="summary.total === 0">
                Ainda não há documentos de venda neste mês.
            </p>
            <p v-else class="hidden sm:block">
                {{
                    unit === 1
                        ? 'Cada ponto colorido corresponde a um documento.'
                        : `Cada ponto colorido representa até ${number(unit)} documentos.`
                }}
            </p>
            <p>
                Sem saldo inclui pagamentos, compensações e documentos sem
                cobrança. Saldos actuais na moeda da empresa.
            </p>
        </div>
    </section>
</template>
