<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Menu, RotateCcw, X } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import CookieConsent from '@/components/CookieConsent.vue';
import { vReveal } from '@/lib/reveal';
import { login, register } from '@/routes';
import { show as legalShow } from '@/routes/legal';

const props = defineProps<{
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

const menuOpen = ref(false);
const menuButton = ref<HTMLButtonElement | null>(null);
const selectedMomentIndex = ref(0);
const selectedLifecycleIndex = ref(0);
const closingWordmark = ref<InstanceType<typeof BrandWordmark> | null>(null);

let lifecycleTimer: number | undefined;

/**
 * Escape closes the menu, which is the one shortcut people try without asking,
 * and hands focus back to the button that opened it so the keyboard is not
 * left on a link that has just disappeared.
 */
function closeOnEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape' && menuOpen.value) {
        menuOpen.value = false;
        menuButton.value?.focus();
    }
}

function stopLifecycleTour(): void {
    if (lifecycleTimer !== undefined) {
        window.clearInterval(lifecycleTimer);
        lifecycleTimer = undefined;
    }
}

/**
 * Walks the hero document from draft to paid once, so a visitor who never
 * touches it still sees the dot travel to its full stop. Anyone who asked for
 * stillness gets the finished, paid document on first paint.
 */
onMounted(() => {
    window.addEventListener('keydown', closeOnEscape);

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        selectedLifecycleIndex.value = lifecycle.length - 1;

        return;
    }

    lifecycleTimer = window.setInterval(() => {
        if (selectedLifecycleIndex.value >= lifecycle.length - 1) {
            stopLifecycleTour();

            return;
        }

        selectedLifecycleIndex.value += 1;
    }, 1100);
});

onUnmounted(() => {
    window.removeEventListener('keydown', closeOnEscape);
    stopLifecycleTour();
});

const sections = [
    { href: '#momentos', label: 'Como funciona' },
    { href: '#mudar', label: 'Mudar de programa' },
    { href: '#precos', label: 'Preços' },
    { href: '#perguntas', label: 'Perguntas' },
];

/**
 * The day e-invoicing stops being optional for everyone else.
 *
 * Large taxpayers and suppliers to the State have issued electronically since
 * January 2026; from this date the Regime Geral and the Regime Simplificado
 * follow. Counted rather than written down so the page never goes stale.
 */
const MANDATORY_FOR_ALL = new Date(2027, 0, 1);

const daysUntilMandatory = computed(() => {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    return Math.ceil(
        (MANDATORY_FOR_ALL.getTime() - today.getTime()) / 86_400_000,
    );
});

/**
 * The real statuses a document moves through, in order.
 *
 * A genuine sequence: each stage is a state the record is actually in, and the
 * reader needs to know a document is not legal until it is validated. The four
 * stages are also the brand's signature line, each one closed by the dot.
 */
const lifecycle: {
    status: string;
    time: string;
    detail: string;
    proofLabel: string;
    proofValue: string;
}[] = [
    {
        status: 'Rascunho',
        time: '09:40',
        detail: 'Nenhum número é consumido. Corrige, apaga e volta atrás as vezes que quiser.',
        proofLabel: 'Pode alterar',
        proofValue: 'Todos os campos',
    },
    {
        status: 'Emitida',
        time: '09:41',
        detail: 'A série dá o número seguinte, sem saltos nem repetições. Daqui em diante é imutável.',
        proofLabel: 'Assinatura',
        proofValue: '9F2C·A104·7B3E',
    },
    {
        status: 'Validada',
        time: '09:41',
        detail: 'Assinada e comunicada à AGT. A resposta fica guardada com o documento, como prova.',
        proofLabel: 'Validação AGT',
        proofValue: '4192/AGT/2026',
    },
    {
        status: 'Paga',
        time: '09:41',
        detail: 'O pagamento abate na conta do cliente e o que falta receber fica à vista.',
        proofLabel: 'Saldo do documento',
        proofValue: '0,00 Kz',
    },
];

const selectedLifecycle = computed(
    () => lifecycle[selectedLifecycleIndex.value]!,
);

function selectLifecycle(index: number): void {
    stopLifecycleTour();
    selectedLifecycleIndex.value = index;
}

/**
 * The page's argument, written as moments rather than as features.
 *
 * Someone deciding whether to pay for invoicing software is remembering the
 * last time something went wrong. Each of these is a situation an Angolan
 * business actually has; the answer is what happens here instead.
 */
