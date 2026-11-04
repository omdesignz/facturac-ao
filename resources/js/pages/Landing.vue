<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check, ChevronDown, Menu, RotateCcw, X } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import PeriodSwitch from '@/components/billing/PeriodSwitch.vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import CookieConsent from '@/components/CookieConsent.vue';
import DevicePhone from '@/components/DevicePhone.vue';
import { initialPeriod, periodOptions } from '@/lib/packages';
import { currencyLabel } from '@/lib/pos-ui';
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
    /** Every figure arrives formatted by the server; the page only chooses which to show. */
    packages: {
        name: string;
        summary: string | null;
        features: string[];
        trial_days: number;
        is_featured: boolean;
        prices: {
            interval: string;
            interval_label: string;
            period_label: string;
            months: number;
            amount: string;
            currency: string;
            monthly_equivalent: string;
            saving_percentage: number | null;
        }[];
    }[];
    trialDays: number;
    provinceCount: number;
    contact: { support_email: string | null; company: string | null };
}>();

const menuOpen = ref(false);

/*
 * Packages are priced for one payment period at a time. Only the periods some
 * package sells are offered, starting on monthly (or the first on offer).
 */
const periods = computed(() =>
    periodOptions(
        props.packages.flatMap((offer) =>
            offer.prices.map((price) => ({
                interval: price.interval,
                interval_label: price.interval_label,
                months: price.months,
                saving_percentage: price.saving_percentage,
            })),
        ),
    ),
);
const chosenPeriod = ref(initialPeriod(periods.value, null) ?? '');
const activePeriod = computed(() =>
    periods.value.some((option) => option.value === chosenPeriod.value)
        ? chosenPeriod.value
        : (initialPeriod(periods.value, null) ?? ''),
);
const periodAnnouncement = ref('');

function choosePeriod(value: string): void {
    chosenPeriod.value = value;

    const option = periods.value.find((candidate) => candidate.value === value);

    periodAnnouncement.value = option ? `Preços: ${option.label}.` : '';
}

/**
 * Packages are usually sold as steps: each one includes everything the one
 * before it does. When that holds, a card names the package it builds on and
 * lists only what it adds, so the difference is the first thing a visitor
 * reads. A package that is not a superset keeps its whole list.
 */
const packageCards = computed(() =>
    props.packages.map((offer, index) => {
        const previous = index > 0 ? props.packages[index - 1]! : null;
        const buildsOnPrevious =
            previous !== null &&
            previous.features.length > 0 &&
            previous.features.every((feature) =>
                offer.features.includes(feature),
            );

        return {
            offer,
            price:
                offer.prices.find(
                    (candidate) => candidate.interval === activePeriod.value,
                ) ?? null,
            otherPeriods: offer.prices
                .map((candidate) => candidate.interval_label.toLowerCase())
                .join(', '),
            buildsOn: buildsOnPrevious ? previous.name : null,
            features: buildsOnPrevious
                ? offer.features.filter(
                      (feature) => !previous.features.includes(feature),
                  )
                : offer.features,
        };
    }),
);

/** Every feature on sale, in the order it is first offered, ticked per package. */
const comparison = computed(() =>
    [...new Set(props.packages.flatMap((offer) => offer.features))].map(
        (feature) => ({
            feature,
            included: props.packages.map((offer) =>
                offer.features.includes(feature),
            ),
        }),
    ),
);

/** Only worth a table when there is more than one package and they differ. */
const showComparison = computed(
    () =>
        props.packages.length > 1 &&
        comparison.value.some((row) => row.included.includes(false)),
);

/** What holds for every package, said once above the cards. */
const pricingTerms: { term: string; value: string }[] = [
    {
        term: 'Sem cartão para começar',
        value: 'Abre a conta e experimenta sem dar dados de pagamento.',
    },
    {
        term: 'Documentos sem limite',
        value: 'O preço é por espaço de trabalho, não por documento emitido.',
    },
    {
        term: 'Arquivo sempre disponível',
        value: 'A consulta dos documentos e o SAF-T ficam disponíveis após o fim da assinatura.',
    },
];
const menuButton = ref<HTMLButtonElement | null>(null);
const selectedMomentIndex = ref(0);
const closingWordmark = ref<InstanceType<typeof BrandWordmark> | null>(null);

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

onMounted(() => window.addEventListener('keydown', closeOnEscape));
onUnmounted(() => window.removeEventListener('keydown', closeOnEscape));

const sections = [
    { href: '#momentos', label: 'Como funciona' },
    { href: '#mudar', label: 'Mudar de programa' },
    { href: '#precos', label: 'Preços' },
    { href: '#perguntas', label: 'Perguntas' },
];

/**
 * The footer lists every door the header does, plus the counter. The header
 * stays at four links; the footer is where someone looking for the till goes.
 */
