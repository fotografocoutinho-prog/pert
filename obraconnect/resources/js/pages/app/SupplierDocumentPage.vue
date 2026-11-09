<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { projectsApi } from '@/api';
import { ApiError } from '@/api/http';
import { centsToInput, eurosToCents, formatPct, formatQty, partnersApi, supplierDocsApi, type Partner, type SupplierDoc } from '@/api/economics';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatDateTime, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const toast = useToastStore();

const doc = ref<SupplierDoc | null>(null);
const error = ref<string | null>(null);
const errors = ref<Record<string, string>>({});
const saving = ref(false);
const projects = ref<{ value: number; label: string }[]>([]);
const partners = ref<Partner[]>([]);

const form = reactive({
    kind: 'invoice',
    category: 'material',
    project_id: null as number | null,
    partner_id: null as number | null,
    purchase_order_id: null as number | null,
    supplier_name: '',
    supplier_nif: '',
    customer_nif: '',
    number: '',
    atcud: '',
    issue_date: '',
    due_date: '',
    order_reference: '',
    net: '',
    vat: '',
    gross: '',
    notes: '',
});

function show(d: SupplierDoc): void {
    doc.value = d;
    Object.assign(form, {
        kind: d.kind,
        category: d.category,
        project_id: d.project_id,
        partner_id: d.partner_id,
        purchase_order_id: d.purchase_order_id,
        supplier_name: d.supplier_name ?? '',
        supplier_nif: d.supplier_nif ?? '',
        customer_nif: d.customer_nif ?? '',
        number: d.number ?? '',
        atcud: d.atcud ?? '',
        issue_date: d.issue_date ?? '',
        due_date: d.due_date ?? '',
        order_reference: d.order_reference ?? '',
        net: centsToInput(d.net_cents),
        vat: centsToInput(d.vat_cents),
        gross: centsToInput(d.gross_cents),
        notes: d.notes ?? '',
    });
    errors.value = {};
    document.title = `${d.supplier ?? 'Documento de fornecedor'}${d.number ? ` · ${d.number}` : ''} · ObraConnect`;
    schedulePoll();
}

async function load(): Promise<void> {
    error.value = null;
    try {
        show(await supplierDocsApi.get(Number(route.params.id)));
        if (doc.value?.can.edit && !projects.value.length) {
            const [page, list] = await Promise.all([projectsApi.list({ per_page: 100 }).catch(() => null), partnersApi.list().catch(() => [] as Partner[])]);
            projects.value = (page?.data ?? []).filter((p) => p.status !== 'archived').map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }));
            partners.value = list.filter((p) => !p.archived);
        }
    } catch (e) {
        error.value = e instanceof ApiError && e.status === 404 ? 'Documento não encontrado.' : e instanceof ApiError ? e.message : 'Não foi possível abrir.';
    }
}
watch(() => route.params.id, load, { immediate: true });

// Enquanto o DocIntel lê, pergunta de novo de 4 em 4 segundos (até 5 minutos).
let pollTimer: number | undefined;
let polls = 0;
function schedulePoll(): void {
    window.clearTimeout(pollTimer);
    if (doc.value?.status === 'reading' && polls < 75) {
        polls++;
        pollTimer = window.setTimeout(async () => {
            try {
                const fresh = await supplierDocsApi.get(doc.value!.id);
                if (fresh.status !== 'reading') toast.success('Documento lido: confira os dados.');
                show(fresh);
            } catch {
                schedulePoll();
            }
        }, 4000);
    } else polls = 0;
}
onBeforeUnmount(() => window.clearTimeout(pollTimer));

const editable = computed(() => (doc.value?.can.edit ?? false) && !session.isReadOnly);
const readOnly = computed(() => session.isReadOnly);
const fail = (e: unknown) => {
    if (e instanceof ApiError) {
        errors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0] ?? '']));
        toast.error(Object.values(e.errors)[0]?.[0] ?? e.message);
    } else toast.error('Não foi possível.');
};

