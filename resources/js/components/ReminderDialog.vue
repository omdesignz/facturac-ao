<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import RecordDialog from '@/components/RecordDialog.vue';

import { canGoByWhatsApp, whatsAppUrl } from '@/lib/reminder-draft';
import type { ReminderDraft } from '@/lib/reminder-draft';
import {
    show as showReminder,
    store as storeReminder,
    whatsapp as recordWhatsAppReminder,
} from '@/routes/debts/reminder';

const props = defineProps<{
    open: boolean;
    customer: { public_id: string; name: string } | null;
}>();

const emit = defineEmits<{ close: [] }>();

/**
 * A reminder for a customer who is late, written for you and sent only when you
 * say so. The words are yours to change: they go out under the company's name.
 */
const draft = ref<ReminderDraft | null>(null);
const loading = ref(false);
const failed = ref(false);

const form = useForm({ subject: '', body: '' });

/**
 * WhatsApp cannot say whether the person pressed send, so after opening it the
 * dialog asks, and only a yes is recorded as a reminder. One key per opening of
 * the dialog, so a second click records nothing twice.
 */
const whatsAppForm = useForm({ client_key: '' });
const asksWhetherSent = ref(false);

const whatsAppable = computed(
    () => canGoByWhatsApp(draft.value) && form.body.trim().length >= 20,
);

const whatsAppLink = computed(() =>
    draft.value && whatsAppable.value
        ? whatsAppUrl(draft.value, form.body)
        : null,
);

const sendable = computed(
    () =>
        draft.value !== null &&
        draft.value.to !== null &&
        draft.value.documents.length > 0,
);

