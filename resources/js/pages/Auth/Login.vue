<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { Passkeys } from '@laravel/passkeys';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { Fingerprint, LoaderCircle, LockKeyhole } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import FormError from '@/components/FormError.vue';
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
        eyebrow="Acesso seguro"
        title="Entre na sua conta"
        description="Entre para voltar às suas facturas. Pedimos-lhe a palavra-passe outra vez sempre que uma operação mexer em dinheiro ou em segurança."
    >
        <Head title="Entrar" />

        <div
            v-if="flash.error"
            class="mb-6 rounded-xl bg-rose-50 p-3 text-sm/6 font-medium text-rose-700 ring-1 ring-rose-200 dark:bg-rose-400/10 dark:text-rose-300 dark:ring-rose-400/20"
            role="alert"
        >
            {{ flash.error }}
        </div>
        <div
            v-else-if="flash.status || flash.success"
            class="mb-6 rounded-xl bg-emerald-50 p-3 text-sm/6 font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20"
            role="status"
        >
            {{ flash.success ?? flash.status }}
        </div>

        <Form
            v-bind="loginStore.form()"
            :reset-on-success="['password']"
            class="space-y-5"
            @error="focusFirstInvalid()"
            #default="{ errors, processing }"
        >
            <div>
                <label
                    for="email"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Email</label
                >
                <input
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
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 placeholder:text-zinc-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:placeholder:text-zinc-500 dark:focus:outline-brand-400"
                    placeholder="nome@empresa.ao"
                />
                <FormError id="email-error" :message="errors.email" />
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <label
                        for="password"
                        class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                        >Palavra-passe</label
                    >
                    <Link
                        v-if="canResetPassword"
                        :href="passwordRequest.url()"
                        class="rounded text-sm/6 font-semibold text-brand-700 focus-ring hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                    >
                        Esqueceu-se?
                    </Link>
                </div>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    :aria-invalid="errors.password ? 'true' : undefined"
                    :aria-describedby="
                        errors.password ? 'password-error' : undefined
                    "
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                />
                <FormError id="password-error" :message="errors.password" />
            </div>

            <label
                class="flex min-h-11 w-fit items-center gap-3 py-2 text-sm/6 text-zinc-700 dark:text-zinc-300"
            >
                <span class="group grid size-4 grid-cols-1">
                    <input
                        name="remember"
                        type="checkbox"
                        value="1"
                        class="col-start-1 row-start-1 appearance-none rounded border border-zinc-300 bg-white focus-ring checked:border-brand-950 checked:bg-brand-950 dark:border-white/15 dark:bg-white/5 dark:checked:border-brand-500 dark:checked:bg-brand-500"
                    />
                    <svg
                        class="pointer-events-none col-start-1 row-start-1 size-3.5 self-center justify-self-center stroke-white opacity-0 group-has-checked:opacity-100"
                        viewBox="0 0 14 14"
                        fill="none"
                        aria-hidden="true"
                    >
                        <path
                            d="M3 8L6 11L11 3.5"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </span>
                Manter sessão neste dispositivo
            </label>

            <button
                type="submit"
                :disabled="processing"
                class="flex h-11 w-full items-center justify-center gap-2 rounded-full bg-brand-950 px-4 text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
            >
                <LoaderCircle
                    v-if="processing"
                    class="size-4 animate-spin"
                    aria-hidden="true"
                />
                {{ processing ? 'A entrar…' : 'Entrar' }}
            </button>
        </Form>

        <div v-if="passkeySupported" class="mt-5">
            <button
                type="button"
                :disabled="passkeyPending"
                class="flex min-h-11 w-full items-center justify-center gap-2 rounded-full bg-white px-4 py-2.5 text-center text-sm font-semibold text-zinc-900 ring-1 ring-zinc-900/10 focus-ring transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:hover:bg-white/10"
                @click="signInWithPasskey"
            >
                <LoaderCircle
                    v-if="passkeyPending"
                    class="size-4 animate-spin"
                    aria-hidden="true"
                />
                <Fingerprint v-else class="size-4" aria-hidden="true" />
                {{
                    passkeyPending
                        ? 'A confirmar…'
                        : 'Entrar com impressão digital ou rosto'
                }}
            </button>
            <p
                v-if="passkeyMessage"
                class="mt-2 text-center text-sm text-rose-600 dark:text-rose-400"
            >
                {{ passkeyMessage }}
            </p>
        </div>

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
                        >ou continue com</span
                    >
                </div>
            </div>
            <a
                :href="googleRedirect.url()"
                class="flex h-11 w-full items-center justify-center gap-3 rounded-full bg-white px-4 text-sm font-semibold text-zinc-900 ring-1 ring-zinc-900/10 focus-ring transition hover:bg-zinc-50 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:hover:bg-white/10"
            >
                <GoogleMark />
                Continuar com Google
            </a>
        </template>

        <template #footer>
            <p class="text-center">
                Ainda não tem conta?
                <Link
                    :href="register.url()"
                    class="rounded font-semibold text-brand-700 focus-ring hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-100"
                    >Criar conta</Link
                >
            </p>
            <p
                class="mt-4 flex items-center justify-center gap-2 text-xs text-zinc-500 dark:text-zinc-400"
            >
                <LockKeyhole class="size-3.5" aria-hidden="true" />
                Sessão protegida e auditável
            </p>
        </template>
    </AuthLayout>
</template>
