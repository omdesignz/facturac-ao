<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { Link } from '@inertiajs/vue3';
import { FileClock, X } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import PosCart from '@/components/pos/PosCart.vue';
import PosCashMovementDialog from '@/components/pos/PosCashMovementDialog.vue';
import PosCatalogue from '@/components/pos/PosCatalogue.vue';
import PosCloseShiftDialog from '@/components/pos/PosCloseShiftDialog.vue';
import PosCustomerDialog from '@/components/pos/PosCustomerDialog.vue';
import PosPaymentDialog from '@/components/pos/PosPaymentDialog.vue';
import PosShiftSummary from '@/components/pos/PosShiftSummary.vue';
import { formatMinor, formatQuantity } from '@/lib/pos';
import { forgetCart, forgetOtherCarts, usePosCart } from '@/lib/pos-cart';
import {
    buttonGold,
    buttonOutlineSmall,
    currencyLabel,
    formatClock,
} from '@/lib/pos-ui';
import { show as agtConnection } from '@/routes/agt/connection';
import type {
    PosCatalogueItem,
    PosCustomer,
    PosCustomerChoice,
    PosFinalConsumer,
    PosPaymentMethod,
    PosSaleResponse,
    PosSaleRow,
    PosSessionProps,
    PosTillSummary,
} from '@/types/pos';

const props = defineProps<{
    session: PosSessionProps;
    catalogue: PosCatalogueItem[];
    customers: PosCustomer[];
    agreedPrices: Record<string, Record<string, string>>;
    paymentMethods: PosPaymentMethod[];
    finalConsumer: PosFinalConsumer;
}>();

const cart = usePosCart({
    sessionId: props.session.public_id,
    catalogue: () => props.catalogue,
    customers: () => props.customers,
    agreedPrices: () => props.agreedPrices,
});

forgetOtherCarts(props.session.public_id);

/* What the server has told us since the page loaded. A reload brings the
   server's own figures, which supersede these, so they are dropped then. */
const liveStock = ref<Record<string, string | null>>({});
const liveSummary = ref<PosTillSummary | null>(null);
const liveSales = ref<PosSaleRow[] | null>(null);

watch(
    () => [props.session, props.catalogue],
    () => {
        liveStock.value = {};
        liveSummary.value = null;
        liveSales.value = null;
    },
);

const summary = computed(() => liveSummary.value ?? props.session.summary);
const recentSales = computed(
    () => liveSales.value ?? props.session.recent_sales,
);
const suffix = computed(() => currencyLabel(props.session.currency_code));

const customerLabel = computed(() =>
    cart.customer.value.kind === 'final'
        ? props.finalConsumer.name
        : cart.customer.value.name,
);

/* --------------------------------------------------------------- dialogs */

const customerOpen = ref(false);
const paymentOpen = ref(false);
const movementOpen = ref(false);
const closeOpen = ref(false);
const summaryOpen = ref(false);
const sheetOpen = ref(false);
let sheetWasOpen = false;

const isDesktop = useMediaQuery('(min-width: 64rem)');

watch(isDesktop, (desktop) => {
    if (desktop) {
        sheetOpen.value = false;
    }
});

const catalogueView = ref<InstanceType<typeof PosCatalogue> | null>(null);
const cartView = ref<InstanceType<typeof PosCart> | null>(null);
const sheetClose = ref<HTMLButtonElement | null>(null);

function finePointer(): boolean {
    return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
}

function anotherDialogOpen(): boolean {
    return (
        customerOpen.value ||
        paymentOpen.value ||
        movementOpen.value ||
        closeOpen.value ||
        summaryOpen.value ||
        document.querySelector('[role="dialog"]:not([data-pos-sheet])') !== null
    );
}

/** Back to the search field once nothing else is asking for attention. */
function settleFocus(): void {
    window.setTimeout(() => {
        if (!anotherDialogOpen() && !sheetOpen.value && finePointer()) {
            catalogueView.value?.focusSearch();
        }
        // After the dialog has gone and the browser has given focus back.
    }, 60);
}

function focusSearch(): void {
    if (!sheetOpen.value) {
        catalogueView.value?.focusSearch();
    }
}

/* ------------------------------------------------------------- the sale */

function addItem(item: PosCatalogueItem): void {
    const quantity = cart.add(item.public_id);

    if (quantity === null) {
        return;
    }

    catalogueView.value?.announce(
        `${item.name} adicionado. ${formatQuantity(quantity)} na venda.`,
    );
    cartView.value?.revealLine(item.public_id);
}

const chargeBlockedReason = computed<string | null>(() => {
    if (cart.isEmpty.value) {
        return 'empty';
    }

    return props.session.series_available ? null : 'series';
});

function startCharge(): void {
    if (chargeBlockedReason.value !== null || paymentOpen.value) {
        return;
    }

    // A quantity still being typed is committed by leaving its field.
    if (document.activeElement instanceof HTMLElement) {
        document.activeElement.blur();
    }

    sheetWasOpen = sheetOpen.value;
    sheetOpen.value = false;
    paymentOpen.value = true;
}

