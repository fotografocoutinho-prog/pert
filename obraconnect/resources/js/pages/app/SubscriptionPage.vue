<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { subscriptionApi } from '@/api';
import type { SubscriptionChoice } from '@/api';
import { ApiError } from '@/api/http';
import type { Plan, SubscriptionOverview, SubscriptionQuote } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatMoney } from '@/utils/format';
import PaymentInfoBlock from '@/components/billing/PaymentInfoBlock.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const toast = useToastStore();
const session = useSessionStore();
const data = ref<SubscriptionOverview | null>(null);
const plans = ref<Plan[]>([]);
const cycle = ref<'monthly' | 'yearly'>('monthly');

async function load(): Promise<void> {
    const [overview, publicPlans] = await Promise.all([subscriptionApi.get(), subscriptionApi.publicPlans()]);
    data.value = overview;
    plans.value = publicPlans;
    if (overview.subscription) cycle.value = overview.subscription.billing_cycle;
}

onMounted(load);

const sub = computed(() => data.value?.subscription ?? null);
const now = () => Date.now();
const running = computed(() => {
    const s = sub.value;
    if (!s) return false;
    if (s.status === 'trialing') return true;
    return s.status === 'active' && !!s.current_period_ends_at && new Date(s.current_period_ends_at).getTime() > now();
});
const renewalOpen = computed(() => !!data.value?.renewal_opens_at && new Date(data.value.renewal_opens_at).getTime() <= now());
const canOrder = computed(() => {
    const s = sub.value;
    if (!data.value?.can_manage) return false;
    if (!s || s.status === 'trialing') return true;
    if (s.status === 'active') return renewalOpen.value;
    return true;
});
const pending = computed(() => data.value?.orders.find((o) => o.status === 'pending' && o.kind !== 'upgrade') ?? null);
const showTrialCoupon = computed(() => data.value?.can_manage && (sub.value?.status === 'trialing' || (sub.value?.status === 'expired' && !sub.value.current_period_ends_at)));
const orderLabel = computed(() => (sub.value?.current_period_ends_at ? 'Renovar com este plano' : 'Pedir a ativação'));

const price = (p: Plan) => (cycle.value === 'yearly' ? p.price_yearly_cents : p.price_monthly_cents);
const isCurrent = (p: Plan) => sub.value?.plan?.code === p.code && sub.value?.billing_cycle === cycle.value;
const cycleLabel = (c: string | null | undefined) => (c === 'yearly' ? 'anual' : 'mensal');
const limitText = (v: number | null | undefined, unit = '') => (v === null || v === undefined ? 'ilimitado' : `${v}${unit}`);

function fail(e: unknown): void {
    toast.error(e instanceof ApiError ? e.message : 'Falhou.');
}

// --- pré-visualização e confirmação ---
const modal = ref<{ intent: 'change' | 'order'; plan: Plan } | null>(null);
const quote = ref<SubscriptionQuote | null>(null);
const quoteError = ref<string | null>(null);
const coupon = ref('');
const couponError = ref<string | null>(null);
const busy = ref(false);

function choice(): SubscriptionChoice {
    return { plan_id: modal.value!.plan.id, billing_cycle: cycle.value, coupon_code: modal.value!.intent === 'order' && coupon.value.trim() ? coupon.value.trim() : null };
}

async function refreshQuote(): Promise<void> {
    if (!modal.value) return;
    quote.value = null;
    quoteError.value = null;
    couponError.value = null;
    try {
        quote.value = await subscriptionApi.quote(modal.value.intent, choice());
    } catch (e) {
        const msg = e instanceof ApiError ? e.message : 'Falhou.';
        if (e instanceof ApiError && e.code?.startsWith('COUPON_')) {
            couponError.value = msg;
            coupon.value = '';
            quote.value = await subscriptionApi.quote(modal.value.intent, choice()).catch(() => null);
        } else {
            quoteError.value = msg;
        }
    }
}

async function open(intent: 'change' | 'order', plan: Plan): Promise<void> {
    modal.value = { intent, plan };
    coupon.value = '';
    await refreshQuote();
}

