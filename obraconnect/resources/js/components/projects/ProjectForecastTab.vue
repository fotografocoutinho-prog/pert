<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { Project } from '@/api/types';
import { ApiError } from '@/api/http';
import { alertsApi, forecastApi, formatPct, type ForecastLevel, type ForecastReason, type ProjectAlert, type ProjectForecast } from '@/api/economics';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import TrendChart from '@/components/ui/TrendChart.vue';
import { dateTicks, dayLabel, dayNumber, type TrendPoint, type TrendReference } from '@/components/ui/trend';

const props = defineProps<{ project: Project }>();
const router = useRouter();
const forecast = ref<ProjectForecast | null>(null);
const alerts = ref<ProjectAlert[]>([]);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        forecast.value = await forecastApi.get(props.project.id);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível calcular as previsões.';
    }
    alerts.value = await alertsApi.list(props.project.id).catch(() => []);
});

const cost = computed(() => forecast.value?.cost ?? null);
const schedule = computed(() => forecast.value?.schedule ?? null);
const money = (c: number | null | undefined) => (c === null || c === undefined ? '—' : formatMoney(c));
const signedMoney = (c: number) => (c > 0 ? '+' : c < 0 ? '−' : '') + formatMoney(Math.abs(c));
const signedPct = (v: number) => (v > 0 ? '+' : v < 0 ? '−' : '') + formatPct(Math.abs(v));
const days = (n: number, working = false) => `${Math.abs(n)} ${Math.abs(n) === 1 ? 'dia' : 'dias'}${working ? (Math.abs(n) === 1 ? ' útil' : ' úteis') : ''}`;

const levelStyle: Record<ForecastLevel, { dot: string; label: string }> = {
    danger: { dot: 'bg-red-600', label: 'Grave' },
    warning: { dot: 'bg-amber-500', label: 'Atenção' },
    info: { dot: 'bg-sky-500', label: 'Informação' },
    good: { dot: 'bg-green-600', label: 'Bom sinal' },
};
const tabLabels: Record<string, string> = {
    custos: 'Custos',
    medicoes: 'Medições e autos',
    compras: 'Compras',
    planeamento: 'Planeamento',
    inspecoes: 'Inspeções',
    dados: 'Dados da obra',
};
function open(reason: ForecastReason): void {
    if (reason.tab) router.replace({ query: { separador: reason.tab } });
}

const confidence = computed(() => {
    const c = cost.value?.confidence;
    return c === 'high' ? { color: 'green' as const, label: 'Confiança alta' } : c === 'medium' ? { color: 'amber' as const, label: 'Confiança média' } : { color: 'gray' as const, label: 'Confiança baixa' };
});
const ceilingBadge = computed(() => {
    const a = cost.value?.alert;
    if (a === 'over') return { color: 'red' as const, label: 'Acima do teto' };
    if (a === 'warning') return { color: 'amber' as const, label: 'Perto do teto' };
    if (a === 'ok') return { color: 'green' as const, label: 'Dentro do teto' };
    return null;
});
// Barra: realizado | comprometido | por gastar até à previsão, com a marca do teto.
const bar = computed(() => {
    const c = cost.value;
    if (!c?.available) return null;
    const scale = Math.max(1, c.forecast_cents, c.ceiling_cents ?? 0, c.bac_cents);
    const pct = (v: number) => `${Math.max(0, (v * 100) / scale)}%`;
    const rest = Math.max(0, c.forecast_cents - c.actual_cents - c.committed_cents);
    return { actual: pct(c.actual_cents), committed: pct(c.committed_cents), rest: pct(rest), restCents: rest, ceiling: c.ceiling_cents === null ? null : pct(c.ceiling_cents) };
});

const scheduleBadge = computed(() => {
    switch (schedule.value?.status) {
        case 'finished':
            return { color: 'green' as const, label: 'Concluída' };
        case 'late':
            return { color: 'red' as const, label: 'Depois do prazo' };
        case 'behind':
            return { color: 'amber' as const, label: 'Atrás do planeamento' };
        default:
            return { color: 'green' as const, label: 'Dentro do previsto' };
    }
});

