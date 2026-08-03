<script setup lang="ts">
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Check,
    ChevronsUpDown,
    CreditCard,
    FileClock,
    FileUp,
    Landmark,
    LayoutDashboard,
    ReceiptText,
    Settings2,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { dashboard, onboarding } from '@/routes';
import { show as agtConnection } from '@/routes/agt/connection';
import { index as agtSubmissions } from '@/routes/agt/submissions';
import { show as billingShow } from '@/routes/billing';
import { index as importIndex } from '@/routes/imports';
import { create as invoiceCreate } from '@/routes/invoices';
import { security } from '@/routes/settings';
import { update as switchWorkspace } from '@/routes/workspace/current';

defineEmits<{
    navigate: [];
}>();

interface NavigationItem {
    name: string;
    href: string;
    match: string;
    icon: Component;
}

interface NavigationSection {
    label: string;
    items: NavigationItem[];
}

const page = usePage();
const currentWorkspace = computed(() => page.props.currentWorkspace);
const legalEntity = computed(() => currentWorkspace.value?.legal_entity);

const displayName = computed(
    () =>
        legalEntity.value?.trade_name ??
        legalEntity.value?.legal_name ??
        currentWorkspace.value?.name ??
        'Espaço de trabalho',
);

const workspaceDetail = computed(() => {
    if (legalEntity.value?.masked_nif) {
        const establishment = legalEntity.value.establishment_name
            ? ` · ${legalEntity.value.establishment_name}`
            : '';

        return `NIF ${legalEntity.value.masked_nif}${establishment}`;
    }

    return currentWorkspace.value?.role_label ?? 'A preparar';
});

const sections: NavigationSection[] = [
    {
        label: 'Visão geral',
        items: [
            {
                name: 'Painel fiscal',
                href: dashboard.url(),
                match: '/dashboard',
                icon: LayoutDashboard,
            },
        ],
    },
    {
        label: 'Operações',
        items: [
            {
                name: 'Nova factura',
                href: invoiceCreate.url(),
                match: '/documents/invoices',
                icon: ReceiptText,
            },
            {
                name: 'Ligação AGT',
                href: agtConnection.url(),
                match: '/agt/connection',
                icon: Landmark,
            },
            {
                name: 'Monitor AGT',
                href: agtSubmissions.url(),
                match: '/agt/submissions',
                icon: FileClock,
            },
            {
                name: 'Importar dados',
                href: importIndex.url(),
                match: '/imports',
                icon: FileUp,
            },
        ],
    },
    {
        label: 'Configuração',
        items: [
            {
                name: 'Preparar empresa',
                href: onboarding.url(),
                match: '/onboarding',
                icon: Building2,
            },
            {
                name: 'Conta e segurança',
                href: security.url(),
                match: '/settings/security',
                icon: Settings2,
            },
            {
                name: 'Plano e cobrança',
                href: billingShow.url(),
                match: '/settings/billing',
                icon: CreditCard,
            },
        ],
    },
];

function initials(name: string): string {
    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase();
}

function isCurrent(item: NavigationItem): boolean {
    return page.url.startsWith(item.match);
}
</script>

