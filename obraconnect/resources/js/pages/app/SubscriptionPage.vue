<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { subscriptionApi } from '@/api';
import type { Plan, Subscription, UsageItem } from '@/api/types';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const subscription = ref<Subscription | null>(null);
const usage = ref<Record<string, UsageItem>>({});
const plans = ref<Plan[]>([]);

onMounted(async () => {
    const [data, publicPlans] = await Promise.all([subscriptionApi.get(), subscriptionApi.publicPlans()]);
    subscription.value = data.subscription;
    usage.value = data.usage;
    plans.value = publicPlans;
});
</script>

<template>
    <div>
        <PageHeader title="Subscrição" description="Plano atual, limites e utilização." />

        <div class="grid gap-4 lg:grid-cols-3">
            <AppCard title="Plano atual">
                <template v-if="subscription">
                    <p class="text-2xl font-bold">{{ subscription.plan?.name }}</p>
                    <AppBadge class="mt-2" :color="subscription.status === 'active' ? 'green' : subscription.status === 'trialing' ? 'brand' : 'amber'">
                        {{ subscription.status_label }}
                    </AppBadge>
                    <dl class="mt-4 space-y-1 text-sm">
                        <div v-if="subscription.trial_ends_at" class="flex justify-between"><dt class="text-slate-500">Fim do período experimental</dt><dd>{{ formatDate(subscription.trial_ends_at) }}</dd></div>
                        <div v-if="subscription.current_period_ends_at" class="flex justify-between"><dt class="text-slate-500">Período atual até</dt><dd>{{ formatDate(subscription.current_period_ends_at) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Faturação</dt><dd>{{ subscription.billing_cycle === 'yearly' ? 'Anual' : 'Mensal' }}</dd></div>
                    </dl>
                </template>
            </AppCard>

            <AppCard title="Utilização" class="lg:col-span-2">
                <ul class="space-y-4">
                    <li v-for="(item, key) in usage" :key="key" class="flex items-center justify-between text-sm">
                        <span class="font-medium">{{ item.label }}</span>
                        <span>{{ item.used ?? 0 }} de {{ item.limit ?? 'ilimitado' }}</span>
                    </li>
                </ul>
            </AppCard>
        </div>

        <h2 class="mt-8 mb-3 text-lg font-semibold">Planos disponíveis</h2>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <AppCard v-for="plan in plans" :key="plan.id" :title="plan.name">
                <p class="text-2xl font-bold">{{ formatMoney(plan.price_monthly_cents, plan.currency) }}<span v-if="plan.price_monthly_cents !== null" class="text-sm font-normal text-slate-500">/mês</span></p>
                <p class="text-xs text-slate-500">Valores sem IVA</p>
                <p class="mt-3 text-sm text-slate-600">{{ plan.description }}</p>
                <AppBadge v-if="subscription?.plan?.code === plan.code" color="brand" class="mt-3">Plano atual</AppBadge>
            </AppCard>
        </div>
        <AppAlert class="mt-6">
            A mudança de plano e o pagamento online ainda não estão disponíveis na aplicação. Para alterar o plano, contacte a equipa ObraConnect.
        </AppAlert>
    </div>
</template>
