<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Banknote,
    CalendarClock,
    Check,
    CheckCircle2,
    CircleAlert,
    Clipboard,
    CreditCard,
    History,
    Landmark,
    LoaderCircle,
    LockKeyhole,
    PackageOpen,
    ReceiptText,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { checkout } from '@/routes/billing';
import { refresh, simulate } from '@/routes/billing/references';
import { security } from '@/routes/settings';

type BadgeTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

interface SubscriptionPlan {
    public_id: string;
    code: string;
    name: string;
    summary: string | null;
    amount_minor: number;
    currency_code: string;
    interval: string;
    interval_label: string;
    trial_days: number;
    features: string[];
    limits: Record<string, unknown>;
}

interface PaymentReference {
    public_id: string;
    entity: string;
    reference: string;
    amount_minor: number;
    currency_code: string;
    status: string;
    status_label: string;
    expires_at: string;
    paid_at: string | null;
    last_checked_at: string | null;
    environment: string;
}

interface Subscription {
    public_id: string;
    status: string;
    status_label: string;
    period_started_at: string | null;
    period_ends_at: string | null;
    cancel_at_period_end: boolean;
    plan: SubscriptionPlan;
}

interface SubscriptionCharge {
    public_id: string;
    amount_minor: number;
    currency_code: string;
    status: string;
    status_label: string;
    created_at: string | null;
    paid_at: string | null;
    plan_name: string;
    reference: PaymentReference | null;
}

interface Gateway {
    provider: string;
    method: string;
    environment: string;
    environment_label: string;
    available: boolean;
    simulated: boolean;
    production_enabled: boolean;
    transaction_fee_basis_points: number;
    maximum_amount_minor: number;
}

const props = defineProps<{
    plans: SubscriptionPlan[];
    subscription: Subscription | null;
    activeReference: PaymentReference | null;
    charges: SubscriptionCharge[];
    gateway: Gateway;
    canManage: boolean;
}>();

const page = usePage();
const selectedPlan = ref<SubscriptionPlan | null>(null);
const copiedField = ref<'entity' | 'reference' | 'instruction' | null>(null);
const checkoutForm = useForm({ plan_public_id: '' });
const refreshForm = useForm({});
const simulationForm = useForm({});

const mfaEnabled = computed(
    () => page.props.auth.user?.two_factor_enabled === true,
);
const feePercentage = computed(() =>
    (props.gateway.transaction_fee_basis_points / 100).toLocaleString('pt-AO', {
        maximumFractionDigits: 2,
    }),
);
const canStartCheckout = computed(
    () =>
        props.canManage &&
        mfaEnabled.value &&
        props.gateway.available &&
        props.activeReference === null,
);

function formatMoney(amountMinor: number, currencyCode = 'AOA'): string {
    return new Intl.NumberFormat('pt-AO', {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amountMinor / 100);
}

function formatDate(value: string | null, includeTime = false): string {
    if (value === null) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-AO', {
        dateStyle: 'medium',
        ...(includeTime ? { timeStyle: 'short' as const } : {}),
        timeZone: 'Africa/Luanda',
    }).format(new Date(value));
}

function statusTone(status: string): BadgeTone {
    if (['active', 'paid'].includes(status)) {
        return 'success';
    }

    if (['creating', 'pending', 'past_due'].includes(status)) {
        return 'warning';
    }

    if (['failed', 'cancelled'].includes(status)) {
        return 'danger';
    }

    if (status === 'trialing') {
        return 'info';
    }

    return 'neutral';
}

function isCurrentPlan(plan: SubscriptionPlan): boolean {
    return props.subscription?.plan.public_id === plan.public_id;
}

function openCheckout(plan: SubscriptionPlan): void {
    if (!canStartCheckout.value) {
        return;
    }

    checkoutForm.clearErrors();
    checkoutForm.plan_public_id = plan.public_id;
    selectedPlan.value = plan;
}

function closeCheckout(): void {
    if (!checkoutForm.processing) {
        selectedPlan.value = null;
    }
}

function submitCheckout(): void {
    if (selectedPlan.value === null) {
        return;
    }

    checkoutForm.post(checkout.url(), {
        preserveScroll: true,
        onSuccess: () => {
            selectedPlan.value = null;
        },
    });
}

function refreshReference(): void {
    if (props.activeReference === null) {
        return;
    }

    refreshForm.post(refresh.url(props.activeReference.public_id), {
        preserveScroll: true,
    });
}

function confirmSimulation(): void {
    if (props.activeReference === null || !props.gateway.simulated) {
        return;
    }

    simulationForm.post(simulate.url(props.activeReference.public_id), {
        preserveScroll: true,
    });
}

