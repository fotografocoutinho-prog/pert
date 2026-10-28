<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { platformApi } from '@/api';
import type { AuditEntry } from '@/api/types';
import { eventLabel, formatDateTime } from '@/utils/format';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppPagination from '@/components/ui/AppPagination.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const entries = ref<AuditEntry[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const filters = reactive({ event: '', tenant_id: '' });

async function load(page = 1): Promise<void> {
    const result = await platformApi.securityLogs({ page, event: filters.event || undefined, tenant_id: filters.tenant_id || undefined });
    entries.value = result.data;
    meta.value = result.meta;
}
onMounted(() => load());
</script>

<template>
    <div>
        <PageHeader title="Logs de segurança" description="Autenticação, ações da plataforma e acessos de suporte." />
        <AppCard>
            <form class="mb-4 grid gap-3 sm:grid-cols-3" @submit.prevent="load(1)">
                <AppInput v-model="filters.event" placeholder="Evento (ex.: auth.failed)" />
                <AppInput v-model="filters.tenant_id" placeholder="ID da empresa" inputmode="numeric" />
                <AppButton type="submit" variant="secondary">Filtrar</AppButton>
            </form>
            <div class="-mx-5 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                        <tr><th class="px-5 py-3">Data</th><th class="px-5 py-3">Evento</th><th class="px-5 py-3">Ator</th><th class="px-5 py-3">Empresa</th><th class="px-5 py-3">IP</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="e in entries" :key="e.id">
                            <td class="px-5 py-2 whitespace-nowrap text-slate-500">{{ formatDateTime(e.created_at) }}</td>
                            <td class="px-5 py-2">{{ eventLabel(e.event) }}<p class="text-xs text-slate-400">{{ e.event }}</p></td>
                            <td class="px-5 py-2">{{ e.actor?.email ?? (e.properties?.email as string | undefined) ?? '—' }}</td>
                            <td class="px-5 py-2">{{ e.tenant_id ?? '—' }}</td>
                            <td class="px-5 py-2 text-slate-500">{{ e.ip_address ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <AppPagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
        </AppCard>
    </div>
</template>
