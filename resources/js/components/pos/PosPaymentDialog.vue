<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { useHttp } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, LoaderCircle, Printer } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import {
    attemptFor,
    describeSaleFailure,
    firstValidationMessage,
    formatMinor,
    formatWholeMinor,
    MAX_CHANGE_MINOR,
    MAX_TOTAL_MINOR,
    minorToInputString,
    parseMoneyToMinor,
    quickTenders,
    saleSignature,
} from '@/lib/pos';
import type { SaleAttempt, SaleFailure, SaleLinePayload } from '@/lib/pos';
import {
    buttonGold,
    buttonInk,
    buttonOutline,
    currencyLabel,
} from '@/lib/pos-ui';
import { store as storeSale } from '@/routes/pos/sales';
import type {
    PosCustomerChoice,
    PosPaymentMethod,
    PosSaleResponse,
} from '@/types/pos';

const props = defineProps<{
    open: boolean;
    sessionId: string;
    totalMinor: number;
    unitCount: number;
    customer: PosCustomerChoice;
    customerLabel: string;
    lines: SaleLinePayload[];
    paymentMethods: PosPaymentMethod[];
    currencyCode: string;
}>();

const emit = defineEmits<{
    /** The cashier went back without charging. */
    close: [];
    /** The sale was issued: the till updates itself while this dialog stays. */
    completed: [response: PosSaleResponse];
    /** The total changed under the cashier's feet: go back and look at the cart. */
    review: [];
    /** «Nova venda»: the sale is done and the dialog can go. */
    finished: [];
    afterLeave: [];
}>();

type SalePayload = {
    client_key: string;
    customer_public_id: string | null;
    customer: { name: string; tax_identification_number: string } | null;
    lines: SaleLinePayload[];
    payment_method: string;
    tendered_minor: number | null;
    expected_total_minor: number;
};

/** Longer than any honest answer; after this the outcome is simply unknown. */
const REQUEST_TIMEOUT_MS = 30_000;
const CASH = 'NU';

const http = useHttp<SalePayload, PosSaleResponse>({
    client_key: '',
    customer_public_id: null,
    customer: null,
    lines: [],
    payment_method: CASH,
    tendered_minor: null,
    expected_total_minor: 0,
});

type Stage = 'form' | 'sending' | 'done';

const stage = ref<Stage>('form');
const method = ref(CASH);
const tenderedText = ref('');
const failure = ref<SaleFailure | null>(null);
const mismatch = ref(false);
const doubt = ref(false);
const result = ref<PosSaleResponse | null>(null);
const announcement = ref('');
const changeAnnouncement = ref('');

const tenderField = ref<HTMLInputElement | null>(null);
const failureWell = ref<HTMLElement | null>(null);
const newSaleButton = ref<HTMLButtonElement | null>(null);

/** What was on the table when the dialog opened; the cart may be cleared later. */
const snapshot = ref({ total: 0, units: 0, customerLabel: '' });

const suffix = computed(() => currencyLabel(props.currencyCode));
const isCash = computed(() => method.value === CASH);
const isSending = computed(() => stage.value === 'sending');

const tenderedMinor = computed(() => parseMoneyToMinor(tenderedText.value));
const changeMinor = computed(() =>
    tenderedMinor.value === null
        ? null
        : tenderedMinor.value - snapshot.value.total,
);
const tenders = computed(() => quickTenders(snapshot.value.total));

/** Far more than is owed: almost certainly a barcode read into the field. */
const tenderTooHigh = computed(
    () => changeMinor.value !== null && changeMinor.value > MAX_CHANGE_MINOR,
);

const canConfirm = computed(
    () =>
        stage.value === 'form' &&
        snapshot.value.total > 0 &&
        snapshot.value.total <= MAX_TOTAL_MINOR &&
        failure.value?.kind !== 'expired' &&
        (!isCash.value ||
            (changeMinor.value !== null &&
                changeMinor.value >= 0 &&
                !tenderTooHigh.value)),
);

const confirmLabel = computed(() =>
    failure.value?.kind === 'unanswered'
        ? 'Tentar outra vez'
        : 'Emitir factura-recibo',
);

