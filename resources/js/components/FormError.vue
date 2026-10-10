<script setup lang="ts">
defineProps<{
    message?: string;
    /** Lets the field point at this message with `aria-describedby`. */
    id?: string;
}>();
</script>

<template>
    <p
        v-if="message"
        :id="id"
        class="mt-2 text-sm/5 font-medium text-rose-600 dark:text-rose-400"
        role="alert"
    >
        {{ message }}
    </p>
</template>
