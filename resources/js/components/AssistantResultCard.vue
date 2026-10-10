<script setup lang="ts">
import { assistantLines } from '@/lib/assistant';
import type { AssistantContext, AssistantResult } from '@/lib/assistant';
defineProps<{ result: AssistantResult; context: AssistantContext }>();
</script>
<template>
    <article class="border-border bg-card rounded-xl border p-5">
        <h2 class="font-semibold">Factos consultados</h2>
        <ul class="mt-3 space-y-2 text-sm">
            <li
                v-for="(line, index) in assistantLines(result)"
                :key="index"
                class="break-words whitespace-pre-wrap [unicode-bidi:plaintext]"
            >
                {{ line }}
            </li>
        </ul>
        <p class="text-muted-foreground mt-4 text-xs break-all">
            Fonte: {{ result.tool }} · {{ result.result_id }} ·
            {{ result.read_at }}
        </p>
        <p class="text-muted-foreground text-xs break-all">
            {{ context.workspace_public_id }} /
            {{ context.legal_entity_public_id }} / {{ context.environment }}
        </p>
    </article>
</template>
