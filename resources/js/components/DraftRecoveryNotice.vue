<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';

defineProps<{ available: boolean; conflict: boolean; message: string }>();
defineEmits<{ restore: []; discard: [] }>();
</script>

<template>
    <div
        v-if="available || message"
        class="space-y-3 rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-800"
    >
        <p role="status">
            {{
                available
                    ? conflict
                        ? 'Os dados mudaram desde a cópia guardada. Está a ver a versão mais recente; a cópia antiga não será aplicada.'
                        : 'Há trabalho por terminar guardado neste separador. Quer recuperá-lo?'
                    : message
            }}
        </p>
        <div v-if="available" class="flex flex-wrap gap-2">
            <AppButton v-if="!conflict" type="button" @click="$emit('restore')"
                >Recuperar</AppButton
            >
            <AppButton type="button" variant="outline" @click="$emit('discard')"
                >Descartar cópia</AppButton
            >
        </div>
    </div>
</template>
