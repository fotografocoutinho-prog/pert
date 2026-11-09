<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { costsApi, formatPct, type FinanceOutlook, type FinanceRow } from '@/api/economics';
import { formatDate, formatMoney } from '@/utils/format';
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
const confidenceLabel = (c: string | null) => (c === 'high' ? 'confiança alta' : c === 'medium' ? 'confiança média' : 'confiança baixa');
const days = (n: number) => `${Math.abs(n)} ${Math.abs(n) === 1 ? 'dia' : 'dias'}`;
// Diferença para a fotografia da semana anterior (histórico das previsões).
function eacChange(o: FinanceOutlook): { text: string; worse: boolean } | null {
    if (!o.previous || o.previous.eac_cents === null || o.eac_cents === null) return null;
    const d = o.eac_cents - o.previous.eac_cents;
    if (Math.abs(d) < 100) return null;
    return { text: `${d > 0 ? '▲ +' : '▼ −'}${formatMoney(Math.abs(d))} desde ${formatDate(o.previous.taken_on)}`, worse: d > 0 };
}
function finishChange(o: FinanceOutlook): { text: string; worse: boolean } | null {
    if (!o.previous?.forecast_finish_on || !o.forecast_finish_on) return null;
    const d = Math.round((Date.parse(o.forecast_finish_on) - Date.parse(o.previous.forecast_finish_on)) / 86400000);
    if (d === 0) return null;
    return { text: `${d > 0 ? '▲ +' : '▼ −'}${days(d)} desde ${formatDate(o.previous.taken_on)}`, worse: d > 0 };
}
function finish(o: FinanceOutlook): { color: 'red' | 'amber' | 'green' | 'gray'; label: string } {
    switch (o.schedule_status) {
        case 'late':
            return { color: 'red', label: o.delay_contract_days ? `${days(o.delay_contract_days)} depois do prazo` : 'Depois do prazo' };
        case 'behind':
            return { color: 'amber', label: 'Atrás do plano' };
        case 'finished':
            return { color: 'green', label: 'Concluída' };
        default:
            return { color: 'green', label: 'No prazo' };
    }
}
</script>

<template>
    <div>
        <PageHeader
            title="Painel financeiro"
            description="Por obra: valor do contrato (orçamento aprovado), autado ao dono de obra, faturado (pré-faturas com a fatura oficial registada, sem IVA), realizado e comprometido, previsão do custo no fim face ao teto, margem prevista e fim previsto. As razões de cada previsão estão no separador Previsões da obra."
        />
        <div v-if="loading" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <template v-else>
            <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <AppCard><p class="text-xs text-slate-500">Contratos</p><p class="text-xl font-bold">{{ money(totals.contract_cents) }}</p></AppCard>
                <AppCard><p class="text-xs text-slate-500">Autado</p><p class="text-xl font-bold">{{ money(totals.certified_cents) }}</p></AppCard>
                <AppCard>
                    <p class="text-xs text-slate-500">Faturado (faturas oficiais)</p>
                    <p class="text-xl font-bold">{{ money(totals.invoiced_cents) }}</p>
                </AppCard>
                <AppCard>
                    <p class="text-xs text-slate-500">Custo previsto no fim</p>
                    <p class="text-xl font-bold">{{ money(totals.eac_cents) }}</p>
                    <p class="text-xs text-slate-500">já realizado + comprometido: {{ money(totals.forecast_cents) }}</p>
                </AppCard>
                <AppCard>
                    <p class="text-xs text-slate-500">Margem prevista</p>
                    <p class="text-xl font-bold" :class="(totals.eac_margin_cents ?? 0) < 0 ? 'text-red-700' : 'text-green-700'">{{ money(totals.eac_margin_cents) }}</p>
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
                                <th class="py-2 pr-3 text-right">Faturado</th>
                                <th class="py-2 pr-3 text-right">Realizado + comprometido</th>
                                <th class="py-2 pr-3 text-right">Custo previsto no fim</th>
                                <th class="py-2 pr-3 text-right">Margem prevista</th>
                                <th class="py-2 pr-3">Teto</th>
                                <th class="py-2">Fim previsto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in rows" :key="r.id">
                                <td class="py-2 pr-3">
                                    <RouterLink :to="`/app/obras/${r.id}?separador=previsoes`" class="font-medium text-brand-700 hover:underline">{{ r.code }}</RouterLink>
                                    <span class="block text-xs text-slate-500">{{ r.name }}</span>
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ money(r.contract_cents) }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">
                                    {{ money(r.certified_cents) }}<span v-if="r.certified_pct !== null" class="block text-xs text-slate-500">{{ formatPct(r.certified_pct) }}</span>
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ money(r.invoiced_cents) }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">
                                    {{ money(r.forecast_cents) }}
                                    <span class="block text-xs text-slate-500">{{ money(r.actual_cents) }} + {{ money(r.committed_cents) }}</span>
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">
                                    <template v-if="r.outlook.cost_available">
                                        <span class="font-semibold">{{ money(r.outlook.eac_cents) }}</span>
                                        <span class="block text-xs text-slate-500">{{ confidenceLabel(r.outlook.confidence) }}</span>
                                        <span v-if="eacChange(r.outlook)" class="block text-xs" :class="eacChange(r.outlook)!.worse ? 'text-red-700' : 'text-green-700'">{{ eacChange(r.outlook)!.text }}</span>
                                    </template>
                                    <span v-else class="text-xs text-slate-500">sem orçamento aprovado</span>
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap" :class="(r.outlook.margin_cents ?? 0) < 0 ? 'text-red-700' : ''">
                                    {{ money(r.outlook.margin_cents) }}<span v-if="r.outlook.margin_pct !== null" class="block text-xs text-slate-500">{{ formatPct(r.outlook.margin_pct) }}</span>
                                </td>
                                <td class="py-2 pr-3">
                                    <AppBadge :color="alertColor(r.outlook.alert)">{{ alertLabel(r.outlook.alert) }}</AppBadge>
                                    <span v-if="r.outlook.ceiling_used_pct !== null" class="block text-xs text-slate-500">{{ formatPct(r.outlook.ceiling_used_pct) }} de {{ money(r.ceiling_cents) }}</span>
                                </td>
                                <td class="py-2 whitespace-nowrap">
                                    <template v-if="r.outlook.schedule_available">
                                        {{ formatDate(r.outlook.forecast_finish_on) }}
                                        <span class="block"><AppBadge :color="finish(r.outlook).color">{{ finish(r.outlook).label }}</AppBadge></span>
                                        <span v-if="finishChange(r.outlook)" class="block text-xs" :class="finishChange(r.outlook)!.worse ? 'text-red-700' : 'text-green-700'">{{ finishChange(r.outlook)!.text }}</span>
                                    </template>
                                    <span v-else class="text-xs text-slate-500">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </template>
    </div>
</template>
