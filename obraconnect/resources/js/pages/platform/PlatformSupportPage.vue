<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { platformApi } from '@/api';
import { ApiError } from '@/api/http';
import type { SupportGrant } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDateTime } from '@/utils/format';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const grants = ref<SupportGrant[]>([]);
const session = useSessionStore();
const router = useRouter();

onMounted(async () => (grants.value = await platformApi.supportGrants()));

async function enter(grant: SupportGrant): Promise<void> {
    try {
        await platformApi.enterSupport(grant.id);
        await session.refresh();
        useToastStore().info(`Modo de suporte em ${grant.tenant?.name}. Só leitura e auditado.`);
        await router.push('/app');
    } catch (e) {
        useToastStore().error(e instanceof ApiError ? e.message : 'Falhou.');
    }
}
</script>

<template>
    <div>
        <PageHeader title="Pedidos de suporte" description="Autorizações ativas dadas pelas empresas. Cada consulta é registada na auditoria da empresa." />
        <AppCard>
            <EmptyState v-if="grants.length === 0" title="Nenhuma autorização ativa" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="g in grants" :key="g.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div>
                        <p class="font-medium">{{ g.tenant?.name }}</p>
                        <p class="text-sm text-slate-600">{{ g.reason }}</p>
                        <p class="text-xs text-slate-500">Autorizado por {{ g.granted_by ?? '—' }} · expira {{ formatDateTime(g.expires_at) }}</p>
                    </div>
                    <AppButton size="sm" @click="enter(g)">Entrar (só leitura)</AppButton>
                </li>
            </ul>
        </AppCard>
    </div>
</template>
