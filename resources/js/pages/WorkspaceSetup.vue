<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { Building2, LoaderCircle } from '@lucide/vue';
import FormError from '@/components/FormError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { store as workspaceStore } from '@/routes/workspace';

const page = usePage();
</script>

<template>
    <AuthLayout
        eyebrow="Primeiro passo"
        title="Como chama o seu negócio?"
        :description="`Olá, ${page.props.auth.user?.name ?? ''}. O espaço organiza utilizadores, empresas e estabelecimentos sem misturar os respectivos dados.`"
    >
        <Head title="Criar espaço de trabalho" />

        <div
            class="mb-6 flex gap-3 rounded-xl bg-brand-50 p-4 text-sm/6 text-brand-900 ring-1 ring-brand-200 dark:bg-brand-400/10 dark:text-brand-200 dark:ring-brand-400/20"
        >
            <Building2 class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <span
                >Use um nome fácil de reconhecer. A denominação legal e o NIF
                vêm a seguir.</span
            >
        </div>

        <Form
            v-bind="workspaceStore.form()"
            class="space-y-5"
            #default="{ errors, processing }"
        >
            <div>
                <label
                    for="workspace-name"
                    class="block text-sm/6 font-medium text-zinc-900 dark:text-zinc-100"
                    >Nome do espaço</label
                >
                <input
                    id="workspace-name"
                    name="workspace_name"
                    type="text"
                    autocomplete="organization"
                    required
                    autofocus
                    class="mt-2 block w-full rounded-xl bg-white px-3 py-2.5 text-base text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:text-sm dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                    placeholder="Ex.: Kwanza Mercantil"
                />
                <FormError :message="errors.workspace_name" />
            </div>

            <button
                type="submit"
                :disabled="processing"
                class="flex h-10 w-full items-center justify-center gap-2 rounded-full bg-accent-400 px-[1.125rem] text-sm font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] focus-ring transition hover:bg-accent-300 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <LoaderCircle
                    v-if="processing"
                    class="size-4 animate-spin"
                    aria-hidden="true"
                />
                {{ processing ? 'A criar…' : 'Criar espaço' }}
            </button>
        </Form>

        <template #footer>
            <p class="text-center">
                Não é a conta certa?
                <Link
                    :href="logout.url()"
                    method="post"
                    as="button"
                    class="font-semibold text-brand-700 hover:text-brand-600 dark:text-brand-300"
                    >Terminar sessão</Link
                >
            </p>
        </template>
    </AuthLayout>
</template>