function payload(): Record<string, unknown> {
    return {
        kind: form.kind,
        category: form.category,
        project_id: form.project_id,
        partner_id: form.partner_id,
        purchase_order_id: form.purchase_order_id,
        supplier_name: form.supplier_name || null,
        supplier_nif: form.supplier_nif || null,
        customer_nif: form.customer_nif || null,
        number: form.number || null,
        atcud: form.atcud || null,
        issue_date: form.issue_date || null,
        due_date: form.due_date || null,
        order_reference: form.order_reference || null,
        net_cents: eurosToCents(form.net),
        vat_cents: eurosToCents(form.vat),
        gross_cents: eurosToCents(form.gross),
        notes: form.notes || null,
    };
}
async function save(quiet = false): Promise<boolean> {
    if (!doc.value) return false;
    saving.value = true;
    try {
        show(await supplierDocsApi.update(doc.value.id, payload()));
        if (!quiet) toast.success('Guardado.');
        return true;
    } catch (e) {
        fail(e);
        return false;
    } finally {
        saving.value = false;
    }
}
// Mudar de fornecedor ou de obra muda as compras sugeridas: guarda logo para as ver.
async function pickPartner(value: string | number | null | undefined): Promise<void> {
    form.partner_id = Number(value) || null;
    form.purchase_order_id = null;
    await save(true);
}
async function pickProject(value: string | number | null | undefined): Promise<void> {
    form.project_id = Number(value) || null;
    form.purchase_order_id = null;
    await save(true);
}

async function act(action: 'post' | 'unpost' | 'reject' | 'reopen' | 'read'): Promise<void> {
    if (!doc.value) return;
    let extra: Record<string, unknown> = {};
    if (action === 'reject') {
        const reason = window.prompt('Porque é rejeitado? (por exemplo: duplicado, não é para esta empresa)');
        if (reason === null) return;
        extra = { reason: reason.trim() || null };
    } else if (action === 'post' && !window.confirm(`${outcome.value} Lançar?`)) return;
    else if (action === 'unpost' && !window.confirm('Desfazer o lançamento? O custo é retirado ou a compra volta ao estado anterior.')) return;
    if ((action === 'post' || action === 'read') && editable.value && !(await save(true))) return;
    try {
        show(await supplierDocsApi.act(doc.value.id, action, extra));
        toast.success({ post: 'Lançado.', unpost: 'Lançamento desfeito.', reject: 'Rejeitado.', reopen: 'Reaberto.', read: 'A ler de novo…' }[action]);
    } catch (e) {
        fail(e);
    }
}
async function remove(): Promise<void> {
    if (!doc.value || !window.confirm('Apagar este documento e o ficheiro?')) return;
    try {
        await supplierDocsApi.remove(doc.value.id);
        router.push('/app/faturas-fornecedores');
    } catch (e) {
        fail(e);
    }
}
async function createSupplier(): Promise<void> {
    if (!form.supplier_name.trim()) {
        toast.error('Escreva o nome do fornecedor.');
        return;
    }
    try {
        const partner = await partnersApi.create({ kind: 'supplier', name: form.supplier_name.trim(), nif: form.supplier_nif || null });
        partners.value = [...partners.value, partner];
        form.partner_id = partner.id;
        await save(true);
        toast.success('Fornecedor criado.');
    } catch (e) {
        fail(e);
    }
}

