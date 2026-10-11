<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleMinus,
    CirclePlus,
    LoaderCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormError from '@/components/FormError.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import { focusFirstInvalid } from '@/lib/focus';
import {
    formatMinor,
    minorToDecimalString,
    parseMoneyToMinor,
} from '@/lib/pos';
import { forgetCart } from '@/lib/pos-cart';
import { buttonInk, buttonOutline, currencyLabel } from '@/lib/pos-ui';
import { close as closeSession } from '@/routes/pos/sessions';

const props = defineProps<{
    open: boolean;
    sessionId: string;
    registerName: string;
    expectedCashMinor: number;
    currencyCode: string;
    /** A sale is half made: closing now would throw it away. */
    hasUnfinishedSale: boolean;
}>();

const emit = defineEmits<{ close: []; afterLeave: [] }>();

const form = useForm({
    counted_cash: '',
    closing_notes: '',
});

const root = ref<HTMLElement | null>(null);
const tried = ref(false);
const suffix = computed(() => currencyLabel(props.currencyCode));

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

const countedMinor = computed(() => parseMoneyToMinor(form.counted_cash));

/** Counted minus expected: positive is a surplus, negative a shortfall. */
const difference = computed(() =>
    countedMinor.value === null
        ? null
        : countedMinor.value - props.expectedCashMinor,
);

const countedError = computed<string | undefined>(
    () =>
        form.errors.counted_cash ??
        (tried.value && countedMinor.value === null
            ? 'Indique o dinheiro contado em kwanzas, por exemplo 45 000 ou 45 000,50.'
            : undefined),
);

function submit(): void {
    tried.value = true;

    if (props.hasUnfinishedSale) {
        return;
    }

    if (countedMinor.value === null) {
        void focusFirstInvalid(root.value);

        return;
    }

    const counted = minorToDecimalString(countedMinor.value);

    form.transform((data) => ({
        ...data,
        counted_cash: counted,
        closing_notes: data.closing_notes.trim(),
    })).post(closeSession.url(props.sessionId), {
        onSuccess: () => forgetCart(props.sessionId),
        onError: () => void focusFirstInvalid(root.value),
    });
}
</script>

<template>
    <RecordDialog
        :open="open"
        eyebrow="Turno"
        :title="`Fechar ${registerName}`"
        description="Conte o dinheiro que está na gaveta. O fecho fica registado e já não se vende nesta caixa."
        @close="emit('close')"
        @after-leave="emit('afterLeave')"
    >
        <form ref="root" class="space-y-5" novalidate @submit.prevent="submit">
            <div
                class="flex items-baseline justify-between gap-4 rounded-2xl bg-zinc-900/[0.04] px-4 py-3 dark:bg-white/[0.04]"
            >
                <span class="text-sm text-zinc-700 dark:text-zinc-300"
                    >Numerário esperado</span
                >
                <span
                    class="numeric text-lg font-semibold text-zinc-950 dark:text-white"
                    >{{ formatMinor(expectedCashMinor)
                    }}<span
                        class="ms-1 text-xs font-normal text-zinc-500 dark:text-zinc-400"
                        >{{ suffix }}</span
                    ></span
                >
            </div>

            <div>
                <label
                    for="pos-counted-cash"
                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                    >Numerário contado</label
                >
                <div class="relative mt-2">
                    <input
                        id="pos-counted-cash"
                        v-model="form.counted_cash"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        spellcheck="false"
                        class="block h-14 form-input pe-14 text-end numeric text-2xl"
                        :aria-invalid="countedError ? 'true' : undefined"
                        :aria-describedby="
                            countedError
                                ? 'pos-counted-cash-error'
                                : 'pos-counted-cash-difference'
                        "
                    />
                    <span
                        class="pointer-events-none absolute inset-y-0 inset-e-4 grid place-items-center text-sm text-zinc-500 dark:text-zinc-400"
                        aria-hidden="true"
                        >{{ suffix }}</span
                    >
                </div>
                <FormError
                    id="pos-counted-cash-error"
                    :message="countedError"
                />

                <p
                    id="pos-counted-cash-difference"
                    class="mt-3 flex min-h-6 items-center gap-2 text-sm font-medium"
                    role="status"
                    :class="
                        difference === null || difference === 0
                            ? 'text-zinc-700 dark:text-zinc-300'
                            : 'text-amber-800 dark:text-amber-300'
                    "
                >
                    <template v-if="difference === null">
                        <span
                            class="font-normal text-zinc-600 dark:text-zinc-400"
                            >A diferença aparece quando indicar o valor.</span
                        >
                    </template>
                    <template v-else-if="difference === 0">
                        <CircleCheck
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        Certo
                    </template>
                    <template v-else-if="difference > 0">
                        <CirclePlus
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="numeric"
                            >Sobram {{ formatMinor(difference) }}
                            {{ suffix }}</span
                        >
                    </template>
                    <template v-else>
                        <CircleMinus
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="numeric"
                            >Faltam {{ formatMinor(-difference) }}
                            {{ suffix }}</span
                        >
                    </template>
                </p>
            </div>

            <div>
                <label
                    for="pos-closing-notes"
                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                    >Notas
                    <span class="font-normal text-zinc-500 dark:text-zinc-400"
                        >(opcional)</span
                    ></label
                >
                <textarea
                    id="pos-closing-notes"
                    v-model="form.closing_notes"
                    rows="2"
                    maxlength="500"
                    class="mt-2 block form-input"
                    :aria-invalid="
                        form.errors.closing_notes ? 'true' : undefined
                    "
                    :aria-describedby="
                        form.errors.closing_notes
                            ? 'pos-closing-notes-error'
                            : undefined
                    "
                />
                <FormError
                    id="pos-closing-notes-error"
                    :message="form.errors.closing_notes"
                />
            </div>

            <p
                v-if="hasUnfinishedSale"
                class="rounded-2xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                role="alert"
            >
                Há uma venda por terminar. Cobre-a ou limpe-a antes de fechar.
            </p>

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
                    :aria-disabled="hasUnfinishedSale ? 'true' : undefined"
                    :class="[buttonInk, 'sm:min-w-40']"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin-delayed"
                        aria-hidden="true"
                    />
                    Fechar caixa
                </button>
            </div>
        </form>
    </RecordDialog>
</template>