const footerSections = [
    sections[0]!,
    { href: '#balcao', label: 'Ponto de venda' },
    ...sections.slice(1),
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
        note?: string;
    };
}[] = [
    {
        tag: 'FR',
        situation: 'O cliente está à sua frente e pede a factura.',
        answer: 'Passa a factura-recibo no telemóvel. O cliente recebe o documento com número, assinatura e QR.',
        proof: {
            eyebrow: 'Documento emitido',
            title: 'FR LOJA2026/00184',
            status: 'Validada pela AGT',
            tone: 'valid',
            rows: [
                { label: 'Cliente', value: 'Mercearia Kilamba' },
                { label: 'Total', value: '139 080,00 Kz', numeric: true },
                { label: 'Pagamento', value: 'Multicaixa' },
            ],
        },
    },
    {
        tag: 'PDV',
        situation: 'Tem fila ao balcão e cada cliente quer o talão.',
        answer: 'Toca no artigo ou lê o código de barras. A venda sai como factura-recibo, com o troco calculado.',
        proof: {
            eyebrow: 'Venda ao balcão',
            title: 'FR LOJA2026/00185',
            status: 'Comunicada à AGT',
            tone: 'valid',
            rows: [
                { label: 'Cliente', value: 'Consumidor final' },
                { label: 'Total', value: '139 080,00 Kz', numeric: true },
                { label: 'Pagamento', value: 'Numerário' },
                { label: 'Troco', value: '10 920,00 Kz', numeric: true },
            ],
            note: 'O talão imprime numa impressora térmica de 80 mm.',
        },
    },
    {
        tag: 'Conta',
        situation: 'Vendeu fiado e já não sabe ao certo quem lhe deve.',
        answer: 'Cada cliente tem conta corrente com o que comprou, o que pagou e o que ainda deve.',
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
        },
    },
    {
        tag: 'Rede',
        situation: 'A internet foi-se abaixo a meio da manhã.',
        answer: 'Continua a facturar. Os documentos seguem para a AGT quando a ligação voltar.',
        proof: {
            eyebrow: 'Por comunicar à AGT',
            title: '3 documentos',
            status: 'À espera de ligação',
            tone: 'neutral',
            rows: [
                { label: 'Assinados e guardados', value: '3', numeric: true },
                { label: 'Próxima tentativa', value: 'Automática' },
            ],
        },
    },
    {
        tag: 'NC',
        situation: 'Enganou-se no valor de uma factura já emitida.',
        answer: 'Emite uma nota de crédito ligada à factura original, com o motivo da correcção.',
        proof: {
            eyebrow: 'Nota de crédito',
            title: 'NC LOJA2026/00012',
            status: 'Ligada à original',
            tone: 'neutral',
            rows: [
                { label: 'Documento de origem', value: 'FT LOJA2026/00179' },
                { label: 'Motivo', value: 'Valor facturado a mais' },
                { label: 'Ajuste', value: '−12 400,00 Kz', numeric: true },
            ],
        },
    },
    {
        tag: 'SAF-T',
        situation: 'O contabilista pediu o ficheiro do trimestre.',
        answer: 'Escolhe as datas e descarrega o ficheiro SAF-T (AO).',
        proof: {
            eyebrow: 'Exportação SAF-T',
            title: '2.º trimestre de 2026',
            status: 'Ficheiro validado',
            tone: 'valid',
            rows: [
                { label: 'Período', value: '01 abr a 30 jun' },
                { label: 'Versão', value: 'SAF-T (AO) 1.01_01' },
                { label: 'Documentos', value: '184', numeric: true },
            ],
        },
    },
    {
        tag: 'Estado',
        situation: 'Vendeu ao Estado e retiveram-lhe parte do imposto.',
        answer: 'A retenção na fonte e o IVA cativo ficam registados no documento, cada um na sua base.',
        proof: {
            eyebrow: 'Venda ao Estado',
            title: 'Impostos no documento',
            status: 'Bases separadas',
            tone: 'neutral',
            rows: [
                { label: 'IVA cativo', value: 'Apurado no documento' },
                { label: 'Retenção', value: 'Indicada à parte' },
                { label: 'Líquido a receber', value: 'Calculado no documento' },
            ],
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
    `Em português e em kwanzas, com as ${props.provinceCount} províncias.`,
    'Vários estabelecimentos na mesma conta.',
    'Facturas noutras moedas, com o câmbio indicado.',
]);

/** What the till promises, in the order a sale happens. */
const atTheCounter: string[] = [
    'Toque no artigo ou leia o código de barras.',
    'Numerário com troco calculado, Multicaixa ou transferência.',
    'O stock desce sozinho a cada venda.',
    'Abre e fecha a caixa com a contagem do dinheiro.',
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
        value: 'Faça testes sem consumir numeração. Passa a produção quando decidir.',
    },
    {
        term: 'Exportação dos dados',
        value: 'Exporte os documentos e os clientes em ficheiros abertos.',
    },
];

