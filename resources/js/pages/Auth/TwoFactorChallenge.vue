<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { KeyRound, LoaderCircle, Smartphone } from '@lucide/vue';
import { ref } from 'vue';
import FormError from '@/components/FormError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store as twoFactorLoginStore } from '@/routes/two-factor/login';

const useRecoveryCode = ref(false);
</script>

<template>
    <AuthLayout
        eyebrow="Segundo factor"
        title="Valide o acesso"
        :description="
            useRecoveryCode
                ? 'Introduza um dos códigos de recuperação guardados quando activou o MFA.'
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
                    name="recovery_code"
                    type="text"
                    autocomplete="one-time-code"
                    required
                    autofocus
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 font-mono text-base tracking-wider text-zinc-900 uppercase outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                />
                <FormError :message="errors.recovery_code" />
            </div>
            <div v-else>
                <label
                    for="code"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Código da aplicação</label
                >
                <input
                    id="code"
                    name="code"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    autocomplete="one-time-code"
                    maxlength="6"
                    required
                    autofocus
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-3 text-center font-mono text-2xl tracking-[0.45em] text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                />
                <FormError :message="errors.code" />
            </div>

            <button
                type="submit"
                :disabled="processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-700 px-3 py-2.5 text-sm/6 font-semibold text-white shadow-sm transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-brand-500 dark:hover:bg-brand-400"
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
                    class="font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300"
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