// Evolução semana a semana: as fotografias de cada segunda-feira e o valor de hoje.
const shortDate = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`;
const euros = (v: number) => `${Math.round(v).toLocaleString('pt-PT')} €`;
const costHistory = computed(() => forecast.value?.history.cost ?? []);
const scheduleHistory = computed(() => forecast.value?.history.schedule ?? []);
const costPoints = computed<TrendPoint[]>(() =>
    costHistory.value.map((h) => ({
        label: h.current ? 'hoje' : shortDate(h.week_start),
        value: h.forecast_cents / 100,
        title: `${h.current ? 'Hoje' : `Semana de ${formatDate(h.week_start)}`}: ${money(h.forecast_cents)}`,
        current: h.current,
    })),
);
const costRefs = computed<TrendReference[]>(() => {
    const last = costHistory.value[costHistory.value.length - 1];
    const refs: TrendReference[] = [];
    // Teto e orçamento quase iguais (a menos de 1 %): uma linha só.
    const same = !!last?.ceiling_cents && !!last.bac_cents && Math.abs(last.bac_cents - last.ceiling_cents) < last.ceiling_cents * 0.01;
    if (last?.ceiling_cents) refs.push({ value: last.ceiling_cents / 100, label: `Teto${same ? ' ≈ orçamento' : ''} ${money(last.ceiling_cents)}`, color: 'red' });
    if (last?.bac_cents && !same) refs.push({ value: last.bac_cents / 100, label: `Orçamento ${money(last.bac_cents)}`, color: 'slate' });
    return refs;
});
const finishPoints = computed<TrendPoint[]>(() =>
    scheduleHistory.value.map((h) => ({
        label: h.current ? 'hoje' : shortDate(h.week_start),
        value: dayNumber(h.forecast_finish_on),
        title: `${h.current ? 'Hoje' : `Semana de ${formatDate(h.week_start)}`}: fim previsto a ${formatDate(h.forecast_finish_on)}`,
        current: h.current,
    })),
);
const finishRefs = computed<TrendReference[]>(() => {
    const last = scheduleHistory.value[scheduleHistory.value.length - 1];
    const refs: TrendReference[] = [];
    if (last?.contract_end_on) refs.push({ value: dayNumber(last.contract_end_on), label: `Prazo ${formatDate(last.contract_end_on)}`, color: 'red' });
    if (last?.planned_finish_on && last.planned_finish_on !== last.contract_end_on) {
        refs.push({ value: dayNumber(last.planned_finish_on), label: `Plano ${formatDate(last.planned_finish_on)}`, color: 'slate' });
    }
    return refs;
});
const hasHistory = computed(() => costHistory.value.length > 1 || scheduleHistory.value.length > 1);
const showHistoryCard = computed(() => costHistory.value.length > 0 || scheduleHistory.value.length > 0);
// Tabela: uma linha por semana, com a diferença para a semana anterior.
const historyRows = computed(() => {
    const weeks = new Map<string, { week: string; current: boolean; cost: number | null; finish: string | null }>();
    for (const h of costHistory.value) weeks.set(h.week_start, { week: h.week_start, current: h.current, cost: h.forecast_cents, finish: null });
    for (const h of scheduleHistory.value) {
        const row = weeks.get(h.week_start) ?? { week: h.week_start, current: h.current, cost: null, finish: null };
        row.finish = h.forecast_finish_on;
        weeks.set(h.week_start, row);
    }
    const rows = [...weeks.values()].sort((a, b) => a.week.localeCompare(b.week));
    return rows
        .map((r, i) => {
            const prev = rows[i - 1];
            return {
                ...r,
                costDelta: prev && prev.cost !== null && r.cost !== null ? r.cost - prev.cost : null,
                finishDelta: prev?.finish && r.finish ? dayNumber(r.finish) - dayNumber(prev.finish) : null,
            };
        })
        .reverse();
});
const signedDays = (n: number) => (n > 0 ? `+${days(n)}` : n < 0 ? `−${days(n)}` : 'igual');
</script>

<template>
    <div>
        <AppAlert v-if="error" type="error">{{ error }}</AppAlert>
        <div v-else-if="!forecast" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>

        <div v-else class="space-y-6">
            <p class="text-sm text-slate-600">
                Previsões calculadas hoje ({{ formatDate(forecast.as_of) }}) com os dados da obra: orçamento aprovado, medições, custos, compras, contratos e o avanço do
                planeamento. Cada número vem com as razões.
            </p>

            <ul v-if="alerts.length" class="space-y-2" data-test="project-alerts">
                <li
                    v-for="a in alerts"
                    :key="a.id"
                    class="flex gap-3 rounded-lg border p-3 text-sm"
                    :class="a.level === 'danger' ? 'border-red-200 bg-red-50 text-red-900' : 'border-amber-200 bg-amber-50 text-amber-900'"
                >
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="a.level === 'danger' ? 'bg-red-600' : 'bg-amber-500'" />
                    <span class="min-w-0">
                        <span class="font-semibold">{{ a.title }}.</span> {{ a.detail }}
                        <RouterLink v-if="a.type === 'hold_point' || a.type === 'measurements_stale'" :to="a.path" class="ml-1 text-xs font-semibold whitespace-nowrap underline">
                            {{ a.type === 'hold_point' ? 'Inspeções' : 'Medições e autos' }} →
                        </RouterLink>
                        <span class="mt-0.5 block text-xs opacity-75">Alerta automático desde {{ formatDate(a.opened_at) }}</span>
                    </span>
                </li>
            </ul>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Custo no fim -->
                <AppCard v-if="cost" title="Custo no fim da obra" class="min-w-0">
                    <p v-if="!cost.available" class="text-sm text-slate-600">{{ cost.message }}</p>
                    <div v-else class="space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="text-3xl font-bold text-slate-900" data-test="eac">{{ money(cost.forecast_cents) }}</p>
                                <p class="text-sm text-slate-600">
                                    Orçamento (custo) {{ money(cost.bac_cents) }} ·
                                    <span :class="cost.variance_cents > 0 ? 'font-semibold text-red-700' : 'text-green-700'">{{ signedMoney(cost.variance_cents) }}</span>
                                    <template v-if="cost.bac_cents > 0"> ({{ signedPct((cost.variance_cents * 100) / cost.bac_cents) }})</template>
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <AppBadge v-if="ceilingBadge" :color="ceilingBadge.color">{{ ceilingBadge.label }}</AppBadge>
                                <AppBadge :color="confidence.color" :title="cost.confidence_text">{{ confidence.label }}</AppBadge>
                            </div>
                        </div>

                        <div v-if="bar">
                            <div class="relative h-3 overflow-hidden rounded-full bg-slate-100" role="img" :aria-label="`Realizado ${money(cost.actual_cents)}, comprometido ${money(cost.committed_cents)}, por gastar ${money(bar.restCents)}`">
                                <div class="absolute inset-y-0 left-0 flex w-full">
                                    <div class="h-full bg-brand-700" :style="{ width: bar.actual }" />
                                    <div class="h-full bg-brand-500" :style="{ width: bar.committed }" />
                                    <div class="h-full bg-brand-200" :style="{ width: bar.rest }" />
                                </div>
                                <div v-if="bar.ceiling" class="absolute inset-y-0 w-0.5 bg-red-600" :style="{ left: bar.ceiling }" title="Teto" />
                            </div>
                            <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
                                <li><span class="mr-1 inline-block h-2 w-2 rounded-full bg-brand-700" />Realizado {{ money(cost.actual_cents) }}</li>
                                <li><span class="mr-1 inline-block h-2 w-2 rounded-full bg-brand-500" />Comprometido {{ money(cost.committed_cents) }}</li>
                                <li><span class="mr-1 inline-block h-2 w-2 rounded-full bg-brand-200" />Por gastar {{ money(bar.restCents) }}</li>
                                <li v-if="cost.ceiling_cents !== null"><span class="mr-1 inline-block h-2 w-0.5 bg-red-600" />Teto {{ money(cost.ceiling_cents) }}</li>
                            </ul>
                        </div>

                        <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-slate-500">Trabalho medido</dt>
                                <dd class="font-semibold">
                                    {{ formatPct(cost.progress_pct) }} do orçamento
                                    <span class="block text-xs font-normal text-slate-500">{{ cost.last_measured_on ? `até ${formatDate(cost.last_measured_on)}` : 'ainda sem medições' }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Margem prevista</dt>
                                <dd class="font-semibold" :class="cost.margin_cents < 0 ? 'text-red-700' : ''">
                                    {{ money(cost.margin_cents) }}<template v-if="cost.margin_pct !== null"> ({{ formatPct(cost.margin_pct) }})</template>
                                    <span v-if="cost.budget_margin_pct !== null" class="block text-xs font-normal text-slate-500">no orçamento: {{ formatPct(cost.budget_margin_pct) }}</span>
                                </dd>
                            </div>
                        </dl>

                        <div>
                            <p class="mb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">Cenários</p>
                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-slate-100">
                                    <tr>
                                        <td class="py-1.5 pr-2">O que falta custa o orçamentado</td>
                                        <td class="py-1.5 text-right whitespace-nowrap" :class="cost.method === 'budget' ? 'font-bold' : ''">{{ money(cost.at_budget_cents) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="py-1.5 pr-2">
                                            Continua como até aqui
                                            <span v-if="cost.cost_per_euro !== null" class="block text-xs text-slate-500">{{ cost.cost_per_euro.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} € por cada 1,00 € orçamentado</span>
                                        </td>
                                        <td class="py-1.5 text-right whitespace-nowrap" :class="cost.method === 'trend' ? 'font-bold' : ''">
                                            {{ cost.at_trend_cents === null ? 'pouco medido' : money(cost.at_trend_cents) }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-1.5 pr-2">Mínimo: realizado + comprometido</td>
                                        <td class="py-1.5 text-right whitespace-nowrap">{{ money(cost.floor_cents) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="mt-1 text-xs text-slate-500">A negrito, o cenário usado na previsão. {{ cost.confidence_text }}</p>
                        </div>

                        <div>
                            <p class="mb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">Porquê</p>
                            <ul class="space-y-2" data-test="cost-reasons">
                                <li v-for="(r, i) in cost.reasons" :key="i" class="flex gap-2 text-sm">
                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="levelStyle[r.level].dot" :title="levelStyle[r.level].label" />
                                    <span class="min-w-0">
                                        {{ r.text }}
                                        <button v-if="r.tab && tabLabels[r.tab]" type="button" class="ml-1 text-xs font-semibold whitespace-nowrap text-brand-700 hover:underline" @click="open(r)">
                                            {{ tabLabels[r.tab] }} →
                                        </button>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </AppCard>

                <!-- Fim da obra -->
                <AppCard v-if="schedule" title="Fim da obra" class="min-w-0">
                    <div v-if="!schedule.available" class="text-sm text-slate-600">
                        {{ schedule.message }}
                        <button type="button" class="ml-1 font-semibold text-brand-700 hover:underline" @click="router.replace({ query: { separador: 'planeamento' } })">Planeamento →</button>
                    </div>
                    <div v-else class="space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="text-3xl font-bold text-slate-900" data-test="finish">{{ formatDate(schedule.forecast_finish_on) }}</p>
                                <p class="text-sm text-slate-600">{{ schedule.finished ? 'concluída' : 'fim previsto' }}</p>
                            </div>
                            <AppBadge :color="scheduleBadge.color">{{ scheduleBadge.label }}</AppBadge>
                        </div>

                        <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-slate-500">Planeamento</dt>
                                <dd class="font-semibold">
                                    {{ formatDate(schedule.planned_finish_on) }}
                                    <span v-if="schedule.delay_plan_days" class="block text-xs font-normal" :class="schedule.delay_plan_days > 0 ? 'text-amber-700' : 'text-green-700'">
                                        {{ schedule.delay_plan_days > 0 ? `${days(schedule.delay_plan_days, true)} de atraso` : `${days(schedule.delay_plan_days, true)} de avanço` }}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Prazo contratual</dt>
                                <dd class="font-semibold">
                                    {{ schedule.contract_end_on ? formatDate(schedule.contract_end_on) : 'sem data' }}
                                    <span v-if="schedule.delay_contract_days !== null" class="block text-xs font-normal" :class="schedule.delay_contract_days > 0 ? 'text-red-700' : 'text-green-700'">
                                        {{ schedule.delay_contract_days > 0 ? `${days(schedule.delay_contract_days)} depois` : schedule.delay_contract_days < 0 ? `${days(schedule.delay_contract_days)} de folga` : 'no próprio dia' }}
                                    </span>
                                </dd>
                            </div>
                        </dl>

                        <div v-if="schedule.started && !schedule.finished" class="space-y-2 text-sm">
                            <div>
                                <div class="flex justify-between text-xs text-slate-500"><span>Planeado para hoje</span><span>{{ formatPct(schedule.planned_progress_pct, 0) }}</span></div>
                                <div class="mt-1 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-slate-400" :style="{ width: `${schedule.planned_progress_pct}%` }" /></div>
                            </div>
                            <div>
                                <div class="flex justify-between text-xs text-slate-500"><span>Feito</span><span>{{ formatPct(schedule.progress_pct, 0) }}</span></div>
                                <div class="mt-1 h-2 rounded-full bg-slate-100">
                                    <div class="h-2 rounded-full" :class="schedule.progress_pct + 5 < schedule.planned_progress_pct ? 'bg-amber-500' : 'bg-green-600'" :style="{ width: `${schedule.progress_pct}%` }" />
                                </div>
                            </div>
                        </div>

                        <div>
                            <p class="mb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">Porquê</p>
                            <ul class="space-y-2" data-test="schedule-reasons">
                                <li v-for="(r, i) in schedule.reasons" :key="i" class="flex gap-2 text-sm">
                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="levelStyle[r.level].dot" :title="levelStyle[r.level].label" />
                                    <span class="min-w-0">
                                        {{ r.text }}
                                        <button v-if="r.tab && tabLabels[r.tab]" type="button" class="ml-1 text-xs font-semibold whitespace-nowrap text-brand-700 hover:underline" @click="open(r)">
                                            {{ tabLabels[r.tab] }} →
                                        </button>
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div v-if="schedule.activities.length">
                            <p class="mb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">Atividades atrasadas ou empurradas</p>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="text-left text-xs text-slate-500">
                                        <tr>
                                            <th class="py-1.5 pr-2">Atividade</th>
                                            <th class="py-1.5 pr-2 text-right">Feito / plano</th>
                                            <th class="py-1.5 text-right">Fim</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <tr v-for="a in schedule.activities" :key="a.id">
                                            <td class="py-1.5 pr-2">
                                                <span class="font-medium">{{ a.code }}</span> {{ a.name }}
                                                <span class="mt-0.5 flex flex-wrap gap-1">
                                                    <AppBadge v-if="a.critical" color="red">Caminho crítico</AppBadge>
                                                    <AppBadge v-if="a.waiting" color="gray">À espera da anterior</AppBadge>
                                                    <AppBadge v-else-if="a.late_start" color="amber">Devia ter começado</AppBadge>
                                                    <AppBadge v-if="a.holds.length" color="amber">Ponto de paragem</AppBadge>
                                                </span>
                                            </td>
                                            <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ a.progress_pct }}% / {{ a.planned_pct }}%</td>
                                            <td class="py-1.5 text-right whitespace-nowrap">
                                                {{ formatDate(a.forecast_finish_on) }}
                                                <span class="block text-xs text-slate-500">plano {{ formatDate(a.planned_finish_on) }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </AppCard>
            </div>

            <AppCard v-if="showHistoryCard" title="Evolução semana a semana">
                <p v-if="!hasHistory" class="text-sm text-slate-600" data-test="history-empty">
                    O histórico começa esta semana. Todos os dias a previsão da semana fica guardada; a partir da próxima segunda-feira vê-se aqui como evolui.
                </p>
                <div v-else class="space-y-6" data-test="history">
                    <div class="grid gap-6 lg:grid-cols-2">
                        <div v-if="costPoints.length > 1" class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900">Custo previsto no fim</p>
                            <TrendChart :points="costPoints" :references="costRefs" :format="euros" label="Evolução do custo previsto no fim da obra, semana a semana" />
                        </div>
                        <div v-if="finishPoints.length > 1" class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900">Fim previsto</p>
                            <TrendChart :points="finishPoints" :references="finishRefs" :format="dayLabel" :tick-values="dateTicks" label="Evolução da data prevista para o fim da obra, semana a semana" />
                        </div>
                    </div>
                    <details class="text-sm">
                        <summary class="cursor-pointer font-medium text-brand-700">Ver em tabela</summary>
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-xs text-slate-500">
                                    <tr>
                                        <th class="py-1.5 pr-2">Semana</th>
                                        <th v-if="costHistory.length" class="py-1.5 pr-2 text-right">Custo previsto</th>
                                        <th v-if="scheduleHistory.length" class="py-1.5 text-right">Fim previsto</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="r in historyRows" :key="r.week">
                                        <td class="py-1.5 pr-2 whitespace-nowrap">{{ r.current ? 'Hoje' : formatDate(r.week) }}</td>
                                        <td v-if="costHistory.length" class="py-1.5 pr-2 text-right whitespace-nowrap">
                                            {{ money(r.cost) }}
                                            <span v-if="r.costDelta" class="block text-xs" :class="r.costDelta > 0 ? 'text-red-700' : 'text-green-700'">{{ signedMoney(r.costDelta) }}</span>
                                        </td>
                                        <td v-if="scheduleHistory.length" class="py-1.5 text-right whitespace-nowrap">
                                            {{ r.finish ? formatDate(r.finish) : '—' }}
                                            <span v-if="r.finishDelta" class="block text-xs" :class="r.finishDelta > 0 ? 'text-red-700' : 'text-green-700'">{{ signedDays(r.finishDelta) }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </AppCard>

            <details class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700 shadow-sm">
                <summary class="cursor-pointer font-semibold text-slate-900">Como se calcula</summary>
                <div class="mt-3 space-y-3">
                    <p>
                        Sem inteligência artificial: são as contas do controlo de obra, feitas com os dados que estão no ObraConnect. Quanto mais em dia estiverem as medições, os
                        custos e o avanço do planeamento, melhor a previsão.
                    </p>
                    <div>
                        <p class="font-semibold">Custo no fim</p>
                        <ul class="mt-1 list-disc space-y-1 pl-5">
                            <li><strong>Trabalho medido</strong>: quantidades medidas × custo unitário do orçamento aprovado.</li>
                            <li><strong>O que falta</strong>: quantidades por medir × custo unitário (um artigo medido acima do orçamentado já não tem nada a faltar).</li>
                            <li>
                                <strong>Custo até à última medição</strong>: realizado e material recebido ainda por faturar até essa data. Dividido pelo trabalho medido dá o ritmo: quanto
                                está a custar cada euro orçamentado.
                            </li>
                            <li>
                                A previsão é o custo até à última medição mais o que falta: ao preço do orçamento enquanto houver menos de metade medida, ao ritmo de até aqui a partir
                                daí. Nunca fica abaixo do realizado mais o comprometido (encomendas e contratos que vão ser pagos).
                            </li>
                            <li>A margem prevista é a venda do orçamento menos a previsão. Trabalhos a mais faturáveis ao dono de obra não entram na venda.</li>
                        </ul>
                    </div>
                    <div>
                        <p class="font-semibold">Fim da obra</p>
                        <ul class="mt-1 list-disc space-y-1 pl-5">
                            <li>O planeamento (caminho crítico, em dias úteis do calendário da obra) é recalculado a partir de hoje.</li>
                            <li>O que está feito já não ocupa tempo; o que está em curso ocupa o que lhe falta (duração × % por fazer); nada do que falta começa antes de hoje.</li>
                            <li>
                                As razões apontam as atividades do caminho crítico atrasadas por si próprias. As que estão só à espera da anterior aparecem na lista, mas não como
                                causa.
                            </li>
                        </ul>
                    </div>
                    <div>
                        <p class="font-semibold">Histórico e alertas</p>
                        <ul class="mt-1 list-disc space-y-1 pl-5">
                            <li>Todos os dias, às 7h, a previsão fica guardada como a da semana; a de cada semana é a do último dia calculado. O ponto "hoje" é o desta página.</li>
                            <li>
                                Ao mesmo tempo, o ObraConnect abre alertas automáticos (perto ou acima do teto, fim depois do prazo, custo ou fim a piorar numa semana, medições
                                atrasadas, pontos de paragem por libertar) e envia-os por email ao diretor de obra. Fecham-se sozinhos quando a situação passa. As regras estão em
                                <strong>Empresa → Alertas automáticos</strong>.
                            </li>
                        </ul>
                    </div>
                </div>
            </details>
        </div>
    </div>
</template>
