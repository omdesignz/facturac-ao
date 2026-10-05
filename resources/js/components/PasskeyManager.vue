<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { Fingerprint, LoaderCircle, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { confirmAction } from '@/lib/confirm';
import { destroy as destroyPasskey } from '@/routes/passkey';

export interface Passkey {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
}

const props = defineProps<{
    passkeys: Passkey[];
}>();

const name = ref('');
const nameError = ref<string | null>(null);
const deletingId = ref<number | null>(null);

const {
    register,
    isLoading,
    error: registerError,
    isSupported,
} = usePasskeyRegister({
    onSuccess: () => {
        name.value = '';
        // Fortify returns JSON here, so ask Inertia for the refreshed list.
        router.reload({ only: ['passkeys'] });
    },
});

const suggestedName = computed(() => {
    if (typeof navigator === 'undefined') {
        return 'Este dispositivo';
    }

    const ua = navigator.userAgent;

    if (/iPhone/i.test(ua)) {
        return 'iPhone';
    }

    if (/iPad/i.test(ua)) {
        return 'iPad';
    }

    if (/Android/i.test(ua)) {
        return 'Telemóvel Android';
    }

    if (/Macintosh/i.test(ua)) {
        return 'Mac';
    }

    if (/Windows/i.test(ua)) {
        return 'PC Windows';
    }

    return 'Este dispositivo';
});

function formatDate(value: string | null): string {
    if (value === null) {
        return 'Nunca';
    }

    return new Intl.DateTimeFormat('pt-PT', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

async function submit(): Promise<void> {
    const chosen = name.value.trim() || suggestedName.value;

    if (chosen.length > 60) {
        nameError.value = 'Use um nome com 60 caracteres ou menos.';

        return;
    }

    nameError.value = null;
    await register(chosen);
}

async function remove(passkey: Passkey): Promise<void> {
    const confirmed = await confirmAction({
        title: `Remover a passkey “${passkey.name}”?`,
        message:
            'Deixa de poder entrar com este dispositivo. Pode registá-lo outra vez a seguir.',
        confirmLabel: 'Remover passkey',
    });

    if (!confirmed) {
        return;
    }

    deletingId.value = passkey.id;

    router.delete(destroyPasskey.url(passkey.id), {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
        },
    });
}
</script>

<template>
    <section class="overflow-hidden rounded-2xl surface">
        <div
            class="flex flex-col gap-5 border-b border-zinc-100 p-6 sm:flex-row sm:items-start sm:justify-between dark:border-white/10"
        >
            <div class="flex gap-4">
                <span
                    class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300"
                >
                    <Fingerprint class="size-5" aria-hidden="true" />
                </span>
                <div>
                    <h2 class="font-semibold text-zinc-950 dark:text-white">
                        Passkeys
                    </h2>
                    <p
                        class="mt-1 max-w-xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                    >
                        Entre com a impressão digital, o rosto ou o PIN do
                        aparelho, sem escrever a palavra-passe. A chave nunca
                        sai do seu dispositivo, por isso não há nada que possa
                        ser roubado num ataque.
                    </p>
                </div>
            </div>
            <StatusBadge
                :label="
                    props.passkeys.length > 0
                        ? `${props.passkeys.length} ${props.passkeys.length === 1 ? 'registada' : 'registadas'}`
                        : 'Nenhuma'
                "
                :tone="props.passkeys.length > 0 ? 'success' : 'neutral'"
            />
        </div>

        <div v-if="!isSupported" class="p-6">
            <p
                class="rounded-xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
            >
                Este navegador não suporta passkeys. Experimente o Chrome, o
                Safari ou o Edge actualizados, num aparelho com bloqueio de ecrã
                activo.
            </p>
        </div>

        <template v-else>
            <ul
                v-if="props.passkeys.length > 0"
                role="list"
                class="divide-y divide-zinc-100 px-6 dark:divide-white/10"
            >
                <li
                    v-for="passkey in props.passkeys"
                    :key="passkey.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-4"
                >
                    <div class="min-w-0">
                        <p
                            class="truncate text-sm font-semibold text-zinc-900 dark:text-white"
                        >
                            {{ passkey.name }}
                        </p>
                        <p
                            class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Criada a {{ formatDate(passkey.created_at) }} ·
                            Última utilização:
                            {{ formatDate(passkey.last_used_at) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-rose-700 focus-ring transition hover:bg-rose-50 disabled:opacity-50 dark:text-rose-400 dark:hover:bg-rose-400/10"
                        :disabled="deletingId === passkey.id"
                        @click="remove(passkey)"
                    >
                        <LoaderCircle
                            v-if="deletingId === passkey.id"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        <Trash2 v-else class="size-4" aria-hidden="true" />
                        Remover
                    </button>
                </li>
            </ul>

            <div class="p-6">
                <label
                    for="passkey-name"
                    class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                >
                    Nome do dispositivo
                </label>
                <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                    <input
                        id="passkey-name"
                        v-model="name"
                        type="text"
                        maxlength="60"
                        autocomplete="off"
                        :placeholder="suggestedName"
                        class="block w-full rounded-xl bg-white px-3 py-2.5 text-sm text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 sm:max-w-xs dark:bg-white/5 dark:text-white dark:outline-white/10 dark:focus:outline-brand-400"
                        @keydown.enter.prevent="submit"
                    />
                    <button
                        type="button"
                        class="inline-flex h-10 items-center gap-2 rounded-full bg-accent-400 px-[1.125rem] text-sm font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] focus-ring transition hover:bg-accent-300 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="isLoading"
                        @click="submit"
                    >
                        <LoaderCircle
                            v-if="isLoading"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        <Plus v-else class="size-4" aria-hidden="true" />
                        Adicionar passkey
                    </button>
                </div>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    Deixe em branco para usar “{{ suggestedName }}”.
                </p>
                <p
                    v-if="nameError ?? registerError"
                    class="mt-2 text-sm text-rose-600 dark:text-rose-400"
                >
                    {{ nameError ?? registerError }}
                </p>
            </div>
        </template>
    </section>
</template>
