<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { ref } from 'vue';

/**
 * The search box in a title bar: 30px, a magnifier, and a clear button once
 * something is typed. The page owns the query and decides when to search.
 */
defineProps<{
    /** Read by screen readers; the placeholder is only a hint. */
    label: string;
    placeholder?: string;
}>();

const query = defineModel<string>({ required: true });
const input = ref<HTMLInputElement | null>(null);

function clear(): void {
    query.value = '';
    input.value?.focus();
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <label
        class="relative flex h-9 items-center gap-2 rounded-lg bg-[var(--surface-raised)] ps-2.5 pe-1 text-[var(--ui-ink-3)] shadow-[var(--ui-control-shadow)] focus-within:shadow-[0_0_0_1px_var(--color-accent-400),0_0_0_4px_var(--ui-halo)] max-md:w-full md:w-64 pointer-coarse:h-11"
    >
        <span class="sr-only">{{ label }}</span>
        <Search class="size-3.5 shrink-0" aria-hidden="true" />
        <input
            ref="input"
            v-model="query"
            type="search"
            autocomplete="off"
            spellcheck="false"
            enterkeyhint="search"
            :placeholder="placeholder"
            class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm font-medium text-[var(--ui-ink)] outline-hidden placeholder:text-[var(--ui-ink-3)] [&::-webkit-search-cancel-button]:hidden"
        />
        <button
            v-if="query"
            type="button"
            class="grid size-6 shrink-0 place-items-center rounded-md text-[var(--ui-ink-3)] focus-ring hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)]"
            @click="clear"
        >
            <span class="sr-only">Limpar procura</span>
            <X class="size-3" :stroke-width="2.4" aria-hidden="true" />
        </button>
    </label>
</template>