const isCost = computed(() => ['invoice', 'credit_note', 'receipt'].includes(form.kind));
const netForPost = computed(() => {
    const net = eurosToCents(form.net);
    if (net !== null) return net;
    const gross = eurosToCents(form.gross);
    const vat = eurosToCents(form.vat);
    return gross !== null && vat !== null ? gross - vat : null;
});
const purchaseOptions = computed(() => {
    const options = (doc.value?.suggestions.purchase_orders ?? []).map((o) => ({
        value: o.id,
        label: `Compra n.º ${o.number} · ${o.project.code} · ${formatMoney(o.total_cents)} · ${o.status_label}${o.reason ? ' · sugerida' : ''}`,
    }));
    const current = doc.value?.purchase_order;
    if (current && !options.some((o) => o.value === current.id)) options.unshift({ value: current.id, label: `Compra n.º ${current.number} · ${formatMoney(current.total_cents)} · ${current.status_label}` });
    return [{ value: 0, label: form.kind === 'delivery_note' ? 'Sem compra (só arquivar a guia)' : 'Sem compra (lançar como custo da obra)' }, ...options];
});
const purchaseModel = computed({
    get: () => form.purchase_order_id ?? 0,
    set: (v: number | string | null) => (form.purchase_order_id = Number(v) || null),
});
const chosenOrder = computed(() => doc.value?.suggestions.purchase_orders.find((o) => o.id === form.purchase_order_id) ?? doc.value?.purchase_order ?? null);
const categoryLabel = computed(() => doc.value?.options.categories.find((c) => c.value === form.category)?.label.toLowerCase() ?? '');
const outcome = computed(() => {
    const order = form.purchase_order_id ? chosenOrder.value : null;
    const net = formatMoney(netForPost.value);
    if (form.kind === 'delivery_note') return order ? `A compra n.º ${order.number} fica recebida.` : 'A guia fica registada na obra, sem custo.';
    if (form.kind === 'other') return 'Escolha o tipo de documento para o poder lançar.';
    if (order) return `A compra n.º ${order.number} fica faturada por ${net} (sem IVA).`;
    if (form.kind === 'credit_note') return `Entra como custo negativo de ${net} (${categoryLabel.value}) na obra.`;
    return `Entra como custo realizado de ${net} sem IVA (${categoryLabel.value}) na obra.`;
});
const partnerOptions = computed(() => partners.value.map((p) => ({ value: p.id, label: `${p.name}${p.nif ? ` · ${p.nif}` : ''}${p.kind === 'subcontractor' ? ' (subempreiteiro)' : ''}` })));
const color = (s: string) => ({ reading: 'blue', to_review: 'amber', posted: 'green', rejected: 'gray' })[s] as 'blue' | 'amber' | 'green' | 'gray';
</script>

