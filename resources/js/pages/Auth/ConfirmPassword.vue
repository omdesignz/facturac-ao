<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { LoaderCircle, ShieldAlert } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { focusFirstInvalid } from '@/lib/focus';
import { store as passwordConfirmStore } from '@/routes/password/confirm';
</script>

<template>
    <AuthLayout
        title="Confirme a palavra-passe"
        description="Esta área altera a segurança da conta. Indique a palavra-passe para continuar."
    >
        <Head title="Confirmar palavra-passe" />

        <div
            class="mb-6 flex gap-3 rounded-xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
        >
            <ShieldAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <span
                >A confirmação expira automaticamente após alguns minutos.</span
            >
        </div>

        <Form
            v-bind="passwordConfirmStore.form()"
            :reset-on-success="['password']"
            class="space-y-5"
            @error="focusFirstInvalid()"
            #default="{ errors, processing }"
        >
            <FormField
                for="password"
                label="Palavra-passe"
                :error="errors.password"
                error-id="password-error"
                ><input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    autofocus
                    :aria-invalid="errors.password ? 'true' : undefined"
                    :aria-describedby="
                        errors.password ? 'password-error' : undefined
                    "
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
                {{ processing ? 'A confirmar…' : 'Confirmar e continuar' }}
            </AppButton>
        </Form>
    </AuthLayout>
</template>
