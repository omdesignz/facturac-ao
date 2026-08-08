<script setup lang="ts">
import { CalendarDays } from '@lucide/vue';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import { pt } from 'date-fns/locale';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** ISO string: `yyyy-MM-dd`, or `yyyy-MM-dd HH:mm` when `withTime`. */
        modelValue: string | null;
        id?: string;
        placeholder?: string;
        ariaLabel?: string;
        disabled?: boolean;
        clearable?: boolean;
        withTime?: boolean;
        minDate?: string;
        maxDate?: string;
    }>(),
    {
        id: undefined,
        placeholder: 'dd/mm/aaaa',
        ariaLabel: undefined,
        disabled: false,
        clearable: true,
        withTime: false,
        minDate: undefined,
        maxDate: undefined,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

// Angola writes dates dd/mm/aaaa; the wire format stays ISO for the backend.
const modelType = computed(() =>
    props.withTime ? 'yyyy-MM-dd HH:mm' : 'yyyy-MM-dd',
);
const displayFormat = computed(() =>
    props.withTime ? 'dd/MM/yyyy HH:mm' : 'dd/MM/yyyy',
);

const value = computed({
    get: () => props.modelValue,
    set: (next: string | null) => emit('update:modelValue', next || null),
});
</script>

<template>
    <!--
        v14 groups these into config objects (formats / timeConfig / inputAttrs)
        rather than flat props — passing them flat silently does nothing.
    -->
    <VueDatePicker
        v-model="value"
        class="vap-datepicker"
        :model-type="modelType"
        :formats="{ input: displayFormat }"
        :text-input="{ format: displayFormat }"
        :time-config="{ enableTimePicker: withTime }"
        :input-attrs="{
            id,
            clearable,
            autocomplete: 'off',
        }"
        :placeholder="placeholder"
        :aria-labels="ariaLabel ? { input: ariaLabel } : undefined"
        :disabled="disabled"
        :min-date="minDate"
        :max-date="maxDate"
        :locale="pt"
        :action-row="{
            selectBtnLabel: 'Escolher',
            cancelBtnLabel: 'Cancelar',
            nowBtnLabel: 'Hoje',
        }"
        auto-apply
    >
        <template #input-icon>
            <CalendarDays class="size-4 text-zinc-400" aria-hidden="true" />
        </template>
    </VueDatePicker>
</template>
