<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { LoaderCircle, ShieldAlert } from '@lucide/vue';
import FormError from '@/components/FormError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store as passwordConfirmStore } from '@/routes/password/confirm';
</script>

<template>
    <AuthLayout
        eyebrow="Confirmação reforçada"
        title="Confirme que é mesmo você"
        description="Esta acção pode alterar a segurança da conta. Volte a indicar a palavra-passe para continuar."
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
            #default="{ errors, processing }"
        >
            <div>
                <label
                    for="password"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Palavra-passe</label
                >
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    autofocus
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                />
                <FormError :message="errors.password" />
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
                {{ processing ? 'A confirmar…' : 'Confirmar e continuar' }}
            </button>
        </Form>
    </AuthLayout>
</template>