async function load(publicId: string): Promise<void> {
    loading.value = true;
    failed.value = false;
    draft.value = null;
    form.reset();
    form.clearErrors();
    asksWhetherSent.value = false;
    whatsAppForm.client_key = crypto.randomUUID();

    try {
        const response = await fetch(showReminder.url(publicId), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        draft.value = (await response.json()) as ReminderDraft;
        form.subject = draft.value.subject;
        form.body = draft.value.body;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.open, props.customer?.public_id] as const,
    ([open, publicId]) => {
        if (open && publicId) {
            void load(publicId);
        }
    },
);

function money(minor: number): string {
    return new Intl.NumberFormat('pt-AO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100);
}

const currency = computed(() =>
    draft.value?.currency_code === 'AOA' ? 'Kz' : draft.value?.currency_code,
);

const lastSent = computed(() =>
    draft.value?.last_sent_at
        ? new Intl.DateTimeFormat('pt-AO', { dateStyle: 'medium' }).format(
              new Date(draft.value.last_sent_at),
          )
        : null,
);

function recordWhatsApp(): void {
    if (!props.customer) {
        return;
    }

    whatsAppForm.post(recordWhatsAppReminder.url(props.customer.public_id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

function send(): void {
    if (!props.customer || !sendable.value) {
        return;
    }

    form.post(storeReminder.url(props.customer.public_id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <RecordDialog
        eyebrow="Cobrança"
        :open="open"
        :title="`Lembrete para ${customer?.name ?? ''}`"
        description="Escrevemos o lembrete por si. Nada é enviado até confirmar."
        @close="emit('close')"
    >
        <div
            v-if="loading"
            class="space-y-3 py-2"
            role="status"
            aria-label="A preparar o lembrete"
        >
            <div class="h-4 w-2/3 rounded bg-zinc-900/[0.06]" />
            <div class="h-32 rounded-xl bg-zinc-900/[0.06]" />
        </div>

        <p
            v-else-if="failed"
            class="text-sm/6 text-rose-700 dark:text-rose-300"
            role="alert"
        >
            Não conseguimos preparar o lembrete. Feche e tente outra vez.
        </p>

        <form v-else-if="draft" class="space-y-5" @submit.prevent="send">
            <p
                v-if="draft.documents.length === 0"
                class="rounded-xl bg-lime-300/40 p-3 text-sm/6 text-lime-950 dark:text-lime-100"
            >
                Este cliente já não tem nada vencido.
            </p>

            <p
                v-else-if="draft.to === null && !canGoByWhatsApp(draft)"
                class="rounded-xl bg-[#fde7ea] p-3 text-sm/6 text-[#9a1b30]"
                role="alert"
            >
                Este cliente não tem email nem telemóvel registado. Adicione um
                na ficha do cliente para poder enviar.
            </p>

            <dl v-else class="grid gap-1 text-sm/6">
                <div v-if="draft.to === null" class="flex gap-2">
                    <dt class="w-20 shrink-0 text-zinc-500 dark:text-zinc-400">
                        Para
                    </dt>
                    <dd>Sem email registado: envie pelo WhatsApp.</dd>
                </div>
                <div v-else class="flex gap-2">
                    <dt class="w-20 shrink-0 text-zinc-500 dark:text-zinc-400">
                        Para
                    </dt>
                    <dd class="font-medium">{{ draft.to }}</dd>
                </div>
                <div class="flex gap-2">
                    <dt class="w-20 shrink-0 text-zinc-500 dark:text-zinc-400">
                        Em atraso
                    </dt>
                    <dd class="numeric font-medium">
                        {{ money(draft.total_minor) }} {{ currency }}
                        <span
                            class="font-normal text-zinc-500 dark:text-zinc-400"
                            >·
                            {{ draft.documents.length }}
                            {{
                                draft.documents.length === 1
                                    ? 'documento'
                                    : 'documentos'
                            }}</span
                        >
                    </dd>
                </div>
                <div v-if="lastSent" class="flex gap-2">
                    <dt class="w-20 shrink-0 text-zinc-500 dark:text-zinc-400">
                        Antes
                    </dt>
                    <dd>Último lembrete a {{ lastSent }}.</dd>
                </div>
            </dl>

            <template v-if="draft.documents.length > 0">
                <FormField
                    v-if="draft.to !== null"
                    for="reminder-subject"
                    label="Assunto"
                    :error="form.errors.subject"
                    ><input
                        id="reminder-subject"
                        v-model="form.subject"
                        type="text"
                        maxlength="150"
                        class="form-input"
                        :aria-invalid="
                            form.errors.subject ? 'true' : undefined
                        "
                /></FormField>

                <FormField
                    for="reminder-body"
                    label="Mensagem"
                    :error="form.errors.body"
                >
                    <textarea
                        id="reminder-body"
                        v-model="form.body"
                        rows="10"
                        maxlength="4000"
                        class="form-input"
                        :aria-invalid="form.errors.body ? 'true' : undefined"
                    />
                </FormField>
            </template>

            <div
                v-if="asksWhetherSent"
                class="rounded-xl bg-zinc-900/[0.04] p-4 dark:bg-white/[0.06]"
                role="group"
                aria-labelledby="reminder-whatsapp-question"
            >
                <p
                    id="reminder-whatsapp-question"
                    class="text-sm/6 font-medium text-zinc-950 dark:text-white"
                >
                    Enviou a mensagem no WhatsApp?
                </p>
                <p class="text-sm/6 text-zinc-500 dark:text-zinc-400">
                    Assim fica registado que {{ customer?.name }} foi lembrado.
                </p>
                <p
                    v-if="whatsAppForm.errors.client_key"
                    class="mt-2 text-sm/6 text-rose-700 dark:text-rose-300"
                    role="alert"
                >
                    {{ whatsAppForm.errors.client_key }}
                </p>
                <div
                    class="mt-3 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
                >
                    <AppButton
                        variant="outline"
                        @click="asksWhetherSent = false"
                    >
                        Não enviei
                    </AppButton>
                    <AppButton
                        variant="gold"
                        :disabled="whatsAppForm.processing"
                        @click="recordWhatsApp"
                    >
                        <LoaderCircle
                            v-if="whatsAppForm.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Sim, enviei
                    </AppButton>
                </div>
            </div>

            <div
                v-else
                class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
            >
                <AppButton variant="outline" @click="emit('close')">
                    Cancelar
                </AppButton>
                <AppButton
                    v-if="whatsAppLink"
                    :variant="draft.to === null ? 'gold' : 'outline'"
                    :href="whatsAppLink"
                    external
                    target="_blank"
                    rel="noopener noreferrer"
                    @click="asksWhetherSent = true"
                >
                    Abrir no WhatsApp
                </AppButton>
                <AppButton
                    v-if="draft.to !== null"
                    variant="gold"
                    type="submit"
                    :disabled="!sendable || form.processing"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    Enviar por email
                </AppButton>
            </div>
        </form>
    </RecordDialog>
</template>
