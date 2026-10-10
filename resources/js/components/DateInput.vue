<script setup lang="ts">
import { CalendarDays } from '@lucide/vue';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import { pt } from 'date-fns/locale';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

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
        /** Marks the field as failing validation (`aria-invalid` and an outline). */
        invalid?: boolean;
        /** The id of the error or hint element that describes this field. */
        describedby?: string;
        required?: boolean;
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
        invalid: false,
        describedby: undefined,
        required: false,
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

/**
 * The picker's own controls speak English by default. These are the labels of
 * its buttons; `input` is only set when the page names the field.
 */
const ariaLabels = computed(() => ({
    ...(props.ariaLabel ? { input: props.ariaLabel } : {}),
    prevMonth: 'Mês anterior',
    nextMonth: 'Mês seguinte',
    prevYear: 'Ano anterior',
    nextYear: 'Ano seguinte',
    openMonthsOverlay: 'Escolher mês',
    openYearsOverlay: 'Escolher ano',
    clearInput: 'Limpar data',
    toggleOverlay: 'Alternar vista',
}));

/**
 * v14's `inputAttrs` has no slot for `aria-describedby` or `spellcheck`, so
 * they are set on the real input directly. Vue only patches the attributes it
 * renders, which leaves these alone across re-renders.
 */
const picker = ref<{ $el?: HTMLElement } | null>(null);

function syncInputAttributes(): void {
    const input = picker.value?.$el?.querySelector('input.dp--input');

    if (!input) {
        return;
    }

    input.setAttribute('spellcheck', 'false');

    if (props.describedby) {
        input.setAttribute('aria-describedby', props.describedby);
    } else {
        input.removeAttribute('aria-describedby');
    }
}

onMounted(syncInputAttributes);
watch(
    () => props.describedby,
    () => void nextTick(syncInputAttributes),
);
</script>

<template>
    <!--
        v14 groups these into config objects (formats / timeConfig / inputAttrs)
        rather than flat props — passing them flat silently does nothing.
    -->
    <VueDatePicker
        ref="picker"
        v-model="value"
        :class="[
            'vap-datepicker',
            invalid &&
                '[&_.dp--input]:shadow-none! [&_.dp--input]:outline-2! [&_.dp--input]:-outline-offset-2! [&_.dp--input]:outline-rose-500! dark:[&_.dp--input]:outline-rose-400!',
        ]"
        :model-type="modelType"
        :formats="{ input: displayFormat }"
        :text-input="{ format: displayFormat }"
        :time-config="{ enableTimePicker: withTime }"
        :input-attrs="{
            id,
            clearable,
            autocomplete: 'off',
            required,
            state: invalid ? false : undefined,
        }"
        :placeholder="placeholder"
        :aria-labels="ariaLabels"
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
