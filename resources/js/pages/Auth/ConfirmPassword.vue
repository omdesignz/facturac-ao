<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle, ShieldAlert } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { focusFirstInvalid } from '@/lib/focus';
import { dashboard } from '@/routes';
import { store as passwordConfirmStore } from '@/routes/password/confirm';
</script>

<template>
    <AuthLayout
        title="Confirme a palavra-passe"
        description="Esta área altera a segurança da conta. Indique a palavra-passe para continuar."
    >
        <Head title="Confirmar palavra-passe" />

        <div
            class="mb-6 flex gap-3 rounded-xl bg-[var(--ui-gold-wash)] p-4 text-sm/6 text-[var(--ui-gold-ink)]"
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
                    data-auth-autofocus
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

        <template #footer>
            <p class="text-center">
                <Link
                    :href="dashboard.url()"
                    class="inline-flex min-h-11 items-center gap-2 rounded px-2 font-semibold text-brand-700 focus-ring hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-100"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Voltar à app
                </Link>
            </p>
        </template>
    </AuthLayout>
</template>
