<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormError from '@/components/FormError.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import { focusFirstInvalid } from '@/lib/focus';
import {
    formatMinor,
    minorToDecimalString,
    parseMoneyToMinor,
} from '@/lib/pos';
import { buttonGold, buttonOutline, currencyLabel } from '@/lib/pos-ui';
import { store as storeMovement } from '@/routes/pos/cash-movements';

const props = defineProps<{
    open: boolean;
    sessionId: string;
    /** Cash that should be in the drawer now: a withdrawal cannot exceed it. */
    expectedCashMinor: number;
    currencyCode: string;
}>();

const emit = defineEmits<{ close: []; afterLeave: [] }>();

const form = useForm({
    type: 'in',
    amount: '',
    reason: '',
});

const root = ref<HTMLElement | null>(null);
const tried = ref(false);

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
            tried.value = false;
        }
    },
);

const amountMinor = computed(() => parseMoneyToMinor(form.amount));
const isWithdrawal = computed(() => form.type === 'out');
const suffix = computed(() => currencyLabel(props.currencyCode));

const amountError = computed<string | undefined>(() => {
    if (form.errors.amount) {
        return form.errors.amount;
    }

    if (!tried.value) {
        return undefined;
    }

    if (amountMinor.value === null || amountMinor.value === 0) {
        return 'Indique o valor em kwanzas, acima de zero.';
    }

    if (isWithdrawal.value && amountMinor.value > props.expectedCashMinor) {
        return 'Não há tanto dinheiro na gaveta.';
    }

    return undefined;
});

const reasonError = computed<string | undefined>(() => {
    if (form.errors.reason) {
        return form.errors.reason;
    }

    return tried.value && form.reason.trim().length < 3
        ? 'Diga para que serve este movimento.'
        : undefined;
});

function submit(): void {
    tried.value = true;

    if (
        amountMinor.value === null ||
        amountError.value !== undefined ||
        reasonError.value !== undefined
    ) {
        void focusFirstInvalid(root.value);

        return;
    }

    const amount = minorToDecimalString(amountMinor.value);

    form.transform((data) => ({
        ...data,
        amount,
        reason: data.reason.trim(),
    })).post(storeMovement.url(props.sessionId), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: () => void focusFirstInvalid(root.value),
    });
}

const pill =
    'flex min-h-10 flex-1 cursor-pointer items-center justify-center rounded-full px-4 py-2 text-sm font-medium text-zinc-700 ring-1 ring-zinc-900/10 ring-inset select-none hover:bg-zinc-900/[0.04] has-checked:bg-brand-950 has-checked:text-white has-checked:ring-brand-950 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand-600 pointer-coarse:min-h-11 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5 dark:has-checked:bg-zinc-100 dark:has-checked:text-brand-950 dark:has-checked:ring-zinc-100';
</script>

<template>
    <RecordDialog
        :open="open"
        eyebrow="Turno"
        title="Movimento de caixa"
        description="Dinheiro posto na gaveta ou tirado dela durante o turno. Entra na conta do fecho."
        @close="emit('close')"
        @after-leave="emit('afterLeave')"
    >
        <form ref="root" class="space-y-5" novalidate @submit.prevent="submit">
            <fieldset>
                <legend class="sr-only">Tipo de movimento</legend>
                <div
                    class="flex gap-2"
                    role="radiogroup"
                    aria-label="Tipo de movimento"
                >
                    <label :class="pill">
                        <input
                            v-model="form.type"
                            type="radio"
                            name="type"
                            value="in"
                            class="sr-only"
                        />
                        Reforço
                    </label>
                    <label :class="pill">
                        <input
                            v-model="form.type"
                            type="radio"
                            name="type"
                            value="out"
                            class="sr-only"
                        />
                        Retirada
                    </label>
                </div>
                <FormError :message="form.errors.type" />
            </fieldset>

            <div>
                <label
                    for="pos-movement-amount"
                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                    >Valor</label
                >
                <div class="relative mt-2">
                    <input
                        id="pos-movement-amount"
                        v-model="form.amount"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        spellcheck="false"
                        class="block h-12 form-input pe-12 text-end numeric text-lg"
                        :aria-invalid="amountError ? 'true' : undefined"
                        :aria-describedby="
                            amountError
                                ? 'pos-movement-amount-error'
                                : isWithdrawal
                                  ? 'pos-movement-drawer'
                                  : undefined
                        "
                    />
                    <span
                        class="pointer-events-none absolute inset-y-0 inset-e-4 grid place-items-center text-sm text-zinc-500 dark:text-zinc-400"
                        aria-hidden="true"
                        >{{ suffix }}</span
                    >
                </div>
                <p
                    v-if="isWithdrawal"
                    id="pos-movement-drawer"
                    class="mt-2 numeric text-sm text-zinc-600 dark:text-zinc-400"
                >
                    Na gaveta: {{ formatMinor(expectedCashMinor) }} {{ suffix }}
                </p>
                <FormError
                    id="pos-movement-amount-error"
                    :message="amountError"
                />
            </div>

            <div>
                <label
                    for="pos-movement-reason"
                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                    >Motivo</label
                >
                <input
                    id="pos-movement-reason"
                    v-model="form.reason"
                    type="text"
                    maxlength="160"
                    autocomplete="off"
                    placeholder="Depósito no banco"
                    class="mt-2 block form-input"
                    :aria-invalid="reasonError ? 'true' : undefined"
                    :aria-describedby="
                        reasonError ? 'pos-movement-reason-error' : undefined
                    "
                />
                <FormError
                    id="pos-movement-reason-error"
                    :message="reasonError"
                />
            </div>

            <div
                class="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end"
            >
                <button
                    type="button"
                    :class="buttonOutline"
                    @click="emit('close')"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    :disabled="form.processing"
                    :class="[buttonGold, 'sm:min-w-36']"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin-delayed"
                        aria-hidden="true"
                    />
                    Registar
                </button>
            </div>
        </form>
    </RecordDialog>
</template>
