<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { auditApi } from '@/api';
import type { AuditEntry } from '@/api/types';
import { eventLabel, formatDateTime } from '@/utils/format';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppPagination from '@/components/ui/AppPagination.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const entries = ref<AuditEntry[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const filters = reactive({ event: '', from: '', to: '' });
const expanded = ref<number | null>(null);

async function load(page = 1): Promise<void> {
    const result = await auditApi.list({
        page,
        event: filters.event || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
    });
    entries.value = result.data;
    meta.value = result.meta;
}

onMounted(() => load());

const actorColors = { user: 'gray', platform: 'blue', support: 'amber', system: 'gray' } as const;
const actorLabels = { user: 'Utilizador', platform: 'Plataforma', support: 'Suporte', system: 'Sistema' };
</script>

<template>
    <div>
        <PageHeader title="Auditoria" description="Registo imutável das ações relevantes na empresa." />

        <AppCard>
            <form class="mb-4 grid gap-3 sm:grid-cols-4" @submit.prevent="load(1)">
                <AppInput v-model="filters.event" label="Evento" placeholder="ex.: auth, membership" />
                <AppInput v-model="filters.from" label="De" type="date" />
                <AppInput v-model="filters.to" label="Até" type="date" />
                <div class="flex items-end"><AppButton type="submit" variant="secondary" block>Filtrar</AppButton></div>
            </form>

            <EmptyState v-if="entries.length === 0" title="Sem registos" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="entry in entries" :key="entry.id" class="py-3">
                    <button class="flex w-full flex-wrap items-start justify-between gap-2 text-left" @click="expanded = expanded === entry.id ? null : entry.id">
                        <div>
                            <p class="font-medium text-slate-900">{{ eventLabel(entry.event) }}</p>
                            <p class="text-sm text-slate-500">
                                {{ entry.actor?.name ?? 'Sistema' }}
                                <AppBadge :color="actorColors[entry.actor_type]" class="ml-1">{{ actorLabels[entry.actor_type] }}</AppBadge>
                            </p>
                        </div>
                        <span class="text-sm text-slate-500">{{ formatDateTime(entry.created_at) }}</span>
                    </button>
                    <div v-if="expanded === entry.id" class="mt-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                        <p><strong>Evento:</strong> {{ entry.event }} · <strong>IP:</strong> {{ entry.ip_address ?? '—' }} · <strong>Pedido:</strong> {{ entry.request_id ?? '—' }}</p>
                        <pre v-if="entry.properties" class="mt-2 overflow-x-auto whitespace-pre-wrap">{{ JSON.stringify(entry.properties, null, 2) }}</pre>
                    </div>
                </li>
            </ul>
            <AppPagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
        </AppCard>
    </div>
</template>
