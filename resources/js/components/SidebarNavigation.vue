<script setup lang="ts">
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    Building2,
    CalendarSync,
    ChartLine,
    Check,
    ChevronsUpDown,
    ClipboardList,
    CreditCard,
    FileClock,
    FileCode2,
    Files,
    FileMinus2,
    FileSignature,
    FileUp,
    HandCoins,
    LifeBuoy,
    Landmark,
    LayoutDashboard,
    Package,
    Tags,
    ReceiptEuro,
    ReceiptText,
    Settings2,
    SlidersHorizontal,
    ShieldCheck,
    Store,
    Truck,
    UserCog,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import RailTooltip from '@/components/RailTooltip.vue';
import { dashboard, onboarding } from '@/routes';
import { show as agtConnection } from '@/routes/agt/connection';
import { index as agtSubmissions } from '@/routes/agt/submissions';
import { index as analyticsIndex } from '@/routes/analytics';
import { show as billingShow } from '@/routes/billing';
import { index as billingCatalogue } from '@/routes/catalogue';
import { index as customersIndex } from '@/routes/customers';
import { index as debtsIndex } from '@/routes/debts';
import { index as documentsIndex } from '@/routes/documents';
import { index as establishmentsIndex } from '@/routes/establishments';
import { index as helpIndex } from '@/routes/help';
import { index as importIndex } from '@/routes/imports';
import { index as priceListsIndex } from '@/routes/price-lists';
import { index as quotesIndex } from '@/routes/quotes';
import { index as recurringIndex } from '@/routes/recurring';
import { index as saftIndex } from '@/routes/saft';
import { account as accountSettings, security } from '@/routes/settings';
import { index as stockIndex } from '@/routes/stock';
import { index as supportIndex } from '@/routes/support';
import { index as supportComplaints } from '@/routes/support/complaints';
import { edit as supportSettings } from '@/routes/support/settings';
import { index as transportDocumentsIndex } from '@/routes/transport-documents';
import { update as switchWorkspace } from '@/routes/workspace/current';

withDefaults(
    defineProps<{
        /** Renders the icon-only rail. Desktop only — the mobile dialog is always expanded. */
        collapsed?: boolean;
    }>(),
    {
        collapsed: false,
    },
);

defineEmits<{
    navigate: [];
}>();

