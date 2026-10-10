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
            class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 p-5 dark:border-white/10"
        >
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-zinc-950 dark:text-white">
                    {{ title }}
                </h2>
                <p
                    v-if="subtitle"
                    class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
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
                    class="icon-button text-zinc-400 focus-ring hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/5 dark:hover:text-white"
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
            class="px-5 py-14 text-center text-sm/6 text-zinc-500 dark:text-zinc-400"
        >
            {{ emptyMessage ?? 'Sem dados para este período.' }}
        </div>

        <div v-else-if="showTable" class="overflow-x-auto">
            <table
                class="min-w-full text-left text-sm"
                :aria-label="`${title}, em tabela`"
            >
                <thead class="border-b border-zinc-100 dark:border-white/10">
                    <tr>
                        <th
                            v-for="(column, index) in columns"
                            :key="column"
                            scope="col"
                            :class="[
                                index === 0 ? 'text-left' : 'text-right',
                                'px-5 py-3 eyebrow text-zinc-500',
                            ]"
                        >
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/10">
                    <tr v-for="(row, rowIndex) in rows" :key="rowIndex">
                        <td
                            v-for="(cell, cellIndex) in row"
                            :key="cellIndex"
                            :class="[
                                cellIndex === 0
                                    ? 'text-left text-zinc-700 dark:text-zinc-300'
                                    : 'text-right numeric text-zinc-950 dark:text-white',
                                'px-5 py-2.5',
                            ]"
                        >
                            {{ cell }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="p-5">
            <slot />
        </div>
    </section>
</template>
