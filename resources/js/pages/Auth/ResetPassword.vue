<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { focusFirstInvalid } from '@/lib/focus';
import { update as passwordUpdate } from '@/routes/password';

defineProps<{
    email: string;
    token: string;
}>();
</script>

<template>
    <AuthLayout
        eyebrow="Nova palavra-passe"
        title="Escolha uma nova palavra-passe"
        description="Esta ligação expira em breve. Depois de a alterar, terá de entrar de novo nos seus dispositivos."
    >
        <Head title="Nova palavra-passe" />

        <Form
            v-bind="passwordUpdate.form()"
            :reset-on-success="['password', 'password_confirmation']"
            class="space-y-5"
            @error="focusFirstInvalid()"
            #default="{ errors, processing }"
        >
            <input type="hidden" name="token" :value="token" />

            <FormField
                for="email"
                label="Email"
                :error="errors.email"
                error-id="email-error"
                ><input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="username"
                    required
                    readonly
                    :aria-invalid="errors.email ? 'true' : undefined"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                    :value="email"
                    class="block form-input"
            /></FormField>

            <FormField
                for="password"
                label="Nova palavra-passe"
                :error="errors.password"
                error-id="password-error"
                ><input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    required
                    autofocus
                    :aria-invalid="errors.password ? 'true' : undefined"
                    :aria-describedby="
                        errors.password ? 'password-error' : undefined
                    "
                    class="block form-input"
            /></FormField>

            <FormField
                for="password-confirmation"
                label="Confirmar palavra-passe"
                ><input
                    id="password-confirmation"
                    name="password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="block form-input"
            /></FormField>

            <AppButton
                variant="ink"
                size="lg"
                type="submit"
                :disabled="processing"
                class="w-full"
            >
                <LoaderCircle
                    v-if="processing"
                    class="size-4 animate-spin"
                    aria-hidden="true"
                />
                {{ processing ? 'A guardar…' : 'Guardar nova palavra-passe' }}
            </AppButton>
        </Form>
    </AuthLayout>
</template>
