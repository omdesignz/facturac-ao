<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { Passkeys } from '@laravel/passkeys';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import {
    ArrowRight,
    Eye,
    EyeOff,
    KeyRound,
    LoaderCircle,
    LockKeyhole,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import FormError from '@/components/FormError.vue';
import FormField from '@/components/FormField.vue';
import GoogleMark from '@/components/GoogleMark.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { focusFirstInvalid } from '@/lib/focus';
import { dashboard, register } from '@/routes';
import { store as loginStore } from '@/routes/login';
import { request as passwordRequest } from '@/routes/password';
import { redirect as googleRedirect } from '@/routes/social/google';

defineProps<{
    canResetPassword: boolean;
    googleEnabled: boolean;
}>();

/**
 * True only while the button below is actually working.
 *
 * The composable's own `isLoading` cannot be used here: with `autofill` on it
 * opens a conditional WebAuthn request on mount that stays pending for the
 * whole life of the page, waiting for the browser's passkey dropdown. Binding
 * the button to that leaves it reading "a confirmar" and disabled from the
 * moment the page loads, without anybody having pressed it.
 */
const passkeyPending = ref(false);
const passkeyMessage = ref<string | null>(null);

// `autofill` surfaces saved passkeys straight from the email field's dropdown;
// the button below is the explicit fallback.
const { verify: verifyPasskey, isSupported: passkeySupported } =
    usePasskeyVerify({
        autofill: true,
        onSuccess: (response) => {
            router.visit(response.redirect ?? dashboard.url());
        },
        onError: (error) => {
            // The background request aborts whenever it is superseded or the
            // page goes away. Only an attempt the reader actually made is worth
            // putting an error under the button for.
            if (passkeyPending.value) {
                passkeyMessage.value = error.message;
            }
        },
    });

async function signInWithPasskey(): Promise<void> {
    if (passkeyPending.value) {
        return;
    }

    passkeyPending.value = true;
    passkeyMessage.value = null;

    // The conditional request opened on mount still holds the browser's single
    // WebAuthn slot; starting a second one while it is pending is rejected.
    Passkeys.cancel();

    try {
        await verifyPasskey();
    } finally {
        passkeyPending.value = false;
    }
}

const emailInput = ref<HTMLInputElement | null>(null);

/**
 * Focus the email field on arrival, but only where there is a keyboard to
 * answer it. On a phone, focusing here opens the on-screen keyboard over half
 * the form before the reader has seen it. Focus is what lets the browser offer
 * saved passkeys from the field, so the passkey request itself does not depend
 * on it: it is already waiting from the moment the page loads.
 */
onMounted(() => {
    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        emailInput.value?.focus();
    }
});

/** The password stays hidden unless the reader asks to check what they typed. */
const passwordShown = ref(false);

const page = usePage();
const flash = computed(
    () =>
        page.props.flash as {
            error?: string;
            status?: string;
            success?: string;
        },
);
</script>

