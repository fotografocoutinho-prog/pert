<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ApiError } from '@/api/http';
import { portalApi, type PortalContract } from '@/api/portal';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';

const route = useRoute();
const toast = useToastStore();
const access = String(route.params.access);
const id = String(route.params.contract);
const contract = ref<PortalContract | null>(null);
const error = ref<string | null>(null);

const today = new Date().toISOString().slice(0, 10);
const monthStart = `${today.slice(0, 8)}01`;
const form = reactive<{ period_start: string; period_end: string; notes: string; qty: Record<number, string> }>({ period_start: monthStart, period_end: today, notes: '', qty: {} });
const proposing = ref(false);
const formError = ref<string | null>(null);
const showForm = ref(false);

async function load(): Promise<void> {
    try {
        contract.value = await portalApi.contract(access, id);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível abrir o contrato.';
    }
}
onMounted(load);

const num = (v: number) => v.toLocaleString('pt-PT', { maximumFractionDigits: 3 });
const parsed = (v: string | undefined) => {
    const n = Number(String(v ?? '').replace(',', '.'));
    return Number.isFinite(n) && n > 0 ? n : 0;
};
const proposalTotal = computed(() => (contract.value?.items ?? []).reduce((sum, i) => sum + Math.round(parsed(form.qty[i.id]) * i.unit_price_cents), 0));
const statusColor = (s: string) => (s === 'approved' ? 'green' : s === 'submitted' ? 'amber' : 'gray');

