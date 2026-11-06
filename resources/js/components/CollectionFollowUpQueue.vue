<script setup lang="ts">
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import { followUpDate, followUpsForToday } from '@/lib/collection-follow-ups';
import type { CollectionFollowUpEntry } from '@/lib/collection-follow-ups';
import { show } from '@/routes/customers';

const props = defineProps<{ entries: CollectionFollowUpEntry[] }>();
const showAll = ref(false);
const due = computed(() => followUpsForToday(props.entries));
const visible = computed(() => (showAll.value ? props.entries : due.value));
</script>

<template>
    <section
        class="rounded-2xl surface p-5 sm:p-6"
        aria-labelledby="collection-queue-heading"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="collection-queue-heading" class="text-lg font-semibold">
                    Seguimentos da equipa
                </h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ due.length }} para hoje ou em atraso ·
                    {{ entries.length }} em aberto. Inclui clientes com saldo já
                    liquidado.
                </p>
            </div>
            <AppButton
                variant="quiet"
                :aria-pressed="showAll"
                @click="showAll = !showAll"
                >{{
                    showAll
                        ? 'Mostrar só para hoje'
                        : 'Ver todos os seguimentos'
                }}</AppButton
            >
        </div>
        <p v-if="visible.length === 0" class="mt-5 text-sm text-zinc-500">
            Sem seguimentos nesta lista. Abra um cliente para registar o próximo
            contacto ou uma promessa de pagamento.
        </p>
        <ul v-else class="mt-4 divide-y divide-zinc-100 dark:divide-white/10">
            <li
                v-for="entry in visible"
                :key="entry.customer_public_id"
                class="flex flex-wrap items-start justify-between gap-3 py-4"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ entry.name }}</p>
                    <p class="mt-1 text-sm text-zinc-500">
                        Próximo contacto:
                        {{ followUpDate(entry.follow_up.next_contact_on) }} ·
                        Pagamento prometido:
                        {{ followUpDate(entry.follow_up.promised_payment_on) }}
                    </p>
                    <p
                        v-if="entry.follow_up.note"
                        class="mt-2 line-clamp-2 text-sm wrap-break-word"
                    >
                        {{ entry.follow_up.note }}
                    </p>
                </div>
                <AppButton
                    :href="`${show.url(entry.customer_public_id)}#follow-up-heading`"
                    >Rever seguimento</AppButton
                >
            </li>
        </ul>
    </section>
</template>
