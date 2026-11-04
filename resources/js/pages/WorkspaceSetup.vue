<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { Building2, LoaderCircle } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { store as workspaceStore } from '@/routes/workspace';

const page = usePage();
</script>

<template>
    <AuthLayout
        title="Como chama o seu negócio?"
        :description="`Olá, ${page.props.auth.user?.name ?? ''}. Este nome identifica a sua conta na aplicação.`"
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
            <FormField
                for="workspace-name"
                label="Nome do espaço"
                :error="errors.workspace_name"
                ><input
                    id="workspace-name"
                    name="workspace_name"
                    type="text"
                    autocomplete="organization"
                    required
                    autofocus
                    class="block form-input"
                    placeholder="Ex.: Kwanza Mercantil"
            /></FormField>

            <AppButton
                variant="gold"
                type="submit"
                :disabled="processing"
                class="w-full"
            >
                <LoaderCircle
                    v-if="processing"
                    class="size-4 animate-spin"
                    aria-hidden="true"
                />
                {{ processing ? 'A criar…' : 'Criar espaço' }}
            </AppButton>
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
