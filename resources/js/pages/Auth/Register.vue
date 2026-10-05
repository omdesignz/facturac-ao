<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import FormError from '@/components/FormError.vue';
import GoogleMark from '@/components/GoogleMark.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store as registerStore } from '@/routes/register';
import { redirect as googleRedirect } from '@/routes/social/google';

defineProps<{
    googleEnabled: boolean;
}>();
</script>

<template>
    <AuthLayout
        eyebrow="Nova conta"
        title="Crie a sua conta"
        description="Comece pelo nome do negócio. O NIF, o regime de IVA e a morada ficam para o passo seguinte."
    >
        <Head title="Criar conta" />

        <Form
            v-bind="registerStore.form()"
            :reset-on-success="['password', 'password_confirmation']"
            class="space-y-5"
            #default="{ errors, processing }"
        >
            <div>
                <label
                    for="name"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Seu nome</label
                >
                <input
                    id="name"
                    name="name"
                    type="text"
                    autocomplete="name"
                    required
                    autofocus
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                />
                <FormError :message="errors.name" />
            </div>

            <div>
                <label
                    for="workspace-name"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Nome do negócio</label
                >
                <input
                    id="workspace-name"
                    name="workspace_name"
                    type="text"
                    autocomplete="organization"
                    required
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                    placeholder="Ex.: Kwanza Mercantil"
                />
                <FormError :message="errors.workspace_name" />
            </div>

            <div>
                <label
                    for="email"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Email profissional</label
                >
                <input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="username"
                    required
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                    placeholder="nome@empresa.ao"
                />
                <FormError :message="errors.email" />
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
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
                        autocomplete="new-password"
                        required
                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                    />
                    <FormError :message="errors.password" />
                </div>
                <div>
                    <label
                        for="password-confirmation"
                        class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                        >Confirmar</label
                    >
                    <input
                        id="password-confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                    />
                </div>
            </div>

            <button
                type="submit"
                :disabled="processing"
                class="flex h-11 w-full items-center justify-center gap-2 rounded-full bg-accent-400 px-4 text-sm font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] focus-ring transition hover:bg-accent-300 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <LoaderCircle
                    v-if="processing"
                    class="size-4 animate-spin"
                    aria-hidden="true"
                />
                {{ processing ? 'A criar…' : 'Criar conta segura' }}
            </button>
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
                Já tem conta?
                <Link
                    :href="login.url()"
                    class="font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300"
                    >Entrar</Link
                >
            </p>
        </template>
    </AuthLayout>
</template>