<template>
    <div
        class="flex h-full grow flex-col gap-y-6 overflow-y-auto bg-brand-950 px-5 pb-5 text-white dark:bg-[#061c17] dark:ring-1 dark:ring-white/10"
    >
        <div class="flex h-20 shrink-0 items-center">
            <AppLogo inverted />
        </div>

        <Menu as="div" class="relative">
            <MenuButton
                class="group flex w-full items-center gap-3 rounded-xl border border-white/10 bg-white/[0.06] p-3 text-left transition hover:border-white/20 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-300"
            >
                <span
                    class="grid size-9 shrink-0 place-items-center rounded-lg bg-amber-300 text-sm font-bold text-brand-950"
                    >{{ initials(displayName) }}</span
                >
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold">{{
                        displayName
                    }}</span>
                    <span
                        class="mt-0.5 block truncate text-xs text-brand-100/65"
                        >{{ workspaceDetail }}</span
                    >
                </span>
                <ChevronsUpDown
                    class="size-4 shrink-0 text-brand-100/60 transition group-hover:text-white"
                    aria-hidden="true"
                />
            </MenuButton>

            <transition
                enter-active-class="transition ease-out duration-100"
                enter-from-class="scale-95 opacity-0"
                enter-to-class="scale-100 opacity-100"
                leave-active-class="transition ease-in duration-75"
                leave-from-class="scale-100 opacity-100"
                leave-to-class="scale-95 opacity-0"
            >
                <MenuItems
                    class="absolute left-0 z-30 mt-2 w-full origin-top rounded-xl bg-white p-1.5 text-zinc-900 shadow-2xl ring-1 ring-zinc-900/10 focus:outline-none dark:bg-zinc-900 dark:text-white dark:ring-white/10"
                >
                    <p
                        class="px-2.5 pt-1.5 pb-2 text-[0.65rem] font-semibold tracking-[0.14em] text-zinc-400 uppercase"
                    >
                        Espaços de trabalho
                    </p>
                    <MenuItem
                        v-for="workspace in page.props.auth.workspaces"
                        :key="workspace.public_id"
                        v-slot="{ active }"
                    >
                        <Link
                            as="button"
                            method="put"
                            preserve-scroll
                            :href="switchWorkspace.url(workspace.public_id)"
                            :disabled="workspace.current"
                            :class="[
                                active ? 'bg-zinc-100 dark:bg-white/5' : '',
                                'flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm disabled:cursor-default',
                            ]"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{
                                    workspace.name
                                }}</span>
                                <span
                                    class="block truncate text-xs text-zinc-500 dark:text-zinc-400"
                                    >{{ workspace.role_label }}</span
                                >
                            </span>
                            <Check
                                v-if="workspace.current"
                                class="size-4 text-brand-700 dark:text-brand-300"
                                aria-hidden="true"
                            />
                        </Link>
                    </MenuItem>
                </MenuItems>
            </transition>
        </Menu>

        <nav class="flex flex-1 flex-col" aria-label="Navegação principal">
            <ul role="list" class="flex flex-1 flex-col gap-y-7">
                <li v-for="section in sections" :key="section.label">
                    <p
                        class="px-2 text-[0.65rem] font-semibold tracking-[0.18em] text-brand-100/50 uppercase"
                    >
                        {{ section.label }}
                    </p>
                    <ul role="list" class="mt-2 space-y-1">
                        <li v-for="item in section.items" :key="item.name">
                            <Link
                                :href="item.href"
                                prefetch
                                :class="[
                                    isCurrent(item)
                                        ? 'bg-white text-brand-950 shadow-sm'
                                        : 'text-brand-100/80 hover:bg-white/10 hover:text-white',
                                    'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition',
                                ]"
                                @click="$emit('navigate')"
                            >
                                <component
                                    :is="item.icon"
                                    :class="[
                                        isCurrent(item)
                                            ? 'text-brand-700'
                                            : 'text-brand-200/65 group-hover:text-amber-300',
                                        'size-5 shrink-0 transition',
                                    ]"
                                    :stroke-width="1.8"
                                    aria-hidden="true"
                                />
                                {{ item.name }}
                            </Link>
                        </li>
                    </ul>
                </li>

                <li class="mt-auto">
                    <div
                        class="rounded-xl border border-emerald-300/20 bg-emerald-300/[0.08] p-3"
                    >
                        <div
                            class="flex items-center gap-2 text-xs font-semibold text-emerald-200"
                        >
                            <ShieldCheck class="size-4" aria-hidden="true" />
                            Integração fiscal protegida
                        </div>
                        <p class="mt-1.5 text-xs/5 text-brand-100/60">
                            Homologação isolada, chaves fora da base de dados e
                            entregas com prova de integridade.
                        </p>
                    </div>
                    <div
                        class="mt-4 flex items-center justify-between px-2 text-[0.65rem] text-brand-100/45"
                    >
                        <span>Base de conformidade</span>
                        <span class="font-mono">v0.6 · fase 5</span>
                    </div>
                </li>
            </ul>
        </nav>
    </div>
</template>
