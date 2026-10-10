<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { CheckCircle2, LoaderCircle, Mail } from '@lucide/vue';
import { computed } from 'vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { send as verificationSend } from '@/routes/verification';

const page = usePage();
const user = computed(() => page.props.auth.user);
const linkWasSent = computed(
    () =>
        (page.props.flash as { status?: string }).status ===
        'verification-link-sent',
);
</script>

<template>
    <AuthLayout
        eyebrow="Falta um passo"
        title="Confirme o seu email"
        :description="`Enviámos uma ligação para ${user?.email ?? 'o seu email'}. Abra-a para confirmar que o endereço é seu.`"
    >
        <Head title="Confirmar email" />

        <div
            v-if="linkWasSent"
            class="mb-6 flex gap-3 rounded-xl bg-emerald-50 p-4 text-sm/6 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20"
            role="status"
        >
            <CheckCircle2 class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <span>Foi enviada uma nova ligação de verificação.</span>
        </div>

        <div
            class="rounded-2xl bg-zinc-50 p-5 ring-1 ring-zinc-200 dark:bg-white/5 dark:ring-white/10"
        >
            <Mail
                class="size-6 text-brand-700 dark:text-brand-300"
                aria-hidden="true"
            />
            <p class="mt-3 text-sm/6 text-zinc-600 dark:text-zinc-300">
                Abra a mensagem no mesmo dispositivo ou copie a ligação para
                este navegador. Verifique também a pasta de correio não
                solicitado.
            </p>
        </div>

        <Form
            v-bind="verificationSend.form()"
            class="mt-6"
            #default="{ processing }"
        >
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
                {{ processing ? 'A reenviar…' : 'Reenviar email' }}
            </button>
        </Form>

        <template #footer>
            <p class="text-center">
                Email errado?
                <Link
                    :href="logout.url()"
                    method="post"
                    as="button"
                    class="rounded font-semibold text-brand-700 focus-ring hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-100"
                    >Terminar sessão</Link
                >
            </p>
        </template>
    </AuthLayout>
</template>
