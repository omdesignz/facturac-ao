<script setup lang="ts">
import { computed } from 'vue';
import { assistantHeadline, assistantLines } from '@/lib/assistant';
import type { AssistantContext, AssistantResult } from '@/lib/assistant';
/**
 * `context` is still passed by the page, which shows it once under
 * "Detalhes técnicos" instead of repeating it on every card.
 */
const props = defineProps<{
    result: AssistantResult;
    context: AssistantContext;
}>();
const lines = computed(() => assistantLines(props.result));
const headline = computed(() => assistantHeadline(props.result));
</script>
<template>
    <article class="rounded-2xl bg-zinc-900/[0.04] p-5 dark:bg-white/[0.05]">
        <h2 class="font-semibold text-zinc-950 dark:text-white">
            Factos consultados
        </h2>
        <template v-if="headline.length > 0">
            <div class="mt-3 space-y-2">
                <p
                    v-for="(line, index) in headline"
                    :key="index"
                    class="text-base break-words whitespace-pre-wrap text-zinc-950 [unicode-bidi:plaintext] dark:text-white"
                >
                    {{ line }}
                </p>
            </div>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                {{ lines[1] }}
            </p>
            <details class="mt-4 text-sm">
                <summary
                    class="cursor-pointer rounded text-zinc-600 focus-ring dark:text-zinc-400"
                >
                    Ver detalhe
                </summary>
                <ul class="mt-3 space-y-2 text-sm">
                    <li
                        v-for="(line, index) in lines"
                        :key="index"
                        class="break-words whitespace-pre-wrap [unicode-bidi:plaintext]"
                    >
                        {{ line }}
                    </li>
                </ul>
            </details>
        </template>
        <ul v-else class="mt-3 space-y-2 text-sm">
            <li
                v-for="(line, index) in lines"
                :key="index"
                class="break-words whitespace-pre-wrap [unicode-bidi:plaintext]"
            >
                {{ line }}
            </li>
        </ul>
        <p class="mt-4 text-xs break-all text-zinc-500 dark:text-zinc-400">
            Fonte: {{ result.tool }} · {{ result.result_id }} ·
            {{ result.read_at }}
        </p>
    </article>
</template>
