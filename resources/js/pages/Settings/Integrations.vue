<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3';
import {
    BookOpen,
    Check,
    Copy,
    ExternalLink,
    Info,
    KeyRound,
    LoaderCircle,
    Plug,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AccountSettingsNav from '@/components/AccountSettingsNav.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { contract } from '@/routes/integrations';
import {
    destroy,
    index,
    reauthenticate,
    replace,
    revokeCredential,
    rotate,
    store,
} from '@/routes/integrations/settings';

interface Credential {
    public_id: string;
    expires_at: string;
    revoked_at: string | null;
    last_used_at: string | null;
    active: boolean;
}
interface Integration {
    public_id: string;
    name: string;
    entity_public_id: string;
    environment: string;
    revision: number;
    revoked_at: string | null;
    scopes: string[];
    credentials: Credential[];
}
interface Disclosure {
    token: string;
    integration_public_id: string;
    credential_public_id: string;
}
const props = defineProps<{
    workspacePublicId: string;
    entities: { public_id: string; name: string }[];
    integrations: Integration[];
    enabled: boolean;
    commandsEnabled: boolean;
    entitled: boolean;
}>();
const form = useHttp<
    {
        name: string;
        entity_public_id: string;
        environment: string;
        scopes: string[];
    },
    Disclosure
>({
    name: '',
    entity_public_id: props.entities[0]?.public_id ?? '',
    environment: 'production',
    scopes: ['documents:read'],
});
const mutation = useHttp<{ revision: number }, Disclosure>({ revision: 1 });
const secret = ref<Disclosure | null>(null);
const message = ref('');
const needsConfirmation = ref(false);
const copied = ref(false);
const busy = computed(() => form.processing || mutation.processing);
/**
 * What an access may do, in the words of the business, grouped the way a
 * reader decides: looking things up, or adding records. Writes exist only in
 * production, since homologation holds no customers or catalogue of its own.
 */
const scopes = [
    {
        value: 'documents:read',
        label: 'Documentos',
        detail: 'Facturas, recibos e notas, com os totais.',
        group: 'read',
        production: false,
    },
    {
        value: 'documents:agt-status:read',
        label: 'Estado na AGT',
        detail: 'Se cada documento foi aceite ou recusado.',
        group: 'read',
        production: false,
    },
    {
        value: 'customers:read',
        label: 'Clientes',
        detail: 'Nome, NIF e contactos.',
        group: 'read',
        production: true,
    },
    {
        value: 'catalogue:read',
        label: 'Catálogo',
        detail: 'Artigos e serviços, com preços.',
        group: 'read',
        production: true,
    },
    {
        value: 'analytics:billing:read',
        label: 'Resumo de facturação',
        detail: 'Totais facturados por período.',
        group: 'read',
        production: true,
    },
    {
        value: 'customers:create',
        label: 'Criar clientes',
        detail: 'Sem duplicar ao repetir o pedido.',
        group: 'write',
        production: true,
    },
    {
        value: 'catalogue:services:create',
        label: 'Criar serviços',
        detail: 'Novos serviços no catálogo.',
        group: 'write',
        production: true,
    },
] as const;
const scopeGroups = [
    { key: 'read', label: 'Consultar' },
    { key: 'write', label: 'Criar' },
] as const;
const ENVIRONMENTS: Record<string, string> = {
    production: 'Produção',
    homologation: 'Homologação',
};
const references = [
    { id: 'read-v1', label: 'Documentos, clientes e catálogo' },
    { id: 'billing-v2', label: 'Resumo de facturação' },
    { id: 'qualified-agt-v2', label: 'Estado na AGT' },
    { id: 'customer-create-v1', label: 'Criar cliente' },
    { id: 'catalogue-service-create-v1', label: 'Criar serviço' },
];

function scopeLabel(value: string): string {
    return scopes.find((scope) => scope.value === value)?.label ?? value;
}

function environmentLabel(value: string): string {
    return ENVIRONMENTS[value] ?? value;
}

function entityName(publicId: string): string {
    return (
        props.entities.find((entity) => entity.public_id === publicId)?.name ??
        publicId
    );
}

function activeCredentials(integration: Integration): number {
    return integration.credentials.filter((item) => item.active).length;
}

/** A short-lived "copiado" on whichever identifier was just copied. */
const copiedValue = ref<string | null>(null);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

async function copyValue(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copiedValue.value = value;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => (copiedValue.value = null), 1600);
    } catch {
        message.value = 'Seleccione e copie o identificador manualmente.';
    }
}

