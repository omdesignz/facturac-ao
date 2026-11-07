<script setup lang="ts">
import { Mail, PackageMinus, ShieldAlert } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import type { Component } from 'vue';
import AppButton from '@/components/AppButton.vue';
import type { Decision } from '@/lib/today-decisions';

const props = defineProps<{
    decisions: Decision[];
    currencyLabel: string;
    formatAmount: (minor: number) => string;
}>();

const emit = defineEmits<{
    remind: [customer: { public_id: string; name: string }];
}>();

/**
 * What needs deciding today, one verb per row. "Mais tarde" puts a row away
 * until tomorrow on this device; nothing about the document or the debt moves.
 */
const storageKey = `facturac:hoje-later:${new Date().toISOString().slice(0, 10)}`;
const later = ref<string[]>([]);

function readLater(): string[] {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(storageKey) ?? '[]',
        );

        return Array.isArray(stored)
            ? stored.filter((key): key is string => typeof key === 'string')
            : [];
    } catch {
        return [];
    }
}

function putAway(decision: Decision): void {
    later.value = [...later.value, decision.key];

    try {
        window.localStorage.setItem(storageKey, JSON.stringify(later.value));
    } catch {
        // Private windows refuse storage; the row is still put away for now.
    }
}

const visible = computed(() =>
    props.decisions.filter((decision) => !later.value.includes(decision.key)),
);

onMounted(() => {
    later.value = readLater();
});

/** Each kind of decision keeps its own mark, so the eye sorts them before reading. */
const marks: Record<Decision['key'], { icon: Component; tile: string }> = {
    fiscal: {
        icon: ShieldAlert,
        tile: 'bg-[var(--ui-critical-wash)] text-[var(--ui-critical)]',
    },
    collection: {
        icon: Mail,
        tile: 'bg-[var(--ui-gold-wash)] text-[var(--ui-gold-ink)]',
    },
    stock: {
        icon: PackageMinus,
        tile: 'bg-[var(--ui-well)] text-[var(--ui-ink-2)]',
    },
};
</script>

<template>
    <section
        aria-labelledby="decisions-title"
        class="flex min-w-0 flex-col self-start rounded-xl bg-[var(--surface-raised)] shadow-[var(--ui-card-shadow)]"
    >
        <div class="flex items-center gap-2 px-[1.125rem] pt-4 pb-2.5">
            <h2
                id="decisions-title"
                class="flex-1 text-sm font-semibold text-[var(--ui-ink)]"
            >
                Precisa de si
            </h2>
            <span
                v-if="visible.length > 0"
                class="grid h-5 min-w-5 place-items-center rounded-full bg-[var(--ui-gold-wash)] px-1.5 text-[0.71875rem] font-semibold text-[var(--ui-gold-ink)]"
                >{{ visible.length }}</span
            >
        </div>

        <p
            v-if="visible.length === 0"
            class="border-t border-[var(--ui-line)] px-[1.125rem] py-5 text-[0.8125rem]/5 text-[var(--ui-ink-3)]"
        >
            Nada para decidir hoje. O que pôs de lado volta amanhã.
        </p>

        <ul v-else role="list">
            <li
                v-for="(decision, index) in visible"
                :key="decision.key"
                class="flex gap-3 border-t border-[var(--ui-line)] px-[1.125rem] py-3"
            >
                <span
                    :class="[
                        'grid size-[1.875rem] shrink-0 place-items-center rounded-lg',
                        marks[decision.key].tile,
                    ]"
                    aria-hidden="true"
                >
                    <component
                        :is="marks[decision.key].icon"
                        class="size-[0.9375rem]"
                        :stroke-width="1.9"
                    />
                </span>
                <div class="min-w-0 flex-1">
                    <p
                        class="text-[0.8125rem] font-semibold text-[var(--ui-ink)]"
                    >
                        {{ decision.headline }}
                        <span
                            v-if="decision.amountMinor !== undefined"
                            class="numeric"
                            >{{ formatAmount(decision.amountMinor) }}
                            {{ currencyLabel }}</span
                        >
                    </p>
                    <p
                        v-if="decision.detail"
                        class="mt-0.5 text-xs/[1.45] text-[var(--ui-ink-2)] first-letter:uppercase"
                    >
                        {{ decision.detail }}
                    </p>
                    <div class="mt-2.5 flex items-center gap-1.5">
                        <AppButton
                            v-if="
                                decision.kind === 'remind' && decision.customer
                            "
                            :variant="index === 0 ? 'gold' : 'outline'"
                            size="xs"
                            @click="emit('remind', decision.customer)"
                        >
                            {{ decision.action }}
                        </AppButton>
                        <AppButton
                            v-else-if="decision.href"
                            :variant="index === 0 ? 'gold' : 'outline'"
                            size="xs"
                            :href="decision.href"
                        >
                            {{ decision.action }}
                        </AppButton>

                        <AppButton
                            v-if="decision.secondary.href"
                            variant="quiet"
                            size="xs"
                            class="px-2"
                            :href="decision.secondary.href"
                        >
                            {{ decision.secondary.label }}
                        </AppButton>
                        <AppButton
                            v-else
                            variant="quiet"
                            size="xs"
                            class="px-2"
                            @click="putAway(decision)"
                        >
                            {{ decision.secondary.label }}
                        </AppButton>
                    </div>
                </div>
            </li>
        </ul>
    </section>
</template>