async function confirm(): Promise<void> {
    if (!modal.value || !quote.value) return;
    busy.value = true;
    try {
        if (modal.value.intent === 'change') {
            const r = await subscriptionApi.change(choice());
            toast.success(r.action === 'schedule' ? 'Mudança marcada para o fim do período.' : 'Plano alterado.');
        } else {
            const o = await subscriptionApi.order(choice());
            toast.success(`Pedido n.º ${o.id} enviado. Vai receber a fatura por email.`);
        }
        modal.value = null;
        await Promise.all([load(), session.refresh?.()]);
    } catch (e) {
        if (e instanceof ApiError && e.code?.startsWith('COUPON_')) couponError.value = e.message;
        else quoteError.value = e instanceof ApiError ? e.message : 'Falhou.';
    } finally {
        busy.value = false;
    }
}

async function cancelScheduled(): Promise<void> {
    try {
        await subscriptionApi.cancelScheduled();
        toast.success('Mudança desmarcada.');
        await load();
    } catch (e) {
        fail(e);
    }
}

async function cancelOrder(id: number): Promise<void> {
    if (!window.confirm(`Cancelar o pedido n.º ${id}?`)) return;
    try {
        await subscriptionApi.cancelOrder(id);
        toast.success('Pedido cancelado.');
        await load();
    } catch (e) {
        fail(e);
    }
}

// --- cupão de período experimental ---
const trialCode = ref('');
const trialError = ref<string | null>(null);
async function redeem(): Promise<void> {
    trialError.value = null;
    try {
        const s = await subscriptionApi.redeemCoupon(trialCode.value.trim());
        toast.success(`Período experimental prolongado até ${formatDate(s.trial_ends_at)}.`);
        trialCode.value = '';
        await Promise.all([load(), session.refresh?.()]);
    } catch (e) {
        trialError.value = e instanceof ApiError ? e.message : 'Falhou.';
    }
}

const statusColor = (s: string) => (s === 'confirmed' ? 'green' : s === 'pending' ? 'amber' : 'gray');
</script>

