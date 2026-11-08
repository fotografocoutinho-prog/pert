<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { clientsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { ClientOption } from '@/api/types';
import { centsToInput, eurosToCents, formatPct, formatQty, invoicingApi, parseNumber, type InvoiceDraft } from '@/api/economics';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const toast = useToastStore();
const draft = ref<InvoiceDraft | null>(null);
const error = ref<string | null>(null);
const clients = ref<ClientOption[]>([]);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

interface LineForm {
    code: string;
    description: string;
    unit: string;
    quantity: string;
    price: string;
    discount: string;
    vat: string;
}
const form = reactive({
    client_name: '',
    client_nif: '',
    client_address: '',
    client_postal_code: '',
    client_city: '',
    client_country: 'PT',
    client_email: '',
    issue_date: '',
    due_date: '',
    reference: '',
    vat_exemption_code: '',
    vat_exemption_reason: '',
    notes: '',
    lines: [] as LineForm[],
});
const num = (v: number) => String(v).replace('.', ',');
function show(d: InvoiceDraft): void {
    draft.value = d;
    Object.assign(form, {
        client_name: d.client_name,
        client_nif: d.client_nif ?? '',
        client_address: d.client_address ?? '',
        client_postal_code: d.client_postal_code ?? '',
        client_city: d.client_city ?? '',
        client_country: d.client_country ?? 'PT',
        client_email: d.client_email ?? '',
        issue_date: d.issue_date ?? '',
        due_date: d.due_date ?? '',
        reference: d.reference ?? '',
        vat_exemption_code: d.vat_exemption_code ?? '',
        vat_exemption_reason: d.vat_exemption_reason ?? '',
        notes: d.notes ?? '',
        lines: d.lines.map((l) => ({ code: l.code ?? '', description: l.description, unit: l.unit, quantity: num(l.quantity), price: centsToInput(l.unit_price_cents), discount: l.discount_pct ? num(l.discount_pct) : '', vat: num(l.vat_rate) })),
    });
    errors.value = {};
    document.title = `${d.code ?? 'Pré-fatura em rascunho'} · ObraConnect`;
}
async function load(): Promise<void> {
    error.value = null;
    try {
        show(await invoicingApi.get(Number(route.params.id)));
        if (draft.value?.can.edit && !clients.value.length && session.can('clients.view')) clients.value = (await clientsApi.options().catch(() => [])).filter((c) => !c.archived);
    } catch (e) {
        error.value = e instanceof ApiError && e.status === 404 ? 'Pré-fatura não encontrada.' : e instanceof ApiError ? e.message : 'Não foi possível abrir.';
    }
}
watch(() => route.params.id, load, { immediate: true });

const editable = computed(() => (draft.value?.can.edit ?? false) && !session.isReadOnly);
const fail = (e: unknown) => {
    if (e instanceof ApiError) {
        errors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0] ?? '']));
        toast.error(Object.values(e.errors)[0]?.[0] ?? e.message);
    } else toast.error('Não foi possível.');
};

// Valores das linhas (como o servidor: IVA arredondado linha a linha).
const lineNet = (l: LineForm) => Math.round((parseNumber(l.quantity) ?? 0) * (eurosToCents(l.price) ?? 0) * (1 - (parseNumber(l.discount) ?? 0) / 100));
const live = computed(() => {
    const byRate = new Map<number, { net: number; vat: number }>();
    for (const l of form.lines) {
        const rate = parseNumber(l.vat) ?? 0;
        const net = lineNet(l);
        const row = byRate.get(rate) ?? { net: 0, vat: 0 };
        row.net += net;
        row.vat += Math.round((net * rate) / 100);
        byRate.set(rate, row);
    }
    const rows = [...byRate.entries()].sort((a, b) => b[0] - a[0]).map(([rate, v]) => ({ rate, ...v }));
    const net = rows.reduce((s, r) => s + r.net, 0);
    const vat = rows.reduce((s, r) => s + r.vat, 0);
    return { rows, net, vat, total: net + vat, hasZero: rows.some((r) => r.rate === 0) };
});

