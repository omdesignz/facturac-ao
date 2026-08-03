<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { LoaderCircle, LockKeyhole } from '@lucide/vue';
import { computed } from 'vue';
import FormError from '@/components/FormError.vue';
import GoogleMark from '@/components/GoogleMark.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';
import { store as loginStore } from '@/routes/login';
import { request as passwordRequest } from '@/routes/password';
import { redirect as googleRedirect } from '@/routes/social/google';

defineProps<{
    canResetPassword: boolean;
    googleEnabled: boolean;
}>();

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
        description="Continue para o seu espaço fiscal. As operações sensíveis continuam protegidas por confirmação de palavra-passe e MFA."
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
                    name="email"
                    type="email"
                    autocomplete="username"
                    required
                    autofocus
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 placeholder:text-zinc-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:placeholder:text-zinc-500 dark:focus:outline-brand-400"
                    placeholder="nome@empresa.ao"
                />
                <FormError :message="errors.email" />
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
                        class="text-sm/6 font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
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
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                />
                <FormError :message="errors.password" />
            </div>

            <label
                class="flex w-fit items-center gap-3 text-sm/6 text-zinc-700 dark:text-zinc-300"
            >
                <span class="group grid size-4 grid-cols-1">
                    <input
                        name="remember"
                        type="checkbox"
                        value="1"
                        class="col-start-1 row-start-1 appearance-none rounded border border-zinc-300 bg-white checked:border-brand-700 checked:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-white/15 dark:bg-white/5 dark:checked:border-brand-500 dark:checked:bg-brand-500"
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
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-700 px-3 py-2.5 text-sm/6 font-semibold text-white shadow-sm transition hover:bg-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-brand-500 dark:hover:bg-brand-400"
            >
                <LoaderCircle
                    v-if="processing"
                    class="size-4 animate-spin"
                    aria-hidden="true"
                />
                {{ processing ? 'A entrar…' : 'Entrar' }}
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
                        >ou continue com</span
                    >
                </div>
            </div>
            <a
                :href="googleRedirect.url()"
                class="flex w-full items-center justify-center gap-3 rounded-xl bg-white px-3 py-2.5 text-sm font-semibold text-zinc-900 shadow-sm ring-1 ring-zinc-300 transition hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:hover:bg-white/10"
            >
                <GoogleMark />
                Google
            </a>
        </template>

        <template #footer>
            <p class="text-center">
                Ainda não tem conta?
                <Link
                    :href="register.url()"
                    class="font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300"
                    >Criar conta</Link
                >
            </p>
            <p
                class="mt-4 flex items-center justify-center gap-2 text-xs text-zinc-400"
            >
                <LockKeyhole class="size-3.5" aria-hidden="true" />
                Sessão protegida e auditável
            </p>
        </template>
    </AuthLayout>
</template>
