<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { platformApi } from '@/api';
import type { Plan } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const plans = ref<Plan[]>([]);
const editing = ref<Plan | 'new' | null>(null);

const form = useForm({
    code: '',
    name: '',
    description: '',
    price_monthly: '' as string | number,
    price_yearly: '' as string | number,
    trial_days: 30 as string | number,
    max_users: '' as string | number,
    max_active_projects: '' as string | number,
    storage_mb: '' as string | number,
    features: '',
    is_public: true,
    is_active: true,
    sort_order: 50 as string | number,
});

async function load(): Promise<void> {
    plans.value = await platformApi.plans();
}
onMounted(load);

const toEuros = (cents: number | null) => (cents === null ? '' : cents / 100);
const toCents = (value: string | number) => (value === '' || value === null ? null : Math.round(Number(value) * 100));
const toLimit = (value: string | number) => (value === '' || value === null ? null : Number(value));

function open(plan: Plan | 'new'): void {
    editing.value = plan;
    if (plan === 'new') {
        form.reset();
        return;
    }
    form.reset({
        code: plan.code,
        name: plan.name,
        description: plan.description ?? '',
        price_monthly: toEuros(plan.price_monthly_cents),
        price_yearly: toEuros(plan.price_yearly_cents),
        trial_days: plan.trial_days,
        max_users: plan.limits.max_users ?? '',
        max_active_projects: plan.limits.max_active_projects ?? '',
        storage_mb: plan.limits.storage_mb ?? '',
        features: plan.features.join(', '),
        is_public: plan.is_public ?? true,
        is_active: plan.is_active ?? true,
        sort_order: plan.sort_order,
    });
}

async function save(): Promise<void> {
    const ok = await form.submit(async (d) => {
        const payload = {
            code: d.code,
            name: d.name,
            description: d.description || null,
            price_monthly_cents: toCents(d.price_monthly),
            price_yearly_cents: toCents(d.price_yearly),
            currency: 'EUR',
            trial_days: Number(d.trial_days),
            limits: { max_users: toLimit(d.max_users), max_active_projects: toLimit(d.max_active_projects), storage_mb: toLimit(d.storage_mb) },
            features: d.features.split(',').map((f) => f.trim()).filter(Boolean),
            is_public: d.is_public,
            is_active: d.is_active,
            sort_order: Number(d.sort_order),
        };
        if (editing.value === 'new') await platformApi.createPlan(payload);
        else if (editing.value) await platformApi.updatePlan(editing.value.id, payload);
        return true;
    }, { success: 'Plano guardado.' });
    if (ok) {
        editing.value = null;
        await load();
    }
}
</script>

<template>
    <div>
        <PageHeader title="Planos e preços" description="Preços (sem IVA), limites e funcionalidades. As alterações aplicam-se sem mudar código.">
            <AppButton @click="open('new')">Novo plano</AppButton>
        </PageHeader>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <AppCard v-for="plan in plans" :key="plan.id" :title="plan.name" :description="plan.code">
                <template #actions><AppButton size="sm" variant="secondary" @click="open(plan)">Editar</AppButton></template>
                <p class="text-2xl font-bold">{{ formatMoney(plan.price_monthly_cents, plan.currency) }}</p>
                <p class="text-xs text-slate-500">por mês, sem IVA</p>
                <ul class="mt-3 space-y-1 text-sm text-slate-600">
                    <li>Utilizadores: {{ plan.limits.max_users ?? 'ilimitado' }}</li>
                    <li>Obras ativas: {{ plan.limits.max_active_projects ?? 'ilimitado' }}</li>
                    <li>Armazenamento: {{ plan.limits.storage_mb ? `${plan.limits.storage_mb / 1024} GB` : 'ilimitado' }}</li>
                    <li>Trial: {{ plan.trial_days }} dias</li>
                </ul>
                <div class="mt-3 flex flex-wrap gap-1">
                    <AppBadge v-if="!plan.is_active" color="red">Inativo</AppBadge>
                    <AppBadge v-if="!plan.is_public" color="gray">Privado</AppBadge>
                    <AppBadge color="blue">{{ plan.subscriptions_count ?? 0 }} subscrições</AppBadge>
                </div>
            </AppCard>
        </div>

        <AppModal :open="editing !== null" :title="editing === 'new' ? 'Novo plano' : 'Editar plano'" size="lg" @close="editing = null">
            <form id="plan-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <AppAlert v-if="form.message.value" type="error" class="sm:col-span-2">{{ form.message.value }}</AppAlert>
                <AppInput v-model="form.data.code" label="Código" :disabled="editing !== 'new'" hint="Letras minúsculas, números e _. Não muda depois." :error="form.errors.value.code" />
                <AppInput v-model="form.data.name" label="Nome" required :error="form.errors.value.name" />
                <AppTextarea v-model="form.data.description" label="Descrição" class="sm:col-span-2" />
                <AppInput v-model="form.data.price_monthly" label="Preço mensal (€, sem IVA)" type="number" hint="Vazio = sob consulta" :error="form.errors.value.price_monthly_cents" />
                <AppInput v-model="form.data.price_yearly" label="Preço anual (€, sem IVA)" type="number" :error="form.errors.value.price_yearly_cents" />
                <AppInput v-model="form.data.max_users" label="Máx. utilizadores" type="number" hint="Vazio = ilimitado" />
                <AppInput v-model="form.data.max_active_projects" label="Máx. obras ativas" type="number" hint="Aplicado a partir da fase 2" />
                <AppInput v-model="form.data.storage_mb" label="Armazenamento (MB)" type="number" />
                <AppInput v-model="form.data.trial_days" label="Dias de trial" type="number" />
                <AppInput v-model="form.data.features" label="Funcionalidades" hint="Separadas por vírgulas (ex.: inspections, costs, ai)" class="sm:col-span-2" :error="form.errors.value['features.0']" />
                <AppInput v-model="form.data.sort_order" label="Ordem" type="number" />
                <div class="space-y-2">
                    <AppCheckbox v-model="form.data.is_public" label="Visível na página de preços" />
                    <AppCheckbox v-model="form.data.is_active" label="Ativo" />
                </div>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="editing = null">Cancelar</AppButton>
                <AppButton type="submit" form="plan-form" :loading="form.processing.value">Guardar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
