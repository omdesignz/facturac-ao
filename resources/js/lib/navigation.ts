import { usePage } from '@inertiajs/vue3';
import {
    Boxes,
    Building2,
    CalendarSync,
    ChartLine,
    ClipboardList,
    CreditCard,
    FileClock,
    FileCode2,
    FileSignature,
    Files,
    FileUp,
    HandCoins,
    Landmark,
    LayoutDashboard,
    LifeBuoy,
    Package,
    ScanBarcode,
    Settings2,
    ShieldCheck,
    SlidersHorizontal,
    Sparkles,
    Store,
    Tags,
    Truck,
    UserCog,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component, ComputedRef } from 'vue';
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
import { show as posShow } from '@/routes/pos';
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

export interface NavigationItem {
    name: string;
    href: string;
    /** The URL prefix that belongs to this page. The longest match wins. */
    match: string;
    icon: Component;
    /** False for a page that should only be requested when someone opens it. */
    prefetch?: boolean;
}

export interface NavigationGroup {
    key: string;
    name: string;
    icon: Component;
    /** Where the group's icon goes when clicked: its first, most used page. */
    href: string;
    items: NavigationItem[];
    /** False for a place that should only be requested when someone opens it. */
    prefetch?: boolean;
}

/**
 * The application's map, in seven places plus an internal one for staff.
 *
 * One definition for the desktop rail and the mobile menu, so the two can
 * never disagree about where a page lives. Facturas, Recibos and Notas de
 * correcção are not separate entries: they are the family tabs inside
 * Documentos, which is where the register already filters them.
 */
const baseGroups: NavigationGroup[] = [
    {
        key: 'dashboard',
        name: 'Painel',
        icon: LayoutDashboard,
        href: dashboard.url(),
        items: [
            {
                name: 'Painel',
                href: dashboard.url(),
                match: '/dashboard',
                icon: LayoutDashboard,
            },
        ],
    },
    {
        key: 'documents',
        name: 'Documentos',
        icon: Files,
        href: documentsIndex.url(),
        items: [
            {
                name: 'Ponto de venda',
                href: posShow.url(),
                match: '/ponto-de-venda',
                icon: ScanBarcode,
            },
            {
                name: 'Documentos fiscais',
                href: documentsIndex.url(),
                match: '/documentos',
                icon: Files,
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
        ],
    },
    {
        key: 'customers',
        name: 'Clientes',
        icon: Users,
        href: customersIndex.url(),
        items: [
            {
                name: 'Clientes',
                href: customersIndex.url(),
                match: '/customers',
                icon: Users,
            },
            {
                name: 'Dívidas',
                href: debtsIndex.url(),
                match: '/dividas',
                icon: HandCoins,
            },
        ],
    },
    {
        key: 'catalogue',
        name: 'Artigos',
        icon: Package,
        href: billingCatalogue.url(),
        items: [
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
                name: 'Inventário',
                href: stockIndex.url(),
                match: '/inventario',
                icon: Boxes,
            },
        ],
    },
    {
        key: 'agt',
        name: 'AGT',
        icon: Landmark,
        href: agtSubmissions.url(),
        items: [
            {
                name: 'Monitor AGT',
                href: agtSubmissions.url(),
                match: '/agt/submissions',
                icon: FileClock,
            },
            {
                name: 'Ligação AGT',
                href: agtConnection.url(),
                match: '/agt/connection',
                icon: ShieldCheck,
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
        key: 'analytics',
        name: 'Análises',
        icon: ChartLine,
        href: analyticsIndex.url(),
        items: [
            {
                name: 'Análises',
                href: analyticsIndex.url(),
                match: '/analises',
                icon: ChartLine,
            },
        ],
    },
    {
        key: 'settings',
        name: 'Configuração',
        icon: Settings2,
        href: onboarding.url(),
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
                name: 'Importar dados',
                href: importIndex.url(),
                match: '/imports',
                icon: FileUp,
            },
            {
                name: 'Conta e segurança',
                href: security.url(),
                match: '/settings/security',
                icon: ShieldCheck,
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

/** Only the platform's own staff see the support console. */
const internalGroup: NavigationGroup = {
    key: 'internal',
    name: 'Interno',
    icon: LifeBuoy,
    href: supportIndex.url(),
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
};

/**
 * The read-only assistant, offered only where the server says it is on.
 *
 * Its address carries the company, so it arrives as a shared prop rather than
 * a route helper; it is never prefetched, because merely hovering the entry
 * should not ask the assistant for anything.
 */
function assistantGroup(url: string): NavigationGroup {
    return {
        key: 'assistant',
        name: 'Assistente',
        icon: Sparkles,
        href: url,
        prefetch: false,
        items: [
            {
                name: 'Assistente',
                href: url,
                match: '/assistant',
                icon: Sparkles,
                prefetch: false,
            },
        ],
    };
}

/** The pages the invoice composer lives under, for highlighting Documentos. */
const DOCUMENT_COMPOSER_PREFIX = '/documents/invoices';

function pathOf(url: string): string {
    return url.split('?')[0] ?? url;
}

/**
 * The one item that owns a URL: the longest matching prefix, so
 * /support/reclamacoes is Reclamações, not also Apoio ao cliente.
 */
export function currentItem(
    groups: NavigationGroup[],
    url: string,
): NavigationItem | null {
    const path = pathOf(url).startsWith(DOCUMENT_COMPOSER_PREFIX)
        ? '/documentos'
        : pathOf(url);
    let best: NavigationItem | null = null;

    for (const group of groups) {
        for (const item of group.items) {
            const matches =
                path === item.match || path.startsWith(`${item.match}/`);

            if (
                matches &&
                (best === null || item.match.length > best.match.length)
            ) {
                best = item;
            }
        }
    }

    return best;
}

export function currentGroup(
    groups: NavigationGroup[],
    url: string,
): NavigationGroup | null {
    const item = currentItem(groups, url);

    return item === null
        ? null
        : (groups.find((group) => group.items.includes(item)) ?? null);
}

export function useNavigation(): {
    groups: ComputedRef<NavigationGroup[]>;
    activeItem: ComputedRef<NavigationItem | null>;
    activeGroup: ComputedRef<NavigationGroup | null>;
} {
    const page = usePage();

    const groups = computed(() => {
        const assistant = page.props.assistant;
        const analyticsAt = baseGroups.findIndex(
            (group) => group.key === 'analytics',
        );
        const visible =
            assistant === null || assistant === undefined
                ? baseGroups
                : [
                      ...baseGroups.slice(0, analyticsAt + 1),
                      assistantGroup(assistant.url),
                      ...baseGroups.slice(analyticsAt + 1),
                  ];

        return page.props.auth.user?.is_support_staff === true
            ? [...visible, internalGroup]
            : visible;
    });

    return {
        groups,
        activeItem: computed(() => currentItem(groups.value, page.url)),
        activeGroup: computed(() => currentGroup(groups.value, page.url)),
    };
}
