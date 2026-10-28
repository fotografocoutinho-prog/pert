<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { platformApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Plan, PlatformTenantDetail } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { eventLabel, formatBytes, formatDate, formatDateTime } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const route = useRoute();
const session = useSessionStore();
const toast = useToastStore();
const id = Number(route.params.id);
const tenant = ref<PlatformTenantDetail | null>(null);
const plans = ref<Plan[]>([]);

const canSuspend = computed(() => session.hasPlatformRole());
const canBilling = computed(() => session.hasPlatformRole('billing'));

async function load(): Promise<void> {
    tenant.value = await platformApi.tenant(id);
}

onMounted(async () => {
    await load();
    if (canBilling.value) plans.value = await platformApi.plans();
});

async function suspend(): Promise<void> {
    const reason = window.prompt('Motivo da suspensão (fica registado):');
    if (!reason) return;
    try {
        await platformApi.suspend(id, reason);
        toast.success('Empresa suspensa.');
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Falhou.');
    }
}

async function reactivate(): Promise<void> {
    try {
        await platformApi.reactivate(id);
        toast.success('Empresa reativada.');
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Falhou.');
    }
}

const subOpen = ref(false);
const subForm = useForm({
    plan_id: null as number | null,
    status: 'active',
    billing_cycle: 'monthly',
    trial_ends_at: '',
    current_period_ends_at: '',
    max_users: '' as string | number,
    notes: '',
});

function openSubscription(): void {
    const current = tenant.value?.subscriptions[0];
    subForm.reset({
        plan_id: tenant.value?.plan_id ?? null,
        status: current?.status ?? 'active',
        billing_cycle: current?.billing_cycle ?? 'monthly',
        trial_ends_at: current?.trial_ends_at?.slice(0, 10) ?? '',
        current_period_ends_at: current?.current_period_ends_at?.slice(0, 10) ?? '',
        max_users: current?.custom_limits?.max_users ?? '',
        notes: current?.notes ?? '',
    });
    subOpen.value = true;
}

async function saveSubscription(): Promise<void> {
    const ok = await subForm.submit(
        (d) =>
            platformApi
                .updateSubscription(id, {
                    plan_id: d.plan_id,
                    status: d.status,
                    billing_cycle: d.billing_cycle,
                    trial_ends_at: d.trial_ends_at || null,
                    current_period_ends_at: d.current_period_ends_at || null,
                    custom_limits: d.max_users === '' ? null : { max_users: Number(d.max_users) },
                    notes: d.notes || null,
                })
                .then(() => true),
        { success: 'Subscrição atualizada.' },
    );
    if (ok) {
        subOpen.value = false;
        await load();
    }
}

const statusOptions = [
    { value: 'trialing', label: 'Período experimental' },
    { value: 'active', label: 'Ativa' },
    { value: 'past_due', label: 'Pagamento em atraso' },
    { value: 'canceled', label: 'Cancelada' },
    { value: 'expired', label: 'Expirada' },
];
const colors = { active: 'green', read_only: 'amber', suspended: 'red' } as const;
</script>

<template>
    <div v-if="tenant">
        <PageHeader :title="tenant.name" :description="tenant.legal_name ?? undefined">
            <AppButton v-if="canBilling" variant="secondary" @click="openSubscription">Alterar subscrição</AppButton>
            <AppButton v-if="canSuspend && tenant.status !== 'suspended'" variant="danger" @click="suspend">Suspender</AppButton>
            <AppButton v-if="canSuspend && tenant.status !== 'active'" @click="reactivate">Reativar</AppButton>
        </PageHeader>

        <AppAlert class="mb-4">Por privacidade, o backoffice mostra apenas dados comerciais. O acesso a dados internos exige uma autorização dada pela empresa.</AppAlert>

        <div class="grid gap-4 lg:grid-cols-3">
            <AppCard title="Identificação">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Estado</dt><dd><AppBadge :color="colors[tenant.status]">{{ tenant.status_label }}</AppBadge></dd></div>
                    <div v-if="tenant.status_reason" class="flex justify-between gap-3"><dt class="text-slate-500">Motivo</dt><dd class="text-right">{{ tenant.status_reason }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">NIF</dt><dd>{{ tenant.nif ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Email</dt><dd class="truncate">{{ tenant.email ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Telefone</dt><dd>{{ tenant.phone ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Registo</dt><dd>{{ formatDate(tenant.created_at) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Armazenamento</dt><dd>{{ formatBytes(tenant.storage_used_bytes) }}</dd></div>
                </dl>
                <h3 class="mt-4 text-sm font-semibold">Administradores</h3>
                <ul class="mt-1 text-sm text-slate-600">
                    <li v-for="admin in tenant.admins" :key="admin.email">{{ admin.name }} · {{ admin.email }}</li>
                </ul>
            </AppCard>

            <AppCard title="Utilização">
                <ul class="space-y-2 text-sm">
                    <li v-for="(item, key) in tenant.usage" :key="key" class="flex justify-between"><span>{{ item.label }}</span><span>{{ item.used ?? 0 }} / {{ item.limit ?? '∞' }}</span></li>
                </ul>
            </AppCard>

            <AppCard title="Histórico de subscrições">
                <ul class="space-y-3 text-sm">
                    <li v-for="s in tenant.subscriptions" :key="s.id">
                        <p class="font-medium">{{ s.plan?.name }} · {{ s.status_label }}</p>
                        <p class="text-slate-500">Desde {{ formatDate(s.created_at) }}<template v-if="s.trial_ends_at"> · trial até {{ formatDate(s.trial_ends_at) }}</template></p>
                    </li>
                </ul>
            </AppCard>
        </div>

        <AppCard title="Ações da plataforma e do suporte nesta empresa" class="mt-4">
            <p v-if="tenant.platform_events.length === 0" class="text-sm text-slate-500">Sem registos.</p>
            <ul v-else class="divide-y divide-slate-100 text-sm">
                <li v-for="e in tenant.platform_events" :key="e.id" class="flex flex-wrap justify-between gap-2 py-2">
                    <span>{{ eventLabel(e.event) }} · {{ e.actor?.name ?? 'Sistema' }}</span>
                    <span class="text-slate-500">{{ formatDateTime(e.created_at) }}</span>
                </li>
            </ul>
        </AppCard>

        <AppModal :open="subOpen" title="Alterar subscrição" @close="subOpen = false">
            <form id="sub-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveSubscription">
                <AppAlert v-if="subForm.message.value" type="error" class="sm:col-span-2">{{ subForm.message.value }}</AppAlert>
                <AppSelect v-model="subForm.data.plan_id" label="Plano" :options="plans.map((p) => ({ value: p.id, label: p.name }))" required class="sm:col-span-2" :error="subForm.errors.value.plan_id" />
                <AppSelect v-model="subForm.data.status" label="Estado" :options="statusOptions" />
                <AppSelect v-model="subForm.data.billing_cycle" label="Faturação" :options="[{ value: 'monthly', label: 'Mensal' }, { value: 'yearly', label: 'Anual' }]" />
                <AppInput v-model="subForm.data.trial_ends_at" label="Fim do trial" type="date" />
                <AppInput v-model="subForm.data.current_period_ends_at" label="Fim do período" type="date" />
                <AppInput v-model="subForm.data.max_users" label="Limite de utilizadores (personalizado)" type="number" hint="Vazio = limite do plano." class="sm:col-span-2" />
                <AppTextarea v-model="subForm.data.notes" label="Notas internas" class="sm:col-span-2" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="subOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="sub-form" :loading="subForm.processing.value">Guardar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
