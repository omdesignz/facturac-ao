<script setup lang="ts">
import { Disclosure, DisclosureButton, DisclosurePanel } from '@headlessui/vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ChevronDown,
    Clock,
    LoaderCircle,
    Mail,
    MessageSquareWarning,
    Phone,
    Scale,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import FormError from '@/components/FormError.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { store as storeComplaint } from '@/routes/help/complaints';
import type { SelectOption } from '@/types/select';

interface OwnComplaint {
    reference: string;
    subject: string;
    category_label: string;
    status: string;
    status_label: string;
    resolution: string | null;
    created_at: string | null;
    response_due_at: string;
    resolved_at: string | null;
    overdue: boolean;
}

const props = defineProps<{
    channels: {
        support_email: string;
        support_phone: string;
        support_whatsapp: string;
        support_hours: string;
        complaints_email: string;
        response_days: number;
        privacy_email: string;
    };
    consumerAuthority: { name: string; url: string; phone: string };
    categories: { value: string; label: string }[];
    contact: { name: string; email: string };
    complaints: OwnComplaint[];
    topics: { question: string; answer: string }[];
}>();

const form = useForm({
    category: 'invoicing',
    subject: '',
    body: '',
    contact_name: props.contact.name,
    contact_email: props.contact.email,
    contact_phone: '',
});

const categoryOptions = computed<SelectOption[]>(() =>
    props.categories.map((category) => ({
        value: category.value,
        label: category.label,
    })),
);

function submit(): void {
    form.post(storeComplaint.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('subject', 'body');
            form.clearErrors();
        },
    });
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium' });

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

const statusTone: Record<string, 'success' | 'warning' | 'neutral' | 'danger'> =
    {
        open: 'warning',
        in_progress: 'warning',
        resolved: 'success',
        rejected: 'neutral',
    };

const whatsappHref = computed(
    () => `https://wa.me/${props.channels.support_whatsapp.replace(/\D/g, '')}`,
);
</script>

