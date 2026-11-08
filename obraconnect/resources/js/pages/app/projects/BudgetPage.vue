<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@/api/http';
import { budgetsApi, centsToInput, eurosToCents, formatPct, formatQty, parseNumber, priceLibraryApi, type Article, type Budget, type BudgetChapter, type BudgetItem, type PriceResource } from '@/api/economics';
import { useForm } from '@/composables/useForm';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';

const route = useRoute();
const router = useRouter();
const toast = useToastStore();
const projectId = computed(() => Number(route.params.id));
const budget = ref<Budget | null>(null);
const error = ref<string | null>(null);
const articles = ref<Article[]>([]);
const resources = ref<PriceResource[]>([]);

const CATEGORY_LABELS: Record<string, string> = { labor: 'Mão de obra', material: 'Materiais', equipment: 'Equipamentos', subcontract: 'Subempreitadas', other: 'Outros' };
const KIND_OPTIONS = [
    { value: 'material', label: 'Material' },
    { value: 'labor', label: 'Mão de obra' },
    { value: 'equipment', label: 'Equipamento' },
    { value: 'subcontract', label: 'Subempreitada' },
];

const header = useForm({ name: '', overhead_pct: '', profit_pct: '', vat_mode: 'normal', vat_rate: '', notes: '' });
function show(b: Budget): void {
    budget.value = b;
    header.reset({ name: b.name, overhead_pct: String(b.overhead_pct).replace('.', ','), profit_pct: String(b.profit_pct).replace('.', ','), vat_mode: b.vat_mode, vat_rate: String(b.vat_rate).replace('.', ','), notes: b.notes ?? '' });
    document.title = `${b.code} v${b.version} · ObraConnect`;
}

async function load(): Promise<void> {
    error.value = null;
    try {
        show(await budgetsApi.get(projectId.value, Number(route.params.budgetId)));
        if (budget.value?.can.edit && !articles.value.length) {
            const lib = await priceLibraryApi.get().catch(() => null);
            articles.value = lib?.articles.filter((a) => !a.archived) ?? [];
            resources.value = lib?.resources.filter((r) => !r.archived) ?? [];
        }
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível abrir o orçamento.';
    }
}
watch(() => route.params.budgetId, load, { immediate: true });

const fail = (e: unknown, fallback = 'Não foi possível.') => toast.error(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : fallback);
async function run(action: () => Promise<Budget>, success?: string): Promise<void> {
    try {
        show(await action());
        if (success) toast.success(success);
    } catch (e) {
        fail(e);
    }
}

async function saveHeader(): Promise<void> {
    const saved = await header.submit(
        () =>
            budgetsApi.update(projectId.value, budget.value!.id, {
                name: header.data.name,
                overhead_pct: parseNumber(header.data.overhead_pct) ?? 0,
                profit_pct: parseNumber(header.data.profit_pct) ?? 0,
                vat_mode: header.data.vat_mode,
                vat_rate: parseNumber(header.data.vat_rate) ?? 23,
                notes: header.data.notes || null,
            }),
        { success: 'Orçamento guardado.' },
    );
    if (saved) show(saved);
}

async function approve(): Promise<void> {
    if (!window.confirm('Aprovar? Passa a ser a base da obra (autos e custo previsto) e deixa de se poder alterar.')) return;
    await run(() => budgetsApi.approve(projectId.value, budget.value!.id), 'Orçamento aprovado.');
}
async function revise(): Promise<void> {
    try {
        const copy = await budgetsApi.revise(projectId.value, budget.value!.id);
        toast.success(`Criada a versão ${copy.version} em rascunho.`);
        router.push(`/app/obras/${projectId.value}/orcamentos/${copy.id}`);
    } catch (e) {
        fail(e);
    }
}
async function removeBudget(): Promise<void> {
    if (!window.confirm('Apagar este orçamento em rascunho?')) return;
    try {
        await budgetsApi.remove(projectId.value, budget.value!.id);
        router.push(`/app/obras/${projectId.value}?separador=orcamento`);
    } catch (e) {
        fail(e);
    }
}

// ---- Importar ----
const importing = ref(false);
async function onImport(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || !budget.value) return;
    importing.value = true;
    try {
        const r = await budgetsApi.import(projectId.value, budget.value.id, file);
        show(r.data);
        toast.success(`Importados ${r.meta.imported.chapters} capítulos e ${r.meta.imported.items} artigos.`);
    } catch (e) {
        fail(e, 'Não foi possível importar.');
    } finally {
        importing.value = false;
    }
}

