<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { platformApi } from '@/api';
import type { Coupon, Plan } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { formatDate } from '@/utils/format';
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

const coupons = ref<Coupon[] | null>(null);
const plans = ref<Plan[]>([]);
const editing = ref<Coupon | 'new' | null>(null);
const kinds = [
    { value: 'percent', label: 'Percentagem de desconto' },
    { value: 'amount', label: 'Valor de desconto (€)' },
    { value: 'trial', label: 'Dias extra de período experimental' },
];
const form = useForm({
    code: '',
    description: '',
    kind: 'percent' as string | number,
    value: '' as string | number,
    max_redemptions: '' as string | number,
    valid_from: '',
    valid_until: '',
    is_active: true,
});
const planIds = ref<Record<number, boolean>>({});
const cycles = ref<Record<string, boolean>>({ monthly: false, yearly: false });
const used = computed(() => editing.value !== null && editing.value !== 'new' && editing.value.redemptions_count > 0);

async function load(): Promise<void> {
    const [c, p] = await Promise.all([platformApi.coupons(), platformApi.plans()]);
    coupons.value = c;
    plans.value = p;
}
onMounted(load);

const day = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('sv-SE', { timeZone: 'Europe/Lisbon' }) : '');

function open(c: Coupon | 'new'): void {
    editing.value = c;
    if (c === 'new') {
        form.reset();
        planIds.value = {};
        cycles.value = { monthly: false, yearly: false };
        return;
    }
    form.reset({
        code: c.code,
        description: c.description ?? '',
        kind: c.percent_off ? 'percent' : c.amount_off_cents ? 'amount' : 'trial',
        value: c.percent_off ?? (c.amount_off_cents ? c.amount_off_cents / 100 : c.trial_extension_days ?? ''),
        max_redemptions: c.max_redemptions ?? '',
        valid_from: day(c.valid_from),
        valid_until: day(c.valid_until),
        is_active: c.is_active,
    });
    planIds.value = Object.fromEntries(c.plan_ids.map((id) => [id, true]));
    cycles.value = { monthly: c.billing_cycles.includes('monthly'), yearly: c.billing_cycles.includes('yearly') };
}

async function save(): Promise<void> {
    const d = form.data;
    const v = d.value === '' ? null : Number(d.value);
    const payload = {
        code: d.code,
        description: d.description || null,
        percent_off: d.kind === 'percent' ? v : null,
        amount_off_cents: d.kind === 'amount' && v !== null ? Math.round(v * 100) : null,
        trial_extension_days: d.kind === 'trial' ? v : null,
        plan_ids: Object.entries(planIds.value).filter(([, on]) => on).map(([id]) => Number(id)),
        billing_cycles: Object.entries(cycles.value).filter(([, on]) => on).map(([c]) => c),
        max_redemptions: d.max_redemptions === '' ? null : Number(d.max_redemptions),
        valid_from: d.valid_from || null,
        valid_until: d.valid_until || null,
        is_active: d.is_active,
    };
    const target = editing.value;
    const saved = await form.submit(() => (target === 'new' || target === null ? platformApi.createCoupon(payload) : platformApi.updateCoupon(target.id, payload)), {
        success: 'Cupão guardado.',
    });
    if (saved) {
        editing.value = null;
        await load();
    }
}

const planName = (id: number) => plans.value.find((p) => p.id === id)?.name ?? `#${id}`;
</script>

