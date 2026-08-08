<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Check, Menu, X } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import BrandSymbol from '@/components/BrandSymbol.vue';
import CookieConsent from '@/components/CookieConsent.vue';
import FiscalStatusOrb from '@/components/FiscalStatusOrb.vue';
import { vReveal } from '@/lib/reveal';
import { login, register } from '@/routes';
import { show as legalShow } from '@/routes/legal';

defineProps<{
    plans: {
        name: string;
        summary: string | null;
        amount: string;
        currency: string;
        interval: string;
        trial_days: number;
        features: string[];
    }[];
    provinceCount: number;
    contact: { support_email: string | null; company: string | null };
}>();

/**
 * Runs the hero sequence one frame after mount.
 *
 * Delays in a stylesheet rather than a motion library: the whole thing is a
 * stagger, and a runtime to schedule it would outweigh the page it decorates.
 * Anyone who asked for stillness gets the finished document on first paint.
 */
const sealed = ref(false);
const menuOpen = ref(false);
const selectedMomentIndex = ref(0);
const selectedLifecycleIndex = ref(0);
const lifecycleTransitioning = ref(false);
const prefersReducedMotion = ref(false);

let lifecycleTransitionTimer: number | undefined;
let reducedMotionQuery: MediaQueryList | undefined;

/** Escape closes the menu, which is the one shortcut people try without asking. */
function closeOnEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        menuOpen.value = false;
    }
}

function syncReducedMotion(event: MediaQueryListEvent): void {
    prefersReducedMotion.value = event.matches;

    if (event.matches) {
        lifecycleTransitioning.value = false;
    }
}

onMounted(() => {
    window.addEventListener('keydown', closeOnEscape);
    reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    prefersReducedMotion.value = reducedMotionQuery.matches;
    reducedMotionQuery.addEventListener('change', syncReducedMotion);

    if (prefersReducedMotion.value) {
        sealed.value = true;

        return;
    }

    requestAnimationFrame(() => {
        sealed.value = true;
    });
});

onUnmounted(() => {
    window.removeEventListener('keydown', closeOnEscape);
    reducedMotionQuery?.removeEventListener('change', syncReducedMotion);

    if (lifecycleTransitionTimer !== undefined) {
        window.clearTimeout(lifecycleTransitionTimer);
    }
});

const sections = [
    { href: '#momentos', label: 'Momentos' },
    { href: '#percurso', label: 'Como funciona' },
    { href: '#pequenos', label: 'Para pequenos negócios' },
    { href: '#incluido', label: 'O que inclui' },
];

/**
 * The page's argument, written as moments rather than as features.
 *
 * Someone deciding whether to pay for invoicing software is not shopping for a
 * feature list — they are remembering the last time something went wrong. Each
 * of these is a situation an Angolan business actually has; the answer is what
 * the application does about it.
 */
type MomentPreviewRow = {
    label: string;
    value: string;
    numeric?: boolean;
};

type Moment = {
    situation: string;
    answer: string;
    proof: string;
    preview: {
        eyebrow: string;
        title: string;
        status: string;
        rows: MomentPreviewRow[];
        note: string;
    };
};

const moments: Moment[] = [
    {
        situation: 'O cliente está à sua frente e pede a factura.',
        answer: 'Passa uma factura-recibo ali mesmo, do telemóvel, já com número, assinatura e QR. O cliente sai com o documento.',
        proof: 'FR',
        preview: {
            eyebrow: 'Documento emitido',
            title: 'FR LOJA2026/00184',
            status: 'Aceite pela AGT',
            rows: [
                { label: 'Cliente', value: 'Mercearia Kilamba' },
                { label: 'Total', value: '139 080,00 Kz', numeric: true },
                {
                    label: 'Validação',
                    value: '4192/AGT/2026',
                    numeric: true,
                },
            ],
            note: 'PDF assinado, guardado e pronto para entregar ao cliente.',
        },
    },
    {
        situation: 'Vendeu fiado e já não sabe ao certo quem lhe deve.',
        answer: 'Cada cliente tem a sua conta corrente: o que comprou, o que pagou, o que falta e desde quando.',
        proof: 'Conta corrente',
        preview: {
            eyebrow: 'Conta corrente',
            title: 'Mercearia Kilamba',
            status: '62 000,00 Kz por receber',
            rows: [
                { label: 'Facturado', value: '201 080,00 Kz', numeric: true },
                { label: 'Recebido', value: '139 080,00 Kz', numeric: true },
                { label: 'Documentos em aberto', value: '1', numeric: true },
            ],
            note: 'O saldo nasce dos documentos e recibos — não de contas feitas à parte.',
        },
    },
    {
        situation: 'A internet foi-se abaixo a meio da manhã.',
        answer: 'Continua a facturar. O que ficou por comunicar à AGT segue assim que a ligação voltar, sem repetir nada.',
        proof: 'Contingência',
        preview: {
            eyebrow: 'Fila de comunicação',
            title: '3 documentos protegidos',
            status: 'À espera de ligação',
            rows: [
                { label: 'Guardados localmente', value: '3', numeric: true },
                { label: 'Envios duplicados', value: '0', numeric: true },
                { label: 'Próxima tentativa', value: 'Automática' },
            ],
            note: 'Quando a rede regressar, a fila continua do ponto exacto onde parou.',
        },
    },
    {
        situation: 'Enganou-se no valor de uma factura já emitida.',
        answer: 'Emite uma nota de crédito que aponta para a original e diz porquê. A emitida não se apaga — nem devia.',
        proof: 'NC',
        preview: {
            eyebrow: 'Correcção fiscal',
            title: 'NC LOJA2026/00012',
            status: 'Ligada à factura original',
            rows: [
                { label: 'Documento de origem', value: 'FT LOJA2026/00179' },
                { label: 'Motivo', value: 'Valor facturado a mais' },
                { label: 'Ajuste', value: '−12 400,00 Kz', numeric: true },
            ],
            note: 'A correcção fica auditável sem reescrever a história do documento.',
        },
    },
    {
        situation: 'O contabilista pediu o ficheiro do trimestre.',
        answer: 'Escolhe as datas e descarrega o SAF-T (AO). Sem exportar folhas de cálculo nem remendar ficheiros à mão.',
        proof: 'SAF-T',
        preview: {
            eyebrow: 'Exportação fiscal',
            title: '2.º trimestre de 2026',
            status: 'Ficheiro validado',
            rows: [
                { label: 'Período', value: '01 Abr — 30 Jun' },
                { label: 'Versão', value: 'SAF-T (AO) 1.01_01' },
                { label: 'Documentos', value: '184', numeric: true },
            ],
            note: 'Um ficheiro pronto para entregar, com a ordem exigida pelo esquema.',
        },
    },
    {
        situation: 'Vendeu ao Estado e retiveram-lhe parte do imposto.',
        answer: 'A retenção na fonte e o IVA cativo ficam no documento, cada um sobre a base certa, sem contas à parte.',
        proof: 'Retenção',
        preview: {
            eyebrow: 'Liquidação',
            title: 'Venda ao Estado',
            status: 'Bases separadas',
            rows: [
                { label: 'IVA cativo', value: 'Apurado no documento' },
                { label: 'Retenção', value: 'Evidenciada à parte' },
                {
                    label: 'Líquido a receber',
                    value: 'Calculado automaticamente',
                },
            ],
            note: 'Cada imposto fica visível na base que lhe corresponde.',
        },
    },
];