async function propose(): Promise<void> {
    if (!contract.value) return;
    proposing.value = true;
    formError.value = null;
    try {
        contract.value = await portalApi.propose(access, id, {
            period_start: form.period_start,
            period_end: form.period_end,
            notes: form.notes || null,
            lines: contract.value.items.map((i) => ({ contract_item_id: i.id, qty_period: parsed(form.qty[i.id]) })),
        });
        toast.success('Auto proposto. A empresa vai aprová-lo ou devolvê-lo; recebe um email com a decisão.');
        showForm.value = false;
        form.qty = {};
        form.notes = '';
    } catch (e) {
        formError.value = e instanceof ApiError ? (e.firstError('period_end') ?? e.message) : 'Não foi possível propor o auto.';
    } finally {
        proposing.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <RouterLink :to="{ path: '/portal', query: { acesso: access } }" class="text-sm font-medium text-brand-700 hover:underline">← Contratos</RouterLink>
        <AppAlert v-if="error" type="error">{{ error }}</AppAlert>
        <div v-else-if="!contract" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
        <template v-else>
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">{{ contract.code }} · {{ contract.title }}</h1>
                    <p class="text-sm text-slate-600">Obra {{ contract.project.code }} · {{ contract.project.name }}</p>
                </div>
                <AppBadge :color="contract.status === 'active' ? 'green' : 'gray'">{{ contract.status_label }}</AppBadge>
            </div>

            <div class="grid gap-3 sm:grid-cols-4">
                <AppCard title="Valor do contrato"><p class="text-lg font-bold">{{ formatMoney(contract.value_cents) }}</p></AppCard>
                <AppCard title="Autado e aprovado"><p class="text-lg font-bold">{{ formatMoney(contract.certified_cents) }}</p></AppCard>
                <AppCard title="Retido"><p class="text-lg font-bold">{{ formatMoney(contract.retained_cents) }}</p><p class="text-xs text-slate-500">{{ contract.retention_pct }} % de retenção</p></AppCard>
                <AppCard title="Prazo"><p class="text-sm font-semibold">{{ contract.starts_on ? formatDate(contract.starts_on) : '—' }} a {{ contract.ends_on ? formatDate(contract.ends_on) : '—' }}</p></AppCard>
            </div>

            <AppCard title="Artigos do contrato" class="min-w-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-slate-500">
                            <tr>
                                <th class="py-1.5 pr-2">Artigo</th>
                                <th class="py-1.5 pr-2 text-right">Contrato</th>
                                <th class="py-1.5 pr-2 text-right">Autado</th>
                                <th class="py-1.5 pr-2 text-right">Falta</th>
                                <th class="py-1.5 text-right">Preço</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="i in contract.items" :key="i.id">
                                <td class="py-1.5 pr-2">{{ i.description }}</td>
                                <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ num(i.quantity) }} {{ i.unit }}</td>
                                <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ num(i.certified_qty) }}</td>
                                <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ num(i.remaining_qty) }}</td>
                                <td class="py-1.5 text-right whitespace-nowrap">{{ formatMoney(i.unit_price_cents) }}/{{ i.unit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard title="Propor um auto" class="min-w-0">
                <p v-if="!contract.can_propose" class="text-sm text-slate-600">
                    <template v-if="contract.status !== 'active'">O contrato está fechado.</template>
                    <template v-else-if="contract.pending_certificate && !contract.certificates.some((c) => c.number === contract!.pending_certificate!.number)">
                        A empresa está a preparar o auto n.º {{ contract.pending_certificate.number }} deste contrato. Quando ficar decidido, pode propor o seguinte.
                    </template>
                    <template v-else>Há um auto à espera da decisão da empresa. Quando for aprovado (ou devolvido), pode propor o seguinte.</template>
                </p>
                <div v-else-if="!showForm">
                    <p class="text-sm text-slate-600">Indique o que fez no período, por artigo. A empresa aprova ou devolve o auto; recebe um email com a decisão.</p>
                    <AppButton class="mt-3" @click="showForm = true">Propor o auto do período</AppButton>
                </div>
                <form v-else class="space-y-3" data-test="proposal" @submit.prevent="propose">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">
                            <span class="font-medium text-slate-700">De</span>
                            <input v-model="form.period_start" type="date" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" />
                        </label>
                        <label class="text-sm">
                            <span class="font-medium text-slate-700">Até</span>
                            <input v-model="form.period_end" type="date" required :max="today" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" />
                        </label>
                    </div>
                    <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                        <li v-for="i in contract.items" :key="i.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                            <span class="min-w-0 flex-1">{{ i.description }} <span class="block text-xs text-slate-500">falta {{ num(i.remaining_qty) }} {{ i.unit }}</span></span>
                            <span class="flex items-center gap-1.5">
                                <input
                                    v-model="form.qty[i.id]"
                                    inputmode="decimal"
                                    :aria-label="`Quantidade feita no período: ${i.description}`"
                                    :disabled="i.remaining_qty <= 0"
                                    placeholder="0"
                                    class="w-24 rounded-md border border-slate-300 px-2 py-1 text-right disabled:bg-slate-50"
                                />
                                <span class="w-8 text-slate-500">{{ i.unit }}</span>
                            </span>
                        </li>
                    </ul>
                    <label class="block text-sm">
                        <span class="font-medium text-slate-700">Notas (opcional)</span>
                        <textarea v-model="form.notes" rows="2" maxlength="2000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" />
                    </label>
                    <p class="text-sm text-slate-700">Valor proposto: <strong>{{ formatMoney(proposalTotal) }}</strong> (sem IVA, antes da retenção de {{ contract.retention_pct }} %)</p>
                    <AppAlert v-if="formError" type="error">{{ formError }}</AppAlert>
                    <div class="flex flex-wrap gap-2">
                        <AppButton type="submit" :loading="proposing" :disabled="proposalTotal <= 0">Enviar à empresa</AppButton>
                        <AppButton type="button" variant="ghost" @click="showForm = false">Cancelar</AppButton>
                    </div>
                </form>
            </AppCard>

            <AppCard title="Autos" class="min-w-0">
                <p v-if="!contract.certificates.length" class="text-sm text-slate-600">Ainda não há autos deste contrato.</p>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="c in contract.certificates" :key="c.id" class="py-2.5 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-semibold">Auto n.º {{ c.number }} <span class="font-normal text-slate-500">· {{ formatDate(c.period_start) }} a {{ formatDate(c.period_end) }}</span></p>
                            <span class="flex items-center gap-2">
                                <span class="whitespace-nowrap">{{ formatMoney(c.amount_cents) }}</span>
                                <AppBadge :color="statusColor(c.status)">{{ c.status === 'draft' ? 'Na empresa, a rever' : c.status_label }}</AppBadge>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500">
                            {{ c.proposed_here ? 'Proposto no portal' : 'Feito pela empresa' }} · retenção {{ formatMoney(c.retention_cents) }} · a receber {{ formatMoney(c.net_cents) }}
                            <template v-if="c.approved_at"> · aprovado a {{ formatDate(c.approved_at) }}</template>
                        </p>
                    </li>
                </ul>
            </AppCard>
        </template>
    </div>
</template>
