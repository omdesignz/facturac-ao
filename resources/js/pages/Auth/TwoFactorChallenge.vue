<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { KeyRound, LoaderCircle, Smartphone } from '@lucide/vue';
import { ref, watch } from 'vue';
import FormError from '@/components/FormError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { focusFirstInvalid } from '@/lib/focus';
import { store as twoFactorLoginStore } from '@/routes/two-factor/login';

const useRecoveryCode = ref(false);
const recoveryInput = ref<HTMLInputElement | null>(null);
const codeInput = ref<HTMLInputElement | null>(null);

/**
 * Switching between the two kinds of code swaps the field under the reader's
 * hands. Focus would otherwise stay on the toggle, so the next thing typed
 * goes nowhere; it follows the field that has just appeared instead.
 */
watch(
    useRecoveryCode,
    (usingRecoveryCode) => {
        (usingRecoveryCode ? recoveryInput : codeInput).value?.focus();
    },
    { flush: 'post' },
);
</script>

<template>
    <AuthLayout
        eyebrow="Segundo passo"
        title="Confirme que é você"
        :description="
            useRecoveryCode
                ? 'Introduza um dos códigos de recuperação guardados quando activou os dois factores.'
                : 'Abra a aplicação autenticadora e introduza o código actual de seis dígitos.'
        "
    >
        <Head title="Autenticação de dois factores" />

        <div
            class="mb-6 flex gap-3 rounded-xl bg-brand-50 p-4 text-sm/6 text-brand-900 ring-1 ring-brand-200 dark:bg-brand-400/10 dark:text-brand-200 dark:ring-brand-400/20"
        >
            <KeyRound
                v-if="useRecoveryCode"
                class="mt-0.5 size-5 shrink-0"
                aria-hidden="true"
            />
            <Smartphone
                v-else
                class="mt-0.5 size-5 shrink-0"
                aria-hidden="true"
            />
            <span
                >Este código nunca é partilhado com a AGT ou com outros
                utilizadores.</span
            >
        </div>

        <Form
            v-bind="twoFactorLoginStore.form()"
            :reset-on-error="[useRecoveryCode ? 'recovery_code' : 'code']"
            class="space-y-5"
            @error="focusFirstInvalid()"
            #default="{ errors, processing }"
        >
            <div v-if="useRecoveryCode">
                <label
                    for="recovery-code"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Código de recuperação</label
                >
                <input
                    id="recovery-code"
                    ref="recoveryInput"
                    name="recovery_code"
                    type="text"
                    autocomplete="one-time-code"
                    autocapitalize="characters"
                    spellcheck="false"
                    required
                    autofocus
                    :aria-invalid="errors.recovery_code ? 'true' : undefined"
                    :aria-describedby="
                        errors.recovery_code ? 'recovery-code-error' : undefined
                    "
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-base tracking-wider text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                />
                <FormError
                    id="recovery-code-error"
                    :message="errors.recovery_code"
                />
            </div>
            <div v-else>
                <label
                    for="code"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Código da aplicação</label
                >
                <input
                    id="code"
                    ref="codeInput"
                    name="code"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    autocomplete="one-time-code"
                    autocapitalize="none"
                    spellcheck="false"
                    maxlength="6"
                    required
                    autofocus
                    :aria-invalid="errors.code ? 'true' : undefined"
                    :aria-describedby="errors.code ? 'code-error' : undefined"
                    class="mt-2 block w-full rounded-xl bg-white py-3 ps-[calc(0.75rem+0.45em)] pe-3 text-center font-mono text-2xl tracking-[0.45em] text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                />
                <FormError id="code-error" :message="errors.code" />
            </div>

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
                {{ processing ? 'A validar…' : 'Validar acesso' }}
            </button>
        </Form>

        <template #footer>
            <p class="text-center">
                <button
                    type="button"
                    class="rounded font-semibold text-brand-700 focus-ring hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-100"
                    @click="useRecoveryCode = !useRecoveryCode"
                >
                    {{
                        useRecoveryCode
                            ? 'Usar código da aplicação'
                            : 'Usar um código de recuperação'
                    }}
                </button>
            </p>
        </template>
    </AuthLayout>
</template>