const moments: {
    tag: string;
    situation: string;
    answer: string;
    proof: {
        eyebrow: string;
        title: string;
        status: string;
        tone: 'valid' | 'pending' | 'neutral';
        rows: { label: string; value: string; numeric?: boolean }[];
        note: string;
    };
}[] = [
    {
        tag: 'FR',
        situation: 'O cliente está à sua frente e pede a factura.',
        answer: 'Passa uma factura-recibo ali mesmo, do telemóvel, já com número, assinatura e QR. O cliente sai com o documento.',
        proof: {
            eyebrow: 'Documento emitido',
            title: 'FR LOJA2026/00184',
            status: 'Validada pela AGT',
            tone: 'valid',
            rows: [
                { label: 'Cliente', value: 'Mercearia Kilamba' },
                { label: 'Total', value: '139 080,00 Kz', numeric: true },
                { label: 'Validação', value: '4192/AGT/2026' },
            ],
            note: 'PDF assinado, guardado e pronto para entregar ao cliente.',
        },
    },
    {
        tag: 'Conta',
        situation: 'Vendeu fiado e já não sabe ao certo quem lhe deve.',
        answer: 'Cada cliente tem a sua conta corrente: o que comprou, o que pagou, o que falta e desde quando.',
        proof: {
            eyebrow: 'Conta corrente',
            title: 'Mercearia Kilamba',
            status: '62 000,00 Kz por receber',
            tone: 'pending',
            rows: [
                { label: 'Facturado', value: '201 080,00 Kz', numeric: true },
                { label: 'Recebido', value: '139 080,00 Kz', numeric: true },
                { label: 'Documentos em aberto', value: '1', numeric: true },
            ],
            note: 'O saldo nasce dos documentos e recibos, não de contas feitas à parte.',
        },
    },
    {
        tag: 'Rede',
        situation: 'A internet foi-se abaixo a meio da manhã.',
        answer: 'Continua a facturar. O que ficou por comunicar à AGT segue assim que a ligação voltar, sem repetir nada.',
        proof: {
            eyebrow: 'Fila de comunicação',
            title: '3 documentos protegidos',
            status: 'À espera de ligação',
            tone: 'neutral',
            rows: [
                { label: 'Assinados e guardados', value: '3', numeric: true },
                { label: 'Envios duplicados', value: '0', numeric: true },
                { label: 'Próxima tentativa', value: 'Automática' },
            ],
            note: 'Quando a rede regressar, a fila continua do ponto exacto onde parou.',
        },
    },
    {
        tag: 'NC',
        situation: 'Enganou-se no valor de uma factura já emitida.',
        answer: 'Emite uma nota de crédito que aponta para a original e diz porquê. A emitida não se apaga, nem devia.',
        proof: {
            eyebrow: 'Correcção fiscal',
            title: 'NC LOJA2026/00012',
            status: 'Ligada à original',
            tone: 'neutral',
            rows: [
                { label: 'Documento de origem', value: 'FT LOJA2026/00179' },
                { label: 'Motivo', value: 'Valor facturado a mais' },
                { label: 'Ajuste', value: '−12 400,00 Kz', numeric: true },
            ],
            note: 'A correcção fica auditável sem reescrever a história do documento.',
        },
    },
    {
        tag: 'SAF-T',
        situation: 'O contabilista pediu o ficheiro do trimestre.',
        answer: 'Escolhe as datas e descarrega o SAF-T (AO). Sem exportar folhas de cálculo nem remendar ficheiros à mão.',
        proof: {
            eyebrow: 'Exportação fiscal',
            title: '2.º trimestre de 2026',
            status: 'Ficheiro validado',
            tone: 'valid',
            rows: [
                { label: 'Período', value: '01 abr a 30 jun' },
                { label: 'Versão', value: 'SAF-T (AO) 1.01_01' },
                { label: 'Documentos', value: '184', numeric: true },
            ],
            note: 'Um ficheiro pronto para entregar, com a ordem exigida pelo esquema.',
        },
    },
    {
        tag: 'Estado',
        situation: 'Vendeu ao Estado e retiveram-lhe parte do imposto.',
        answer: 'A retenção na fonte e o IVA cativo ficam no documento, cada um sobre a base certa, sem contas à parte.',
        proof: {
            eyebrow: 'Liquidação',
            title: 'Venda ao Estado',
            status: 'Bases separadas',
            tone: 'neutral',
            rows: [
                { label: 'IVA cativo', value: 'Apurado no documento' },
                { label: 'Retenção', value: 'Evidenciada à parte' },
                { label: 'Líquido a receber', value: 'Calculado sozinho' },
            ],
            note: 'Cada imposto fica visível na base que lhe corresponde.',
        },
    },
];

const selectedMoment = computed(() => moments[selectedMomentIndex.value]!);

/** Arrow keys move between moments, as they do in any tab list. */
function moveMoment(event: KeyboardEvent, index: number): void {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
        return;
    }

    event.preventDefault();

    const next =
        (index + (event.key === 'ArrowDown' ? 1 : moments.length - 1)) %
        moments.length;

    selectedMomentIndex.value = next;
    document.getElementById(`momento-${next}`)?.focus();
}

function proofToneClasses(tone: 'valid' | 'pending' | 'neutral'): string {
    if (tone === 'valid') {
        return 'bg-lime-300 text-lime-950 dark:bg-lime-300/90';
    }

    if (tone === 'pending') {
        return 'bg-accent-400 text-brand-950';
    }

    return 'bg-zinc-200/80 text-zinc-700 dark:bg-white/10 dark:text-zinc-300';
}

/**
 * The promises made to a one-person business, in its own terms.
 *
 * The province count is interpolated rather than typed out, so the page cannot
 * contradict the picker the day the map changes again.
 */
const forSmallBusiness = computed(() => [
    'Não precisa de contabilista para começar.',
    `Em português, em kwanzas, com as ${props.provinceCount} províncias.`,
    'Uma loja hoje, várias amanhã, sem mudar de programa.',
    'Quem factura ao estrangeiro escolhe a moeda e o câmbio.',
]);

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

/** Stated plainly and without adjectives: this part is not selling. */
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
        value: 'Documentos dentro do prazo não se apagam, nem a pedido de quem os emitiu.',
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
            'Recibos (RG) que liquidam várias facturas',
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
            'Artigos, inventário e movimentos de stock',
            'Vários estabelecimentos, cada um com a sua série',
            'Envio dos documentos por email ao cliente',
            'Extractos, dívidas e análise de vendas',
        ],
    },
];

/** The four facts a buyer checks first, set as a strip under the hero. */
const proofFacts: { term: string; value: string }[] = [
    { term: 'FT · FR · RG', value: 'Facturas, facturas-recibo e recibos' },
    { term: 'NC · ND', value: 'Correcções ligadas ao original' },
    { term: 'SAF-T (AO)', value: 'O ficheiro do período, a pedido' },
    { term: 'Sem rede?', value: 'Continua a facturar e envia depois' },
];

/** Illustration only: the phone shows what the screen looks like, not data. */
const exampleReceivables: {
    initials: string;
    customer: string;
    when: string;
    amount: string;
    tone: string;
}[] = [
    {
        initials: 'MK',
        customer: 'Mercearia Kilamba',
        when: 'há 6 dias',
        amount: '62 000',
        tone: 'bg-rose-100 text-rose-900',
    },
    {
        initials: 'RM',
        customer: 'Restaurante Mufete',
        when: 'vence amanhã',
        amount: '78 000',
        tone: 'bg-lime-100 text-lime-900',
    },
    {
        initials: 'OK',
        customer: 'Obras Kilamba',
        when: 'vence 14 out',
        amount: '172 000',
        tone: 'bg-sky-100 text-sky-900',
    },
];

/** Moving over is a real sequence, so it is the one place numbers are used. */
const switchingSteps: { title: string; detail: string }[] = [
    {
        title: 'Exporte do programa actual',
        detail: 'O Excel ou o CSV que já usa, ou a exportação de outra aplicação.',
    },
    {
        title: 'Carregue e confira',
        detail: 'Mostramos o que vai entrar, e o que precisa de revisão, antes de gravar seja o que for.',
    },
    {
        title: 'Confirme',
        detail: 'Clientes e artigos ficam prontos para facturar. Nada é gravado sem a sua confirmação.',
    },
];

