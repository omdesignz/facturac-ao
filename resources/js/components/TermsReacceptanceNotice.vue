<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ScrollText } from '@lucide/vue';
import { computed, ref } from 'vue';
import { accept } from '@/routes/legal/terms';

const page = usePage();

const visible = computed(() => page.props.legal.terms_reacceptance_required);
const accepting = ref(false);

/**
 * A notice rather than a blocking dialog: new terms are worth reading, but
 * locking someone out of invoices they are legally obliged to issue is a
 * disproportionate way to ask.
 */
function acceptTerms(): void {
    if (accepting.value) {
        return;
    }

    accepting.value = true;
    router.post(
        accept.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                accepting.value = false;
            },
        },
    );
}
</script>

<template>
    <div
        v-if="visible"
        class="flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-amber-200 bg-amber-50 px-4 py-3 sm:px-6 lg:px-8 dark:border-amber-400/20 dark:bg-amber-400/10"
        role="status"
    >
        <ScrollText
            class="size-5 shrink-0 text-amber-700 dark:text-amber-300"
            aria-hidden="true"
        />

        <p class="text-sm/6 text-amber-900 dark:text-amber-200">
            Actualizámos os Termos e Condições.
            <Link
                href="/termos"
                class="font-semibold underline underline-offset-4"
            >
                Leia a nova versão
            </Link>
        </p>

        <button
            type="button"
            :disabled="accepting"
            class="ms-auto rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-amber-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 disabled:opacity-60"
            @click="acceptTerms"
        >
            Aceito
        </button>
    </div>
</template>
