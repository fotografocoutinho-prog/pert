<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { Project } from '@/api/types';
import { ApiError } from '@/api/http';
import { centsToInput, costsApi, eurosToCents, formatPct, type CostReport } from '@/api/economics';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';

const props = defineProps<{ project: Project }>();
const session = useSessionStore();
const toast = useToastStore();
const report = ref<CostReport | null>(null);
const canManage = computed(() => (report.value?.can_manage ?? false) && !session.isReadOnly);

onMounted(async () => {
    report.value = await costsApi.get(props.project.id);
});

const money = (c: number | null | undefined) => (c === null || c === undefined ? '—' : formatMoney(c));
const alert = computed(() => {
    const a = report.value?.alert;
    if (a === 'over') return { color: 'red' as const, label: 'Acima do teto', text: 'O realizado mais o comprometido já ultrapassa o teto da obra.' };
    if (a === 'warning') return { color: 'amber' as const, label: 'Perto do teto', text: 'O realizado mais o comprometido já passa 90% do teto.' };
    if (a === 'ok') return { color: 'green' as const, label: 'Dentro do teto', text: null };
    return { color: 'gray' as const, label: 'Sem teto', text: null };
});
const barMax = computed(() => Math.max(1, ...(report.value?.by_category ?? []).map((c) => Math.max(c.planned_cents, c.forecast_cents))));
const monthMax = computed(() => Math.max(1, ...(report.value?.monthly_actual ?? []).map((m) => m.actual_cents)));
const monthLabel = (m: string) => {
    const [y, mm] = m.split('-');
    return `${['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][Number(mm) - 1]} ${y?.slice(2)}`;
};