const selectedMoment = computed(() => moments[selectedMomentIndex.value]!);

/**
 * The real statuses a document moves through, in order.
 *
 * The page's structural device, in place of numbered steps, because this is a
 * genuine sequence: each stage is a state the record is actually in, and the
 * reader needs to know a document is not legal until the third one.
 */
const lifecycle: {
    status: string;
    label: string;
    detail: string;
    tone: string;
    orb: 'breathing' | 'shaping' | 'connecting' | 'settling';
    processing: string;
    result: string;
    documentNumber: string;
    proofLabel: string;
    proofValue: string;
}[] = [
    {
        status: 'Rascunho',
        label: 'Escreve à vontade',
        detail: 'Nenhum número é consumido. Corrige, apaga e volta atrás as vezes que quiser.',
        tone: 'text-zinc-500 dark:text-zinc-400',
        orb: 'breathing',
        processing: 'A abrir uma área segura para editar…',
        result: 'Pronto para continuar a escrever',
        documentNumber: 'Ainda sem número',
        proofLabel: 'Pode alterar',
        proofValue: 'Todos os campos',
    },
    {
        status: 'Emitida',
        label: 'Recebe o número',
        detail: 'A série dá o número seguinte, sem saltos nem repetições. Daqui em diante é imutável.',
        tone: 'text-accent-600 dark:text-accent-300',
        orb: 'shaping',
        processing: 'A numerar e assinar o documento…',
        result: 'Número e assinatura confirmados',
        documentNumber: 'FR LOJA2026/00184',
        proofLabel: 'Assinatura',
        proofValue: '9F2C·A104·7B3E',
    },
    {
        status: 'Comunicada',
        label: 'Chega à AGT',
        detail: 'Assinada e submetida. A resposta fica guardada com o documento, como prova.',
        tone: 'text-emerald-700 dark:text-emerald-300',
        orb: 'connecting',
        processing: 'A comunicar o documento à AGT…',
        result: 'Aceite e guardado como prova',
        documentNumber: 'FR LOJA2026/00184',
        proofLabel: 'Validação AGT',
        proofValue: '4192/AGT/2026',
    },
    {
        status: 'Liquidada',
        label: 'Fica paga',
        detail: 'O recibo abate na dívida do cliente e o que falta receber fica à vista.',
        tone: 'text-emerald-700 dark:text-emerald-300',
        orb: 'settling',
        processing: 'A reconciliar o recebimento…',
        result: 'Pagamento reflectido na conta corrente',
        documentNumber: 'FR LOJA2026/00184',
        proofLabel: 'Saldo do documento',
        proofValue: '0,00 Kz',
    },
];

const selectedLifecycle = computed(
    () => lifecycle[selectedLifecycleIndex.value]!,
);

function selectLifecycle(index: number): void {
    selectedLifecycleIndex.value = index;

    if (lifecycleTransitionTimer !== undefined) {
        window.clearTimeout(lifecycleTransitionTimer);
    }

    if (prefersReducedMotion.value) {
        lifecycleTransitioning.value = false;

        return;
    }

    lifecycleTransitioning.value = true;
    lifecycleTransitionTimer = window.setTimeout(() => {
        lifecycleTransitioning.value = false;
        lifecycleTransitionTimer = undefined;
    }, 820);
}

/**
 * The promises made to a one-person business, in its own terms.
 *
 * The province count is interpolated rather than typed out, so the page cannot
 * contradict the picker the day the map changes again.
 */
const forSmallBusiness = (count: number): string[] => [
    'Não precisa de contabilista para começar.',
    'Funciona no telemóvel que já tem no bolso.',
    `Em português, em kwanzas, com as ${count} províncias.`,
    'Uma loja hoje, várias amanhã — sem mudar de programa.',
    'Quem factura ao estrangeiro escolhe a moeda e o câmbio.',
];

/**
 * What is true about opening an account, price list or no price list.
 *
 * Carries the section on its own while nothing is published, so a visitor who
 * arrives before the plans do still knows exactly what happens if they say yes.
 */
const openingAnAccount: { term: string; value: string }[] = [
    {
        term: 'Sem cartão',
        value: 'Abre a conta e configura a empresa sem dar dados de pagamento.',
    },
    {
        term: 'Ambiente de testes',
        value: 'Emite à vontade sem consumir numeração. Só passa a produção quando quiser.',
    },
    {
        term: 'Sem ficar preso',
        value: 'Os documentos e os clientes saem consigo, num arquivo aberto, quando pedir.',
    },
];

/** Stated plainly and without adjectives: this section is not selling. */
const obligations: { term: string; value: string }[] = [
    {
        term: 'Comunicação à AGT',
        value: 'Cada documento assinado e submetido, com a resposta arquivada junto ao registo.',
    },
    {
        term: 'SAF-T (AO) 1.01_01',
        value: 'O ficheiro de auditoria do período gerado a pedido, na ordem que o esquema exige.',
    },
    {
        term: 'Conservação legal',
        value: 'Documentos dentro do prazo não se apagam — nem a pedido de quem os emitiu.',
    },
    {
        term: 'Os dados são seus',
        value: 'Exporta tudo num arquivo aberto, quando quiser, sem ter de pedir a ninguém.',
    },
];

/** What comes with the account, set as an inventory rather than as cards. */
const included: { group: string; items: string[] }[] = [
    {
        group: 'Documentos',
        items: [
            'Facturas (FT) e facturas-recibo (FR)',
            'Recibos (RC) que liquidam várias facturas',
            'Notas de crédito e de débito (NC, ND)',
            'Orçamentos que se convertem em factura',
            'Facturação periódica automática',
            'PDF com QR, assinatura e o seu logótipo',
        ],
    },
    {
        group: 'A casa em ordem',
        items: [
            'Clientes com prazos e limite de crédito',
            'Tabelas de preços e preços por cliente',
            'Artigos, existências e movimentos de stock',
            'Vários estabelecimentos, cada um com a sua série',
            'Envio dos documentos por email ao cliente',
            'Extractos, dívidas e análise de vendas',
        ],
    },
];
</script>

