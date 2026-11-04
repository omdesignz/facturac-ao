<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import GoogleMark from '@/components/GoogleMark.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { focusFirstInvalid } from '@/lib/focus';
import { login } from '@/routes';
import { store as registerStore } from '@/routes/register';
import { redirect as googleRedirect } from '@/routes/social/google';

defineProps<{
    googleEnabled: boolean;
}>();

const nameInput = ref<HTMLInputElement | null>(null);

/**
 * Focus the first field on arrival only where there is a keyboard to answer
 * it; on a phone it would open the on-screen keyboard over the form.
 */
onMounted(() => {
    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        nameInput.value?.focus();
    }
});
</script>

<template>
    <AuthLayout
        title="Crie a sua conta"
        description="Comece pelo nome do negócio. O NIF, o regime de IVA e a morada ficam para o passo seguinte."
    >
        <Head title="Criar conta" />

        <Form
            v-bind="registerStore.form()"
            :reset-on-success="['password', 'password_confirmation']"
            class="space-y-5"
            @error="focusFirstInvalid()"
            #default="{ errors, processing }"
        >
            <FormField
                for="name"
                label="O seu nome"
                :error="errors.name"
                error-id="name-error"
                ><input
                    id="name"
                    ref="nameInput"
                    name="name"
                    type="text"
                    autocomplete="name"
                    required
                    :aria-invalid="errors.name ? 'true' : undefined"
                    :aria-describedby="errors.name ? 'name-error' : undefined"
                    class="block form-input"
            /></FormField>

            <FormField
                for="workspace-name"
                label="Nome do negócio"
                :error="errors.workspace_name"
                error-id="workspace-name-error"
                ><input
                    id="workspace-name"
                    name="workspace_name"
                    type="text"
                    autocomplete="organization"
                    required
                    :aria-invalid="errors.workspace_name ? 'true' : undefined"
                    :aria-describedby="
                        errors.workspace_name
                            ? 'workspace-name-error'
                            : undefined
                    "
                    class="block form-input"
                    placeholder="Ex.: Kwanza Mercantil"
            /></FormField>

            <FormField
                for="email"
                label="Email profissional"
                :error="errors.email"
                error-id="email-error"
                ><input
                    id="email"
                    name="email"
                    type="email"
                    inputmode="email"
                    autocomplete="username"
                    autocapitalize="none"
                    spellcheck="false"
                    required
                    :aria-invalid="errors.email ? 'true' : undefined"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                    class="block form-input"
                    placeholder="nome@empresa.ao"
            /></FormField>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <FormField
                    for="password"
                    label="Palavra-passe"
                    :error="errors.password"
                    error-id="password-error"
                    ><input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        required
                        :aria-invalid="errors.password ? 'true' : undefined"
                        :aria-describedby="
                            errors.password ? 'password-error' : undefined
                        "
                        class="block form-input"
                /></FormField>
                <FormField for="password-confirmation" label="Confirmar"
                    ><input
                        id="password-confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="block form-input"
                /></FormField>
            </div>

            <AppButton
                variant="gold"
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
                {{ processing ? 'A criar…' : 'Criar conta segura' }}
            </AppButton>
        </Form>

        <template v-if="googleEnabled">
            <div class="relative my-7">
                <div
                    class="absolute inset-0 flex items-center"
                    aria-hidden="true"
                >
                    <div
                        class="w-full border-t border-zinc-200 dark:border-white/10"
                    />
                </div>
                <div class="relative flex justify-center text-xs font-medium">
                    <span
                        class="bg-white px-4 text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400"
                        >ou use uma conta existente</span
                    >
                </div>
            </div>
            <AppButton
                variant="outline"
                size="lg"
                external
                :href="googleRedirect.url()"
                class="w-full"
            >
                <GoogleMark />
                Continuar com Google
            </AppButton>
        </template>

        <template #footer>
            <p class="text-center">
                Já tem conta?
                <Link
                    :href="login.url()"
                    class="rounded font-semibold text-brand-700 focus-ring hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-100"
                    >Entrar</Link
                >
            </p>
        </template>
    </AuthLayout>
</template>