interface NavigationItem {
    name: string;
    href: string;
    match: string;
    /** Distinguishes filtered register entries that share `/documentos`. */
    matchFamily?: 'invoice' | 'adjustment' | 'receipt';
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

const baseSections: NavigationSection[] = [
    {
        label: 'Visão geral',
        items: [
            {
                name: 'Painel fiscal',
                href: dashboard.url(),
                match: '/dashboard',
                icon: LayoutDashboard,
            },
            {
                name: 'Análises',
                href: analyticsIndex.url(),
                match: '/analises',
                icon: ChartLine,
            },
        ],
    },
    {
        label: 'Facturação',
        items: [
            {
                name: 'Documentos fiscais',
                href: documentsIndex.url(),
                match: '/documentos',
                icon: Files,
            },
            {
                name: 'Facturas',
                href: documentsIndex.url({ query: { family: 'invoice' } }),
                match: '/documentos',
                matchFamily: 'invoice',
                icon: ReceiptText,
            },
            {
                name: 'Recibos',
                href: documentsIndex.url({ query: { family: 'receipt' } }),
                match: '/documentos',
                matchFamily: 'receipt',
                icon: ReceiptEuro,
            },
            {
                name: 'Notas de correcção',
                href: documentsIndex.url({ query: { family: 'adjustment' } }),
                match: '/documentos',
                matchFamily: 'adjustment',
                icon: FileMinus2,
            },
            {
                name: 'Orçamentos',
                href: quotesIndex.url(),
                match: '/orcamentos',
                icon: FileSignature,
            },
            {
                name: 'Guias e transporte',
                href: transportDocumentsIndex.url(),
                match: '/guias',
                icon: Truck,
            },
            {
                name: 'Avenças',
                href: recurringIndex.url(),
                match: '/avencas',
                icon: CalendarSync,
            },
            {
                name: 'Monitor AGT',
                href: agtSubmissions.url(),
                match: '/agt/submissions',
                icon: FileClock,
            },
            {
                name: 'SAF-T (AO)',
                href: saftIndex.url(),
                match: '/saft',
                icon: FileCode2,
            },
        ],
    },
    {
        label: 'Registos',
        items: [
            {
                name: 'Clientes',
                href: customersIndex.url(),
                match: '/customers',
                icon: Users,
            },
            {
                name: 'Artigos e serviços',
                href: billingCatalogue.url(),
                match: '/catalogue',
                icon: Package,
            },
            {
                name: 'Tabelas de preços',
                href: priceListsIndex.url(),
                match: '/tabelas-de-precos',
                icon: Tags,
            },
            {
                name: 'Dívidas',
                href: debtsIndex.url(),
                match: '/dividas',
                icon: HandCoins,
            },
            {
                name: 'Existências',
                href: stockIndex.url(),
                match: '/existencias',
                icon: Boxes,
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
                name: 'Estabelecimentos',
                href: establishmentsIndex.url(),
                match: '/estabelecimentos',
                icon: Store,
            },
            {
                name: 'Ligação AGT',
                href: agtConnection.url(),
                match: '/agt/connection',
                icon: Landmark,
            },
            {
                name: 'Conta e segurança',
                href: security.url(),
                match: '/settings/security',
                icon: Settings2,
            },
            {
                name: 'A sua conta',
                href: accountSettings.url(),
                match: '/settings/conta',
                icon: UserCog,
            },
            {
                name: 'Plano e cobrança',
                href: billingShow.url(),
                match: '/settings/billing',
                icon: CreditCard,
            },
            {
                name: 'Ajuda e reclamações',
                href: helpIndex.url(),
                match: '/ajuda',
                icon: LifeBuoy,
            },
        ],
    },
];

/**
 * The support console only exists for the platform's own staff, so customers
 * are never shown a door they cannot open.
 */
const sections = computed<NavigationSection[]>(() =>
    page.props.auth.user?.is_support_staff === true
        ? [
              ...baseSections,
              {
                  label: 'Interno',
                  items: [
                      {
                          name: 'Apoio ao cliente',
                          href: supportIndex.url(),
                          match: '/support',
                          icon: LifeBuoy,
                      },
                      {
                          name: 'Reclamações',
                          href: supportComplaints.url(),
                          match: '/support/reclamacoes',
                          icon: ClipboardList,
                      },
                      {
                          name: 'Definições',
                          href: supportSettings.url(),
                          match: '/support/definicoes',
                          icon: SlidersHorizontal,
                      },
                  ],
              },
          ]
        : baseSections,
);

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
    const isDocumentComposer =
        item.match === '/documentos' &&
        page.url.startsWith('/documents/invoices');

    if (!page.url.startsWith(item.match) && !isDocumentComposer) {
        return false;
    }

    if (item.matchFamily === undefined) {
        return !/[?&]family=(invoice|receipt|adjustment)\b/.test(page.url);
    }

    return (
        new URLSearchParams(page.url.split('?')[1] ?? '').get('family') ===
        item.matchFamily
    );
}
</script>