<template>
    <Head>
        <title>facturac.ao · Facturação para negócios angolanos</title>
        <meta
            name="description"
            content="Passe facturas, recibos e notas com comunicação à AGT, SAF-T e contas correntes. Feito para quem tem um negócio em Angola, do balcão à empresa com várias lojas."
        />
    </Head>

    <div
        class="min-h-screen bg-[var(--surface-page)] text-brand-900 dark:text-zinc-100"
    >
        <a
            href="#conteudo"
            class="sr-only rounded-xl bg-brand-950 px-4 py-2 text-sm font-semibold text-white focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-[60]"
        >
            Saltar para o conteúdo
        </a>

        <!-- ------------------------------------------------------------ nav -->
        <header
            class="sticky top-0 z-50 border-b border-zinc-900/5 bg-[var(--surface-page)]/80 backdrop-blur-xl dark:border-white/10"
        >
            <nav
                class="mx-auto flex max-w-6xl items-center justify-between gap-6 px-5 py-3.5 sm:px-8"
                aria-label="Principal"
            >
                <a
                    href="#topo"
                    class="flex items-center gap-2.5 rounded-lg focus-ring"
                >
                    <BrandSymbol
                        class="size-8 text-brand-950 dark:text-white"
                        :animated="false"
                    />
                    <span
                        class="brand-wordmark text-lg text-brand-950 dark:text-white"
                        >facturac.ao</span
                    >
                </a>

                <div
                    class="hidden items-center gap-7 text-sm text-brand-700 lg:flex dark:text-zinc-300"
                >
                    <a
                        v-for="section in sections"
                        :key="section.href"
                        :href="section.href"
                        class="rounded focus-ring transition hover:text-brand-950 dark:hover:text-white"
                        >{{ section.label }}</a
                    >
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="login.url()"
                        class="hidden rounded-xl px-3.5 py-2 text-sm font-semibold text-brand-800 focus-ring transition hover:bg-brand-950/5 sm:inline-flex dark:text-zinc-200 dark:hover:bg-white/10"
                    >
                        Entrar
                    </Link>
                    <Link
                        :href="register.url()"
                        class="inline-flex items-center rounded-xl bg-brand-950 px-4 py-2 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-800 dark:bg-white dark:text-brand-950 dark:hover:bg-zinc-200"
                    >
                        Criar conta
                    </Link>
                    <button
                        type="button"
                        class="rounded-xl p-2 text-brand-800 focus-ring transition hover:bg-brand-950/5 lg:hidden dark:text-zinc-200 dark:hover:bg-white/10"
                        :aria-expanded="menuOpen"
                        aria-controls="menu-movel"
                        @click="menuOpen = !menuOpen"
                    >
                        <span class="sr-only">{{
                            menuOpen ? 'Fechar menu' : 'Abrir menu'
                        }}</span>
                        <component
                            :is="menuOpen ? X : Menu"
                            class="size-5"
                            aria-hidden="true"
                        />
                    </button>
                </div>
            </nav>

            <div
                v-if="menuOpen"
                id="menu-movel"
                class="border-t border-zinc-900/5 px-5 pb-4 lg:hidden dark:border-white/10"
            >
                <a
                    v-for="section in sections"
                    :key="section.href"
                    :href="section.href"
                    class="block rounded-lg py-2.5 text-sm font-medium text-brand-700 focus-ring dark:text-zinc-300"
                    @click="menuOpen = false"
                    >{{ section.label }}</a
                >
                <Link
                    :href="login.url()"
                    class="block rounded-lg py-2.5 text-sm font-medium text-brand-700 focus-ring sm:hidden dark:text-zinc-300"
                    >Entrar</Link
                >
            </div>
        </header>

        <main id="conteudo">
            <!-- ------------------------------------------------------- hero -->
            <section
                id="topo"
                class="relative overflow-hidden bg-brand-950 text-white"
            >
                <div
                    class="fiscal-grid pointer-events-none absolute inset-0 opacity-60"
                    aria-hidden="true"
                />
                <div
                    class="pointer-events-none absolute -top-48 -right-40 size-[38rem] rounded-full bg-accent-400/10 blur-3xl"
                    aria-hidden="true"
                />

                <div
                    class="relative mx-auto grid max-w-6xl gap-14 px-5 py-20 sm:px-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,25rem)] lg:items-center lg:gap-16 lg:py-28"
                >
                    <div :class="['hero-copy', { 'is-in': sealed }]">
                        <p
                            class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 eyebrow text-accent-300"
                            style="--step: 0"
                        >
                            Facturação electrónica · Angola
                        </p>

                        <h1
                            class="mt-6 text-[2.6rem]/[1.05] display text-balance text-white sm:text-6xl lg:text-[4.25rem]"
                            style="--step: 1"
                        >
                            Passe a factura.<br />
                            <span class="text-accent-400"
                                >Do resto tratamos nós.</span
                            >
                        </h1>

                        <p
                            class="mt-7 max-w-xl text-lg/8 text-zinc-300"
                            style="--step: 2"
                        >
                            O número, a assinatura, a comunicação à AGT e o
                            comprovativo acontecem sozinhos, enquanto você
                            atende o cliente seguinte. É essa a parte chata — e
                            é essa que deixa de ser sua.
                        </p>

                        <div
                            class="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center"
                            style="--step: 3"
                        >
                            <Link
                                :href="register.url()"
                                class="group inline-flex items-center justify-center gap-2 rounded-xl bg-accent-400 px-6 py-3.5 text-base font-semibold text-brand-950 shadow-lg shadow-accent-400/20 focus-ring-inverted transition hover:bg-accent-300"
                            >
                                Abrir conta grátis
                                <ArrowRight
                                    class="size-4 transition-transform group-hover:translate-x-0.5"
                                    aria-hidden="true"
                                />
                            </Link>
                            <a
                                href="#momentos"
                                class="inline-flex items-center justify-center rounded-xl px-6 py-3.5 text-base font-semibold text-white ring-1 ring-white/20 focus-ring-inverted transition hover:bg-white/10"
                            >
                                Ver o que resolve
                            </a>
                        </div>

                        <p class="mt-5 text-sm text-zinc-400" style="--step: 4">
                            Sem cartão. Sem contrato. Os seus dados saem consigo
                            se um dia quiser sair.
                        </p>
                    </div>

                    <!--
                        The signature: a document becoming legal in front of the
                        reader. The seal is the brand mark doing the thing it
                        stands for, instead of sitting in a corner as a logo.
                    -->
                    <div
                        :class="['doc-stage', { 'is-sealed': sealed }]"
                        aria-hidden="true"
                    >
                        <article class="doc">
                            <header class="doc__head">
                                <div>
                                    <p class="doc__kind">Factura-recibo</p>
                                    <p class="doc__no numeric">
                                        FR LOJA2026/00184
                                    </p>
                                </div>
                                <BrandSymbol
                                    class="size-7 text-brand-950"
                                    :animated="false"
                                />
                            </header>

                            <dl class="doc__party">
                                <div>
                                    <dt>Cliente</dt>
                                    <dd>Mercearia Kilamba, Lda.</dd>
                                </div>
                                <div>
                                    <dt>NIF</dt>
                                    <dd class="numeric">5417238904</dd>
                                </div>
                            </dl>

                            <ul class="doc__lines">
                                <li class="doc__line" style="--row: 0">
                                    <span>Arroz 25 kg · 4 sacos</span>
                                    <span class="numeric">62 000,00</span>
                                </li>
                                <li class="doc__line" style="--row: 1">
                                    <span>Óleo alimentar · 12 un</span>
                                    <span class="numeric">38 400,00</span>
                                </li>
                                <li class="doc__line" style="--row: 2">
                                    <span>Açúcar 1 kg · 30 un</span>
                                    <span class="numeric">21 600,00</span>
                                </li>
                            </ul>

                            <div class="doc__totals">
                                <div class="doc__total">
                                    <span>Incidência</span>
                                    <span class="numeric">122 000,00</span>
                                </div>
                                <div class="doc__total">
                                    <span>IVA 14%</span>
                                    <span class="numeric">17 080,00</span>
                                </div>
                                <div class="doc__total doc__total--grand">
                                    <span>Total</span>
                                    <span class="numeric">139 080,00 Kz</span>
                                </div>
                            </div>

                            <footer class="doc__fiscal">
                                <div class="doc__qr">
                                    <svg viewBox="0 0 21 21" class="size-full">
                                        <path
                                            class="doc__qr-path"
                                            fill="currentColor"
                                            d="M0 0h7v7H0zm2 2v3h3V2zM14 0h7v7h-7zm2 2v3h3V2zM0 14h7v7H0zm2 2v3h3v-3zM9 0h2v2H9zm2 2h2v2h-2zM9 4h2v2H9zm4 5h2v2h-2zm-4 0h2v2H9zm-9 0h2v2H0zm4 0h2v2H4zm5 4h2v2H9zm4 0h2v2h-2zm4 0h2v2h-2zm-8 4h2v2H9zm4 0h2v2h-2zm4 0h2v2h-2zm2-4h2v2h-2z"
                                        />
                                    </svg>
                                </div>
                                <div class="doc__proof">
                                    <p class="doc__proof-label">
                                        Nº de validação
                                    </p>
                                    <p class="doc__proof-value numeric">
                                        4192/AGT/2026
                                    </p>
                                    <p class="doc__proof-label">Assinatura</p>
                                    <p class="doc__proof-value numeric">
                                        9F2C·A104·7B3E
                                    </p>
                                </div>
                            </footer>

                            <div class="doc__seal">
                                <BrandSymbol
                                    class="size-full text-brand-950"
                                    :animated="false"
                                />
                            </div>
                        </article>

                        <p class="doc__status">
                            <span class="doc__status-dot" />
                            Comunicada à AGT · aceite
                        </p>
                    </div>
                </div>
            </section>

            <!-- -------------------------------------------------- the moments -->
            <section
                id="momentos"
                class="mx-auto max-w-6xl px-5 py-24 sm:px-8 lg:py-32"
            >
                <div v-reveal class="max-w-2xl">
                    <p class="eyebrow text-accent-600 dark:text-accent-400">
                        Momentos
                    </p>
                    <h2
                        class="mt-3 text-3xl display text-balance text-brand-950 sm:text-4xl lg:text-[2.75rem] lg:leading-tight dark:text-white"
                    >
                        Isto não é uma lista de funcionalidades. São as coisas
                        que já lhe aconteceram.
                    </h2>
                    <p
                        class="mt-4 text-base/7 text-brand-600 dark:text-zinc-400"
                    >
                        Escolha uma situação para ver como a resposta aparece no
                        produto.
                    </p>
                </div>

                <div
                    class="mt-16 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,0.78fr)] lg:gap-12"
                >
                    <ol
                        v-reveal
                        class="-mx-5 flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth px-5 pb-3 motion-reduce:scroll-auto sm:-mx-8 sm:px-8 lg:mx-0 lg:block lg:space-y-2 lg:overflow-visible lg:px-0 lg:pb-0"
                        aria-label="Escolher um momento do negócio"
                    >
                        <li
                            v-for="(moment, index) in moments"
                            :key="moment.proof"
                            class="w-[82vw] max-w-80 shrink-0 snap-start lg:w-auto lg:max-w-none"
                        >
                            <button
                                :id="`moment-trigger-${index}`"
                                type="button"
                                class="group flex h-full min-h-28 w-full items-center gap-4 rounded-2xl border px-5 py-5 text-left focus-ring transition duration-300 motion-reduce:transition-none sm:px-6 lg:min-h-0"
                                :class="
                                    selectedMomentIndex === index
                                        ? 'border-accent-400/70 bg-white shadow-lg shadow-brand-950/5 dark:bg-zinc-900'
                                        : 'border-zinc-900/8 bg-transparent hover:border-zinc-900/15 hover:bg-white/70 dark:border-white/10 dark:hover:border-white/20 dark:hover:bg-zinc-900/70'
                                "
                                :aria-pressed="selectedMomentIndex === index"
                                aria-controls="moment-preview"
                                @click="selectedMomentIndex = index"
                            >
                                <span
                                    class="min-w-0 flex-1 text-base/7 font-medium text-balance text-brand-950 sm:text-lg/8 dark:text-white"
                                >
                                    {{ moment.situation }}
                                </span>
                                <span
                                    class="hidden shrink-0 rounded-lg bg-accent-400/12 px-2.5 py-1 font-mono numeric text-[0.6875rem] font-semibold text-accent-700 sm:inline dark:bg-accent-400/15 dark:text-accent-300"
                                >
                                    {{ moment.proof }}
                                </span>
                                <ArrowRight
                                    class="size-4 shrink-0 text-zinc-400 transition-transform duration-300 group-hover:translate-x-0.5 motion-reduce:transition-none"
                                    :class="{
                                        'translate-x-0.5 text-accent-600 dark:text-accent-300':
                                            selectedMomentIndex === index,
                                    }"
                                    aria-hidden="true"
                                />
                            </button>
                        </li>
                    </ol>

                    <div v-reveal="1" class="lg:sticky lg:top-24">
                        <article
                            id="moment-preview"
                            class="relative overflow-hidden rounded-3xl bg-brand-950 p-6 text-white shadow-2xl shadow-brand-950/15 sm:p-8"
                            :aria-labelledby="`moment-trigger-${selectedMomentIndex}`"
                        >
                            <div
                                class="fiscal-grid pointer-events-none absolute inset-0 opacity-40"
                                aria-hidden="true"
                            />

                            <div class="relative">
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <p class="eyebrow text-zinc-400">
                                        Exemplo no produto
                                    </p>
                                    <span
                                        class="rounded-lg bg-accent-400/12 px-2.5 py-1 font-mono numeric text-[0.6875rem] font-semibold text-accent-300"
                                    >
                                        {{ selectedMoment.proof }}
                                    </span>
                                </div>

                                <Transition name="preview-swap" mode="out-in">
                                    <div :key="selectedMoment.proof">
                                        <p
                                            class="mt-5 text-base/7 text-zinc-300"
                                        >
                                            {{ selectedMoment.answer }}
                                        </p>

                                        <div
                                            class="mt-6 overflow-hidden rounded-2xl bg-[#fdfcfa] text-brand-950 shadow-xl"
                                        >
                                            <header
                                                class="border-b border-zinc-900/8 px-5 py-5"
                                            >
                                                <p
                                                    class="text-[0.625rem] font-semibold tracking-[0.16em] text-brand-500 uppercase"
                                                >
                                                    {{
                                                        selectedMoment.preview
                                                            .eyebrow
                                                    }}
                                                </p>
                                                <h3
                                                    class="mt-1.5 font-mono numeric text-base font-semibold text-brand-950"
                                                >
                                                    {{
                                                        selectedMoment.preview
                                                            .title
                                                    }}
                                                </h3>
                                                <p
                                                    class="mt-3 inline-flex items-center gap-2 text-xs font-medium text-emerald-700"
                                                >
                                                    <span
                                                        class="size-1.5 rounded-full bg-current"
                                                        aria-hidden="true"
                                                    />
                                                    {{
                                                        selectedMoment.preview
                                                            .status
                                                    }}
                                                </p>
                                            </header>

                                            <dl
                                                class="divide-y divide-zinc-900/6 px-5"
                                            >
                                                <div
                                                    v-for="row in selectedMoment
                                                        .preview.rows"
                                                    :key="row.label"
                                                    class="flex items-baseline justify-between gap-5 py-3.5 text-sm"
                                                >
                                                    <dt class="text-brand-500">
                                                        {{ row.label }}
                                                    </dt>
                                                    <dd
                                                        class="text-right font-medium text-brand-900"
                                                        :class="{
                                                            'font-mono numeric':
                                                                row.numeric,
                                                        }"
                                                    >
                                                        {{ row.value }}
                                                    </dd>
                                                </div>
                                            </dl>

                                            <p
                                                class="border-t border-zinc-900/8 bg-accent-400/8 px-5 py-4 text-xs/5 text-brand-600"
                                            >
                                                {{
                                                    selectedMoment.preview.note
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </Transition>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <!-- ---------------------------------------------------- lifecycle -->
            <section
                id="percurso"
                class="border-y border-zinc-900/5 bg-white py-24 lg:py-32 dark:border-white/10 dark:bg-zinc-900"
            >
                <div class="mx-auto max-w-6xl px-5 sm:px-8">
                    <div v-reveal class="max-w-2xl">
                        <p class="eyebrow text-accent-600 dark:text-accent-400">
                            O percurso de um documento
                        </p>
                        <h2
                            class="mt-3 text-3xl display text-balance text-brand-950 sm:text-4xl dark:text-white"
                        >
                            Quatro estados, e sabe sempre em qual está.
                        </h2>
                        <p
                            class="mt-4 text-lg/8 text-brand-600 dark:text-zinc-400"
                        >
                            Não são passos de folheto. São os estados em que o
                            registo está mesmo — e nenhum deles acontece nas
                            suas costas.
                        </p>
                    </div>

                    <div
                        class="mt-16 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(23rem,0.82fr)] lg:gap-12"
                    >
                        <ol
                            v-reveal
                            class="-mx-5 flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth px-5 pb-3 motion-reduce:scroll-auto sm:-mx-8 sm:px-8 lg:mx-0 lg:block lg:space-y-2 lg:overflow-visible lg:px-0 lg:pb-0"
                            aria-label="Escolher estado do documento"
                        >
                            <li
                                v-for="(stage, index) in lifecycle"
                                :key="stage.status"
                                class="w-[82vw] max-w-80 shrink-0 snap-start lg:w-auto lg:max-w-none"
                            >
                                <button
                                    type="button"
                                    class="relative h-full min-h-40 w-full overflow-hidden rounded-2xl border px-6 py-5 text-left focus-ring transition duration-300 motion-reduce:transition-none lg:min-h-0"
                                    :class="
                                        selectedLifecycleIndex === index
                                            ? 'border-accent-400/70 bg-accent-400/8 shadow-lg shadow-brand-950/5'
                                            : 'border-zinc-900/8 bg-white hover:border-zinc-900/15 dark:border-white/10 dark:bg-zinc-900 dark:hover:border-white/20'
                                    "
                                    :aria-pressed="
                                        selectedLifecycleIndex === index
                                    "
                                    aria-controls="lifecycle-preview"
                                    @click="selectLifecycle(index)"
                                >
                                    <span
                                        class="absolute inset-y-0 left-0 w-0.5 bg-accent-400 transition-transform duration-300 motion-reduce:transition-none"
                                        :class="
                                            selectedLifecycleIndex === index
                                                ? 'scale-y-100'
                                                : 'scale-y-0'
                                        "
                                        aria-hidden="true"
                                    />
                                    <span :class="['eyebrow', stage.tone]">{{
                                        stage.status
                                    }}</span>
                                    <div
                                        class="mt-2 grid gap-1 sm:grid-cols-[11rem_1fr] sm:items-baseline sm:gap-5"
                                    >
                                        <h3
                                            class="text-base font-semibold text-brand-950 dark:text-white"
                                        >
                                            {{ stage.label }}
                                        </h3>
                                        <p
                                            class="text-sm/6 text-brand-600 dark:text-zinc-400"
                                        >
                                            {{ stage.detail }}
                                        </p>
                                    </div>
                                </button>
                            </li>
                        </ol>

                        <article
                            id="lifecycle-preview"
                            v-reveal="1"
                            class="relative overflow-hidden rounded-3xl bg-brand-950 p-6 text-white shadow-2xl shadow-brand-950/15 sm:p-8 lg:sticky lg:top-24"
                        >
                            <div
                                class="fiscal-grid pointer-events-none absolute inset-0 opacity-40"
                                aria-hidden="true"
                            />

                            <div class="relative">
                                <header
                                    class="flex items-start justify-between gap-6"
                                >
                                    <div>
                                        <p class="eyebrow text-accent-300">
                                            Estado seleccionado
                                        </p>
                                        <h3
                                            class="mt-2 text-2xl display text-white"
                                        >
                                            {{ selectedLifecycle.status }}
                                        </h3>
                                    </div>
                                    <div
                                        class="grid size-24 shrink-0 place-items-center rounded-full bg-white shadow-xl"
                                    >
                                        <FiscalStatusOrb
                                            :active="lifecycleTransitioning"
                                            :mode="selectedLifecycle.orb"
                                            :label="
                                                lifecycleTransitioning
                                                    ? selectedLifecycle.processing
                                                    : selectedLifecycle.result
                                            "
                                        />
                                    </div>
                                </header>

                                <p
                                    class="mt-5 flex min-h-6 items-center gap-2 text-sm font-medium text-accent-300"
                                    role="status"
                                    aria-live="polite"
                                >
                                    <span
                                        class="size-1.5 rounded-full bg-current"
                                        :class="{
                                            'motion-safe:animate-pulse':
                                                lifecycleTransitioning,
                                        }"
                                        aria-hidden="true"
                                    />
                                    {{
                                        lifecycleTransitioning
                                            ? selectedLifecycle.processing
                                            : selectedLifecycle.result
                                    }}
                                </p>

                                <div
                                    class="mt-6 rounded-2xl bg-[#fdfcfa] p-5 text-brand-950 shadow-xl"
                                >
                                    <div
                                        class="flex items-start justify-between gap-4 border-b border-zinc-900/8 pb-4"
                                    >
                                        <div>
                                            <p
                                                class="text-[0.625rem] font-semibold tracking-[0.16em] text-brand-500 uppercase"
                                            >
                                                Factura-recibo
                                            </p>
                                            <p
                                                class="mt-1 font-mono numeric text-sm font-semibold"
                                            >
                                                {{
                                                    selectedLifecycle.documentNumber
                                                }}
                                            </p>
                                        </div>
                                        <span
                                            class="rounded-lg bg-accent-400/12 px-2.5 py-1 text-xs font-semibold text-accent-700"
                                        >
                                            {{ selectedLifecycle.status }}
                                        </span>
                                    </div>

                                    <dl class="mt-4 space-y-3 text-sm">
                                        <div
                                            class="flex items-baseline justify-between gap-5"
                                        >
                                            <dt class="text-brand-500">
                                                {{
                                                    selectedLifecycle.proofLabel
                                                }}
                                            </dt>
                                            <dd
                                                class="font-mono numeric font-semibold text-brand-900"
                                            >
                                                {{
                                                    selectedLifecycle.proofValue
                                                }}
                                            </dd>
                                        </div>
                                        <div
                                            class="flex items-baseline justify-between gap-5"
                                        >
                                            <dt class="text-brand-500">
                                                Total
                                            </dt>
                                            <dd
                                                class="font-mono numeric font-semibold text-brand-900"
                                            >
                                                139 080,00 Kz
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------ small business -->
            <section id="pequenos" class="px-5 py-24 sm:px-8 lg:py-32">
                <div
                    class="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-brand-950 px-6 py-16 sm:px-12 lg:px-16 lg:py-20"
                >
                    <div
                        class="fiscal-grid pointer-events-none absolute inset-0 opacity-50"
                        aria-hidden="true"
                    />

                    <div
                        class="relative grid gap-14 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)] lg:gap-20"
                    >
                        <div v-reveal>
                            <p class="eyebrow text-accent-300">
                                Para pequenos negócios
                            </p>
                            <h2
                                class="mt-4 text-3xl display text-balance text-white sm:text-4xl lg:text-[2.75rem] lg:leading-tight"
                            >
                                Se o seu negócio é você, uma banca e um caderno,
                                isto também é para si.
                            </h2>
                            <p class="mt-6 max-w-xl text-lg/8 text-zinc-300">
                                A lei é a mesma para a multinacional e para quem
                                vende à esquina — as ferramentas é que quase
                                nunca são. Esta foi pensada primeiro para quem
                                factura sozinho, e continua a servir no dia em
                                que forem catorze pessoas.
                            </p>

                            <div class="mt-10 flex flex-wrap gap-3">
                                <Link
                                    :href="register.url()"
                                    class="group inline-flex items-center gap-2 rounded-xl bg-accent-400 px-5 py-3 text-sm font-semibold text-brand-950 focus-ring-inverted transition hover:bg-accent-300"
                                >
                                    Começar sem pagar nada
                                    <ArrowRight
                                        class="size-4 transition-transform group-hover:translate-x-0.5"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </div>
                        </div>

                        <ul class="relative space-y-5">
                            <li
                                v-for="(claim, index) in forSmallBusiness(
                                    provinceCount,
                                )"
                                :key="claim"
                                v-reveal="index"
                                class="flex items-start gap-3.5 text-base/7 text-zinc-200"
                            >
                                <span
                                    class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-accent-400/15 text-accent-300"
                                >
                                    <Check class="size-3" aria-hidden="true" />
                                </span>
                                {{ claim }}
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------------ included -->
            <section
                id="incluido"
                class="border-y border-zinc-900/5 bg-white py-24 lg:py-32 dark:border-white/10 dark:bg-zinc-900"
            >
                <div class="mx-auto max-w-6xl px-5 sm:px-8">
                    <div v-reveal class="max-w-2xl">
                        <p class="eyebrow text-accent-600 dark:text-accent-400">
                            O que inclui
                        </p>
                        <h2
                            class="mt-3 text-3xl display text-balance text-brand-950 sm:text-4xl dark:text-white"
                        >
                            Tudo isto vem na mesma conta.
                        </h2>
                        <p
                            class="mt-4 text-lg/8 text-brand-600 dark:text-zinc-400"
                        >
                            Sem módulos a comprar à parte quando o negócio
                            crescer.
                        </p>
                    </div>

                    <div class="mt-16 grid gap-x-16 gap-y-12 sm:grid-cols-2">
                        <div
                            v-for="(block, blockIndex) in included"
                            :key="block.group"
                            v-reveal="blockIndex"
                        >
                            <h3
                                class="border-b border-zinc-900/8 pb-3 eyebrow text-brand-500 dark:border-white/10 dark:text-zinc-400"
                            >
                                {{ block.group }}
                            </h3>
                            <ul class="mt-5 space-y-3.5">
                                <li
                                    v-for="item in block.items"
                                    :key="item"
                                    class="flex items-start gap-3 text-base/7 text-brand-700 dark:text-zinc-300"
                                >
                                    <Check
                                        class="mt-1.5 size-4 shrink-0 text-accent-600 dark:text-accent-400"
                                        aria-hidden="true"
                                    />
                                    {{ item }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ---------------------------------------------------- compliance -->
            <section class="mx-auto max-w-6xl px-5 py-24 sm:px-8 lg:py-32">
                <div v-reveal class="max-w-2xl">
                    <p class="eyebrow text-accent-600 dark:text-accent-400">
                        Conformidade
                    </p>
                    <h2
                        class="mt-3 text-3xl display text-balance text-brand-950 sm:text-4xl dark:text-white"
                    >
                        A parte que não se negoceia.
                    </h2>
                </div>

                <dl
                    class="mt-14 divide-y divide-zinc-900/8 dark:divide-white/10"
                >
                    <div
                        v-for="(row, index) in obligations"
                        :key="row.term"
                        v-reveal="index"
                        class="grid gap-2 py-6 sm:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] sm:gap-10"
                    >
                        <dt
                            class="font-mono numeric text-sm font-semibold tracking-tight text-brand-950 dark:text-white"
                        >
                            {{ row.term }}
                        </dt>
                        <dd
                            class="text-base/7 text-brand-600 dark:text-zinc-400"
                        >
                            {{ row.value }}
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- ------------------------------------------------------- plans -->
            <section
                class="border-t border-zinc-900/5 px-5 py-24 sm:px-8 lg:py-32 dark:border-white/10"
            >
                <div v-reveal class="mx-auto max-w-2xl text-center">
                    <p class="eyebrow text-accent-600 dark:text-accent-400">
                        Começar
                    </p>
                    <h2
                        class="mt-3 text-3xl display text-balance text-brand-950 sm:text-4xl dark:text-white"
                    >
                        Experimente primeiro. Decida depois.
                    </h2>
                    <p class="mt-4 text-lg/8 text-brand-600 dark:text-zinc-400">
                        Abra a conta, configure a empresa e emita à vontade em
                        ambiente de testes. Só passa a produção quando estiver
                        pronto.
                    </p>
                </div>

                <!--
                    Rendered from the plans table. With none published the page
                    says so plainly, rather than showing a price nobody agreed
                    to charge.
                -->
                <div
                    v-if="plans.length > 0"
                    class="mx-auto mt-14 flex max-w-4xl flex-wrap justify-center gap-6"
                >
                    <article
                        v-for="(plan, index) in plans"
                        :key="plan.name"
                        v-reveal="index"
                        class="flex w-full max-w-sm flex-col rounded-2xl surface p-7 sm:w-[calc(50%-0.75rem)] lg:w-[calc(33.333%-1rem)]"
                    >
                        <h3
                            class="font-semibold text-brand-950 dark:text-white"
                        >
                            {{ plan.name }}
                        </h3>
                        <p
                            v-if="plan.summary"
                            class="mt-1.5 text-sm/6 text-brand-600 dark:text-zinc-400"
                        >
                            {{ plan.summary }}
                        </p>
                        <p class="mt-6 flex items-baseline gap-1.5">
                            <span
                                class="numeric text-3xl font-semibold text-brand-950 dark:text-white"
                                >{{ plan.amount }}</span
                            >
                            <span
                                class="text-sm font-medium text-brand-500 dark:text-zinc-400"
                                >{{ plan.currency }} / {{ plan.interval }}</span
                            >
                        </p>
                        <ul
                            v-if="plan.features.length > 0"
                            class="mt-6 space-y-2.5 text-sm/6 text-brand-700 dark:text-zinc-300"
                        >
                            <li
                                v-for="feature in plan.features"
                                :key="feature"
                                class="flex items-start gap-2.5"
                            >
                                <Check
                                    class="mt-1 size-3.5 shrink-0 text-accent-600 dark:text-accent-400"
                                    aria-hidden="true"
                                />
                                {{ feature }}
                            </li>
                        </ul>
                        <Link
                            :href="register.url()"
                            class="mt-8 inline-flex items-center justify-center rounded-xl bg-brand-950 px-4 py-2.5 text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 dark:bg-white dark:text-brand-950 dark:hover:bg-zinc-200"
                        >
                            Escolher {{ plan.name }}
                        </Link>
                    </article>
                </div>

                <!--
                    No published plan is not the same as nothing to say. What
                    the reader is deciding right now is whether to open an
                    account, and every answer to that is true with or without a
                    price list.
                -->
                <div
                    v-else
                    v-reveal
                    class="mx-auto mt-14 max-w-3xl rounded-2xl surface p-8 sm:p-10"
                >
                    <dl
                        class="grid gap-8 text-center sm:grid-cols-3 sm:text-left"
                    >
                        <div
                            v-for="(assurance, index) in openingAnAccount"
                            :key="assurance.term"
                            v-reveal="index"
                        >
                            <dt
                                class="font-semibold text-brand-950 dark:text-white"
                            >
                                {{ assurance.term }}
                            </dt>
                            <dd
                                class="mt-1.5 text-sm/6 text-brand-600 dark:text-zinc-400"
                            >
                                {{ assurance.value }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div
                    v-reveal="1"
                    class="mt-14 flex flex-col items-center gap-4"
                >
                    <Link
                        :href="register.url()"
                        class="group inline-flex items-center gap-2 rounded-xl bg-accent-400 px-7 py-4 text-base font-semibold text-brand-950 shadow-lg shadow-accent-400/20 focus-ring transition hover:bg-accent-300"
                    >
                        Abrir a minha conta
                        <ArrowRight
                            class="size-4 transition-transform group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </Link>
                    <Link
                        :href="login.url()"
                        class="rounded text-sm font-medium text-brand-600 underline-offset-4 focus-ring transition hover:text-brand-950 hover:underline dark:text-zinc-400 dark:hover:text-white"
                    >
                        Já tenho conta
                    </Link>
                </div>
            </section>
        </main>

        <!-- --------------------------------------------------------- footer -->
        <footer
            class="border-t border-zinc-900/5 px-5 py-12 sm:px-8 dark:border-white/10"
        >
            <div
                class="mx-auto flex max-w-6xl flex-col gap-8 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center gap-2.5">
                    <BrandSymbol
                        class="size-7 text-brand-950 dark:text-white"
                        :animated="false"
                    />
                    <div>
                        <p
                            class="brand-wordmark text-brand-950 dark:text-white"
                        >
                            facturac.ao
                        </p>
                        <p
                            v-if="contact.company"
                            class="text-xs text-brand-500 dark:text-zinc-400"
                        >
                            {{ contact.company }}
                        </p>
                    </div>
                </div>

                <nav
                    class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-brand-600 dark:text-zinc-400"
                    aria-label="Rodapé"
                >
                    <Link
                        :href="legalShow.url('termos')"
                        class="rounded focus-ring transition hover:text-brand-950 dark:hover:text-white"
                        >Termos</Link
                    >
                    <Link
                        :href="legalShow.url('privacidade')"
                        class="rounded focus-ring transition hover:text-brand-950 dark:hover:text-white"
                        >Privacidade</Link
                    >
                    <Link
                        :href="legalShow.url('cookies')"
                        class="rounded focus-ring transition hover:text-brand-950 dark:hover:text-white"
                        >Cookies</Link
                    >
                    <a
                        v-if="contact.support_email"
                        :href="`mailto:${contact.support_email}`"
                        class="rounded focus-ring transition hover:text-brand-950 dark:hover:text-white"
                        >Apoio</a
                    >
                </nav>
            </div>
        </footer>

        <CookieConsent />
    </div>
</template>

<style scoped>
/* ----------------------------------------------- interactive previews -- */

.preview-swap-enter-active,
.preview-swap-leave-active {
    transition:
        opacity 180ms ease,
        transform 260ms cubic-bezier(0.22, 1, 0.36, 1);
}

.preview-swap-enter-from {
    opacity: 0;
    transform: translateY(8px);
}

.preview-swap-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

/* ------------------------------------------------------------- hero copy -- */

.hero-copy > * {
    opacity: 0;
    transform: translateY(14px);
}

.hero-copy.is-in > * {
    animation: rise 660ms cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: calc(var(--step, 0) * 95ms);
}

@keyframes rise {
    to {
        opacity: 1;
        transform: none;
    }
}

/* ------------------------------------------------------------- the sheet -- */

.doc-stage {
    position: relative;
    perspective: 1400px;
}

.doc {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1.5rem;
    border-radius: 1rem;
    background: #fdfcfa;
    color: var(--color-brand-900);
    box-shadow:
        0 1px 2px rgb(0 0 0 / 20%),
        0 40px 80px -32px rgb(0 0 0 / 65%);
    opacity: 0;
    transform: translateY(26px) rotateX(9deg);
    transform-origin: 50% 0;
}

.doc-stage.is-sealed .doc {
    animation: sheet-settle 900ms cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes sheet-settle {
    to {
        opacity: 1;
        transform: none;
    }
}

.doc__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding-bottom: 0.875rem;
    border-bottom: 1px solid rgb(0 0 0 / 8%);
}

.doc__kind {
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--color-brand-500);
}

.doc__no {
    margin-top: 0.25rem;
    font-family: var(--font-mono);
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-brand-950);
}