function payload(): Record<string, unknown> {
    return {
        client_name: form.client_name,
        client_nif: form.client_nif || null,
        client_address: form.client_address || null,
        client_postal_code: form.client_postal_code || null,
        client_city: form.client_city || null,
        client_country: (form.client_country || 'PT').toUpperCase(),
        client_email: form.client_email || null,
        issue_date: form.issue_date || null,
        due_date: form.due_date || null,
        reference: form.reference || null,
        vat_exemption_code: form.vat_exemption_code || null,
        vat_exemption_reason: form.vat_exemption_reason || null,
        notes: form.notes || null,
        lines: form.lines.map((l) => ({
            code: l.code || null,
            description: l.description,
            unit: l.unit,
            quantity: parseNumber(l.quantity),
            unit_price_cents: eurosToCents(l.price) ?? 0,
            discount_pct: parseNumber(l.discount) ?? 0,
            vat_rate: parseNumber(l.vat) ?? 0,
        })),
    };
}
async function save(quiet = false): Promise<boolean> {
    if (!draft.value) return false;
    saving.value = true;
    try {
        show(await invoicingApi.update(draft.value.id, payload()));
        if (!quiet) toast.success('Pré-fatura guardada.');
        return true;
    } catch (e) {
        fail(e);
        return false;
    } finally {
        saving.value = false;
    }
}
async function pickClient(event: Event): Promise<void> {
    const id = Number((event.target as HTMLSelectElement).value);
    if (!id || !draft.value) return;
    try {
        show(await invoicingApi.update(draft.value.id, { client_id: id, client_name: null }));
    } catch (e) {
        fail(e);
    }
}
function addLine(): void {
    form.lines.push({ code: '', description: '', unit: 'un', quantity: '1', price: '', discount: '', vat: form.lines.at(-1)?.vat ?? '23' });
}

async function act(action: 'ready' | 'reopen' | 'cancel'): Promise<void> {
    if (!draft.value) return;
    const ask = {
        ready: 'Dar como pronta a faturar? Recebe o número interno e passa a sair na exportação.',
        reopen: 'Voltar a rascunho para corrigir? Mantém o número interno.',
        cancel: 'Anular esta pré-fatura?',
    }[action];
    if (!window.confirm(ask)) return;
    if (action === 'ready' && editable.value && !(await save(true))) return;
    try {
        show(await invoicingApi.act(draft.value.id, action, action === 'ready' && form.issue_date ? { issue_date: form.issue_date } : {}));
        toast.success({ ready: 'Pronta a faturar.', reopen: 'De volta a rascunho.', cancel: 'Anulada.' }[action]);
    } catch (e) {
        fail(e);
    }
}
async function remove(): Promise<void> {
    if (!draft.value || !window.confirm('Apagar este rascunho?')) return;
    try {
        await invoicingApi.remove(draft.value.id);
        router.push('/app/faturacao');
    } catch (e) {
        fail(e);
    }
}

const invoiceOpen = ref(false);
const official = reactive({ number: '', date: new Date().toISOString().slice(0, 10) });
async function registerInvoice(): Promise<void> {
    if (!draft.value) return;
    try {
        show(await invoicingApi.act(draft.value.id, 'invoice', { official_number: official.number, official_date: official.date }));
        invoiceOpen.value = false;
        toast.success('Fatura oficial registada.');
    } catch (e) {
        fail(e);
    }
}
const color = (s: string) => ({ draft: 'amber', ready: 'blue', invoiced: 'green', cancelled: 'gray' })[s] as 'amber' | 'blue' | 'green' | 'gray';
const backTo = computed(() => (draft.value?.certificate ? `/app/obras/${draft.value.certificate.project_id}/autos/${draft.value.certificate.id}` : null));
</script>

