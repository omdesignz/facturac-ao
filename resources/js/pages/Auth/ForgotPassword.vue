<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle, MailCheck } from '@lucide/vue';
import { computed } from 'vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { focusFirstInvalid } from '@/lib/focus';
import { login } from '@/routes';
import { email as passwordEmail } from '@/routes/password';

const page = usePage();
const status = computed(() => (page.props.flash as { status?: string }).status);
</script>

<template>
    <AuthLayout
        title="Recuperar a palavra-passe"
        description="Indique o email da conta. Se estiver registado, enviamos uma ligação para criar uma nova palavra-passe."
    >
        <Head title="Recuperar palavra-passe" />

        <div
            v-if="status"
            class="mb-6 flex gap-3 rounded-xl bg-emerald-50 p-4 text-sm/6 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20"
            role="status"
        >
            <MailCheck class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <span
                >Se o email existir, a ligação de recuperação foi enviada.</span
            >
        </div>

        <Form
            v-bind="passwordEmail.form()"
            class="space-y-5"
            @error="focusFirstInvalid()"
            #default="{ errors, processing }"
        >
            <FormField
                for="email"
                label="Email"
                :error="errors.email"
                error-id="email-error"
                ><input
                    id="email"
                    name="email"
                    type="email"
                    inputmode="email"
                    autocomplete="email"
                    autocapitalize="none"
                    spellcheck="false"
                    required
                    autofocus
                    :aria-invalid="errors.email ? 'true' : undefined"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                    class="block form-input"
                    placeholder="nome@empresa.ao"
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
                {{ processing ? 'A enviar…' : 'Enviar ligação segura' }}
            </AppButton>
        </Form>

        <template #footer>
            <p class="text-center">
                <Link
                    :href="login.url()"
                    class="inline-flex items-center gap-2 rounded font-semibold text-brand-700 focus-ring hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-100"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Voltar à entrada
                </Link>
            </p>
        </template>
    </AuthLayout>
</template>
