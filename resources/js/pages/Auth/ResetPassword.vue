<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import FormError from '@/components/FormError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { focusFirstInvalid } from '@/lib/focus';
import { update as passwordUpdate } from '@/routes/password';

defineProps<{
    email: string;
    token: string;
}>();
</script>

<template>
    <AuthLayout
        eyebrow="Nova palavra-passe"
        title="Escolha uma nova palavra-passe"
        description="Esta ligação expira em breve. Depois de a alterar, terá de entrar de novo nos seus dispositivos."
    >
        <Head title="Nova palavra-passe" />

        <Form
            v-bind="passwordUpdate.form()"
            :reset-on-success="['password', 'password_confirmation']"
            class="space-y-5"
            @error="focusFirstInvalid()"
            #default="{ errors, processing }"
        >
            <input type="hidden" name="token" :value="token" />

            <div>
                <label
                    for="email"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Email</label
                >
                <input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="username"
                    required
                    readonly
                    :aria-invalid="errors.email ? 'true' : undefined"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                    :value="email"
                    class="mt-2 block w-full rounded-xl bg-zinc-50 px-3 py-2.5 text-base text-zinc-700 outline-1 -outline-offset-1 outline-zinc-200 sm:text-sm dark:bg-white/5 dark:text-zinc-300 dark:outline-white/10"
                />
                <FormError id="email-error" :message="errors.email" />
            </div>

            <div>
                <label
                    for="password"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Nova palavra-passe</label
                >
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    required
                    autofocus
                    :aria-invalid="errors.password ? 'true' : undefined"
                    :aria-describedby="
                        errors.password ? 'password-error' : undefined
                    "
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                />
                <FormError id="password-error" :message="errors.password" />
            </div>

            <div>
                <label
                    for="password-confirmation"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Confirmar palavra-passe</label
                >
                <input
                    id="password-confirmation"
                    name="password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                />
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
                {{ processing ? 'A guardar…' : 'Guardar nova palavra-passe' }}
            </button>
        </Form>
    </AuthLayout>
</template>