watch(
    () => form.environment,
    () => {
        form.scopes = form.scopes.filter(
            (scope) =>
                form.environment === 'production' ||
                !scopes.find((item) => item.value === scope)?.production,
        );
    },
);
function clearSecret(): void {
    secret.value = null;
    form.response = null;
    mutation.response = null;
    copied.value = false;
}
onMounted(() => window.addEventListener('pagehide', clearSecret));
onBeforeUnmount(() => {
    window.removeEventListener('pagehide', clearSecret);
    clearSecret();
});
function failure(error: unknown): void {
    const status = (error as { response?: { status?: number } })?.response
        ?.status;
    needsConfirmation.value = status === 423;
    message.value =
        status === 423
            ? 'Confirme a sua identidade e volte a tentar.'
            : status === 409
              ? 'O acesso mudou ou ainda existem duas chaves activas. Actualize a página e reveja as chaves.'
              : status === 422
                ? 'Verifique os campos e as permissões seleccionadas.'
                : 'Não foi possível concluir. Actualize a lista antes de repetir. Se a chave foi criada mas não chegou, revogue-a e crie outra.';
}
async function create(): Promise<void> {
    clearSecret();
    message.value = '';
    needsConfirmation.value = false;

    try {
        const result = await form.submit(store());

        if (!result) {
            message.value =
                'Verifique os campos e as permissões seleccionadas.';

            return;
        }

        secret.value = result;
        form.response = null;
        form.name = '';
        router.reload({ only: ['integrations'] });
    } catch (error) {
        failure(error);
    }
}
async function change(
    integration: Integration,
    action: 'rotate' | 'replace' | 'revoke',
    credential?: Credential,
): Promise<void> {
    if (
        action === 'revoke' &&
        !(await confirmAction({
            title: 'Revogar acesso?',
            message: credential
                ? 'Esta chave deixa de funcionar imediatamente.'
                : 'Todas as chaves desta integração deixam de funcionar. Esta acção não pode ser desfeita.',
            confirmLabel: 'Revogar',
            tone: 'danger',
        }))
    ) {
        return;
    }

    clearSecret();
    message.value = '';
    needsConfirmation.value = false;
    mutation.revision = integration.revision;

    try {
        const target =
            action === 'rotate' && credential
                ? rotate([integration.public_id, credential.public_id])
                : action === 'replace'
                  ? replace(integration.public_id)
                  : credential
                    ? revokeCredential([
                          integration.public_id,
                          credential.public_id,
                      ])
                    : destroy(integration.public_id);
        const result = await mutation.submit(target);

        if (!result) {
            message.value = 'Verifique os dados enviados.';

            return;
        }

        if (action !== 'revoke') {
            secret.value = result;
        }

        mutation.response = null;
        router.reload({ only: ['integrations'] });
    } catch (error) {
        failure(error);
    }
}
async function copy(): Promise<void> {
    if (!secret.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(secret.value.token);
        copied.value = true;
    } catch {
        message.value = 'Seleccione e copie a chave manualmente.';
    }
}
/**
 * "8 dez, 22:38", with the year only when it is not this one. Built by hand,
 * as on Quem me deve: the locale's short date is "8/12/2026".
 */
const monthFormatter = new Intl.DateTimeFormat('pt-PT', { month: 'short' });
const timeFormatter = new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit',
    minute: '2-digit',
});

