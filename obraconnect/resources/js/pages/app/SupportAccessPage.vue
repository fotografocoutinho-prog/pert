<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { supportApi } from '@/api';
import { ApiError } from '@/api/http';
import type { SupportGrant } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDateTime } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const grants = ref<SupportGrant[]>([]);
const form = useForm({ reason: '', hours: 4 });
const canWrite = computed(() => !session.isReadOnly);

const hourOptions = [1, 4, 8, 24, 48, 72].map((h) => ({ value: h, label: h === 1 ? '1 hora' : `${h} horas` }));

async function load(): Promise<void> {
    grants.value = await supportApi.list();
}

onMounted(load);

async function grant(): Promise<void> {
    const created = await form.submit((d) => supportApi.create(d.reason, Number(d.hours)), { success: 'Acesso autorizado.' });
    if (created) {
        form.reset();
        await load();
    }
}

async function revoke(g: SupportGrant): Promise<void> {
    try {
        await supportApi.revoke(g.id);
        useToastStore().success('Acesso revogado.');
        await load();
    } catch (e) {
        useToastStore().error(e instanceof ApiError ? e.message : 'Falhou.');
    }
}
</script>

<template>
    <div>
        <PageHeader
            title="Suporte técnico"
            description="A equipa ObraConnect só consegue ver os dados da empresa quando autoriza aqui, por tempo limitado e apenas em modo de leitura. Cada consulta fica registada na auditoria."
        />

        <div class="grid gap-4 lg:grid-cols-3">
            <AppCard v-if="canWrite" title="Autorizar acesso">
                <form class="space-y-4" novalidate @submit.prevent="grant">
                    <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                    <AppTextarea v-model="form.data.reason" label="Motivo" required :maxlength="500" hint="Ex.: número do pedido de suporte e o problema." :error="form.errors.value.reason" />
                    <AppSelect v-model="form.data.hours" label="Duração" :options="hourOptions" :error="form.errors.value.hours" />
                    <AppButton type="submit" block :loading="form.processing.value">Autorizar (só leitura)</AppButton>
                </form>
            </AppCard>

            <AppCard title="Autorizações" :class="canWrite ? 'lg:col-span-2' : 'lg:col-span-3'">
                <EmptyState v-if="grants.length === 0" title="Nenhuma autorização" description="O suporte não tem acesso aos dados da empresa." />
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="g in grants" :key="g.id" class="flex flex-wrap items-start justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="font-medium">{{ g.reason }}</p>
                            <p class="text-sm text-slate-500">
                                Por {{ g.granted_by ?? '—' }} · até {{ formatDateTime(g.expires_at) }}
                                <template v-if="g.last_used_at"> · último uso {{ formatDateTime(g.last_used_at) }} ({{ g.last_used_by }})</template>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <AppBadge :color="g.is_active ? 'green' : 'gray'">{{ g.is_active ? 'Ativa' : g.revoked_at ? 'Revogada' : 'Expirada' }}</AppBadge>
                            <AppButton v-if="g.is_active && canWrite" size="sm" variant="danger" @click="revoke(g)">Revogar</AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>
    </div>
</template>
