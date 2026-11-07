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
        class="rounded-xl bg-[var(--surface-raised)] px-[1.125rem] pt-4 pb-1 shadow-[var(--ui-card-shadow)]"
        aria-labelledby="collection-queue-heading"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2
                    id="collection-queue-heading"
                    class="text-sm font-semibold text-[var(--ui-ink)]"
                >
                    Seguimentos da equipa
                </h2>
                <p class="mt-0.5 text-xs text-[var(--ui-ink-3)]">
                    {{ due.length }} para hoje ou em atraso ·
                    {{ entries.length }} em aberto. Inclui clientes com saldo já
                    liquidado.
                </p>
            </div>
            <AppButton
                variant="quiet"
                size="xs"
                :aria-pressed="showAll"
                @click="showAll = !showAll"
                >{{
                    showAll
                        ? 'Mostrar só para hoje'
                        : 'Ver todos os seguimentos'
                }}</AppButton
            >
        </div>
        <p
            v-if="visible.length === 0"
            class="py-4 text-[0.8125rem] text-[var(--ui-ink-3)]"
        >
            Sem seguimentos nesta lista. Abra um cliente para registar o próximo
            contacto ou uma promessa de pagamento.
        </p>
        <ul v-else class="mt-3 divide-y divide-[var(--ui-line)]">
            <li
                v-for="entry in visible"
                :key="entry.customer_public_id"
                class="flex flex-wrap items-center justify-between gap-3 py-3"
            >
                <div class="min-w-0 flex-1">
                    <p
                        class="text-[0.8125rem] font-semibold text-[var(--ui-ink)]"
                    >
                        {{ entry.name }}
                    </p>
                    <p class="mt-0.5 text-xs text-[var(--ui-ink-3)]">
                        Próximo contacto:
                        {{ followUpDate(entry.follow_up.next_contact_on) }} ·
                        Pagamento prometido:
                        {{ followUpDate(entry.follow_up.promised_payment_on) }}
                    </p>
                    <p
                        v-if="entry.follow_up.note"
                        class="mt-1 line-clamp-2 text-xs wrap-break-word text-[var(--ui-ink-2)]"
                    >
                        {{ entry.follow_up.note }}
                    </p>
                </div>
                <AppButton
                    size="xs"
                    :href="`${show.url(entry.customer_public_id)}#follow-up-heading`"
                    >Rever seguimento</AppButton
                >
            </li>
        </ul>
    </section>
</template>