/** Stated plainly and without adjectives: this part is not selling. */
const obligations: { term: string; value: string }[] = [
    {
        term: 'Comunicação à AGT',
        value: 'Cada documento é assinado e submetido, e a resposta fica guardada.',
    },
    {
        term: 'SAF-T (AO) 1.01_01',
        value: 'O ficheiro de auditoria do período, quando precisar.',
    },
    {
        term: 'Conservação legal',
        value: 'Os documentos ficam guardados durante o prazo legal.',
    },
    {
        term: 'Exportação dos dados',
        value: 'Exporte tudo em ficheiros abertos, quando quiser.',
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
            'Envio dos documentos por email ao cliente',
        ],
    },
    {
        group: 'Gestão',
        items: [
            'Ponto de venda com abertura e fecho de caixa',
            'Clientes com prazos e limite de crédito',
            'Tabelas de preços e preços por cliente',
            'Artigos, inventário e movimentos de stock',
            'Vários estabelecimentos, cada um com a sua série',
            'Extractos, dívidas e análise de vendas',
        ],
    },
];

/**
 * The AGT certificate, quoted exactly as issued (certificate of 28/10/2026 for
 * facturac.ao version 1.1.0). Nothing here goes beyond what it states.
 */
const certification = {
    number: 'FE/419/AGT/2026',
    version: '1.1.0',
    issuedOn: '28/10/2026',
};

/** Moving over is a real sequence, so it is the one place numbers are used. */
const switchingSteps: { title: string; detail: string }[] = [
    {
        title: 'Exporte do programa actual',
        detail: 'O Excel ou o CSV que já usa, ou a exportação de outra aplicação.',
    },
    {
        title: 'Carregue e confira',
        detail: 'Vê o que vai entrar e o que precisa de revisão antes de gravar.',
    },
    {
        title: 'Confirme',
        detail: 'Clientes e artigos ficam prontos para facturar.',
    },
];

const questions: { question: string; answer: string }[] = [
    {
        question: 'O que muda a 1 de janeiro de 2027?',
        answer: 'As empresas do Regime Geral e do Regime Simplificado do IVA passam a ter de emitir factura electrónica, assinada e comunicada à AGT. Os grandes contribuintes e quem factura ao Estado já o fazem desde janeiro de 2026.',
    },
    {
        question: 'Preciso de contabilista para começar?',
        answer: 'Não. Cria a conta e preenche o NIF e os dados da empresa. O IVA, as retenções e a comunicação à AGT são tratados pela aplicação. Quando o contabilista precisar, gera o SAF-T do período.',
    },
    {
        question: 'E se a internet falhar?',
        answer: 'Continua a facturar. Os documentos ficam assinados e seguem para a AGT quando a ligação voltar.',
    },
    {
        question: 'Enganei-me numa factura já emitida. E agora?',
        answer: 'Emite uma nota de crédito ligada à factura original, com o motivo. Uma factura emitida não se apaga, por lei.',
    },
    {
        question: 'Como se escreve o vosso nome?',
        answer: 'Tire o til e a cedilha a "facturação" e ponha um ponto antes do "ao": facturac.ao.',
    },
];
</script>

