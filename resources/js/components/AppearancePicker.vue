<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/lib/appearance';

const { appearance, changeAppearance } = useAppearance();
const options = [
    { value: 'light' as const, label: 'Claro', icon: Sun },
    { value: 'dark' as const, label: 'Escuro', icon: Moon },
    { value: 'system' as const, label: 'Sistema', icon: Monitor },
];
</script>

<template>
    <div role="group" aria-label="Aparência" class="grid grid-cols-3 gap-2">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            :aria-pressed="appearance === option.value"
            :class="[
                'flex min-h-20 flex-col items-center justify-center gap-2 rounded-lg border p-3 text-sm font-medium focus-ring',
                appearance === option.value
                    ? 'border-accent-400 bg-[var(--ui-select)] text-[var(--ui-ink)]'
                    : 'border-[var(--ui-line-strong)] text-[var(--ui-ink-2)] hover:bg-[var(--ui-wash)]',
            ]"
            @click="changeAppearance(option.value)"
        >
            <component :is="option.icon" class="size-5" aria-hidden="true" />
            {{ option.label }}
        </button>
    </div>
</template>
