<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Pencil, Plus, TicketPercent, Trash2 } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import DataTable from '@/components/DataTable.vue';
import DateInput from '@/components/DateInput.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { formatMinor, minorToInputText, parseAmount } from '@/lib/packages';
import { buttonGold, currencyLabel, wellSection } from '@/lib/pos-ui';
import {
    destroy as destroyCoupon,
    store as storeCoupon,
    update as updateCoupon,
} from '@/routes/support/coupons';
import type { SelectOption } from '@/types/select';

interface CouponRow {
    public_id: string;
    code: string;
    description: string | null;
    discount_type: 'percentage' | 'fixed';
    percent_off: number | null;
    amount_off_minor: number | null;
    currency_code: string;
    max_redemptions: number | null;
    used_count: number;
    expires_on: string | null;
    is_active: boolean;
    is_expired: boolean;
    can_delete: boolean;
    created_at: string | null;
}

const props = defineProps<{
    coupons: CouponRow[];
    discountTypes: { value: string; label: string }[];
    currencies: string[];
}>();

const dialogOpen = ref(false);
const editing = ref<CouponRow | null>(null);
const newCouponButton = ref<HTMLButtonElement | null>(null);
const amountText = ref('');

const form = useForm({
    code: '',
    description: '',
    discount_type: 'percentage',
    percent_off: '' as string | number,
    amount_off: '',
    currency_code: 'AOA',
    max_redemptions: '' as string | number,
    expires_on: null as string | null,
    is_active: true,
});

const typeOptions = computed<SelectOption[]>(() =>
    props.discountTypes.map((type) => ({
        value: type.value,
        label: type.label,
    })),
);

const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Africa/Luanda',
}).format(new Date());

function discountLabel(row: CouponRow): string {
    if (row.discount_type === 'percentage') {
        return `${row.percent_off ?? 0}%`;
    }

    return `${formatMinor(row.amount_off_minor ?? 0)} ${currencyLabel(row.currency_code)}`;
}

function usageLabel(row: CouponRow): string {
    return row.max_redemptions === null
        ? `${row.used_count} · sem limite`
        : `${row.used_count} de ${row.max_redemptions}`;
}

function statusOf(row: CouponRow): {
    label: string;
    tone: 'success' | 'warning' | 'neutral';
} {
    if (!row.is_active) {
        return { label: 'Desactivado', tone: 'neutral' };
    }

    if (row.is_expired) {
        return { label: 'Expirado', tone: 'neutral' };
    }

    if (row.max_redemptions !== null && row.used_count >= row.max_redemptions) {
        return { label: 'Esgotado', tone: 'warning' };
    }

    return { label: 'Activo', tone: 'success' };
}

function formatDate(value: string | null): string {
    if (value === null) {
        return 'Sem validade';
    }

    return new Intl.DateTimeFormat('pt-AO', {
        dateStyle: 'medium',
        timeZone: 'UTC',
    }).format(new Date(`${value}T00:00:00Z`));
}

function openCreate(): void {
    editing.value = null;
    form.defaults({
        code: '',
        description: '',
        discount_type: 'percentage',
        percent_off: '',
        amount_off: '',
        currency_code: props.currencies[0] ?? 'AOA',
        max_redemptions: '',
        expires_on: null,
        is_active: true,
    });
    form.reset();
    form.clearErrors();
    amountText.value = '';
    dialogOpen.value = true;
}

function openEdit(row: CouponRow): void {
    editing.value = row;
    form.defaults({
        code: row.code,
        description: row.description ?? '',
        discount_type: row.discount_type,
        percent_off: row.percent_off ?? '',
        amount_off: '',
        currency_code: row.currency_code,
        max_redemptions: row.max_redemptions ?? '',
        expires_on: row.expires_on,
        is_active: row.is_active,
    });
    form.reset();
    form.clearErrors();
    amountText.value =
        row.amount_off_minor === null
            ? ''
            : minorToInputText(row.amount_off_minor);
    dialogOpen.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    form.transform((data) => ({
        ...data,
        code: data.code.trim().toUpperCase(),
        description: data.description.trim() === '' ? null : data.description,
        percent_off: data.percent_off === '' ? null : Number(data.percent_off),
        amount_off: parseAmount(amountText.value)?.decimal ?? amountText.value,
        max_redemptions:
            data.max_redemptions === '' ? null : Number(data.max_redemptions),
    }));

    if (editing.value === null) {
        form.post(storeCoupon.url(), options);

        return;
    }

    form.put(updateCoupon.url(editing.value.public_id), options);
}