/* ------------------------------------------------------- the saved attempt */

function attemptKey(): string {
    return `pos-sale-attempt:${props.sessionId}`;
}

function readAttempt(): SaleAttempt | null {
    try {
        const raw = window.sessionStorage.getItem(attemptKey());
        const parsed: unknown = raw === null ? null : JSON.parse(raw);

        if (
            typeof parsed === 'object' &&
            parsed !== null &&
            typeof (parsed as SaleAttempt).key === 'string' &&
            typeof (parsed as SaleAttempt).signature === 'string'
        ) {
            return {
                key: (parsed as SaleAttempt).key,
                signature: (parsed as SaleAttempt).signature,
                unanswered: (parsed as SaleAttempt).unanswered === true,
            };
        }
    } catch {
        // Unreadable storage is the same as no earlier attempt.
    }

    return null;
}

function writeAttempt(attempt: SaleAttempt | null): void {
    try {
        if (attempt === null) {
            window.sessionStorage.removeItem(attemptKey());
        } else {
            window.sessionStorage.setItem(
                attemptKey(),
                JSON.stringify(attempt),
            );
        }
    } catch {
        // Without storage a reload forgets the key; the dialog still holds it.
    }
}

let attempt: SaleAttempt | null = null;

function currentSignature(): string {
    return saleSignature(
        props.lines,
        {
            kind: props.customer.kind,
            id: props.customer.kind === 'saved' ? props.customer.public_id : '',
            name: props.customer.kind === 'final' ? '' : props.customer.name,
            nif: props.customer.kind === 'final' ? '' : props.customer.nif,
        },
        snapshot.value.total,
    );
}

/* ------------------------------------------------------------ opening it */

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        stage.value = 'form';
        failure.value = null;
        mismatch.value = false;
        result.value = null;
        announcement.value = '';
        method.value =
            props.paymentMethods.find((entry) => entry.value === CASH)?.value ??
            props.paymentMethods[0]?.value ??
            CASH;
        snapshot.value = {
            total: props.totalMinor,
            units: props.unitCount,
            customerLabel: props.customerLabel,
        };
        // The exact amount, selected: a customer who pays exactly needs only Enter.
        tenderedText.value = minorToInputString(props.totalMinor);

        // A cart that is unchanged since an unanswered try keeps its key.
        const chosen = attemptFor(readAttempt(), currentSignature());

        attempt = chosen.attempt;
        doubt.value = chosen.priorUnanswered;
        writeAttempt(attempt);
    },
    { immediate: true },
);

/* ------------------------------------------------------------ the request */

let timeout: ReturnType<typeof setTimeout> | undefined;
let timedOut = false;

function warnBeforeLeaving(event: BeforeUnloadEvent): void {
    event.preventDefault();
}

watch(
    () => isSending.value || attempt?.unanswered === true,
    (guard) => {
        if (guard) {
            window.addEventListener('beforeunload', warnBeforeLeaving);
        } else {
            window.removeEventListener('beforeunload', warnBeforeLeaving);
        }
    },
);

onBeforeUnmount(() => {
    clearTimeout(timeout);
    clearTimeout(changeTimer);
    window.removeEventListener('beforeunload', warnBeforeLeaving);
});

function showFailure(next: SaleFailure): void {
    failure.value = next;

    void nextTick(() => failureWell.value?.focus());
}