// ---- Teto ----
const editingCeiling = ref(false);
const ceiling = ref('');
function editCeiling(): void {
    ceiling.value = report.value?.ceiling_is_custom ? centsToInput(report.value.ceiling_cents) : '';
    editingCeiling.value = true;
}
async function saveCeiling(): Promise<void> {
    try {
        report.value = await costsApi.setCeiling(props.project.id, eurosToCents(ceiling.value));
        editingCeiling.value = false;
        toast.success('Teto guardado.');
    } catch (e) {
        toast.error(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Não foi possível.');
    }
}

// ---- Lançamentos manuais ----
const entry = useForm({ category: 'other', kind: 'actual', description: '', amount: '', incurred_on: new Date().toISOString().slice(0, 10) });
async function addEntry(): Promise<void> {
    const saved = await entry.submit(
        () =>
            costsApi.addEntry(props.project.id, {
                category: entry.data.category,
                kind: entry.data.kind,
                description: entry.data.description,
                amount_cents: eurosToCents(entry.data.amount),
                incurred_on: entry.data.incurred_on,
            }),
        { success: 'Lançamento registado.' },
    );
    if (saved) {
        report.value = saved;
        entry.reset({ category: entry.data.category, kind: entry.data.kind, incurred_on: entry.data.incurred_on });
    }
}
async function removeEntry(id: number): Promise<void> {
    if (!window.confirm('Apagar este lançamento?')) return;
    report.value = await costsApi.removeEntry(props.project.id, id);
}
</script>

<template>
    <div v-if="!report" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
    <div v-else class="space-y-6">
        <AppAlert v-if="alert.text" :type="report.alert === 'over' ? 'error' : 'warning'">{{ alert.text }}</AppAlert>
        <AppAlert v-if="!report.budget">Sem orçamento aprovado: o custo previsto fica a zero até aprovar um orçamento.</AppAlert>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <AppCard><p class="text-xs text-slate-500">Previsto (orçamento)</p><p class="text-lg font-bold">{{ money(report.planned_cents) }}</p></AppCard>
            <AppCard><p class="text-xs text-slate-500">Comprometido</p><p class="text-lg font-bold">{{ money(report.committed_cents) }}</p><p class="text-xs text-slate-500">encomendas e contratos por faturar</p></AppCard>
            <AppCard><p class="text-xs text-slate-500">Realizado</p><p class="text-lg font-bold">{{ money(report.actual_cents) }}</p></AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Realizado + comprometido</p>
                <p class="text-lg font-bold">{{ money(report.forecast_cents) }}</p>
                <RouterLink :to="{ query: { separador: 'previsoes' } }" class="text-xs font-semibold text-brand-700 hover:underline">Previsão no fim da obra →</RouterLink>
            </AppCard>
            <AppCard>
                <div class="flex items-start justify-between gap-1">
                    <p class="text-xs text-slate-500">Teto</p>
                    <AppBadge :color="alert.color">{{ alert.label }}</AppBadge>
                </div>
                <template v-if="!editingCeiling">
                    <p class="text-lg font-bold">{{ money(report.ceiling_cents) }}</p>
                    <p class="text-xs text-slate-500">
                        <template v-if="report.ceiling_used_pct !== null">{{ formatPct(report.ceiling_used_pct) }} usado · </template>{{ report.ceiling_is_custom ? 'definido à mão' : 'custo do orçamento' }}
                    </p>
                    <button v-if="canManage" type="button" class="mt-1 text-xs font-semibold text-brand-700 hover:underline" @click="editCeiling">Alterar teto</button>
                </template>
                <form v-else class="mt-1 space-y-2" novalidate @submit.prevent="saveCeiling">
                    <AppInput v-model="ceiling" aria-label="Teto em euros" inputmode="decimal" placeholder="vazio = custo do orçamento" />
                    <div class="flex gap-1">
                        <AppButton size="sm" type="submit">Guardar</AppButton>
                        <AppButton size="sm" variant="ghost" @click="editingCeiling = false">Cancelar</AppButton>
                    </div>
                </form>
            </AppCard>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <AppCard><p class="text-xs text-slate-500">Valor do contrato (venda)</p><p class="text-lg font-bold">{{ money(report.revenue.contract_cents) }}</p></AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Autado ao dono de obra</p>
                <p class="text-lg font-bold">{{ money(report.revenue.certified_cents) }}</p>
                <p v-if="report.revenue.certified_pct !== null" class="text-xs text-slate-500">{{ formatPct(report.revenue.certified_pct) }} do contrato</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Venda menos realizado e comprometido</p>
                <p class="text-lg font-bold" :class="(report.margin_forecast_cents ?? 0) < 0 ? 'text-red-700' : 'text-green-700'">{{ money(report.margin_forecast_cents) }}</p>
            </AppCard>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <AppCard title="Por natureza">
                <ul class="space-y-3 text-sm">
                    <li v-for="c in report.by_category" :key="c.category">
                        <div class="flex flex-wrap justify-between gap-x-2">
                            <span class="font-medium">{{ c.label }}</span>
                            <span class="text-xs text-slate-600">previsto {{ money(c.planned_cents) }} · real {{ money(c.actual_cents) }} · comp. {{ money(c.committed_cents) }}</span>
                        </div>
                        <div class="relative mt-1 h-2 rounded-full bg-slate-100" :title="`Realizado + comprometido ${money(c.forecast_cents)}`">
                            <div class="absolute h-2 rounded-full bg-slate-300" :style="{ width: `${(c.planned_cents * 100) / barMax}%` }" />
                            <div class="absolute h-2 rounded-full" :class="c.forecast_cents > c.planned_cents ? 'bg-red-500' : 'bg-brand-600'" :style="{ width: `${(c.forecast_cents * 100) / barMax}%`, opacity: 0.85 }" />
                        </div>
                    </li>
                </ul>
                <p class="mt-3 text-xs text-slate-500">Cinzento: previsto. Cor: previsão final (vermelho quando passa o previsto).</p>
            </AppCard>

            <AppCard title="Custo realizado por mês">
                <p v-if="!report.monthly_actual.length" class="text-sm text-slate-500">Ainda sem custos realizados.</p>
                <div v-else class="flex h-40 items-end gap-1.5" role="img" :aria-label="`Custo por mês: ${report.monthly_actual.map((m) => `${monthLabel(m.month)} ${money(m.actual_cents)}`).join(', ')}`">
                    <div v-for="m in report.monthly_actual" :key="m.month" class="flex flex-1 flex-col items-center justify-end gap-1" :title="`${monthLabel(m.month)}: ${money(m.actual_cents)}`">
                        <div class="w-full rounded-t bg-brand-600" :style="{ height: `${Math.max(2, (m.actual_cents * 100) / monthMax)}%` }" />
                        <span class="text-[10px] text-slate-500">{{ monthLabel(m.month) }}</span>
                    </div>
                </div>
            </AppCard>
        </div>

        <AppCard v-if="report.by_cost_center.length" title="Por centro de custo">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-slate-500">
                    <tr>
                        <th class="py-1.5">Centro de custo</th>
                        <th class="py-1.5 text-right">Comprometido</th>
                        <th class="py-1.5 text-right">Realizado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="c in report.by_cost_center" :key="c.cost_center_id ?? 0">
                        <td class="py-1.5">{{ c.label }}</td>
                        <td class="py-1.5 text-right">{{ money(c.committed_cents) }}</td>
                        <td class="py-1.5 text-right">{{ money(c.actual_cents) }}</td>
                    </tr>
                </tbody>
            </table>
        </AppCard>

        <AppCard title="Lançamentos" description="Custos que não vêm de compras, autos, horas ou equipamentos (taxas, licenças, seguros, ajustes).">
            <div class="mb-3 flex justify-end">
                <a :href="appUrl(costsApi.exportUrl(project.id))" class="text-sm font-semibold text-brand-700 hover:underline">Exportar mapa de custos (CSV)</a>
            </div>
            <form v-if="canManage" class="mb-4 grid gap-3 sm:grid-cols-6" novalidate @submit.prevent="addEntry">
                <AppSelect v-model="entry.data.category" label="Natureza" :options="report.categories" />
                <AppSelect
                    v-model="entry.data.kind"
                    label="Tipo"
                    :options="[
                        { value: 'actual', label: 'Realizado' },
                        { value: 'committed', label: 'Comprometido' },
                    ]"
                />
                <AppInput v-model="entry.data.description" class="sm:col-span-2" label="Descrição" :error="entry.errors.value.description" />
                <AppInput v-model="entry.data.amount" label="Valor (€)" inputmode="decimal" :error="entry.errors.value.amount_cents" />
                <AppInput v-model="entry.data.incurred_on" type="date" label="Data" :error="entry.errors.value.incurred_on" />
                <div class="sm:col-span-6"><AppButton type="submit" size="sm" :loading="entry.processing.value">Lançar</AppButton></div>
            </form>
            <p v-if="!report.entries.length" class="text-sm text-slate-500">Sem lançamentos manuais.</p>
            <ul v-else class="divide-y divide-slate-100 text-sm">
                <li v-for="e in report.entries" :key="e.id" class="flex flex-wrap justify-between gap-2 py-2">
                    <span>
                        {{ formatDate(e.incurred_on) }} · {{ e.description }}
                        <span class="block text-xs text-slate-500">{{ e.category_label }} · {{ e.kind === 'actual' ? 'realizado' : 'comprometido' }}<template v-if="e.created_by"> · {{ e.created_by }}</template></span>
                    </span>
                    <span class="flex items-center gap-3 whitespace-nowrap">
                        {{ money(e.amount_cents) }}
                        <RouterLink v-if="e.supplier_document_id" :to="`/app/faturas-fornecedores/${e.supplier_document_id}`" class="text-xs text-brand-700 hover:underline">Ver a fatura</RouterLink>
                        <button v-else-if="canManage" type="button" class="text-xs text-red-700 hover:underline" @click="removeEntry(e.id)">Apagar</button>
                    </span>
                </li>
            </ul>
        </AppCard>
    </div>
</template>
