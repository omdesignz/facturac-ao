<script setup lang="ts">
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    supportEmail: string | null;
    integrationPackages: string[];
}>();

const enquiryUrl = computed(() =>
    props.supportEmail
        ? `mailto:${props.supportEmail}?subject=${encodeURIComponent('API e integrações — facturac.ao')}`
        : null,
);
const demoCommand =
    'npm exec --yes --package=@vapsolucoes.ao/facturac@0.2.0 -- facturac-export --demo ./facturac-demo.csv';
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
                    <a
                        href="https://github.com/omdesignz/facturac-sdk#try-an-integration-in-five-minutes"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex min-h-11 items-center gap-2 rounded-full bg-brand-950 px-5 py-3 text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 dark:bg-white dark:text-brand-950 dark:hover:bg-zinc-200"
                    >
                        Guia de integração
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </a>
                    <a
                        href="https://www.npmjs.com/package/@vapsolucoes.ao/facturac"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex min-h-11 items-center rounded-full px-5 py-3 text-sm font-semibold ring-1 ring-brand-950/15 focus-ring transition ring-inset hover:bg-brand-50 dark:ring-white/20 dark:hover:bg-white/5"
                        >Ver no npm</a
                    >
                </div>
            </div>
            <div
                class="min-w-0 rounded-[2rem] bg-brand-950 p-7 text-white sm:p-9 dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-white/15 pb-5"
                >
                    <span class="font-mono text-sm text-zinc-300"
                        >facturac-export</span
                    >
                    <span
                        class="rounded-full bg-lime-300/15 px-3 py-1 text-xs font-medium text-lime-200"
                        >Demonstração local</span
                    >
                </div>
                <p class="mt-6 text-sm text-zinc-400">
                    No seu terminal · Node.js 20 ou superior
                </p>
                <pre
                    class="mt-4 font-mono text-sm/7 break-all whitespace-pre-wrap"
                ><code>{{ demoCommand }}</code></pre>
                <div
                    class="mt-6 border-t border-white/15 pt-5 font-mono text-sm/6 text-lime-200"
                    aria-label="Resultado esperado da demonstração"
                >
                    <p>DEMO — synthetic data.</p>
                    <p>Exported 2 documents.</p>
                </div>
                <p class="mt-5 text-sm/6 text-zinc-400">
                    O npm descarrega o pacote na primeira execução. A
                    demonstração usa dados fictícios e não contacta a sua
                    empresa nem a AGT.
                </p>
            </div>
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
                <a
                    v-if="enquiryUrl"
                    :href="enquiryUrl"
                    class="inline-flex min-h-11 items-center justify-center rounded-full bg-brand-950 px-5 py-3 text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 dark:bg-white dark:text-brand-950 dark:hover:bg-zinc-200"
                    >Falar sobre a minha integração</a
                >
                <a
                    href="#precos"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-full px-5 py-3 text-sm font-semibold focus-ring transition hover:bg-brand-100 dark:hover:bg-white/5"
                    >Consultar planos
                    <ArrowRight class="size-4" aria-hidden="true"
                /></a>
            </div>
        </div>
    </section>
</template>