<template>
    <AuthLayout
        title="Entre na sua conta"
        :description="
            passkeySupported
                ? 'Use a chave de acesso deste dispositivo, ou o email e a palavra-passe.'
                : 'Use o email e a palavra-passe da sua conta.'
        "
    >
        <Head title="Entrar" />

        <div
            v-if="flash.error"
            class="mb-5 rounded-lg bg-[var(--ui-critical-wash)] px-3 py-2.5 text-[0.8125rem]/5 font-medium text-[var(--ui-critical)]"
            role="alert"
        >
            {{ flash.error }}
        </div>
        <div
            v-else-if="flash.status || flash.success"
            class="mb-5 rounded-lg bg-[var(--ui-positive-wash)] px-3 py-2.5 text-[0.8125rem]/5 font-medium text-[var(--ui-positive)]"
            role="status"
        >
            {{ flash.success ?? flash.status }}
        </div>

        <template v-if="passkeySupported">
            <AppButton
                variant="outline"
                size="lg"
                :disabled="passkeyPending"
                class="relative h-[2.625rem] w-full rounded-lg text-sm font-[550]"
                @click="signInWithPasskey"
            >
                <LoaderCircle
                    v-if="passkeyPending"
                    class="size-4 animate-spin"
                    aria-hidden="true"
                />
                <KeyRound
                    v-else
                    class="size-[1.0625rem]"
                    :stroke-width="1.8"
                    aria-hidden="true"
                />
                {{
                    passkeyPending
                        ? 'A confirmar…'
                        : 'Continuar com chave de acesso'
                }}
            </AppButton>
            <p
                v-if="passkeyMessage"
                class="mt-2 text-center text-[0.8125rem] text-[var(--ui-critical)]"
            >
                {{ passkeyMessage }}
            </p>
            <div
                class="my-6 flex items-center gap-3 text-xs text-[var(--ui-ink-3)]"
                aria-hidden="true"
            >
                <span class="h-px flex-1 bg-[var(--ui-line)]" />ou<span
                    class="h-px flex-1 bg-[var(--ui-line)]"
                />
            </div>
        </template>

        <Form
            v-bind="loginStore.form()"
            :reset-on-success="['password']"
            class="flex flex-col"
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
                    ref="emailInput"
                    name="email"
                    type="email"
                    inputmode="email"
                    autocomplete="username webauthn"
                    autocapitalize="none"
                    spellcheck="false"
                    required
                    :aria-invalid="errors.email ? 'true' : undefined"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                    class="block form-input"
                    placeholder="nome@empresa.ao"
            /></FormField>

            <div class="mt-[1.125rem]">
                <FormField
                    class="flex items-baseline justify-between"
                    for="password"
                    label="Palavra-passe"
                    ><Link
                        v-if="canResetPassword"
                        :href="passwordRequest.url()"
                        class="rounded text-[0.78125rem] font-medium text-[var(--ui-ink-2)] focus-ring hover:text-[var(--ui-ink)]"
                    >
                        Esqueceu-se?
                    </Link></FormField
                >
                <div class="relative mt-2">
                    <input
                        id="password"
                        name="password"
                        :type="passwordShown ? 'text' : 'password'"
                        autocomplete="current-password"
                        required
                        :aria-invalid="errors.password ? 'true' : undefined"
                        :aria-describedby="
                            errors.password ? 'password-error' : undefined
                        "
                        class="block form-input pe-11"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 inset-e-1 my-auto grid size-8 place-items-center rounded-md text-[var(--ui-ink-3)] focus-ring hover:text-[var(--ui-ink)] pointer-coarse:size-10"
                        :aria-pressed="passwordShown"
                        :aria-label="
                            passwordShown
                                ? 'Esconder a palavra-passe'
                                : 'Mostrar a palavra-passe'
                        "
                        @click="passwordShown = !passwordShown"
                    >
                        <EyeOff
                            v-if="passwordShown"
                            class="size-4"
                            :stroke-width="1.8"
                            aria-hidden="true"
                        />
                        <Eye
                            v-else
                            class="size-4"
                            :stroke-width="1.8"
                            aria-hidden="true"
                        />
                    </button>
                </div>
                <FormError id="password-error" :message="errors.password" />
            </div>

            <label
                class="mt-3 flex min-h-11 w-fit items-center gap-2.5 text-[0.8125rem] text-[var(--ui-ink-2)]"
            >
                <input
                    name="remember"
                    type="checkbox"
                    value="1"
                    class="form-checkbox"
                />
                Manter sessão neste dispositivo
            </label>

            <AppButton
                variant="ink"
                size="lg"
                type="submit"
                :disabled="processing"
                class="mt-3 h-[2.625rem] w-full text-sm"
            >
                <LoaderCircle
                    v-if="processing"
                    class="size-4 animate-spin"
                    aria-hidden="true"
                />
                {{ processing ? 'A entrar…' : 'Entrar' }}
                <ArrowRight
                    v-if="!processing"
                    class="size-[0.9375rem]"
                    :stroke-width="2.2"
                    aria-hidden="true"
                />
            </AppButton>

            <p
                class="mt-[1.125rem] flex items-center gap-2 text-[0.78125rem] text-[var(--ui-ink-3)]"
            >
                <LockKeyhole
                    class="size-3.5 shrink-0"
                    :stroke-width="1.9"
                    aria-hidden="true"
                />
                Sessão protegida e auditável.
            </p>
        </Form>

        <template v-if="googleEnabled">
            <div
                class="my-6 flex items-center gap-3 text-xs text-[var(--ui-ink-3)]"
                aria-hidden="true"
            >
                <span class="h-px flex-1 bg-[var(--ui-line)]" />ou<span
                    class="h-px flex-1 bg-[var(--ui-line)]"
                />
            </div>
            <AppButton
                variant="outline"
                size="lg"
                external
                :href="googleRedirect.url()"
                class="h-[2.625rem] w-full rounded-lg text-sm font-[550]"
            >
                <GoogleMark />
                Continuar com Google
            </AppButton>
        </template>

        <template #footer>
            Ainda não tem conta?
            <Link
                :href="register.url()"
                class="rounded font-semibold text-[var(--ui-ink)] focus-ring hover:underline hover:underline-offset-2"
                >Abrir conta</Link
            >
            <span class="ms-1 whitespace-nowrap text-[var(--ui-ink-3)]"
                >· sem cartão para começar</span
            >
        </template>
    </AuthLayout>
</template>
