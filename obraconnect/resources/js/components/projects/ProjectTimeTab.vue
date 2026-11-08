<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import type { Project } from '@/api/types';
import { ApiError } from '@/api/http';
import { equipmentApi, formatQty, parseNumber, timeApi, workersApi, type EquipmentSummary, type EquipmentUsage, type TimeEntry, type TimeWeek, type Worker } from '@/api/economics';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const props = defineProps<{ project: Project }>();
const session = useSessionStore();
const toast = useToastStore();
const has = (p: string) => props.project.permissions.includes(p);
const showEquipment = computed(() => ['equipment.manage', 'costs.view', 'workforce.view'].some(has));
const showTime = computed(() => ['workforce.view', 'time.register_team', 'time.register_own', 'time.approve', 'costs.view'].some(has));

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayIso = iso(new Date());
const weekOf = ref(todayIso);
const week = ref<TimeWeek | null>(null);
const workers = ref<Worker[]>([]);
const loading = ref(true);
const DAY = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

async function loadWeek(): Promise<void> {
    week.value = await timeApi.week(props.project.id, weekOf.value);
}
onMounted(async () => {
    if (showTime.value) await loadWeek();
    if (week.value?.can.register_team || week.value?.can.approve || has('workforce.view')) {
        workers.value = (await workersApi.list().catch(() => [] as Worker[])).filter((w) => w.active);
    }
    if (showEquipment.value) await loadEquipment();
    loading.value = false;
    if (week.value) resetSheet();
});
function shiftWeek(days: number): void {
    const d = new Date(`${week.value?.week_start ?? weekOf.value}T12:00:00`);
    d.setDate(d.getDate() + days);
    weekOf.value = iso(d);
    loadWeek();
}

const days = computed(() => {
    if (!week.value) return [];
    const start = new Date(`${week.value.week_start}T12:00:00`);
    return DAY.map((label, i) => {
        const d = new Date(start);
        d.setDate(d.getDate() + i);
        return { label, date: iso(d), day: d.getDate() };
    });
});
const grid = computed(() => {
    const rows = new Map<number, { worker: string; category: string | null; cells: Record<string, TimeEntry>; hours: number; cost: number | null }>();
    for (const e of week.value?.entries ?? []) {
        const row = rows.get(e.worker_id) ?? { worker: e.worker ?? '—', category: e.category, cells: {}, hours: 0, cost: e.cost_cents === null ? null : 0 };
        row.cells[e.work_date] = e;
        row.hours += e.hours + e.overtime_hours;
        if (row.cost !== null && e.cost_cents !== null) row.cost += e.cost_cents;
        rows.set(e.worker_id, row);
    }
    return [...rows.values()].sort((a, b) => a.worker.localeCompare(b.worker));
});
const weekCost = computed(() => (grid.value.some((r) => r.cost !== null) ? grid.value.reduce((s, r) => s + (r.cost ?? 0), 0) : null));
const cellClass = (e: TimeEntry | undefined) =>
    !e ? 'text-slate-300' : e.status === 'approved' ? 'bg-green-50 text-green-800' : e.status === 'rejected' ? 'bg-red-50 text-red-700 line-through' : 'bg-amber-50 text-amber-800';

