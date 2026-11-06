<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import DateInput from '@/components/DateInput.vue';
import DraftRecoveryNotice from '@/components/DraftRecoveryNotice.vue';
import FormField from '@/components/FormField.vue';
import { followUpDate } from '@/lib/collection-follow-ups';
import type { CollectionFollowUp } from '@/lib/collection-follow-ups';
import { useDraftRecovery } from '@/lib/use-draft-recovery';
import { update } from '@/routes/customers/follow-up';

const props = defineProps<{
    customerPublicId: string;
    followUp: CollectionFollowUp;
    canUpdate: boolean;
    recoveryScope: (string | number)[];
}>();
const form = useForm({
    revision: props.followUp.revision,
    next_contact_on: props.followUp.next_contact_on,
    promised_payment_on: props.followUp.promised_payment_on,
    note: props.followUp.note ?? '',
    completed: props.followUp.completed,
});
const recovery = useDraftRecovery({
    scope: () => props.recoveryScope,
    document: () => `collection-follow-up:${props.customerPublicId}`,
    version: () => String(props.followUp.revision),
    data: () => ({
        next_contact_on: form.next_contact_on,
        promised_payment_on: form.promised_payment_on,
        note: form.note,
    }),
    restore: (data) => Object.assign(form, data),
    enabled: () => props.canUpdate,
});
function save(completed: boolean): void {
    if (form.processing || recovery.pending.value) {
        return;
    }

    recovery.beforeSave();
    form.completed = completed;
    form.put(update.url(props.customerPublicId), {
        preserveScroll: true,
        onSuccess: loadCurrentFollowUp,
    });
}
function loadCurrentFollowUp(): void {
    form.defaults({
        revision: props.followUp.revision,
        next_contact_on: props.followUp.next_contact_on,
        promised_payment_on: props.followUp.promised_payment_on,
        note: props.followUp.note ?? '',
        completed: props.followUp.completed,
    });
    form.reset();
    form.clearErrors();
    recovery.beforeSave();
    recovery.saved();
}

function discardAndReload(): void {
    router.reload({ only: ['followUp'], onSuccess: loadCurrentFollowUp });
}
</script>

<template>
    <section
        class="rounded-2xl surface p-5 sm:p-6"
        aria-labelledby="follow-up-heading"
    >
        <h2 id="follow-up-heading" class="text-lg font-semibold">
            Seguimento de cobrança
        </h2>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Nota interna partilhada com a equipa. Guardar ou concluir não envia
            mensagens nem regista pagamentos.
        </p>
        <p
            v-if="followUp.completed"
            class="mt-3 text-sm font-semibold text-emerald-700 dark:text-emerald-400"
        >
            Seguimento concluído
        </p>
        <p v-if="followUp.updated_by" class="mt-3 text-xs text-zinc-500">
            Última alteração por {{ followUp.updated_by }}.
        </p>
        <form
            v-if="canUpdate"
            class="mt-5 space-y-4"
            @submit.prevent="save(false)"
        >
            <DraftRecoveryNotice
                :available="recovery.pending.value !== null"
                :conflict="recovery.hasConflict.value"
                :message="recovery.message.value"
                @restore="recovery.restore"
                @discard="recovery.discard"
            />
            <fieldset
                :disabled="form.processing || recovery.pending.value !== null"
                :inert="recovery.pending.value !== null"
                class="space-y-4"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        for="follow-up-contact"
                        label="Próximo contacto"
                        :error="form.errors.next_contact_on"
                    >
                        <DateInput
                            id="follow-up-contact"
                            v-model="form.next_contact_on"
                            :disabled="form.processing"
                        />
                    </FormField>
                    <FormField
                        for="follow-up-promise"
                        label="Pagamento prometido para"
                        :error="form.errors.promised_payment_on"
                    >
                        <DateInput
                            id="follow-up-promise"
                            v-model="form.promised_payment_on"
                            :disabled="form.processing"
                        />
                    </FormField>
                </div>
                <FormField
                    for="follow-up-note"
                    label="Nota para a equipa"
                    :error="form.errors.note"
                >
                    <textarea
                        id="follow-up-note"
                        v-model="form.note"
                        class="block form-input w-full"
                        rows="3"
                        maxlength="2000"
                        placeholder="Ex.: confirmou que paga na sexta-feira; voltar a ligar de manhã."
                    />
                </FormField>
                <p
                    v-if="form.errors.revision"
                    role="alert"
                    class="text-sm text-red-700 dark:text-red-400"
                >
                    {{ form.errors.revision }}
                </p>
                <div class="flex flex-wrap gap-3">
                    <AppButton type="submit" variant="gold">{{
                        form.processing
                            ? 'A guardar…'
                            : followUp.completed
                              ? 'Reabrir seguimento'
                              : 'Guardar seguimento'
                    }}</AppButton>
                    <AppButton
                        v-if="!followUp.completed && followUp.revision > 0"
                        @click="save(true)"
                        >Concluir seguimento</AppButton
                    >
                    <AppButton
                        v-if="form.errors.revision"
                        variant="quiet"
                        @click="discardAndReload"
                        >Descartar alterações e carregar versão da
                        equipa</AppButton
                    >
                </div>
            </fieldset>
        </form>
        <dl v-else class="mt-5 space-y-2 text-sm">
            <div>
                <dt class="text-zinc-500">Próximo contacto</dt>
                <dd>{{ followUpDate(followUp.next_contact_on) }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">Pagamento prometido</dt>
                <dd>{{ followUpDate(followUp.promised_payment_on) }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">Nota interna</dt>
                <dd class="whitespace-pre-wrap">
                    {{ followUp.note ?? 'Sem nota.' }}
                </dd>
            </div>
        </dl>
    </section>
</template>