const questions: { question: string; answer: string }[] = [
    {
        question: 'O que muda a 1 de janeiro de 2027?',
        answer: 'As empresas do Regime Geral e do Regime Simplificado do IVA passam a ter de emitir factura electrónica, assinada e comunicada à AGT. Os grandes contribuintes e quem factura ao Estado já o fazem desde janeiro de 2026.',
    },
    {
        question: 'Preciso de contabilista para começar?',
        answer: 'Não. Cria a conta, preenche o NIF e os dados da empresa, e o IVA, as retenções e a comunicação à AGT são tratados por si. Quando o contabilista precisar, gera o SAF-T do período.',
    },
    {
        question: 'E se a internet falhar?',
        answer: 'Continua a facturar. Os documentos ficam assinados e na fila, e seguem para a AGT assim que a ligação voltar, sem repetir nada.',
    },
    {
        question: 'Enganei-me numa factura já emitida. E agora?',
        answer: 'Emite uma nota de crédito que aponta para a original e diz porquê. A factura emitida não se apaga, porque a lei não deixa, mas a correcção fica ligada a ela.',
    },
    {
        question: 'Como se escreve o vosso nome?',
        answer: 'Tire o til e a cedilha a "facturação" e ponha um ponto antes do "ao": facturac.ao.',
    },
];
</script>

