<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CreditCard, Plug, ShieldCheck, UserCog } from '@lucide/vue';
import { computed } from 'vue';
import { show as billing } from '@/routes/billing';
import { index as integrations } from '@/routes/integrations/settings';
import { account, security } from '@/routes/settings';
import type { CurrentWorkspace } from '@/types';

const page = usePage();
const links = computed(() =>
    [
        {
            label: 'A sua conta',
            href: account.url(),
            component: 'Settings/Account',
            icon: UserCog,
        },
        {
            label: 'Segurança',
            href: security.url(),
            component: 'Settings/Security',
            icon: ShieldCheck,
        },
        {
            label: 'Integrações e API',
            href: integrations.url(),
            component: 'Settings/Integrations',
            icon: Plug,
        },
        {
            label: 'Plano e cobrança',
            href: billing.url(),
            component: 'Billing/Show',
            icon: CreditCard,
        },
    ].filter(
        (link) =>
            link.component !== 'Settings/Integrations' ||
            ['owner', 'administrator'].includes(
                (page.props.currentWorkspace as CurrentWorkspace | null)
                    ?.role ?? '',
            ),
    ),
);
</script>

<template>
    <nav
        aria-label="Definições da conta"
        class="flex flex-wrap gap-1 border-b border-[var(--ui-line)] pb-4"
    >
        <Link
            v-for="link in links"
            :key="link.href"
            :href="link.href"
            :aria-current="
                page.component === link.component ? 'page' : undefined
            "
            :class="[
                'inline-flex min-h-9 items-center gap-2 rounded-lg px-3 text-sm font-medium focus-ring pointer-coarse:min-h-11',
                page.component === link.component
                    ? 'bg-[var(--surface-raised)] text-[var(--ui-ink)] shadow-[var(--ui-control-shadow)]'
                    : 'text-[var(--ui-ink-2)] hover:bg-[var(--ui-wash)]',
            ]"
        >
            <component :is="link.icon" class="size-4" aria-hidden="true" />
            {{ link.label }}
        </Link>
    </nav>
</template>