<template>
    <Head>
        <title>facturac.ao: facturação electrónica certificada pela AGT</title>
        <meta
            name="description"
            content="Software de facturação certificado pela AGT: facturas, recibos, ponto de venda, SAF-T e contas correntes. A factura electrónica é obrigatória a 1 de janeiro de 2027."
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
                        <span class="sm:hidden">Abrir conta</span>
                        <span class="hidden sm:inline">Abrir conta grátis</span>
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
            <section id="topo" class="overflow-clip">
                <div
                    class="mx-auto max-w-6xl px-5 pt-12 text-center sm:px-8 lg:pt-14"
                >
                    <h1
                        class="font-display text-[length:clamp(2.75rem,1.45rem+6vw,6rem)] leading-[1.04] font-bold tracking-[-0.018em] text-balance [font-variant-ligatures:none]"
                    >
                        Facture com software certificado pela AGT
                    </h1>
                    <p
                        class="mx-auto mt-6 max-w-2xl text-lg/7 text-pretty text-brand-600 sm:text-xl/8 dark:text-zinc-400"
                    >
                        Facturas, recibos e ponto de venda, no telemóvel ou no
                        computador. Cada documento sai assinado e é comunicado à
                        AGT.
                    </p>
                    <div class="mt-8 flex flex-wrap justify-center gap-3">
                        <Link
                            :href="register.url()"
                            class="inline-flex h-13 items-center rounded-full bg-brand-950 px-7 text-base font-semibold text-white focus-ring transition hover:bg-brand-800 dark:bg-white dark:text-brand-950 dark:hover:bg-zinc-200"
                        >
                            Abrir conta grátis
                        </Link>
                        <a
                            href="#precos"
                            class="inline-flex h-13 items-center rounded-full px-7 text-base font-semibold text-brand-950 ring-1 ring-zinc-900/15 focus-ring transition ring-inset hover:bg-brand-50 dark:text-white dark:ring-white/20 dark:hover:bg-white/5"
                        >
                            Ver preços
                        </a>
                    </div>
                    <p class="mt-4 text-sm text-brand-500 dark:text-zinc-400">
                        {{ trialDays }} dias grátis, sem cartão.
                    </p>
                </div>

                <!--
                    The dot from the wordmark, rising behind the real product.
                    The one bold thing on the page: everything below stays quiet.
                -->
                <div class="relative mt-14">
                    <div class="hero-sun" aria-hidden="true" />
                    <div class="relative mx-auto max-w-6xl px-5 pt-12 sm:px-8">
                        <div
                            class="hero-window hidden aspect-[16/8.6] overflow-hidden rounded-t-2xl bg-white shadow-[0_0_0_1px_rgb(23_23_22/0.12),0_40px_80px_-30px_rgb(92_54_0/0.55)] sm:block"
                        >
                            <picture>
                                <source
                                    media="(min-width: 40rem)"
                                    srcset="
                                        /images/landing/documents-1440.webp 1440w,
                                        /images/landing/documents-2880.webp 2880w
                                    "
                                    sizes="(min-width: 72rem) 68rem, 100vw"
                                />
                                <img
                                    src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="
                                    width="1440"
                                    height="900"
                                    fetchpriority="high"
                                    class="block h-auto w-full"
                                    alt="O ecrã Documentos da aplicação: a lista de facturas validadas e, ao lado, os detalhes de uma factura com o seu estado na AGT."
                                />
                            </picture>
                        </div>
                        <div
                            class="hero-phone mx-auto w-64 pb-10 sm:absolute sm:right-[4%] sm:bottom-6 sm:w-[21%] sm:pb-0"
                        >
                            <DevicePhone
                                kind="iphone"
                                src="/images/landing/iphone-today.webp"
                                :width="786"
                                :height="1554"
                                time="18:54"
                                loading="eager"
                                fetchpriority="high"
                                alt="O ecrã Hoje num iPhone, com o total facturado no dia, o valor por receber e a barra de navegação da aplicação."
                            />
                        </div>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------- certification -->
            <section
                id="certificacao"
                class="mx-auto flex max-w-6xl flex-col gap-3 px-5 pt-10 text-sm text-brand-600 sm:px-8 lg:flex-row lg:items-baseline lg:justify-between dark:text-zinc-400"
            >
                <h2 class="font-semibold text-brand-950 dark:text-white">
                    Certificado da AGT n.º
                    <span class="numeric">{{ certification.number }}</span
                    >, de {{ certification.issuedOn }}, para a versão
                    {{ certification.version }}.
                </h2>
                <p>Imagens da aplicação com dados de demonstração.</p>
            </section>

            <!-- ------------------------------------------------------ deadline -->
            <section id="prazo" class="px-5 pt-(--space-section) sm:px-8">
                <div
                    class="mx-auto grid max-w-6xl items-end gap-10 rounded-[2rem] bg-brand-950 p-8 text-white sm:p-12 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] lg:p-16 dark:bg-zinc-900 dark:ring-1 dark:ring-white/10"
                >
                    <div>
                        <h2
                            class="font-display text-stamp font-bold whitespace-nowrap"
                        >
                            <span aria-hidden="true"
                                >atenc<span class="brand-dot" />ao</span
                            ><span class="sr-only">Atenção</span>
                        </h2>
                        <p
                            v-if="daysUntilMandatory > 0"
                            class="mt-6 flex items-baseline gap-3"
                        >
                            <span
                                class="font-display numeric text-6xl font-bold tracking-[-0.03em] sm:text-7xl"
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
                                a factura electrónica é obrigatória para todas
                                as empresas do Regime Geral e do Regime
                                Simplificado.
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
                            <Link
                                :href="register.url()"
                                class="inline-flex h-11 items-center rounded-full bg-accent-400 px-5 text-sm font-semibold text-brand-950 focus-ring-inverted transition hover:bg-accent-300"
                                >Abrir conta grátis</Link
                            >
                            <a
                                href="#perguntas"
                                class="inline-flex h-11 items-center rounded-full px-5 text-sm font-semibold text-white ring-1 ring-white/20 focus-ring-inverted transition ring-inset hover:bg-white/5"
                                >O que muda</a
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
                    <h2
                        class="font-display text-section font-bold text-balance"
                    >
                        O que fazer em cada situação
                    </h2>
                </div>

                <div
                    class="mt-12 grid items-start gap-8 lg:grid-cols-2 lg:gap-14"
                >
                    <div
                        role="tablist"
                        aria-label="Escolher um momento do negócio"
                        aria-orientation="vertical"
                        class="border-bs border-zinc-900/10 lg:sticky lg:inset-bs-28 lg:self-start dark:border-white/10"
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
                            class="flex w-full items-center justify-between gap-4 border-be border-zinc-900/10 px-1 py-4 text-start text-base/6 focus-ring transition lg:py-6 dark:border-white/10"
                            :class="
                                index === selectedMomentIndex
                                    ? 'font-semibold text-brand-950 dark:text-white'
                                    : 'text-brand-600 hover:text-brand-950 dark:text-zinc-400 dark:hover:text-white'
                            "
                            @click="selectedMomentIndex = index"
                            @keydown="moveMoment($event, index)"
                        >
                            <span>{{ moment.situation }}</span>
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
                                    class="text-sm text-brand-500 dark:text-zinc-400"
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
                            v-if="selectedMoment.proof.note"
                            class="mt-4 text-sm text-brand-600 dark:text-zinc-400"
                        >
                            {{ selectedMoment.proof.note }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- ---------------------------------------------------------- today -->
            <section
                id="dia"
                class="mx-auto max-w-6xl px-5 pt-(--space-section) sm:px-8"
            >
                <div class="grid gap-4 lg:grid-cols-2 lg:gap-14">
                    <h2
                        class="font-display text-section font-bold text-balance"
                    >
                        O dia do seu negócio num só ecrã
                    </h2>
                    <p
                        class="text-lg/8 text-pretty text-brand-600 dark:text-zinc-400"
                    >
                        Quanto facturou hoje, quem lhe deve e há quanto tempo. O
                        lembrete ao cliente já vem escrito, e só sai quando o
                        confirmar.
                    </p>
                </div>
                <div
                    class="mt-10 overflow-hidden rounded-2xl bg-white shadow-[0_0_0_1px_rgb(23_23_22/0.1),0_30px_60px_-34px_rgb(23_23_22/0.35)]"
                >
                    <img
                        src="/images/landing/today-1440.webp"
                        srcset="
                            /images/landing/today-1440.webp 1440w,
                            /images/landing/today-2880.webp 2880w
                        "
                        sizes="(min-width: 72rem) 68rem, 100vw"
                        width="1440"
                        height="900"
                        loading="lazy"
                        decoding="async"
                        class="block h-auto w-full"
                        alt="O ecrã Hoje da aplicação: total facturado no dia, valor por receber e um lembrete de pagamento pronto a enviar."
                    />
                </div>
            </section>

            <!-- ------------------------------------------------ small business -->
            <section id="pequenos" class="pt-(--space-section)">
                <div class="overflow-clip bg-[#f5f5f7] dark:bg-white/[0.05]">
                    <div
                        class="mx-auto grid max-w-6xl items-center gap-12 px-5 pt-14 sm:px-8 lg:grid-cols-2 lg:gap-14 lg:pt-20"
                    >
                        <div class="lg:pb-20">
                            <h2
                                class="font-display text-section font-bold text-balance"
                            >
                                Comece pelo telemóvel que já tem
                            </h2>
                            <p
                                class="mt-4 text-lg/8 text-pretty text-brand-600 dark:text-zinc-400"
                            >
                                Passe facturas, veja o que tem por receber e
                                lembre os clientes, sem instalar nada e sem
                                contabilista.
                            </p>
                            <ul
                                class="mt-6 grid gap-2 text-base text-brand-800 dark:text-zinc-200"
                            >
                                <li
                                    v-for="promise in forSmallBusiness"
                                    :key="promise"
                                >
                                    {{ promise }}
                                </li>
                            </ul>
                        </div>
                        <div
                            class="flex items-end justify-center gap-4 pb-14 sm:gap-8 lg:pb-20"
                        >
                            <div class="w-[47%] max-w-64">
                                <DevicePhone
                                    kind="iphone"
                                    src="/images/landing/iphone-today.webp"
                                    :width="786"
                                    :height="1554"
                                    time="18:54"
                                    alt="O ecrã Hoje num iPhone, com o total facturado no dia e o valor por receber."
                                />
                            </div>
                            <div class="w-[47%] max-w-[15.5rem]">
                                <DevicePhone
                                    kind="android"
                                    src="/images/landing/android-debts.webp"
                                    :width="824"
                                    :height="1720"
                                    time="18:54"
                                    alt="O ecrã Dívidas num telemóvel Android, com o total por receber e os clientes em atraso."
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------------- counter -->
            <section
                id="balcao"
                class="mx-auto max-w-6xl px-5 pt-(--space-section) sm:px-8"
            >
                <div class="grid gap-6 lg:grid-cols-2 lg:gap-14">
                    <div>
                        <h2
                            class="font-display text-section font-bold text-balance"
                        >
                            Ao balcão, é tocar e cobrar
                        </h2>
                        <p
                            class="mbs-4 text-lg/8 text-pretty text-brand-600 dark:text-zinc-400"
                        >
                            Cada venda sai como factura-recibo, assinada e
                            comunicada à AGT.
                        </p>
                    </div>
                    <div>
                        <ul
                            class="grid gap-2 text-base text-brand-800 dark:text-zinc-200"
                        >
                            <li v-for="promise in atTheCounter" :key="promise">
                                {{ promise }}
                            </li>
                        </ul>
                        <p
                            class="mbs-3 text-sm text-brand-500 dark:text-zinc-400"
                        >
                            O talão sai em impressoras térmicas de 80 mm.
                        </p>
                    </div>
                </div>
                <div
                    class="mt-10 aspect-[1440/790] overflow-hidden rounded-2xl bg-white shadow-[0_0_0_1px_rgb(23_23_22/0.1),0_30px_60px_-34px_rgb(23_23_22/0.35)]"
                >
                    <img
                        src="/images/landing/till-1440.webp"
                        srcset="
                            /images/landing/till-1440.webp 1440w,
                            /images/landing/till-2880.webp 2880w
                        "
                        sizes="(min-width: 72rem) 68rem, 100vw"
                        width="1440"
                        height="900"
                        loading="lazy"
                        decoding="async"
                        class="block h-auto w-full"
                        alt="O ponto de venda da aplicação: os artigos à esquerda e a venda em curso à direita, com o total e o botão para cobrar."
                    />
                </div>
            </section>

            <!-- ----------------------------------------------------- switching -->
            <section id="mudar" class="px-5 pt-(--space-section) sm:px-8">
                <div
                    class="mx-auto max-w-6xl rounded-[2rem] bg-accent-400 p-8 text-brand-950 [--wordmark-dot:var(--color-brand-950)] sm:p-12 lg:p-16"
                >
                    <div class="flex flex-wrap items-end justify-between gap-6">
                        <h2
                            class="font-display text-[length:clamp(2.75rem,0.5rem+11vw,6.5rem)] leading-[0.9] font-bold tracking-[-0.03em] whitespace-nowrap"
                        >
                            <span aria-hidden="true"
                                >migrac<span class="brand-dot" />ao</span
                            ><span class="sr-only">Migração</span>
                        </h2>
                        <p class="max-w-sm text-lg/7 text-accent-950">
                            Clientes e artigos mudam consigo, a partir de Excel
                            ou CSV.
                        </p>
                    </div>
                    <ol class="mt-10 grid gap-4 md:grid-cols-3">
                        <li
                            v-for="(step, index) in switchingSteps"
                            :key="step.title"
                            class="grid content-start gap-2 rounded-2xl bg-white/55 p-5"
                        >
                            <span
                                class="text-sm font-semibold text-accent-900"
                                >{{ index + 1 }}</span
                            >
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
                        Abrir conta grátis
                    </Link>
                </div>
            </section>

            <!-- ------------------------------------------------------ included -->
            <section
                id="incluido"
                class="mx-auto max-w-6xl px-5 pt-(--space-section) sm:px-8"
            >
                <h2
                    class="max-w-3xl font-display text-section font-bold text-balance"
                >
                    O que vem incluído
                </h2>
                <div class="mt-12 grid gap-10 lg:grid-cols-3">
                    <div v-for="group in included" :key="group.group">
                        <h3
                            class="border-b border-brand-950 pb-3 font-semibold dark:border-white"
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
                    <div>
                        <h3
                            class="border-b border-brand-950 pb-3 font-semibold dark:border-white"
                        >
                            Fiscal
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
                <div
                    class="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div class="max-w-2xl">
                        <h2
                            class="font-display text-section font-bold text-balance"
                        >
                            Preços
                        </h2>
                        <p
                            class="mt-4 text-lg text-pretty text-brand-600 dark:text-zinc-400"
                        >
                            Experimente tudo durante {{ trialDays }} dias, sem
                            cartão. Depois, escolha um pacote.
                        </p>
                    </div>
                    <div
                        v-if="packages.length > 0 && periods.length > 1"
                        class="lg:shrink-0"
                    >
                        <PeriodSwitch
                            :model-value="activePeriod"
                            :options="periods"
                            label="Período de pagamento"
                            @update:model-value="choosePeriod"
                        />
                    </div>
                </div>

                <!--
                    The terms that hold for every package, said once here
                    instead of once per card.
                -->
                <dl
                    v-if="packages.length > 0 || plans.length > 0"
                    class="mt-10 grid gap-x-8 gap-y-5 border-y border-zinc-900/10 py-6 sm:grid-cols-3 dark:border-white/10"
                >
                    <div
                        v-for="term in pricingTerms"
                        :key="term.term"
                        class="flex min-w-0 gap-3"
                    >
                        <Check
                            class="mt-0.5 size-4 shrink-0 text-accent-500"
                            aria-hidden="true"
                        />
                        <div class="min-w-0">
                            <dt class="text-[0.95rem] font-semibold">
                                {{ term.term }}
                            </dt>
                            <dd
                                class="mt-0.5 text-sm text-pretty text-brand-600 dark:text-zinc-400"
                            >
                                {{ term.value }}
                            </dd>
                        </div>
                    </div>
                </dl>

                <!--
                    Rendered from the plans table. With none published the page
                    says what is true about opening an account instead, rather
                    than showing a price nobody agreed to charge.
                -->
                <template v-if="packages.length > 0">
                    <p class="sr-only" role="status" aria-live="polite">
                        {{ periodAnnouncement }}
                    </p>

                    <!--
                        Each card is a subgrid of the same five rows (name,
                        price, action, what it includes, trial), so the prices,
                        buttons and lists line up across the row whatever their
                        length. Switching the period moves nothing.
                    -->
                    <div class="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <article
                            v-for="(card, index) in packageCards"
                            :key="card.offer.name"
                            :aria-labelledby="`pacote-${index}`"
                            :class="[
                                card.offer.is_featured
                                    ? 'bg-brand-950 text-white shadow-[0_24px_48px_-24px_rgb(23_23_22/0.45)] dark:bg-white dark:text-brand-950'
                                    : 'bg-brand-50 dark:bg-white/5',
                                'row-span-5 grid min-w-0 grid-rows-subgrid gap-0 rounded-3xl p-7 sm:p-8',
                            ]"
                        >
                            <header>
                                <div
                                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2"
                                >
                                    <h3
                                        :id="`pacote-${index}`"
                                        class="font-display text-2xl tracking-[-0.02em] [overflow-wrap:anywhere]"
                                    >
                                        {{ card.offer.name }}
                                    </h3>
                                    <p
                                        v-if="card.offer.is_featured"
                                        class="rounded-full bg-accent-400 px-2.5 py-0.5 text-xs font-semibold text-brand-950"
                                    >
                                        Recomendado
                                    </p>
                                </div>
                                <p
                                    v-if="card.offer.summary"
                                    :class="[
                                        card.offer.is_featured
                                            ? 'text-white/70 dark:text-brand-600'
                                            : 'text-brand-600 dark:text-zinc-400',
                                        'mt-2 text-[0.95rem] text-pretty',
                                    ]"
                                >
                                    {{ card.offer.summary }}
                                </p>
                            </header>

                            <div class="mt-7">
                                <template v-if="card.price">
                                    <p class="flex items-baseline gap-2">
                                        <span
                                            class="font-display numeric text-[2.75rem] leading-none tracking-[-0.03em] [overflow-wrap:anywhere]"
                                            >{{ card.price.amount }}</span
                                        >
                                        <span
                                            :class="[
                                                card.offer.is_featured
                                                    ? 'text-white/70 dark:text-brand-500'
                                                    : 'text-brand-500 dark:text-zinc-400',
                                                'text-sm font-medium',
                                            ]"
                                            >{{
                                                currencyLabel(
                                                    card.price.currency,
                                                )
                                            }}
                                            {{ card.price.period_label }}</span
                                        >
                                    </p>
                                    <p
                                        :class="[
                                            card.offer.is_featured
                                                ? 'text-white/70 dark:text-brand-600'
                                                : 'text-brand-600 dark:text-zinc-400',
                                            'mt-2.5 flex min-h-6 flex-wrap items-center gap-x-2 gap-y-1 numeric text-sm',
                                        ]"
                                    >
                                        <template v-if="card.price.months > 1">
                                            <!-- prettier-ignore -->
                                            <span>≈ {{ card.price.monthly_equivalent }} {{ currencyLabel(card.price.currency) }} por mês</span>
                                            <span
                                                v-if="
                                                    card.price
                                                        .saving_percentage !==
                                                    null
                                                "
                                                :class="[
                                                    card.offer.is_featured
                                                        ? 'bg-accent-400 text-brand-950'
                                                        : 'bg-accent-100 text-accent-950 dark:bg-accent-400/15 dark:text-accent-300',
                                                    'rounded-full px-2 py-0.5 text-xs font-semibold',
                                                ]"
                                                >Poupa
                                                {{
                                                    card.price
                                                        .saving_percentage
                                                }}%</span
                                            >
                                        </template>
                                    </p>
                                </template>
                                <template v-else>
                                    <p class="text-base font-medium">
                                        Não disponível neste período
                                    </p>
                                    <p
                                        :class="[
                                            card.offer.is_featured
                                                ? 'text-white/70 dark:text-brand-600'
                                                : 'text-brand-600 dark:text-zinc-400',
                                            'mt-1.5 text-sm',
                                        ]"
                                    >
                                        Disponível: {{ card.otherPeriods }}.
                                    </p>
                                </template>
                            </div>

                            <div class="mt-6">
                                <Link
                                    v-if="card.offer.is_featured"
                                    :href="register.url()"
                                    class="flex h-12 items-center justify-center gap-2 rounded-full bg-accent-400 px-5 text-sm font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1)] focus-ring transition hover:bg-accent-300"
                                >
                                    Abrir conta grátis<span class="sr-only">
                                        com o pacote {{ card.offer.name }}</span
                                    >
                                    <ArrowRight
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                                <Link
                                    v-else
                                    :href="register.url()"
                                    class="flex h-12 items-center justify-center gap-2 rounded-full bg-white px-5 text-sm font-semibold text-brand-950 ring-1 ring-zinc-900/15 focus-ring transition hover:ring-zinc-900/40 dark:bg-transparent dark:text-white dark:ring-white/20 dark:hover:ring-white/50"
                                >
                                    Abrir conta grátis<span class="sr-only">
                                        com o pacote {{ card.offer.name }}</span
                                    >
                                </Link>
                            </div>

                            <div
                                :class="[
                                    card.offer.is_featured
                                        ? 'border-white/15 dark:border-zinc-900/10'
                                        : 'border-zinc-900/10 dark:border-white/10',
                                    'mt-7 border-t pt-6',
                                ]"
                            >
                                <p
                                    v-if="card.buildsOn"
                                    class="text-sm font-semibold"
                                >
                                    Tudo do {{ card.buildsOn }}, e ainda:
                                </p>
                                <p
                                    v-else-if="card.features.length > 0"
                                    class="text-sm font-semibold"
                                >
                                    Inclui:
                                </p>
                                <ul
                                    v-if="card.features.length > 0"
                                    :class="[
                                        card.offer.is_featured
                                            ? 'text-white/85 dark:text-brand-700'
                                            : 'text-brand-700 dark:text-zinc-300',
                                        'mt-3 grid content-start gap-2.5 text-[0.95rem]',
                                    ]"
                                >
                                    <li
                                        v-for="feature in card.features"
                                        :key="feature"
                                        class="flex gap-3"
                                    >
                                        <Check
                                            :class="[
                                                card.offer.is_featured
                                                    ? 'text-accent-400 dark:text-accent-600'
                                                    : 'text-accent-500',
                                                'mt-0.5 size-4 shrink-0',
                                            ]"
                                            aria-hidden="true"
                                        />
                                        <span
                                            class="min-w-0 [overflow-wrap:anywhere]"
                                            >{{ feature }}</span
                                        >
                                    </li>
                                </ul>
                            </div>

                            <p
                                :class="[
                                    card.offer.is_featured
                                        ? 'text-white/60 dark:text-brand-500'
                                        : 'text-brand-500 dark:text-zinc-500',
                                    'mt-6 self-end text-sm',
                                ]"
                            >
                                <template v-if="card.offer.trial_days > 0">
                                    {{
                                        card.offer.trial_days === 1
                                            ? '1 dia de experiência, sem cartão.'
                                            : `${card.offer.trial_days} dias de experiência, sem cartão.`
                                    }}
                                </template>
                            </p>
                        </article>
                    </div>

                    <!--
                        Every feature any package sells, in the order they are
                        first offered, ticked per package. Closed by default:
                        the cards answer most visitors; this is for the one
                        comparing line by line.
                    -->
                    <details v-if="showComparison" class="group mt-10">
                        <summary
                            class="mx-auto flex w-fit cursor-pointer list-none items-center gap-2 rounded-full px-4 py-2.5 text-sm font-semibold ring-1 ring-zinc-900/15 focus-ring transition hover:ring-zinc-900/40 dark:ring-white/20 dark:hover:ring-white/50 [&::-webkit-details-marker]:hidden"
                        >
                            <span class="group-open:hidden"
                                >Comparar os pacotes linha a linha</span
                            >
                            <span class="hidden group-open:inline"
                                >Esconder a comparação</span
                            >
                            <ChevronDown
                                class="size-4 transition group-open:rotate-180 motion-reduce:transition-none"
                                aria-hidden="true"
                            />
                        </summary>
                        <div class="mt-8 overflow-x-auto">
                            <table class="w-full min-w-[36rem] text-left">
                                <caption class="sr-only">
                                    O que cada pacote inclui
                                </caption>
                                <thead>
                                    <tr
                                        class="border-b border-brand-950 dark:border-white"
                                    >
                                        <th
                                            scope="col"
                                            class="w-2/5 pb-3 text-sm font-semibold text-brand-500 normal-case dark:text-zinc-400"
                                        >
                                            Funcionalidade
                                        </th>
                                        <th
                                            v-for="offer in packages"
                                            :key="offer.name"
                                            scope="col"
                                            :style="{
                                                width: `${60 / packages.length}%`,
                                            }"
                                            :class="[
                                                offer.is_featured
                                                    ? 'bg-accent-50 dark:bg-accent-400/[0.06]'
                                                    : '',
                                                'pt-3 pb-3 text-center font-display text-lg font-normal tracking-[-0.02em]',
                                            ]"
                                        >
                                            {{ offer.name }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in comparison"
                                        :key="row.feature"
                                        class="border-b border-zinc-900/10 dark:border-white/10"
                                    >
                                        <th
                                            scope="row"
                                            class="py-3 pe-4 text-[0.95rem] font-normal"
                                        >
                                            {{ row.feature }}
                                        </th>
                                        <td
                                            v-for="(
                                                included, column
                                            ) in row.included"
                                            :key="column"
                                            :class="[
                                                packages[column]?.is_featured
                                                    ? 'bg-accent-50 dark:bg-accent-400/[0.06]'
                                                    : '',
                                                'py-3 text-center',
                                            ]"
                                        >
                                            <Check
                                                v-if="included"
                                                class="mx-auto size-4 text-accent-500"
                                                aria-hidden="true"
                                            />
                                            <span
                                                v-else
                                                class="text-brand-400 dark:text-zinc-600"
                                                aria-hidden="true"
                                                >—</span
                                            >
                                            <span class="sr-only">{{
                                                included
                                                    ? 'Incluído'
                                                    : 'Não incluído'
                                            }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </template>

                <div
                    v-else-if="plans.length > 0"
                    class="mt-12 grid gap-4 md:grid-cols-2 lg:grid-cols-3"
                >
                    <article
                        v-for="plan in plans"
                        :key="plan.name"
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

                <dl v-else class="mt-12 grid gap-4 md:grid-cols-3">
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
                <h2 class="font-display text-section font-bold text-balance">
                    Perguntas frequentes
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
                    <p class="mt-1 text-sm text-brand-400 dark:text-zinc-500">
                        Certificado da AGT n.º {{ certification.number }}
                    </p>
                </div>
                <nav aria-label="Produto">
                    <p
                        class="text-sm font-semibold text-brand-950 dark:text-white"
                    >
                        Produto
                    </p>
                    <ul class="mt-3 grid gap-2 text-[0.95rem]">
                        <li
                            v-for="section in footerSections"
                            :key="section.href"
                        >
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
                        class="text-sm font-semibold text-brand-950 dark:text-white"
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
