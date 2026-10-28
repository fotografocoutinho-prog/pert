<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { exportsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { DataExport } from '@/api/types';
import { useToastStore } from '@/stores/toast';
import { formatBytes, formatDateTime } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const exports = ref<DataExport[]>([]);
const requesting = ref(false);
let poll: number | undefined;

const labels: Record<DataExport['status'], string> = {
    pending: 'Em fila',
    processing: 'A preparar',
    completed: 'Pronta',
    failed: 'Falhou',
    expired: 'Expirada',
};

async function load(): Promise<void> {
    exports.value = await exportsApi.list();
    const running = exports.value.some((e) => e.status === 'pending' || e.status === 'processing');
    window.clearTimeout(poll);
    if (running) poll = window.setTimeout(load, 10000);
}

onMounted(load);
onBeforeUnmount(() => window.clearTimeout(poll));

async function request(): Promise<void> {
    requesting.value = true;
    try {
        await exportsApi.create();
        useToastStore().success('Exportação pedida. Receberá um email quando estiver pronta.');
        await load();
    } catch (e) {
        useToastStore().error(e instanceof ApiError ? e.message : 'Falhou.');
    } finally {
        requesting.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader title="Exportação de dados" description="Descarregue todos os dados da empresa num ficheiro ZIP com JSON (portabilidade, RGPD).">
            <AppButton :loading="requesting" @click="request">Pedir exportação</AppButton>
        </PageHeader>

        <AppAlert class="mb-4">
            A exportação é preparada em segundo plano (normalmente em poucos minutos). O ficheiro fica disponível durante 48 horas e cada download é registado.
        </AppAlert>

        <AppCard>
            <EmptyState v-if="exports.length === 0" title="Ainda não pediu nenhuma exportação" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="item in exports" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div>
                        <p class="font-medium">Exportação de {{ formatDateTime(item.created_at) }}</p>
                        <p class="text-sm text-slate-500">
                            Pedida por {{ item.requested_by ?? '—' }}
                            <template v-if="item.size_bytes"> · {{ formatBytes(item.size_bytes) }}</template>
                            <template v-if="item.expires_at && item.is_downloadable"> · disponível até {{ formatDateTime(item.expires_at) }}</template>
                        </p>
                        <p v-if="item.error" class="text-sm text-red-700">{{ item.error }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <AppBadge :color="item.status === 'completed' ? 'green' : item.status === 'failed' ? 'red' : 'gray'">{{ labels[item.status] }}</AppBadge>
                        <a v-if="item.is_downloadable" :href="exportsApi.downloadUrl(item.id)" class="rounded-lg bg-brand-700 px-3 py-1.5 text-sm font-semibold text-white">Descarregar</a>
                    </div>
                </li>
            </ul>
        </AppCard>
    </div>
</template>
