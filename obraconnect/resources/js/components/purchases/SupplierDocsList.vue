<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ApiError } from '@/api/http';
import { supplierDocsApi, type SupplierDocList, type SupplierDocStatus } from '@/api/economics';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

/** Caixa de faturas e guias de fornecedores: da empresa toda ou só de uma obra (projectId). */
const props = defineProps<{ projectId?: number | null }>();

const session = useSessionStore();
const toast = useToastStore();

const TABS: { key: SupplierDocStatus | ''; label: string }[] = [
    { key: 'to_review', label: 'Por rever' },
    { key: 'reading', label: 'A ler' },
    { key: 'posted', label: 'Lançados' },
    { key: 'rejected', label: 'Rejeitados' },
    { key: '', label: 'Todos' },
];
const status = ref<SupplierDocStatus | ''>('to_review');
const search = ref('');
const result = ref<SupplierDocList | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(quiet = false): Promise<void> {
    if (!quiet) loading.value = true;
    try {
        result.value = await supplierDocsApi.list({ status: status.value || undefined, search: search.value || undefined, project_id: props.projectId ?? undefined });
        error.value = null;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível carregar.';
    } finally {
        loading.value = false;
    }
    schedulePoll();
}
let searchTimer: number | undefined;
watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => load(), 300);
});
watch([status, () => props.projectId], () => load(), { immediate: true });

// Enquanto houver documentos a ser lidos, volta a perguntar (a fila corre de minuto a minuto).
let pollTimer: number | undefined;
let polls = 0;
function schedulePoll(): void {
    window.clearTimeout(pollTimer);
    if ((result.value?.meta.counts.reading ?? 0) > 0 && polls < 60) {
        polls++;
        pollTimer = window.setTimeout(() => load(true), 5000);
    } else polls = 0;
}
onBeforeUnmount(() => {
    window.clearTimeout(pollTimer);
    window.clearTimeout(searchTimer);
});

const canUpload = computed(() => (result.value?.meta.can_upload ?? false) && !session.isReadOnly);
const uploading = ref<{ name: string; percent: number }[]>([]);
const dragging = ref(false);
async function upload(files: FileList | File[] | null): Promise<void> {
    const list = Array.from(files ?? []);
    if (!list.length) return;
    let sent = 0;
    for (const file of list) {
        const row = { name: file.name, percent: 0 };
        uploading.value.push(row);
        try {
            await supplierDocsApi.upload(file, props.projectId, (p) => (row.percent = p));
            sent++;
        } catch (e) {
            toast.error(`${file.name}: ${e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'não foi enviado.'}`);
        } finally {
            uploading.value = uploading.value.filter((r) => r !== row);
        }
    }
    if (sent) {
        toast.success(sent === 1 ? 'Documento recebido.' : `${sent} documentos recebidos.`);
        if (status.value !== '' && status.value !== 'to_review' && status.value !== 'reading') status.value = result.value?.meta.reader_enabled ? 'reading' : 'to_review';
        else await load(true);
    }
}
function onDrop(event: DragEvent): void {
    dragging.value = false;
    if (canUpload.value) upload(event.dataTransfer?.files ?? null);
}

const color = (s: string) => ({ reading: 'blue', to_review: 'amber', posted: 'green', rejected: 'gray' })[s] as 'blue' | 'amber' | 'green' | 'gray';
</script>

<template>
    <div>
        <label
            v-if="canUpload"
            class="mb-4 flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed px-4 py-6 text-center"
            :class="dragging ? 'border-brand-600 bg-brand-50' : 'border-slate-300 bg-white hover:bg-slate-50'"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <span class="font-semibold text-slate-800">Juntar faturas, notas de crédito, talões ou guias</span>
            <span class="text-sm text-slate-500">
                PDF ou fotografia · arraste para aqui ou toque para escolher
                <template v-if="result && !result.meta.reader_enabled"> · a leitura automática não está ligada: os dados preenchem-se à mão</template>
            </span>
            <input type="file" class="sr-only" accept="application/pdf,image/jpeg,image/png,image/webp" multiple @change="upload(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''" />
        </label>
        <ul v-if="uploading.length" class="mb-3 space-y-1 text-sm text-slate-600">
            <li v-for="u in uploading" :key="u.name" class="flex items-center gap-2"><AppSpinner class="h-4 w-4 text-brand-700" /> {{ u.name }} · {{ u.percent }}%</li>
        </ul>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <button
                v-for="t in TABS"
                :key="t.key"
                type="button"
                class="rounded-full border px-3 py-1.5 text-sm font-medium"
                :class="status === t.key ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                @click="status = t.key"
            >
                {{ t.label }}<template v-if="t.key && result?.meta.counts[t.key]"> ({{ result.meta.counts[t.key] }})</template>
            </button>
            <div class="ml-auto w-full sm:w-64"><AppInput v-model="search" aria-label="Procurar" placeholder="Fornecedor, NIF, número, ficheiro" /></div>
        </div>

        <AppAlert v-if="error" type="error">{{ error }}</AppAlert>
        <div v-else-if="loading && !result" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
        <EmptyState
            v-else-if="!result?.data.length"
            title="Sem documentos"
            :description="status === 'to_review' ? 'Os documentos que juntar ficam aqui até serem revistos e lançados.' : undefined"
        />
        <ul v-else class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
            <li v-for="d in result.data" :key="d.id">
                <RouterLink :to="`/app/faturas-fornecedores/${d.id}`" class="flex flex-wrap items-start justify-between gap-2 px-3 py-2.5 hover:bg-slate-50">
                    <span class="min-w-0">
                        <strong>{{ d.supplier ?? 'Fornecedor por identificar' }}</strong>
                        <template v-if="d.number"> · {{ d.number }}</template>
                        <span v-if="d.attention" class="ml-1 text-amber-700" title="Há avisos a confirmar">⚠</span>
                        <span class="block truncate text-xs text-slate-500">
                            {{ [d.kind_label, d.issue_date ? formatDate(d.issue_date) : null, !projectId && d.project ? `${d.project.code} · ${d.project.name}` : null, d.original_name].filter(Boolean).join(' · ') }}
                        </span>
                    </span>
                    <span class="flex items-center gap-2 whitespace-nowrap">
                        <span v-if="d.gross_cents !== null || d.net_cents !== null" class="text-right">
                            <span class="block font-semibold">{{ formatMoney(d.gross_cents ?? d.net_cents) }}</span>
                            <span v-if="d.net_cents !== null" class="block text-xs text-slate-500">{{ formatMoney(d.net_cents) }} s/ IVA</span>
                        </span>
                        <AppBadge :color="color(d.status)">{{ d.status_label }}</AppBadge>
                    </span>
                </RouterLink>
            </li>
        </ul>
    </div>
</template>