// ---- Capítulos ----
const depthOf = (c: BudgetChapter): number => {
    let d = 0;
    let current: BudgetChapter | undefined = c;
    while (current?.parent_id && d < 10) {
        current = budget.value?.chapters.find((x) => x.id === current!.parent_id);
        d++;
    }
    return d;
};
const itemsOf = (chapterId: number) => budget.value?.items.filter((i) => i.chapter_id === chapterId) ?? [];
async function addChapter(parentId: number | null): Promise<void> {
    const name = window.prompt(parentId ? 'Nome do subcapítulo' : 'Nome do capítulo');
    if (name?.trim()) await run(() => budgetsApi.addChapter(projectId.value, budget.value!.id, { name: name.trim(), parent_id: parentId }));
}
async function renameChapter(c: BudgetChapter): Promise<void> {
    const name = window.prompt('Nome do capítulo', c.name);
    if (name?.trim() && name !== c.name) await run(() => budgetsApi.updateChapter(projectId.value, budget.value!.id, c.id, { name: name.trim() }));
}
async function removeChapter(c: BudgetChapter): Promise<void> {
    if (window.confirm(`Apagar o capítulo ${c.code} e todos os seus artigos?`)) await run(() => budgetsApi.removeChapter(projectId.value, budget.value!.id, c.id));
}

// ---- Artigos ----
const itemOpen = ref(false);
const editingItem = ref<BudgetItem | null>(null);
interface ComponentForm {
    resource_id: number | '';
    kind: string;
    name: string;
    unit: string;
    quantity: string;
    cost: string;
}
const item = useForm({ chapter_id: '' as number | '', article_id: '' as number | '', code: '', description: '', unit: '', quantity: '', cost: '', price: '', components: [] as ComponentForm[] });
function openItem(chapterId: number, i: BudgetItem | null): void {
    editingItem.value = i;
    item.reset(
        i
            ? {
                  chapter_id: i.chapter_id,
                  code: i.code,
                  description: i.description,
                  unit: i.unit,
                  quantity: String(i.quantity).replace('.', ','),
                  cost: i.components.length ? '' : centsToInput(i.unit_cost_cents),
                  price: centsToInput(i.unit_price_cents),
                  components: i.components.map((c) => ({
                      resource_id: c.resource_id ?? '',
                      kind: c.kind,
                      name: c.name,
                      unit: c.unit,
                      quantity: String(c.quantity).replace('.', ','),
                      cost: centsToInput(c.unit_cost_cents),
                  })),
              }
            : { chapter_id: chapterId, components: [] },
    );
    itemOpen.value = true;
}
const chapterOptions = computed(() => (budget.value?.chapters ?? []).map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })));
const articleOptions = computed(() => [{ value: '', label: 'Escrever à mão (sem biblioteca)' }, ...articles.value.map((a) => ({ value: a.id, label: `${a.code} · ${a.description} (${formatMoney(a.unit_cost_cents)}/${a.unit})` }))]);
const componentsCost = computed(() => item.data.components.reduce((s, c) => s + (parseNumber(c.quantity) ?? 0) * (eurosToCents(c.cost) ?? 0), 0));
function pickResource(c: ComponentForm): void {
    const r = resources.value.find((x) => x.id === c.resource_id);
    if (r) Object.assign(c, { kind: r.kind, name: r.name, unit: r.unit, cost: centsToInput(r.unit_cost_cents) });
}
async function saveItem(): Promise<void> {
    if (!budget.value) return;
    const payload: Record<string, unknown> = {
        chapter_id: Number(item.data.chapter_id),
        quantity: parseNumber(item.data.quantity) ?? 0,
        unit_price_cents: eurosToCents(item.data.price),
    };
    const fromArticle = !editingItem.value && item.data.article_id !== '';
    if (fromArticle) payload.article_id = Number(item.data.article_id);
    else {
        payload.description = item.data.description;
        payload.unit = item.data.unit;
        if (item.data.code) payload.code = item.data.code;
        payload.components = item.data.components.map((c) => ({
            resource_id: c.resource_id === '' ? null : Number(c.resource_id),
            kind: c.kind,
            name: c.name,
            unit: c.unit,
            quantity: parseNumber(c.quantity) ?? 0,
            unit_cost_cents: eurosToCents(c.cost) ?? 0,
        }));
        if (!item.data.components.length) payload.unit_cost_cents = eurosToCents(item.data.cost) ?? 0;
    }
    const id = editingItem.value?.id;
    const saved = await item.submit(() => (id ? budgetsApi.updateItem(projectId.value, budget.value!.id, id, payload) : budgetsApi.addItem(projectId.value, budget.value!.id, payload)));
    if (saved) {
        show(saved);
        itemOpen.value = false;
    }
}
async function removeItem(): Promise<void> {
    if (!editingItem.value || !window.confirm('Apagar este artigo?')) return;
    await run(() => budgetsApi.removeItem(projectId.value, budget.value!.id, editingItem.value!.id));
    itemOpen.value = false;
}

