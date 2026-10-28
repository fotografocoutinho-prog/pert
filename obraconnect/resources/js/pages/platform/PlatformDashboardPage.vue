<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { platformApi } from '@/api';
import type { PlatformDashboard } from '@/api/types';
import { subscriptionStatusLabel } from '@/utils/format';
import AppCard from '@/components/ui/AppCard.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const data = ref<PlatformDashboard | null>(null);
onMounted(async () => (data.value = await platformApi.dashboard()));

const statusLabels: Record<string, string> = { active: 'Ativas', read_only: 'Só leitura', suspended: 'Suspensas' };
const signupTotal = computed(() => Object.values(data.value?.signups_30d ?? {}).reduce((a, b) => a + b, 0));
</script>

<template>
    <div v-if="data">
        <PageHeader title="Painel da plataforma" description="Indicadores agregados. Não inclui dados internos das empresas." />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard title="Empresas"><p class="text-3xl font-bold">{{ data.tenants.total }}</p></AppCard>
            <AppCard title="Utilizadores"><p class="text-3xl font-bold">{{ data.users.total }}</p><p class="text-sm text-slate-500">{{ data.users.active_30d }} ativos em 30 dias</p></AppCard>
            <AppCard title="Registos (30 dias)"><p class="text-3xl font-bold">{{ signupTotal }}</p></AppCard>
            <AppCard title="Trials a terminar (7 dias)"><p class="text-3xl font-bold">{{ data.subscriptions.trials_ending_7d }}</p></AppCard>
        </div>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <AppCard title="Empresas por estado">
                <ul class="space-y-2 text-sm">
                    <li v-for="(total, status) in data.tenants.by_status" :key="status" class="flex justify-between">
                        <span>{{ statusLabels[status] ?? status }}</span><strong>{{ total }}</strong>
                    </li>
                </ul>
            </AppCard>
            <AppCard title="Subscrições atuais por plano">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-500 uppercase"><tr><th class="py-1">Plano</th><th>Estado</th><th class="text-right">Total</th></tr></thead>
                    <tbody>
                        <tr v-for="row in data.subscriptions.by_plan" :key="`${row.name}-${row.status}`" class="border-t border-slate-100">
                            <td class="py-1.5">{{ row.name }}</td><td>{{ subscriptionStatusLabel(row.status) }}</td><td class="text-right font-semibold">{{ row.total }}</td>
                        </tr>
                    </tbody>
                </table>
            </AppCard>
        </div>
    </div>
</template>