<template>
    <!-- The root deliberately has no overflow: when collapsed the workspace
         menu is wider than the rail and must not be clipped. Scrolling lives
         on the <nav> below instead. -->
    <div
        data-rail
        :class="[
            collapsed ? 'items-center gap-y-4 px-3' : 'gap-y-6 px-5',
            'flex h-full grow flex-col bg-brand-950 pb-5 text-white dark:bg-brand-950 dark:ring-1 dark:ring-white/10',
        ]"
    >
        <div
            :class="[
                collapsed ? 'h-16 justify-center' : 'h-20',
                'flex shrink-0 items-center',
            ]"
        >
            <AppLogo inverted :compact="collapsed" />
        </div>

        <Menu as="div" :class="[collapsed ? '' : 'w-full', 'relative']">
            <RailTooltip
                v-if="collapsed"
                :label="displayName"
                :detail="workspaceDetail"
            >
                <MenuButton
                    class="grid size-11 place-items-center rounded-xl bg-accent-400 text-sm font-bold text-brand-950 focus-ring-inverted transition hover:bg-accent-300"
                >
                    <span class="sr-only">Mudar de espaço de trabalho</span>
                    {{ initials(displayName) }}
                </MenuButton>
            </RailTooltip>

            <MenuButton
                v-else
                class="group flex w-full items-center gap-3 rounded-xl border border-white/10 bg-white/[0.06] p-3 text-left focus-ring-inverted transition hover:border-white/20 hover:bg-white/10"
            >
                <span class="sr-only">Mudar de espaço de trabalho</span>
                <span
                    class="grid size-9 shrink-0 place-items-center rounded-lg bg-accent-400 text-sm font-bold text-brand-950"
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
                    :class="[
                        collapsed
                            ? 'w-64 origin-top-left'
                            : 'w-full origin-top',
                        'absolute left-0 z-30 mt-2 rounded-xl bg-white p-1.5 text-zinc-900 shadow-2xl ring-1 ring-zinc-900/10 focus:outline-none dark:bg-zinc-900 dark:text-white dark:ring-white/10',
                    ]"
                >
                    <p class="px-2.5 pt-1.5 pb-2 eyebrow text-zinc-400">
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

        <nav
            class="flex min-h-0 w-full flex-1 flex-col overflow-x-clip overflow-y-auto"
            aria-label="Navegação principal"
        >
            <ul
                role="list"
                :class="[
                    collapsed ? 'gap-y-4' : 'gap-y-5',
                    'flex flex-1 flex-col',
                ]"
            >
                <li
                    v-for="(section, sectionIndex) in sections"
                    :key="section.label"
                >
                    <!-- Collapsed, the group headings are replaced by a rule so
                         the grouping survives without the labels. -->
                    <div
                        v-if="collapsed && sectionIndex > 0"
                        class="mx-auto mb-3 h-px w-6 bg-white/15"
                        aria-hidden="true"
                    />
                    <p v-if="!collapsed" class="px-2 eyebrow text-brand-100/50">
                        {{ section.label }}
                    </p>
                    <ul
                        role="list"
                        :class="[
                            collapsed ? 'flex flex-col items-center' : 'mt-2',
                            'space-y-1',
                        ]"
                    >
                        <li v-for="item in section.items" :key="item.name">
                            <RailTooltip
                                :label="item.name"
                                :disabled="!collapsed"
                            >
                                <Link
                                    :href="item.href"
                                    prefetch
                                    :aria-label="
                                        collapsed ? item.name : undefined
                                    "
                                    :aria-current="
                                        isCurrent(item) ? 'page' : undefined
                                    "
                                    :class="[
                                        isCurrent(item)
                                            ? 'bg-white text-brand-950 shadow-sm'
                                            : 'text-brand-100/80 hover:bg-white/10 hover:text-white',
                                        collapsed
                                            ? 'size-11 justify-center'
                                            : 'gap-3 px-3 py-2.5',
                                        'group flex items-center rounded-xl text-sm font-semibold focus-ring-inverted transition',
                                    ]"
                                    @click="$emit('navigate')"
                                >
                                    <component
                                        :is="item.icon"
                                        :class="[
                                            isCurrent(item)
                                                ? 'text-brand-700'
                                                : 'text-brand-200/65 group-hover:text-accent-400',
                                            'size-5 shrink-0 transition',
                                        ]"
                                        :stroke-width="1.8"
                                        aria-hidden="true"
                                    />
                                    <!-- Truncates rather than spilling out of
                                         the rail while the width animates. -->
                                    <span v-if="!collapsed" class="truncate">{{
                                        item.name
                                    }}</span>
                                </Link>
                            </RailTooltip>
                        </li>
                    </ul>
                </li>

                <li
                    :class="[collapsed ? 'flex justify-center' : '', 'mt-auto']"
                >
                    <RailTooltip
                        v-if="collapsed"
                        label="Chaves AGT protegidas"
                        detail="Cifradas e fora da base de dados"
                    >
                        <div
                            class="grid size-11 place-items-center rounded-xl bg-emerald-300/[0.08] text-emerald-300 ring-1 ring-emerald-300/20"
                        >
                            <ShieldCheck class="size-5" aria-hidden="true" />
                            <span class="sr-only"
                                >As suas chaves AGT estão protegidas</span
                            >
                        </div>
                    </RailTooltip>
                    <template v-else>
                        <div
                            class="flex items-start gap-2.5 rounded-xl border border-emerald-300/20 bg-emerald-300/[0.08] px-3 py-2.5"
                        >
                            <ShieldCheck
                                class="mt-0.5 size-4 shrink-0 text-emerald-300"
                                aria-hidden="true"
                            />
                            <p class="text-xs/5 text-emerald-100/80">
                                Chaves AGT cifradas, fora da base de dados.
                            </p>
                        </div>
                    </template>
                </li>
            </ul>
        </nav>
    </div>
</template>