.doc__party {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 0.75rem;
    font-size: 0.75rem;
}

.doc__party dt {
    color: var(--color-brand-500);
}

.doc__party dd {
    margin-top: 0.125rem;
    font-weight: 500;
    color: var(--color-brand-900);
}

.doc__party .numeric {
    font-family: var(--font-mono);
}

/* The items arrive one after another, the way they are keyed in. */

.doc__lines {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 0.75rem 0;
    border-top: 1px solid rgb(0 0 0 / 6%);
    border-bottom: 1px solid rgb(0 0 0 / 6%);
}

.doc__line {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
    font-size: 0.8125rem;
    opacity: 0;
    transform: translateY(6px);
}

.doc__line span:first-child {
    color: var(--color-brand-700);
}

.doc__line .numeric {
    font-family: var(--font-mono);
    font-weight: 500;
    color: var(--color-brand-950);
}

.doc-stage.is-sealed .doc__line {
    animation: rise 420ms cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: calc(420ms + var(--row) * 120ms);
}

.doc__totals {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    opacity: 0;
}

.doc-stage.is-sealed .doc__totals {
    animation: rise 460ms cubic-bezier(0.22, 1, 0.36, 1) 880ms both;
}

.doc__total {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    font-size: 0.8125rem;
    color: var(--color-brand-600);
}

