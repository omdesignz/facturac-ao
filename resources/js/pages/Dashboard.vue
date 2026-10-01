<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    Building2,
    CircleGauge,
    CloudCog,
    CreditCard,
    FileUp,
    Fingerprint,
    Landmark,
    LockKeyhole,
    ReceiptText,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { onboarding } from '@/routes';
import { show as agtConnection } from '@/routes/agt/connection';
import { index as agtSubmissions } from '@/routes/agt/submissions';
import { show as billingShow } from '@/routes/billing';
import { index as importIndex } from '@/routes/imports';
import { create as invoiceCreate } from '@/routes/invoices';
import { security } from '@/routes/settings';

interface CompanyReadiness {
    configured: boolean;
    status: string;
    status_label: string;
}

interface AgtReadiness {
    configured: boolean;
    verified: boolean;
    status: string;
    status_label: string;
}

interface FoundationStat {
    name: string;
    value: string;
    detail: string;
    tone: 'success' | 'warning' | 'neutral';
    icon: Component;
}

const props = defineProps<{
    companyReadiness: CompanyReadiness;
    agtReadiness: AgtReadiness;
}>();

const page = usePage();
const workspace = computed(() => page.props.currentWorkspace);
const user = computed(() => page.props.auth.user);

const readiness = computed(() => [
    {
        label: 'Espaço de trabalho criado',
        description:
            'Os seus dados ficam separados dos de qualquer outra empresa.',
        done: workspace.value !== null,
    },
    {
        label: 'Email confirmado',
        description: 'Confirmámos que o endereço da sua conta é mesmo seu.',
        done: user.value?.email_verified_at !== null,
    },
    {
        label: 'Empresa e estabelecimento',
        description: 'NIF, denominação e o local onde emite as facturas.',
        done: props.companyReadiness.configured,
    },
    {
        label: 'Autenticação em dois passos',
        description: 'Uma segunda prova antes de qualquer operação sensível.',
        done: user.value?.two_factor_enabled ?? false,
    },
    {
        label: 'Credenciais da AGT',
        description: 'As chaves que assinam e entregam as suas facturas.',
        done: props.agtReadiness.configured,
    },
    {
        label: 'Pronto para facturar',
        description: 'Os valores são calculados e guardados antes de emitir.',
        done: props.companyReadiness.configured,
    },
]);

const completedReadiness = computed(
    () => readiness.value.filter((item) => item.done).length,
);
const readinessPercentage = computed(() =>
    Math.round((completedReadiness.value / readiness.value.length) * 100),
);

const foundationStats = computed<FoundationStat[]>(() => [
    {
        name: 'Perfil da empresa',
        value: props.companyReadiness.configured ? 'Completo' : 'Pendente',
        detail: props.companyReadiness.status_label,
        tone: props.companyReadiness.configured ? 'success' : 'warning',
        icon: Building2,
    },
    {
        name: 'O seu acesso',
        value: workspace.value?.role_label ?? '—',
        detail: workspace.value?.name ?? 'Nenhuma empresa seleccionada',
        tone: 'neutral',
        icon: Fingerprint,
    },
    {
        name: 'Dois passos',
        value: user.value?.two_factor_enabled ? 'Activa' : 'Desligada',
        detail:
            workspace.value?.requires_mfa && !user.value?.two_factor_enabled
                ? 'Obrigatória para o seu tipo de acesso'
                : 'Segunda prova ao iniciar sessão',
        tone: user.value?.two_factor_enabled ? 'success' : 'warning',
        icon: LockKeyhole,
    },
    {
        name: 'Ligação AGT',
        value: props.agtReadiness.verified ? 'Verificada' : 'Homologação',
        detail: props.agtReadiness.status_label,
        tone: props.agtReadiness.verified
            ? 'success'
            : props.agtReadiness.configured
              ? 'warning'
              : 'neutral',
        icon: ShieldCheck,
    },
]);

const previews = [
    {
        title: 'Emitir uma factura',
        description:
            'Comece por um rascunho, confira os totais e só depois emita. Nada é enviado sem a sua confirmação.',
        href: invoiceCreate.url(),
        action: 'Criar factura',
        icon: ReceiptText,
    },
    {
        title: 'Ligar à AGT',
        description:
            'Guarde as credenciais, teste a ligação e traga as séries que a AGT lhe autorizou.',
        href: agtConnection.url(),
        action: 'Configurar ligação',
        icon: Landmark,
    },
    {
        title: 'Seguir as entregas',
        description:
            'Veja onde está cada factura: na fila, entregue ou devolvida — e porquê, quando corre mal.',
        href: agtSubmissions.url(),
        action: 'Ver entregas',
        icon: CloudCog,
    },
    {
        title: 'Trazer os seus dados',
        description:
            'Carregue o Excel ou CSV que já usa. Mostramos o que vai entrar antes de gravar seja o que for.',
        href: importIndex.url(),
        action: 'Importar dados',
        icon: FileUp,
    },
    {
        title: 'Plano e pagamentos',
        description:
            'Gere uma Referência EMIS, pague no banco ou no Multicaixa e veja o histórico de cobranças.',
        href: billingShow.url(),
        action: 'Gerir assinatura',
        icon: CreditCard,
    },
];

