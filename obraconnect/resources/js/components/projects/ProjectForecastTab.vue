<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { Project } from '@/api/types';
import { ApiError } from '@/api/http';
import { forecastApi, formatPct, type ForecastLevel, type ForecastReason, type ProjectForecast } from '@/api/economics';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';

const props = defineProps<{ project: Project }>();
const router = useRouter();
const forecast = ref<ProjectForecast | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        forecast.value = await forecastApi.get(props.project.id);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível calcular as previsões.';
    }
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

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Custo no fim -->
                <AppCard v-if="cost" title="Custo no fim da obra">
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
                <AppCard v-if="schedule" title="Fim da obra">
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
                </div>
            </details>
        </div>
    </div>
</template>
