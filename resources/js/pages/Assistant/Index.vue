<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import AssistantResultCard from '@/components/AssistantResultCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { acceptsResponse, assistantReason, contextKey } from '@/lib/assistant';
import type { AssistantContext, AssistantResponse } from '@/lib/assistant';
import { acknowledgement, store } from '@/routes/assistant';

type ProviderNotice = {
    policy: string;
    notice_url: string | null;
    available: boolean;
    acknowledged: boolean;
};
const props = defineProps<{
    context: AssistantContext;
    provider?: ProviderNotice;
}>();
const unavailableNotice = (): ProviderNotice => ({
    policy: '',
    notice_url: null,
    available: false,
    acknowledged: false,
});
const providerState = ref<ProviderNotice>(
    props.provider ?? unavailableNotice(),
);
const acknowledgementHttp = useHttp<
    { policy: string; acknowledged: boolean },
    { context: AssistantContext; provider: ProviderNotice }
>({ policy: '', acknowledged: false });
const answer = ref<AssistantResponse | null>(null);
const error = ref('');
const referenceKind = ref<'customer' | 'document'>('document');
const referenceId = ref('');
const http = useHttp<
    {
        request_nonce: string;
        question: string;
        references: { kind: string; public_id: string }[];
    },
    AssistantResponse
