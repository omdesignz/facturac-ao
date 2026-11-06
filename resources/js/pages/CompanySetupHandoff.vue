<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { dashboard } from '@/routes';
import { update } from '@/routes/company-setup';

defineProps<{ company: { public_id: string; name: string } }>();
</script>

<template>
    <AuthLayout title="Continuar a configuração" :description="company.name">
        <Head title="Continuar a configuração" />
        <p class="mb-6 text-sm/6 text-zinc-600 dark:text-zinc-400">
            Esta é a empresa que abriu no iPhone. Para continuar, passe para
            {{ company.name }}. A empresa seleccionada também muda nos seus
            outros dispositivos.
        </p>
        <Form v-bind="update.form(company.public_id)" #default="{ processing }">
            <AppButton
                type="submit"
                variant="gold"
                :disabled="processing"
                class="w-full"
            >
                {{
                    processing
                        ? 'A continuar…'
                        : 'Passar para esta empresa e continuar'
                }}
            </AppButton>
        </Form>
        <template #footer>
            <AppButton :href="dashboard.url()" variant="quiet"
                >Manter a empresa actual</AppButton
            >
        </template>
    </AuthLayout>
</template>
