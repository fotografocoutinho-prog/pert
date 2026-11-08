<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@/api/http';
import { certificatesApi, formatQty, invoicingApi, parseNumber, type Certificate, type CertificateLine } from '@/api/economics';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';

const route = useRoute();
const router = useRouter();
const toast = useToastStore();
const projectId = computed(() => Number(route.params.id));
const cert = ref<Certificate | null>(null);
const error = ref<string | null>(null);
const drafts = reactive<Record<number, string>>({});

function show(c: Certificate): void {
    cert.value = c;
    for (const l of c.lines) drafts[l.id] = String(l.qty_period).replace('.', ',');
    document.title = `Auto n.º ${c.number} · ObraConnect`;
}
async function load(): Promise<void> {
    error.value = null;
    try {
        show(await certificatesApi.get(projectId.value, Number(route.params.certId)));
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível abrir o auto.';
    }
}
watch(() => route.params.certId, load, { immediate: true });

const backTo = computed(() => `/app/obras/${projectId.value}?separador=${cert.value?.kind === 'contract' ? 'compras' : 'medicoes'}`);
const fail = (e: unknown) => toast.error(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Não foi possível.');

async function saveLine(l: CertificateLine): Promise<void> {
    const qty = parseNumber(drafts[l.id]);
    if (qty === null || qty === l.qty_period || !cert.value) return;
    try {
        show(await certificatesApi.updateLine(projectId.value, cert.value.id, l.id, qty));
    } catch (e) {
        drafts[l.id] = String(l.qty_period).replace('.', ',');
        fail(e);
    }
}
async function act(action: 'submit' | 'approve' | 'reopen'): Promise<void> {
    const ask = { submit: 'Submeter o auto para aprovação?', approve: 'Aprovar o auto? As quantidades ficam fechadas e o valor passa a faturável.', reopen: 'Reabrir o auto para correção?' }[action];
    if (!cert.value || !window.confirm(ask)) return;
    try {
        show(await certificatesApi.act(projectId.value, cert.value.id, action));
        toast.success({ submit: 'Auto submetido.', approve: 'Auto aprovado.', reopen: 'Auto reaberto.' }[action]);
    } catch (e) {
        fail(e);
    }
}
async function remove(): Promise<void> {
    if (!cert.value || !window.confirm('Apagar este auto? As medições voltam a ficar por autar.')) return;
    try {
        await certificatesApi.remove(projectId.value, cert.value.id);
        router.push(backTo.value);
    } catch (e) {
        fail(e);
    }
}
const printPage = () => window.print();
const invoicing = ref(false);
async function createInvoice(): Promise<void> {
    if (!cert.value) return;
    invoicing.value = true;
    try {
        const d = await invoicingApi.create({ certificate_id: cert.value.id });
        router.push(`/app/faturacao/${d.id}`);
    } catch (e) {
        fail(e);
    } finally {
        invoicing.value = false;
    }
}
const statusColor = (s: string) => (s === 'approved' ? 'green' : s === 'submitted' ? 'blue' : 'amber');
</script>

<template>
    <div>
        <RouterLink :to="backTo" class="text-sm font-medium text-brand-700 hover:underline print:hidden">← Voltar à obra</RouterLink>
        <AppAlert v-if="error" type="error" class="mt-3">{{ error }}</AppAlert>
        <div v-else-if="!cert" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <template v-else>
            <div class="mt-2 mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold text-slate-500">{{ cert.kind === 'contract' ? `Subempreitada ${cert.contract?.code} · ${cert.contract?.partner ?? ''}` : 'Auto ao dono de obra' }}</p>
                    <h1 class="text-2xl font-bold text-slate-900">Auto de medição n.º {{ cert.number }}</h1>
                    <p class="text-sm text-slate-600">
                        Período de {{ formatDate(cert.period_start) }} a {{ formatDate(cert.period_end) }}
                        <template v-if="cert.approved_by"> · aprovado por {{ cert.approved_by }} a {{ formatDate(cert.approved_at) }}</template>
                    </p>
                </div>
                <AppBadge :color="statusColor(cert.status)">{{ cert.status_label }}</AppBadge>
            </div>

            <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <AppCard><p class="text-xs text-slate-500">Neste auto</p><p class="text-lg font-bold">{{ formatMoney(cert.amount_cents) }}</p></AppCard>
                <AppCard><p class="text-xs text-slate-500">Autos anteriores</p><p class="text-lg font-bold">{{ formatMoney(cert.previous_cents) }}</p></AppCard>
                <AppCard v-if="cert.kind === 'contract'"><p class="text-xs text-slate-500">Retenção (garantia)</p><p class="text-lg font-bold">{{ formatMoney(cert.retention_cents) }}</p></AppCard>
                <AppCard><p class="text-xs text-slate-500">{{ cert.kind === 'contract' ? 'A pagar' : 'Acumulado' }}</p><p class="text-lg font-bold">{{ formatMoney(cert.kind === 'contract' ? cert.net_cents : cert.previous_cents + cert.amount_cents) }}</p></AppCard>
            </div>

            <div class="mb-4 flex flex-wrap gap-2 print:hidden">
                <AppButton v-if="cert.can.submit" @click="act('submit')">Submeter</AppButton>
                <AppButton v-if="cert.can.approve" @click="act('approve')">Aprovar</AppButton>
                <AppButton v-if="cert.can.reopen" variant="secondary" @click="act('reopen')">Reabrir</AppButton>
                <AppButton v-if="cert.can.invoice" variant="secondary" :loading="invoicing" @click="createInvoice">Criar pré-fatura</AppButton>
                <RouterLink
                    v-if="cert.invoice_draft"
                    :to="`/app/faturacao/${cert.invoice_draft.id}`"
                    class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-brand-700 hover:bg-slate-50"
                >
                    Pré-fatura {{ cert.invoice_draft.code ?? '(rascunho)' }} · {{ cert.invoice_draft.official_number ? `fatura ${cert.invoice_draft.official_number}` : cert.invoice_draft.status_label }}
                </RouterLink>
                <AppButton variant="secondary" @click="printPage">Imprimir</AppButton>
                <AppButton v-if="cert.can.delete" variant="ghost" class="text-red-700" @click="remove">Apagar</AppButton>
            </div>
            <AppAlert v-if="cert.can.edit" class="mb-4 print:hidden">Pode corrigir a quantidade deste período em cada linha; o valor recalcula-se ao sair do campo.</AppAlert>

            <AppCard>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-slate-500">
                            <tr>
                                <th class="py-1.5 pr-2">Artigo</th>
                                <th class="py-1.5 pr-2">Un.</th>
                                <th class="py-1.5 pr-2 text-right">Contrato</th>
                                <th class="py-1.5 pr-2 text-right">Anterior</th>
                                <th class="py-1.5 pr-2 text-right">Este auto</th>
                                <th class="py-1.5 pr-2 text-right">Acumulado</th>
                                <th class="py-1.5 pr-2 text-right">Preço</th>
                                <th class="py-1.5 text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="l in cert.lines" :key="l.id" :class="l.over_contract ? 'bg-red-50' : ''">
                                <td class="py-1.5 pr-2"><span class="font-mono text-xs">{{ l.code }}</span> {{ l.description }}<span v-if="l.over_contract" class="block text-xs font-semibold text-red-700">Acima do contratado</span></td>
                                <td class="py-1.5 pr-2">{{ l.unit }}</td>
                                <td class="py-1.5 pr-2 text-right">{{ formatQty(l.qty_contract) }}</td>
                                <td class="py-1.5 pr-2 text-right">{{ formatQty(l.qty_previous) }}</td>
                                <td class="py-1.5 pr-2 text-right">
                                    <input
                                        v-if="cert.can.edit"
                                        v-model="drafts[l.id]"
                                        inputmode="decimal"
                                        class="w-24 rounded border border-slate-300 px-2 py-1 text-right text-sm"
                                        :aria-label="`Quantidade deste auto, ${l.description}`"
                                        @change="saveLine(l)"
                                    />
                                    <template v-else>{{ formatQty(l.qty_period) }}</template>
                                </td>
                                <td class="py-1.5 pr-2 text-right">{{ formatQty(l.qty_accumulated) }}</td>
                                <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ formatMoney(l.unit_price_cents) }}</td>
                                <td class="py-1.5 text-right font-medium whitespace-nowrap">{{ formatMoney(l.amount_cents) }}</td>
                            </tr>
                            <tr v-if="!cert.lines.length">
                                <td colspan="8" class="py-4 text-center text-slate-500">Sem linhas: não há medições por autar neste período.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="cert.notes" class="mt-3 text-sm text-slate-600">{{ cert.notes }}</p>
            </AppCard>
        </template>
    </div>
</template>
