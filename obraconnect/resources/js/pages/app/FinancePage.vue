<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { costsApi, formatPct, type FinanceRow } from '@/api/economics';
import { formatMoney } from '@/utils/format';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const rows = ref<FinanceRow[]>([]);
const totals = ref<Record<string, number>>({});
const loading = ref(true);
onMounted(async () => {
    const data = await costsApi.finance();
    rows.value = data.projects;
    totals.value = data.totals;
    loading.value = false;
});
const money = (c: number | null | undefined) => (c === null || c === undefined ? '—' : formatMoney(c));
const alertColor = (a: string | null) => (a === 'over' ? 'red' : a === 'warning' ? 'amber' : 'green');
const alertLabel = (a: string | null) => (a === 'over' ? 'Acima do teto' : a === 'warning' ? 'Perto do teto' : a === 'ok' ? 'Dentro do teto' : 'Sem teto');
</script>

<template>
    <div>
        <PageHeader title="Painel financeiro" description="Por obra: valor do contrato (orçamento aprovado), autado ao dono de obra, custo realizado e comprometido, previsão final face ao teto e margem prevista." />
        <div v-if="loading" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <template v-else>
            <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <AppCard><p class="text-xs text-slate-500">Contratos</p><p class="text-xl font-bold">{{ money(totals.contract_cents) }}</p></AppCard>
                <AppCard><p class="text-xs text-slate-500">Autado</p><p class="text-xl font-bold">{{ money(totals.certified_cents) }}</p></AppCard>
                <AppCard><p class="text-xs text-slate-500">Previsão de custo</p><p class="text-xl font-bold">{{ money(totals.forecast_cents) }}</p></AppCard>
                <AppCard>
                    <p class="text-xs text-slate-500">Margem prevista</p>
                    <p class="text-xl font-bold" :class="(totals.margin_forecast_cents ?? 0) < 0 ? 'text-red-700' : 'text-green-700'">{{ money(totals.margin_forecast_cents) }}</p>
                </AppCard>
            </div>
            <AppCard>
                <EmptyState v-if="!rows.length" title="Sem obras" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-slate-500">
                            <tr>
                                <th class="py-2 pr-3">Obra</th>
                                <th class="py-2 pr-3 text-right">Contrato</th>
                                <th class="py-2 pr-3 text-right">Autado</th>
                                <th class="py-2 pr-3 text-right">Realizado</th>
                                <th class="py-2 pr-3 text-right">Comprometido</th>
                                <th class="py-2 pr-3 text-right">Previsão</th>
                                <th class="py-2 pr-3 text-right">Margem</th>
                                <th class="py-2">Teto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in rows" :key="r.id">
                                <td class="py-2 pr-3">
                                    <RouterLink :to="`/app/obras/${r.id}?separador=custos`" class="font-medium text-brand-700 hover:underline">{{ r.code }}</RouterLink>
                                    <span class="block text-xs text-slate-500">{{ r.name }}</span>
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ money(r.contract_cents) }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">
                                    {{ money(r.certified_cents) }}<span v-if="r.certified_pct !== null" class="block text-xs text-slate-500">{{ formatPct(r.certified_pct) }}</span>
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ money(r.actual_cents) }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ money(r.committed_cents) }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ money(r.forecast_cents) }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap" :class="(r.margin_forecast_cents ?? 0) < 0 ? 'text-red-700' : ''">{{ money(r.margin_forecast_cents) }}</td>
                                <td class="py-2">
                                    <AppBadge :color="alertColor(r.alert)">{{ alertLabel(r.alert) }}</AppBadge>
                                    <span v-if="r.ceiling_used_pct !== null" class="block text-xs text-slate-500">{{ formatPct(r.ceiling_used_pct) }} de {{ money(r.ceiling_cents) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </template>
    </div>
</template>
