<script setup lang="ts">
import { Disclosure, DisclosureButton, DisclosurePanel } from '@headlessui/vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    BookOpen,
    ArrowRight,
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
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { store as storeComplaint } from '@/routes/help/complaints';
import { index as userGuideIndex } from '@/routes/help/guide';
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

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="page-shell space-y-6">
                <header>
                    <p class="text-[0.8125rem] text-[var(--ui-ink-3)]">
                        Estamos cá
                    </p>
                    <h1
                        class="mt-0.5 text-[1.375rem] leading-[1.2] font-[650] tracking-[-0.025em] text-balance text-[var(--ui-ink)]"
                    >
                        Ajuda e Reclamações
                    </h1>
                    <p
                        class="mt-1.5 max-w-2xl text-[0.8125rem]/5 text-[var(--ui-ink-3)]"
                    >
                        Fale connosco pelo canal que lhe der mais jeito. Se algo
                        correu mal, registe uma reclamação — recebe uma
                        referência e uma resposta em
                        {{ channels.response_days }} dias úteis.
                    </p>
                </header>

                <Link
                    :href="userGuideIndex.url()"
                    class="flex flex-wrap items-center gap-4 rounded-2xl surface p-5 focus-ring active:opacity-75"
                >
                    <BookOpen
                        class="size-7 text-accent-600 dark:text-accent-300"
                        aria-hidden="true"
                    />
                    <div class="flex-1">
                        <h2 class="font-semibold">Aprenda a usar o facturac</h2>
                        <p class="text-sm/6 text-zinc-600 dark:text-zinc-400">
                            Guia interactivo, listas de conferência e PDF para
                            ter sempre à mão.
                        </p>
                    </div>
                    <ArrowRight class="size-5" aria-hidden="true" />
                </Link>

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
                            <FormField
                                class="sm:col-span-2"
                                label="Assunto da reclamação"
                                :error="form.errors.category"
                                ><div>
                                    <SelectInput
                                        v-model="form.category"
                                        :options="categoryOptions"
                                    /></div
                            ></FormField>

                            <FormField
                                class="sm:col-span-2"
                                for="complaint-subject"
                                label="Resumo"
                                :error="form.errors.subject"
                                ><input
                                    id="complaint-subject"
                                    v-model="form.subject"
                                    type="text"
                                    maxlength="150"
                                    placeholder="Ex.: a factura FT 2026/14 não foi comunicada"
                                    class="form-input"
                            /></FormField>

                            <FormField
                                class="sm:col-span-2"
                                for="complaint-body"
                                label="O que aconteceu"
                                :error="form.errors.body"
                            >
                                <textarea
                                    id="complaint-body"
                                    v-model="form.body"
                                    rows="5"
                                    maxlength="4000"
                                    placeholder="Descreva o que correu mal, quando, e o que já tentou."
                                    class="form-input [--form-rows:5]"
                                />
                            </FormField>

                            <FormField
                                for="complaint-name"
                                label="O seu nome"
                                :error="form.errors.contact_name"
                                ><input
                                    id="complaint-name"
                                    v-model="form.contact_name"
                                    type="text"
                                    class="form-input"
                            /></FormField>

                            <FormField
                                for="complaint-email"
                                label="Email para resposta"
                                :error="form.errors.contact_email"
                                ><input
                                    id="complaint-email"
                                    v-model="form.contact_email"
                                    type="email"
                                    class="form-input"
                            /></FormField>

                            <FormField
                                for="complaint-phone"
                                label="Telefone"
                                optional
                                :error="form.errors.contact_phone"
                                ><input
                                    id="complaint-phone"
                                    v-model="form.contact_phone"
                                    type="tel"
                                    class="form-input"
                            /></FormField>
                        </div>

                        <div class="flex justify-end">
                            <AppButton
                                variant="gold"
                                type="submit"
                                :disabled="form.processing"
                            >
                                <LoaderCircle
                                    v-if="form.processing"
                                    class="size-4 animate-spin"
                                    aria-hidden="true"
                                />
                                Registar reclamação
                            </AppButton>
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