function statToneClasses(tone: FoundationStat['tone']): string {
    if (tone === 'success') {
        return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300';
    }

    if (tone === 'warning') {
        return 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300';
    }

    return 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300';
}

/**
 * The supporting line carries the tone as well as the icon.
 *
 * A tinted 32px chip was the only thing marking "two-factor is off and your
 * role requires it" as different from an ordinary fact. Something mandatory and
 * switched off has to read as a problem in the words, not just in a swatch most
 * people will never register.
 *
 * Only the warning speaks up. Tinting the calm states too would leave nothing
 * standing out from anything else.
 */
function statDetailClasses(tone: FoundationStat['tone']): string {
    if (tone === 'warning') {
        return 'font-medium text-amber-700 dark:text-amber-300';
    }

    return 'text-zinc-500 dark:text-zinc-400';
}
</script>

<template>
    <AppLayout>
        <Head title="Painel" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-[100rem] space-y-8">
                <section
                    class="fiscal-grid relative overflow-hidden rounded-3xl bg-brand-950 px-6 py-7 text-white shadow-[0_28px_80px_-45px_rgba(8,40,32,0.9)] sm:px-8 sm:py-9"
                >
                    <div
                        class="absolute -top-24 -right-16 size-72 rounded-full bg-accent-400/15 blur-3xl"
                        aria-hidden="true"
                    />
                    <div
                        class="relative flex flex-col justify-between gap-7 xl:flex-row xl:items-end"
                    >
                        <div class="max-w-3xl">
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    :label="
                                        agtReadiness.verified
                                            ? 'Ligação AGT verificada'
                                            : 'Ligação AGT por verificar'
                                    "
                                    :tone="
                                        agtReadiness.verified
                                            ? 'success'
                                            : 'warning'
                                    "
                                />
                                <span
                                    class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-xs font-semibold text-brand-100/75"
                                    >{{ workspace?.name }}</span
                                >
                            </div>
                            <h1
                                class="mt-5 max-w-4xl text-4xl leading-[1.05] display sm:text-5xl"
                            >
                                {{
                                    companyReadiness.configured
                                        ? 'A sua empresa está pronta.'
                                        : 'Vamos começar pela sua empresa.'
                                }}
                                <br />
                                <span class="text-accent-400"
                                    >Confira antes de emitir.</span
                                >
                            </h1>
                            <p
                                class="mt-4 max-w-2xl text-sm/6 text-brand-100/70 sm:text-base/7"
                            >
                                Cada factura passa primeiro por rascunho, com o
                                IVA e as retenções já calculados. Só segue para
                                a AGT depois de confirmar — e o que paga pelo
                                serviço nunca se mistura com o que factura aos
                                seus clientes.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <Link
                                :href="security.url()"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/5 px-4 py-2.5 text-sm font-semibold text-white focus-ring-inverted transition hover:bg-white/10"
                            >
                                <LockKeyhole
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Rever segurança
                            </Link>
                            <Link
                                :href="onboarding.url()"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-accent-400 px-4 py-2.5 text-sm font-semibold text-brand-950 shadow-sm focus-ring-inverted transition hover:bg-accent-300"
                            >
                                <Building2 class="size-4" aria-hidden="true" />
                                Preparar empresa
                            </Link>
                        </div>
                    </div>
                </section>

                <dl
                    class="grid grid-cols-1 overflow-hidden rounded-2xl bg-zinc-200/70 shadow-sm ring-1 ring-zinc-900/5 sm:grid-cols-2 xl:grid-cols-4 dark:bg-white/10 dark:ring-white/10"
                >
                    <div
                        v-for="stat in foundationStats"
                        :key="stat.name"
                        class="bg-white px-5 py-6 sm:px-6 dark:bg-zinc-900"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <dt
                                class="text-sm font-medium text-zinc-500 dark:text-zinc-400"
                            >
                                {{ stat.name }}
                            </dt>
                            <span
                                :class="[
                                    statToneClasses(stat.tone),
                                    'grid size-8 place-items-center rounded-lg',
                                ]"
                            >
                                <component
                                    :is="stat.icon"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </span>
                        </div>
                        <dd
                            class="mt-4 numeric text-2xl font-semibold tracking-tight text-zinc-950 dark:text-white"
                        >
                            {{ stat.value }}
                        </dd>
                        <!--
                            Wraps rather than truncates: the detail is where the
                            reason lives, and "Obrigatória para o seu tipo de
                            acesso" cut off at the card edge tells nobody
                            anything.
                        -->
                        <dd
                            :class="[
                                'mt-1 text-xs/5',
                                statDetailClasses(stat.tone),
                            ]"
                        >
                            {{ stat.detail }}
                        </dd>
                    </div>
                </dl>

                <div
                    class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(22rem,0.65fr)]"
                >
                    <section class="overflow-hidden rounded-2xl surface">
                        <div
                            class="flex items-start justify-between gap-4 border-b border-zinc-100 px-5 py-5 sm:px-6 dark:border-white/10"
                        >
                            <div>
                                <p
                                    class="eyebrow text-brand-700 dark:text-brand-300"
                                >
                                    Configuração da conta
                                </p>
                                <h2
                                    class="mt-1 text-lg font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{ completedReadiness }} de
                                    {{ readiness.length }} passos concluídos
                                </h2>
                            </div>
                            <span
                                class="numeric text-3xl font-semibold tracking-tight text-brand-700 dark:text-brand-300"
                                >{{ readinessPercentage }}%</span
                            >
                        </div>
                        <div
                            class="mx-5 mt-5 h-2 overflow-hidden rounded-full bg-zinc-100 sm:mx-6 dark:bg-white/10"
                        >
                            <div
                                class="h-full rounded-full bg-brand-500 transition-all"
                                :style="{ width: `${readinessPercentage}%` }"
                            />
                        </div>
                        <ul
                            role="list"
                            class="divide-y divide-zinc-100 px-5 pt-3 sm:px-6 dark:divide-white/10"
                        >
                            <li
                                v-for="item in readiness"
                                :key="item.label"
                                class="flex gap-3 py-4"
                            >
                                <BadgeCheck
                                    v-if="item.done"
                                    class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                    aria-hidden="true"
                                />
                                <CircleGauge
                                    v-else
                                    class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400"
                                    aria-hidden="true"
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center justify-between gap-2"
                                    >
                                        <p
                                            class="text-sm font-semibold text-zinc-900 dark:text-white"
                                        >
                                            {{ item.label }}
                                        </p>
                                        <span
                                            v-if="!item.done"
                                            class="eyebrow text-amber-600 dark:text-amber-400"
                                            >Por fazer</span
                                        >
                                    </div>
                                    <p
                                        class="mt-0.5 text-xs/5 text-zinc-500 dark:text-zinc-400"
                                    >
                                        {{ item.description }}
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <aside
                        class="rounded-2xl bg-brand-50 p-6 ring-1 ring-brand-200/70 dark:bg-brand-400/[0.06] dark:ring-brand-400/15"
                    >
                        <span
                            class="grid size-11 place-items-center rounded-xl bg-brand-700 text-white dark:bg-brand-400 dark:text-brand-950"
                        >
                            <ShieldCheck class="size-5" aria-hidden="true" />
                        </span>
                        <h2
                            class="mt-5 text-lg font-semibold text-brand-950 dark:text-white"
                        >
                            A facturar como
                        </h2>
                        <p
                            class="mt-2 text-sm/6 text-brand-900/70 dark:text-brand-100/65"
                        >
                            Tudo o que vir e alterar pertence a esta empresa. Se
                            gere mais do que uma, troque de espaço no canto
                            superior da navegação.
                        </p>
                        <dl class="mt-6 space-y-4 text-sm">
                            <div>
                                <dt
                                    class="eyebrow text-brand-700 dark:text-brand-300"
                                >
                                    Espaço
                                </dt>
                                <dd
                                    class="mt-1 font-semibold text-brand-950 dark:text-white"
                                >
                                    {{ workspace?.name }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="eyebrow text-brand-700 dark:text-brand-300"
                                >
                                    Empresa
                                </dt>
                                <dd
                                    class="mt-1 font-semibold text-brand-950 dark:text-white"
                                >
                                    {{
                                        workspace?.legal_entity?.trade_name ??
                                        workspace?.legal_entity?.legal_name ??
                                        'Por configurar'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="eyebrow text-brand-700 dark:text-brand-300"
                                >
                                    NIF protegido
                                </dt>
                                <dd
                                    class="mt-1 font-mono font-semibold text-brand-950 dark:text-white"
                                >
                                    {{
                                        workspace?.legal_entity?.masked_nif ??
                                        '—'
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </aside>
                </div>

                <section>
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
                    >
                        <div>
                            <p
                                class="eyebrow text-brand-700 dark:text-brand-300"
                            >
                                Atalhos
                            </p>
                            <h2
                                class="mt-1 text-xl font-semibold text-zinc-950 dark:text-white"
                            >
                                O que pode fazer a seguir
                            </h2>
                        </div>
                    </div>
                    <div class="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                        <article
                            v-for="preview in previews"
                            :key="preview.title"
                            class="rounded-2xl surface p-5"
                        >
                            <component
                                :is="preview.icon"
                                class="size-5 text-brand-700 dark:text-brand-300"
                                aria-hidden="true"
                            />
                            <h3
                                class="mt-5 font-semibold text-zinc-950 dark:text-white"
                            >
                                {{ preview.title }}
                            </h3>
                            <p
                                class="mt-2 min-h-15 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                {{ preview.description }}
                            </p>
                            <Link
                                :href="preview.href"
                                class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300"
                            >
                                {{ preview.action }}
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                        </article>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
