<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    Download,
    FileArchive,
    Landmark,
    LoaderCircle,
    ShieldAlert,
    TriangleAlert,
} from '@lucide/vue';
import { ref } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { security } from '@/routes/settings';
import {
    destroy as destroyAccount,
    exportMethod as exportData,
} from '@/routes/settings/account';

const props = defineProps<{
    deletion: {
        can_delete: boolean;
        blocking_workspaces: string[];
        retained_workspaces: {
            name: string;
            until: string;
            documents: number;
        }[];
        retains_documents: boolean;
        retains_support_record: boolean;
        issued_count: number;
        workspaces_deleted: string[];
        workspaces_left: string[];
    };
    exports: { name: string; size: number; created_at: string }[];
}>();

const exporting = ref(false);

/**
 * Submits a real form rather than an Inertia visit.
 *
 * The response is the archive itself, and Inertia has nowhere to put a file —
 * only a browser navigation hands it to the download manager. It stays a POST
 * because each call writes a new archive, and a GET is something browsers feel
 * free to prefetch.
 */
function requestExport(): void {
    exporting.value = true;

    const token = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = exportData.url();
    form.style.display = 'none';

    const field = document.createElement('input');
    field.name = '_token';
    field.value = token ? decodeURIComponent(token) : '';
    form.append(field);

    document.body.append(form);
    form.submit();
    form.remove();

    // The page stays put while the browser downloads, so the button has to
    // stop spinning on its own.
    window.setTimeout(() => {
        exporting.value = false;
    }, 4000);
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatDate(value: string): string {
    return dateFormatter.format(new Date(value));
}

const dayFormatter = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'long' });

function formatDay(value: string): string {
    return dayFormatter.format(new Date(value));
}

function formatSize(bytes: number): string {
    const units = ['B', 'kB', 'MB', 'GB'];
    let size = bytes;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit += 1;
    }

    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

