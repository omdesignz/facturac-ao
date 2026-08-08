<script setup lang="ts">
import { Form, Head, router, useForm, useHttp } from '@inertiajs/vue3';
import {
    Bell,
    Check,
    Copy,
    LogOut,
    MonitorSmartphone,
    KeyRound,
    LoaderCircle,
    LockKeyhole,
    RefreshCw,
    ShieldCheck,
    ShieldOff,
    Smartphone,
    Timer,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormError from '@/components/FormError.vue';
import GoogleMark from '@/components/GoogleMark.vue';
import PasskeyManager from '@/components/PasskeyManager.vue';
import type { Passkey } from '@/components/PasskeyManager.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { update as updateNotifications } from '@/routes/settings/notifications';
import {
    destroy as destroySession,
    destroyOthers as destroyOtherSessions,
} from '@/routes/settings/sessions';
import { update as updateWorkSession } from '@/routes/settings/work-session';
import {
    confirm as confirmTwoFactor,
    disable as disableTwoFactor,
    enable as enableTwoFactor,
    qrCode,
    recoveryCodes,
    regenerateRecoveryCodes,
} from '@/routes/two-factor';
import type { SelectOption } from '@/types/select';

interface TwoFactorState {
    enabled: boolean;
    confirmed: boolean;
    required_for_role: boolean;
}

interface QrCodeResponse {
    svg: string;
    url: string;
}

interface WorkSessionState {
    minutes: number;
    min_minutes: number;
    max_minutes: number;
    enabled: boolean;
}

interface ActiveSession {
    id: string;
    is_current: boolean;
    ip_address: string | null;
    browser: string;
    platform: string;
    last_active_at: string | null;
}

interface NotificationTopicState {
    value: string;
    label: string;
    description: string;
    database: boolean;
    mail: boolean;
}

const props = defineProps<{
    twoFactor: TwoFactorState;
    workSessionPreference: WorkSessionState;
    passkeys: Passkey[];
    socialConnections: {
        google: boolean;
    };
    notificationTopics: NotificationTopicState[];
    sessions: ActiveSession[];
}>();

const workSessionForm = useForm({
    work_session_minutes: props.workSessionPreference.minutes,
});

/** Common focus-block lengths, with the current value kept selectable. */
const workSessionOptions = computed<SelectOption<number>[]>(() => {
    const presets = [15, 25, 45, 60, 90, 120, 240];
    const values = presets.includes(props.workSessionPreference.minutes)
        ? presets
        : [...presets, props.workSessionPreference.minutes].sort(
              (a, b) => a - b,
          );

    return values
        .filter(
            (minutes) =>
                minutes >= props.workSessionPreference.min_minutes &&
                minutes <= props.workSessionPreference.max_minutes,
        )
        .map((minutes) => ({
            value: minutes,
            label:
                minutes >= 60
                    ? `${minutes / 60} ${minutes === 60 ? 'hora' : 'horas'}`
                    : `${minutes} minutos`,
        }));
});

const dateTimeFormatter = new Intl.DateTimeFormat('pt-PT', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatDateTime(value: string | null): string {
    return value ? dateTimeFormatter.format(new Date(value)) : 'Sem registo';
}

async function endSession(session: ActiveSession): Promise<void> {
    const confirmed = await confirmAction({
        title: `Terminar a sessão em ${session.browser} no ${session.platform}?`,
        message:
            'Esse dispositivo passa a precisar de entrar outra vez. Se não o reconhece, mude também a palavra-passe.',
        confirmLabel: 'Terminar sessão',
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroySession.url({ session: session.id }), {
        preserveScroll: true,
    });
}

async function endOtherSessions(): Promise<void> {
    const confirmed = await confirmAction({
        title: 'Terminar todas as outras sessões?',
        message:
            'Continua com sessão iniciada neste dispositivo; todos os outros terão de entrar outra vez.',
        confirmLabel: 'Terminar as outras',
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroyOtherSessions.url(), { preserveScroll: true });
}

const notificationForm = useForm({
    topics: Object.fromEntries(
        props.notificationTopics.map((topic) => [
            topic.value,
            { database: topic.database, mail: topic.mail },
        ]),
    ) as Record<string, { database: boolean; mail: boolean }>,
});

function saveNotificationPreferences(): void {
    notificationForm.put(updateNotifications.url(), { preserveScroll: true });
}

function saveWorkSession(): void {
    workSessionForm.put(updateWorkSession.url(), { preserveScroll: true });
}

const qrRequest = useHttp<Record<string, never>, QrCodeResponse>(qrCode(), {});
const recoveryRequest = useHttp<Record<string, never>, string[]>(
    recoveryCodes(),
    {},
);
const copied = ref(false);

const recoveryCodeList = computed(() => recoveryRequest.response ?? []);

async function loadQrCode(): Promise<void> {
    if (!props.twoFactor.enabled || props.twoFactor.confirmed) {
        return;
    }

    await qrRequest.submit();
}

async function loadRecoveryCodes(): Promise<void> {
    await recoveryRequest.submit();
}

async function copyRecoveryCodes(): Promise<void> {
    if (recoveryCodeList.value.length === 0) {
        return;
    }

    try {
        await navigator.clipboard.writeText(recoveryCodeList.value.join('\n'));
    } catch {
        return;
    }

    copied.value = true;
    window.setTimeout(() => {
        copied.value = false;
    }, 2500);
}

watch(
    () => [props.twoFactor.enabled, props.twoFactor.confirmed] as const,
    ([enabled, confirmed]) => {
        if (enabled && !confirmed) {
            void loadQrCode();
        }
    },
    { immediate: true },
);
</script>

<template>
    <AppLayout>
        <Head title="Segurança da conta" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-5xl space-y-8">
                <header>
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            :label="
                                passkeys.length > 0
                                    ? 'Passkeys activas'
                                    : 'Sem passkeys'
                            "
                            :tone="passkeys.length > 0 ? 'success' : 'neutral'"
                        />
                        <StatusBadge
                            :label="
                                twoFactor.confirmed
                                    ? 'MFA activo'
                                    : 'MFA por activar'
                            "
                            :tone="twoFactor.confirmed ? 'success' : 'warning'"
                        />
                    </div>
                    <h1
                        class="mt-4 text-4xl display text-zinc-950 dark:text-white"
                    >
                        Segurança da conta
                    </h1>
                    <p
                        class="mt-3 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Este ecrã exige confirmação recente da palavra-passe.
                        Segredos TOTP e códigos de recuperação nunca são
                        enviados para a AGT.
                    </p>
                </header>

                <section
                    v-if="workSessionPreference.enabled"
                    class="overflow-hidden rounded-2xl surface"
                >
                    <div
                        class="flex flex-col gap-5 border-b border-zinc-100 p-6 sm:flex-row sm:items-start sm:justify-between dark:border-white/10"
                    >
                        <div class="flex gap-4">
                            <span
                                class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                            >
                                <Timer class="size-5" aria-hidden="true" />
                            </span>
                            <div>
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Sessão de trabalho
                                </h2>
                                <p
                                    class="mt-1 max-w-xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    Cada sessão dura um tempo fixo, contado pelo
                                    servidor. Não é reiniciada por actualizar a
                                    página nem por continuar a trabalhar — no
                                    fim, avisamos antes de fechar.
                                </p>
                            </div>
                        </div>
                        <StatusBadge
                            :label="`${workSessionPreference.minutes} min`"
                            tone="info"
                        />
                    </div>

                    <div
                        class="flex flex-col gap-4 p-6 sm:flex-row sm:items-end"
                    >
                        <div class="w-full sm:max-w-xs">
                            <label
                                for="work-session-minutes"
                                class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >
                                Duração
                            </label>
                            <SelectInput
                                id="work-session-minutes"
                                v-model="workSessionForm.work_session_minutes"
                                class="mt-2"
                                :options="workSessionOptions"
                            />
                            <FormError
                                :message="
                                    workSessionForm.errors.work_session_minutes
                                "
                            />
                        </div>
                        <button
                            type="button"
                            :disabled="workSessionForm.processing"
                            class="inline-flex w-fit items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:opacity-60 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                            @click="saveWorkSession"
                        >
                            <LoaderCircle
                                v-if="workSessionForm.processing"
                                class="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            Guardar
                        </button>
                    </div>
                    <p
                        class="px-6 pb-6 text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        Guardar começa já uma sessão nova com a duração
                        escolhida.
                    </p>
                </section>

                <section class="overflow-hidden rounded-2xl surface">
                    <div
                        class="flex flex-col gap-5 border-b border-zinc-100 p-6 sm:flex-row sm:items-start sm:justify-between dark:border-white/10"
                    >
                        <div class="flex gap-4">
                            <span
                                class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                            >
                                <Bell class="size-5" aria-hidden="true" />
                            </span>
                            <div>
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Notificações
                                </h2>
                                <p
                                    class="mt-1 max-w-xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    O que quer saber, e por onde. Os acessos do
                                    suporte à sua conta chegam sempre — não é
                                    uma preferência.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-100 dark:border-white/10"
                            >
                                <tr>
                                    <th class="px-6 py-3 eyebrow text-zinc-500">
                                        Tema
                                    </th>
                                    <th
                                        class="px-3 py-3 text-center eyebrow text-zinc-500"
                                    >
                                        Na aplicação
                                    </th>
                                    <th
                                        class="px-6 py-3 text-center eyebrow text-zinc-500"
                                    >
                                        Por email
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <tr
                                    v-for="topic in notificationTopics"
                                    :key="topic.value"
                                >
                                    <td class="px-6 py-3">
                                        <p
                                            class="font-medium text-zinc-950 dark:text-white"
                                        >
                                            {{ topic.label }}
                                        </p>
                                        <p
                                            class="mt-0.5 text-xs/5 text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ topic.description }}
                                        </p>
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <input
                                            v-model="
                                                notificationForm.topics[
                                                    topic.value
                                                ].database
                                            "
                                            type="checkbox"
                                            :aria-label="`${topic.label} na aplicação`"
                                            class="size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/15 dark:bg-white/5"
                                        />
                                    </td>
                                    <td class="px-6 py-3 text-center">
                                        <input
                                            v-model="
                                                notificationForm.topics[
                                                    topic.value
                                                ].mail
                                            "
                                            type="checkbox"
                                            :aria-label="`${topic.label} por email`"
                                            class="size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/15 dark:bg-white/5"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        class="flex items-center justify-end border-t border-zinc-100 p-6 dark:border-white/10"
                    >
                        <button
                            type="button"
                            :disabled="notificationForm.processing"
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:opacity-60 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                            @click="saveNotificationPreferences"
                        >
                            <LoaderCircle
                                v-if="notificationForm.processing"
                                class="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            Guardar preferências
                        </button>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl surface">
                    <div
                        class="flex flex-col gap-5 border-b border-zinc-100 p-6 sm:flex-row sm:items-start sm:justify-between dark:border-white/10"
                    >
                        <div class="flex gap-4">
                            <span
                                class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                            >
                                <MonitorSmartphone
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <div>
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Sessões abertas
                                </h2>
                                <p
                                    class="mt-1 max-w-xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    Onde a sua conta está aberta neste momento.
                                    Se não reconhecer um dispositivo, termine-o
                                    e mude a palavra-passe.
                                </p>
                            </div>
                        </div>
                        <button
                            v-if="sessions.length > 1"
                            type="button"
                            class="w-fit shrink-0 rounded-xl px-3.5 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                            @click="endOtherSessions"
                        >
                            Terminar as outras
                        </button>
                    </div>

                    <ul class="divide-y divide-zinc-100 dark:divide-white/10">
                        <li
                            v-for="session in sessions"
                            :key="session.id"
                            class="flex items-center justify-between gap-4 p-6"
                        >
                            <div class="min-w-0">
                                <p
                                    class="flex flex-wrap items-center gap-2 text-sm font-medium text-zinc-950 dark:text-white"
                                >
                                    {{ session.browser }} no
                                    {{ session.platform }}
                                    <StatusBadge
                                        v-if="session.is_current"
                                        label="Este dispositivo"
                                        tone="success"
                                    />
                                </p>
                                <p
                                    class="mt-1 numeric text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{
                                        session.ip_address ??
                                        'Endereço desconhecido'
                                    }}
                                    ·
                                    {{ formatDateTime(session.last_active_at) }}
                                </p>
                            </div>

                            <button
                                v-if="!session.is_current"
                                type="button"
                                class="shrink-0 rounded-lg p-2 text-zinc-400 focus-ring transition hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                                @click="endSession(session)"
                            >
                                <span class="sr-only"
                                    >Terminar a sessão em
                                    {{ session.browser }}</span
                                >
                                <LogOut class="size-4" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>

                    <p
                        v-if="sessions.length === 0"
                        class="px-6 pb-6 text-sm/6 text-zinc-500 dark:text-zinc-400"
                    >
                        Não há registo de sessões para mostrar.
                    </p>
                </section>

                <PasskeyManager :passkeys="passkeys" />

                <section class="overflow-hidden rounded-2xl surface">
                    <div
                        class="flex flex-col gap-5 border-b border-zinc-100 p-6 sm:flex-row sm:items-start sm:justify-between dark:border-white/10"
                    >
                        <div class="flex gap-4">
                            <span
                                class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                            >
                                <Smartphone class="size-5" aria-hidden="true" />
                            </span>
                            <div>
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Autenticação de dois factores
                                </h2>
                                <p
                                    class="mt-1 max-w-xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    Use qualquer aplicação compatível com TOTP.
                                    Para proprietários, administradores e
                                    contabilistas, este controlo será
                                    obrigatório antes de operações fiscais
                                    privilegiadas.
                                </p>
                            </div>
                        </div>
                        <StatusBadge
                            :label="
                                twoFactor.confirmed
                                    ? 'Protegida'
                                    : twoFactor.enabled
                                      ? 'A confirmar'
                                      : 'Inactiva'
                            "
                            :tone="twoFactor.confirmed ? 'success' : 'warning'"
                        />
                    </div>

                    <div v-if="!twoFactor.enabled" class="p-6">
                        <div
                            v-if="twoFactor.required_for_role"
                            class="mb-5 flex gap-3 rounded-xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                        >
                            <LockKeyhole
                                class="mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <span
                                >O seu perfil tem permissões elevadas. Active o
                                MFA antes da futura ligação fiscal.</span
                            >
                        </div>

                        <Form
                            v-bind="enableTwoFactor.form()"
                            #default="{ processing }"
                        >
                            <button
                                type="submit"
                                :disabled="processing"
                                class="flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-60 dark:bg-brand-500 dark:hover:bg-brand-400"
                            >
                                <LoaderCircle
                                    v-if="processing"
                                    class="size-4 animate-spin"
                                    aria-hidden="true"
                                />
                                {{ processing ? 'A preparar…' : 'Activar MFA' }}
                            </button>
                        </Form>
                    </div>

                    <div
                        v-else-if="!twoFactor.confirmed"
                        class="grid grid-cols-1 gap-8 p-6 lg:grid-cols-[13rem_minmax(0,1fr)]"
                    >
                        <div
                            class="grid min-h-52 place-items-center rounded-2xl bg-white p-3 ring-1 ring-zinc-200 dark:ring-white/10"
                        >
                            <LoaderCircle
                                v-if="qrRequest.processing"
                                class="size-6 animate-spin text-brand-700"
                                aria-label="A carregar código QR"
                            />
                            <div
                                v-else-if="qrRequest.response?.svg"
                                class="size-48 [&>svg]:size-full"
                                aria-label="Código QR para a aplicação autenticadora"
                                v-html="qrRequest.response.svg"
                            />
                            <button
                                v-else
                                type="button"
                                class="text-sm font-semibold text-brand-700 dark:text-brand-300"
                                @click="loadQrCode"
                            >
                                Carregar código QR
                            </button>
                        </div>

                        <div>
                            <p
                                class="eyebrow text-brand-700 dark:text-brand-300"
                            >
                                Passo final
                            </p>
                            <h3
                                class="mt-2 text-lg font-semibold text-zinc-950 dark:text-white"
                            >
                                Leia o QR e confirme o código
                            </h3>
                            <ol
                                class="mt-4 space-y-2 text-sm/6 text-zinc-600 dark:text-zinc-400"
                            >
                                <li>
                                    1. Adicione uma nova conta na aplicação
                                    autenticadora.
                                </li>
                                <li>2. Leia este código QR.</li>
                                <li>
                                    3. Introduza o código de seis dígitos
                                    apresentado.
                                </li>
                            </ol>

                            <Form
                                v-bind="confirmTwoFactor.form()"
                                :reset-on-error="['code']"
                                class="mt-6 flex max-w-md flex-col gap-3 sm:flex-row sm:items-start"
                                #default="{ errors, processing }"
                            >
                                <div class="flex-1">
                                    <label
                                        for="confirmation-code"
                                        class="sr-only"
                                        >Código de confirmação</label
                                    >
                                    <input
                                        id="confirmation-code"
                                        name="code"
                                        type="text"
                                        inputmode="numeric"
                                        pattern="[0-9]*"
                                        autocomplete="one-time-code"
                                        maxlength="6"
                                        required
                                        class="block w-full rounded-xl bg-white px-3 py-2.5 text-center font-mono text-lg tracking-[0.3em] text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                                        placeholder="000000"
                                    />
                                    <FormError :message="errors.code" />
                                </div>
                                <button
                                    type="submit"
                                    :disabled="processing"
                                    class="flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-600 disabled:opacity-60 dark:bg-brand-500 dark:hover:bg-brand-400"
                                >
                                    <LoaderCircle
                                        v-if="processing"
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    Confirmar
                                </button>
                            </Form>

                            <Form
                                v-bind="disableTwoFactor.form()"
                                class="mt-5"
                                #default="{ processing }"
                            >
                                <button
                                    type="submit"
                                    :disabled="processing"
                                    class="inline-flex items-center gap-2 text-sm font-semibold text-zinc-500 transition hover:text-rose-700 disabled:opacity-60 dark:text-zinc-400 dark:hover:text-rose-300"
                                >
                                    <ShieldOff
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Cancelar configuração do MFA
                                </button>
                            </Form>
                        </div>
                    </div>

                    <div v-else class="p-6">
                        <div
                            class="flex gap-3 rounded-xl bg-emerald-50 p-4 text-sm/6 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-200 dark:ring-emerald-400/20"
                        >
                            <ShieldCheck
                                class="mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <span
                                >O segundo factor está confirmado e será pedido
                                no próximo início de sessão.</span
                            >
                        </div>

                        <div class="mt-6 flex flex-wrap gap-3">
                            <button
                                type="button"
                                :disabled="recoveryRequest.processing"
                                class="flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 transition hover:bg-zinc-50 disabled:opacity-60 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                @click="loadRecoveryCodes"
                            >
                                <LoaderCircle
                                    v-if="recoveryRequest.processing"
                                    class="size-4 animate-spin"
                                    aria-hidden="true"
                                />
                                <KeyRound
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Mostrar códigos de recuperação
                            </button>

                            <Form
                                v-bind="disableTwoFactor.form()"
                                #default="{ processing }"
                            >
                                <button
                                    type="submit"
                                    :disabled="processing"
                                    class="flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-rose-700 ring-1 ring-rose-200 transition hover:bg-rose-50 disabled:opacity-60 dark:text-rose-300 dark:ring-rose-400/20 dark:hover:bg-rose-400/10"
                                >
                                    <ShieldOff
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Desactivar MFA
                                </button>
                            </Form>
                        </div>

                        <div
                            v-if="recoveryCodeList.length > 0"
                            class="mt-6 rounded-2xl bg-zinc-950 p-5 text-white ring-1 ring-zinc-800"
                        >
                            <div
                                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <h3 class="font-semibold">
                                        Códigos de recuperação
                                    </h3>
                                    <p class="mt-1 text-xs/5 text-zinc-400">
                                        Guarde-os fora deste dispositivo. Cada
                                        código só funciona uma vez.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="flex items-center gap-2 rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold hover:bg-white/15"
                                    @click="copyRecoveryCodes"
                                >
                                    <Check
                                        v-if="copied"
                                        class="size-4 text-emerald-300"
                                        aria-hidden="true"
                                    />
                                    <Copy
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ copied ? 'Copiados' : 'Copiar' }}
                                </button>
                            </div>
                            <div
                                class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2"
                            >
                                <code
                                    v-for="code in recoveryCodeList"
                                    :key="code"
                                    class="rounded-lg bg-white/5 px-3 py-2 font-mono text-sm text-zinc-200"
                                    >{{ code }}</code
                                >
                            </div>
                            <Form
                                v-bind="regenerateRecoveryCodes.form()"
                                class="mt-4"
                                #default="{ processing }"
                                @success="loadRecoveryCodes"
                            >
                                <button
                                    type="submit"
                                    :disabled="processing"
                                    class="flex items-center gap-2 text-xs font-semibold text-accent-400 hover:text-amber-200 disabled:opacity-60"
                                >
                                    <RefreshCw
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Gerar novos códigos e invalidar estes
                                </button>
                            </Form>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl surface p-6">
                    <div
                        class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex gap-4">
                            <span
                                class="grid size-11 shrink-0 place-items-center rounded-xl bg-zinc-100 dark:bg-white/5"
                            >
                                <GoogleMark />
                            </span>
                            <div>
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Conta Google
                                </h2>
                                <p
                                    class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    {{
                                        socialConnections.google
                                            ? 'Ligada para entrada segura. Não guardamos tokens Google.'
                                            : 'Não existe uma identidade Google ligada a esta conta.'
                                    }}
                                </p>
                            </div>
                        </div>
                        <StatusBadge
                            :label="
                                socialConnections.google
                                    ? 'Ligada'
                                    : 'Não ligada'
                            "
                            :tone="
                                socialConnections.google ? 'success' : 'neutral'
                            "
                        />
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