async function remove(row: CouponRow): Promise<void> {
    const confirmed = await confirmAction({
        title: `Apagar ${row.code}?`,
        message:
            'Este código nunca foi usado num pagamento, por isso desaparece por completo. Um código já usado só se desactiva.',
        confirmLabel: 'Apagar código',
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroyCoupon.url(row.public_id), {
        preserveScroll: true,
        onFinish: () => {
            void nextTick(() => newCouponButton.value?.focus());
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Códigos de desconto" />

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="max-w-5xl space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Apoio · Comercial"
                    title="Códigos de desconto"
                    description="Códigos que os clientes escrevem em «Plano e cobrança» antes de pagar. Cada empresa usa cada código uma vez; um pagamento que falha devolve a utilização."
                >
                    <template #actions>
                        <button
                            ref="newCouponButton"
                            type="button"
                            :class="buttonGold"
                            @click="openCreate"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Novo código
                        </button>
                    </template>
                </PageHeader>

                <div
                    v-if="coupons.length === 0"
                    :class="[wellSection, 'px-6 py-14 text-center']"
                >
                    <TicketPercent
                        class="mx-auto size-8 text-zinc-400 dark:text-zinc-500"
                        aria-hidden="true"
                    />
                    <p class="mt-4 font-semibold text-[var(--ui-ink)]">
                        Ainda não há códigos de desconto.
                    </p>
                    <p
                        class="mx-auto mt-1 max-w-sm text-sm/6 text-[var(--ui-ink-3)]"
                    >
                        Crie um para uma campanha ou para um cliente em
                        particular.
                    </p>
                </div>

                <DataTable
                    v-else
                    caption="Códigos de desconto"
                    table-class="min-w-[44rem]"
                    edge
                >
                    <template #head>
                        <th scope="col">Código</th>
                        <th scope="col">Desconto</th>
                        <th scope="col">Utilizações</th>
                        <th scope="col">Válido até</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-right">
                            <span class="sr-only">Acções</span>
                        </th>
                    </template>
                    <tr v-for="row in coupons" :key="row.public_id">
                        <td class="text-sm">
                            <p
                                class="font-mono font-semibold text-[var(--ui-ink)]"
                            >
                                {{ row.code }}
                            </p>
                            <p
                                v-if="row.description"
                                class="mt-0.5 max-w-[16rem] truncate text-xs text-[var(--ui-ink-3)]"
                            >
                                {{ row.description }}
                            </p>
                        </td>
                        <td class="numeric text-sm whitespace-nowrap">
                            {{ discountLabel(row) }}
                        </td>
                        <td
                            class="numeric text-sm whitespace-nowrap text-[var(--ui-ink-2)]"
                        >
                            {{ usageLabel(row) }}
                        </td>
                        <td
                            class="text-sm whitespace-nowrap text-[var(--ui-ink-2)]"
                        >
                            {{ formatDate(row.expires_on) }}
                        </td>
                        <td class="text-sm whitespace-nowrap">
                            <StatusBadge
                                :label="statusOf(row).label"
                                :tone="statusOf(row).tone"
                            />
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1">
                                <AppButton
                                    variant="outline"
                                    size="sm"
                                    @click="openEdit(row)"
                                >
                                    <Pencil
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Editar<span class="sr-only">
                                        {{ row.code }}</span
                                    >
                                </AppButton>
                                <button
                                    v-if="row.can_delete"
                                    type="button"
                                    class="icon-button rounded-full text-zinc-500 focus-ring transition hover:bg-rose-50 hover:text-rose-700 dark:text-zinc-400 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                                    @click="remove(row)"
                                >
                                    <span class="sr-only"
                                        >Apagar {{ row.code }}</span
                                    >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </DataTable>
            </div>
        </div>

        <RecordDialog
            eyebrow="Códigos de desconto"
            :open="dialogOpen"
            :title="editing === null ? 'Novo código' : `Editar ${editing.code}`"
            :description="
                editing === null
                    ? 'O código e o desconto não mudam depois de criados, para que cada pagamento continue a dizer o que foi pago.'
                    : 'O código e o desconto já não mudam. Pode mudar a descrição, o limite, a validade e se está activo.'
            "
            @close="dialogOpen = false"
        >
            <form class="space-y-6" novalidate @submit.prevent="submit">
                <FormField
                    for="coupon-code"
                    label="Código"
                    :error="form.errors.code"
                    error-id="coupon-code-error"
                    ><input
                        id="coupon-code"
                        v-model="form.code"
                        type="text"
                        :readonly="editing !== null"
                        :required="editing === null"
                        maxlength="32"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        class="block form-input font-mono uppercase"
                        :aria-invalid="form.errors.code ? 'true' : undefined"
                        :aria-describedby="
                            form.errors.code
                                ? 'coupon-code-error'
                                : 'coupon-code-hint'
                        "
                    />
                    <p id="coupon-code-hint" class="field-hint">
                        Letras, algarismos e hífenes, por exemplo LANCAMENTO-20.
                    </p></FormField
                >

                <FormField
                    for="coupon-description"
                    label="Descrição"
                    optional
                    :error="form.errors.description"
                    error-id="coupon-description-error"
                    ><input
                        id="coupon-description"
                        v-model="form.description"
                        type="text"
                        maxlength="160"
                        autocomplete="off"
                        class="block form-input"
                        :aria-invalid="
                            form.errors.description ? 'true' : undefined
                        "
                /></FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        for="coupon-type"
                        label="Tipo de desconto"
                        :error="form.errors.discount_type"
                        ><SelectInput
                            id="coupon-type"
                            v-model="form.discount_type"
                            :options="typeOptions"
                            :disabled="editing !== null"
                            :invalid="Boolean(form.errors.discount_type)"
                    /></FormField>

                    <FormField
                        v-if="form.discount_type === 'percentage'"
                        for="coupon-percent"
                        label="Percentagem"
                        :error="form.errors.percent_off"
                        error-id="coupon-percent-error"
                    >
                        <div class="relative">
                            <input
                                id="coupon-percent"
                                v-model="form.percent_off"
                                type="text"
                                inputmode="numeric"
                                maxlength="2"
                                autocomplete="off"
                                :readonly="editing !== null"
                                class="block form-input pe-9 text-end numeric"
                                :aria-invalid="
                                    form.errors.percent_off ? 'true' : undefined
                                "
                                :aria-describedby="
                                    form.errors.percent_off
                                        ? 'coupon-percent-error'
                                        : undefined
                                "
                            />
                            <span class="field-adornment-end">%</span>
                        </div>
                    </FormField>

                    <FormField
                        v-else
                        for="coupon-amount"
                        label="Valor do desconto"
                        :error="form.errors.amount_off"
                        error-id="coupon-amount-error"
                    >
                        <div class="relative">
                            <input
                                id="coupon-amount"
                                v-model="amountText"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                :readonly="editing !== null"
                                class="block form-input pe-12 text-end numeric"
                                :aria-invalid="
                                    form.errors.amount_off ? 'true' : undefined
                                "
                                :aria-describedby="
                                    form.errors.amount_off
                                        ? 'coupon-amount-error'
                                        : undefined
                                "
                            />
                            <span class="field-adornment-end">{{
                                currencyLabel(form.currency_code)
                            }}</span>
                        </div>
                    </FormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        for="coupon-limit"
                        label="Limite de utilizações"
                        optional
                        :error="form.errors.max_redemptions"
                        error-id="coupon-limit-error"
                    >
                        <input
                            id="coupon-limit"
                            v-model="form.max_redemptions"
                            type="text"
                            inputmode="numeric"
                            maxlength="6"
                            autocomplete="off"
                            placeholder="Sem limite"
                            class="block form-input numeric"
                            :aria-invalid="
                                form.errors.max_redemptions ? 'true' : undefined
                            "
                            :aria-describedby="
                                form.errors.max_redemptions
                                    ? 'coupon-limit-error'
                                    : undefined
                            "
                        />
                    </FormField>

                    <FormField
                        for="coupon-expires"
                        label="Válido até"
                        optional
                        :error="form.errors.expires_on"
                        error-id="coupon-expires-error"
                    >
                        <DateInput
                            id="coupon-expires"
                            v-model="form.expires_on"
                            placeholder="Sem validade"
                            :min-date="today"
                            :invalid="Boolean(form.errors.expires_on)"
                            :describedby="
                                form.errors.expires_on
                                    ? 'coupon-expires-error'
                                    : 'coupon-expires-hint'
                            "
                        />
                        <p id="coupon-expires-hint" class="field-hint">
                            Inclui o próprio dia, até às 23:59 de Luanda.
                        </p>
                    </FormField>
                </div>

                <div
                    class="grid gap-1 rounded-lg bg-[var(--ui-wash)] px-4 py-2"
                >
                    <ToggleSwitch id="coupon-active" v-model="form.is_active">
                        <template #label>Código activo</template>
                        <template #description
                            >Um código desactivado é recusado no pagamento, mas
                            fica no histórico.</template
                        >
                    </ToggleSwitch>
                    <FormError :message="form.errors.is_active" />
                </div>

                <div
                    class="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end"
                >
                    <AppButton variant="outline" @click="dialogOpen = false">
                        Cancelar
                    </AppButton>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        :class="buttonGold"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin-delayed"
                            aria-hidden="true"
                        />
                        {{
                            editing === null
                                ? 'Guardar código'
                                : 'Guardar alterações'
                        }}
                    </button>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