<template>
    <div>
        <PageHeader title="Cupões" description="Desconto num pedido de ativação ou renovação, ou dias extra de período experimental. Cada empresa usa cada cupão uma vez." />

        <AppCard title="Cupões">
            <template #actions><AppButton size="sm" @click="open('new')">Novo cupão</AppButton></template>
            <div v-if="!coupons" class="flex justify-center py-10"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
            <EmptyState v-else-if="coupons.length === 0" title="Nenhum cupão" description="Crie um código para uma campanha ou para um cliente." />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="c in coupons" :key="c.id" class="flex flex-wrap items-start justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="font-medium"><code class="rounded bg-slate-100 px-1.5 py-0.5">{{ c.code }}</code> · {{ c.summary }}</p>
                        <p v-if="c.description" class="text-sm text-slate-600">{{ c.description }}</p>
                        <p class="text-xs text-slate-500">
                            {{ c.redemptions_count }}<template v-if="c.max_redemptions"> de {{ c.max_redemptions }}</template> usos
                            <template v-if="c.plan_ids.length"> · planos: {{ c.plan_ids.map(planName).join(', ') }}</template>
                            <template v-if="c.billing_cycles.length"> · {{ c.billing_cycles.map((x) => (x === 'yearly' ? 'anual' : 'mensal')).join(' ou ') }}</template>
                            <template v-if="c.valid_from"> · desde {{ formatDate(c.valid_from) }}</template>
                            <template v-if="c.valid_until"> · até {{ formatDate(c.valid_until) }}</template>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <AppBadge :color="c.is_active ? 'green' : 'gray'">{{ c.is_active ? 'Ativo' : 'Desativado' }}</AppBadge>
                        <AppButton size="sm" variant="secondary" @click="open(c)">Editar</AppButton>
                    </div>
                </li>
            </ul>
        </AppCard>

        <AppModal :open="editing !== null" :title="editing === 'new' ? 'Novo cupão' : 'Editar cupão'" @close="editing = null">
            <form id="coupon-form" class="space-y-4" novalidate @submit.prevent="save">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <AppAlert v-if="used" type="info">Já foi usado: o código e o desconto ficam como estão. Pode mudar a validade, os limites ou desativá-lo.</AppAlert>
                <AppInput v-model="form.data.code" label="Código" required :maxlength="40" :disabled="used" hint="Letras, números, - e _ (guarda-se em maiúsculas)." :error="form.errors.value.code" />
                <AppInput v-model="form.data.description" label="Descrição (só para a plataforma)" :maxlength="200" :error="form.errors.value.description" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <AppSelect v-model="form.data.kind" label="Tipo" :options="kinds" :disabled="used" />
                    <AppInput v-model="form.data.value" type="number" inputmode="decimal" :label="form.data.kind === 'percent' ? 'Percentagem' : form.data.kind === 'amount' ? 'Valor (€)' : 'Dias'"
                        required :disabled="used" :error="form.errors.value.percent_off || form.errors.value.amount_off_cents || form.errors.value.trial_extension_days" />
                </div>
                <fieldset v-if="form.data.kind !== 'trial'">
                    <legend class="mb-2 text-sm font-medium text-slate-700">Só para estes planos (nenhum = todos)</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <AppCheckbox v-for="p in plans" :key="p.id" v-model="planIds[p.id]" :label="p.name" />
                    </div>
                </fieldset>
                <fieldset v-if="form.data.kind !== 'trial'">
                    <legend class="mb-2 text-sm font-medium text-slate-700">Só para a faturação (nenhuma = as duas)</legend>
                    <div class="flex gap-4">
                        <AppCheckbox v-model="cycles.monthly" label="Mensal" />
                        <AppCheckbox v-model="cycles.yearly" label="Anual" />
                    </div>
                </fieldset>
                <div class="grid gap-3 sm:grid-cols-3">
                    <AppInput v-model="form.data.max_redemptions" type="number" inputmode="numeric" label="Máximo de usos" hint="Vazio = sem limite" :error="form.errors.value.max_redemptions" />
                    <AppInput v-model="form.data.valid_from" type="date" label="Desde" :error="form.errors.value.valid_from" />
                    <AppInput v-model="form.data.valid_until" type="date" label="Até" :error="form.errors.value.valid_until" />
                </div>
                <AppCheckbox v-model="form.data.is_active" label="Ativo" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="editing = null">Cancelar</AppButton>
                <AppButton type="submit" form="coupon-form" :loading="form.processing.value">Guardar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