<template>
    <div>
        <RouterLink to="/app/faturacao" class="text-sm font-medium text-brand-700 hover:underline">← Faturação</RouterLink>
        <AppAlert v-if="error" type="error" class="mt-3">{{ error }}</AppAlert>
        <div v-else-if="!draft" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <template v-else>
            <div class="mt-2 mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold text-slate-500">PRÉ-FATURA · DOCUMENTO INTERNO SEM VALOR FISCAL</p>
                    <h1 class="text-2xl font-bold text-slate-900">{{ draft.code ?? 'Rascunho' }}</h1>
                    <p class="text-sm text-slate-600">
                        {{ draft.client_name }}
                        <template v-if="draft.project"> · {{ draft.project.code }} · {{ draft.project.name }}</template>
                        <template v-if="backTo"> · <RouterLink :to="backTo" class="text-brand-700 hover:underline">auto n.º {{ draft.certificate?.number }}</RouterLink></template>
                    </p>
                    <p v-if="draft.official_number" class="mt-1 text-sm font-semibold text-green-700">Fatura oficial {{ draft.official_number }} de {{ formatDate(draft.official_date) }}<template v-if="draft.invoiced_by"> (registada por {{ draft.invoiced_by }})</template></p>
                </div>
                <AppBadge :color="color(draft.status)">{{ draft.status_label }}</AppBadge>
            </div>

            <div class="mb-4 flex flex-wrap gap-2">
                <AppButton v-if="editable" :loading="saving" @click="save()">Guardar</AppButton>
                <AppButton v-if="draft.can.ready && !session.isReadOnly" variant="secondary" @click="act('ready')">Pronta a faturar</AppButton>
                <AppButton v-if="draft.can.invoice && !session.isReadOnly" @click="invoiceOpen = true">Registar fatura oficial</AppButton>
                <AppButton v-if="draft.can.reopen && !session.isReadOnly" variant="secondary" @click="act('reopen')">Voltar a rascunho</AppButton>
                <a :href="appUrl(invoicingApi.pdfUrl(draft.id))" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50">PDF</a>
                <a :href="appUrl(invoicingApi.exportUrl('xlsx', { ids: [draft.id] }))" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50">Exportar (Excel)</a>
                <AppButton v-if="draft.can.cancel && !session.isReadOnly" variant="ghost" class="text-red-700" @click="act('cancel')">Anular</AppButton>
                <AppButton v-if="draft.can.delete && !session.isReadOnly" variant="ghost" class="text-red-700" @click="remove">Apagar</AppButton>
            </div>
            <AppAlert v-if="draft.status === 'ready'" class="mb-4">Exporte-a para o programa de faturação certificado, emita lá a fatura e registe aqui o número dela.</AppAlert>

            <div class="grid gap-6 lg:grid-cols-3">
                <AppCard class="min-w-0 lg:col-span-2" title="Linhas">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs text-slate-500">
                                <tr>
                                    <th class="py-1.5 pr-1">Código</th>
                                    <th class="py-1.5 pr-1">Descrição</th>
                                    <th class="py-1.5 pr-1">Un.</th>
                                    <th class="py-1.5 pr-1 text-right">Quant.</th>
                                    <th class="py-1.5 pr-1 text-right">Preço (€)</th>
                                    <th class="py-1.5 pr-1 text-right">Desc. %</th>
                                    <th class="py-1.5 pr-1 text-right">IVA %</th>
                                    <th class="py-1.5 text-right">Valor</th>
                                    <th v-if="editable"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(l, i) in form.lines" :key="i">
                                    <template v-if="editable">
                                        <td class="py-1 pr-1"><input v-model="l.code" class="w-16 rounded border border-slate-300 px-1.5 py-1 text-sm" :aria-label="`Código ${i + 1}`" /></td>
                                        <td class="py-1 pr-1"><input v-model="l.description" class="w-56 rounded border border-slate-300 px-1.5 py-1 text-sm sm:w-full" :aria-label="`Descrição ${i + 1}`" /></td>
                                        <td class="py-1 pr-1"><input v-model="l.unit" class="w-12 rounded border border-slate-300 px-1.5 py-1 text-sm" :aria-label="`Unidade ${i + 1}`" /></td>
                                        <td class="py-1 pr-1"><input v-model="l.quantity" inputmode="decimal" class="w-20 rounded border border-slate-300 px-1.5 py-1 text-right text-sm" :aria-label="`Quantidade ${i + 1}`" /></td>
                                        <td class="py-1 pr-1"><input v-model="l.price" inputmode="decimal" class="w-24 rounded border border-slate-300 px-1.5 py-1 text-right text-sm" :aria-label="`Preço ${i + 1}`" /></td>
                                        <td class="py-1 pr-1"><input v-model="l.discount" inputmode="decimal" class="w-14 rounded border border-slate-300 px-1.5 py-1 text-right text-sm" :aria-label="`Desconto ${i + 1}`" /></td>
                                        <td class="py-1 pr-1"><input v-model="l.vat" inputmode="decimal" class="w-14 rounded border border-slate-300 px-1.5 py-1 text-right text-sm" :aria-label="`IVA ${i + 1}`" /></td>
                                        <td class="py-1 text-right whitespace-nowrap">{{ formatMoney(lineNet(l)) }}</td>
                                        <td class="py-1 pl-1"><button type="button" class="text-red-700" :aria-label="`Retirar linha ${i + 1}`" @click="form.lines.splice(i, 1)">✕</button></td>
                                    </template>
                                    <template v-else>
                                        <td class="py-1.5 pr-1 font-mono text-xs">{{ l.code }}</td>
                                        <td class="py-1.5 pr-1">{{ l.description }}</td>
                                        <td class="py-1.5 pr-1">{{ l.unit }}</td>
                                        <td class="py-1.5 pr-1 text-right">{{ formatQty(parseNumber(l.quantity)) }}</td>
                                        <td class="py-1.5 pr-1 text-right whitespace-nowrap">{{ formatMoney(eurosToCents(l.price)) }}</td>
                                        <td class="py-1.5 pr-1 text-right">{{ l.discount ? formatPct(parseNumber(l.discount), 2) : '' }}</td>
                                        <td class="py-1.5 pr-1 text-right">{{ formatPct(parseNumber(l.vat), 2) }}</td>
                                        <td class="py-1.5 text-right whitespace-nowrap">{{ formatMoney(lineNet(l)) }}</td>
                                    </template>
                                </tr>
                                <tr v-if="!form.lines.length"><td colspan="9" class="py-4 text-center text-slate-500">Sem linhas.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <button v-if="editable" type="button" class="mt-2 text-sm font-semibold text-brand-700 hover:underline" @click="addLine">+ Linha</button>
                    <p v-if="errors.lines" class="mt-1 text-sm text-red-700">{{ errors.lines }}</p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <table class="text-sm">
                            <thead class="text-left text-xs text-slate-500">
                                <tr><th>Taxa</th><th class="text-right">Incidência</th><th class="text-right">IVA</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in live.rows" :key="r.rate"><td>{{ formatPct(r.rate, 2) }}</td><td class="text-right">{{ formatMoney(r.net) }}</td><td class="text-right">{{ formatMoney(r.vat) }}</td></tr>
                            </tbody>
                        </table>
                        <div class="space-y-1 text-sm sm:text-right">
                            <p>Total sem IVA: <strong>{{ formatMoney(live.net) }}</strong></p>
                            <p>IVA: <strong>{{ formatMoney(live.vat) }}</strong></p>
                            <p class="text-lg">Total: <strong>{{ formatMoney(live.total) }}</strong></p>
                        </div>
                    </div>
                </AppCard>

                <div class="min-w-0 space-y-6">
                    <AppCard title="Cliente">
                        <div class="space-y-3">
                            <div v-if="editable && clients.length">
                                <label class="mb-1 block text-sm font-medium text-slate-700" for="pick-client">Copiar de um cliente</label>
                                <select id="pick-client" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm" @change="pickClient">
                                    <option value="">Escolha…</option>
                                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                            <AppInput v-model="form.client_name" label="Nome" :disabled="!editable" :error="errors.client_name" />
                            <div class="grid grid-cols-2 gap-3">
                                <AppInput v-model="form.client_nif" label="NIF" :disabled="!editable" :error="errors.client_nif" />
                                <AppInput v-model="form.client_country" label="País" :maxlength="2" :disabled="!editable" :error="errors.client_country" />
                            </div>
                            <AppInput v-model="form.client_address" label="Morada" :disabled="!editable" />
                            <div class="grid grid-cols-2 gap-3">
                                <AppInput v-model="form.client_postal_code" label="Código postal" :disabled="!editable" />
                                <AppInput v-model="form.client_city" label="Localidade" :disabled="!editable" />
                            </div>
                            <AppInput v-model="form.client_email" type="email" label="Email" :disabled="!editable" :error="errors.client_email" />
                        </div>
                    </AppCard>
                    <AppCard title="Documento">
                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <AppInput v-model="form.issue_date" type="date" label="Data" hint="Vazio = no dia em que fica pronta." :disabled="!editable" :error="errors.issue_date" />
                                <AppInput v-model="form.due_date" type="date" label="Vencimento" :disabled="!editable" :error="errors.due_date" />
                            </div>
                            <AppInput v-model="form.reference" label="Referência" :disabled="!editable" />
                            <AppAlert v-if="editable && live.hasZero && !form.vat_exemption_reason" type="warning">Há linhas sem IVA: escreva o motivo da isenção (ou a menção de autoliquidação).</AppAlert>
                            <div class="grid grid-cols-3 gap-3">
                                <AppInput v-model="form.vat_exemption_code" label="Código" placeholder="M…" :disabled="!editable" />
                                <AppInput v-model="form.vat_exemption_reason" class="col-span-2" label="Motivo de isenção de IVA" :disabled="!editable" />
                            </div>
                            <AppTextarea v-model="form.notes" label="Notas" :rows="3" :disabled="!editable" />
                        </div>
                    </AppCard>
                </div>
            </div>
        </template>

        <AppModal :open="invoiceOpen" title="Registar a fatura oficial" @close="invoiceOpen = false">
            <form id="official-form" class="space-y-4" novalidate @submit.prevent="registerInvoice">
                <p class="text-sm text-slate-600">O número e a data da fatura emitida no programa de faturação certificado.</p>
                <AppInput v-model="official.number" label="N.º da fatura" placeholder="FT A/2026/57" required :error="errors.official_number" />
                <AppInput v-model="official.date" type="date" label="Data da fatura" :error="errors.official_date" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="invoiceOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="official-form">Registar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
