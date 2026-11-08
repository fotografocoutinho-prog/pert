<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError } from '@/api/http';
import { invoicingApi, type InvoiceDraftSummary } from '@/api/economics';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();
const router = useRouter();
const canManage = computed(() => session.can('invoicing.manage') && !session.isReadOnly);

const TABS = [
    { key: 'ready', label: 'Prontas a faturar' },
    { key: 'draft', label: 'Rascunhos' },
    { key: 'invoiced', label: 'Faturadas' },
    { key: 'cancelled', label: 'Anuladas' },
    { key: '', label: 'Todas' },
];
const status = ref('ready');
const search = ref('');
const list = ref<InvoiceDraftSummary[]>([]);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    try {
        list.value = await invoicingApi.list({ status: status.value || undefined, search: search.value || undefined });
    } finally {
        loading.value = false;
    }
}
let timer: number | undefined;
watch(search, () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(load, 300);
});
watch(status, load, { immediate: true });

const totals = computed(() => ({
    net: list.value.reduce((s, d) => s + d.net_cents, 0),
    total: list.value.reduce((s, d) => s + d.total_cents, 0),
}));
const exportParams = computed(() => (status.value ? { status: status.value, search: search.value || undefined } : { ids: list.value.map((d) => d.id) }));
const color = (s: string) => ({ draft: 'amber', ready: 'blue', invoiced: 'green', cancelled: 'gray' })[s] as 'amber' | 'blue' | 'green' | 'gray';

const creating = ref(false);
async function create(): Promise<void> {
    const name = window.prompt('Nome do cliente (pode escolher um cliente da lista a seguir)');
    if (!name?.trim()) return;
    creating.value = true;
    try {
        const d = await invoicingApi.create({ client_name: name.trim() });
        router.push(`/app/faturacao/${d.id}`);
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível criar.');
    } finally {
        creating.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader title="Faturação" description="Pré-faturas: documentos internos para exportar para o programa de faturação certificado, onde se emite a fatura oficial.">
            <AppButton v-if="canManage" :loading="creating" @click="create">Nova pré-fatura</AppButton>
        </PageHeader>

        <AppAlert class="mb-4">
            As pré-faturas <strong>não têm valor fiscal</strong> e não servem de fatura. Prepare-as aqui (os autos aprovados têm o botão <strong>Criar pré-fatura</strong>),
            exporte as prontas para o seu programa de faturação e, depois de emitir a fatura oficial, registe o número dela em cada pré-fatura.
        </AppAlert>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <button
                v-for="t in TABS"
                :key="t.key"
                type="button"
                class="rounded-full border px-3 py-1.5 text-sm font-medium"
                :class="status === t.key ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                @click="status = t.key"
            >
                {{ t.label }}
            </button>
            <div class="ml-auto w-full sm:w-64"><AppInput v-model="search" aria-label="Procurar" placeholder="Cliente, NIF, referência, fatura" /></div>
        </div>

        <AppCard>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-600">{{ list.length }} {{ list.length === 1 ? 'documento' : 'documentos' }} ·{{ formatMoney(totals.net) }} sem IVA · {{ formatMoney(totals.total) }} com IVA</p>
                <div v-if="list.length" class="flex flex-wrap gap-2 text-sm">
                    <span class="text-slate-500">Exportar para o programa de faturação:</span>
                    <a :href="appUrl(invoicingApi.exportUrl('xlsx', exportParams))" class="font-semibold text-brand-700 hover:underline">Excel</a>
                    <a :href="appUrl(invoicingApi.exportUrl('csv', exportParams))" class="font-semibold text-brand-700 hover:underline">CSV</a>
                    <a :href="appUrl(invoicingApi.exportUrl('json', exportParams))" class="font-semibold text-brand-700 hover:underline">JSON</a>
                </div>
            </div>
            <div v-if="loading" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
            <EmptyState v-else-if="!list.length" title="Sem pré-faturas" :description="status === 'ready' ? 'Dê como prontas as pré-faturas em rascunho para as exportar.' : undefined" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="d in list" :key="d.id">
                    <RouterLink :to="`/app/faturacao/${d.id}`" class="flex flex-wrap items-start justify-between gap-2 py-2.5 hover:bg-slate-50">
                        <span class="min-w-0">
                            <strong>{{ d.code ?? 'Rascunho' }}</strong> · {{ d.client_name }}<template v-if="d.client_nif"> ({{ d.client_nif }})</template>
                            <span class="block text-xs text-slate-500">
                                {{ [d.issue_date ? formatDate(d.issue_date) : null, d.project ? `${d.project.code} · ${d.project.name}` : null, d.reference].filter(Boolean).join(' · ') }}
                                <template v-if="d.official_number"> · fatura {{ d.official_number }} de {{ formatDate(d.official_date) }}</template>
                            </span>
                        </span>
                        <span class="flex items-center gap-2 whitespace-nowrap">
                            <span class="text-right">
                                <span class="block font-semibold">{{ formatMoney(d.total_cents) }}</span>
                                <span class="block text-xs text-slate-500">{{ formatMoney(d.net_cents) }} s/ IVA</span>
                            </span>
                            <AppBadge :color="color(d.status)">{{ d.status_label }}</AppBadge>
                        </span>
                    </RouterLink>
                </li>
            </ul>
        </AppCard>
    </div>
</template>
