<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError } from '@/api/http';
import { centsToInput, eurosToCents, formatQty, parseNumber, priceLibraryApi, type Article, type Option, type PriceResource } from '@/api/economics';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();
const canManage = computed(() => session.can('budgets.manage') && !session.isReadOnly);

const tab = ref<'resources' | 'articles'>('articles');
const kinds = ref<Option[]>([]);
const resources = ref<PriceResource[]>([]);
const articles = ref<Article[]>([]);
const loading = ref(true);
const search = ref('');
const showArchived = ref(false);

async function load(): Promise<void> {
    const data = await priceLibraryApi.get();
    kinds.value = data.kinds;
    resources.value = data.resources;
    articles.value = data.articles;
    loading.value = false;
}
onMounted(load);

const match = (text: string) => !search.value || text.toLowerCase().includes(search.value.toLowerCase());
const visibleResources = computed(() => resources.value.filter((r) => (showArchived.value || !r.archived) && match(`${r.code} ${r.name}`)));
const visibleArticles = computed(() => articles.value.filter((a) => (showArchived.value || !a.archived) && match(`${a.code} ${a.description}`)));
const fail = (e: unknown, fallback: string) => toast.error(e instanceof ApiError ? e.message : fallback);

// ---- Recurso ----
const resourceOpen = ref(false);
const editingResource = ref<PriceResource | null>(null);
const resourceForm = useForm({ kind: 'material', code: '', name: '', unit: '', cost: '' });
function openResource(r: PriceResource | null): void {
    editingResource.value = r;
    resourceForm.reset(r ? { kind: r.kind, code: r.code, name: r.name, unit: r.unit, cost: centsToInput(r.unit_cost_cents) } : {});
    resourceOpen.value = true;
}
async function saveResource(): Promise<void> {
    const payload = { kind: resourceForm.data.kind, code: resourceForm.data.code, name: resourceForm.data.name, unit: resourceForm.data.unit, unit_cost_cents: eurosToCents(resourceForm.data.cost) ?? 0 };
    const id = editingResource.value?.id;
    const saved = await resourceForm.submit(() => (id ? priceLibraryApi.updateResource(id, payload) : priceLibraryApi.createResource(payload)), { success: 'Recurso guardado.' });
    if (saved) {
        resourceOpen.value = false;
        await load();
    }
}
async function toggleResource(r: PriceResource): Promise<void> {
    try {
        if (r.used) await priceLibraryApi.updateResource(r.id, { archived: !r.archived });
        else if (window.confirm(`Apagar o recurso ${r.code}?`)) await priceLibraryApi.removeResource(r.id);
        await load();
    } catch (e) {
        fail(e, 'Não foi possível.');
    }
}

// ---- Artigo ----
const articleOpen = ref(false);
const editingArticle = ref<Article | null>(null);
const articleForm = useForm({ code: '', description: '', unit: '', cost: '', notes: '', components: [] as { resource_id: number | ''; quantity: string }[] });
function openArticle(a: Article | null): void {
    editingArticle.value = a;
    articleForm.reset(
        a
            ? {
                  code: a.code,
                  description: a.description,
                  unit: a.unit,
                  cost: a.composed ? '' : centsToInput(a.unit_cost_cents),
                  notes: a.notes ?? '',
                  components: a.components.map((c) => ({ resource_id: c.resource_id, quantity: String(c.quantity).replace('.', ',') })),
              }
            : { components: [] },
    );
    articleOpen.value = true;
}
const resourceOptions = computed(() => [
    { value: '', label: 'Escolha um recurso…' },
    ...resources.value.filter((r) => !r.archived).map((r) => ({ value: r.id, label: `${r.code} · ${r.name} (${formatMoney(r.unit_cost_cents)}/${r.unit})` })),
]);
const composedCost = computed(() =>
    articleForm.data.components.reduce((sum, c) => {
        const r = resources.value.find((x) => x.id === c.resource_id);
        return sum + (r ? (parseNumber(c.quantity) ?? 0) * r.unit_cost_cents : 0);
    }, 0),
);
async function saveArticle(): Promise<void> {
    const components = articleForm.data.components.filter((c) => c.resource_id !== '').map((c) => ({ resource_id: Number(c.resource_id), quantity: parseNumber(c.quantity) ?? 0 }));
    const payload: Record<string, unknown> = {
        code: articleForm.data.code,
        description: articleForm.data.description,
        unit: articleForm.data.unit,
        notes: articleForm.data.notes || null,
        components,
    };
    if (components.length === 0) payload.unit_cost_cents = eurosToCents(articleForm.data.cost) ?? 0;
    const id = editingArticle.value?.id;
    const saved = await articleForm.submit(() => (id ? priceLibraryApi.updateArticle(id, payload) : priceLibraryApi.createArticle(payload)), { success: 'Artigo guardado.' });
    if (saved) {
        articleOpen.value = false;
        await load();
    }
}
async function toggleArticle(a: Article): Promise<void> {
    try {
        await priceLibraryApi.removeArticle(a.id).catch(async (e) => {
            if (e instanceof ApiError && e.code === 'ARTICLE_IN_USE') return priceLibraryApi.updateArticle(a.id, { archived: !a.archived });
            throw e;
        });
        await load();
    } catch (e) {
        fail(e, 'Não foi possível.');
    }
}
</script>