.doc__total .numeric {
    font-family: var(--font-mono);
}

.doc__total--grand {
    padding-top: 0.5rem;
    border-top: 1px solid rgb(0 0 0 / 8%);
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-brand-950);
}

/* The identity the AGT hands back, which is the point of the whole thing. */

.doc__fiscal {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.875rem;
    border-radius: 0.625rem;
    background: color-mix(in srgb, var(--color-accent-400) 10%, transparent);
    opacity: 0;
}

.doc-stage.is-sealed .doc__fiscal {
    animation: rise 520ms cubic-bezier(0.22, 1, 0.36, 1) 1180ms both;
}

.doc__qr {
    flex: none;
    width: 3.25rem;
    height: 3.25rem;
    padding: 0.25rem;
    border-radius: 0.375rem;
    background: #fff;
    color: var(--color-brand-950);
}

.doc-stage.is-sealed .doc__qr-path {
    animation: qr-in 520ms steps(6, end) 1320ms both;
}

@keyframes qr-in {
    from {
        opacity: 0;
    }

    to {
        opacity: 1;
    }
}

.doc__proof-label {
    font-size: 0.5625rem;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--color-brand-500);
}

.doc__proof-label + .doc__proof-value {
    margin-bottom: 0.375rem;
}

.doc__proof-value {
    font-family: var(--font-mono);
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-brand-950);
}

