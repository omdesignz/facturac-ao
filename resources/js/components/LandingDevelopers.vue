<script setup lang="ts">
import { ArrowRight, Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import {
    demoCommand,
    demoSession,
    developerExamples,
    installCommand,
    sdkVersion,
    tokenize,
} from '@/lib/developer-examples';

/**
 * The homepage's word to developers: what the API does, real code to read,
 * and a demo that runs on their own machine without an account. Every panel
 * is rendered and only hidden, so the code is there for search engines and
 * for anyone reading the page without scripts.
 */
const props = defineProps<{
    supportEmail: string | null;
    integrationPackages: string[];
}>();

const enquiryUrl = computed(() =>
    props.supportEmail
        ? `mailto:${props.supportEmail}?subject=${encodeURIComponent('API e integrações — facturac.ao')}`
        : null,
);

const tabs = [
    ...developerExamples.map((example) => ({
        id: example.id,
        label: example.label,
    })),
    { id: 'sandbox', label: 'Experimentar' },
];
const selected = ref(tabs[0]!.id);
const tabButtons = ref<HTMLButtonElement[]>([]);

const highlighted = developerExamples.map((example) => ({
    ...example,
    tokens: tokenize(example.code),
}));

const copied = ref<string | null>(null);
const copyMessage = ref('');
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

async function copyText(value: string, key: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        copyMessage.value = 'Copiado.';
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => {
            copied.value = null;
            copyMessage.value = '';
        }, 1800);
    } catch {
        copyMessage.value = 'Seleccione o texto e copie manualmente.';
    }
}

const selectedCopy = computed(() =>
    selected.value === 'sandbox'
        ? demoCommand
        : (developerExamples.find((example) => example.id === selected.value)
              ?.code ?? ''),
);

/** Arrow keys move between tabs, as in any tab list. */
function onTabKeydown(event: KeyboardEvent, index: number): void {
    const step =
        event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

    if (step === 0) {
        return;
    }

    event.preventDefault();
    const next = (index + step + tabs.length) % tabs.length;
    selected.value = tabs[next]!.id;
    tabButtons.value[next]?.focus();
}

const capabilities = [
    'Documentos, clientes e catálogo, página a página',
    'Facturas, facturas-recibo e notas de crédito com o SDK 0.3.0',
    'O estado de cada documento na AGT',
    'Clientes e serviços criados sem duplicar quando um pedido se repete',
    'Uma chave por empresa, com as permissões que escolher',
];

const tokenClass = {
    keyword: 'text-accent-300',
    string: 'text-[#d9e7b5]',
    plain: '',
} as const;
</script>

