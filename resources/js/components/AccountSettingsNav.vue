<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CreditCard, ShieldCheck, UserCog } from '@lucide/vue';
import { show as billing } from '@/routes/billing';
import { account, security } from '@/routes/settings';

const page = usePage();
const links = [
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
        label: 'Plano e cobrança',
        href: billing.url(),
        component: 'Billing/Show',
        icon: CreditCard,
    },
];
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
