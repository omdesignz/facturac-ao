<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { buttonGold, buttonOutline } from '@/lib/pos-ui';
import { decisionsFor } from '@/lib/today-decisions';
import type { Decision, DecisionInput } from '@/lib/today-decisions';
import { index as agtSubmissions } from '@/routes/agt/submissions';
import { index as stockIndex } from '@/routes/stock';

const props = defineProps<
    DecisionInput & {
        currencyLabel: string;
        formatAmount: (minor: number) => string;
    }
>();

const emit = defineEmits<{
    remind: [customer: { public_id: string; name: string }];
}>();

/**
 * What needs deciding today, one verb per card. On a phone this is the page:
 * the long review beside the focus document is for a desk.
 */
const decisions = computed<Decision[]>(() =>
    decisionsFor(props, {
        fiscal: agtSubmissions.url(),
        stock: stockIndex.url({ query: { low: '1' } }),
    }),
);
</script>

<template>
    <section v-if="decisions.length > 0" aria-label="Para decidir hoje">
        <ul role="list" class="grid gap-3">
            <li
                v-for="decision in decisions"
                :key="decision.key"
                class="rounded-3xl surface p-5"
            >
                <p class="eyebrow text-zinc-500 dark:text-zinc-400">
                    {{ decision.kicker }}
                </p>
                <p
                    class="mt-2 text-base/6 font-medium text-zinc-950 dark:text-white"
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
                    class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                >
                    {{ decision.detail }}
                </p>
                <div class="mt-4">
                    <button
                        v-if="decision.kind === 'remind' && decision.customer"
                        type="button"
                        :class="buttonGold"
                        @click="emit('remind', decision.customer)"
                    >
                        {{ decision.action }}
                    </button>
                    <Link
                        v-else-if="decision.href"
                        :href="decision.href"
                        :class="buttonOutline"
                    >
                        {{ decision.action }}
                    </Link>
                </div>
            </li>
        </ul>
    </section>
</template>
