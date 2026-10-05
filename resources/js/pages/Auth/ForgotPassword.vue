<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle, MailCheck } from '@lucide/vue';
import { computed } from 'vue';
import FormError from '@/components/FormError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { email as passwordEmail } from '@/routes/password';

const page = usePage();
const status = computed(() => (page.props.flash as { status?: string }).status);
</script>

<template>
    <AuthLayout
        eyebrow="Recuperar acesso"
        title="Redefina a palavra-passe"
        description="Indique o email da conta. Se estiver registado, enviamos-lhe uma ligação que expira daqui a pouco."
    >
        <Head title="Recuperar palavra-passe" />

        <div
            v-if="status"
            class="mb-6 flex gap-3 rounded-xl bg-emerald-50 p-4 text-sm/6 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20"
            role="status"
        >
            <MailCheck class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <span
                >Se o email existir, a ligação de recuperação foi enviada.</span
            >
        </div>

        <Form
            v-bind="passwordEmail.form()"
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
                    autocomplete="email"
                    required
                    autofocus
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                    placeholder="nome@empresa.ao"
                />
                <FormError :message="errors.email" />
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
                {{ processing ? 'A enviar…' : 'Enviar ligação segura' }}
            </button>
        </Form>

        <template #footer>
            <p class="text-center">
                <Link
                    :href="login.url()"
                    class="inline-flex items-center gap-2 font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Voltar à entrada
                </Link>
            </p>
        </template>
    </AuthLayout>
</template>