async function confirm(): Promise<void> {
    if (!canConfirm.value || attempt === null) {
        return;
    }

    stage.value = 'sending';
    failure.value = null;
    mismatch.value = false;
    timedOut = false;

    http.client_key = attempt.key;
    http.customer_public_id =
        props.customer.kind === 'saved' ? props.customer.public_id : null;
    http.customer =
        props.customer.kind === 'typed'
            ? {
                  name: props.customer.name,
                  tax_identification_number: props.customer.nif,
              }
            : null;
    http.lines = props.lines;
    http.payment_method = method.value;
    http.tendered_minor = isCash.value ? tenderedMinor.value : null;
    http.expected_total_minor = snapshot.value.total;
    http.clearErrors();

    timeout = setTimeout(() => {
        timedOut = true;
        http.cancel();
    }, REQUEST_TIMEOUT_MS);

    try {
        const response = await http.post(storeSale.url(props.sessionId));

        if (!response) {
            // 422: the server looked at the sale and refused it; nothing exists.
            const refusal = firstValidationMessage(http.errors);

            attempt = { ...attempt, unanswered: false };
            writeAttempt(attempt);
            mismatch.value = refusal?.field === 'expected_total_minor';
            stage.value = 'form';
            showFailure({
                kind: 'rejected',
                message: refusal?.message ?? 'A venda não foi emitida.',
            });

            return;
        }

        succeed(response);
    } catch (error) {
        const reason = describeSaleFailure(error);

        attempt = { ...attempt, unanswered: reason.kind === 'unanswered' };
        writeAttempt(attempt);
        stage.value = 'form';
        showFailure(
            timedOut ? describeSaleFailure(new Error('timeout')) : reason,
        );
    } finally {
        clearTimeout(timeout);
    }
}

function succeed(response: PosSaleResponse): void {
    result.value = response;
    stage.value = 'done';
    doubt.value = false;
    attempt = null;
    writeAttempt(null);

    const change =
        response.sale.payment_method === CASH
            ? ` Troco ${formatMinor(response.sale.change_minor)} ${suffix.value}.`
            : '';

    announcement.value = `Venda emitida.${change}`;
    emit('completed', response);

    void nextTick(() => newSaleButton.value?.focus());
}

/* ------------------------------------------------------------ the fields */

let changeTimer: ReturnType<typeof setTimeout> | undefined;

watch([tenderedMinor, method], () => {
    clearTimeout(changeTimer);
    changeTimer = setTimeout(() => {
        if (stage.value !== 'form' || !isCash.value) {
            changeAnnouncement.value = '';

            return;
        }

        changeAnnouncement.value =
            changeMinor.value === null
                ? 'Valor recebido inválido.'
                : tenderTooHigh.value
                  ? 'O valor recebido está muito acima do total. Confirme-o.'
                  : changeMinor.value >= 0
                    ? `Troco ${formatMinor(changeMinor.value)} ${suffix.value}.`
                    : `Faltam ${formatMinor(-changeMinor.value)} ${suffix.value}.`;
    }, 600);
});

function chooseMethod(value: string): void {
    method.value = value;
}

function chooseTender(amount: number): void {
    tenderedText.value = minorToInputString(amount);
    tenderField.value?.focus({ preventScroll: true });
}

function selectAll(event: FocusEvent): void {
    (event.target as HTMLInputElement).select();
}

function printReceipt(): void {
    if (result.value !== null) {
        // Called straight from the click so a popup blocker lets it through.
        window.open(result.value.sale.receipt_url, '_blank');
    }
}

function requestClose(): void {
    if (isSending.value) {
        return;
    }

    if (stage.value === 'done') {
        emit('finished');

        return;
    }

    emit('close');
}

function review(): void {
    emit('review');
}

function reload(): void {
    window.location.reload();
}

const methodPill =
    'flex min-h-10 cursor-pointer items-center justify-center rounded-full px-4 py-2 text-center text-sm font-medium text-zinc-700 ring-1 ring-zinc-900/10 ring-inset select-none hover:bg-zinc-900/[0.04] has-checked:bg-brand-950 has-checked:text-white has-checked:ring-brand-950 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand-600 pointer-coarse:min-h-11 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5 dark:has-checked:bg-zinc-100 dark:has-checked:text-brand-950 dark:has-checked:ring-zinc-100';
</script>