function leavePayment(): void {
    paymentOpen.value = false;

    if (sheetWasOpen && !cart.isEmpty.value) {
        sheetOpen.value = true;
    }

    sheetWasOpen = false;
}

function finishSale(): void {
    paymentOpen.value = false;
    sheetWasOpen = false;
    settleFocus();
}

function reviewSale(): void {
    leavePayment();
}

function saleCompleted(response: PosSaleResponse): void {
    cart.clear();
    forgetCart(props.session.public_id);

    liveSummary.value = response.summary;
    liveSales.value = [
        response.sale,
        ...recentSales.value.filter(
            (sale) => sale.public_id !== response.sale.public_id,
        ),
    ].slice(0, 10);

    if (response.stock.length > 0) {
        liveStock.value = {
            ...liveStock.value,
            ...Object.fromEntries(
                response.stock.map((row) => [
                    row.catalogue_item_public_id,
                    row.quantity_on_hand,
                ]),
            ),
        };
    }
}

function chooseCustomer(choice: PosCustomerChoice): void {
    cart.setCustomer(choice);
}

function openCustomer(): void {
    customerOpen.value = true;
}

/* ------------------------------------------------------------- shortcuts */

function typingInField(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
    );
}

/**
 * Registered while the capture phase is still ours, because the header's
 * command palette also listens for «/» and would open on top of the till.
 */
function onKeydown(event: KeyboardEvent): void {
    const modified = event.ctrlKey || event.metaKey;

    if (event.key === 'F9' || (modified && event.key === 'Enter')) {
        if (!anotherDialogOpen()) {
            event.preventDefault();
            event.stopPropagation();
            startCharge();
        }

        return;
    }

    const wantsSearch =
        event.key === 'F2' ||
        (event.key === '/' && !modified && !typingInField(event.target));

    if (wantsSearch && !anotherDialogOpen() && !sheetOpen.value) {
        event.preventDefault();
        event.stopPropagation();
        catalogueView.value?.focusSearch(true);
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown, true));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown, true));

const paymentLines = computed(() => cart.salePayloadLines());
</script>

