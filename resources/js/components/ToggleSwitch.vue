<script setup lang="ts">
import { computed, useId, useSlots } from 'vue';

/**
 * An on/off setting: «Activo», «Enviar por email». A checkbox says «tick this
 * to agree or to pick»; a switch says «this is on» and takes effect as it is
 * set, so settings use this and consent and multi-select keep the checkbox.
 *
 * It is a real button with `role="switch"`, so Space and Enter work, and the
 * thumb's position carries the state as well as the colour. With `name` it
 * posts its `value` (default `1`) only while it is on, like a checkbox.
 */
const props = withDefaults(
    defineProps<{
        modelValue: boolean;
        id?: string;
        name?: string;
        value?: string;
        disabled?: boolean;
        /** For a switch with no visible label; with a label, the label names it. */
        ariaLabel?: string;
    }>(),
    {
        id: undefined,
        name: undefined,
        value: '1',
        disabled: false,
        ariaLabel: undefined,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    change: [value: boolean];
}>();

const slots = useSlots();
const uid = useId();
const switchId = computed(() => props.id ?? `switch-${uid}`);
const labelId = computed(() => `${switchId.value}-label`);
const descriptionId = computed(() => `${switchId.value}-description`);

function toggle(): void {
    if (props.disabled) {
        return;
    }

    emit('update:modelValue', !props.modelValue);
    emit('change', !props.modelValue);
}
</script>

<template>
    <div
        class="flex items-center justify-between gap-4 pointer-coarse:min-h-11"
    >
        <div v-if="slots.label || slots.description" class="min-w-0 flex-1">
            <label
                v-if="slots.label"
                :id="labelId"
                :for="switchId"
                :class="[
                    'block text-sm font-medium text-zinc-900 dark:text-white',
                    disabled
                        ? 'cursor-not-allowed opacity-60'
                        : 'cursor-pointer',
                ]"
            >
                <slot name="label" />
            </label>
            <p
                v-if="slots.description"
                :id="descriptionId"
                class="mt-0.5 text-xs/5 text-zinc-500 dark:text-zinc-400"
            >
                <slot name="description" />
            </p>
        </div>

        <button
            :id="switchId"
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :aria-labelledby="slots.label ? labelId : undefined"
            :aria-label="slots.label ? undefined : ariaLabel"
            :aria-describedby="slots.description ? descriptionId : undefined"
            :disabled="disabled"
            :class="[
                'tap-target relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full p-0.5 ring-1 focus-ring ring-inset disabled:cursor-not-allowed disabled:opacity-50 motion-safe:transition-colors motion-safe:duration-150 motion-safe:ease-out forced-colors:border forced-colors:border-[ButtonText] forced-colors:bg-[Canvas]',
                modelValue
                    ? 'bg-brand-950 ring-brand-950 dark:bg-zinc-100 dark:ring-zinc-100'
                    : 'bg-zinc-200 ring-zinc-400 dark:bg-white/10 dark:ring-white/30',
            ]"
            @click="toggle"
        >
            <span
                aria-hidden="true"
                :class="[
                    'pointer-events-none block size-5 rounded-full shadow-sm motion-safe:transition-transform motion-safe:duration-150 motion-safe:ease-out forced-colors:bg-[ButtonText]',
                    modelValue
                        ? 'translate-x-5 bg-white rtl:-translate-x-5 dark:bg-brand-950'
                        : 'translate-x-0 bg-white dark:bg-zinc-300',
                ]"
            />
        </button>

        <input
            v-if="name && modelValue"
            type="hidden"
            :name="name"
            :value="value"
        />
    </div>
</template>