async function copyValue(
    field: 'entity' | 'reference' | 'instruction',
    value: string,
): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copiedField.value = field;
        window.setTimeout(() => {
            if (copiedField.value === field) {
                copiedField.value = null;
            }
        }, 1800);
    } catch {
        copiedField.value = null;
    }
}

function copyPaymentInstruction(): void {
    if (props.activeReference === null) {
        return;
    }

    const reference = props.activeReference;
    void copyValue(
        'instruction',
        [
            `${props.gateway.method} — ${props.gateway.provider}`,
            `Entidade: ${reference.entity}`,
            `Referência: ${reference.reference}`,
            `Valor: ${formatMoney(reference.amount_minor, reference.currency_code)}`,
            `Válida até: ${formatDate(reference.expires_at, true)}`,
        ].join('\n'),
    );
}
</script>

<template>
    <AppLayout>
        <Head title="Plano e cobrança" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-[100rem] space-y-8">
                <section
                    class="relative isolate overflow-hidden rounded-3xl bg-brand-950 px-6 py-8 text-white shadow-[0_28px_80px_-45px_rgba(8,40,32,0.9)] sm:px-8 lg:px-10"
                >
                    <div
                        class="absolute -top-28 -right-24 size-80 rounded-full bg-amber-300/15 blur-3xl"
                        aria-hidden="true"
                    />
                    <div
                        class="absolute -bottom-24 left-1/4 size-64 rounded-full bg-emerald-400/10 blur-3xl"
                        aria-hidden="true"
                    />
                    <div
                        class="relative flex flex-col justify-between gap-7 xl:flex-row xl:items-end"
                    >
                        <div class="max-w-3xl">
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    label="Fase 5 · Subscrições"
                                    tone="info"
                                />
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-xs font-semibold text-brand-100/75"
                                >
                                    <Landmark
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Referência EMIS
                                </span>
                            </div>
                            <h1
                                class="mt-5 max-w-4xl font-display text-4xl leading-none font-semibold tracking-tight sm:text-5xl"
                            >
                                Um plano claro.
                                <span class="text-amber-300"
                                    >Pagamento familiar.</span
                                >
                            </h1>
                            <p
                                class="mt-4 max-w-2xl text-sm/6 text-brand-100/70 sm:text-base/7"
                            >
                                Escolha o plano, gere uma Referência EMIS e
                                pague através de qualquer banco, ATM ou
                                aplicação bancária. A assinatura só é activada
                                depois da confirmação do provedor.
                            </p>
                        </div>

                        <div
                            class="grid min-w-full gap-3 sm:grid-cols-2 xl:min-w-[28rem]"
                        >
                            <div
                                class="rounded-2xl border border-white/10 bg-white/[0.06] p-4 backdrop-blur"
                            >
                                <p
                                    class="text-xs font-semibold tracking-wider text-brand-100/55 uppercase"
                                >
                                    Operador
                                </p>
                                <p class="mt-2 text-sm font-semibold">
                                    {{ gateway.provider }}
                                </p>
                                <p class="mt-1 text-xs text-brand-100/60">
                                    {{ gateway.environment_label }}
                                </p>
                            </div>
                            <div
                                class="rounded-2xl border border-white/10 bg-white/[0.06] p-4 backdrop-blur"
                            >
                                <p
                                    class="text-xs font-semibold tracking-wider text-brand-100/55 uppercase"
                                >
                                    Referência activa
                                </p>
                                <p class="mt-2 text-sm font-semibold">
                                    {{
                                        activeReference
                                            ? 'A aguardar'
                                            : 'Nenhuma'
                                    }}
                                </p>
                                <p class="mt-1 text-xs text-brand-100/60">
                                    Valor exacto e confirmação automática
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    v-if="gateway.simulated"
                    class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-600/15 dark:bg-amber-400/10 dark:ring-amber-400/20"
                    aria-labelledby="simulation-heading"
                >
                    <div class="flex gap-3">
                        <TriangleAlert
                            class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-300"
                            aria-hidden="true"
                        />
                        <div>
                            <h2
                                id="simulation-heading"
                                class="text-sm font-semibold text-amber-900 dark:text-amber-100"
                            >
                                Ambiente de demonstração — não efectue um
                                pagamento real
                            </h2>
                            <p
                                class="mt-1 text-sm/6 text-amber-800/80 dark:text-amber-100/75"
                            >
                                A entidade e a referência geradas aqui são
                                simuladas e não são pagáveis. A produção fica
                                bloqueada até instalar a especificação API e as
                                credenciais comerciais da Pay4All.
                            </p>
                        </div>
                    </div>
                </section>

                <section
                    v-if="!mfaEnabled"
                    class="rounded-2xl bg-sky-50 p-4 ring-1 ring-sky-600/15 dark:bg-sky-400/10 dark:ring-sky-400/20"
                    aria-labelledby="mfa-heading"
                >
                    <div class="flex gap-3">
                        <LockKeyhole
                            class="mt-0.5 size-5 shrink-0 text-sky-600 dark:text-sky-300"
                            aria-hidden="true"
                        />
                        <div>
                            <h2
                                id="mfa-heading"
                                class="text-sm font-semibold text-sky-900 dark:text-sky-100"
                            >
                                Active a autenticação multifactor
                            </h2>
                            <p
                                class="mt-1 text-sm/6 text-sky-800/80 dark:text-sky-100/75"
                            >
                                Gerar ou confirmar uma cobrança altera o acesso
                                ao serviço e exige MFA.
                                <Link
                                    :href="security.url()"
                                    class="font-semibold underline underline-offset-2 hover:no-underline"
                                >
                                    Configurar segurança
                                </Link>
                            </p>
                        </div>
                    </div>
                </section>

                <div
                    class="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(22rem,0.85fr)]"
                >
                    <section
                        v-if="activeReference"
                        class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-zinc-900/5 dark:bg-zinc-900 dark:ring-white/10"
                        aria-labelledby="reference-heading"
                    >
                        <div
                            class="flex flex-col gap-4 border-b border-zinc-200 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7 dark:border-white/10"
                        >
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2
                                        id="reference-heading"
                                        class="text-base font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Instruções de pagamento
                                    </h2>
                                    <StatusBadge
                                        :label="activeReference.status_label"
                                        :tone="
                                            statusTone(activeReference.status)
                                        "
                                        pulse
                                    />
                                </div>
                                <p
                                    class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    Use exactamente estes dados no canal
                                    bancário da sua preferência.
                                </p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-zinc-950 px-3.5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200"
                                @click="copyPaymentInstruction"
                            >
                                <Check
                                    v-if="copiedField === 'instruction'"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                <Clipboard
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{
                                    copiedField === 'instruction'
                                        ? 'Copiado'
                                        : 'Copiar instrução'
                                }}
                            </button>
                        </div>

                        <dl
                            class="divide-y divide-zinc-100 dark:divide-white/5"
                        >
                            <div
                                class="px-5 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-7"
                            >
                                <dt
                                    class="text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >
                                    Entidade
                                </dt>
                                <dd
                                    class="mt-2 flex items-center justify-between gap-4 sm:col-span-2 sm:mt-0"
                                >
                                    <span
                                        class="font-mono text-xl font-semibold tracking-[0.16em] text-zinc-950 dark:text-white"
                                        >{{ activeReference.entity }}</span
                                    >
                                    <button
                                        type="button"
                                        class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 focus-visible:outline-2 focus-visible:outline-brand-600 dark:hover:bg-white/5 dark:hover:text-white"
                                        :aria-label="`Copiar entidade ${activeReference.entity}`"
                                        @click="
                                            copyValue(
                                                'entity',
                                                activeReference.entity,
                                            )
                                        "
                                    >
                                        <Check
                                            v-if="copiedField === 'entity'"
                                            class="size-4 text-emerald-600 dark:text-emerald-400"
                                            aria-hidden="true"
                                        />
                                        <Clipboard
                                            v-else
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </dd>
                            </div>
                            <div
                                class="px-5 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-7"
                            >
                                <dt
                                    class="text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >
                                    Referência
                                </dt>
                                <dd
                                    class="mt-2 flex items-center justify-between gap-4 sm:col-span-2 sm:mt-0"
                                >
                                    <span
                                        class="font-mono text-xl font-semibold tracking-[0.12em] text-zinc-950 dark:text-white"
                                        >{{ activeReference.reference }}</span
                                    >
                                    <button
                                        type="button"
                                        class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 focus-visible:outline-2 focus-visible:outline-brand-600 dark:hover:bg-white/5 dark:hover:text-white"
                                        :aria-label="`Copiar referência ${activeReference.reference}`"
                                        @click="
                                            copyValue(
                                                'reference',
                                                activeReference.reference,
                                            )
                                        "
                                    >
                                        <Check
                                            v-if="copiedField === 'reference'"
                                            class="size-4 text-emerald-600 dark:text-emerald-400"
                                            aria-hidden="true"
                                        />
                                        <Clipboard
                                            v-else
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </dd>
                            </div>
                            <div
                                class="px-5 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-7"
                            >
                                <dt
                                    class="text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >
                                    Valor exacto
                                </dt>
                                <dd
                                    class="mt-1 font-display text-xl font-semibold text-zinc-950 sm:col-span-2 sm:mt-0 dark:text-white"
                                >
                                    {{
                                        formatMoney(
                                            activeReference.amount_minor,
                                            activeReference.currency_code,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div
                                class="px-5 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-7"
                            >
                                <dt
                                    class="text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >
                                    Válida até
                                </dt>
                                <dd
                                    class="mt-1 flex items-center gap-2 text-sm text-zinc-700 sm:col-span-2 sm:mt-0 dark:text-zinc-300"
                                >
                                    <CalendarClock
                                        class="size-4 text-zinc-400"
                                        aria-hidden="true"
                                    />
                                    {{
                                        formatDate(
                                            activeReference.expires_at,
                                            true,
                                        )
                                    }}
                                </dd>
                            </div>
                        </dl>

                        <div
                            class="border-t border-zinc-200 bg-zinc-50 px-5 py-5 sm:flex sm:items-center sm:justify-between sm:gap-4 sm:px-7 dark:border-white/10 dark:bg-white/[0.025]"
                        >
                            <p
                                class="flex items-start gap-2 text-xs/5 text-zinc-500 dark:text-zinc-400"
                            >
                                <CircleAlert
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                A referência é uma instrução, não um
                                comprovativo. O acesso só muda após confirmação
                                do pagamento.
                            </p>
                            <div
                                class="mt-4 flex shrink-0 flex-wrap gap-2 sm:mt-0"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-3.5 py-2.5 text-sm font-semibold text-zinc-900 shadow-sm ring-1 ring-zinc-300 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:hover:bg-white/10"
                                    :disabled="refreshForm.processing"
                                    @click="refreshReference"
                                >
                                    <LoaderCircle
                                        v-if="refreshForm.processing"
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    <RefreshCw
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Verificar estado
                                </button>
                                <button
                                    v-if="gateway.simulated"
                                    type="button"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-300 px-3.5 py-2.5 text-sm font-semibold text-brand-950 shadow-sm transition hover:bg-amber-200 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="simulationForm.processing"
                                    @click="confirmSimulation"
                                >
                                    <LoaderCircle
                                        v-if="simulationForm.processing"
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    <Sparkles
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Simular confirmação
                                </button>
                            </div>
                        </div>
                    </section>

                    <section
                        v-else
                        class="rounded-2xl bg-white p-7 text-center shadow-sm ring-1 ring-zinc-900/5 sm:p-10 dark:bg-zinc-900 dark:ring-white/10"
                        aria-labelledby="no-reference-heading"
                    >
                        <span
                            class="mx-auto grid size-12 place-items-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                        >
                            <Landmark class="size-6" aria-hidden="true" />
                        </span>
                        <h2
                            id="no-reference-heading"
                            class="mt-4 text-base font-semibold text-zinc-950 dark:text-white"
                        >
                            Nenhuma Referência EMIS pendente
                        </h2>
                        <p
                            class="mx-auto mt-2 max-w-lg text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Seleccione um plano abaixo. Antes de gerar a
                            referência, mostramos o valor, o período e todas as
                            condições para uma confirmação consciente.
                        </p>
                    </section>

                    <aside
                        class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-zinc-900/5 dark:bg-zinc-900 dark:ring-white/10"
                        aria-labelledby="subscription-heading"
                    >
                        <div
                            class="border-b border-zinc-200 px-5 py-5 sm:px-6 dark:border-white/10"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <h2
                                    id="subscription-heading"
                                    class="text-base font-semibold text-zinc-950 dark:text-white"
                                >
                                    Assinatura actual
                                </h2>
                                <StatusBadge
                                    v-if="subscription"
                                    :label="subscription.status_label"
                                    :tone="statusTone(subscription.status)"
                                />
                            </div>
                        </div>

                        <div v-if="subscription" class="p-5 sm:p-6">
                            <div class="flex items-start gap-4">
                                <span
                                    class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300"
                                >
                                    <BadgeCheck
                                        class="size-6"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div class="min-w-0">
                                    <p
                                        class="font-display text-2xl font-semibold tracking-tight text-zinc-950 dark:text-white"
                                    >
                                        {{ subscription.plan.name }}
                                    </p>
                                    <p
                                        class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                    >
                                        {{
                                            formatMoney(
                                                subscription.plan.amount_minor,
                                                subscription.plan.currency_code,
                                            )
                                        }}
                                        · {{ subscription.plan.interval_label }}
                                    </p>
                                </div>
                            </div>
                            <dl
                                class="mt-6 space-y-4 border-t border-zinc-100 pt-5 dark:border-white/5"
                            >
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt
                                        class="text-sm text-zinc-500 dark:text-zinc-400"
                                    >
                                        Início do período
                                    </dt>
                                    <dd
                                        class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{
                                            formatDate(
                                                subscription.period_started_at,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <dt
                                        class="text-sm text-zinc-500 dark:text-zinc-400"
                                    >
                                        Válida até
                                    </dt>
                                    <dd
                                        class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{
                                            formatDate(
                                                subscription.period_ends_at,
                                            )
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div v-else class="p-6 text-center">
                            <PackageOpen
                                class="mx-auto size-10 text-zinc-300 dark:text-zinc-600"
                                aria-hidden="true"
                            />
                            <p
                                class="mt-3 text-sm font-semibold text-zinc-900 dark:text-white"
                            >
                                Sem assinatura activa
                            </p>
                            <p
                                class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                            >
                                Os seus dados fiscais permanecem isolados; o
                                plano define o acesso contínuo ao serviço.
                            </p>
                        </div>

                        <div
                            class="border-t border-zinc-200 bg-zinc-50 px-5 py-4 dark:border-white/10 dark:bg-white/[0.025]"
                        >
                            <div
                                class="flex items-start gap-2 text-xs/5 text-zinc-500 dark:text-zinc-400"
                            >
                                <ShieldCheck
                                    class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                    aria-hidden="true"
                                />
                                Valor do plano cobrado em AOA. A taxa comercial
                                Pay4All de {{ feePercentage }}% é controlada
                                internamente e não é somada à referência do
                                cliente.
                            </div>
                        </div>
                    </aside>
                </div>

                <section aria-labelledby="plans-heading">
                    <div class="mx-auto max-w-3xl text-center">
                        <p
                            class="text-sm font-semibold text-brand-700 dark:text-brand-300"
                        >
                            Planos configuráveis
                        </p>
                        <h2
                            id="plans-heading"
                            class="mt-2 font-display text-3xl font-semibold tracking-tight text-zinc-950 dark:text-white"
                        >
                            Escolha o ritmo da sua empresa
                        </h2>
                        <p
                            class="mt-3 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Os preços são administrados na plataforma. Nenhum
                            valor de plano foi inferido da proposta comercial da
                            Pay4All.
                        </p>
                    </div>

                    <div
                        v-if="plans.length > 0"
                        class="mx-auto mt-8 grid max-w-7xl grid-cols-1 items-stretch gap-5 lg:grid-cols-3"
                    >
                        <article
                            v-for="plan in plans"
                            :key="plan.public_id"
                            :class="[
                                isCurrentPlan(plan)
                                    ? 'bg-brand-950 text-white shadow-2xl ring-brand-950 dark:bg-brand-900 dark:ring-brand-700'
                                    : 'bg-white text-zinc-950 shadow-sm ring-zinc-900/10 dark:bg-zinc-900 dark:text-white dark:ring-white/10',
                                'relative flex rounded-3xl p-7 ring-1 sm:p-8',
                            ]"
                        >
                            <div class="flex w-full flex-col">
                                <div
                                    class="flex items-start justify-between gap-4"
                                >
                                    <h3
                                        :id="`plan-${plan.code}`"
                                        :class="[
                                            isCurrentPlan(plan)
                                                ? 'text-amber-300'
                                                : 'text-brand-700 dark:text-brand-300',
                                            'text-base font-semibold',
                                        ]"
                                    >
                                        {{ plan.name }}
                                    </h3>
                                    <StatusBadge
                                        v-if="isCurrentPlan(plan)"
                                        label="Plano actual"
                                        tone="success"
                                    />
                                </div>
                                <p class="mt-5 flex items-baseline gap-x-2">
                                    <span
                                        class="font-display text-4xl font-semibold tracking-tight"
                                    >
                                        {{
                                            formatMoney(
                                                plan.amount_minor,
                                                plan.currency_code,
                                            )
                                        }}
                                    </span>
                                    <span
                                        :class="[
                                            isCurrentPlan(plan)
                                                ? 'text-brand-100/60'
                                                : 'text-zinc-500 dark:text-zinc-400',
                                            'text-sm',
                                        ]"
                                    >
                                        /
                                        {{ plan.interval_label.toLowerCase() }}
                                    </span>
                                </p>
                                <p
                                    :class="[
                                        isCurrentPlan(plan)
                                            ? 'text-brand-100/70'
                                            : 'text-zinc-600 dark:text-zinc-300',
                                        'mt-5 min-h-12 text-sm/6',
                                    ]"
                                >
                                    {{
                                        plan.summary ??
                                        'Facturação electrónica preparada para crescer consigo.'
                                    }}
                                </p>
                                <ul
                                    role="list"
                                    :class="[
                                        isCurrentPlan(plan)
                                            ? 'text-brand-50/85'
                                            : 'text-zinc-600 dark:text-zinc-300',
                                        'mt-7 flex-1 space-y-3 text-sm/6',
                                    ]"
                                >
                                    <li
                                        v-for="feature in plan.features"
                                        :key="feature"
                                        class="flex gap-x-3"
                                    >
                                        <CheckCircle2
                                            :class="[
                                                isCurrentPlan(plan)
                                                    ? 'text-amber-300'
                                                    : 'text-emerald-600 dark:text-emerald-400',
                                                'mt-0.5 size-5 shrink-0',
                                            ]"
                                            aria-hidden="true"
                                        />
                                        {{ feature }}
                                    </li>
                                </ul>
                                <button
                                    type="button"
                                    :aria-describedby="`plan-${plan.code}`"
                                    :disabled="!canStartCheckout"
                                    :class="[
                                        isCurrentPlan(plan)
                                            ? 'bg-amber-300 text-brand-950 hover:bg-amber-200 focus-visible:outline-amber-300'
                                            : 'bg-brand-700 text-white hover:bg-brand-600 focus-visible:outline-brand-600 dark:bg-brand-500 dark:hover:bg-brand-400',
                                        'mt-8 inline-flex w-full items-center justify-center gap-2 rounded-xl px-3.5 py-2.5 text-sm font-semibold shadow-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-45',
                                    ]"
                                    @click="openCheckout(plan)"
                                >
                                    <CreditCard
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{
                                        activeReference
                                            ? 'Conclua a referência activa'
                                            : isCurrentPlan(plan)
                                              ? 'Renovar este plano'
                                              : 'Escolher plano'
                                    }}
                                </button>
                            </div>
                        </article>
                    </div>

                    <div
                        v-else
                        class="mt-8 rounded-2xl border border-dashed border-zinc-300 bg-white px-6 py-12 text-center dark:border-white/15 dark:bg-zinc-900"
                    >
                        <PackageOpen
                            class="mx-auto size-12 text-zinc-400 dark:text-zinc-500"
                            aria-hidden="true"
                        />
                        <h3
                            class="mt-3 text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Ainda não existem planos publicados
                        </h3>
                        <p
                            class="mx-auto mt-1 max-w-lg text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            A estrutura está pronta, mas a tabela comercial da
                            aplicação deve ser aprovada antes de aceitar
                            cobranças.
                        </p>
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-zinc-900/5 dark:bg-zinc-900 dark:ring-white/10"
                    aria-labelledby="history-heading"
                >
                    <div
                        class="flex items-start gap-3 border-b border-zinc-200 px-5 py-5 sm:px-7 dark:border-white/10"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300"
                        >
                            <History class="size-5" aria-hidden="true" />
                        </span>
                        <div>
                            <h2
                                id="history-heading"
                                class="text-base font-semibold text-zinc-950 dark:text-white"
                            >
                                Histórico de cobranças
                            </h2>
                            <p
                                class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                As vinte cobranças mais recentes, com referência
                                e estado preservados para auditoria.
                            </p>
                        </div>
                    </div>

                    <div v-if="charges.length > 0" class="flow-root">
                        <div class="overflow-x-auto">
                            <div class="inline-block min-w-full align-middle">
                                <table
                                    class="relative min-w-full divide-y divide-zinc-300 dark:divide-white/15"
                                >
                                    <thead
                                        class="bg-zinc-50 dark:bg-white/[0.025]"
                                    >
                                        <tr>
                                            <th
                                                scope="col"
                                                class="py-3.5 pr-3 pl-5 text-left text-xs font-semibold tracking-wider text-zinc-600 uppercase sm:pl-7 dark:text-zinc-300"
                                            >
                                                Plano
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-3.5 text-left text-xs font-semibold tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                                            >
                                                Referência EMIS
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-3.5 text-left text-xs font-semibold tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                                            >
                                                Data
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-3.5 text-left text-xs font-semibold tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                                            >
                                                Estado
                                            </th>
                                            <th
                                                scope="col"
                                                class="py-3.5 pr-5 pl-3 text-right text-xs font-semibold tracking-wider text-zinc-600 uppercase sm:pr-7 dark:text-zinc-300"
                                            >
                                                Valor
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-zinc-200 bg-white dark:divide-white/10 dark:bg-zinc-900"
                                    >
                                        <tr
                                            v-for="charge in charges"
                                            :key="charge.public_id"
                                            class="transition hover:bg-zinc-50/70 dark:hover:bg-white/[0.025]"
                                        >
                                            <td
                                                class="py-4 pr-3 pl-5 text-sm whitespace-nowrap sm:pl-7"
                                            >
                                                <p
                                                    class="font-semibold text-zinc-950 dark:text-white"
                                                >
                                                    {{ charge.plan_name }}
                                                </p>
                                                <p
                                                    class="mt-0.5 font-mono text-[0.7rem] text-zinc-400"
                                                >
                                                    {{ charge.public_id }}
                                                </p>
                                            </td>
                                            <td
                                                class="px-3 py-4 text-sm whitespace-nowrap text-zinc-600 dark:text-zinc-300"
                                            >
                                                <span
                                                    v-if="charge.reference"
                                                    class="font-mono font-medium tracking-wide"
                                                >
                                                    {{
                                                        charge.reference.entity
                                                    }}
                                                    ·
                                                    {{
                                                        charge.reference
                                                            .reference
                                                    }}
                                                </span>
                                                <span v-else>—</span>
                                            </td>
                                            <td
                                                class="px-3 py-4 text-sm whitespace-nowrap text-zinc-500 dark:text-zinc-400"
                                            >
                                                {{
                                                    formatDate(
                                                        charge.created_at,
                                                        true,
                                                    )
                                                }}
                                            </td>
                                            <td
                                                class="px-3 py-4 text-sm whitespace-nowrap"
                                            >
                                                <StatusBadge
                                                    :label="charge.status_label"
                                                    :tone="
                                                        statusTone(
                                                            charge.status,
                                                        )
                                                    "
                                                />
                                            </td>
                                            <td
                                                class="py-4 pr-5 pl-3 text-right text-sm font-semibold whitespace-nowrap text-zinc-950 sm:pr-7 dark:text-white"
                                            >
                                                {{
                                                    formatMoney(
                                                        charge.amount_minor,
                                                        charge.currency_code,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div v-else class="px-6 py-12 text-center">
                        <ReceiptText
                            class="mx-auto size-11 text-zinc-300 dark:text-zinc-600"
                            aria-hidden="true"
                        />
                        <h3
                            class="mt-3 text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Sem cobranças
                        </h3>
                        <p
                            class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            A primeira Referência EMIS aparecerá aqui.
                        </p>
                    </div>
                </section>

                <section
                    class="grid gap-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-5 sm:grid-cols-3 dark:border-white/10 dark:bg-white/[0.025]"
                    aria-label="Garantias do fluxo de cobrança"
                >
                    <div class="flex gap-3">
                        <Banknote
                            class="mt-0.5 size-5 shrink-0 text-brand-700 dark:text-brand-300"
                            aria-hidden="true"
                        />
                        <div>
                            <p
                                class="text-sm font-semibold text-zinc-900 dark:text-white"
                            >
                                Valor exacto
                            </p>
                            <p
                                class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                            >
                                A confirmação é rejeitada se valor ou moeda não
                                coincidirem.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <RefreshCw
                            class="mt-0.5 size-5 shrink-0 text-brand-700 dark:text-brand-300"
                            aria-hidden="true"
                        />
                        <div>
                            <p
                                class="text-sm font-semibold text-zinc-900 dark:text-white"
                            >
                                Sem duplicações
                            </p>
                            <p
                                class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                            >
                                Cliques repetidos e eventos repetidos são
                                tratados idempotentemente.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <ShieldCheck
                            class="mt-0.5 size-5 shrink-0 text-brand-700 dark:text-brand-300"
                            aria-hidden="true"
                        />
                        <div>
                            <p
                                class="text-sm font-semibold text-zinc-900 dark:text-white"
                            >
                                Evidência preservada
                            </p>
                            <p
                                class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                            >
                                Eventos de criação, expiração e pagamento são
                                append-only.
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <TransitionRoot as="template" :show="selectedPlan !== null">
            <Dialog class="relative z-50" @close="closeCheckout">
                <TransitionChild
                    as="template"
                    enter="ease-out duration-300"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="ease-in duration-200"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div
                        class="fixed inset-0 bg-zinc-950/70 backdrop-blur-sm transition-opacity"
                    />
                </TransitionChild>

                <div class="fixed inset-0 z-50 w-screen overflow-y-auto">
                    <div
                        class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-6"
                    >
                        <TransitionChild
                            as="template"
                            enter="ease-out duration-300"
                            enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                            enter-to="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-200"
                            leave-from="opacity-100 translate-y-0 sm:scale-100"
                            leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        >
                            <DialogPanel
                                class="relative w-full transform overflow-hidden rounded-2xl bg-white p-6 text-left shadow-2xl ring-1 ring-zinc-900/10 transition-all sm:max-w-lg sm:p-7 dark:bg-zinc-900 dark:ring-white/10"
                            >
                                <button
                                    type="button"
                                    class="absolute top-4 right-4 rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 focus-visible:outline-2 focus-visible:outline-brand-600 dark:hover:bg-white/5 dark:hover:text-white"
                                    aria-label="Fechar confirmação"
                                    @click="closeCheckout"
                                >
                                    <X class="size-5" aria-hidden="true" />
                                </button>

                                <div
                                    class="grid size-11 place-items-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                                >
                                    <CreditCard
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                                <DialogTitle
                                    as="h2"
                                    class="mt-5 pr-10 text-lg font-semibold text-zinc-950 dark:text-white"
                                >
                                    Gerar Referência EMIS
                                </DialogTitle>
                                <p
                                    class="mt-2 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    Confirme os dados antes de criar uma
                                    instrução de pagamento para o plano
                                    <strong
                                        class="font-semibold text-zinc-900 dark:text-white"
                                        >{{ selectedPlan?.name }}</strong
                                    >.
                                </p>

                                <dl
                                    v-if="selectedPlan"
                                    class="mt-6 divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-white/5 dark:border-white/10"
                                >
                                    <div
                                        class="flex items-center justify-between gap-4 px-4 py-3"
                                    >
                                        <dt
                                            class="text-sm text-zinc-500 dark:text-zinc-400"
                                        >
                                            Plano
                                        </dt>
                                        <dd
                                            class="text-sm font-semibold text-zinc-900 dark:text-white"
                                        >
                                            {{ selectedPlan.name }}
                                        </dd>
                                    </div>
                                    <div
                                        class="flex items-center justify-between gap-4 px-4 py-3"
                                    >
                                        <dt
                                            class="text-sm text-zinc-500 dark:text-zinc-400"
                                        >
                                            Período
                                        </dt>
                                        <dd
                                            class="text-sm font-semibold text-zinc-900 dark:text-white"
                                        >
                                            {{ selectedPlan.interval_label }}
                                        </dd>
                                    </div>
                                    <div
                                        class="flex items-center justify-between gap-4 px-4 py-3"
                                    >
                                        <dt
                                            class="text-sm text-zinc-500 dark:text-zinc-400"
                                        >
                                            Total a pagar
                                        </dt>
                                        <dd
                                            class="font-display text-lg font-semibold text-zinc-950 dark:text-white"
                                        >
                                            {{
                                                formatMoney(
                                                    selectedPlan.amount_minor,
                                                    selectedPlan.currency_code,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                </dl>

                                <div
                                    class="mt-5 flex gap-3 rounded-xl bg-amber-50 p-3 text-xs/5 text-amber-800 ring-1 ring-amber-600/15 dark:bg-amber-400/10 dark:text-amber-100 dark:ring-amber-400/20"
                                >
                                    <CircleAlert
                                        class="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Gerar a referência não activa o plano. A
                                    activação ocorre apenas depois de a Pay4All
                                    confirmar o pagamento exacto.
                                </div>

                                <p
                                    v-if="checkoutForm.errors.plan_public_id"
                                    class="mt-4 flex items-start gap-2 text-sm text-rose-600 dark:text-rose-400"
                                >
                                    <CircleAlert
                                        class="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {{ checkoutForm.errors.plan_public_id }}
                                </p>

                                <div
                                    class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex w-full justify-center rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-zinc-900 shadow-sm ring-1 ring-zinc-300 transition hover:bg-zinc-50 disabled:opacity-50 sm:w-auto dark:bg-white/5 dark:text-white dark:ring-white/10 dark:hover:bg-white/10"
                                        :disabled="checkoutForm.processing"
                                        @click="closeCheckout"
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto dark:bg-brand-500 dark:hover:bg-brand-400"
                                        :disabled="checkoutForm.processing"
                                        @click="submitCheckout"
                                    >
                                        <LoaderCircle
                                            v-if="checkoutForm.processing"
                                            class="size-4 animate-spin"
                                            aria-hidden="true"
                                        />
                                        <Landmark
                                            v-else
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Gerar referência
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>
    </AppLayout>
</template>
