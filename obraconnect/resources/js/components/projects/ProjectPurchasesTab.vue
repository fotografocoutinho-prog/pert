<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { costCentersApi } from '@/api';
import type { CostCenter, Project } from '@/api/types';
import { ApiError } from '@/api/http';
import { centsToInput, certificatesApi, contractsApi, eurosToCents, formatPct, measurementsApi, parseNumber, partnersApi, purchasesApi, type Contract, type MeasurementProgress, type Partner, type PurchaseOrder } from '@/api/economics';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SupplierDocsList from '@/components/purchases/SupplierDocsList.vue';

const props = defineProps<{ project: Project }>();
const session = useSessionStore();
const toast = useToastStore();
const router = useRouter();
const open = computed(() => props.project.status !== 'archived' && !session.isReadOnly);
const has = (p: string) => props.project.permissions.includes(p);
const canBuy = computed(() => open.value && has('purchases.manage'));
const canSub = computed(() => open.value && has('subcontractors.manage'));
const showPurchases = computed(() => has('purchases.manage') || has('costs.view'));
const showSupplierDocs = computed(() => has('purchases.manage') || has('costs.manage') || session.can('supplier_docs.manage'));
const showContracts = computed(() => has('subcontractors.manage') || has('costs.view') || has('certificates.approve'));

const CATEGORIES = [
    { value: 'material', label: 'Materiais' },
    { value: 'equipment', label: 'Equipamentos' },
    { value: 'subcontract', label: 'Subempreitadas' },
    { value: 'labor', label: 'Mão de obra' },
    { value: 'other', label: 'Outros' },
];

const loading = ref(true);
const orders = ref<PurchaseOrder[]>([]);
const contracts = ref<Contract[]>([]);
const suppliers = ref<Partner[]>([]);
const subcontractors = ref<Partner[]>([]);
const costCenters = ref<CostCenter[]>([]);
const budgetItems = ref<MeasurementProgress[]>([]);

async function load(): Promise<void> {
    const [o, c] = await Promise.all([showPurchases.value ? purchasesApi.list(props.project.id) : [], showContracts.value ? contractsApi.list(props.project.id) : []]);
    orders.value = o;
    contracts.value = c;
    loading.value = false;
}
async function loadLookups(): Promise<void> {
    if (suppliers.value.length || subcontractors.value.length) return;
    const [partners, centers, m] = await Promise.all([
        partnersApi.list().catch(() => [] as Partner[]),
        costCentersApi.list().catch(() => [] as CostCenter[]),
        measurementsApi.list(props.project.id).catch(() => null),
    ]);
    suppliers.value = partners.filter((p) => p.kind === 'supplier' && !p.archived);
    subcontractors.value = partners.filter((p) => p.kind === 'subcontractor' && !p.archived);
    costCenters.value = centers.filter((c) => !c.archived);
    budgetItems.value = m?.progress ?? [];
}
onMounted(load);