<template>
    <section
        id="integracoes"
        aria-labelledby="integracoes-heading"
        class="mx-auto max-w-6xl scroll-mt-24 px-5 pt-(--space-section) sm:px-8"
    >
        <div
            class="grid gap-10 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)] lg:gap-14"
        >
            <div class="lg:pt-2">
                <h2
                    id="integracoes-heading"
                    class="font-display text-section font-bold text-balance"
                >
                    O seu software, ligado ao facturac.ao
                </h2>
                <p
                    class="mbs-4 text-lg/8 text-pretty text-brand-600 dark:text-zinc-400"
                >
                    Uma API e um SDK para JavaScript e TypeScript, para o ERP, o
                    CRM ou a contabilidade lerem e escreverem na sua empresa.
                </p>
                <ul
                    class="mbs-6 grid gap-2 text-base text-brand-800 dark:text-zinc-200"
                >
                    <li
                        v-for="capability in capabilities"
                        :key="capability"
                        class="flex gap-3"
                    >
                        <span
                            class="mt-[0.6em] size-1.5 shrink-0 rounded-full bg-accent-400"
                            aria-hidden="true"
                        />
                        {{ capability }}
                    </li>
                </ul>
                <div class="mbs-8 flex flex-wrap gap-3">
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
                        >SDK {{ sdkVersion }} no npm</AppButton
                    >
                </div>
            </div>

            <!-- The code, in a window of its own. -->
            <div
                class="min-w-0 overflow-hidden rounded-2xl bg-brand-950 text-zinc-100 shadow-[0_30px_60px_-34px_rgb(23_23_22/0.55)] dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
            >
                <div
                    class="flex items-center gap-2 border-b border-white/10 px-2 py-2 sm:px-3"
                >
                    <div
                        role="tablist"
                        aria-label="Exemplos de integração"
                        class="flex min-w-0 flex-1 [scrollbar-width:none] gap-1 overflow-x-auto"
                    >
                        <button
                            v-for="(tab, index) in tabs"
                            :id="`sdk-tab-${tab.id}`"
                            ref="tabButtons"
                            :key="tab.id"
                            type="button"
                            role="tab"
                            :aria-selected="selected === tab.id"
                            :aria-controls="`sdk-panel-${tab.id}`"
                            :tabindex="selected === tab.id ? 0 : -1"
                            :class="[
                                'shrink-0 rounded-lg px-2.5 py-1.5 text-[0.8125rem] font-medium whitespace-nowrap focus-ring-inverted transition-colors sm:px-3',
                                selected === tab.id
                                    ? 'bg-white/10 text-white'
                                    : 'text-zinc-400 hover:text-white',
                            ]"
                            @click="selected = tab.id"
                            @keydown="onTabKeydown($event, index)"
                        >
                            {{ tab.label }}
                        </button>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-xs font-medium text-zinc-300 focus-ring-inverted transition-colors hover:bg-white/10 hover:text-white"
                        :aria-label="
                            selected === 'sandbox'
                                ? 'Copiar comandos'
                                : 'Copiar código'
                        "
                        @click="copyText(selectedCopy, selected)"
                    >
                        <Check
                            v-if="copied === selected"
                            class="size-3.5 text-accent-300"
                            aria-hidden="true"
                        />
                        <Copy v-else class="size-3.5" aria-hidden="true" />
                        <span class="max-sm:sr-only">{{
                            copied === selected ? 'Copiado' : 'Copiar'
                        }}</span>
                    </button>
                </div>

                <div
                    v-for="example in highlighted"
                    v-show="selected === example.id"
                    :id="`sdk-panel-${example.id}`"
                    :key="example.id"
                    role="tabpanel"
                    :aria-labelledby="`sdk-tab-${example.id}`"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-5 pt-4 text-xs text-zinc-400"
                    >
                        <span class="font-mono text-zinc-300">{{
                            example.file
                        }}</span>
                        <span
                            >Permissão
                            <code class="font-mono text-accent-300">{{
                                example.scope
                            }}</code></span
                        >
                    </div>
                    <pre
                        tabindex="0"
                        :aria-label="`Código do exemplo: ${example.label}`"
                        class="max-h-[22rem] overflow-auto px-5 py-4 font-mono text-[0.78125rem]/6 focus-ring-inverted"
                    ><code><span v-for="(token, index) in example.tokens" :key="index" :class="tokenClass[token.kind]">{{ token.text }}</span></code></pre>
                    <p
                        class="border-t border-white/10 px-5 py-4 text-[0.8125rem]/6 text-zinc-400"
                    >
                        <span class="text-zinc-200">{{
                            example.description
                        }}</span>
                        {{ example.note }}
                    </p>
                </div>

                <div
                    v-show="selected === 'sandbox'"
                    id="sdk-panel-sandbox"
                    role="tabpanel"
                    aria-labelledby="sdk-tab-sandbox"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-5 pt-4 text-xs text-zinc-400"
                    >
                        <span class="font-mono text-zinc-300">Terminal</span>
                        <span
                            >Sandbox local, sem conta, Node.js 20 ou
                            superior</span
                        >
                    </div>
                    <pre
                        tabindex="0"
                        aria-label="Sessão de terminal da demonstração local"
                        class="max-h-[22rem] overflow-auto px-5 py-4 font-mono text-[0.78125rem]/6 [overflow-wrap:anywhere] whitespace-pre-wrap focus-ring-inverted"
                    ><code><span v-for="(line, index) in demoSession" :key="index" :class="['block min-h-6', line.kind === 'command' ? 'text-white' : 'text-zinc-400']"><span v-if="line.kind === 'command'" class="text-zinc-500 select-none">$ </span>{{ line.text }}</span></code></pre>
                    <p
                        class="border-t border-white/10 px-5 py-4 text-[0.8125rem]/6 text-zinc-400"
                    >
                        <span class="text-zinc-200"
                            >Experimente emissão e exportação com dados
                            fictícios, sem conta nem chave.</span
                        >
                        A emissão simula três exemplos fixos (FT, FR e NC),
                        repetição de pedidos e conflitos de idempotência. Corre
                        no seu computador: não emite documentos reais nem
                        contacta a AGT. Não simula autenticação, limites de
                        pedidos, cálculo fiscal ou assinatura. O estado reinicia
                        em cada execução. Na exportação CSV, use um nome de
                        ficheiro novo para não substituir dados.
                    </p>
                </div>
            </div>
        </div>

        <p role="status" class="sr-only">{{ copyMessage }}</p>

        <!-- From reading to a real key, in one strip. -->
        <div
            class="mt-10 grid gap-6 rounded-2xl p-5 shadow-[0_0_0_1px_rgb(23_23_22/0.1)] sm:p-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-center lg:gap-10 dark:shadow-[0_0_0_1px_rgb(255_255_255/0.1)]"
        >
            <div class="min-w-0">
                <p class="font-semibold">Para começar</p>
                <div
                    class="mt-3 flex min-w-0 items-center gap-2 rounded-xl bg-brand-950/[0.04] py-1.5 ps-4 pe-1.5 dark:bg-white/[0.06]"
                >
                    <code
                        class="min-w-0 flex-1 [scrollbar-width:none] overflow-x-auto font-mono text-[0.8125rem] whitespace-nowrap"
                        ><span
                            class="text-brand-400 select-none dark:text-zinc-500"
                            >$ </span
                        >{{ installCommand }}</code
                    >
                    <button
                        type="button"
                        class="grid size-8 shrink-0 place-items-center rounded-lg text-brand-500 focus-ring transition-colors hover:bg-white hover:text-brand-950 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
                        aria-label="Copiar o comando de instalação"
                        @click="copyText(installCommand, 'install')"
                    >
                        <Check
                            v-if="copied === 'install'"
                            class="size-4 text-accent-600"
                            aria-hidden="true"
                        />
                        <Copy v-else class="size-4" aria-hidden="true" />
                    </button>
                </div>
            </div>
            <div class="text-[0.9375rem]/7 text-brand-600 dark:text-zinc-400">
                <p>
                    Depois, crie a chave em
                    <strong class="font-semibold text-brand-950 dark:text-white"
                        >Definições → Integrações e API</strong
                    >
                    e guarde-a só no servidor.
                    <template v-if="integrationPackages.length">
                        Acesso à API incluído:
                        <strong
                            v-for="(name, index) in integrationPackages"
                            :key="name"
                            class="font-semibold text-brand-950 dark:text-white"
                            >{{ name
                            }}{{
                                index < integrationPackages.length - 1
                                    ? ', '
                                    : ''
                            }}</strong
                        >.
                    </template>
                    <template v-else>
                        O acesso à API requer um pacote com integrações
                        externas.
                    </template>
                </p>
                <p v-if="enquiryUrl" class="mt-2">
                    <a
                        :href="enquiryUrl"
                        class="font-semibold text-brand-950 underline decoration-brand-950/25 underline-offset-4 focus-ring transition-colors hover:decoration-brand-950 dark:text-white dark:decoration-white/30 dark:hover:decoration-white"
                        >Fale connosco sobre o seu sistema</a
                    >
                    e confirme a activação antes de ligar dados reais.
                </p>
            </div>
        </div>
        <p class="mt-4 text-sm text-brand-500 dark:text-zinc-500">
            O SDK suporta emissão fiscal com activação no servidor e
            configuração fiscal válida. A resposta de emissão não confirma a
            aceitação pela AGT. Pagamentos autónomos, cancelamentos e webhooks
            não estão incluídos.
        </p>
    </section>
</template>
