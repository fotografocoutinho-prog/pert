<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { platformApi } from '@/api';
import type { PlatformTenant } from '@/api/types';
import { formatDate, subscriptionStatusLabel } from '@/utils/format';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppPagination from '@/components/ui/AppPagination.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const tenants = ref<PlatformTenant[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const search = ref('');
const status = ref<string | null>('');

const statusOptions = [
    { value: '', label: 'Todos os estados' },
    { value: 'active', label: 'Ativas' },
    { value: 'read_only', label: 'Só leitura' },
    { value: 'suspended', label: 'Suspensas' },
];

async function load(page = 1): Promise<void> {
    const result = await platformApi.tenants({ page, search: search.value || undefined, status: status.value || undefined });
    tenants.value = result.data;
    meta.value = result.meta;
}

onMounted(() => load());
let timer: number | undefined;
watch([search, status], () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => load(1), 300);
});

const colors = { active: 'green', read_only: 'amber', suspended: 'red' } as const;
</script>

<template>
    <div>
        <PageHeader title="Empresas" description="Clientes registados na plataforma." />
        <AppCard>
            <div class="mb-4 grid gap-3 sm:grid-cols-3">
                <AppInput v-model="search" placeholder="Nome ou NIF" class="sm:col-span-2" />
                <AppSelect v-model="status" :options="statusOptions" />
            </div>
            <EmptyState v-if="tenants.length === 0" title="Sem empresas" />
            <div v-else class="-mx-5 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                        <tr><th class="px-5 py-3">Empresa</th><th class="px-5 py-3">Plano</th><th class="px-5 py-3">Estado</th><th class="hidden px-5 py-3 md:table-cell">Utilizadores</th><th class="hidden px-5 py-3 md:table-cell">Registo</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="t in tenants" :key="t.id" class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <RouterLink :to="`/plataforma/empresas/${t.id}`" class="font-medium text-brand-800 hover:underline">{{ t.name }}</RouterLink>
                                <p class="text-slate-500">{{ t.nif ?? 'Sem NIF' }}</p>
                            </td>
                            <td class="px-5 py-3">{{ t.plan ?? '—' }}<p class="text-xs text-slate-500">{{ subscriptionStatusLabel(t.subscription_status) }}</p></td>
                            <td class="px-5 py-3"><AppBadge :color="colors[t.status]">{{ t.status_label }}</AppBadge></td>
                            <td class="hidden px-5 py-3 md:table-cell">{{ t.members_count }}</td>
                            <td class="hidden px-5 py-3 md:table-cell">{{ formatDate(t.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <AppPagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
        </AppCard>
    </div>
</template>