// ---- Folha do dia ----
const sheetDate = ref(todayIso);
const sheet = reactive<Record<number, { hours: string; overtime: string; description: string }>>({});
const sheetWorkers = computed(() => {
    if (!week.value) return [];
    if (week.value.can.register_team) return workers.value;
    return workers.value.filter((w) => week.value!.own_worker_ids.includes(w.id)).concat(
        week.value.own_worker_ids.filter((id) => !workers.value.some((w) => w.id === id)).map((id) => ({ id, name: 'As minhas horas', category: null }) as Worker),
    );
});
async function resetSheet(): Promise<void> {
    if (sheetDate.value < (week.value?.week_start ?? '') || sheetDate.value > (week.value?.week_end ?? '')) {
        weekOf.value = sheetDate.value;
        await loadWeek();
    }
    for (const w of sheetWorkers.value) {
        const e = week.value?.entries.find((x) => x.worker_id === w.id && x.work_date === sheetDate.value);
        sheet[w.id] = { hours: e ? String(e.hours).replace('.', ',') : '', overtime: e && e.overtime_hours ? String(e.overtime_hours).replace('.', ',') : '', description: e?.description ?? '' };
    }
}
const locked = (workerId: number) => week.value?.entries.some((x) => x.worker_id === workerId && x.work_date === sheetDate.value && x.status === 'approved') ?? false;
const saving = ref(false);
async function saveSheet(): Promise<void> {
    const entries = sheetWorkers.value
        .filter((w) => !locked(w.id) && (sheet[w.id]?.hours || sheet[w.id]?.overtime))
        .map((w) => ({ worker_id: w.id, hours: parseNumber(sheet[w.id]!.hours) ?? 0, overtime_hours: parseNumber(sheet[w.id]!.overtime) ?? 0, description: sheet[w.id]!.description || null }));
    if (!entries.length) {
        toast.error('Escreva as horas de pelo menos uma pessoa.');
        return;
    }
    saving.value = true;
    try {
        const r = await timeApi.save(props.project.id, { work_date: sheetDate.value, entries });
        toast.success(`${r.saved} ${r.saved === 1 ? 'registo guardado' : 'registos guardados'} (por aprovar).`);
        weekOf.value = sheetDate.value;
        await loadWeek();
    } catch (e) {
        toast.error(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Não foi possível guardar.');
    } finally {
        saving.value = false;
    }
}
const canRegister = computed(() => !session.isReadOnly && (week.value?.can.register_team || (week.value?.can.register_own && sheetWorkers.value.length > 0)));

// ---- Aprovação ----
const pending = computed(() => week.value?.entries.filter((e) => e.status === 'pending') ?? []);
const selected = ref<number[]>([]);
async function decide(decision: 'approve' | 'reject'): Promise<void> {
    const ids = selected.value.length ? selected.value : pending.value.map((e) => e.id);
    if (!ids.length) return;
    try {
        const r = await timeApi.decide(props.project.id, decision, ids);
        toast.success(`${r.updated} ${r.updated === 1 ? 'registo' : 'registos'} ${decision === 'approve' ? (r.updated === 1 ? 'aprovado' : 'aprovados') : r.updated === 1 ? 'rejeitado' : 'rejeitados'}.`);
        selected.value = [];
        await loadWeek();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível.');
    }
}

// ---- Equipamentos ----
const usages = ref<EquipmentUsage[]>([]);
const onSite = ref<EquipmentSummary[]>([]);
const allEquipment = ref<EquipmentSummary[]>([]);
const canUse = ref(false);
async function loadEquipment(): Promise<void> {
    const r = await equipmentApi.usages(props.project.id);
    usages.value = r.usages;
    onSite.value = r.on_site;
    canUse.value = r.can_register && !session.isReadOnly;
    if (canUse.value && !allEquipment.value.length) allEquipment.value = (await equipmentApi.list().catch(() => [])).filter((e) => e.status !== 'retired');
}
const equipmentOptions = computed(() => {
    const list = [...onSite.value, ...allEquipment.value.filter((e) => !onSite.value.some((o) => o.id === e.id))];
    return list.map((e) => ({ value: e.id, label: `${e.code} · ${e.name}${e.project_id === props.project.id ? ' (na obra)' : ''}` }));
});
const usage = useForm({ equipment_id: '' as number | '', used_on: todayIso, hours: '', notes: '' });
async function addUsage(): Promise<void> {
    const saved = await usage.submit(
        () =>
            equipmentApi.addUsage(props.project.id, {
                equipment_id: usage.data.equipment_id === '' ? null : Number(usage.data.equipment_id),
                used_on: usage.data.used_on,
                hours: parseNumber(usage.data.hours),
                notes: usage.data.notes || null,
            }),
        { success: 'Horas do equipamento registadas.' },
    );
    if (saved) {
        usage.reset({ equipment_id: usage.data.equipment_id, used_on: usage.data.used_on });
        await loadEquipment();
    }
}
async function removeUsage(u: EquipmentUsage): Promise<void> {
    if (!window.confirm('Apagar este registo?')) return;
    await equipmentApi.removeUsage(props.project.id, u.id);
    await loadEquipment();
}
</script>

<template>
    <div v-if="loading" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
    <div v-else class="space-y-6">
        <AppCard v-if="week" title="Horas da semana">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-1">
                    <AppButton size="sm" variant="secondary" aria-label="Semana anterior" @click="shiftWeek(-7)">‹</AppButton>
                    <span class="px-2 text-sm font-semibold">{{ formatDate(week.week_start) }} a {{ formatDate(week.week_end) }}</span>
                    <AppButton size="sm" variant="secondary" aria-label="Semana seguinte" @click="shiftWeek(7)">›</AppButton>
                </div>
                <p class="text-sm text-slate-600">
                    <span class="mr-2 inline-block h-3 w-3 rounded bg-amber-100 align-middle" /> por aprovar
                    <span class="mr-2 ml-3 inline-block h-3 w-3 rounded bg-green-100 align-middle" /> aprovadas
                    <template v-if="weekCost !== null"> · custo da semana <strong>{{ formatMoney(weekCost) }}</strong></template>
                </p>
            </div>
            <EmptyState v-if="!grid.length" title="Sem horas nesta semana" />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-500">
                        <tr>
                            <th class="py-1.5 pr-2 text-left">Trabalhador</th>
                            <th v-for="d in days" :key="d.date" class="px-1 py-1.5 text-center" :class="d.date === todayIso ? 'text-brand-700' : ''">{{ d.label }} {{ d.day }}</th>
                            <th class="py-1.5 pl-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="r in grid" :key="r.worker">
                            <td class="py-1.5 pr-2">{{ r.worker }}<span v-if="r.category" class="block text-xs text-slate-500">{{ r.category }}</span></td>
                            <td v-for="d in days" :key="d.date" class="px-1 py-1.5 text-center">
                                <span class="inline-block min-w-10 rounded px-1 py-0.5" :class="cellClass(r.cells[d.date])" :title="r.cells[d.date]?.description ?? undefined">
                                    {{ r.cells[d.date] ? formatQty(r.cells[d.date]!.hours, 1) : '·' }}<sup v-if="r.cells[d.date]?.overtime_hours">+{{ formatQty(r.cells[d.date]!.overtime_hours, 1) }}</sup>
                                </span>
                            </td>
                            <td class="py-1.5 pl-2 text-right whitespace-nowrap">{{ formatQty(r.hours, 1) }} h<span v-if="r.cost !== null" class="block text-xs text-slate-500">{{ formatMoney(r.cost) }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="week.can.approve && pending.length && !session.isReadOnly" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
                <p class="mb-2 text-sm font-semibold text-amber-900">{{ pending.length }} registos por aprovar nesta semana</p>
                <div class="mb-2 flex max-h-40 flex-col gap-1 overflow-y-auto text-sm">
                    <label v-for="e in pending" :key="e.id" class="flex items-center gap-2">
                        <input v-model="selected" type="checkbox" :value="e.id" />
                        {{ formatDate(e.work_date) }} · {{ e.worker }} · {{ formatQty(e.hours, 1) }} h<template v-if="e.overtime_hours"> + {{ formatQty(e.overtime_hours, 1) }} extra</template>
                    </label>
                </div>
                <div class="flex gap-2">
                    <AppButton size="sm" @click="decide('approve')">{{ selected.length ? `Aprovar ${selected.length}` : 'Aprovar todos' }}</AppButton>
                    <AppButton size="sm" variant="secondary" @click="decide('reject')">{{ selected.length ? `Rejeitar ${selected.length}` : 'Rejeitar todos' }}</AppButton>
                </div>
            </div>
        </AppCard>

        <AppCard v-if="week && canRegister" title="Folha do dia" description="Horas normais e extra de cada pessoa neste dia. Voltar a gravar o mesmo dia substitui o registo (se ainda não foi aprovado).">
            <div class="mb-3 flex items-end gap-2">
                <AppInput v-model="sheetDate" type="date" label="Dia" />
                <AppButton size="sm" variant="secondary" @click="resetSheet">Carregar</AppButton>
            </div>
            <AppAlert v-if="!sheetWorkers.length">Ainda não há trabalhadores. Crie-os em Trabalhadores.</AppAlert>
            <form v-else novalidate @submit.prevent="saveSheet">
                <div class="divide-y divide-slate-100">
                    <div v-for="w in sheetWorkers" :key="w.id" class="grid grid-cols-12 items-center gap-2 py-1.5">
                        <span class="col-span-12 text-sm sm:col-span-4">{{ w.name }}<span v-if="w.category" class="text-xs text-slate-500"> · {{ w.category }}</span><span v-if="locked(w.id)" class="text-xs text-green-700"> · aprovado</span></span>
                        <template v-if="sheet[w.id]">
                            <input v-model="sheet[w.id]!.hours" inputmode="decimal" placeholder="horas" class="col-span-3 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-2" :disabled="locked(w.id)" :aria-label="`Horas de ${w.name}`" />
                            <input v-model="sheet[w.id]!.overtime" inputmode="decimal" placeholder="extra" class="col-span-3 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-2" :disabled="locked(w.id)" :aria-label="`Horas extra de ${w.name}`" />
                            <input v-model="sheet[w.id]!.description" placeholder="o que fez" class="col-span-6 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-4" :disabled="locked(w.id)" :aria-label="`Trabalho de ${w.name}`" />
                        </template>
                    </div>
                </div>
                <AppButton class="mt-3" type="submit" :loading="saving">Guardar folha</AppButton>
            </form>
        </AppCard>

        <AppCard v-if="showEquipment" title="Equipamentos na obra" description="As horas de equipamento entram no custo realizado (custo/hora do equipamento).">
            <p v-if="onSite.length" class="mb-3 text-sm text-slate-600">
                Na obra:
                <RouterLink v-for="(e, i) in onSite" :key="e.id" :to="`/app/equipamentos/${e.id}`" class="font-medium text-brand-700 hover:underline">{{ e.code }}{{ i < onSite.length - 1 ? ', ' : '' }}</RouterLink>
            </p>
            <form v-if="canUse" class="mb-4 grid gap-3 sm:grid-cols-5" novalidate @submit.prevent="addUsage">
                <AppSelect v-model="usage.data.equipment_id" class="sm:col-span-2" label="Equipamento" placeholder="Escolha" :options="equipmentOptions" :error="usage.errors.value.equipment_id" />
                <AppInput v-model="usage.data.used_on" type="date" label="Dia" :error="usage.errors.value.used_on" />
                <AppInput v-model="usage.data.hours" label="Horas" inputmode="decimal" :error="usage.errors.value.hours" />
                <AppInput v-model="usage.data.notes" label="Notas" />
                <div class="sm:col-span-5"><AppButton type="submit" size="sm" :loading="usage.processing.value">Registar horas do equipamento</AppButton></div>
            </form>
            <EmptyState v-if="!usages.length" title="Sem horas de equipamento" />
            <ul v-else class="divide-y divide-slate-100 text-sm">
                <li v-for="u in usages" :key="u.id" class="flex justify-between gap-2 py-2">
                    <span>{{ formatDate(u.used_on) }} · <strong>{{ u.equipment?.code }}</strong> {{ u.equipment?.name }} · {{ formatQty(u.hours, 1) }} h<template v-if="u.notes"> · {{ u.notes }}</template></span>
                    <span class="flex items-center gap-3 whitespace-nowrap">
                        {{ formatMoney(u.cost_cents) }}
                        <button v-if="canUse" type="button" class="text-xs text-red-700 hover:underline" @click="removeUsage(u)">Apagar</button>
                    </span>
                </li>
            </ul>
        </AppCard>
    </div>
</template>
