<script setup lang="ts">
import { Table2 } from '@lucide/vue';
import { ref } from 'vue';

/**
 * The shell every chart sits in: title, legend, and the table view.
 *
 * The table is not a fallback — it is the readable-another-way relief that the
 * light-mode contrast check requires, and the answer to "a tooltip as the only
 * way to read a value".
 */
defineProps<{
    title: string;
    subtitle?: string;
    /** Column headers for the table view. */
    columns: string[];
    /** One row of already-formatted strings per data point. */
    rows: string[][];
    empty?: boolean;
    emptyMessage?: string;
}>();

const showTable = ref(false);
</script>

<template>
    <section class="viz overflow-clip rounded-2xl surface">
        <header
            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3 px-5 pt-5"
        >
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-[var(--ui-ink)]">
                    {{ title }}
                </h2>
                <p
                    v-if="subtitle"
                    class="mt-0.5 text-[0.8125rem]/5 text-[var(--ui-ink-3)]"
                >
                    {{ subtitle }}
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-3">
                <slot name="legend" />

                <button
                    v-if="!empty"
                    type="button"
                    :aria-pressed="showTable"
                    class="icon-button text-[var(--ui-ink-3)] focus-ring hover:bg-[var(--ui-well)] hover:text-[var(--ui-ink)] aria-pressed:bg-[var(--ui-well)] aria-pressed:text-[var(--ui-ink)]"
                    :title="showTable ? 'Ver gráfico' : 'Ver tabela'"
                    @click="showTable = !showTable"
                >
                    <span class="sr-only">{{
                        showTable ? 'Ver gráfico' : 'Ver tabela'
                    }}</span>
                    <Table2 class="size-4" aria-hidden="true" />
                </button>
            </div>
        </header>

        <div
            v-if="empty"
            class="mx-5 my-5 rounded-lg bg-[var(--ui-wash)] px-5 py-12 text-center text-sm/6 text-[var(--ui-ink-3)]"
        >
            {{ emptyMessage ?? 'Sem dados para este período.' }}
        </div>

        <div
            v-else-if="showTable"
            class="mt-4 overflow-x-auto border-t border-[var(--ui-line)]"
        >
            <table
                class="min-w-full text-left text-sm"
                :aria-label="`${title}, em tabela`"
            >
                <thead class="border-b border-[var(--ui-line)]">
                    <tr>
                        <th
                            v-for="(column, index) in columns"
                            :key="column"
                            scope="col"
                            :class="[
                                index === 0 ? 'text-left' : 'text-right',
                                'px-5 py-3 eyebrow text-[var(--ui-ink-3)]',
                            ]"
                        >
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--ui-line)]">
                    <tr v-for="(row, rowIndex) in rows" :key="rowIndex">
                        <td
                            v-for="(cell, cellIndex) in row"
                            :key="cellIndex"
                            :class="[
                                cellIndex === 0
                                    ? 'text-left text-[var(--ui-ink-2)]'
                                    : 'text-right numeric text-[var(--ui-ink)]',
                                'px-5 py-2.5',
                            ]"
                        >
                            {{ cell }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="px-5 pt-5 pb-4">
            <slot />
        </div>
    </section>
</template>