const fail = (e: unknown) => toast.error(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Não foi possível.');
const today = () => new Date().toISOString().slice(0, 10);
const orderColor = (s: string) => ({ draft: 'amber', approved: 'blue', received: 'brand', invoiced: 'green', cancelled: 'gray' })[s] as 'amber' | 'blue' | 'brand' | 'green' | 'gray';
const contractColor = (s: string) => (s === 'active' ? 'green' : s === 'draft' ? 'amber' : 'gray');
const totals = computed(() => ({
    committed: orders.value.filter((o) => o.status === 'approved' || o.status === 'received').reduce((s, o) => s + o.total_cents, 0),
    invoiced: orders.value.filter((o) => o.status === 'invoiced').reduce((s, o) => s + (o.invoiced_cents ?? o.total_cents), 0),
}));

// ---- Encomendas ----
interface LineForm {
    budget_item_id: number | '';
    description: string;
    unit: string;
    quantity: string;
    price: string;
}
const orderOpen = ref(false);
const editingOrder = ref<PurchaseOrder | null>(null);
const order = useForm({ partner_id: '' as number | '', category: 'material', cost_center_id: '' as number | '', ordered_on: '', expected_on: '', notes: '', lines: [] as LineForm[] });
const readOnlyOrder = computed(() => editingOrder.value !== null && !editingOrder.value.can.edit);
async function openOrder(o: PurchaseOrder | null): Promise<void> {
    editingOrder.value = o;
    order.reset(
        o
            ? {
                  partner_id: o.partner_id,
                  category: o.category,
                  cost_center_id: o.cost_center_id ?? '',
                  ordered_on: o.ordered_on ?? '',
                  expected_on: o.expected_on ?? '',
                  notes: o.notes ?? '',
                  lines: o.lines.map((l) => ({ budget_item_id: l.budget_item_id ?? '', description: l.description, unit: l.unit, quantity: String(l.quantity).replace('.', ','), price: centsToInput(l.unit_price_cents) })),
              }
            : { lines: [{ budget_item_id: '', description: '', unit: 'un', quantity: '1', price: '' }] },
    );
    orderOpen.value = true;
    await loadLookups();
}
const orderTotal = computed(() => order.data.lines.reduce((s, l) => s + (parseNumber(l.quantity) ?? 0) * (eurosToCents(l.price) ?? 0), 0));
function pickBudgetItem(l: LineForm): void {
    const b = budgetItems.value.find((x) => x.budget_item_id === l.budget_item_id);
    if (b && !l.description) Object.assign(l, { description: b.description, unit: b.unit });
}
async function saveOrder(): Promise<void> {
    const payload = {
        partner_id: order.data.partner_id === '' ? null : Number(order.data.partner_id),
        category: order.data.category,
        cost_center_id: order.data.cost_center_id === '' ? null : Number(order.data.cost_center_id),
        ordered_on: order.data.ordered_on || null,
        expected_on: order.data.expected_on || null,
        notes: order.data.notes || null,
        lines: order.data.lines.map((l) => ({
            budget_item_id: l.budget_item_id === '' ? null : Number(l.budget_item_id),
            description: l.description,
            unit: l.unit,
            quantity: parseNumber(l.quantity) ?? 0,
            unit_price_cents: eurosToCents(l.price) ?? 0,
        })),
    };
    const id = editingOrder.value?.id;
    const saved = await order.submit(() => (id ? purchasesApi.update(props.project.id, id, payload) : purchasesApi.create(props.project.id, payload)), { success: 'Compra guardada.' });
    if (saved) {
        orderOpen.value = false;
        await load();
    }
}
async function orderAction(o: PurchaseOrder, action: 'approve' | 'receive' | 'cancel'): Promise<void> {
    const ask = { approve: 'Aprovar a encomenda? O valor passa a custo comprometido.', receive: 'Dar como recebida (tudo)?', cancel: 'Cancelar a encomenda?' }[action];
    if (!window.confirm(ask)) return;
    try {
        await purchasesApi.act(props.project.id, o.id, action);
        orderOpen.value = false;
        await load();
    } catch (e) {
        fail(e);
    }
}
async function removeOrder(o: PurchaseOrder): Promise<void> {
    if (!window.confirm('Apagar esta compra em rascunho?')) return;
    try {
        await purchasesApi.remove(props.project.id, o.id);
        orderOpen.value = false;
        await load();
    } catch (e) {
        fail(e);
    }
}
const invoiceOpen = ref(false);
const invoiceFor = ref<PurchaseOrder | null>(null);
const invoice = useForm({ invoice_number: '', invoiced_on: today(), value: '' });
function openInvoice(o: PurchaseOrder): void {
    invoiceFor.value = o;
    invoice.reset({ invoiced_on: today(), value: centsToInput(o.total_cents) });
    orderOpen.value = false;
    invoiceOpen.value = true;
}
async function saveInvoice(): Promise<void> {
    const o = invoiceFor.value!;
    const saved = await invoice.submit(
        () => purchasesApi.act(props.project.id, o.id, 'invoice', { invoice_number: invoice.data.invoice_number, invoiced_on: invoice.data.invoiced_on, invoiced_cents: eurosToCents(invoice.data.value) }),
        { success: 'Fatura registada: o valor passa a custo realizado.' },
    );
    if (saved) {
        invoiceOpen.value = false;
        await load();
    }
}

// ---- Subempreitadas ----
interface ContractItemForm {
    budget_item_id: number | '';
    description: string;
    unit: string;
    quantity: string;
    price: string;
}
const contractOpen = ref(false);
const editingContract = ref<Contract | null>(null);
const contract = useForm({ partner_id: '' as number | '', code: '', title: '', retention_pct: '5', starts_on: '', ends_on: '', notes: '', items: [] as ContractItemForm[] });
const readOnlyContract = computed(() => editingContract.value !== null && !editingContract.value.can.edit);
async function openContract(c: Contract | null): Promise<void> {
    editingContract.value = c;
    contract.reset(
        c
            ? {
                  partner_id: c.partner_id,
                  code: c.code,
                  title: c.title,
                  retention_pct: String(c.retention_pct).replace('.', ','),
                  starts_on: c.starts_on ?? '',
                  ends_on: c.ends_on ?? '',
                  notes: c.notes ?? '',
                  items: c.items.map((i) => ({ budget_item_id: i.budget_item_id ?? '', description: i.description, unit: i.unit, quantity: String(i.quantity).replace('.', ','), price: centsToInput(i.unit_price_cents) })),
              }
            : { items: [] },
    );
    contractOpen.value = true;
    await loadLookups();
}
function pickContractItem(i: ContractItemForm): void {
    const b = budgetItems.value.find((x) => x.budget_item_id === i.budget_item_id);
    if (b) Object.assign(i, { description: b.description, unit: b.unit, quantity: String(b.budget_quantity).replace('.', ',') });
}
const contractTotal = computed(() => contract.data.items.reduce((s, l) => s + (parseNumber(l.quantity) ?? 0) * (eurosToCents(l.price) ?? 0), 0));
async function saveContract(): Promise<void> {
    const payload: Record<string, unknown> = {
        partner_id: contract.data.partner_id === '' ? null : Number(contract.data.partner_id),
        title: contract.data.title,
        retention_pct: parseNumber(contract.data.retention_pct) ?? 0,
        starts_on: contract.data.starts_on || null,
        ends_on: contract.data.ends_on || null,
        notes: contract.data.notes || null,
        items: contract.data.items.map((i) => ({
            budget_item_id: i.budget_item_id === '' ? null : Number(i.budget_item_id),
            description: i.description,
            unit: i.unit,
            quantity: parseNumber(i.quantity) ?? 0,
            unit_price_cents: eurosToCents(i.price) ?? 0,
        })),
    };
    if (contract.data.code) payload.code = contract.data.code;
    const id = editingContract.value?.id;
    const saved = await contract.submit(() => (id ? contractsApi.update(props.project.id, id, payload) : contractsApi.create(props.project.id, payload)), { success: 'Subempreitada guardada.' });
    if (saved) {
        contractOpen.value = false;
        await load();
    }
}
async function contractAction(c: Contract, action: 'activate' | 'close'): Promise<void> {
    if (!window.confirm(action === 'activate' ? 'Dar a subempreitada como adjudicada (em curso)? O valor passa a custo comprometido.' : 'Fechar a subempreitada?')) return;
    try {
        await contractsApi.act(props.project.id, c.id, action);
        contractOpen.value = false;
        await load();
    } catch (e) {
        fail(e);
    }
}
async function removeContract(c: Contract): Promise<void> {
    if (!window.confirm('Apagar esta subempreitada em rascunho?')) return;
    try {
        await contractsApi.remove(props.project.id, c.id);
        contractOpen.value = false;
        await load();
    } catch (e) {
        fail(e);
    }
}
const certifying = ref<number | null>(null);
async function certify(c: Contract): Promise<void> {
    const start = window.prompt('Início do período do auto (AAAA-MM-DD)', c.starts_on ?? today());
    if (!start) return;
    const end = window.prompt('Fim do período (AAAA-MM-DD)', today());
    if (!end) return;
    certifying.value = c.id;
    try {
        const cert = await certificatesApi.create(props.project.id, { contract_id: c.id, period_start: start, period_end: end });
        router.push(`/app/obras/${props.project.id}/autos/${cert.id}`);
    } catch (e) {
        fail(e);
    } finally {
        certifying.value = null;
    }
}
const partnerOptions = (list: Partner[]) => list.map((p) => ({ value: p.id, label: p.name }));
const budgetItemOptions = computed(() => [{ value: '', label: '(fora do orçamento)' }, ...budgetItems.value.map((b) => ({ value: b.budget_item_id, label: `${b.code} · ${b.description}` }))]);
const costCenterOptions = computed(() => [{ value: '', label: '(o da obra)' }, ...costCenters.value.map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` }))]);
</script>

<template>
    <div v-if="loading" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
    <div v-else class="space-y-6">
        <AppCard v-if="showPurchases" title="Compras" description="Encomenda aprovada = custo comprometido; fatura registada = custo realizado.">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-600">Comprometido {{ formatMoney(totals.committed) }} · faturado {{ formatMoney(totals.invoiced) }}</p>
                <AppButton v-if="canBuy" size="sm" @click="openOrder(null)">Nova compra</AppButton>
            </div>
            <EmptyState v-if="!orders.length" title="Sem compras" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="o in orders" :key="o.id">
                    <button type="button" class="flex w-full flex-wrap items-center justify-between gap-2 py-2.5 text-left hover:bg-slate-50" @click="openOrder(o)">
                        <span>
                            <strong>EC {{ o.number }}</strong> · {{ o.partner }}
                            <span class="block text-xs text-slate-500">
                                {{ o.category_label }} · {{ o.lines.length }} {{ o.lines.length === 1 ? 'linha' : 'linhas' }}<template v-if="o.ordered_on"> · {{ formatDate(o.ordered_on) }}</template><template v-if="o.invoice_number"> · fatura {{ o.invoice_number }}</template>
                            </span>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="font-semibold">{{ formatMoney(o.invoiced_cents ?? o.total_cents) }}</span>
                            <AppBadge :color="orderColor(o.status)">{{ o.status_label }}</AppBadge>
                        </span>
                    </button>
                </li>
            </ul>
        </AppCard>

        <AppCard
            v-if="showSupplierDocs"
            title="Faturas e guias de fornecedores"
            description="Junte aqui as faturas, notas de crédito, talões e guias desta obra: são lidos, revistos e lançados na compra certa ou como custo."
        >
            <SupplierDocsList :project-id="project.id" />
        </AppCard>

        <AppCard v-if="showContracts" title="Subempreitadas" description="Contrato adjudicado = custo comprometido; autos aprovados = custo realizado (com retenção de garantia).">
            <div v-if="canSub" class="mb-3"><AppButton size="sm" @click="openContract(null)">Nova subempreitada</AppButton></div>
            <EmptyState v-if="!contracts.length" title="Sem subempreitadas" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="c in contracts" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                    <button type="button" class="min-w-0 text-left hover:underline" @click="openContract(c)">
                        <strong>{{ c.code }}</strong> · {{ c.title }}
                        <span class="block text-xs text-slate-500">{{ c.partner }} · autado {{ formatMoney(c.certified_cents) }}<template v-if="c.certified_pct !== null"> ({{ formatPct(c.certified_pct) }})</template> · retido {{ formatMoney(c.retained_cents) }}</span>
                    </button>
                    <span class="flex items-center gap-2">
                        <span class="font-semibold">{{ formatMoney(c.value_cents) }}</span>
                        <AppBadge :color="contractColor(c.status)">{{ c.status_label }}</AppBadge>
                        <AppButton v-if="c.can.certify" size="sm" variant="secondary" :loading="certifying === c.id" @click="certify(c)">Novo auto</AppButton>
                    </span>
                </li>
            </ul>
        </AppCard>

        <!-- Encomenda -->
        <AppModal :open="orderOpen" :title="editingOrder ? `Encomenda EC ${editingOrder.number} · ${editingOrder.status_label}` : 'Nova compra'" size="lg" @close="orderOpen = false">
            <form id="order-form" class="space-y-4" novalidate @submit.prevent="saveOrder">
                <AppAlert v-if="order.message.value" type="error">{{ order.message.value }}</AppAlert>
                <AppAlert v-if="canBuy && !suppliers.length && !editingOrder">Primeiro crie fornecedores em Fornecedores e subempreiteiros.</AppAlert>
                <div class="grid gap-4 sm:grid-cols-3">
                    <AppSelect v-model="order.data.partner_id" class="sm:col-span-2" label="Fornecedor" placeholder="Escolha" :options="partnerOptions(suppliers)" :disabled="readOnlyOrder" :error="order.errors.value.partner_id" />
                    <AppSelect v-model="order.data.category" label="Natureza" :options="CATEGORIES" :disabled="readOnlyOrder" />
                    <AppSelect v-model="order.data.cost_center_id" label="Centro de custo" :options="costCenterOptions" :disabled="readOnlyOrder" />
                    <AppInput v-model="order.data.ordered_on" type="date" label="Encomendada a" :disabled="readOnlyOrder" />
                    <AppInput v-model="order.data.expected_on" type="date" label="Entrega prevista" :disabled="readOnlyOrder" />
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Linhas</h3>
                    <div v-for="(l, i) in order.data.lines" :key="i" class="mb-2 grid grid-cols-12 gap-1.5">
                        <select v-model="l.budget_item_id" class="col-span-12 rounded border border-slate-300 bg-white px-2 py-1.5 text-sm sm:col-span-3" :disabled="readOnlyOrder" :aria-label="`Artigo do orçamento ${i + 1}`" @change="pickBudgetItem(l)">
                            <option v-for="b in budgetItemOptions" :key="b.value" :value="b.value">{{ b.label }}</option>
                        </select>
                        <input v-model="l.description" placeholder="descrição" class="col-span-12 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-4" :disabled="readOnlyOrder" :aria-label="`Descrição ${i + 1}`" />
                        <input v-model="l.quantity" placeholder="quant." inputmode="decimal" class="col-span-3 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-1" :disabled="readOnlyOrder" :aria-label="`Quantidade ${i + 1}`" />
                        <input v-model="l.unit" placeholder="un" class="col-span-3 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-1" :disabled="readOnlyOrder" :aria-label="`Unidade ${i + 1}`" />
                        <input v-model="l.price" placeholder="€/un" inputmode="decimal" class="col-span-4 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-2" :disabled="readOnlyOrder" :aria-label="`Preço ${i + 1}`" />
                        <button v-if="!readOnlyOrder" type="button" class="col-span-2 text-red-700 sm:col-span-1" :aria-label="`Retirar linha ${i + 1}`" @click="order.data.lines.splice(i, 1)">✕</button>
                    </div>
                    <button v-if="!readOnlyOrder" type="button" class="text-sm font-semibold text-brand-700 hover:underline" @click="order.data.lines.push({ budget_item_id: '', description: '', unit: 'un', quantity: '1', price: '' })">+ Linha</button>
                    <p class="mt-2 text-sm">Total: <strong>{{ formatMoney(Math.round(orderTotal)) }}</strong> <span class="text-slate-500">(sem IVA)</span></p>
                    <p v-if="order.errors.value.lines" class="text-sm text-red-700">{{ order.errors.value.lines }}</p>
                </div>
                <AppInput v-model="order.data.notes" label="Notas" :disabled="readOnlyOrder" />
                <p v-if="editingOrder?.invoice_number" class="text-sm text-slate-600">Fatura {{ editingOrder.invoice_number }} de {{ formatDate(editingOrder.invoiced_on) }}: {{ formatMoney(editingOrder.invoiced_cents) }}</p>
            </form>
            <template #footer>
                <template v-if="editingOrder">
                    <AppButton v-if="editingOrder.can.edit" variant="ghost" class="mr-auto text-red-700" @click="removeOrder(editingOrder)">Apagar</AppButton>
                    <AppButton v-if="editingOrder.can.cancel" variant="ghost" @click="orderAction(editingOrder, 'cancel')">Cancelar encomenda</AppButton>
                    <AppButton v-if="editingOrder.can.approve" variant="secondary" @click="orderAction(editingOrder, 'approve')">Aprovar</AppButton>
                    <AppButton v-if="editingOrder.can.receive" variant="secondary" @click="orderAction(editingOrder, 'receive')">Recebida</AppButton>
                    <AppButton v-if="editingOrder.can.invoice" variant="secondary" @click="openInvoice(editingOrder)">Registar fatura</AppButton>
                </template>
                <AppButton variant="secondary" @click="orderOpen = false">Fechar</AppButton>
                <AppButton v-if="!readOnlyOrder && canBuy" type="submit" form="order-form" :loading="order.processing.value">Guardar</AppButton>
            </template>
        </AppModal>

        <AppModal :open="invoiceOpen" :title="`Fatura da EC ${invoiceFor?.number ?? ''}`" @close="invoiceOpen = false">
            <form id="invoice-form" class="space-y-4" novalidate @submit.prevent="saveInvoice">
                <AppAlert v-if="invoice.message.value" type="error">{{ invoice.message.value }}</AppAlert>
                <AppInput v-model="invoice.data.invoice_number" label="N.º da fatura" required :error="invoice.errors.value.invoice_number" />
                <div class="grid grid-cols-2 gap-3">
                    <AppInput v-model="invoice.data.invoiced_on" type="date" label="Data" :error="invoice.errors.value.invoiced_on" />
                    <AppInput v-model="invoice.data.value" label="Valor sem IVA (€)" inputmode="decimal" :error="invoice.errors.value.invoiced_cents" />
                </div>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="invoiceOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="invoice-form" :loading="invoice.processing.value">Registar</AppButton>
            </template>
        </AppModal>

        <!-- Subempreitada -->
        <AppModal :open="contractOpen" :title="editingContract ? `${editingContract.code} · ${editingContract.status_label}` : 'Nova subempreitada'" size="lg" @close="contractOpen = false">
            <form id="contract-form" class="space-y-4" novalidate @submit.prevent="saveContract">
                <AppAlert v-if="contract.message.value" type="error">{{ contract.message.value }}</AppAlert>
                <AppAlert v-if="canSub && !subcontractors.length && !editingContract">Primeiro crie subempreiteiros em Fornecedores e subempreiteiros.</AppAlert>
                <div class="grid gap-4 sm:grid-cols-3">
                    <AppSelect v-model="contract.data.partner_id" class="sm:col-span-2" label="Subempreiteiro" placeholder="Escolha" :options="partnerOptions(subcontractors)" :disabled="readOnlyContract" :error="contract.errors.value.partner_id" />
                    <AppInput v-model="contract.data.code" label="Código" placeholder="automático" :disabled="readOnlyContract" :error="contract.errors.value.code" />
                    <AppInput v-model="contract.data.title" class="sm:col-span-3" label="Objeto" placeholder="Instalações elétricas" :disabled="readOnlyContract" :error="contract.errors.value.title" />
                    <AppInput v-model="contract.data.retention_pct" label="Retenção de garantia (%)" inputmode="decimal" :disabled="readOnlyContract" :error="contract.errors.value.retention_pct" />
                    <AppInput v-model="contract.data.starts_on" type="date" label="Início" :disabled="readOnlyContract" />
                    <AppInput v-model="contract.data.ends_on" type="date" label="Fim" :disabled="readOnlyContract" :error="contract.errors.value.ends_on" />
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Trabalhos e preços acordados</h3>
                    <div v-for="(l, i) in contract.data.items" :key="i" class="mb-2 grid grid-cols-12 gap-1.5">
                        <select v-model="l.budget_item_id" class="col-span-12 rounded border border-slate-300 bg-white px-2 py-1.5 text-sm sm:col-span-3" :disabled="readOnlyContract" :aria-label="`Artigo do orçamento ${i + 1}`" @change="pickContractItem(l)">
                            <option v-for="b in budgetItemOptions" :key="b.value" :value="b.value">{{ b.label }}</option>
                        </select>
                        <input v-model="l.description" placeholder="descrição" class="col-span-12 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-4" :disabled="readOnlyContract" :aria-label="`Descrição ${i + 1}`" />
                        <input v-model="l.quantity" placeholder="quant." inputmode="decimal" class="col-span-3 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-1" :disabled="readOnlyContract" :aria-label="`Quantidade ${i + 1}`" />
                        <input v-model="l.unit" placeholder="un" class="col-span-3 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-1" :disabled="readOnlyContract" :aria-label="`Unidade ${i + 1}`" />
                        <input v-model="l.price" placeholder="€/un" inputmode="decimal" class="col-span-4 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-2" :disabled="readOnlyContract" :aria-label="`Preço ${i + 1}`" />
                        <button v-if="!readOnlyContract" type="button" class="col-span-2 text-red-700 sm:col-span-1" :aria-label="`Retirar ${i + 1}`" @click="contract.data.items.splice(i, 1)">✕</button>
                    </div>
                    <button v-if="!readOnlyContract" type="button" class="text-sm font-semibold text-brand-700 hover:underline" @click="contract.data.items.push({ budget_item_id: '', description: '', unit: 'un', quantity: '1', price: '' })">+ Trabalho</button>
                    <p class="mt-2 text-sm">Valor do contrato: <strong>{{ formatMoney(Math.round(contractTotal)) }}</strong></p>
                </div>
                <AppInput v-model="contract.data.notes" label="Notas" :disabled="readOnlyContract" />
            </form>
            <template #footer>
                <template v-if="editingContract">
                    <AppButton v-if="editingContract.can.edit && editingContract.status === 'draft'" variant="ghost" class="mr-auto text-red-700" @click="removeContract(editingContract)">Apagar</AppButton>
                    <AppButton v-if="editingContract.can.activate" variant="secondary" @click="contractAction(editingContract, 'activate')">Adjudicar</AppButton>
                    <AppButton v-if="editingContract.can.close" variant="secondary" @click="contractAction(editingContract, 'close')">Fechar contrato</AppButton>
                </template>
                <AppButton variant="secondary" @click="contractOpen = false">Fechar</AppButton>
                <AppButton v-if="!readOnlyContract && canSub" type="submit" form="contract-form" :loading="contract.processing.value">Guardar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