<template>
    <div>
        <PageHeader title="Subscrição" description="Plano atual, limites e utilização. Mude de plano ou peça a ativação e a renovação aqui." />

        <div v-if="!data" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <template v-else>
            <AppAlert v-if="data.custom_terms" class="mb-4">A subscrição da empresa tem condições próprias combinadas com a equipa ObraConnect: para a mudar, fale connosco.</AppAlert>
            <AppAlert v-else-if="data.tenant_status === 'read_only'" type="warning" class="mb-4">
                A empresa está em modo só leitura porque a subscrição terminou. Escolha um plano abaixo e peça a ativação: tudo volta a funcionar quando o pagamento for confirmado.
            </AppAlert>

            <div class="grid gap-4 lg:grid-cols-3">
                <AppCard title="Plano atual" class="min-w-0">
                    <template v-if="sub">
                        <p class="text-2xl font-bold">{{ sub.plan?.name }}</p>
                        <AppBadge class="mt-2" :color="sub.status === 'active' ? 'green' : sub.status === 'trialing' ? 'brand' : 'amber'">{{ sub.status_label }}</AppBadge>
                        <dl class="mt-4 space-y-1 text-sm">
                            <div v-if="sub.trial_ends_at && sub.status === 'trialing'" class="flex justify-between gap-2"><dt class="text-slate-500">Fim do período experimental</dt><dd>{{ formatDate(sub.trial_ends_at) }}</dd></div>
                            <div v-if="sub.current_period_ends_at" class="flex justify-between gap-2"><dt class="text-slate-500">Período pago</dt><dd>{{ formatDate(sub.current_period_starts_at) }} – {{ formatDate(sub.current_period_ends_at) }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-slate-500">Faturação</dt><dd>{{ sub.billing_cycle === 'yearly' ? 'Anual' : 'Mensal' }}</dd></div>
                            <div v-if="data.renewal_opens_at && !renewalOpen" class="flex justify-between gap-2"><dt class="text-slate-500">Renovação a partir de</dt><dd>{{ formatDate(data.renewal_opens_at) }}</dd></div>
                        </dl>
                        <div v-if="sub.scheduled_change" class="mt-4 rounded-lg bg-slate-50 p-3 text-sm">
                            Muda para <strong>{{ sub.scheduled_change.plan?.name }}</strong> ({{ cycleLabel(sub.scheduled_change.billing_cycle) }}) a {{ formatDate(sub.scheduled_change.at) }}.
                            <AppButton v-if="data.can_manage" size="sm" variant="ghost" class="mt-1" @click="cancelScheduled">Desmarcar</AppButton>
                        </div>
                    </template>
                    <p v-else class="text-sm text-slate-500">Sem subscrição.</p>
                </AppCard>

                <AppCard title="Utilização" class="min-w-0 lg:col-span-2">
                    <ul class="space-y-4">
                        <li v-for="(item, key) in data.usage" :key="key" class="flex items-center justify-between gap-2 text-sm">
                            <span class="font-medium">{{ item.label }}</span>
                            <span>{{ item.used ?? 0 }} de {{ item.limit ?? 'ilimitado' }}</span>
                        </li>
                    </ul>
                </AppCard>
            </div>

            <AppCard v-if="pending" title="Pedido por confirmar" class="mt-4">
                <p class="text-sm">
                    Pedido n.º {{ pending.id }} · {{ pending.kind_label }} do plano <strong>{{ pending.plan?.name }}</strong> ({{ cycleLabel(pending.billing_cycle) }}):
                    <strong>{{ formatMoney(pending.total_cents, pending.currency) }}</strong> sem IVA<template v-if="pending.coupon"> (cupão {{ pending.coupon }})</template>.
                </p>
                <PaymentInfoBlock class="mt-3" :info="data.payment" :reference="pending.payment_reference" />
                <AppButton v-if="data.can_manage" size="sm" variant="secondary" class="mt-3" @click="cancelOrder(pending.id)">Cancelar o pedido</AppButton>
            </AppCard>

            <AppCard v-if="showTrialCoupon" title="Tem um código?" class="mt-4">
                <form class="flex flex-wrap items-start gap-2" novalidate @submit.prevent="redeem">
                    <div class="min-w-0 flex-1"><AppInput v-model="trialCode" aria-label="Código do cupão" placeholder="Código" :maxlength="40" :error="trialError ?? undefined" /></div>
                    <AppButton type="submit" variant="secondary" :disabled="!trialCode.trim()">Usar</AppButton>
                </form>
                <p class="mt-2 text-xs text-slate-500">Os códigos de dias extra de período experimental usam-se aqui; os de desconto, ao pedir a ativação.</p>
            </AppCard>

            <div class="mt-8 mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Planos</h2>
                <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm" role="group" aria-label="Faturação">
                    <button type="button" :class="['rounded-md px-3 py-1', cycle === 'monthly' ? 'bg-brand-700 text-white' : 'text-slate-600']" @click="cycle = 'monthly'">Mensal</button>
                    <button type="button" :class="['rounded-md px-3 py-1', cycle === 'yearly' ? 'bg-brand-700 text-white' : 'text-slate-600']" @click="cycle = 'yearly'">Anual</button>
                </div>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <AppCard v-for="plan in plans" :key="plan.id" :title="plan.name" class="min-w-0">
                    <p class="text-2xl font-bold">
                        <template v-if="price(plan) !== null">{{ formatMoney(price(plan), plan.currency) }}<span class="text-sm font-normal text-slate-500">/{{ cycle === 'yearly' ? 'ano' : 'mês' }}</span></template>
                        <template v-else>Sob consulta</template>
                    </p>
                    <p class="text-xs text-slate-500">Valores sem IVA</p>
                    <p class="mt-3 text-sm text-slate-600">{{ plan.description }}</p>
                    <ul class="mt-3 space-y-1 text-xs text-slate-500">
                        <li>Utilizadores: {{ limitText(plan.limits.max_users) }}</li>
                        <li>Obras ativas: {{ limitText(plan.limits.max_active_projects) }}</li>
                        <li>Armazenamento: {{ plan.limits.storage_mb == null ? 'ilimitado' : `${Math.round(plan.limits.storage_mb / 1024)} GB` }}</li>
                    </ul>
                    <AppBadge v-if="isCurrent(plan)" color="brand" class="mt-3">Plano atual</AppBadge>
                    <div v-if="data.can_manage" class="mt-3 flex flex-col gap-2">
                        <template v-if="price(plan) === null">
                            <p class="text-sm text-slate-600">Fale com a equipa ObraConnect.</p>
                        </template>
                        <template v-else>
                            <AppButton v-if="running && !isCurrent(plan)" size="sm" variant="secondary" @click="open('change', plan)">Mudar para este plano</AppButton>
                            <AppButton v-if="canOrder && !pending" size="sm" @click="open('order', plan)">{{ orderLabel }}</AppButton>
                        </template>
                    </div>
                </AppCard>
            </div>

            <AppCard v-if="data.orders.length" title="Pedidos" class="mt-6">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead class="text-left text-xs text-slate-500">
                            <tr><th class="py-2 pr-3">N.º</th><th class="pr-3">Pedido</th><th class="pr-3">Plano</th><th class="pr-3 text-right">Total s/ IVA</th><th class="pr-3">Período</th><th>Estado</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="o in data.orders" :key="o.id">
                                <td class="py-2 pr-3">{{ o.id }}</td>
                                <td class="pr-3">{{ o.kind_label }}<span v-if="o.coupon" class="text-xs text-slate-500"> · {{ o.coupon }}</span></td>
                                <td class="pr-3">{{ o.plan?.name }} ({{ cycleLabel(o.billing_cycle) }})</td>
                                <td class="pr-3 text-right">{{ formatMoney(o.total_cents, o.currency) }}</td>
                                <td class="pr-3">{{ formatDate(o.period_starts_at) }} – {{ formatDate(o.period_ends_at) }}</td>
                                <td><AppBadge :color="statusColor(o.status)">{{ o.status_label }}</AppBadge></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </template>

        <AppModal :open="!!modal" :title="modal ? (modal.intent === 'change' ? `Mudar para ${modal.plan.name}` : `${orderLabel === 'Pedir a ativação' ? 'Ativar' : 'Renovar'}: ${modal.plan.name}`) : ''" @close="modal = null">
            <div v-if="modal" class="space-y-4">
                <p class="text-sm text-slate-600">Faturação {{ cycleLabel(cycle) }}.</p>
                <AppAlert v-if="quoteError" type="error">{{ quoteError }}</AppAlert>
                <div v-else-if="!quote" class="flex justify-center py-6"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
                <template v-else>
                    <p class="text-sm">{{ quote.message }}</p>
                    <dl v-if="quote.intent === 'order'" class="space-y-1 rounded-lg bg-slate-50 p-3 text-sm">
                        <div class="flex justify-between"><dt>Preço</dt><dd>{{ formatMoney(quote.list_price_cents ?? 0, quote.plan.currency) }}</dd></div>
                        <div v-if="quote.discount_cents" class="flex justify-between text-green-700"><dt>Cupão {{ quote.coupon?.code }}</dt><dd>−{{ formatMoney(quote.discount_cents, quote.plan.currency) }}</dd></div>
                        <div class="flex justify-between font-semibold"><dt>Total sem IVA</dt><dd>{{ formatMoney(quote.total_cents, quote.plan.currency) }}</dd></div>
                    </dl>
                    <AppAlert v-if="!quote.allowed" type="warning">
                        O plano {{ quote.plan.name }} não chega para o que a empresa já usa:
                        <span v-for="(o, i) in quote.over" :key="o.key">{{ o.label.toLowerCase() }} {{ o.used }} (máximo {{ o.limit }})<template v-if="i < quote.over.length - 1">; </template></span>.
                        Reduza primeiro (ex.: arquive ou conclua obras, retire utilizadores).
                    </AppAlert>
                    <p v-if="quote.intent === 'order'" class="text-xs text-slate-500">
                        {{ quote.payment_instructions }}<template v-if="data?.payment.transfer"> Depois de enviar o pedido, os dados para a transferência ficam nesta página e no email.</template>
                    </p>
                </template>
                <form v-if="modal.intent === 'order' && !quoteError" class="flex flex-wrap items-start gap-2" novalidate @submit.prevent="refreshQuote">
                    <div class="min-w-0 flex-1"><AppInput v-model="coupon" aria-label="Cupão de desconto" placeholder="Cupão de desconto (opcional)" :maxlength="40" :error="couponError ?? undefined" /></div>
                    <AppButton type="submit" variant="secondary" :disabled="!coupon.trim()">Aplicar</AppButton>
                </form>
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="modal = null">Fechar</AppButton>
                <AppButton :disabled="!quote || !quote.allowed || !!quoteError" :loading="busy" @click="confirm">
                    {{ modal?.intent === 'order' ? 'Enviar o pedido' : quote?.action === 'schedule' ? 'Marcar a mudança' : 'Mudar agora' }}
                </AppButton>
            </template>
        </AppModal>
    </div>
</template>
