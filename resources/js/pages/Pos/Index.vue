<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PosOpenShift from '@/components/pos/PosOpenShift.vue';
import PosTill from '@/components/pos/PosTill.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    PosCatalogueItem,
    PosCustomer,
    PosFinalConsumer,
    PosPaymentMethod,
    PosRegisterChoice,
    PosSessionProps,
} from '@/types/pos';

/**
 * Ponto de venda. With no shift open it asks for a till and a float; with one
 * open it is the till itself, and everything it needs arrives with the page so
 * the only request a sale makes is the sale.
 */
defineProps<{
    company: {
        legal_name: string;
        trade_name: string | null;
        currency_code: string;
    };
    canManageRegisters: boolean;
    canSell: boolean;
    paymentMethods: PosPaymentMethod[];
    finalConsumer: PosFinalConsumer;
    registers: PosRegisterChoice[];
    session: PosSessionProps | null;
    catalogue: PosCatalogueItem[];
    customers: PosCustomer[];
    agreedPrices: Record<string, Record<string, string>>;
}>();
</script>

<template>
    <AppLayout>
        <Head title="Ponto de venda" />

        <PosTill
            v-if="session !== null"
            :session="session"
            :catalogue="catalogue"
            :customers="customers"
            :agreed-prices="agreedPrices"
            :payment-methods="paymentMethods"
            :final-consumer="finalConsumer"
        />

        <PosOpenShift
            v-else
            :registers="registers"
            :can-sell="canSell"
            :can-manage-registers="canManageRegisters"
            :currency-code="company.currency_code"
        />
    </AppLayout>
</template>