>({ request_nonce: '', question: '', references: [] });
let generation = 0;
function clear(): void {
    generation++;
    http.cancel();
    acknowledgementHttp.cancel();
    acknowledgementHttp.reset();
    acknowledgementHttp.response = null;
    http.reset();
    http.response = null;
    referenceId.value = '';
    answer.value = null;
    error.value = '';
}
watch(
    () => [contextKey(props.context), props.provider],
    () => {
        clear();
        providerState.value = props.provider ?? unavailableNotice();
    },
);
const removeNavigationListener = router.on('before', clear);
onBeforeUnmount(() => {
    clear();
    removeNavigationListener();
});
async function setAcknowledgement(acknowledged: boolean): Promise<void> {
    if (acknowledgementHttp.processing) {
        return;
    }

    clear();
    const submitted = ++generation;
    const expected = { ...props.context };
    acknowledgementHttp.policy = providerState.value.policy;
    acknowledgementHttp.acknowledged = acknowledged;

    try {
        const response = await acknowledgementHttp.post(
            acknowledgement.url({
                workspacePublicId: expected.workspace_public_id,
                entityPublicId: expected.legal_entity_public_id,
                environment: expected.environment,
            }),
        );

        if (
            acceptsResponse(
                props.context,
                response.context,
                generation,
                submitted,
            )
        ) {
            providerState.value = response.provider;
        }
    } catch {
        if (generation === submitted) {
            error.value =
                'Não foi possível actualizar a confirmação. Tente novamente.';
        }
    }
}
async function submit(): Promise<void> {
    if (
        http.processing ||
        acknowledgementHttp.processing ||
        !providerState.value.available ||
        !providerState.value.acknowledged
    ) {
        return;
    }

    answer.value = null;
    error.value = '';
    const submitted = ++generation;
    const expected = { ...props.context };
    http.request_nonce = crypto.randomUUID();
    http.references = referenceId.value.trim()
        ? [{ kind: referenceKind.value, public_id: referenceId.value.trim() }]
        : [];

    try {
        const response = await http.post(
            store.url({
                workspacePublicId: expected.workspace_public_id,
                entityPublicId: expected.legal_entity_public_id,
                environment: expected.environment,
            }),
        );

        if (
            acceptsResponse(
                props.context,
                response.context,
                generation,
                submitted,
            )
        ) {
            answer.value = response;
        }
    } catch {
        if (generation === submitted) {
            error.value =
                'Não foi possível concluir o pedido. Utilize a pesquisa e as páginas habituais da aplicação.';
        }
    }
}
</script>
<template>
    <AppLayout>
        <Head title="Assistente de leitura" />
        <main class="mx-auto w-full max-w-3xl space-y-5 p-6">
            <h1 class="text-xl font-semibold">Assistente de leitura</h1>
            <p
                v-if="!providerState.available"
                class="text-muted-foreground text-sm"
            >
                O processamento externo de perguntas está indisponível. Utilize
                a pesquisa e as páginas habituais da aplicação. Não inclua dados
                pessoais ou segredos nas perguntas.
            </p>
            <section
                v-if="providerState.available"
                aria-label="Processamento externo de perguntas"
                class="space-y-3 text-sm"
            >
                <p>
                    A Facturac utiliza a API comercial da Anthropic para
                    interpretar a pergunta que escrever. Enviamos o texto após
                    filtragem local limitada, referências opacas seleccionadas,
                    o mês e as operações de leitura disponíveis. Os nomes de
                    clientes que escrever podem ser enviados. Não enviamos
                    registos de clientes, facturas, evidência AGT ou os
                    resultados da resposta. As respostas são construídas
                    localmente a partir de registos autorizados. A IA pode
                    escolher a operação errada; verifique o contexto e a fonte
                    apresentados.
                </p>
                <p>
                    Não cole números fiscais ou de documentos, valores,
                    contactos, moradas, palavras-passe, chaves ou informação
                    pessoal sensível. A filtragem não detecta tudo. O
                    processamento utiliza a configuração aprovada nos Estados
                    Unidos, com as excepções, conservação e transferências
                    descritas na política. Pode utilizar a aplicação normal sem
                    processamento externo de perguntas.
                </p>
                <a
                    v-if="providerState.notice_url"
                    :href="providerState.notice_url"
                    class="underline"
                    >Política de privacidade — {{ providerState.policy }}</a
                >
                <button
                    v-if="!providerState.acknowledged"
                    type="button"
                    :disabled="acknowledgementHttp.processing"
                    class="block rounded border px-3 py-2"
                    @click="setAcknowledgement(true)"
                >
                    Compreendo como funciona o processamento externo de
                    perguntas
                </button>
            </section>
            <button
                v-if="providerState.acknowledged"
                type="button"
                :disabled="acknowledgementHttp.processing"
                class="rounded border px-3 py-2 text-sm"
                @click="setAcknowledgement(false)"
            >
                Retirar a confirmação e impedir novos envios
            </button>
            <p class="text-xs break-all">
                Empresa: {{ context.workspace_public_id }} · Entidade:
                {{ context.legal_entity_public_id }} · {{ context.environment }}
            </p>
            <form class="space-y-3" @submit.prevent="submit">
                <label class="block text-sm" for="assistant-question"
                    >Pergunta</label
                >
                <textarea
                    id="assistant-question"
                    v-model="http.question"
                    maxlength="2000"
                    required
                    rows="3"
                    class="border-border bg-background w-full rounded-lg border p-3"
                />
                <label class="block text-sm" for="assistant-kind"
                    >Referência explícita opcional</label
                >
                <select
                    id="assistant-kind"
                    v-model="referenceKind"
                    class="border-border bg-background rounded-lg border p-2"
                >
                    <option value="document">Documento</option>
                    <option value="customer">Cliente</option>
                </select>
                <input
                    v-model="referenceId"
                    aria-label="Identificador público seleccionado"
                    maxlength="26"
                    class="border-border bg-background w-full rounded-lg border p-2"
                />
                <button
                    type="submit"
                    :disabled="
                        http.processing ||
                        acknowledgementHttp.processing ||
                        !providerState.available ||
                        !providerState.acknowledged
                    "
                    class="bg-primary text-primary-foreground rounded-lg px-4 py-2 disabled:opacity-50"
                >
                    {{ http.processing ? 'A consultar…' : 'Consultar' }}
                </button>
            </form>
            <p v-if="error" role="alert" class="text-sm">{{ error }}</p>
            <section v-if="answer" aria-live="polite" class="space-y-3">
                <p v-if="answer.outcome !== 'answered'">
                    {{ assistantReason(answer.reason) }}
                </p>
                <AssistantResultCard
                    v-for="result in answer.results"
                    :key="result.result_id"
                    :result="result"
                    :context="answer.context"
                />
                <p class="text-muted-foreground text-xs">
                    Cada fonte representa uma consulta própria. Pedido:
                    {{ answer.request_id }}
                </p>
            </section>
        </main>
    </AppLayout>
</template>
