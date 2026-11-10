<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Eye, EyeOff, LoaderCircle } from '@lucide/vue';
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

/** One switch for both fields: the pair is only useful read side by side. */
const passwordsShown = ref(false);

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

            <div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <FormField
                        for="password"
                        label="Palavra-passe"
                        :error="errors.password"
                        error-id="password-error"
                        ><input
                            id="password"
                            name="password"
                            :type="passwordsShown ? 'text' : 'password'"
                            autocomplete="new-password"
                            required
                            :aria-invalid="errors.password ? 'true' : undefined"
                            :aria-describedby="
                                errors.password
                                    ? 'password-error password-hint'
                                    : 'password-hint'
                            "
                            class="block form-input"
                    /></FormField>
                    <FormField for="password-confirmation" label="Confirmar"
                        ><input
                            id="password-confirmation"
                            name="password_confirmation"
                            :type="passwordsShown ? 'text' : 'password'"
                            autocomplete="new-password"
                            required
                            class="block form-input"
                    /></FormField>
                </div>

                <div
                    class="mt-2 flex items-start justify-between gap-3 text-xs/5 text-[var(--ui-ink-3)]"
                >
                    <p id="password-hint">
                        Pelo menos 12 caracteres, com maiúsculas, minúsculas,
                        números e um símbolo.
                    </p>
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded font-medium text-[var(--ui-ink-2)] focus-ring hover:text-[var(--ui-ink)] pointer-coarse:min-h-11"
                        :aria-pressed="passwordsShown"
                        @click="passwordsShown = !passwordsShown"
                    >
                        <EyeOff
                            v-if="passwordsShown"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        <Eye v-else class="size-3.5" aria-hidden="true" />
                        {{ passwordsShown ? 'Ocultar' : 'Mostrar' }}
                    </button>
                </div>
            </div>

            <div>
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
                    {{ processing ? 'A criar…' : 'Criar conta' }}
                </AppButton>

                <p class="mt-3 text-center text-xs text-[var(--ui-ink-3)]">
                    Sem cartão para começar.
                </p>
            </div>
        </Form>

        <template v-if="googleEnabled">
            <div
                class="my-6 flex items-center gap-3 text-xs text-[var(--ui-ink-3)]"
            >
                <span class="h-px flex-1 bg-[var(--ui-line)]" />ou use uma conta
                existente<span class="h-px flex-1 bg-[var(--ui-line)]" />
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
                    class="rounded font-semibold text-[var(--ui-ink)] focus-ring hover:underline hover:underline-offset-2"
                    >Entrar</Link
                >
            </p>
        </template>
    </AuthLayout>
</template>