/* The press. One beat after the number lands, which is when it would happen. */

.doc__seal {
    position: absolute;
    right: -1.25rem;
    bottom: -1.25rem;
    width: 6.5rem;
    height: 6.5rem;
    opacity: 0;
}

.doc-stage.is-sealed .doc__seal {
    animation: seal-press 560ms cubic-bezier(0.34, 1.36, 0.64, 1) 1560ms both;
}

@keyframes seal-press {
    from {
        opacity: 0;
        transform: rotate(-22deg) scale(1.55);
    }

    60% {
        opacity: 0.95;
    }

    to {
        opacity: 0.95;
        transform: rotate(0deg) scale(1);
    }
}

.doc__status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 1.25rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-accent-300);
    opacity: 0;
}

.doc-stage.is-sealed .doc__status {
    animation: rise 480ms cubic-bezier(0.22, 1, 0.36, 1) 1980ms both;
}

.doc__status-dot {
    width: 0.4375rem;
    height: 0.4375rem;
    border-radius: 9999px;
    background: currentColor;
}

/*
 * Everything above decorates a document that is already correct and already
 * readable, so stillness costs the reader nothing.
 */
@media (prefers-reduced-motion: reduce) {
    .preview-swap-enter-active,
    .preview-swap-leave-active {
        transition: none;
    }

    .hero-copy > *,
    .doc,
    .doc__line,
    .doc__totals,
    .doc__fiscal,
    .doc__seal,
    .doc__status {
        opacity: 1;
        transform: none;
        animation: none;
    }

    .doc__seal {
        opacity: 0.95;
    }
}
</style>
