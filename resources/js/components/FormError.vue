<script setup lang="ts">
defineProps<{
    message?: string;
    /** Lets the field point at this message with `aria-describedby`. */
    id?: string;
}>();
</script>

<template>
    <!--
        Mounted only when there is something to say, so a screen reader is told
        once, when the message arrives, and never for an empty element. It sits
        6px under its control (the hint above it sits at the same distance).
    -->
    <p
        v-if="message"
        :id="id"
        class="mt-1.5 text-sm/5 font-medium text-rose-600 dark:text-rose-400"
        role="alert"
    >
        {{ message }}
    </p>
</template>