const categories = computed(() => {
    const b = budget.value;
    if (!b) return [];
    const total = Object.values(b.by_category).reduce((s, v) => s + v, 0) || 1;
    return Object.entries(b.by_category)
        .filter(([, v]) => v > 0)
        .map(([k, v]) => ({ key: k, label: CATEGORY_LABELS[k] ?? k, cents: v, pct: Math.round((v * 100) / total) }));
});
</script>

<template>
    <div>
        <RouterLink :to="`/app/obras/${projectId}?separador=orcamento`" class="text-sm font-medium text-brand-700 hover:underline">← Orçamentos da obra</RouterLink>
        <AppAlert v-if="error" type="error" class="mt-3">{{ error }}</AppAlert>
        <div v-else-if="!budget" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <template v-else>
            <div class="mt-2 mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-mono text-xs font-semibold text-slate-500">{{ budget.code }} · versão {{ budget.version }}</p>
                    <h1 class="text-2xl font-bold text-slate-900">{{ budget.name }}</h1>
                    <p class="text-sm text-slate-600">
                        Encargos {{ formatPct(budget.overhead_pct, 2) }} · margem {{ formatPct(budget.profit_pct, 2) }} · {{ budget.vat_mode === 'normal' ? `IVA ${formatPct(budget.vat_rate, 2)}` : budget.vat_mode_label }}
                        <template v-if="budget.approved_by"> · aprovado por {{ budget.approved_by }}</template>
                    </p>
                </div>
                <AppBadge :color="budget.status === 'approved' ? 'green' : budget.status === 'draft' ? 'amber' : 'gray'">{{ budget.status_label }}</AppBadge>
            </div>

            <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <AppCard><p class="text-xs text-slate-500">Custo</p><p class="text-lg font-bold">{{ formatMoney(budget.cost_cents) }}</p></AppCard>
                <AppCard><p class="text-xs text-slate-500">Venda (sem IVA)</p><p class="text-lg font-bold">{{ formatMoney(budget.sale_cents) }}</p></AppCard>
                <AppCard><p class="text-xs text-slate-500">IVA</p><p class="text-lg font-bold">{{ formatMoney(budget.vat_cents) }}</p></AppCard>
                <AppCard><p class="text-xs text-slate-500">Total</p><p class="text-lg font-bold">{{ formatMoney(budget.total_cents) }}</p></AppCard>
                <AppCard><p class="text-xs text-slate-500">Margem</p><p class="text-lg font-bold">{{ formatPct(budget.margin_pct) }}</p></AppCard>
            </div>

            <div class="mb-5 flex flex-wrap gap-2">
                <AppButton v-if="budget.can.approve" @click="approve">Aprovar orçamento</AppButton>
                <AppButton v-if="budget.can.revise" variant="secondary" @click="revise">Fazer revisão</AppButton>
                <label v-if="budget.can.edit" class="inline-flex cursor-pointer items-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                    {{ importing ? 'A importar…' : 'Importar Excel / CSV' }}
                    <input type="file" accept=".xlsx,.csv" class="sr-only" :disabled="importing" @change="onImport" />
                </label>
                <a :href="appUrl(budgetsApi.exportUrl(projectId, budget.id))" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50">Exportar CSV</a>
                <AppButton v-if="budget.can.edit" variant="ghost" class="text-red-700" @click="removeBudget">Apagar rascunho</AppButton>
            </div>
            <AppAlert v-if="budget.can.edit && !budget.items.length" class="mb-4">
                Junte capítulos e artigos, ou importe uma folha com as colunas <strong>Código, Descrição, Unidade, Quantidade, Custo unitário</strong> (linhas sem unidade são capítulos).
            </AppAlert>

            <div class="grid gap-6 lg:grid-cols-4">
                <div class="space-y-4 lg:col-span-3">
                    <AppCard v-for="c in budget.chapters" :key="c.id" :style="{ marginLeft: `${depthOf(c) * 1}rem` }">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="font-semibold text-slate-900"><span class="font-mono">{{ c.code }}</span> · {{ c.name }}</h2>
                            <div class="flex flex-wrap items-center gap-1">
                                <span class="mr-2 text-sm font-semibold">{{ formatMoney(c.sale_cents) }}</span>
                                <template v-if="budget.can.edit">
                                    <AppButton size="sm" variant="ghost" @click="openItem(c.id, null)">+ Artigo</AppButton>
                                    <AppButton size="sm" variant="ghost" @click="addChapter(c.id)">+ Subcapítulo</AppButton>
                                    <AppButton size="sm" variant="ghost" @click="renameChapter(c)">Renomear</AppButton>
                                    <AppButton size="sm" variant="ghost" class="text-red-700" @click="removeChapter(c)">Apagar</AppButton>
                                </template>
                            </div>
                        </div>
                        <div v-if="itemsOf(c.id).length" class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-xs text-slate-500">
                                    <tr>
                                        <th class="py-1.5 pr-2">Código</th>
                                        <th class="py-1.5 pr-2">Descrição</th>
                                        <th class="py-1.5 pr-2">Un.</th>
                                        <th class="py-1.5 pr-2 text-right">Quant.</th>
                                        <th class="py-1.5 pr-2 text-right">Custo un.</th>
                                        <th class="py-1.5 pr-2 text-right">Preço un.</th>
                                        <th class="py-1.5 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="i in itemsOf(c.id)" :key="i.id" class="cursor-pointer hover:bg-slate-50" @click="openItem(c.id, i)">
                                        <td class="py-1.5 pr-2 font-mono text-xs">{{ i.code }}</td>
                                        <td class="py-1.5 pr-2">
                                            {{ i.description }}
                                            <span v-if="i.components.length" class="block text-xs text-slate-500">{{ i.components.length }} {{ i.components.length === 1 ? 'recurso' : 'recursos' }}</span>
                                            <span v-if="i.measured_quantity !== null" class="block text-xs text-brand-700">Medido: {{ formatQty(i.measured_quantity) }} {{ i.unit }}</span>
                                        </td>
                                        <td class="py-1.5 pr-2">{{ i.unit }}</td>
                                        <td class="py-1.5 pr-2 text-right">{{ formatQty(i.quantity) }}</td>
                                        <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ formatMoney(i.unit_cost_cents) }}</td>
                                        <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ formatMoney(i.sale_unit_cents) }}<span v-if="i.unit_price_cents !== null" class="text-xs text-amber-700" title="Preço escrito à mão"> *</span></td>
                                        <td class="py-1.5 text-right font-medium whitespace-nowrap">{{ formatMoney(i.sale_cents) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else-if="!budget.chapters.some((x) => x.parent_id === c.id)" class="text-sm text-slate-500">Sem artigos neste capítulo.</p>
                    </AppCard>
                    <AppButton v-if="budget.can.edit" variant="secondary" @click="addChapter(null)">+ Capítulo</AppButton>
                </div>

                <div class="space-y-4">
                    <AppCard title="Custo por natureza" description="É o custo previsto que o mapa de custos compara com o realizado.">
                        <ul class="space-y-2 text-sm">
                            <li v-for="c in categories" :key="c.key">
                                <div class="flex justify-between"><span>{{ c.label }}</span><span class="font-medium">{{ formatMoney(c.cents) }}</span></div>
                                <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-brand-600" :style="{ width: `${c.pct}%` }" /></div>
                            </li>
                            <li v-if="!categories.length" class="text-slate-500">Sem custos.</li>
                        </ul>
                    </AppCard>
                    <AppCard v-if="budget.can.edit" title="Condições">
                        <form class="space-y-3" novalidate @submit.prevent="saveHeader">
                            <AppInput v-model="header.data.name" label="Nome" :error="header.errors.value.name" />
                            <div class="grid grid-cols-2 gap-3">
                                <AppInput v-model="header.data.overhead_pct" label="Encargos (%)" inputmode="decimal" :error="header.errors.value.overhead_pct" />
                                <AppInput v-model="header.data.profit_pct" label="Margem (%)" inputmode="decimal" :error="header.errors.value.profit_pct" />
                            </div>
                            <AppSelect
                                v-model="header.data.vat_mode"
                                label="IVA"
                                :options="[
                                    { value: 'normal', label: 'À taxa' },
                                    { value: 'reverse_charge', label: 'Autoliquidação' },
                                    { value: 'exempt', label: 'Isento' },
                                ]"
                            />
                            <AppInput v-if="header.data.vat_mode === 'normal'" v-model="header.data.vat_rate" label="Taxa (%)" inputmode="decimal" :error="header.errors.value.vat_rate" />
                            <AppButton type="submit" size="sm" :loading="header.processing.value">Guardar condições</AppButton>
                        </form>
                    </AppCard>
                </div>
            </div>
        </template>

        <AppModal :open="itemOpen" :title="editingItem ? `Artigo ${editingItem.code}` : 'Novo artigo'" size="lg" @close="itemOpen = false">
            <form id="item-form" class="space-y-4" novalidate @submit.prevent="saveItem">
                <AppAlert v-if="item.message.value" type="error">{{ item.message.value }}</AppAlert>
                <AppSelect v-if="!editingItem && articles.length" v-model="item.data.article_id" label="Da biblioteca de preços" :options="articleOptions" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppSelect v-model="item.data.chapter_id" label="Capítulo" :options="chapterOptions" :error="item.errors.value.chapter_id" />
                    <AppInput v-model="item.data.quantity" label="Quantidade" inputmode="decimal" :error="item.errors.value.quantity" />
                </div>
                <template v-if="editingItem || item.data.article_id === ''">
                    <div class="grid gap-4 sm:grid-cols-4">
                        <AppInput v-model="item.data.code" label="Código" placeholder="automático" />
                        <AppInput v-model="item.data.description" class="sm:col-span-2" label="Descrição" :error="item.errors.value.description" />
                        <AppInput v-model="item.data.unit" label="Unidade" :error="item.errors.value.unit" />
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-slate-800">Composição do preço</h3>
                        <div v-for="(c, i) in item.data.components" :key="i" class="mb-2 grid grid-cols-12 gap-1.5">
                            <select v-model="c.resource_id" class="col-span-12 rounded border border-slate-300 bg-white px-2 py-1.5 text-sm sm:col-span-3" :aria-label="`Recurso ${i + 1}`" @change="pickResource(c)">
                                <option value="">(escrever)</option>
                                <option v-for="r in resources" :key="r.id" :value="r.id">{{ r.code }} · {{ r.name }}</option>
                            </select>
                            <select v-model="c.kind" class="col-span-4 rounded border border-slate-300 bg-white px-1 py-1.5 text-sm sm:col-span-2" :aria-label="`Tipo ${i + 1}`">
                                <option v-for="k in KIND_OPTIONS" :key="k.value" :value="k.value">{{ k.label }}</option>
                            </select>
                            <input v-model="c.name" placeholder="recurso" class="col-span-8 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-3" :aria-label="`Nome ${i + 1}`" />
                            <input v-model="c.quantity" placeholder="rend." inputmode="decimal" class="col-span-3 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-1" :aria-label="`Rendimento ${i + 1}`" />
                            <input v-model="c.unit" placeholder="un" class="col-span-2 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-1" :aria-label="`Unidade ${i + 1}`" />
                            <input v-model="c.cost" placeholder="€/un" inputmode="decimal" class="col-span-5 rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-1" :aria-label="`Custo ${i + 1}`" />
                            <button type="button" class="col-span-2 text-red-700 sm:col-span-1" :aria-label="`Retirar ${i + 1}`" @click="item.data.components.splice(i, 1)">✕</button>
                        </div>
                        <button
                            type="button"
                            class="text-sm font-semibold text-brand-700 hover:underline"
                            @click="item.data.components.push({ resource_id: '', kind: 'material', name: '', unit: '', quantity: '1', cost: '' })"
                        >
                            + Juntar recurso
                        </button>
                        <p v-if="item.data.components.length" class="mt-2 text-sm">Custo unitário: <strong>{{ formatMoney(Math.round(componentsCost)) }}</strong></p>
                    </div>
                    <AppInput v-if="!item.data.components.length" v-model="item.data.cost" label="Custo unitário (€)" inputmode="decimal" :error="item.errors.value.unit_cost_cents" />
                </template>
                <AppInput v-model="item.data.price" label="Preço de venda unitário (€)" inputmode="decimal" hint="Vazio = custo × encargos × margem do orçamento." :error="item.errors.value.unit_price_cents" />
            </form>
            <template #footer>
                <AppButton v-if="editingItem && budget?.can.edit" variant="ghost" class="mr-auto text-red-700" @click="removeItem">Apagar artigo</AppButton>
                <AppButton variant="secondary" @click="itemOpen = false">{{ budget?.can.edit ? 'Cancelar' : 'Fechar' }}</AppButton>
                <AppButton v-if="budget?.can.edit" type="submit" form="item-form" :loading="item.processing.value">Guardar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
