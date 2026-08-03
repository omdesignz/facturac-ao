<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <p
        v-if="message"
        class="mt-2 text-sm/5 font-medium text-rose-600 dark:text-rose-400"
        role="alert"
    >
        {{ message }}
    </p>
</template>