<template>
    <AppLayout>
        <Head title="Ajuda e Reclamações" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-5xl space-y-6">
                <header>
                    <p class="eyebrow text-brand-700 dark:text-brand-300">
                        Estamos cá
                    </p>
                    <h1
                        class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                    >
                        Ajuda e Reclamações
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Fale connosco pelo canal que lhe der mais jeito. Se algo
                        correu mal, registe uma reclamação — recebe uma
                        referência e uma resposta em
                        {{ channels.response_days }} dias úteis.
                    </p>
                </header>

                <section class="grid gap-4 sm:grid-cols-3">
                    <a
                        :href="`mailto:${channels.support_email}`"
                        class="rounded-2xl surface p-4 focus-ring transition hover:shadow-md"
                    >
                        <Mail
                            class="size-5 text-brand-700 dark:text-accent-300"
                            aria-hidden="true"
                        />
                        <p
                            class="mt-3 text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Email
                        </p>
                        <p
                            class="mt-1 text-sm break-all text-zinc-600 dark:text-zinc-400"
                        >
                            {{ channels.support_email }}
                        </p>
                    </a>

                    <a
                        :href="`tel:${channels.support_phone.replace(/\s/g, '')}`"
                        class="rounded-2xl surface p-4 focus-ring transition hover:shadow-md"
                    >
                        <Phone
                            class="size-5 text-brand-700 dark:text-accent-300"
                            aria-hidden="true"
                        />
                        <p
                            class="mt-3 text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Telefone
                        </p>
                        <p
                            class="mt-1 text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            {{ channels.support_phone }}
                        </p>
                    </a>

                    <a
                        :href="whatsappHref"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rounded-2xl surface p-4 focus-ring transition hover:shadow-md"
                    >
                        <MessageSquareWarning
                            class="size-5 text-brand-700 dark:text-accent-300"
                            aria-hidden="true"
                        />
                        <p
                            class="mt-3 text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            WhatsApp
                        </p>
                        <p
                            class="mt-1 text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            {{ channels.support_whatsapp }}
                        </p>
                    </a>
                </section>

                <p
                    class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400"
                >
                    <Clock class="size-4" aria-hidden="true" />
                    {{ channels.support_hours }}
                </p>

                <section class="overflow-hidden rounded-2xl surface">
                    <header
                        class="border-b border-zinc-100 p-5 dark:border-white/10"
                    >
                        <h2
                            class="text-base font-semibold text-zinc-950 dark:text-white"
                        >
                            Perguntas frequentes
                        </h2>
                    </header>

                    <div class="divide-y divide-zinc-100 dark:divide-white/10">
                        <Disclosure
                            v-for="topic in topics"
                            :key="topic.question"
                            v-slot="{ open }"
                        >
                            <DisclosureButton
                                class="flex w-full items-center justify-between gap-4 p-5 text-start focus-ring"
                            >
                                <span
                                    class="text-sm font-medium text-zinc-950 dark:text-white"
                                >
                                    {{ topic.question }}
                                </span>
                                <ChevronDown
                                    :class="[
                                        open ? 'rotate-180' : '',
                                        'size-4 shrink-0 text-zinc-400 transition-transform',
                                    ]"
                                    aria-hidden="true"
                                />
                            </DisclosureButton>
                            <DisclosurePanel
                                class="px-5 pb-5 text-sm/6 text-zinc-600 dark:text-zinc-400"
                            >
                                {{ topic.answer }}
                            </DisclosurePanel>
                        </Disclosure>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl surface">
                    <header
                        class="border-b border-zinc-100 p-5 dark:border-white/10"
                    >
                        <h2
                            class="text-base font-semibold text-zinc-950 dark:text-white"
                        >
                            Registar uma reclamação
                        </h2>
                        <p
                            class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            Fica registada no nosso livro de reclamações, com
                            referência própria. Respondemos em
                            {{ channels.response_days }} dias úteis.
                        </p>
                    </header>

                    <form class="space-y-5 p-5" @submit.prevent="submit">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label
                                    class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                >
                                    Assunto da reclamação
                                </label>
                                <div class="mt-2">
                                    <SelectInput
                                        v-model="form.category"
                                        :options="categoryOptions"
                                    />
                                </div>
                                <FormError :message="form.errors.category" />
                            </div>

                            <div class="sm:col-span-2">
                                <label
                                    for="complaint-subject"
                                    class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                >
                                    Resumo
                                </label>
                                <input
                                    id="complaint-subject"
                                    v-model="form.subject"
                                    type="text"
                                    maxlength="150"
                                    placeholder="Ex.: a factura FT 2026/14 não foi comunicada"
                                    class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset placeholder:text-zinc-400 dark:bg-white/5 dark:text-white dark:ring-white/10"
                                />
                                <FormError :message="form.errors.subject" />
                            </div>

                            <div class="sm:col-span-2">
                                <label
                                    for="complaint-body"
                                    class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                >
                                    O que aconteceu
                                </label>
                                <textarea
                                    id="complaint-body"
                                    v-model="form.body"
                                    rows="5"
                                    maxlength="4000"
                                    placeholder="Descreva o que correu mal, quando, e o que já tentou."
                                    class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset placeholder:text-zinc-400 dark:bg-white/5 dark:text-white dark:ring-white/10"
                                />
                                <FormError :message="form.errors.body" />
                            </div>

                            <div>
                                <label
                                    for="complaint-name"
                                    class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                >
                                    O seu nome
                                </label>
                                <input
                                    id="complaint-name"
                                    v-model="form.contact_name"
                                    type="text"
                                    class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                />
                                <FormError
                                    :message="form.errors.contact_name"
                                />
                            </div>

                            <div>
                                <label
                                    for="complaint-email"
                                    class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                >
                                    Email para resposta
                                </label>
                                <input
                                    id="complaint-email"
                                    v-model="form.contact_email"
                                    type="email"
                                    class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                />
                                <FormError
                                    :message="form.errors.contact_email"
                                />
                            </div>

                            <div>
                                <label
                                    for="complaint-phone"
                                    class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                >
                                    Telefone
                                    <span class="text-zinc-400"
                                        >(opcional)</span
                                    >
                                </label>
                                <input
                                    id="complaint-phone"
                                    v-model="form.contact_phone"
                                    type="tel"
                                    class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                                />
                                <FormError
                                    :message="form.errors.contact_phone"
                                />
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:opacity-60 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                            >
                                <LoaderCircle
                                    v-if="form.processing"
                                    class="size-4 animate-spin"
                                    aria-hidden="true"
                                />
                                Registar reclamação
                            </button>
                        </div>
                    </form>
                </section>

                <section
                    v-if="complaints.length > 0"
                    class="overflow-hidden rounded-2xl surface"
                >
                    <header
                        class="border-b border-zinc-100 p-5 dark:border-white/10"
                    >
                        <h2
                            class="text-base font-semibold text-zinc-950 dark:text-white"
                        >
                            As suas reclamações
                        </h2>
                    </header>

                    <ul class="divide-y divide-zinc-100 dark:divide-white/10">
                        <li
                            v-for="complaint in complaints"
                            :key="complaint.reference"
                            class="p-5"
                        >
                            <div class="flex flex-wrap items-center gap-3">
                                <span
                                    class="numeric text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{ complaint.reference }}
                                </span>
                                <StatusBadge
                                    :tone="statusTone[complaint.status]"
                                    :label="complaint.status_label"
                                />
                                <span
                                    v-if="complaint.overdue"
                                    class="rounded-lg bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-400/10 dark:text-rose-300"
                                >
                                    Fora do prazo
                                </span>
                                <span
                                    class="ms-auto text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ formatDate(complaint.created_at) }}
                                </span>
                            </div>

                            <p
                                class="mt-2 text-sm font-medium text-zinc-950 dark:text-white"
                            >
                                {{ complaint.subject }}
                            </p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ complaint.category_label }} · resposta até
                                {{ formatDate(complaint.response_due_at) }}
                            </p>

                            <p
                                v-if="complaint.resolution"
                                class="mt-3 rounded-xl bg-zinc-50 p-3 text-sm/6 text-zinc-700 dark:bg-white/5 dark:text-zinc-300"
                            >
                                {{ complaint.resolution }}
                            </p>
                        </li>
                    </ul>
                </section>

                <section
                    class="flex flex-col gap-3 rounded-2xl bg-zinc-50 p-5 text-sm/6 text-zinc-600 sm:flex-row sm:items-start dark:bg-white/5 dark:text-zinc-400"
                >
                    <Scale
                        class="size-5 shrink-0 text-zinc-400"
                        aria-hidden="true"
                    />
                    <div>
                        <p class="font-semibold text-zinc-950 dark:text-white">
                            Se a nossa resposta não o satisfizer
                        </p>
                        <p class="mt-1">
                            Pode recorrer ao {{ consumerAuthority.name }} —
                            <a
                                :href="consumerAuthority.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-medium text-brand-700 underline underline-offset-4 dark:text-accent-300"
                                >{{ consumerAuthority.url }}</a
                            >, {{ consumerAuthority.phone }}.
                        </p>
                        <p
                            class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <ShieldCheck
                                class="size-4 text-zinc-400"
                                aria-hidden="true"
                            />
                            Questões sobre dados pessoais:
                            <a
                                :href="`mailto:${channels.privacy_email}`"
                                class="font-medium text-brand-700 underline underline-offset-4 dark:text-accent-300"
                                >{{ channels.privacy_email }}</a
                            >
                            <Link
                                href="/privacidade"
                                class="text-zinc-500 underline underline-offset-4 dark:text-zinc-400"
                                >Política de Privacidade</Link
                            >
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