<template>
    <TransitionRoot
        as="template"
        :show="open"
        @after-leave="emit('afterLeave')"
    >
        <Dialog
            class="relative z-50"
            :initial-focus="tenderField"
            @close="requestClose"
        >
            <TransitionChild
                as="template"
                enter="ease-out duration-200"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-out duration-150"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 dialog-scrim" />
            </TransitionChild>

            <div class="fixed inset-0 z-50 overflow-y-auto overscroll-contain">
                <div
                    class="flex min-h-full items-end justify-center pt-10 sm:items-center sm:p-6"
                >
                    <TransitionChild
                        as="template"
                        enter="transition-[opacity,translate,scale] ease-out duration-200"
                        enter-from="max-sm:translate-y-full sm:scale-95 sm:opacity-0"
                        enter-to="max-sm:translate-y-0 sm:scale-100 sm:opacity-100"
                        leave="transition-[opacity,translate,scale] ease-out duration-150"
                        leave-from="max-sm:translate-y-0 sm:scale-100 sm:opacity-100"
                        leave-to="max-sm:translate-y-full sm:scale-95 sm:opacity-0"
                    >
                        <DialogPanel
                            class="relative w-full max-w-xl dialog-panel p-6 max-sm:rounded-b-none max-sm:pb-[max(1.5rem,env(safe-area-inset-bottom))] sm:p-8"
                        >
                            <p class="sr-only" role="status" aria-live="polite">
                                {{ announcement }}
                            </p>
                            <p class="sr-only" role="status" aria-live="polite">
                                {{ changeAnnouncement }}
                            </p>

                            <!-- ------------------------------------ issued -->
                            <div v-if="stage === 'done' && result">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="grid size-10 shrink-0 place-items-center rounded-full bg-lime-300 text-lime-950"
                                    >
                                        <CircleCheck
                                            class="size-6"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <DialogTitle
                                        class="min-w-0 text-base font-semibold text-zinc-950 dark:text-white"
                                    >
                                        <span
                                            v-if="result.sale.document_no"
                                            class="font-mono [overflow-wrap:anywhere]"
                                            >{{ result.sale.document_no }}</span
                                        >
                                        <span v-else>Venda</span>
                                        emitida
                                    </DialogTitle>
                                </div>

                                <div class="mt-8">
                                    <template
                                        v-if="
                                            result.sale.payment_method === CASH
                                        "
                                    >
                                        <p
                                            class="eyebrow text-zinc-500 dark:text-zinc-400"
                                        >
                                            Troco
                                        </p>
                                        <p
                                            class="mt-2 numeric text-[clamp(2.25rem,12vw,3.5rem)] leading-none font-semibold tracking-[-0.03em] text-zinc-950 dark:text-white"
                                        >
                                            {{
                                                formatMinor(
                                                    result.sale.change_minor,
                                                )
                                            }}<span
                                                class="ms-2 text-base font-normal tracking-normal text-zinc-500 dark:text-zinc-400"
                                                >{{ suffix }}</span
                                            >
                                        </p>
                                        <p
                                            class="mt-3 numeric text-sm text-zinc-600 dark:text-zinc-400"
                                        >
                                            Total
                                            {{
                                                formatMinor(
                                                    result.sale.total_minor,
                                                )
                                            }}
                                            {{ suffix }} · Recebido
                                            {{
                                                formatMinor(
                                                    result.sale.tendered_minor,
                                                )
                                            }}
                                            {{ suffix }}
                                        </p>
                                    </template>
                                    <template v-else>
                                        <p
                                            class="eyebrow text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{
                                                result.sale.payment_method_label
                                            }}
                                        </p>
                                        <p
                                            class="mt-2 numeric text-[clamp(2.25rem,12vw,3.5rem)] leading-none font-semibold tracking-[-0.03em] text-zinc-950 dark:text-white"
                                        >
                                            {{
                                                formatMinor(
                                                    result.sale.total_minor,
                                                )
                                            }}<span
                                                class="ms-2 text-base font-normal tracking-normal text-zinc-500 dark:text-zinc-400"
                                                >{{ suffix }}</span
                                            >
                                        </p>
                                    </template>
                                    <p
                                        v-if="result.sale.replayed"
                                        class="mt-4 text-sm/6 text-zinc-600 dark:text-zinc-400"
                                    >
                                        Esta venda já tinha sido emitida. Não
                                        foi cobrada outra vez.
                                    </p>
                                </div>

                                <div
                                    class="mt-8 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                                >
                                    <button
                                        type="button"
                                        :class="buttonOutline"
                                        @click="printReceipt"
                                    >
                                        <Printer
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Imprimir talão
                                    </button>
                                    <button
                                        ref="newSaleButton"
                                        type="button"
                                        :class="buttonGold"
                                        @click="emit('finished')"
                                    >
                                        Nova venda
                                    </button>
                                </div>
                            </div>

                            <!-- ------------------------------------- charge -->
                            <form v-else novalidate @submit.prevent="confirm">
                                <div :inert="isSending">
                                    <DialogTitle
                                        class="eyebrow text-zinc-500 dark:text-zinc-400"
                                    >
                                        Cobrar
                                    </DialogTitle>
                                    <p
                                        class="mt-2 numeric text-[clamp(1.875rem,9vw,2.5rem)] leading-none font-semibold tracking-[-0.03em] text-zinc-950 dark:text-white"
                                    >
                                        {{ formatMinor(snapshot.total)
                                        }}<span
                                            class="ms-2 text-base font-normal tracking-normal text-zinc-500 dark:text-zinc-400"
                                            >{{ suffix }}</span
                                        >
                                    </p>
                                    <p
                                        class="mt-2 text-sm [overflow-wrap:anywhere] text-zinc-600 dark:text-zinc-400"
                                    >
                                        {{ snapshot.customerLabel }} ·
                                        {{ snapshot.units }}
                                        {{
                                            snapshot.units === 1
                                                ? 'artigo'
                                                : 'artigos'
                                        }}
                                    </p>

                                    <fieldset class="mt-6">
                                        <legend
                                            class="mb-2.5 eyebrow text-zinc-500 dark:text-zinc-400"
                                        >
                                            Meio de pagamento
                                        </legend>
                                        <div
                                            class="flex flex-wrap gap-2"
                                            role="radiogroup"
                                            aria-label="Meio de pagamento"
                                        >
                                            <label
                                                v-for="option in paymentMethods"
                                                :key="option.value"
                                                :class="methodPill"
                                            >
                                                <input
                                                    type="radio"
                                                    name="pos-payment-method"
                                                    class="sr-only"
                                                    :value="option.value"
                                                    :checked="
                                                        method === option.value
                                                    "
                                                    @change="
                                                        chooseMethod(
                                                            option.value,
                                                        )
                                                    "
                                                />
                                                {{ option.label }}
                                            </label>
                                        </div>
                                    </fieldset>

                                    <div v-if="isCash" class="mt-6">
                                        <label
                                            for="pos-tendered"
                                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                                            >Recebido</label
                                        >
                                        <div class="relative mt-2">
                                            <input
                                                id="pos-tendered"
                                                ref="tenderField"
                                                v-model="tenderedText"
                                                type="text"
                                                inputmode="decimal"
                                                autocomplete="off"
                                                spellcheck="false"
                                                enterkeyhint="done"
                                                class="block h-14 form-input pe-14 text-end numeric text-2xl"
                                                :aria-invalid="
                                                    tenderedMinor === null
                                                        ? 'true'
                                                        : undefined
                                                "
                                                aria-describedby="pos-change"
                                                @focus="selectAll"
                                            />
                                            <span
                                                class="pointer-events-none absolute inset-y-0 inset-e-4 grid place-items-center text-sm text-zinc-500 dark:text-zinc-400"
                                                aria-hidden="true"
                                                >{{ suffix }}</span
                                            >
                                        </div>

                                        <div
                                            v-if="tenders.length > 1"
                                            class="mt-3 flex flex-wrap gap-2"
                                        >
                                            <button
                                                v-for="(
                                                    amount, index
                                                ) in tenders"
                                                :key="amount"
                                                type="button"
                                                :class="[
                                                    buttonOutline,
                                                    'numeric',
                                                ]"
                                                @click="chooseTender(amount)"
                                            >
                                                {{
                                                    index === 0
                                                        ? 'Valor exacto'
                                                        : formatWholeMinor(
                                                              amount,
                                                          )
                                                }}
                                            </button>
                                        </div>

                                        <div
                                            id="pos-change"
                                            class="mt-4 flex min-h-12 items-center justify-between gap-4 rounded-2xl bg-zinc-900/[0.04] px-4 py-2 dark:bg-white/[0.04]"
                                        >
                                            <template
                                                v-if="
                                                    changeMinor !== null &&
                                                    changeMinor < 0
                                                "
                                            >
                                                <span
                                                    class="flex items-center gap-2 text-sm font-medium text-rose-700 dark:text-rose-400"
                                                >
                                                    <CircleAlert
                                                        class="size-4 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    Faltam
                                                </span>
                                                <span
                                                    class="numeric text-lg font-semibold text-rose-700 dark:text-rose-400"
                                                    >{{
                                                        formatMinor(
                                                            -changeMinor,
                                                        )
                                                    }}<span
                                                        class="ms-1 text-xs font-normal"
                                                        >{{ suffix }}</span
                                                    ></span
                                                >
                                            </template>
                                            <template v-else-if="tenderTooHigh">
                                                <span
                                                    class="flex items-center gap-2 text-sm font-medium text-rose-700 dark:text-rose-400"
                                                >
                                                    <CircleAlert
                                                        class="size-4 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    O valor recebido está muito
                                                    acima do total. Confirme-o.
                                                </span>
                                            </template>
                                            <template
                                                v-else-if="changeMinor === null"
                                            >
                                                <span
                                                    class="text-sm text-zinc-600 dark:text-zinc-400"
                                                    >Indique o valor recebido,
                                                    por exemplo 5 000.</span
                                                >
                                            </template>
                                            <template v-else>
                                                <span
                                                    class="text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                                    >Troco</span
                                                >
                                                <span
                                                    class="numeric text-lg font-semibold text-zinc-950 dark:text-white"
                                                    >{{
                                                        formatMinor(
                                                            changeMinor,
                                                        )
                                                    }}<span
                                                        class="ms-1 text-xs font-normal text-zinc-500 dark:text-zinc-400"
                                                        >{{ suffix }}</span
                                                    ></span
                                                >
                                            </template>
                                        </div>
                                    </div>
                                    <p
                                        v-else
                                        class="mt-6 text-sm/6 text-zinc-600 dark:text-zinc-400"
                                    >
                                        Confirme o pagamento no terminal antes
                                        de emitir.
                                    </p>
                                </div>

                                <p
                                    v-if="doubt"
                                    class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                                >
                                    A tentativa anterior ficou sem resposta.
                                    Antes de cobrar, veja no resumo do turno se
                                    essa venda foi emitida.
                                </p>

                                <div
                                    v-if="failure"
                                    ref="failureWell"
                                    tabindex="-1"
                                    role="alert"
                                    class="mt-4 flex gap-3 rounded-2xl bg-rose-50 p-4 text-sm/6 text-rose-800 ring-1 ring-rose-200 outline-hidden dark:bg-rose-400/10 dark:text-rose-300 dark:ring-rose-400/20"
                                >
                                    <CircleAlert
                                        class="mt-0.5 size-5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <div class="min-w-0">
                                        <p>{{ failure.message }}</p>
                                        <div
                                            v-if="
                                                mismatch ||
                                                failure.kind === 'expired'
                                            "
                                            class="mt-3 flex flex-wrap gap-2"
                                        >
                                            <button
                                                v-if="mismatch"
                                                type="button"
                                                :class="buttonOutline"
                                                @click="review"
                                            >
                                                Rever venda
                                            </button>
                                            <button
                                                v-if="
                                                    failure.kind === 'expired'
                                                "
                                                type="button"
                                                :class="buttonOutline"
                                                @click="reload"
                                            >
                                                Recarregar a página
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                                >
                                    <button
                                        type="button"
                                        :class="buttonOutline"
                                        :disabled="isSending"
                                        @click="requestClose"
                                    >
                                        Voltar
                                    </button>
                                    <button
                                        type="submit"
                                        :aria-disabled="
                                            !canConfirm ? 'true' : undefined
                                        "
                                        :aria-busy="
                                            isSending ? 'true' : undefined
                                        "
                                        :class="[buttonInk, 'sm:min-w-60']"
                                        @click="
                                            !canConfirm &&
                                            $event.preventDefault()
                                        "
                                    >
                                        <LoaderCircle
                                            v-if="isSending"
                                            class="size-4 animate-spin-delayed"
                                            aria-hidden="true"
                                        />
                                        {{
                                            isSending
                                                ? 'A emitir…'
                                                : confirmLabel
                                        }}
                                    </button>
                                </div>
                            </form>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
