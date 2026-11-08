<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { Project } from '@/api/types';
import { ApiError } from '@/api/http';
import { parseNumber, planningApi, type Plan, type PlanActivity } from '@/api/economics';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const props = defineProps<{ project: Project }>();
const session = useSessionStore();
const toast = useToastStore();
const plan = ref<Plan | null>(null);
const canManage = computed(() => (plan.value?.can_manage ?? false) && !session.isReadOnly);

onMounted(async () => {
    plan.value = await planningApi.get(props.project.id);
});
const fail = (e: unknown) => toast.error(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Não foi possível.');

// Ordem em árvore (resumo seguido das filhas) e profundidade.
const rows = computed(() => {
    const list = plan.value?.activities ?? [];
    const children = new Map<number | null, PlanActivity[]>();
    for (const a of list) children.set(a.parent_id, [...(children.get(a.parent_id) ?? []), a]);
    const out: { a: PlanActivity; depth: number }[] = [];
    const walk = (parent: number | null, depth: number) => {
        for (const a of children.get(parent) ?? []) {
            out.push({ a, depth });
            if (depth < 8) walk(a.id, depth + 1);
        }
    };
    walk(null, 0);
    return out;
});
const byId = computed(() => new Map((plan.value?.activities ?? []).map((a) => [a.id, a])));

// ---- Gantt (eixo em dias de calendário) ----
const DAY_MS = 86_400_000;
const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const ROW = 30;
const toDay = (iso: string) => Math.round(new Date(`${iso}T12:00:00`).getTime() / DAY_MS);
const range = computed(() => {
    const p = plan.value;
    if (!p?.start_on || !p.finish_on) return null;
    const first = toDay(p.start_on);
    let last = toDay(p.finish_on) + 1;
    if (p.project.planned_end_on) last = Math.max(last, toDay(p.project.planned_end_on) + 1);
    const days = Math.max(7, last - first);
    const scale = Math.max(4, Math.min(28, 900 / days));
    return { first, days, scale, width: days * scale };
});
const x = (iso: string) => (toDay(iso) - range.value!.first) * range.value!.scale;
const months = computed(() => {
    const r = range.value;
    if (!r) return [];
    const out: { x: number; label: string }[] = [];
    const d = new Date((r.first + 0.5) * DAY_MS);
    d.setUTCDate(1);
    for (let i = 0; i < 60; i++) {
        const day = Math.round(d.getTime() / DAY_MS) - r.first;
        if (day > r.days) break;
        if (day >= -31) out.push({ x: Math.max(0, day * r.scale), label: `${MONTHS[d.getUTCMonth()]} ${String(d.getUTCFullYear()).slice(2)}` });
        d.setUTCMonth(d.getUTCMonth() + 1);
    }
    return out;
});
const todayX = computed(() => {
    const r = range.value;
    if (!r) return null;
    const t = Math.round(Date.now() / DAY_MS) - r.first;
    return t >= 0 && t <= r.days ? t * r.scale : null;
});
const endX = computed(() => (plan.value?.project.planned_end_on && range.value ? x(plan.value.project.planned_end_on) + range.value.scale : null));
function bar(a: PlanActivity) {
    if (!a.start_on || !a.finish_on || !range.value) return null;
    const x0 = x(a.start_on);
    const x1 = x(a.finish_on) + range.value.scale;
    return { x: x0, w: Math.max(2, x1 - x0), milestone: (a.duration_days ?? 0) === 0 && !a.summary };
}
// Ligações FS (fim → início), as mais comuns.
const links = computed(() => {
    const index = new Map(rows.value.map((r, i) => [r.a.id, i]));
    const out: { d: string; critical: boolean }[] = [];
    for (const { a } of rows.value) {
        for (const dep of a.predecessors) {
            const from = byId.value.get(dep.predecessor_id);
            const bf = from ? bar(from) : null;
            const bt = bar(a);
            const i0 = index.get(dep.predecessor_id);
            const i1 = index.get(a.id);
            if (!bf || !bt || i0 === undefined || i1 === undefined) continue;
            const sx = dep.type === 'SS' || dep.type === 'SF' ? bf.x : bf.x + bf.w;
            const ex = dep.type === 'FF' || dep.type === 'SF' ? bt.x + bt.w : bt.x;
            const sy = i0 * ROW + ROW / 2;
            const ey = i1 * ROW + ROW / 2;
            const mid = Math.max(sx + 6, Math.min(ex - 6, sx + 6));
            out.push({ d: `M${sx},${sy} H${mid} V${ey} H${ex}`, critical: !!from?.critical && !!a.critical });
        }
    }
    return out;
});

// ---- Atividade ----
const open = ref(false);
const editing = ref<PlanActivity | null>(null);
const form = useForm({ name: '', code: '', parent_id: '' as number | '', duration_days: '5', optimistic_days: '', likely_days: '', pessimistic_days: '', start_no_earlier_than: '' });
function openActivity(a: PlanActivity | null, parentId: number | null = null): void {
    editing.value = a;
    form.reset(
        a
            ? {
                  name: a.name,
                  code: a.code,
                  parent_id: a.parent_id ?? '',
                  duration_days: String(a.duration_days),
                  optimistic_days: a.optimistic_days === null ? '' : String(a.optimistic_days),
                  likely_days: a.likely_days === null ? '' : String(a.likely_days),
                  pessimistic_days: a.pessimistic_days === null ? '' : String(a.pessimistic_days),
                  start_no_earlier_than: a.start_no_earlier_than ?? '',
              }
            : { parent_id: parentId ?? '' },
    );
    progress.value = String(a?.progress_pct ?? 0);
    dep.reset();
    open.value = true;
}
const parentOptions = computed(() => [
    { value: '', label: '(no topo)' },
    ...rows.value.filter((r) => r.a.id !== editing.value?.id).map((r) => ({ value: r.a.id, label: `${'— '.repeat(r.depth)}${r.a.code} · ${r.a.name}` })),
]);
async function save(): Promise<void> {
    const payload: Record<string, unknown> = {
        name: form.data.name,
        parent_id: form.data.parent_id === '' ? null : Number(form.data.parent_id),
        duration_days: Math.round(parseNumber(form.data.duration_days) ?? 0),
        optimistic_days: parseNumber(form.data.optimistic_days),
        likely_days: parseNumber(form.data.likely_days),
        pessimistic_days: parseNumber(form.data.pessimistic_days),
        start_no_earlier_than: form.data.start_no_earlier_than || null,
    };
    if (form.data.code) payload.code = form.data.code;
    const id = editing.value?.id;
    const saved = await form.submit(() => (id ? planningApi.updateActivity(props.project.id, id, payload) : planningApi.addActivity(props.project.id, payload)));
    if (saved) {
        plan.value = saved;
        open.value = false;
    }
}
async function remove(): Promise<void> {
    if (!editing.value || !window.confirm(`Apagar a atividade ${editing.value.code}?`)) return;
    try {
        plan.value = await planningApi.removeActivity(props.project.id, editing.value.id);
        open.value = false;
    } catch (e) {
        fail(e);
    }
}
const progress = ref('0');
async function saveProgress(): Promise<void> {
    if (!editing.value) return;
    try {
        plan.value = await planningApi.progress(props.project.id, editing.value.id, Math.max(0, Math.min(100, Math.round(parseNumber(progress.value) ?? 0))));
        editing.value = byId.value.get(editing.value.id) ?? null;
        toast.success('Avanço guardado.');
    } catch (e) {
        fail(e);
    }
}
const dep = useForm({ predecessor_id: '' as number | '', type: 'FS', lag_days: '0' });
const TYPES = [
    { value: 'FS', label: 'Fim → início' },
    { value: 'SS', label: 'Início → início' },
    { value: 'FF', label: 'Fim → fim' },
    { value: 'SF', label: 'Início → fim' },
];
async function addDependency(): Promise<void> {
    if (!editing.value) return;
    const saved = await dep.submit(() =>
        planningApi.addDependency(props.project.id, {
            predecessor_id: dep.data.predecessor_id === '' ? null : Number(dep.data.predecessor_id),
            successor_id: editing.value!.id,
            type: dep.data.type,
            lag_days: Math.round(parseNumber(dep.data.lag_days) ?? 0),
        }),
    );
    if (saved) {
        plan.value = saved;
        editing.value = byId.value.get(editing.value.id) ?? null;
        dep.reset();
    }
}
async function removeDependency(id: number): Promise<void> {
    if (!editing.value) return;
    plan.value = await planningApi.removeDependency(props.project.id, id);
    editing.value = byId.value.get(editing.value.id) ?? null;
}
const predecessorOptions = computed(() => rows.value.filter((r) => r.a.id !== editing.value?.id && !r.a.summary).map((r) => ({ value: r.a.id, label: `${r.a.code} · ${r.a.name}` })));
const nameOf = (id: number) => {
    const a = byId.value.get(id);
    return a ? `${a.code} · ${a.name}` : `#${id}`;
};
</script>

<template>
    <div v-if="!plan" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
    <div v-else class="space-y-4">
        <AppAlert v-if="plan.error" type="error">{{ plan.error }}</AppAlert>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="text-sm text-slate-600">
                <template v-if="plan.start_on">
                    De <strong>{{ formatDate(plan.start_on) }}</strong> a <strong>{{ formatDate(plan.finish_on) }}</strong> · {{ plan.duration_days }} dias úteis
                    <template v-if="plan.pert"> · PERT: {{ plan.pert.expected_days }} ± {{ plan.pert.sigma_days }} dias</template>
                </template>
                <template v-else>Sem atividades planeadas.</template>
                <span v-if="plan.project.planned_end_on" class="block">Fim previsto da obra: {{ formatDate(plan.project.planned_end_on) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <AppBadge v-if="plan.late" color="red">O plano acaba depois do fim previsto</AppBadge>
                <AppButton v-if="canManage" size="sm" @click="openActivity(null)">Nova atividade</AppButton>
            </div>
        </div>

        <AppCard>
            <EmptyState v-if="!rows.length" title="Sem atividades" :description="canManage ? 'Crie as atividades, a duração em dias úteis e as precedências; o caminho crítico calcula-se sozinho.' : undefined" />
            <div v-else class="flex overflow-hidden">
                <div class="w-56 shrink-0 border-r border-slate-200 sm:w-80">
                    <div class="h-7 border-b border-slate-200 text-xs font-semibold text-slate-500">Atividade</div>
                    <button
                        v-for="r in rows"
                        :key="r.a.id"
                        type="button"
                        class="flex w-full items-center gap-1 border-b border-slate-50 pr-2 text-left text-sm hover:bg-slate-50"
                        :style="{ height: `${ROW}px`, paddingLeft: `${r.depth * 12 + 4}px` }"
                        :class="r.a.summary ? 'font-semibold' : ''"
                        @click="openActivity(r.a)"
                    >
                        <span class="font-mono text-xs text-slate-500">{{ r.a.code }}</span>
                        <span class="truncate" :class="r.a.critical && !r.a.summary ? 'text-red-700' : ''">{{ r.a.name }}</span>
                        <span v-if="r.a.holds.length" class="ml-auto shrink-0 rounded bg-red-100 px-1 text-[10px] font-bold text-red-700" :title="`Ponto de paragem por libertar (inspeção ${r.a.holds.map((h) => h.number).join(', ')})`">PP</span>
                        <span v-else-if="r.a.completed_at" class="ml-auto shrink-0 text-xs text-green-700">✓</span>
                    </button>
                </div>
                <div class="min-w-0 flex-1 overflow-x-auto">
                    <svg v-if="range" :width="range.width + 20" :height="rows.length * ROW + 28" class="block" role="img" aria-label="Gráfico de Gantt">
                        <g font-size="10" fill="#64748b">
                            <g v-for="m in months" :key="m.x">
                                <line :x1="m.x" :x2="m.x" y1="0" :y2="rows.length * ROW + 28" stroke="#e2e8f0" />
                                <text :x="m.x + 3" y="12">{{ m.label }}</text>
                            </g>
                        </g>
                        <g transform="translate(0,28)">
                            <rect v-for="(r, i) in rows" :key="`bg${r.a.id}`" x="0" :y="i * ROW" :width="range.width + 20" :height="ROW" :fill="i % 2 ? '#f8fafc' : 'transparent'" />
                            <path v-for="(l, i) in links" :key="`l${i}`" :d="l.d" fill="none" :stroke="l.critical ? '#dc2626' : '#94a3b8'" stroke-width="1" marker-end="url(#arrow)" />
                            <g v-for="(r, i) in rows" :key="`b${r.a.id}`">
                                <template v-if="bar(r.a)">
                                    <template v-if="r.a.summary">
                                        <rect :x="bar(r.a)!.x" :y="i * ROW + 11" :width="bar(r.a)!.w" height="6" fill="#334155" />
                                        <path :d="`M${bar(r.a)!.x},${i * ROW + 11} v11 l5,-5 z M${bar(r.a)!.x + bar(r.a)!.w},${i * ROW + 11} v11 l-5,-5 z`" fill="#334155" />
                                    </template>
                                    <path
                                        v-else-if="bar(r.a)!.milestone"
                                        :d="`M${bar(r.a)!.x},${i * ROW + 7} l8,8 l-8,8 l-8,-8 z`"
                                        :fill="r.a.critical ? '#dc2626' : '#0f766e'"
                                    />
                                    <template v-else>
                                        <rect :x="bar(r.a)!.x" :y="i * ROW + 7" :width="bar(r.a)!.w" height="16" rx="3" :fill="r.a.critical ? '#fecaca' : '#ccfbf1'" :stroke="r.a.critical ? '#dc2626' : '#0f766e'" />
                                        <rect :x="bar(r.a)!.x" :y="i * ROW + 7" :width="(bar(r.a)!.w * r.a.progress_pct) / 100" height="16" rx="3" :fill="r.a.critical ? '#dc2626' : '#0f766e'" opacity="0.75" />
                                    </template>
                                    <title>{{ r.a.code }} {{ r.a.name }}: {{ formatDate(r.a.start_on) }} a {{ formatDate(r.a.finish_on) }} · {{ r.a.duration_days }} d · folga {{ r.a.total_float }} d · {{ r.a.progress_pct }}%</title>
                                </template>
                            </g>
                            <line v-if="todayX !== null" :x1="todayX" :x2="todayX" y1="-6" :y2="rows.length * ROW" stroke="#2563eb" stroke-dasharray="3 3" />
                            <line v-if="endX !== null" :x1="endX" :x2="endX" y1="-6" :y2="rows.length * ROW" stroke="#7c3aed" stroke-width="1.5" />
                        </g>
                        <defs>
                            <marker id="arrow" viewBox="0 0 6 6" refX="5" refY="3" markerWidth="5" markerHeight="5" orient="auto"><path d="M0,0 L6,3 L0,6 z" fill="#94a3b8" /></marker>
                        </defs>
                    </svg>
                </div>
            </div>
            <p v-if="rows.length" class="mt-3 text-xs text-slate-500">
                <span class="text-red-700">Vermelho</span>: caminho crítico (sem folga). Parte cheia da barra: avanço. Azul tracejado: hoje. Roxo: fim previsto da obra. PP: ponto de paragem por libertar.
            </p>
        </AppCard>

        <AppModal :open="open" :title="editing ? `${editing.code} · ${editing.name}` : 'Nova atividade'" size="lg" @close="open = false">
            <div v-if="editing && !editing.summary" class="mb-4 grid gap-2 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-3">
                <span>Início: <strong>{{ formatDate(editing.start_on) }}</strong></span>
                <span>Fim: <strong>{{ formatDate(editing.finish_on) }}</strong></span>
                <span>Folga: <strong>{{ editing.total_float }} dias</strong><template v-if="editing.critical"> · crítica</template></span>
                <span v-if="editing.pert_expected_days" class="sm:col-span-3">PERT: {{ editing.pert_expected_days }} dias esperados</span>
                <span v-if="editing.holds.length" class="font-semibold text-red-700 sm:col-span-3">Ponto de paragem por libertar: não se dá como concluída antes da inspeção.</span>
            </div>
            <form v-if="canManage" id="activity-form" class="space-y-4" novalidate @submit.prevent="save">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <div class="grid gap-4 sm:grid-cols-4">
                    <AppInput v-model="form.data.code" label="Código" placeholder="automático" :error="form.errors.value.code" />
                    <AppInput v-model="form.data.name" class="sm:col-span-3" label="Nome" required :error="form.errors.value.name" />
                    <AppSelect v-model="form.data.parent_id" class="sm:col-span-2" label="Dentro de" :options="parentOptions" :error="form.errors.value.parent_id" />
                    <AppInput v-model="form.data.duration_days" label="Duração (dias úteis)" inputmode="numeric" :error="form.errors.value.duration_days" />
                    <AppInput v-model="form.data.start_no_earlier_than" type="date" label="Não antes de" />
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <AppInput v-model="form.data.optimistic_days" label="PERT otimista" inputmode="decimal" />
                    <AppInput v-model="form.data.likely_days" label="Mais provável" inputmode="decimal" :error="form.errors.value.likely_days" />
                    <AppInput v-model="form.data.pessimistic_days" label="Pessimista" inputmode="decimal" />
                </div>
            </form>

            <template v-if="editing && !editing.summary">
                <div v-if="canManage" class="mt-5 flex items-end gap-2 border-t border-slate-200 pt-4">
                    <AppInput v-model="progress" label="Avanço (%)" inputmode="numeric" />
                    <AppButton size="sm" variant="secondary" @click="saveProgress">Guardar avanço</AppButton>
                </div>
                <p v-else class="mt-3 text-sm">Avanço: {{ editing.progress_pct }}%</p>

                <div class="mt-5 border-t border-slate-200 pt-4">
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Precedências (o que tem de acontecer antes)</h3>
                    <ul class="mb-3 space-y-1 text-sm">
                        <li v-for="d in editing.predecessors" :key="d.id" class="flex justify-between gap-2">
                            <span>{{ nameOf(d.predecessor_id) }} · {{ TYPES.find((t) => t.value === d.type)?.label }}<template v-if="d.lag_days"> · {{ d.lag_days > 0 ? '+' : '' }}{{ d.lag_days }} d</template></span>
                            <button v-if="canManage" type="button" class="text-xs text-red-700 hover:underline" @click="removeDependency(d.id)">Retirar</button>
                        </li>
                        <li v-if="!editing.predecessors.length" class="text-slate-500">Nenhuma: começa no início da obra (ou na data “não antes de”).</li>
                    </ul>
                    <form v-if="canManage" class="grid items-end gap-2 sm:grid-cols-6" novalidate @submit.prevent="addDependency">
                        <AppSelect v-model="dep.data.predecessor_id" class="sm:col-span-3" label="Atividade anterior" placeholder="Escolha" :options="predecessorOptions" :error="dep.errors.value.predecessor_id || dep.message.value || undefined" />
                        <AppSelect v-model="dep.data.type" label="Ligação" :options="TYPES" />
                        <AppInput v-model="dep.data.lag_days" label="Desfasamento (d)" inputmode="numeric" />
                        <AppButton type="submit" size="sm" variant="secondary" :loading="dep.processing.value">Juntar</AppButton>
                    </form>
                </div>
            </template>
            <template #footer>
                <AppButton v-if="editing && canManage" variant="ghost" class="mr-auto text-red-700" @click="remove">Apagar</AppButton>
                <AppButton variant="secondary" @click="open = false">Fechar</AppButton>
                <AppButton v-if="canManage" type="submit" form="activity-form" :loading="form.processing.value">Guardar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