<template>
    <div>
        <PageHeader title="Biblioteca de preços" description="Recursos com o seu custo e artigos para os orçamentos. Um artigo composto soma os recursos × rendimento; os orçamentos guardam uma cópia.">
            <AppButton v-if="canManage" variant="secondary" @click="openResource(null)">Novo recurso</AppButton>
            <AppButton v-if="canManage" @click="openArticle(null)">Novo artigo</AppButton>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button
                v-for="t in [
                    { key: 'articles', label: `Artigos (${articles.length})` },
                    { key: 'resources', label: `Recursos (${resources.length})` },
                ]"
                :key="t.key"
                type="button"
                class="rounded-full border px-3 py-1.5 text-sm font-medium"
                :class="tab === t.key ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700'"
                @click="tab = t.key as 'articles' | 'resources'"
            >
                {{ t.label }}
            </button>
            <input v-model="search" type="search" placeholder="Procurar" aria-label="Procurar na biblioteca" class="ml-auto w-56 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <AppCheckbox v-model="showArchived" label="Arquivados" />
        </div>

        <div v-if="loading" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <AppCard v-else-if="tab === 'articles'">
            <EmptyState v-if="visibleArticles.length === 0" title="Sem artigos" :description="canManage ? 'Ex.: EST-01 Betão C25/30 em lajes (m3), composto por betão, cofragem e mão de obra.' : undefined" />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr>
                            <th class="py-2 pr-3">Código</th>
                            <th class="py-2 pr-3">Descrição</th>
                            <th class="py-2 pr-3">Un.</th>
                            <th class="py-2 pr-3 text-right">Custo</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="a in visibleArticles" :key="a.id" :class="a.archived ? 'text-slate-400' : ''">
                            <td class="py-2 pr-3 font-mono text-xs font-semibold">{{ a.code }}</td>
                            <td class="py-2 pr-3">
                                {{ a.description }}
                                <span v-if="a.composed" class="block text-xs text-slate-500">{{ a.components.map((c) => `${formatQty(c.quantity, 4)} ${c.unit} ${c.name}`).join(' + ') }}</span>
                            </td>
                            <td class="py-2 pr-3">{{ a.unit }}</td>
                            <td class="py-2 pr-3 text-right whitespace-nowrap">{{ formatMoney(a.unit_cost_cents) }}</td>
                            <td class="py-2 text-right whitespace-nowrap">
                                <template v-if="canManage">
                                    <AppButton size="sm" variant="ghost" @click="openArticle(a)">Editar</AppButton>
                                    <AppButton size="sm" variant="ghost" class="text-red-700" @click="toggleArticle(a)">{{ a.archived ? 'Reativar' : 'Retirar' }}</AppButton>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
        <AppCard v-else>
            <EmptyState v-if="visibleResources.length === 0" title="Sem recursos" :description="canManage ? 'Ex.: Betão C25/30 (material, m3), Pedreiro de 1.ª (mão de obra, h), Retroescavadora (equipamento, h).' : undefined" />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr>
                            <th class="py-2 pr-3">Código</th>
                            <th class="py-2 pr-3">Nome</th>
                            <th class="py-2 pr-3">Tipo</th>
                            <th class="py-2 pr-3">Un.</th>
                            <th class="py-2 pr-3 text-right">Custo</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="r in visibleResources" :key="r.id" :class="r.archived ? 'text-slate-400' : ''">
                            <td class="py-2 pr-3 font-mono text-xs font-semibold">{{ r.code }}</td>
                            <td class="py-2 pr-3">{{ r.name }}</td>
                            <td class="py-2 pr-3"><AppBadge>{{ r.kind_label }}</AppBadge></td>
                            <td class="py-2 pr-3">{{ r.unit }}</td>
                            <td class="py-2 pr-3 text-right whitespace-nowrap">{{ formatMoney(r.unit_cost_cents) }}</td>
                            <td class="py-2 text-right whitespace-nowrap">
                                <template v-if="canManage">
                                    <AppButton size="sm" variant="ghost" @click="openResource(r)">Editar</AppButton>
                                    <AppButton size="sm" variant="ghost" class="text-red-700" @click="toggleResource(r)">{{ r.used ? (r.archived ? 'Reativar' : 'Arquivar') : 'Apagar' }}</AppButton>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>

        <AppModal :open="resourceOpen" :title="editingResource ? 'Editar recurso' : 'Novo recurso'" @close="resourceOpen = false">
            <form id="resource-form" class="space-y-4" novalidate @submit.prevent="saveResource">
                <AppAlert v-if="resourceForm.message.value" type="error">{{ resourceForm.message.value }}</AppAlert>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppSelect v-model="resourceForm.data.kind" label="Tipo" :options="kinds" :error="resourceForm.errors.value.kind" />
                    <AppInput v-model="resourceForm.data.code" label="Código" required :maxlength="30" :error="resourceForm.errors.value.code" />
                </div>
                <AppInput v-model="resourceForm.data.name" label="Nome" required :maxlength="200" :error="resourceForm.errors.value.name" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="resourceForm.data.unit" label="Unidade" required placeholder="m3, h, kg, un" :maxlength="20" :error="resourceForm.errors.value.unit" />
                    <AppInput v-model="resourceForm.data.cost" label="Custo unitário (€)" inputmode="decimal" :error="resourceForm.errors.value.unit_cost_cents" />
                </div>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="resourceOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="resource-form" :loading="resourceForm.processing.value">Guardar</AppButton>
            </template>
        </AppModal>

        <AppModal :open="articleOpen" :title="editingArticle ? 'Editar artigo' : 'Novo artigo'" size="lg" @close="articleOpen = false">
            <form id="article-form" class="space-y-4" novalidate @submit.prevent="saveArticle">
                <AppAlert v-if="articleForm.message.value" type="error">{{ articleForm.message.value }}</AppAlert>
                <div class="grid gap-4 sm:grid-cols-3">
                    <AppInput v-model="articleForm.data.code" label="Código" required :maxlength="30" :error="articleForm.errors.value.code" />
                    <AppInput v-model="articleForm.data.unit" label="Unidade" required :maxlength="20" :error="articleForm.errors.value.unit" />
                    <AppInput
                        v-if="!articleForm.data.components.length"
                        v-model="articleForm.data.cost"
                        label="Custo unitário (€)"
                        inputmode="decimal"
                        :error="articleForm.errors.value.unit_cost_cents"
                    />
                    <div v-else class="text-sm">
                        <p class="mb-1 font-medium text-slate-700">Custo (composição)</p>
                        <p class="py-2.5 font-semibold">{{ formatMoney(Math.round(composedCost)) }}</p>
                    </div>
                </div>
                <AppInput v-model="articleForm.data.description" label="Descrição" required :maxlength="1000" :error="articleForm.errors.value.description" />
                <div>
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Composição (opcional)</h3>
                    <div v-for="(c, i) in articleForm.data.components" :key="i" class="mb-2 flex gap-2">
                        <select v-model="c.resource_id" class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm" :aria-label="`Recurso ${i + 1}`">
                            <option v-for="o in resourceOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                        <input v-model="c.quantity" inputmode="decimal" placeholder="rendimento" class="w-28 rounded-lg border border-slate-300 px-2 py-2 text-sm" :aria-label="`Rendimento ${i + 1}`" />
                        <button type="button" class="px-2 text-red-700" :aria-label="`Retirar ${i + 1}`" @click="articleForm.data.components.splice(i, 1)">✕</button>
                    </div>
                    <button type="button" class="text-sm font-semibold text-brand-700 hover:underline" @click="articleForm.data.components.push({ resource_id: '', quantity: '1' })">+ Juntar recurso</button>
                </div>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="articleOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="article-form" :loading="articleForm.processing.value">Guardar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