<template>
    <div>
        <RouterLink to="/app/faturas-fornecedores" class="text-sm font-medium text-brand-700 hover:underline">← Faturas de fornecedores</RouterLink>
        <AppAlert v-if="error" type="error" class="mt-3">{{ error }}</AppAlert>
        <div v-else-if="!doc" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <template v-else>
            <div class="mt-2 mb-4 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-slate-500 uppercase">{{ doc.kind_label }}</p>
                    <h1 class="text-2xl font-bold break-words text-slate-900">{{ doc.supplier ?? 'Fornecedor por identificar' }}<template v-if="doc.number"> · {{ doc.number }}</template></h1>
                    <p class="text-sm text-slate-600">
                        {{ doc.original_name }}
                        <template v-if="doc.project"> · <RouterLink :to="`/app/obras/${doc.project.id}?separador=compras`" class="text-brand-700 hover:underline">{{ doc.project.code }} · {{ doc.project.name }}</RouterLink></template>
                    </p>
                    <p v-if="doc.status === 'posted'" class="mt-1 text-sm font-semibold text-green-700">
                        Lançado<template v-if="doc.posted_by"> por {{ doc.posted_by }}</template> em {{ formatDateTime(doc.posted_at) }}
                        <template v-if="doc.purchase_order"> · compra n.º {{ doc.purchase_order.number }} ({{ doc.purchase_order.status_label.toLowerCase() }})</template>
                        <template v-else-if="doc.cost_entry_id"> · custo da obra</template>
                    </p>
                </div>
                <AppBadge :color="color(doc.status)">{{ doc.status_label }}</AppBadge>
            </div>

            <div class="mb-4 flex flex-wrap gap-2">
                <AppButton v-if="editable" :loading="saving" variant="secondary" @click="save()">Guardar</AppButton>
                <AppButton v-if="doc.can.post && !readOnly" @click="act('post')">Lançar</AppButton>
                <AppButton v-if="doc.can.unpost && !readOnly" variant="secondary" @click="act('unpost')">Desfazer lançamento</AppButton>
                <AppButton v-if="doc.can.read && !readOnly" variant="secondary" @click="act('read')">Ler de novo</AppButton>
                <AppButton v-if="doc.can.reopen && !readOnly" variant="secondary" @click="act('reopen')">Reabrir</AppButton>
                <a :href="appUrl(supplierDocsApi.fileUrl(doc.id))" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50">Abrir o original</a>
                <AppButton v-if="doc.can.reject && !readOnly" variant="ghost" class="text-red-700" @click="act('reject')">Rejeitar</AppButton>
                <AppButton v-if="doc.can.delete && !readOnly" variant="ghost" class="text-red-700" @click="remove">Apagar</AppButton>
            </div>

            <AppAlert v-if="doc.status === 'reading'" class="mb-4">
                <span class="inline-flex items-center gap-2"><AppSpinner class="h-4 w-4" /> A ler o documento no DocIntel… Os dados aparecem aqui assim que estiverem prontos.</span>
            </AppAlert>
            <AppAlert v-if="doc.warnings.length" type="warning" class="mb-4" title="A confirmar">
                <ul class="list-disc space-y-0.5 pl-4">
                    <li v-for="w in doc.warnings" :key="w">{{ w }}</li>
                </ul>
            </AppAlert>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="min-w-0">
                    <AppCard title="Original">
                        <iframe v-if="doc.file.is_pdf" :src="appUrl(supplierDocsApi.fileUrl(doc.id))" class="h-[70vh] w-full rounded border border-slate-200" title="Documento original"></iframe>
                        <img v-else :src="appUrl(supplierDocsApi.fileUrl(doc.id))" class="max-h-[70vh] w-full rounded border border-slate-200 object-contain" alt="Fotografia do documento" />
                        <p class="mt-2 text-xs text-slate-500">
                            <template v-if="doc.reader === 'docintel' && doc.read_at">
                                Lido pelo DocIntel em {{ formatDateTime(doc.read_at) }}<template v-if="doc.confidence !== null"> · confiança {{ formatPct(doc.confidence * 100, 0) }}</template>.
                            </template>
                            <template v-else-if="doc.reader !== 'docintel'">Preenchido à mão.</template>
                            Recebido<template v-if="doc.created_by"> de {{ doc.created_by }}</template> em {{ formatDateTime(doc.created_at) }}.
                        </p>
                    </AppCard>
                    <AppCard v-if="doc.lines.length" title="Linhas lidas" class="mt-6">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-xs text-slate-500">
                                    <tr>
                                        <th class="py-1.5 pr-2">Descrição</th>
                                        <th class="py-1.5 pr-2 text-right">Quant.</th>
                                        <th class="py-1.5 pr-2 text-right">Preço</th>
                                        <th class="py-1.5 pr-2 text-right">IVA</th>
                                        <th class="py-1.5 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="(l, i) in doc.lines" :key="i" :class="l.arithmetic_ok === false ? 'bg-amber-50' : ''">
                                        <td class="py-1.5 pr-2">
                                            <span v-if="l.code" class="font-mono text-xs text-slate-500">{{ l.code }} </span>{{ l.description }}
                                            <span v-if="l.arithmetic_ok === false" class="block text-xs text-amber-700">quantidade × preço não dá o total</span>
                                        </td>
                                        <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ l.quantity !== null ? formatQty(l.quantity) : '' }} {{ l.unit }}</td>
                                        <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ l.unit_price !== null ? formatMoney(Math.round(l.unit_price * 100)) : '' }}</td>
                                        <td class="py-1.5 pr-2 text-right">{{ l.vat_rate !== null ? formatPct(l.vat_rate, 0) : '' }}</td>
                                        <td class="py-1.5 text-right whitespace-nowrap">{{ l.total !== null ? formatMoney(Math.round(l.total * 100)) : '' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </AppCard>
                </div>

                <div class="min-w-0 space-y-6">
                    <AppCard title="Documento">
                        <div class="space-y-3">
                            <AppSelect v-model="form.kind" label="Tipo" :options="doc.options.kinds" :disabled="!editable" :error="errors.kind" />
                            <div v-if="editable && partnerOptions.length">
                                <AppSelect
                                    :model-value="form.partner_id ?? ''"
                                    label="Fornecedor da lista"
                                    :options="[{ value: '', label: 'Nenhum (escrever abaixo)' }, ...partnerOptions]"
                                    @update:model-value="pickPartner"
                                />
                            </div>
                            <p v-else-if="doc.partner" class="text-sm">Fornecedor: <strong>{{ doc.partner.name }}</strong><template v-if="doc.partner.nif"> · {{ doc.partner.nif }}</template></p>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="sm:col-span-2"><AppInput v-model="form.supplier_name" label="Nome no documento" :disabled="!editable" :error="errors.supplier_name" /></div>
                                <AppInput v-model="form.supplier_nif" label="NIF do fornecedor" :disabled="!editable" :error="errors.supplier_nif" />
                            </div>
                            <button v-if="doc.can.create_supplier && !readOnly && !form.partner_id" type="button" class="text-sm font-semibold text-brand-700 hover:underline" @click="createSupplier">
                                + Criar fornecedor com este nome e NIF
                            </button>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <AppInput v-model="form.number" label="Número" :disabled="!editable" :error="errors.number" />
                                <AppInput v-model="form.atcud" label="ATCUD" :disabled="!editable" :error="errors.atcud" />
                                <AppInput v-model="form.issue_date" type="date" label="Data" :disabled="!editable" :error="errors.issue_date" />
                                <AppInput v-model="form.due_date" type="date" label="Vencimento" :disabled="!editable" :error="errors.due_date" />
                                <AppInput v-model="form.customer_nif" label="NIF do cliente (o da empresa)" :disabled="!editable" :error="errors.customer_nif" />
                                <AppInput v-model="form.order_reference" label="Referência da encomenda" :disabled="!editable" :error="errors.order_reference" />
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <AppInput v-model="form.net" label="Sem IVA (€)" inputmode="decimal" :disabled="!editable" :error="errors.net_cents" />
                                <AppInput v-model="form.vat" label="IVA (€)" inputmode="decimal" :disabled="!editable" :error="errors.vat_cents" />
                                <AppInput v-model="form.gross" label="Total (€)" inputmode="decimal" :disabled="!editable" :error="errors.gross_cents" />
                            </div>
                            <AppTextarea v-model="form.notes" label="Notas" :rows="2" :disabled="!editable" />
                        </div>
                    </AppCard>

                    <AppCard title="Lançamento">
                        <div class="space-y-3">
                            <AppSelect
                                v-if="editable"
                                :model-value="form.project_id"
                                label="Obra"
                                placeholder="Escolha a obra"
                                :options="projects"
                                :error="errors.project_id"
                                @update:model-value="pickProject"
                            />
                            <p v-else class="text-sm">Obra: <strong>{{ doc.project ? `${doc.project.code} · ${doc.project.name}` : '—' }}</strong></p>
                            <AppSelect v-if="form.kind !== 'other'" v-model="purchaseModel" label="Compra" :options="purchaseOptions" :disabled="!editable || form.kind === 'credit_note'" :error="errors.purchase_order_id" />
                            <p v-if="editable && form.partner_id && !doc.suggestions.purchase_orders.length && form.kind !== 'other'" class="text-xs text-slate-500">
                                Não há compras aprovadas deste fornecedor{{ form.project_id ? ' nesta obra' : '' }}.
                            </p>
                            <AppSelect v-if="isCost && !form.purchase_order_id" v-model="form.category" label="Natureza do custo" :options="doc.options.categories" :disabled="!editable" />
                            <p v-if="doc.status !== 'posted'" class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ outcome }}</p>
                            <p v-if="doc.cost_center" class="text-xs text-slate-500">Centro de custo: {{ doc.cost_center }}</p>
                        </div>
                    </AppCard>
                </div>
            </div>
        </template>
    </div>
</template>
