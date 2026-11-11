<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { platformApi } from '@/api';
import { ApiError } from '@/api/http';
import type { PlatformSubscriptionOrder } from '@/api/types';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppPagination from '@/components/ui/AppPagination.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const toast = useToastStore();
const orders = ref<PlatformSubscriptionOrder[] | null>(null);
const meta = ref({ page: 1, last_page: 1, total: 0, pending: 0 });
const status = ref('pending');
const page = ref(1);
const statusOptions = [
    { value: 'pending', label: 'Por confirmar' },
    { value: 'confirmed', label: 'Confirmados' },
    { value: 'canceled', label: 'Cancelados' },
    { value: 'expired', label: 'Caducados' },
    { value: 'all', label: 'Todos' },
];

async function load(): Promise<void> {
    orders.value = null;
    const r = await platformApi.orders({ status: status.value, page: page.value });
    orders.value = r.data;
    meta.value = r.meta;
}
onMounted(load);
watch(status, () => {
    page.value = 1;
    load();
});
watch(page, load);

const cycleLabel = (c: string) => (c === 'yearly' ? 'anual' : 'mensal');
const statusColor = (s: string) => (s === 'confirmed' ? 'green' : s === 'pending' ? 'amber' : 'gray');

// Confirmar / cancelar
const acting = ref<{ order: PlatformSubscriptionOrder; mode: 'confirm' | 'cancel' } | null>(null);
const invoice = ref('');
const notes = ref('');
const reason = ref('');
const error = ref<string | null>(null);
const busy = ref(false);

function start(order: PlatformSubscriptionOrder, mode: 'confirm' | 'cancel'): void {
    acting.value = { order, mode };
    invoice.value = '';
    notes.value = '';
    reason.value = '';
    error.value = null;
}

async function submit(): Promise<void> {
    if (!acting.value) return;
    busy.value = true;
    error.value = null;
    try {
        if (acting.value.mode === 'confirm') {
            await platformApi.confirmOrder(acting.value.order.id, { invoice_reference: invoice.value.trim() || null, notes: notes.value.trim() || null });
            toast.success(`Pedido n.º ${acting.value.order.id} confirmado.`);
        } else {
            await platformApi.cancelOrder(acting.value.order.id, reason.value.trim());
            toast.success(`Pedido n.º ${acting.value.order.id} cancelado.`);
        }
        acting.value = null;
        await load();
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Falhou.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader
            title="Pedidos de subscrição"
            description="Pedidos das empresas. Emita a fatura no programa certificado e, depois de receber o pagamento, confirme: a ativação ou a renovação só conta a partir daí. As subidas de plano já estão aplicadas; confirmar marca-as como faturadas."
        />

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="w-48"><AppSelect v-model="status" label="Estado" :options="statusOptions" /></div>
            <p class="pb-2 text-sm text-slate-500">{{ meta.pending }} por confirmar</p>
        </div>

        <AppCard>
            <div v-if="!orders" class="flex justify-center py-10"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
            <EmptyState v-else-if="orders.length === 0" title="Sem pedidos" description="Não há pedidos neste estado." />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="o in orders" :key="o.id" class="flex flex-wrap items-start justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="font-medium">
                            N.º {{ o.id }} · {{ o.kind_label }} · {{ o.tenant?.name }}<span v-if="o.tenant?.nif" class="text-sm font-normal text-slate-500"> (NIF {{ o.tenant.nif }})</span>
                        </p>
                        <p class="text-sm text-slate-600">
                            <template v-if="o.from_plan && o.kind === 'upgrade'">{{ o.from_plan }} → </template>{{ o.plan?.name }} ({{ cycleLabel(o.billing_cycle) }}) ·
                            <strong>{{ formatMoney(o.total_cents, o.currency) }}</strong> sem IVA
                            <template v-if="o.discount_cents"> (preço {{ formatMoney(o.list_price_cents, o.currency) }}, cupão {{ o.coupon }} −{{ formatMoney(o.discount_cents, o.currency) }})</template>
                        </p>
                        <p class="text-xs text-slate-500">
                            Pedido a {{ formatDateTime(o.created_at) }}<template v-if="o.requested_by"> por {{ o.requested_by.name }} ({{ o.requested_by.email }})</template>
                            · período {{ formatDate(o.period_starts_at) }} – {{ formatDate(o.period_ends_at) }}
                            · descritivo da transferência «{{ o.payment_reference }}»
                            <template v-if="o.confirmed_at"> · confirmado a {{ formatDateTime(o.confirmed_at) }} por {{ o.confirmed_by }}<template v-if="o.invoice_reference"> (fatura {{ o.invoice_reference }})</template></template>
                            <template v-if="o.cancel_reason"> · {{ o.cancel_reason }}</template>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <AppBadge :color="statusColor(o.status)">{{ o.status_label }}</AppBadge>
                        <template v-if="o.status === 'pending'">
                            <AppButton size="sm" @click="start(o, 'confirm')">{{ o.kind === 'upgrade' ? 'Faturado' : 'Confirmar pagamento' }}</AppButton>
                            <AppButton size="sm" variant="ghost" @click="start(o, 'cancel')">Cancelar</AppButton>
                        </template>
                    </div>
                </li>
            </ul>
            <AppPagination :page="meta.page" :last-page="meta.last_page" :total="meta.total" @change="page = $event" />
        </AppCard>

        <AppModal :open="!!acting" :title="acting ? (acting.mode === 'confirm' ? `Confirmar o pedido n.º ${acting.order.id}` : `Cancelar o pedido n.º ${acting.order.id}`) : ''" @close="acting = null">
            <form v-if="acting" id="order-form" class="space-y-4" novalidate @submit.prevent="submit">
                <AppAlert v-if="error" type="error">{{ error }}</AppAlert>
                <template v-if="acting.mode === 'confirm'">
                    <p class="text-sm">
                        {{ acting.order.tenant?.name }} · {{ acting.order.plan?.name }} ({{ cycleLabel(acting.order.billing_cycle) }}) ·
                        {{ formatMoney(acting.order.total_cents, acting.order.currency) }} sem IVA.
                        <template v-if="acting.order.kind !== 'upgrade'"> O período começa (ou é prolongado) já e a empresa é avisada por email.</template>
                    </p>
                    <AppInput v-model="invoice" label="N.º da fatura (programa certificado)" :maxlength="60" />
                    <AppTextarea v-model="notes" label="Notas (só para a plataforma)" :maxlength="2000" />
                </template>
                <AppTextarea v-else v-model="reason" label="Motivo (vai no email à empresa)" required :maxlength="300" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="acting = null">Fechar</AppButton>
                <AppButton type="submit" form="order-form" :variant="acting?.mode === 'cancel' ? 'danger' : 'primary'" :loading="busy" :disabled="acting?.mode === 'cancel' && reason.trim().length < 3">
                    {{ acting?.mode === 'cancel' ? 'Cancelar o pedido' : 'Confirmar' }}
                </AppButton>
            </template>
        </AppModal>
    </div>
</template>