function date(value: string): string {
    const moment = new Date(value);
    const month = monthFormatter.format(moment).replace('.', '');
    const year =
        moment.getFullYear() === new Date().getFullYear()
            ? ''
            : ` ${moment.getFullYear()}`;

    return `${moment.getDate()} ${month}${year}, ${timeFormatter.format(moment)}`;
}
</script>

<template>
    <AppLayout>
        <Head title="Integrações e API" />

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell space-y-8">
                <AccountSettingsNav />

                <header>
                    <p class="text-[0.8125rem] text-[var(--ui-ink-3)]">
                        Ligações a outros sistemas
                    </p>
                    <h1
                        class="mt-0.5 text-[1.375rem] leading-[1.2] font-[650] tracking-[-0.025em] text-balance text-[var(--ui-ink)]"
                    >
                        Integrações e API
                    </h1>
                    <p
                        class="mt-1.5 max-w-2xl text-[0.8125rem]/5 text-pretty text-[var(--ui-ink-3)]"
                    >
                        Ligue o ERP, o CRM ou a loja online à facturac.ao. Cada
                        acesso pertence a uma empresa e a um ambiente, e só faz
                        o que lhe permitir.
                    </p>
                </header>

                <div
                    v-if="!enabled || !commandsEnabled || !entitled"
                    class="flex max-w-3xl gap-3 rounded-xl bg-[var(--ui-wash)] p-4 text-sm/6 text-[var(--ui-ink-2)]"
                    role="status"
                >
                    <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <div class="space-y-1">
                        <p v-if="!entitled">
                            O plano actual não inclui integrações. Pode
                            continuar a consultar e revogar os acessos que já
                            existem.
                        </p>
                        <p v-if="!enabled">
                            A API de consulta ainda não está ligada neste
                            servidor. Pode preparar as chaves; começam a
                            funcionar quando for ligada.
                        </p>
                        <p v-else-if="!commandsEnabled">
                            Criar clientes e serviços pela API ainda não está
                            ligado. As consultas já funcionam.
                        </p>
                    </div>
                </div>

                <div
                    v-if="message"
                    role="alert"
                    class="flex max-w-3xl flex-col gap-3 rounded-xl bg-[var(--ui-critical-wash)] p-4 text-sm/6 text-[var(--ui-ink)] sm:flex-row sm:items-center"
                >
                    <TriangleAlert
                        class="size-4 shrink-0 text-[var(--ui-critical)]"
                        aria-hidden="true"
                    />
                    <p class="flex-1">{{ message }}</p>
                    <div class="flex shrink-0 gap-2">
                        <AppButton
                            v-if="needsConfirmation"
                            :href="reauthenticate.url()"
                            variant="ink"
                            size="sm"
                            >Confirmar identidade</AppButton
                        >
                        <AppButton
                            :href="index.url()"
                            variant="outline"
                            size="sm"
                            >Actualizar a lista</AppButton
                        >
                    </div>
                </div>

                <section
                    v-if="secret"
                    aria-labelledby="new-key-title"
                    class="max-w-3xl overflow-hidden rounded-2xl surface shadow-[0_0_0_1px_var(--ui-positive),var(--ui-card-shadow)]"
                >
                    <div class="flex gap-4 p-6">
                        <span
                            class="grid size-11 shrink-0 place-items-center rounded-xl bg-[var(--ui-positive-wash)] text-[var(--ui-positive)]"
                        >
                            <KeyRound class="size-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h2
                                id="new-key-title"
                                class="font-semibold text-[var(--ui-ink)]"
                            >
                                Guarde esta chave agora
                            </h2>
                            <p
                                class="mt-1 max-w-xl text-sm/6 text-[var(--ui-ink-2)]"
                            >
                                É mostrada uma única vez. Guarde-a no gestor de
                                segredos do seu servidor, nunca numa aplicação
                                pública, num navegador ou num repositório.
                            </p>
                            <div
                                class="mt-4 flex items-start gap-2 rounded-xl bg-[var(--ui-well)] p-3"
                            >
                                <code
                                    class="min-w-0 flex-1 font-mono text-[0.8125rem]/6 break-all text-[var(--ui-ink)] select-all"
                                    >{{ secret.token }}</code
                                >
                                <AppButton
                                    variant="ink"
                                    size="sm"
                                    class="shrink-0"
                                    @click="copy"
                                >
                                    <Check
                                        v-if="copied"
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <Copy
                                        v-else
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ copied ? 'Copiada' : 'Copiar' }}
                                </AppButton>
                            </div>
                            <div
                                class="mt-4 flex flex-wrap items-center justify-between gap-3"
                            >
                                <p class="text-xs text-[var(--ui-ink-3)]">
                                    Chave
                                    <span class="font-mono">{{
                                        secret.credential_public_id
                                    }}</span>
                                </p>
                                <AppButton
                                    variant="outline"
                                    size="sm"
                                    @click="clearSecret"
                                    >Já guardei</AppButton
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <div
                    class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]"
                >
                    <div class="min-w-0 space-y-6">
                        <section
                            v-if="integrations.length"
                            aria-labelledby="accesses-title"
                            class="space-y-3"
                        >
                            <h2
                                id="accesses-title"
                                class="text-base font-semibold text-[var(--ui-ink)]"
                            >
                                Acessos existentes
                            </h2>

                            <article
                                v-for="integration in integrations"
                                :key="integration.public_id"
                                :aria-label="integration.name"
                                class="overflow-hidden rounded-2xl surface"
                            >
                                <div
                                    class="flex flex-wrap items-start justify-between gap-4 p-5"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <h3
                                                class="font-semibold text-[var(--ui-ink)]"
                                            >
                                                {{ integration.name }}
                                            </h3>
                                            <StatusBadge
                                                :label="
                                                    integration.revoked_at
                                                        ? 'Revogado'
                                                        : 'Activo'
                                                "
                                                :tone="
                                                    integration.revoked_at
                                                        ? 'neutral'
                                                        : 'success'
                                                "
                                            />
                                        </div>
                                        <p
                                            class="mt-1 text-sm text-[var(--ui-ink-2)]"
                                        >
                                            {{
                                                entityName(
                                                    integration.entity_public_id,
                                                )
                                            }}, em
                                            {{
                                                environmentLabel(
                                                    integration.environment,
                                                ).toLowerCase()
                                            }}
                                        </p>
                                    </div>
                                    <AppButton
                                        v-if="!integration.revoked_at"
                                        variant="quiet"
                                        size="sm"
                                        class="text-[var(--ui-critical)]"
                                        :disabled="busy"
                                        @click="change(integration, 'revoke')"
                                        >Revogar integração</AppButton
                                    >
                                </div>

                                <div class="space-y-4 px-5 pb-5">
                                    <ul
                                        class="flex flex-wrap gap-1.5"
                                        aria-label="Permissões"
                                    >
                                        <li
                                            v-for="scope in integration.scopes"
                                            :key="scope"
                                            class="rounded-full bg-[var(--ui-wash)] px-2.5 py-1 text-xs font-medium text-[var(--ui-ink-2)]"
                                        >
                                            {{ scopeLabel(scope) }}
                                        </li>
                                    </ul>

                                    <dl
                                        class="grid gap-2 text-xs sm:grid-cols-2"
                                    >
                                        <div
                                            v-for="identifier in [
                                                {
                                                    label: 'Espaço de trabalho',
                                                    value: workspacePublicId,
                                                },
                                                {
                                                    label: 'Empresa',
                                                    value: integration.entity_public_id,
                                                },
                                            ]"
                                            :key="identifier.label"
                                            class="flex min-w-0 items-center gap-2 rounded-lg bg-[var(--ui-well)] py-1.5 ps-3 pe-1.5"
                                        >
                                            <dt
                                                class="shrink-0 text-[var(--ui-ink-3)]"
                                            >
                                                {{ identifier.label }}
                                            </dt>
                                            <dd
                                                class="min-w-0 flex-1 truncate font-mono text-[var(--ui-ink)]"
                                                :title="identifier.value"
                                            >
                                                {{ identifier.value }}
                                            </dd>
                                            <button
                                                type="button"
                                                class="grid size-7 shrink-0 place-items-center rounded-md text-[var(--ui-ink-3)] focus-ring transition-colors hover:bg-[var(--surface-raised)] hover:text-[var(--ui-ink)]"
                                                :aria-label="`Copiar o identificador de ${identifier.label.toLowerCase()}`"
                                                @click="
                                                    copyValue(identifier.value)
                                                "
                                            >
                                                <Check
                                                    v-if="
                                                        copiedValue ===
                                                        identifier.value
                                                    "
                                                    class="size-3.5 text-[var(--ui-positive)]"
                                                    aria-hidden="true"
                                                />
                                                <Copy
                                                    v-else
                                                    class="size-3.5"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </div>
                                    </dl>
                                </div>

                                <ul
                                    v-if="integration.credentials.length"
                                    class="border-t border-[var(--ui-hairline)]"
                                    aria-label="Chaves"
                                >
                                    <li
                                        v-for="credential in integration.credentials"
                                        :key="credential.public_id"
                                        class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-[var(--ui-hairline)] px-5 py-3 last:border-b-0"
                                    >
                                        <KeyRound
                                            class="size-4 shrink-0 text-[var(--ui-ink-3)]"
                                            aria-hidden="true"
                                        />
                                        <div class="min-w-0 flex-1 basis-56 text-sm">
                                            <p
                                                class="truncate font-mono text-xs text-[var(--ui-ink)]"
                                            >
                                                {{ credential.public_id }}
                                            </p>
                                            <p
                                                class="mt-0.5 text-xs text-[var(--ui-ink-3)]"
                                            >
                                                {{
                                                    credential.revoked_at
                                                        ? `Revogada a ${date(credential.revoked_at)}`
                                                        : credential.active
                                                          ? `Expira a ${date(credential.expires_at)}`
                                                          : `Expirou a ${date(credential.expires_at)}`
                                                }}.
                                                {{
                                                    credential.last_used_at
                                                        ? `Último uso a ${date(credential.last_used_at)}.`
                                                        : 'Ainda não foi usada.'
                                                }}
                                            </p>
                                        </div>
                                        <StatusBadge
                                            v-if="
                                                credential.revoked_at ||
                                                !credential.active
                                            "
                                            :label="
                                                credential.revoked_at
                                                    ? 'Revogada'
                                                    : 'Expirada'
                                            "
                                            tone="neutral"
                                        />
                                        <div
                                            v-else-if="!integration.revoked_at"
                                            class="flex gap-1.5"
                                        >
                                            <AppButton
                                                v-if="
                                                    entitled &&
                                                    activeCredentials(
                                                        integration,
                                                    ) < 2
                                                "
                                                variant="outline"
                                                size="sm"
                                                :disabled="busy"
                                                @click="
                                                    change(
                                                        integration,
                                                        'rotate',
                                                        credential,
                                                    )
                                                "
                                                >Rodar chave</AppButton
                                            >
                                            <AppButton
                                                variant="quiet"
                                                size="sm"
                                                :disabled="busy"
                                                @click="
                                                    change(
                                                        integration,
                                                        'revoke',
                                                        credential,
                                                    )
                                                "
                                                >Revogar</AppButton
                                            >
                                        </div>
                                    </li>
                                </ul>

                                <div
                                    v-if="
                                        entitled &&
                                        !integration.revoked_at &&
                                        !integration.credentials.some(
                                            (item) => item.active,
                                        )
                                    "
                                    class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--ui-hairline)] bg-[var(--ui-head)] px-5 py-3"
                                >
                                    <p class="text-sm text-[var(--ui-ink-2)]">
                                        Nenhuma chave activa. O sistema ligado
                                        deixou de ter acesso.
                                    </p>
                                    <AppButton
                                        variant="ink"
                                        size="sm"
                                        :disabled="busy"
                                        @click="change(integration, 'replace')"
                                        >Criar chave nova</AppButton
                                    >
                                </div>
                            </article>

                            <p class="text-xs/5 text-[var(--ui-ink-3)]">
                                Ao rodar uma chave, a anterior continua válida
                                até 24 horas, para dar tempo de actualizar o
                                sistema ligado. Revogue-a quando terminar.
                            </p>
                        </section>

                        <form
                            v-if="entitled"
                            aria-labelledby="new-access-title"
                            class="overflow-hidden rounded-2xl surface"
                            @submit.prevent="create"
                        >
                            <div class="flex gap-4 p-6 pb-0">
                                <span
                                    class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                                >
                                    <Plug class="size-5" aria-hidden="true" />
                                </span>
                                <div>
                                    <h2
                                        id="new-access-title"
                                        class="font-semibold text-[var(--ui-ink)]"
                                    >
                                        {{
                                            integrations.length
                                                ? 'Novo acesso'
                                                : 'Ligar o primeiro sistema'
                                        }}
                                    </h2>
                                    <p
                                        class="mt-1 max-w-xl text-sm/6 text-[var(--ui-ink-2)]"
                                    >
                                        A chave vale 30 dias e pode ser rodada
                                        sem interromper o sistema ligado.
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-6 p-6">
                                <div class="grid gap-4 sm:grid-cols-3">
                                    <FormField
                                        label="Nome do sistema"
                                        for="integration-name"
                                        :error="form.errors.name"
                                        ><input
                                            id="integration-name"
                                            v-model="form.name"
                                            class="form-input"
                                            required
                                            maxlength="100"
                                            placeholder="ERP da empresa"
                                    /></FormField>
                                    <FormField
                                        label="Empresa"
                                        for="integration-entity"
                                        :error="form.errors.entity_public_id"
                                    >
                                        <SelectInput
                                            id="integration-entity"
                                            v-model="form.entity_public_id"
                                            :options="
                                                entities.map((entity) => ({
                                                    value: entity.public_id,
                                                    label: entity.name,
                                                }))
                                            "
                                            required
                                    /></FormField>
                                    <FormField
                                        label="Ambiente"
                                        for="integration-environment"
                                        :error="form.errors.environment"
                                    >
                                        <SelectInput
                                            id="integration-environment"
                                            v-model="form.environment"
                                            :options="[
                                                {
                                                    value: 'production',
                                                    label: 'Produção',
                                                },
                                                {
                                                    value: 'homologation',
                                                    label: 'Homologação',
                                                },
                                            ]"
                                    /></FormField>
                                </div>

                                <fieldset>
                                    <legend class="field-label">
                                        O que pode fazer
                                    </legend>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-2">
                                        <div
                                            v-for="group in scopeGroups"
                                            :key="group.key"
                                        >
                                            <p
                                                class="text-xs font-medium text-[var(--ui-ink-3)]"
                                            >
                                                {{ group.label }}
                                            </p>
                                            <div class="mt-1.5 space-y-0.5">
                                                <label
                                                    v-for="scope in scopes.filter(
                                                        (item) =>
                                                            item.group ===
                                                            group.key,
                                                    )"
                                                    :key="scope.value"
                                                    :class="[
                                                        '-mx-2 flex gap-3 rounded-lg px-2 py-2 transition-colors',
                                                        form.environment !==
                                                            'production' &&
                                                        scope.production
                                                            ? 'cursor-not-allowed opacity-50'
                                                            : 'cursor-pointer hover:bg-[var(--ui-wash)]',
                                                    ]"
                                                    ><input
                                                        v-model="form.scopes"
                                                        type="checkbox"
                                                        class="form-checkbox mt-0.5"
                                                        :value="scope.value"
                                                        :disabled="
                                                            form.environment !==
                                                                'production' &&
                                                            scope.production
                                                        "
                                                    /><span class="min-w-0">
                                                        <span
                                                            class="block text-sm font-medium text-[var(--ui-ink)]"
                                                            >{{
                                                                scope.label
                                                            }}</span
                                                        >
                                                        <span
                                                            class="block text-xs/5 text-[var(--ui-ink-3)]"
                                                            >{{
                                                                form.environment !==
                                                                    'production' &&
                                                                scope.production
                                                                    ? 'Só em produção.'
                                                                    : scope.detail
                                                            }}</span
                                                        >
                                                    </span></label
                                                >
                                            </div>
                                        </div>
                                    </div>
                                    <p
                                        v-if="form.errors.scopes"
                                        role="alert"
                                        class="mt-2 text-sm/5 font-medium text-rose-600 dark:text-rose-400"
                                    >
                                        {{ form.errors.scopes }}
                                    </p>
                                </fieldset>
                            </div>

                            <div
                                class="flex flex-col gap-3 border-t border-[var(--ui-hairline)] bg-[var(--ui-head)] px-6 py-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <p
                                    class="max-w-md text-xs/5 text-[var(--ui-ink-3)]"
                                >
                                    Pedimos que confirme a identidade se não o
                                    fez nos últimos 5 minutos. Homologação
                                    consulta documentos de teste, não uma cópia
                                    da produção.
                                </p>
                                <AppButton
                                    type="submit"
                                    variant="ink"
                                    class="shrink-0"
                                    :disabled="
                                        busy ||
                                        !form.scopes.length ||
                                        !entities.length
                                    "
                                >
                                    <LoaderCircle
                                        v-if="form.processing"
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    {{
                                        form.processing
                                            ? 'A criar…'
                                            : 'Criar chave'
                                    }}
                                </AppButton>
                            </div>
                        </form>
                    </div>

                    <aside
                        aria-labelledby="developers-title"
                        class="rounded-2xl surface p-5 xl:sticky xl:top-20"
                    >
                        <div class="flex items-center gap-2">
                            <BookOpen
                                class="size-4 text-[var(--ui-ink-3)]"
                                aria-hidden="true"
                            />
                            <h2
                                id="developers-title"
                                class="text-sm font-semibold text-[var(--ui-ink)]"
                            >
                                Para programadores
                            </h2>
                        </div>
                        <p class="mt-2 text-sm/6 text-[var(--ui-ink-2)]">
                            API HTTPS com autenticação Bearer e um SDK para
                            JavaScript e TypeScript, só para servidores. Os
                            contratos OpenAPI descrevem pedidos, respostas,
                            limites e erros.
                        </p>
                        <ul class="-mx-2 mt-3">
                            <li
                                v-for="reference in references"
                                :key="reference.id"
                            >
                                <a
                                    :href="contract.url(reference.id)"
                                    target="_blank"
                                    rel="noopener"
                                    class="group flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-sm text-[var(--ui-ink)] focus-ring transition-colors hover:bg-[var(--ui-wash)]"
                                >
                                    {{ reference.label }}
                                    <ExternalLink
                                        class="size-3.5 shrink-0 text-[var(--ui-ink-3)] group-hover:text-[var(--ui-ink)]"
                                        aria-hidden="true"
                                    />
                                </a>
                            </li>
                        </ul>
                        <p
                            class="mt-4 border-t border-[var(--ui-hairline)] pt-4 text-xs/5 text-[var(--ui-ink-3)]"
                        >
                            Os pedidos de criação levam uma chave de
                            idempotência: ao repetir um pedido sem resposta,
                            envie a mesma chave com os mesmos dados. A API ainda
                            não emite nem anula documentos fiscais, não recebe
                            pagamentos e não comunica com a AGT.
                        </p>
                    </aside>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