async function deleteAccount(): Promise<void> {
    const consequences = [
        props.deletion.workspaces_deleted.length > 0
            ? `As empresas ${props.deletion.workspaces_deleted.join(', ')} são eliminadas com todos os seus registos.`
            : null,
        props.deletion.retains_documents
            ? `Os ${props.deletion.issued_count} documentos que emitiu ficam guardados sem o seu nome, porque a lei fiscal exige que se mantenham.`
            : null,
        props.deletion.retains_support_record
            ? 'O registo dos acessos de apoio técnico fica guardado sem o seu nome, porque também identifica quem fez esses acessos.'
            : null,
    ]
        .filter(Boolean)
        .join(' ');

    const confirmed = await confirmAction({
        title: 'Eliminar a sua conta?',
        message: `${consequences} Não há forma de voltar atrás.`.trim(),
        confirmLabel: 'Eliminar a minha conta',
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroyAccount.url());
}
</script>

<template>
    <AppLayout>
        <Head title="A sua conta" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-3xl space-y-6">
                <FlashBanner />

                <header>
                    <p class="eyebrow text-zinc-500 dark:text-zinc-400">
                        Os seus dados
                    </p>
                    <h1
                        class="mt-2.5 text-[2.125rem] leading-[1.08] display text-zinc-950 dark:text-white"
                    >
                        A sua conta
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Leve os seus registos consigo a qualquer momento, ou
                        feche a conta. Para dispositivos e palavra-passe, veja
                        <Link
                            :href="security.url()"
                            class="font-medium text-brand-700 underline-offset-2 hover:underline dark:text-brand-300"
                            >Conta e segurança</Link
                        >.
                    </p>
                </header>

                <section class="overflow-hidden rounded-2xl surface">
                    <div
                        class="flex flex-col gap-5 border-b border-zinc-100 p-6 sm:flex-row sm:items-start sm:justify-between dark:border-white/10"
                    >
                        <div class="flex gap-4">
                            <span
                                class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                            >
                                <FileArchive
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <div>
                                <h2
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    Exportar os dados
                                </h2>
                                <p
                                    class="mt-1 max-w-xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    Clientes, artigos, tabelas de preços,
                                    orçamentos e todos os documentos emitidos,
                                    em ficheiros .csv que abrem numa folha de
                                    cálculo. Sem palavras-passe nem credenciais
                                    da AGT.
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            :disabled="exporting"
                            class="inline-flex h-10 items-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
                            @click="requestExport"
                        >
                            <LoaderCircle
                                v-if="exporting"
                                class="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            <Download
                                v-else
                                class="size-4"
                                aria-hidden="true"
                            />
                            Exportar agora
                        </button>
                    </div>

                    <div v-if="exports.length > 0" class="p-6">
                        <p class="eyebrow text-zinc-500">
                            Exportações recentes
                        </p>
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="file in exports"
                                :key="file.name"
                                class="flex items-center justify-between gap-4 text-sm"
                            >
                                <span
                                    class="truncate numeric text-zinc-600 dark:text-zinc-400"
                                    >{{ file.name }}</span
                                >
                                <span
                                    class="shrink-0 numeric text-xs text-zinc-500 dark:text-zinc-500"
                                    >{{ formatSize(file.size) }} ·
                                    {{ formatDate(file.created_at) }}</span
                                >
                            </li>
                        </ul>
                        <p
                            class="mt-3 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Guardadas no servidor até serem removidas pela
                            manutenção. Se já as descarregou, não precisa de
                            fazer nada.
                        </p>
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border border-rose-200 bg-white dark:border-rose-400/20 dark:bg-zinc-900"
                >
                    <div class="flex gap-4 p-6">
                        <span
                            class="grid size-11 shrink-0 place-items-center rounded-xl bg-rose-100 text-rose-700 dark:bg-rose-400/10 dark:text-rose-300"
                        >
                            <ShieldAlert class="size-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <h2
                                class="font-semibold text-zinc-950 dark:text-white"
                            >
                                Eliminar a conta
                            </h2>
                            <p
                                class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                            >
                                Antes de decidir, exporte os seus dados acima.
                                Depois disto não há forma de os recuperar.
                            </p>

                            <div
                                v-for="held in deletion.retained_workspaces"
                                :key="held.name"
                                class="mt-4 flex items-start gap-3 rounded-xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                            >
                                <Landmark
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <p>
                                    <strong>{{ held.name }}</strong> tem
                                    <span class="numeric">{{
                                        held.documents
                                    }}</span>
                                    documentos fiscais dentro do prazo legal de
                                    conservação, que termina a
                                    <span class="numeric">{{
                                        formatDay(held.until)
                                    }}</span
                                    >. Até lá não podem ser eliminados, nem a
                                    seu pedido — a obrigação de os guardar é do
                                    contribuinte e não pode ser dispensada.
                                    Exporte-os acima se precisar de os levar
                                    consigo.
                                </p>
                            </div>

                            <div
                                v-if="deletion.blocking_workspaces.length > 0"
                                class="mt-4 flex items-start gap-3 rounded-xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                            >
                                <TriangleAlert
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <p>
                                    É o único proprietário de
                                    <strong>{{
                                        deletion.blocking_workspaces.join(', ')
                                    }}</strong>
                                    e há mais pessoas a trabalhar lá. Passe a
                                    propriedade a alguém antes de eliminar a
                                    conta — deixar uma empresa sem dono não é
                                    algo que possamos decidir por si.
                                </p>
                            </div>

                            <ul
                                v-if="deletion.can_delete"
                                class="mt-4 space-y-2 text-sm/6 text-zinc-600 dark:text-zinc-400"
                            >
                                <li
                                    v-if="
                                        deletion.workspaces_deleted.length > 0
                                    "
                                    class="flex items-start gap-2"
                                >
                                    <Building2
                                        class="mt-1 size-3.5 shrink-0 text-zinc-400"
                                        aria-hidden="true"
                                    />
                                    <span
                                        >{{
                                            deletion.workspaces_deleted.join(
                                                ', ',
                                            )
                                        }}
                                        e todos os seus registos são
                                        eliminados.</span
                                    >
                                </li>
                                <li
                                    v-if="deletion.workspaces_left.length > 0"
                                    class="flex items-start gap-2"
                                >
                                    <Building2
                                        class="mt-1 size-3.5 shrink-0 text-zinc-400"
                                        aria-hidden="true"
                                    />
                                    <span
                                        >Deixa de ter acesso a
                                        {{
                                            deletion.workspaces_left.join(', ')
                                        }}, que continuam a existir.</span
                                    >
                                </li>
                                <li
                                    v-if="deletion.retains_documents"
                                    class="flex items-start gap-2"
                                >
                                    <TriangleAlert
                                        class="mt-1 size-3.5 shrink-0 text-amber-500"
                                        aria-hidden="true"
                                    />
                                    <span
                                        >Os
                                        <strong class="numeric">{{
                                            deletion.issued_count
                                        }}</strong>
                                        documentos fiscais que emitiu ficam
                                        guardados sem o seu nome. A lei exige
                                        que se mantenham, e já foram comunicados
                                        à AGT.</span
                                    >
                                </li>
                                <li
                                    v-if="deletion.retains_support_record"
                                    class="flex items-start gap-2"
                                >
                                    <TriangleAlert
                                        class="mt-1 size-3.5 shrink-0 text-amber-500"
                                        aria-hidden="true"
                                    />
                                    <span
                                        >O registo dos acessos de apoio técnico
                                        fica guardado sem o seu nome, porque
                                        também identifica quem fez esses
                                        acessos.</span
                                    >
                                </li>
                            </ul>

                            <button
                                v-if="deletion.can_delete"
                                type="button"
                                class="mt-5 inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-rose-500"
                                @click="deleteAccount"
                            >
                                Eliminar a minha conta
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