<template>
    <Head>
        <title>facturac.ao · Facturação electrónica para Angola</title>
        <meta
            name="description"
            content="Passe facturas, recibos e notas com comunicação à AGT, SAF-T e contas correntes. Pronto para a factura electrónica obrigatória a 1 de janeiro de 2027."
        />
    </Head>

    <div
        class="min-h-screen bg-white text-brand-950 dark:bg-zinc-950 dark:text-zinc-100"
    >
        <a
            href="#conteudo"
            class="sr-only rounded-xl bg-brand-950 px-4 py-2 text-sm font-semibold text-white focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-[60]"
        >
            Saltar para o conteúdo
        </a>

        <!-- ------------------------------------------------------------ nav -->
        <header
            class="sticky top-0 z-50 border-b border-zinc-900/5 bg-white/85 backdrop-blur-xl dark:border-white/10 dark:bg-zinc-950/85"
        >
            <nav
                class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-3 px-5 sm:gap-6 sm:px-8"
                aria-label="Principal"
            >
                <a
                    href="#topo"
                    class="shrink-0 rounded-lg text-brand-950 focus-ring dark:text-white"
                >
                    <BrandWordmark
                        class="h-4 w-auto min-[360px]:h-5 sm:h-[1.4rem]"
                    />
                </a>

                <div
                    class="hidden items-center gap-1 text-sm font-medium text-brand-600 lg:flex dark:text-zinc-300"
                >
                    <a
                        v-for="section in sections"
                        :key="section.href"
                        :href="section.href"
                        class="rounded-full px-3 py-2 focus-ring transition hover:bg-brand-50 hover:text-brand-950 dark:hover:bg-white/5 dark:hover:text-white"
                        >{{ section.label }}</a
                    >
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="login.url()"
                        class="hidden rounded-full px-3.5 py-2 text-sm font-semibold text-brand-800 focus-ring transition hover:bg-brand-50 sm:inline-flex dark:text-zinc-200 dark:hover:bg-white/5"
                    >
                        Entrar
                    </Link>
                    <Link
                        :href="register.url()"
                        class="inline-flex h-10 items-center rounded-full bg-accent-400 px-3.5 text-sm font-semibold whitespace-nowrap text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1)] focus-ring transition hover:bg-accent-300 sm:px-4"
                    >
                        Abrir conta
                    </Link>
                    <button
                        ref="menuButton"
                        type="button"
                        class="grid size-10 place-items-center rounded-full text-brand-800 focus-ring transition hover:bg-brand-50 lg:hidden dark:text-zinc-200 dark:hover:bg-white/5 pointer-coarse:size-11"
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

            <Transition
                enter-active-class="transition duration-150 ease-out motion-reduce:transition-none"
                enter-from-class="-translate-y-1 opacity-0"
            >
                <div
                    v-if="menuOpen"
                    id="menu-movel"
                    class="border-t border-zinc-900/5 px-5 pb-4 lg:hidden dark:border-white/10"
                >
                    <a
                        v-for="section in sections"
                        :key="section.href"
                        :href="section.href"
                        class="block rounded-lg py-3 text-sm font-medium text-brand-700 focus-ring dark:text-zinc-300"
                        @click="menuOpen = false"
                        >{{ section.label }}</a
                    >
                    <Link
                        :href="login.url()"
                        class="block rounded-lg py-3 text-sm font-medium text-brand-700 focus-ring sm:hidden dark:text-zinc-300"
                        >Entrar</Link
                    >
                </div>
            </Transition>
        </header>

        <main id="conteudo" tabindex="-1" class="focus:outline-hidden">
            <!-- ---------------------------------------------------------- hero -->
            <section
                id="topo"
                class="mx-auto grid max-w-6xl items-center gap-14 px-5 pt-14 pb-16 sm:px-8 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] lg:gap-16 lg:pt-24 lg:pb-20"
            >
                <div>
                    <p
                        class="flex flex-wrap items-center gap-2.5 text-xs font-medium tracking-[0.08em] text-brand-500 uppercase dark:text-zinc-400"
                    >
                        Facturação electrónica para Angola
                        <span
                            class="rounded-full bg-lime-300 px-2.5 py-1 text-xs font-semibold tracking-normal text-lime-950 normal-case"
                            >Pronta para 2027</span
                        >
                    </p>
                    <h1 class="mt-5 font-display text-hero text-balance">
                        Facturação sempre acabou em
                        <span class="whitespace-nowrap"
                            ><span class="brand-dot" aria-hidden="true" /><span
                                class="sr-only"
                                >.</span
                            >ao</span
                        >
                    </h1>
                    <p
                        class="mt-6 max-w-xl text-lg/7 text-brand-600 sm:text-xl/8 dark:text-zinc-400"
                    >
                        Passe a factura. O número, a assinatura, a comunicação à
                        AGT e o comprovativo acontecem sozinhos, enquanto atende
                        o cliente seguinte.
                    </p>
                    <div class="mt-9 flex flex-wrap gap-3">
                        <Link
                            :href="register.url()"
                            class="inline-flex h-13 items-center gap-2 rounded-full bg-accent-400 px-6 text-base font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] focus-ring transition hover:bg-accent-300"
                        >
                            Abrir conta grátis
                        </Link>
                        <a
                            href="#momentos"
                            class="inline-flex h-13 items-center rounded-full px-6 text-base font-semibold text-brand-950 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-brand-50 dark:text-white dark:ring-white/15 dark:hover:bg-white/5"
                        >
                            Ver o que resolve
                        </a>
                    </div>
                    <p class="mt-4 text-sm text-brand-500 dark:text-zinc-400">
                        Sem cartão. Sem contrato. Os seus dados saem consigo se
                        um dia quiser sair.
                    </p>
                </div>

                <!--
                    The signature: one real document moving through its states,
                    with the gold dot travelling to its full stop. The steps are
                    buttons, so the reader can walk it themselves.
                -->
                <div class="relative mx-auto w-full max-w-md lg:max-w-none">
                    <div
                        class="ml-auto w-full max-w-sm rounded-md bg-white p-6 text-[0.72rem]/[1.45] text-zinc-800 shadow-[0_2px_6px_rgb(23_23_22/0.06),0_40px_70px_-36px_rgb(23_23_22/0.38)] ring-1 ring-zinc-900/5"
                        aria-label="Exemplo de uma factura-recibo"
                        role="img"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <span
                                    class="grid size-8 place-items-center rounded-md bg-[#2c4a3b] text-[0.65rem] font-semibold text-[#f2efe2]"
                                    >LK</span
                                >
                                <p class="mt-2 text-[0.78rem] font-semibold">
                                    Loja Kianda, Lda.
                                </p>
                                <p class="text-zinc-400">
                                    NIF 5417 880 214 · Talatona
                                </p>
                            </div>
                            <div class="text-right">
                                <p
                                    class="text-[0.68rem] font-semibold tracking-[0.08em]"
                                >
                                    FACTURA-RECIBO
                                </p>
                                <p class="font-mono text-[0.78rem] font-medium">
                                    {{
                                        selectedLifecycleIndex === 0
                                            ? 'Ainda sem número'
                                            : 'FR LOJA2026/00184'
                                    }}
                                </p>
                                <p class="text-zinc-400">05-10-2026</p>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div>
                                <p
                                    class="text-[0.56rem] font-semibold tracking-[0.08em] text-zinc-400 uppercase"
                                >
                                    Cliente
                                </p>
                                <p class="font-semibold">Mercearia Kilamba</p>
                                <p class="text-zinc-400">NIF 5000 412 778</p>
                            </div>
                            <div>
                                <p
                                    class="text-[0.56rem] font-semibold tracking-[0.08em] text-zinc-400 uppercase"
                                >
                                    Pagamento
                                </p>
                                <p>Multicaixa</p>
                                <p class="text-zinc-400">Pago no acto</p>
                            </div>
                        </div>
                        <table class="mt-4 w-full numeric">
                            <thead>
                                <tr
                                    class="text-[0.56rem] tracking-[0.08em] text-zinc-400 uppercase"
                                >
                                    <th
                                        class="border-b border-zinc-200 py-1 text-left font-semibold"
                                    >
                                        Artigo
                                    </th>
                                    <th
                                        class="border-b border-zinc-200 py-1 text-right font-semibold"
                                    >
                                        Qtd
                                    </th>
                                    <th
                                        class="border-b border-zinc-200 py-1 text-right font-semibold"
                                    >
                                        Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="[&_td]:border-b [&_td]:border-zinc-100 [&_td]:py-1.5"
                            >
                                <tr>
                                    <td>Prateleira metálica, 5 níveis</td>
                                    <td class="text-right">2</td>
                                    <td class="text-right">64 000,00</td>
                                </tr>
                                <tr>
                                    <td>Balança digital 30 kg</td>
                                    <td class="text-right">1</td>
                                    <td class="text-right">38 000,00</td>
                                </tr>
                                <tr>
                                    <td>Cesto de compras</td>
                                    <td class="text-right">20</td>
                                    <td class="text-right">20 000,00</td>
                                </tr>
                            </tbody>
                        </table>
                        <dl class="mt-3 ml-auto grid w-3/5 gap-1 numeric">
                            <div class="flex justify-between">
                                <dt>Ilíquido</dt>
                                <dd>122 000,00</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>IVA 14%</dt>
                                <dd>17 080,00</dd>
                            </div>
                            <div
                                class="mt-1 flex justify-between border-t border-zinc-800 pt-1.5 text-[0.85rem] font-semibold"
                            >
                                <dt>Total Kz</dt>
                                <dd>139 080,00</dd>
                            </div>
                        </dl>
                        <p class="mt-4 text-zinc-400">
                            {{ selectedLifecycle.proofLabel }} ·
                            <span class="font-mono text-zinc-600">{{
                                selectedLifecycle.proofValue
                            }}</span>
                        </p>
                    </div>

                    <div
                        class="relative -mt-10 rounded-[1.25rem] bg-white p-5 shadow-[0_1px_2px_rgb(23_23_22/0.05),0_12px_28px_-12px_rgb(23_23_22/0.22)] ring-1 ring-zinc-900/5 sm:mr-8 dark:bg-zinc-900 dark:ring-white/10"
                    >
                        <div
                            class="flex items-baseline justify-between gap-4 text-sm"
                        >
                            <span
                                class="font-mono text-xs text-brand-500 dark:text-zinc-400"
                                >FR LOJA2026/00184</span
                            >
                            <span class="font-semibold" aria-live="polite"
                                >{{ selectedLifecycle.status
                                }}<span class="brand-dot" aria-hidden="true"
                            /></span>
                        </div>
                        <div
                            class="relative mx-2 mt-4 h-5"
                            role="group"
                            aria-label="Escolher estado do documento"
                        >
                            <span
                                class="absolute inset-x-0 top-2 h-0.5 rounded bg-zinc-200 dark:bg-white/10"
                            />
                            <span
                                class="absolute top-2 left-0 h-0.5 rounded bg-brand-950 transition-[width] duration-[450ms] ease-in-out-strong motion-reduce:transition-none dark:bg-white"
                                :style="{
                                    width: `${(selectedLifecycleIndex / (lifecycle.length - 1)) * 100}%`,
                                }"
                            />
                            <button
                                v-for="(stage, index) in lifecycle"
                                :key="stage.status"
                                type="button"
                                class="absolute top-0 -ml-2.5 grid size-5 place-items-center rounded-full focus-ring after:absolute after:-inset-3 after:content-['']"
                                :style="{
                                    left: `${(index / (lifecycle.length - 1)) * 100}%`,
                                }"
                                :aria-pressed="index === selectedLifecycleIndex"
                                :aria-label="stage.status"
                                @click="selectLifecycle(index)"
                            >
                                <span
                                    class="size-2.5 rounded-full ring-4 ring-white dark:ring-zinc-900"
                                    :class="
                                        index <= selectedLifecycleIndex
                                            ? 'bg-brand-950 dark:bg-white'
                                            : 'bg-zinc-300 dark:bg-zinc-600'
                                    "
                                />
                            </button>
                            <span
                                class="pointer-events-none absolute top-0 -ml-2.5 size-5 rounded-full bg-accent-400 shadow-[0_0_0_4px_white,0_6px_14px_-4px_rgb(150_95_0/0.55)] transition-[left] duration-[450ms] ease-in-out-strong motion-reduce:transition-none dark:shadow-[0_0_0_4px_var(--color-zinc-900)]"
                                :style="{
                                    left: `${(selectedLifecycleIndex / (lifecycle.length - 1)) * 100}%`,
                                }"
                                aria-hidden="true"
                            />
                        </div>
                        <div
                            class="mt-3 grid grid-cols-4 text-xs font-semibold"
                        >
                            <span
                                v-for="(stage, index) in lifecycle"
                                :key="stage.status"
                                :class="[
                                    index === 0
                                        ? 'text-left'
                                        : index === lifecycle.length - 1
                                          ? 'text-right'
                                          : 'text-center',
                                    index <= selectedLifecycleIndex
                                        ? 'text-brand-950 dark:text-white'
                                        : 'text-brand-300 dark:text-zinc-600',
                                ]"
                                >{{ stage.status
                                }}<span
                                    class="block font-normal text-brand-500 dark:text-zinc-400"
                                    >{{ stage.time }}</span
                                ></span
                            >
                        </div>
                        <p
                            class="mt-4 border-t border-zinc-900/5 pt-3 text-sm/6 text-brand-600 dark:border-white/10 dark:text-zinc-400"
                        >
                            {{ selectedLifecycle.detail }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- ---------------------------------------------------- proof strip -->
            <div class="mx-auto max-w-6xl px-5 sm:px-8">
                <dl
                    class="grid grid-cols-2 gap-px border-y border-zinc-900/10 bg-zinc-900/10 lg:grid-cols-4 dark:border-white/10 dark:bg-white/10"
                >
                    <div
                        v-for="fact in proofFacts"
                        :key="fact.term"
                        class="bg-white py-5 pr-5 dark:bg-zinc-950 [&:nth-child(2n)]:pl-5 lg:[&:nth-child(n+2)]:pl-5"
                    >
                        <dt
                            class="font-display text-2xl tracking-[-0.02em] text-brand-950 dark:text-white"
                        >
                            {{ fact.term }}
                        </dt>
                        <dd
                            class="mt-1 text-sm text-brand-600 dark:text-zinc-400"
                        >
                            {{ fact.value }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- ------------------------------------------------------ deadline -->
            <section id="prazo" class="px-5 pt-(--space-section) sm:px-8">
                <div
                    v-reveal
                    class="mx-auto grid max-w-6xl items-end gap-10 rounded-[2rem] bg-brand-950 p-8 text-white sm:p-12 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] lg:p-16 dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
                >
                    <div>
                        <h2 class="font-display text-stamp whitespace-nowrap">
                            <span aria-hidden="true"
                                >atenc<span class="brand-dot" />ao</span
                            ><span class="sr-only">Atenção</span>
                        </h2>
                        <p
                            v-if="daysUntilMandatory > 0"
                            class="mt-6 flex items-baseline gap-3"
                        >
                            <span
                                class="font-display numeric text-5xl tracking-[-0.03em] sm:text-6xl"
                                >{{ daysUntilMandatory }}</span
                            >
                            <span class="text-zinc-400"
                                >{{
                                    daysUntilMandatory === 1 ? 'dia' : 'dias'
                                }}
                                até 1 de janeiro de 2027</span
                            >
                        </p>
                    </div>
                    <div>
                        <p class="max-w-md text-lg/8 text-zinc-400">
                            <template v-if="daysUntilMandatory > 0">
                                <strong class="font-semibold text-white"
                                    >A partir de 1 de janeiro de 2027,</strong
                                >
                                todas as empresas do Regime Geral e do Regime
                                Simplificado passam a emitir factura
                                electrónica. Quem ainda factura em papel ou em
                                Excel tem de mudar este ano.
                            </template>
                            <template v-else>
                                <strong class="font-semibold text-white"
                                    >Desde 1 de janeiro de 2027,</strong
                                >
                                a factura electrónica é obrigatória para todas
                                as empresas do Regime Geral e do Regime
                                Simplificado.
                            </template>
                        </p>
                        <div class="mt-7 flex flex-wrap gap-3">
                            <a
                                href="#mudar"
                                class="inline-flex h-11 items-center rounded-full bg-accent-400 px-5 text-sm font-semibold text-brand-950 focus-ring-inverted transition hover:bg-accent-300"
                                >Mudar agora</a
                            >
                            <a
                                href="#perguntas"
                                class="inline-flex h-11 items-center rounded-full px-5 text-sm font-semibold text-white ring-1 ring-white/20 focus-ring-inverted transition ring-inset hover:bg-white/5"
                                >O que muda para mim?</a
                            >
                        </div>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------------- moments -->
            <section
                id="momentos"
                class="mx-auto max-w-6xl px-5 pt-(--space-section) sm:px-8"
            >
                <div class="max-w-2xl">
                    <h2 class="font-display text-section text-balance">
                        Feito para o que acontece num dia de trabalho
                    </h2>
                    <p class="mt-4 text-lg text-brand-600 dark:text-zinc-400">
                        Escolha uma situação. Ao lado está o que acontece aqui,
                        e o documento que fica.
                    </p>
                </div>

                <div
                    class="mt-12 grid items-start gap-8 lg:grid-cols-2 lg:gap-14"
                >
                    <div
                        role="tablist"
                        aria-label="Escolher um momento do negócio"
                        aria-orientation="vertical"
                        class="border-t border-zinc-900/10 dark:border-white/10"
                    >
                        <button
                            v-for="(moment, index) in moments"
                            :id="`momento-${index}`"
                            :key="moment.tag"
                            type="button"
                            role="tab"
                            :aria-selected="index === selectedMomentIndex"
                            aria-controls="momento-detalhe"
                            :tabindex="index === selectedMomentIndex ? 0 : -1"
                            class="flex w-full items-center justify-between gap-4 border-b border-zinc-900/10 px-1 py-4 text-left text-base/6 focus-ring transition dark:border-white/10"
                            :class="
                                index === selectedMomentIndex
                                    ? 'font-semibold text-brand-950 dark:text-white'
                                    : 'text-brand-600 hover:text-brand-950 dark:text-zinc-400 dark:hover:text-white'
                            "
                            @click="selectedMomentIndex = index"
                            @keydown="moveMoment($event, index)"
                        >
                            <span>{{ moment.situation }}</span>
                            <span
                                class="shrink-0 font-mono text-xs font-normal"
                                :class="
                                    index === selectedMomentIndex
                                        ? 'text-accent-700 dark:text-accent-400'
                                        : 'text-brand-500 dark:text-zinc-400'
                                "
                                >{{ moment.tag }}</span
                            >
                        </button>
                    </div>

                    <div
                        id="momento-detalhe"
                        role="tabpanel"
                        :aria-labelledby="`momento-${selectedMomentIndex}`"
                        class="rounded-3xl bg-brand-50 p-6 sm:p-8 lg:sticky lg:top-24 dark:bg-white/5"
                    >
                        <p
                            class="font-display text-2xl/snug tracking-[-0.02em] text-balance sm:text-[1.75rem]/snug"
                        >
                            {{ selectedMoment.answer }}
                        </p>
                        <div
                            class="mt-6 rounded-2xl bg-white p-5 shadow-[0_1px_2px_rgb(23_23_22/0.05),0_10px_24px_-14px_rgb(23_23_22/0.25)] dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <span
                                    class="text-[0.7rem] font-medium tracking-[0.07em] text-brand-500 uppercase dark:text-zinc-400"
                                    >{{ selectedMoment.proof.eyebrow }}</span
                                >
                                <span
                                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        proofToneClasses(
                                            selectedMoment.proof.tone,
                                        )
                                    "
                                    >{{ selectedMoment.proof.status }}</span
                                >
                            </div>
                            <p class="mt-2 text-lg font-semibold">
                                {{ selectedMoment.proof.title }}
                            </p>
                            <dl class="mt-3">
                                <div
                                    v-for="row in selectedMoment.proof.rows"
                                    :key="row.label"
                                    class="flex justify-between gap-4 border-t border-zinc-900/5 py-2.5 text-sm dark:border-white/10"
                                >
                                    <dt
                                        class="text-brand-500 dark:text-zinc-400"
                                    >
                                        {{ row.label }}
                                    </dt>
                                    <dd
                                        class="text-right font-medium"
                                        :class="{ numeric: row.numeric }"
                                    >
                                        {{ row.value }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <p
                            class="mt-4 text-sm text-brand-600 dark:text-zinc-400"
                        >
                            {{ selectedMoment.proof.note }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------ small business -->
            <section
                id="pequenos"
                class="mx-auto grid max-w-6xl items-center gap-14 px-5 pt-(--space-section) sm:px-8 lg:grid-cols-[minmax(0,1fr)_auto] lg:gap-20"
            >
                <div v-reveal>
                    <h2 class="font-display text-section text-balance">
                        Comece pelo telemóvel que já tem no bolso
                    </h2>
                    <p class="mt-4 text-lg text-brand-600 dark:text-zinc-400">
                        A primeira coisa que vê de manhã é quanto recebeu. A
                        segunda é um botão para facturar.
                    </p>
                    <ul
                        class="mt-8 border-t border-zinc-900/10 dark:border-white/10"
                    >
                        <li
                            v-for="promise in forSmallBusiness"
                            :key="promise"
                            class="flex gap-4 border-b border-zinc-900/10 py-4 text-base dark:border-white/10"
                        >
                            <span
                                class="mt-2 size-2 shrink-0 rounded-full bg-accent-400"
                                aria-hidden="true"
                            />
                            {{ promise }}
                        </li>
                    </ul>
                </div>

                <!-- Example data only: the phone illustrates, it does not report. -->
                <div
                    v-reveal="1"
                    class="relative mx-auto aspect-[390/800] w-72 overflow-hidden rounded-[2.75rem] bg-white text-[0.78rem] text-brand-950 shadow-[0_0_0_9px_#1c1c1b,0_0_0_10px_#3a3a37,0_50px_80px_-40px_rgb(0_0_0/0.5)] dark:bg-zinc-950 dark:text-white"
                    role="img"
                    aria-label="Exemplo do ecrã inicial no telemóvel: recebido este mês e facturas por receber"
                >
                    <span
                        class="absolute top-2.5 left-1/2 h-7 w-24 -translate-x-1/2 rounded-full bg-black"
                    />
                    <p class="px-6 pt-4 text-xs font-semibold">9:41</p>
                    <div class="px-4 pt-4">
                        <p
                            class="text-[0.58rem] font-medium tracking-[0.07em] text-brand-400 uppercase"
                        >
                            Loja Kianda · outubro
                        </p>
                        <p class="mt-1 text-xl tracking-[-0.02em]">
                            Olá, Joana
                        </p>
                        <div
                            class="mt-3 rounded-2xl bg-brand-50 p-3.5 dark:bg-white/5"
                        >
                            <p
                                class="text-[0.58rem] font-medium tracking-[0.07em] text-brand-400 uppercase"
                            >
                                Recebido este mês
                            </p>
                            <p
                                class="mt-2 flex items-baseline gap-1.5 numeric text-3xl tracking-[-0.04em]"
                            >
                                845 000<span
                                    class="text-xs tracking-normal text-brand-400"
                                    >Kz</span
                                >
                            </p>
                            <div
                                class="mt-3 h-1 overflow-hidden rounded bg-zinc-200 dark:bg-white/10"
                            >
                                <div
                                    class="h-full w-[73%] bg-brand-950 dark:bg-white"
                                />
                            </div>
                            <p
                                class="mt-2 text-[0.66rem] text-brand-600 dark:text-zinc-400"
                            >
                                Faltam receber <b>312 000 Kz</b> de 3 clientes
                            </p>
                        </div>
                        <p
                            class="mt-3 grid h-11 place-items-center rounded-2xl bg-accent-400 text-sm font-semibold text-brand-950"
                        >
                            + Nova factura
                        </p>
                        <div
                            v-for="row in exampleReceivables"
                            :key="row.initials"
                            class="grid grid-cols-[1.75rem_minmax(0,1fr)_auto] items-center gap-2.5 border-b border-zinc-900/5 py-2.5 last:border-b-0 dark:border-white/10"
                        >
                            <span
                                class="grid size-7 place-items-center rounded-full text-[0.55rem] font-semibold"
                                :class="row.tone"
                                >{{ row.initials }}</span
                            >
                            <span class="min-w-0">
                                <b class="block truncate text-[0.72rem]">{{
                                    row.customer
                                }}</b>
                                <span class="text-[0.62rem] text-brand-400">{{
                                    row.when
                                }}</span>
                            </span>
                            <span
                                class="text-right numeric text-[0.72rem] font-semibold"
                                >{{ row.amount }}
                                <span
                                    class="block text-[0.6rem] text-accent-700 dark:text-accent-400"
                                    >Lembrar</span
                                ></span
                            >
                        </div>
                    </div>
                </div>
            </section>

            <!-- ----------------------------------------------------- switching -->
            <section id="mudar" class="px-5 pt-(--space-section) sm:px-8">
                <div
                    v-reveal
                    class="mx-auto max-w-6xl rounded-[2rem] bg-accent-400 p-8 text-brand-950 [--wordmark-dot:var(--color-brand-950)] sm:p-12 lg:p-16"
                >
                    <div class="flex flex-wrap items-end justify-between gap-6">
                        <h2
                            class="font-display text-[length:clamp(2.75rem,0.5rem+11vw,6.5rem)] leading-[0.9] tracking-[-0.04em] whitespace-nowrap"
                        >
                            <span aria-hidden="true"
                                >migrac<span class="brand-dot" />ao</span
                            ><span class="sr-only">Migração</span>
                        </h2>
                        <p class="max-w-sm text-lg/7 text-accent-950">
                            Traga o que já tem. Clientes e artigos mudam
                            consigo, e vê tudo antes de gravar.
                        </p>
                    </div>
                    <ol class="mt-10 grid gap-4 md:grid-cols-3">
                        <li
                            v-for="(step, index) in switchingSteps"
                            :key="step.title"
                            class="grid content-start gap-2 rounded-2xl bg-white/55 p-5"
                        >
                            <span class="font-mono text-xs text-accent-900">{{
                                index + 1
                            }}</span>
                            <span class="text-lg font-semibold">{{
                                step.title
                            }}</span>
                            <span class="text-[0.95rem]/6 text-accent-950">{{
                                step.detail
                            }}</span>
                        </li>
                    </ol>
                    <Link
                        :href="register.url()"
                        class="mt-8 inline-flex h-13 items-center gap-2 rounded-full bg-brand-950 px-6 text-base font-semibold text-white focus-ring transition hover:bg-brand-800"
                    >
                        Começar a mudança
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </section>

            <!-- ------------------------------------------------------ included -->
            <section
                id="incluido"
                class="mx-auto max-w-6xl px-5 pt-(--space-section) sm:px-8"
            >
                <h2
                    v-reveal
                    class="max-w-3xl font-display text-section text-balance"
                >
                    Tudo o que a AGT pede, e o resto que o seu negócio precisa
                </h2>
                <div class="mt-12 grid gap-10 lg:grid-cols-3">
                    <div
                        v-for="(group, index) in included"
                        :key="group.group"
                        v-reveal="index"
                    >
                        <h3
                            class="border-b border-brand-950 pb-3 text-xs font-semibold tracking-[0.07em] text-brand-500 uppercase dark:border-white dark:text-zinc-400"
                        >
                            {{ group.group }}
                        </h3>
                        <ul>
                            <li
                                v-for="item in group.items"
                                :key="item"
                                class="border-b border-zinc-900/10 py-3 dark:border-white/10"
                            >
                                {{ item }}
                            </li>
                        </ul>
                    </div>
                    <div v-reveal="2">
                        <h3
                            class="border-b border-brand-950 pb-3 text-xs font-semibold tracking-[0.07em] text-brand-500 uppercase dark:border-white dark:text-zinc-400"
                        >
                            Fiscal e confiança
                        </h3>
                        <dl>
                            <div
                                v-for="row in obligations"
                                :key="row.term"
                                class="border-b border-zinc-900/10 py-3 dark:border-white/10"
                            >
                                <dt>{{ row.term }}</dt>
                                <dd
                                    class="mt-0.5 text-sm text-brand-500 dark:text-zinc-400"
                                >
                                    {{ row.value }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>

            <!-- --------------------------------------------------------- plans -->
            <section
                id="precos"
                class="mx-auto max-w-6xl px-5 pt-(--space-section) sm:px-8"
            >
                <div class="max-w-2xl">
                    <h2 class="font-display text-section text-balance">
                        Experimente primeiro. Decida depois.
                    </h2>
                    <p class="mt-4 text-lg text-brand-600 dark:text-zinc-400">
                        Abra a conta, configure a empresa e emita à vontade em
                        ambiente de testes. Só passa a produção quando estiver
                        pronto.
                    </p>
                </div>

                <!--
                    Rendered from the plans table. With none published the page
                    says what is true about opening an account instead, rather
                    than showing a price nobody agreed to charge.
                -->
                <div
                    v-if="plans.length > 0"
                    class="mt-12 grid gap-4 md:grid-cols-2 lg:grid-cols-3"
                >
                    <article
                        v-for="(plan, index) in plans"
                        :key="plan.name"
                        v-reveal="index"
                        class="flex flex-col rounded-3xl bg-brand-50 p-7 dark:bg-white/5"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">{{ plan.name }}</h3>
                            <span
                                v-if="plan.trial_days > 0"
                                class="rounded-full bg-lime-300 px-2.5 py-1 text-xs font-semibold text-lime-950"
                                >{{ plan.trial_days }} dias grátis</span
                            >
                        </div>
                        <p class="mt-5 flex items-baseline gap-2">
                            <span
                                class="font-display numeric text-[2.6rem] leading-none tracking-[-0.03em]"
                                >{{ plan.amount }}</span
                            >
                            <span
                                class="text-sm font-medium text-brand-400 dark:text-zinc-500"
                                >{{ plan.currency }} / {{ plan.interval }}</span
                            >
                        </p>
                        <p
                            v-if="plan.summary"
                            class="mt-3 text-[0.95rem] text-brand-600 dark:text-zinc-400"
                        >
                            {{ plan.summary }}
                        </p>
                        <ul
                            v-if="plan.features.length > 0"
                            class="mt-5 grid gap-2 text-[0.95rem] text-brand-700 dark:text-zinc-300"
                        >
                            <li
                                v-for="feature in plan.features"
                                :key="feature"
                                class="flex gap-3"
                            >
                                <span
                                    class="mt-2.5 size-1.5 shrink-0 rounded-full bg-accent-400"
                                    aria-hidden="true"
                                />
                                {{ feature }}
                            </li>
                        </ul>
                        <div class="mt-auto pt-7">
                            <Link
                                :href="register.url()"
                                class="flex h-11 items-center justify-center rounded-full bg-brand-950 px-5 text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 dark:bg-white dark:text-brand-950 dark:hover:bg-zinc-200"
                            >
                                Escolher {{ plan.name }}
                            </Link>
                        </div>
                    </article>
                </div>

                <dl v-else v-reveal class="mt-12 grid gap-4 md:grid-cols-3">
                    <div
                        v-for="assurance in openingAnAccount"
                        :key="assurance.term"
                        class="rounded-3xl bg-brand-50 p-7 dark:bg-white/5"
                    >
                        <dt class="font-display text-2xl tracking-[-0.02em]">
                            {{ assurance.term
                            }}<span class="brand-dot" aria-hidden="true" />
                        </dt>
                        <dd
                            class="mt-2 text-[0.95rem] text-brand-600 dark:text-zinc-400"
                        >
                            {{ assurance.value }}
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- ----------------------------------------------------- questions -->
            <section
                id="perguntas"
                class="mx-auto grid max-w-6xl gap-10 px-5 pt-(--space-section) sm:px-8 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:gap-16"
            >
                <h2 class="font-display text-section text-balance">
                    O que as pessoas perguntam antes de mudar
                </h2>
                <div
                    class="faq border-t border-zinc-900/10 dark:border-white/10"
                >
                    <details
                        v-for="(item, index) in questions"
                        :key="item.question"
                        class="group border-b border-zinc-900/10 dark:border-white/10"
                        :open="index === 0"
                    >
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-6 py-5 text-lg font-semibold focus-ring [&::-webkit-details-marker]:hidden"
                        >
                            {{ item.question }}
                            <span
                                class="size-2.5 shrink-0 rotate-45 border-r-2 border-b-2 border-brand-400 transition group-open:-rotate-135 motion-reduce:transition-none"
                                aria-hidden="true"
                            />
                        </summary>
                        <p
                            class="max-w-2xl pb-6 text-brand-600 dark:text-zinc-400"
                        >
                            {{ item.answer }}
                        </p>
                    </details>
                </div>
            </section>

            <!-- --------------------------------------------------------- close -->
            <section
                class="mx-auto flex max-w-6xl flex-col items-center px-5 pt-28 pb-20 text-center sm:px-8 lg:pt-40"
            >
                <BrandWordmark
                    ref="closingWordmark"
                    animated
                    title="facturação transforma-se em facturac.ao"
                    class="h-auto w-full max-w-2xl text-brand-950 dark:text-white"
                />
                <p
                    class="mt-8 font-display text-xl tracking-[-0.02em] sm:text-2xl"
                >
                    Tire o til. Tire a cedilha. Ponha um ponto.
                    <span class="text-brand-500 dark:text-zinc-400"
                        >É só isso.</span
                    >
                </p>
                <Link
                    :href="register.url()"
                    class="mt-8 inline-flex h-13 items-center rounded-full bg-accent-400 px-7 text-base font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1)] focus-ring transition hover:bg-accent-300"
                >
                    Abrir conta grátis
                </Link>
                <button
                    type="button"
                    class="mt-4 inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm text-brand-500 focus-ring transition hover:text-brand-950 dark:text-zinc-400 dark:hover:text-white"
                    @click="closingWordmark?.replay()"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" />
                    Ver outra vez
                </button>
            </section>
        </main>

        <footer
            class="mx-auto max-w-6xl border-t border-zinc-900/10 px-5 py-12 sm:px-8 dark:border-white/10"
        >
            <div
                class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr]"
            >
                <div>
                    <BrandWordmark
                        class="h-7 w-auto text-brand-950 dark:text-white"
                    />
                    <p
                        class="mt-4 font-display text-lg tracking-[-0.02em] text-brand-600 dark:text-zinc-400"
                    >
                        Emitida<span class="brand-dot" aria-hidden="true" />
                        Validada<span class="brand-dot" aria-hidden="true" />
                        Paga<span class="brand-dot" aria-hidden="true" />
                    </p>
                    <p
                        v-if="contact.company"
                        class="mt-4 text-sm text-brand-400 dark:text-zinc-500"
                    >
                        {{ contact.company }}
                    </p>
                </div>
                <nav aria-label="Produto">
                    <p
                        class="text-xs font-medium tracking-[0.07em] text-brand-500 uppercase dark:text-zinc-400"
                    >
                        Produto
                    </p>
                    <ul class="mt-3 grid gap-2 text-[0.95rem]">
                        <li v-for="section in sections" :key="section.href">
                            <a
                                :href="section.href"
                                class="rounded text-brand-600 focus-ring transition hover:text-brand-950 dark:text-zinc-400 dark:hover:text-white"
                                >{{ section.label }}</a
                            >
                        </li>
                    </ul>
                </nav>
                <nav aria-label="Legal">
                    <p
                        class="text-xs font-medium tracking-[0.07em] text-brand-500 uppercase dark:text-zinc-400"
                    >
                        Legal
                    </p>
                    <ul class="mt-3 grid gap-2 text-[0.95rem]">
                        <li>
                            <Link
                                :href="legalShow.url('termos')"
                                class="rounded text-brand-600 focus-ring transition hover:text-brand-950 dark:text-zinc-400 dark:hover:text-white"
                                >Termos</Link
                            >
                        </li>
                        <li>
                            <Link
                                :href="legalShow.url('privacidade')"
                                class="rounded text-brand-600 focus-ring transition hover:text-brand-950 dark:text-zinc-400 dark:hover:text-white"
                                >Privacidade</Link
                            >
                        </li>
                        <li>
                            <Link
                                :href="legalShow.url('cookies')"
                                class="rounded text-brand-600 focus-ring transition hover:text-brand-950 dark:text-zinc-400 dark:hover:text-white"
                                >Cookies</Link
                            >
                        </li>
                        <li v-if="contact.support_email">
                            <a
                                :href="`mailto:${contact.support_email}`"
                                class="rounded text-brand-600 focus-ring transition hover:text-brand-950 dark:text-zinc-400 dark:hover:text-white"
                                >{{ contact.support_email }}</a
                            >
                        </li>
                    </ul>
                </nav>
            </div>
        </footer>

        <CookieConsent />
    </div>
</template>