<template>
    <div
        class="px-4 py-6 pb-[calc(6rem+env(safe-area-inset-bottom))] sm:px-6 lg:px-8 lg:pb-8"
    >
        <div class="mx-auto max-w-[120rem] space-y-4">
            <h1 class="sr-only">Ponto de venda</h1>
            <FlashBanner />

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <p
                    class="min-w-0 text-sm [overflow-wrap:anywhere] text-zinc-600 dark:text-zinc-400"
                >
                    <span class="font-semibold text-zinc-950 dark:text-white">{{
                        session.register.name
                    }}</span>
                    · {{ session.establishment.name }} · Aberto às
                    <span class="numeric">{{
                        formatClock(session.opened_at)
                    }}</span>
                </p>
                <div class="flex flex-wrap items-center gap-2 sm:ms-auto">
                    <button
                        type="button"
                        :class="buttonOutlineSmall"
                        @click="movementOpen = true"
                    >
                        Movimento de caixa
                    </button>
                    <button
                        type="button"
                        :class="buttonOutlineSmall"
                        @click="summaryOpen = true"
                    >
                        Resumo do turno
                    </button>
                    <button
                        type="button"
                        :class="buttonOutlineSmall"
                        @click="closeOpen = true"
                    >
                        Fechar caixa
                    </button>
                </div>
            </div>

            <div
                v-if="!session.series_available"
                class="flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                role="status"
            >
                <FileClock class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <p>
                    Não há série de Factura/Recibo disponível neste
                    estabelecimento. Não é possível vender até a AGT atribuir
                    uma série.
                    <Link
                        :href="agtConnection.url()"
                        class="rounded font-semibold underline underline-offset-4 focus-ring"
                        >Ver séries da AGT</Link
                    >
                </p>
            </div>

            <div
                class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_26rem] xl:grid-cols-[minmax(0,1fr)_27.5rem]"
            >
                <PosCatalogue
                    ref="catalogueView"
                    :items="catalogue"
                    :stock="liveStock"
                    :quantities="cart.quantities.value"
                    :currency-code="session.currency_code"
                    @add="addItem"
                />

                <aside
                    v-if="isDesktop"
                    aria-label="Venda"
                    class="sticky inset-bs-[calc(5rem+var(--impersonation-bar,0px))]"
                >
                    <div
                        class="flex max-h-[calc(100dvh-11rem-var(--impersonation-bar,0px))] flex-col overflow-clip rounded-3xl bg-zinc-900/[0.04] dark:bg-white/[0.04]"
                    >
                        <PosCart
                            ref="cartView"
                            :lines="cart.lines.value"
                            :totals="cart.totals.value"
                            :customer="cart.customer.value"
                            :final-consumer-name="finalConsumer.name"
                            :currency-code="session.currency_code"
                            :series-available="session.series_available"
                            @pick-customer="openCustomer"
                            @charge="startCharge"
                            @clear="cart.clear()"
                            @step="cart.step"
                            @quantity="cart.setQuantity"
                            @discount="cart.setDiscount"
                            @remove="cart.remove"
                            @focus-search="focusSearch"
                        />
                    </div>
                </aside>
            </div>
        </div>

        <!-- Below lg the sale lives behind this bar, always one thumb away. -->
        <div
            v-if="!isDesktop"
            class="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-900/[0.07] bg-stone-50/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-xl sm:px-6 dark:border-white/10 dark:bg-zinc-950/95"
        >
            <div class="mx-auto flex max-w-[120rem] items-center gap-3">
                <div class="min-w-0 flex-1">
                    <p class="numeric text-xs text-zinc-600 dark:text-zinc-400">
                        {{ cart.unitCount.value }}
                        {{ cart.unitCount.value === 1 ? 'artigo' : 'artigos' }}
                    </p>
                    <p
                        class="truncate numeric text-lg/6 font-semibold text-zinc-950 dark:text-white"
                    >
                        {{ formatMinor(cart.totals.value.grossMinor)
                        }}<span
                            class="ms-1 text-xs font-normal text-zinc-500 dark:text-zinc-400"
                            >{{ suffix }}</span
                        >
                    </p>
                </div>
                <button
                    type="button"
                    :class="[buttonGold, 'shrink-0']"
                    aria-haspopup="dialog"
                    @click="sheetOpen = true"
                >
                    Ver venda
                </button>
            </div>
        </div>

        <TransitionRoot as="template" :show="sheetOpen && !isDesktop">
            <Dialog
                class="relative z-50"
                data-pos-sheet
                @close="sheetOpen = false"
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

                <div
                    class="fixed inset-0 z-50 overflow-y-auto overscroll-contain"
                >
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
                                class="relative flex max-h-[calc(100dvh-2.5rem)] w-full max-w-lg flex-col dialog-panel max-sm:rounded-b-none max-sm:pb-[env(safe-area-inset-bottom)] sm:max-h-[calc(100dvh-3rem)]"
                            >
                                <div
                                    class="flex shrink-0 items-center justify-between gap-3 px-4 pt-3 pb-1 sm:px-5"
                                >
                                    <DialogTitle
                                        class="text-[1.625rem] leading-[1.12] display text-zinc-950 dark:text-white"
                                    >
                                        Venda
                                    </DialogTitle>
                                    <button
                                        ref="sheetClose"
                                        type="button"
                                        class="icon-button rounded-full text-zinc-500 focus-ring hover:bg-zinc-900/[0.05] hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
                                        @click="sheetOpen = false"
                                    >
                                        <span class="sr-only">Fechar</span>
                                        <X class="size-4" aria-hidden="true" />
                                    </button>
                                </div>
                                <PosCart
                                    ref="cartView"
                                    hide-title
                                    :lines="cart.lines.value"
                                    :totals="cart.totals.value"
                                    :customer="cart.customer.value"
                                    :final-consumer-name="finalConsumer.name"
                                    :currency-code="session.currency_code"
                                    :series-available="session.series_available"
                                    @pick-customer="openCustomer"
                                    @charge="startCharge"
                                    @clear="cart.clear()"
                                    @step="cart.step"
                                    @quantity="cart.setQuantity"
                                    @discount="cart.setDiscount"
                                    @remove="cart.remove"
                                    @focus-search="sheetClose?.focus()"
                                />
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>

        <PosCustomerDialog
            :open="customerOpen"
            :current="cart.customer.value"
            :customers="customers"
            :final-consumer="finalConsumer"
            @close="customerOpen = false"
            @after-leave="settleFocus"
            @choose="chooseCustomer"
        />

        <PosPaymentDialog
            :open="paymentOpen"
            :session-id="session.public_id"
            :total-minor="cart.totals.value.grossMinor"
            :unit-count="cart.unitCount.value"
            :customer="cart.customer.value"
            :customer-label="customerLabel"
            :lines="paymentLines"
            :payment-methods="paymentMethods"
            :currency-code="session.currency_code"
            @close="leavePayment"
            @review="reviewSale"
            @completed="saleCompleted"
            @finished="finishSale"
            @after-leave="settleFocus"
        />

        <PosCashMovementDialog
            :open="movementOpen"
            :session-id="session.public_id"
            :expected-cash-minor="summary.expected_cash_minor"
            :currency-code="session.currency_code"
            @close="movementOpen = false"
            @after-leave="settleFocus"
        />

        <PosShiftSummary
            :open="summaryOpen"
            :register-name="session.register.name"
            :opened-at="session.opened_at"
            :opening-float-minor="session.opening_float_minor"
            :summary="summary"
            :cash-movements="session.cash_movements"
            :recent-sales="recentSales"
            :currency-code="session.currency_code"
            @close="summaryOpen = false"
            @after-leave="settleFocus"
        />

        <PosCloseShiftDialog
            :open="closeOpen"
            :session-id="session.public_id"
            :register-name="session.register.name"
            :expected-cash-minor="summary.expected_cash_minor"
            :currency-code="session.currency_code"
            :has-unfinished-sale="!cart.isEmpty.value"
            @close="closeOpen = false"
            @after-leave="settleFocus"
        />
    </div>
</template>
