<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { buttonGold, buttonInk, buttonQuiet } from '@/lib/pos-ui';
import { fetchReminderDraft, toneLabel } from '@/lib/reminder-draft';
import type { ReminderDraft } from '@/lib/reminder-draft';
import type { Decision } from '@/lib/today-decisions';
import { show as showReminder } from '@/routes/debts/reminder';

const props = defineProps<{
    decisions: Decision[];
    currencyLabel: string;
    formatAmount: (minor: number) => string;
}>();

const emit = defineEmits<{
    remind: [customer: { public_id: string; name: string }];
}>();

/**
 * What needs deciding today, one verb per card. "Mais tarde" puts a card away
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
        // Private windows refuse storage; the card is still put away for now.
    }
}

const visible = computed(() =>
    props.decisions.filter((decision) => !later.value.includes(decision.key)),
);

/** The words that would go out, read here so the card shows what "send" means. */
const draft = ref<ReminderDraft | null>(null);

const collection = computed(() =>
    props.decisions.find((decision) => decision.key === 'collection'),
);

onMounted(() => {
    later.value = readLater();
});

/** The collections arrive after the page does, so the words are fetched when they do. */
watch(
    () => collection.value?.customer?.public_id,
    async (publicId) => {
        if (publicId === undefined) {
            return;
        }

        try {
            draft.value = await fetchReminderDraft(showReminder.url(publicId));
        } catch {
            // The card still works; the dialog prepares the words when opened.
        }
    },
    { immediate: true },
);

const kickerClass: Record<Decision['key'], string> = {
    fiscal: 'bg-[#fde7ea] text-[#9a1b30] dark:bg-rose-400/10 dark:text-rose-300',
    collection:
        'bg-[#fef3d6] text-[#7a4a00] dark:bg-accent-400/15 dark:text-accent-300',
    stock: 'bg-zinc-900/[0.06] text-zinc-700 dark:bg-white/10 dark:text-zinc-300',
};
</script>

<template>
    <section
        v-if="decisions.length > 0"
        aria-labelledby="decisions-title"
        class="min-w-0"
    >
        <h2
            id="decisions-title"
            class="flex items-center gap-2.5 text-[1.375rem] font-normal tracking-[-0.02em] text-zinc-950 dark:text-white"
        >
            Precisa de si
            <span
                class="grid h-6 min-w-6 place-items-center rounded-full bg-zinc-900/[0.07] px-1.5 text-xs font-semibold text-zinc-700 dark:bg-white/10 dark:text-zinc-200"
                >{{ visible.length }}</span
            >
        </h2>

        <p
            v-if="visible.length === 0"
            class="mt-4 rounded-3xl surface p-6 text-sm/6 text-zinc-500 dark:text-zinc-400"
        >
            Nada mais para decidir hoje. O que pôs de lado volta amanhã.
        </p>

        <ul v-else role="list" class="mt-4 grid gap-3">
            <li
                v-for="decision in visible"
                :key="decision.key"
                class="rounded-3xl surface p-5 sm:p-6"
            >
                <div class="flex flex-col gap-4 md:flex-row md:justify-between">
                    <div class="min-w-0">
                        <span
                            class="inline-flex h-6 items-center rounded-full px-2.5 text-xs font-semibold"
                            :class="kickerClass[decision.key]"
                            >{{ decision.kicker }}</span
                        >
                        <p
                            class="mt-3 text-lg/7 font-medium tracking-[-0.01em] text-zinc-950 dark:text-white"
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
                            class="mt-1 max-w-xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            {{ decision.detail }}
                        </p>
                    </div>

                    <div
                        class="flex shrink-0 items-center gap-1.5 md:self-start"
                    >
                        <button
                            v-if="
                                decision.kind === 'remind' && decision.customer
                            "
                            type="button"
                            :class="buttonGold"
                            @click="emit('remind', decision.customer)"
                        >
                            {{ decision.action }}
                        </button>
                        <Link
                            v-else-if="decision.href"
                            :href="decision.href"
                            :class="buttonInk"
                        >
                            {{ decision.action }}
                        </Link>

                        <Link
                            v-if="decision.secondary.href"
                            :href="decision.secondary.href"
                            :class="buttonQuiet"
                        >
                            {{ decision.secondary.label }}
                        </Link>
                        <button
                            v-else
                            type="button"
                            :class="buttonQuiet"
                            @click="putAway(decision)"
                        >
                            {{ decision.secondary.label }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="
                        decision.key === 'collection' &&
                        draft !== null &&
                        draft.body !== ''
                    "
                    class="mt-5 rounded-2xl bg-[#f5f5f7] p-4 dark:bg-white/[0.05]"
                >
                    <p
                        class="flex flex-wrap items-center gap-x-1.5 text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        <Sparkles class="size-3" aria-hidden="true" />
                        Lembrete preparado · {{ toneLabel(draft.tone) }}
                        <template v-if="draft.to"
                            >· para {{ draft.to }}</template
                        >
                    </p>
                    <p
                        class="mt-2 line-clamp-4 text-sm/6 whitespace-pre-line text-zinc-800 dark:text-zinc-200"
                    >
                        {{ draft.body }}
                    </p>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        Nada é enviado até confirmar.
                    </p>
                </div>
            </li>
        </ul>
    </section>
</template>
