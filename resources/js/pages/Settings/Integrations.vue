<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AccountSettingsNav from '@/components/AccountSettingsNav.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import SelectInput from '@/components/SelectInput.vue';
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
const scopes = [
    {
        value: 'documents:read',
        label: 'Consultar documentos',
        production: false,
    },
    {
        value: 'documents:agt-status:read',
        label: 'Consultar estado AGT',
        production: false,
    },
    { value: 'customers:read', label: 'Consultar clientes', production: true },
    { value: 'catalogue:read', label: 'Consultar catálogo', production: true },
    {
        value: 'analytics:billing:read',
        label: 'Consultar resumo de facturação',
        production: true,
    },
    { value: 'customers:create', label: 'Criar clientes', production: true },
    {
        value: 'catalogue:services:create',
        label: 'Criar serviços',
        production: true,
    },
];
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
function date(value: string): string {
    return new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
</script>

<template>
    <AppLayout>
        <Head title="Integrações e API" />
        <div class="mx-auto max-w-5xl space-y-8 px-4 py-8 sm:px-6">
            <AccountSettingsNav />
            <header class="space-y-2">
                <h1 class="text-2xl font-semibold text-[var(--ui-ink)]">
                    Integrações e API
                </h1>
                <p class="text-sm text-[var(--ui-ink-2)]">
                    Ligue os seus sistemas à Facturac. Cada acesso pertence a
                    uma empresa e ambiente, com as permissões que escolher.
                </p>
            </header>
            <p
                v-if="!enabled || !commandsEnabled"
                class="rounded-xl border border-[var(--ui-line)] p-4 text-sm text-[var(--ui-ink-2)]"
            >
                {{
                    !enabled
                        ? 'A API de consulta ainda não está activada neste servidor.'
                        : ''
                }}
                {{
                    !commandsEnabled
                        ? 'A criação de clientes e serviços pela API ainda não está activada.'
                        : ''
                }}
                As chaves podem ser preparadas, mas só funcionam quando o
                serviço correspondente estiver activo.
            </p>
            <p
                v-if="!entitled"
                role="status"
                class="text-sm text-[var(--ui-ink-2)]"
            >
                O plano actual não inclui integrações. Pode continuar a
                consultar e revogar os acessos existentes.
            </p>
            <div
                v-if="message"
                role="alert"
                class="space-y-3 rounded-xl border border-[var(--ui-line)] p-4"
            >
                <p class="text-sm">{{ message }}</p>
                <AppButton v-if="needsConfirmation" :href="reauthenticate.url()"
                    >Confirmar identidade</AppButton
                >
                <AppButton :href="index.url()">Actualizar lista</AppButton>
            </div>
            <section
                v-if="secret"
                aria-label="Nova chave"
                class="space-y-3 rounded-xl border border-emerald-600 p-5"
            >
                <h2 class="font-semibold">Guarde esta chave agora</h2>
                <p class="text-sm text-[var(--ui-ink-2)]">
                    Só é apresentada uma vez. Guarde-a num gestor de segredos do
                    seu servidor. Nunca a inclua em aplicações públicas,
                    navegadores ou repositórios.
                </p>
                <p class="text-xs text-[var(--ui-ink-2)]">
                    Identificador: {{ secret.credential_public_id }}
                </p>
                <code
                    class="block rounded-lg bg-[var(--ui-wash)] p-3 text-sm break-all select-all"
                    >{{ secret.token }}</code
                >
                <div class="flex flex-wrap gap-2">
                    <AppButton @click="copy">{{
                        copied ? 'Copiada' : 'Copiar chave'
                    }}</AppButton>
                    <AppButton @click="clearSecret">Já guardei</AppButton>
                </div>
            </section>
            <form
                v-if="entitled"
                class="space-y-5 rounded-xl border border-[var(--ui-line)] p-5"
                @submit.prevent="create"
            >
                <h2 class="font-semibold">Novo acesso</h2>
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
                                { value: 'production', label: 'Produção' },
                                { value: 'homologation', label: 'Homologação' },
                            ]"
                    /></FormField>
                </div>
                <fieldset class="space-y-3">
                    <legend class="mb-3 text-sm font-medium">Permissões</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label
                            v-for="scope in scopes"
                            :key="scope.value"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.scopes"
                                type="checkbox"
                                class="form-checkbox"
                                :value="scope.value"
                                :disabled="
                                    form.environment !== 'production' &&
                                    scope.production
                                "
                            />{{ scope.label }}</label
                        >
                    </div>
                    <p
                        v-if="form.errors.scopes"
                        role="alert"
                        class="text-sm text-red-600"
                    >
                        {{ form.errors.scopes }}
                    </p>
                </fieldset>
                <p class="text-sm text-[var(--ui-ink-2)]">
                    Validade: 30 dias. Para criar ou renovar uma chave, confirme
                    a identidade nos últimos 5 minutos. Homologação permite
                    consultar documentos e estado AGT; não é uma cópia dos dados
                    de produção.
                </p>
                <AppButton
                    type="submit"
                    variant="ink"
                    :disabled="busy || !form.scopes.length || !entities.length"
                    >{{
                        form.processing ? 'A criar…' : 'Criar chave'
                    }}</AppButton
                >
            </form>
            <section
                class="space-y-3 rounded-xl border border-[var(--ui-line)] p-5"
                aria-label="Referência da API"
            >
                <h2 class="font-semibold">Para programadores</h2>
                <p class="text-sm text-[var(--ui-ink-2)]">
                    API HTTPS com autenticação Bearer e SDK
                    JavaScript/TypeScript para servidores. Use os
                    identificadores da integração abaixo. Os contratos OpenAPI
                    descrevem os pedidos, respostas, limites e erros.
                </p>
                <div class="flex flex-wrap gap-2">
                    <AppButton
                        v-for="reference in [
                            {
                                id: 'read-v1',
                                label: 'Documentos, clientes e catálogo',
                            },
                            { id: 'billing-v2', label: 'Resumo de facturação' },
                            { id: 'qualified-agt-v2', label: 'Estado AGT' },
                            {
                                id: 'customer-create-v1',
                                label: 'Criar cliente',
                            },
                            {
                                id: 'catalogue-service-create-v1',
                                label: 'Criar serviço',
                            },
                        ]"
                        :key="reference.id"
                        :href="contract.url(reference.id)"
                        external
                        >{{ reference.label }}</AppButton
                    >
                </div>
                <p class="text-sm text-[var(--ui-ink-2)]">
                    Os pedidos de criação exigem uma chave de idempotência. Ao
                    repetir um pedido sem resposta, mantenha a mesma chave e os
                    mesmos dados. Esta API ainda não permite emitir ou cancelar
                    documentos fiscais, receber pagamentos ou enviar documentos
                    à AGT.
                </p>
            </section>
            <section class="space-y-4" aria-label="Acessos existentes">
                <h2 class="text-lg font-semibold">Acessos existentes</h2>
                <p
                    v-if="!integrations.length"
                    class="text-sm text-[var(--ui-ink-2)]"
                >
                    Ainda não existem integrações nesta empresa.
                </p>
                <article
                    v-for="integration in integrations"
                    :key="integration.public_id"
                    class="space-y-4 rounded-xl border border-[var(--ui-line)] p-5"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h3 class="font-semibold">
                                {{ integration.name }}
                            </h3>
                            <p class="text-sm text-[var(--ui-ink-2)]">
                                {{ integration.environment }} ·
                                {{
                                    integration.revoked_at
                                        ? 'Revogado'
                                        : 'Activo'
                                }}
                            </p>
                        </div>
                        <AppButton
                            v-if="!integration.revoked_at"
                            :disabled="busy"
                            @click="change(integration, 'revoke')"
                            >Revogar integração</AppButton
                        >
                    </div>
                    <p class="text-xs break-all text-[var(--ui-ink-2)]">
                        Workspace: {{ workspacePublicId }}<br />Empresa:
                        {{ integration.entity_public_id }}
                    </p>
                    <p class="text-sm break-words text-[var(--ui-ink-2)]">
                        {{ integration.scopes.join(', ') }}
                    </p>
                    <ul class="space-y-3">
                        <li
                            v-for="credential in integration.credentials"
                            :key="credential.public_id"
                            class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--ui-line)] pt-3"
                        >
                            <div class="text-sm">
                                <p class="font-mono text-xs break-all">
                                    {{ credential.public_id }}
                                </p>
                                <p>
                                    {{
                                        credential.revoked_at
                                            ? 'Revogada'
                                            : credential.active
                                              ? 'Expira'
                                              : 'Expirada'
                                    }}
                                    ·
                                    {{
                                        date(
                                            credential.revoked_at ??
                                                credential.expires_at,
                                        )
                                    }}
                                </p>
                                <p class="text-[var(--ui-ink-2)]">
                                    {{
                                        credential.last_used_at
                                            ? `Último uso: ${date(credential.last_used_at)}`
                                            : 'Sem utilização registada'
                                    }}
                                </p>
                            </div>
                            <div
                                v-if="
                                    !integration.revoked_at && credential.active
                                "
                                class="flex gap-2"
                            >
                                <AppButton
                                    v-if="
                                        entitled &&
                                        integration.credentials.filter(
                                            (item) => item.active,
                                        ).length < 2
                                    "
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
                                    :disabled="busy"
                                    @click="
                                        change(
                                            integration,
                                            'revoke',
                                            credential,
                                        )
                                    "
                                    >Revogar chave</AppButton
                                >
                            </div>
                        </li>
                    </ul>
                    <AppButton
                        v-if="
                            entitled &&
                            !integration.revoked_at &&
                            !integration.credentials.some((item) => item.active)
                        "
                        :disabled="busy"
                        @click="change(integration, 'replace')"
                        >Criar chave de substituição</AppButton
                    >
                </article>
                <p class="text-sm text-[var(--ui-ink-2)]">
                    Ao rodar uma chave, a anterior mantém-se válida durante, no
                    máximo, 24 horas. Actualize o seu sistema e revogue a
                    anterior quando terminar.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
