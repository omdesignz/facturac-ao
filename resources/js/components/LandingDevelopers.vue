<script setup lang="ts">
import { ArrowRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import { demoCommand, developerExamples } from '@/lib/developer-examples';

const props = defineProps<{
    supportEmail: string | null;
    integrationPackages: string[];
}>();

const enquiryUrl = computed(() =>
    props.supportEmail
        ? `mailto:${props.supportEmail}?subject=${encodeURIComponent('API e integrações — facturac.ao')}`
        : null,
);
const selectedExample = ref(0);
const example = computed(() => developerExamples[selectedExample.value]);
const copyMessage = ref('');

async function copyCode(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copyMessage.value = 'Copiado.';
    } catch {
        copyMessage.value = 'Seleccione o código e copie manualmente.';
    }
}

const capabilities = [
    {
        title: 'Consultar e sincronizar',
        detail: 'Documentos, clientes, catálogo e resumos de facturação para os seus sistemas.',
    },
    {
        title: 'Criar clientes e serviços',
        detail: 'Com permissões específicas e protecção contra pedidos repetidos.',
    },
    {
        title: 'Experimentar primeiro',
        detail: 'Uma demonstração local exporta dois documentos fictícios para CSV, sem conta nem chave.',
    },
];
</script>

<template>
    <section
        id="integracoes"
        aria-labelledby="integracoes-heading"
        class="mx-auto max-w-6xl scroll-mt-24 px-5 pt-(--space-section) sm:px-8"
    >
        <div
            class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-16"
        >
            <div>
                <p
                    class="text-sm font-semibold tracking-wide text-brand-500 uppercase dark:text-zinc-400"
                >
                    API & SDK
                </p>
                <h2
                    id="integracoes-heading"
                    class="mt-4 font-display text-section font-bold text-balance"
                >
                    O seu software.<br />Ligado ao facturac.
                </h2>
                <p
                    class="mt-5 max-w-xl text-lg/8 text-brand-600 dark:text-zinc-400"
                >
                    Ligue o ERP, o CRM ou as ferramentas de contabilidade à sua
                    empresa. O SDK oficial para JavaScript e TypeScript ajuda a
                    começar.
                </p>
                <div class="mt-7 flex flex-wrap gap-3">
                    <AppButton
                        href="https://github.com/omdesignz/facturac-sdk#try-an-integration-in-five-minutes"
                        external
                        variant="ink"
                        size="lg"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Guia de integração
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </AppButton>
                    <AppButton
                        href="https://www.npmjs.com/package/@vapsolucoes.ao/facturac"
                        external
                        variant="outline"
                        size="lg"
                        target="_blank"
                        rel="noopener noreferrer"
                        >Ver no npm</AppButton
                    >
                </div>
            </div>
            <div
                class="min-w-0 rounded-[2rem] bg-brand-950 p-6 text-white sm:p-8 dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
            >
                <div
                    class="flex flex-wrap gap-2"
                    aria-label="Escolher exemplo de integração"
                >
                    <button
                        v-for="(item, index) in developerExamples"
                        :key="item.id"
                        type="button"
                        :aria-pressed="selectedExample === index"
                        aria-controls="sdk-example"
                        class="rounded-full px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-lime-300"
                        :class="
                            selectedExample === index
                                ? 'bg-white text-brand-950'
                                : 'text-zinc-300 hover:bg-white/10'
                        "
                        @click="
                            selectedExample = index;
                            copyMessage = '';
                        "
                    >
                        {{ item.label }}
                    </button>
                </div>
                <div id="sdk-example" class="mt-6">
                    <p class="text-sm/6 text-zinc-300">
                        {{ example.description }}
                    </p>
                    <div
                        class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-zinc-400"
                    >
                        <span>TypeScript · no servidor</span>
                        <button
                            type="button"
                            class="rounded px-2 py-1 text-lime-200 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-lime-300"
                            @click="copyCode(example.code)"
                        >
                            Copiar código
                        </button>
                    </div>
                    <pre
                        tabindex="0"
                        aria-label="Código TypeScript do exemplo"
                        class="mt-3 max-h-96 overflow-auto rounded-xl bg-black/20 p-4 font-mono text-xs/6 text-zinc-100"
                    ><code>{{ example.code }}</code></pre>
                    <p class="mt-4 font-mono text-xs text-lime-200">
                        Permissão: {{ example.scope }}
                    </p>
                    <p class="mt-3 text-xs/6 text-zinc-400">
                        {{ example.note }}
                    </p>
                </div>
                <p role="status" class="mt-2 text-xs text-lime-200">
                    {{ copyMessage }}
                </p>
            </div>
        </div>
        <div
            id="sandbox"
            class="mt-10 grid gap-8 rounded-3xl border border-brand-950/10 p-6 sm:p-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] dark:border-white/10"
        >
            <div>
                <p
                    class="text-xs font-semibold tracking-wide text-brand-500 uppercase dark:text-zinc-400"
                >
                    Sandbox local · sem conta nem chave
                </p>
                <h3 class="mt-3 font-display text-2xl font-semibold">
                    Do terminal ao primeiro CSV.
                </h3>
                <p class="mt-3 text-sm/7 text-brand-600 dark:text-zinc-400">
                    Experimente o SDK com dois documentos fictícios e paginação
                    real. O npm descarrega o pacote; depois, a demonstração
                    funciona localmente, sem contactar a sua empresa nem a AGT.
                </p>
                <p class="mt-4 text-xs/6 text-brand-500 dark:text-zinc-400">
                    Node.js 20 ou superior. Escolha um nome de ficheiro novo em
                    cada execução; o exportador não substitui ficheiros
                    existentes.
                </p>
                <p class="mt-4 text-xs/6 text-brand-500 dark:text-zinc-400">
                    Este sandbox cobre a leitura de documentos. Não simula
                    autenticação, limites de pedidos ou escritas. Um ambiente de
                    homologação alojado requer uma instalação e credenciais
                    próprias.
                </p>
            </div>
            <div class="min-w-0">
                <div
                    class="flex items-center justify-between gap-3 text-xs font-medium"
                >
                    <span>1. Execute no terminal</span>
                    <button
                        type="button"
                        class="rounded px-2 py-1 underline underline-offset-4 focus-visible:outline-2"
                        @click="copyCode(demoCommand)"
                    >
                        Copiar comando
                    </button>
                </div>
                <pre
                    tabindex="0"
                    aria-label="Comando da demonstração local"
                    class="mt-3 overflow-x-auto rounded-xl bg-brand-950 p-4 font-mono text-xs/6 break-all whitespace-pre-wrap text-white dark:bg-zinc-900"
                ><code>{{ demoCommand }}</code></pre>
                <p class="mt-4 text-xs font-medium">
                    2. Abra facturac-demo.csv
                </p>
                <div
                    class="mt-3 overflow-x-auto rounded-xl border border-brand-950/10 dark:border-white/10"
                >
                    <table class="w-full text-left text-xs">
                        <caption class="sr-only">
                            Pré-visualização dos dois documentos fictícios
                            exportados. Valores em unidades mínimas.
                        </caption>
                        <thead class="bg-brand-50 dark:bg-white/5">
                            <tr>
                                <th class="px-3 py-2">Documento</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2">gross_total_minor</th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-brand-950/10 dark:divide-white/10"
                        >
                            <tr>
                                <td class="px-3 py-3">FT DEMO/3</td>
                                <td class="px-3 py-3">issued</td>
                                <td class="px-3 py-3 font-mono">150000 AOA</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-3">Sem número</td>
                                <td class="px-3 py-3">draft</td>
                                <td class="px-3 py-3 font-mono">50000 AOA</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs/6 text-brand-500 dark:text-zinc-400">
                    Dados fictícios. O CSV inclui identificadores públicos,
                    revisão e ambiente para reconciliação.
                </p>
            </div>
        </div>
        <div class="mt-8 grid gap-5 text-sm/6 sm:grid-cols-3">
            <p>
                <strong class="block">1. Instale o SDK</strong
                ><code class="mt-2 block text-xs break-all"
                    >npm install @vapsolucoes.ao/facturac</code
                >
            </p>
            <p>
                <strong class="block">2. Peça acesso à API</strong
                ><span class="text-brand-600 dark:text-zinc-400"
                    >Confirme a activação e crie a credencial em Definições →
                    Integrações e API.</span
                >
            </p>
            <p>
                <strong class="block">3. Ligue o seu servidor</strong
                ><span class="text-brand-600 dark:text-zinc-400"
                    >Guarde a credencial em variáveis de ambiente. Nunca a
                    inclua no navegador, na aplicação móvel ou no
                    repositório.</span
                >
            </p>
        </div>
        <dl
            class="mt-10 grid gap-6 border-t border-brand-950/10 pt-7 md:grid-cols-3 dark:border-white/10"
        >
            <div v-for="capability in capabilities" :key="capability.title">
                <dt class="font-semibold">{{ capability.title }}</dt>
                <dd class="mt-2 text-sm/6 text-brand-600 dark:text-zinc-400">
                    {{ capability.detail }}
                </dd>
            </div>
        </dl>
        <div
            class="mt-10 grid gap-7 rounded-3xl bg-brand-50 p-7 sm:p-9 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center dark:bg-white/5"
        >
            <div class="max-w-2xl">
                <h3
                    class="font-display text-2xl font-semibold tracking-[-0.02em]"
                >
                    API & Integrações para a sua equipa
                </h3>
                <p
                    v-if="integrationPackages.length"
                    class="mt-3 text-brand-700 dark:text-zinc-300"
                >
                    Acesso à API incluído:
                    <strong class="font-semibold">{{
                        integrationPackages.join(', ')
                    }}</strong
                    >.
                </p>
                <p v-else class="mt-3 text-brand-700 dark:text-zinc-300">
                    O acesso à API requer um pacote com integrações externas.
                </p>
                <p class="mt-2 text-sm/6 text-brand-600 dark:text-zinc-400">
                    Precisa de uma integração à medida? Fale com a equipa sobre
                    o seu sistema e confirme a activação antes de ligar dados
                    reais.
                </p>
                <p class="mt-3 text-sm/6 text-brand-600 dark:text-zinc-400">
                    Nesta versão, a API não inclui emissão fiscal, pagamentos ou
                    webhooks. O CSV de demonstração é um registo de documentos,
                    não um livro contabilístico completo.
                </p>
            </div>
            <div
                class="flex flex-wrap items-center gap-4 lg:flex-col lg:items-stretch"
            >
                <AppButton
                    v-if="enquiryUrl"
                    :href="enquiryUrl"
                    external
                    variant="ink"
                    size="lg"
                    >Falar sobre a minha integração</AppButton
                >
                <AppButton href="#precos" external variant="quiet" size="lg"
                    >Consultar planos
                    <ArrowRight class="size-4" aria-hidden="true"
                /></AppButton>
            </div>
        </div>
    </section>
</template>
