<script setup lang="ts">
import FormError from '@/components/FormError.vue';

const props = defineProps<{
    label: string;
    /** The id of the control the label names. */
    for?: string;
    optional?: boolean;
    error?: string;
    /** Lets the control point at the error with `aria-describedby`. */
    errorId?: string;
}>();
</script>

<template>
    <div>
        <label :for="props.for" class="field-label"
            >{{ label }}
            <span
                v-if="optional"
                class="font-normal text-zinc-500 dark:text-zinc-400"
                >(opcional)</span
            ></label
        >
        <div class="mt-2">
            <slot />
        </div>
        <FormError :id="errorId" :message="error" />
    </div>
</template>
