<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { subscriptionApi } from '@/api';
import type { UsageItem } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { daysUntil, formatDate } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const usage = ref<Record<string, UsageItem> | null>(null);

const subscription = computed(() => session.current?.subscription ?? null);
const trialDays = computed(() => (subscription.value?.status === 'trialing' ? daysUntil(subscription.value.trial_ends_at) : null));

onMounted(async () => {
    if (session.can('subscription.view')) {
        usage.value = (await subscriptionApi.get()).usage;
    }
});

function percent(item: UsageItem): number {
    if (!item.limit || item.used === null) return 0;
    return Math.min(100, Math.round((item.used / item.limit) * 100));
}
</script>

<template>
    <div>
        <PageHeader :title="`Olá, ${session.user?.name?.split(' ')[0] ?? ''}`" :description="session.current?.tenant.name ?? session.supportSession?.tenant.name ?? ''" />

        <div class="grid gap-4 md:grid-cols-3">
            <AppCard title="O seu perfil">
                <p class="text-lg font-semibold">{{ session.current?.role.name ?? 'Suporte técnico' }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ session.current?.permissions.length ?? 0 }} permissões ativas</p>
            </AppCard>

            <AppCard title="Subscrição">
                <template v-if="subscription">
                    <p class="text-lg font-semibold">{{ subscription.plan }}</p>
                    <AppBadge :color="subscription.status === 'active' ? 'green' : subscription.status === 'trialing' ? 'brand' : 'amber'" class="mt-1">
                        {{ subscription.status_label }}
                    </AppBadge>
                    <p v-if="trialDays !== null" class="mt-2 text-sm text-slate-600">
                        Termina a {{ formatDate(subscription.trial_ends_at) }} ({{ Math.max(trialDays, 0) }} dias).
                    </p>
                </template>
                <p v-else class="text-sm text-slate-500">Sem informação.</p>
            </AppCard>

            <AppCard title="Segurança da conta">
                <AppBadge :color="session.user?.two_factor_enabled ? 'green' : 'amber'">
                    {{ session.user?.two_factor_enabled ? '2FA ativa' : '2FA desativada' }}
                </AppBadge>
                <p class="mt-2 text-sm text-slate-600">
                    <RouterLink to="/app/conta" class="font-medium text-brand-700 hover:underline">Gerir segurança da conta</RouterLink>
                </p>
            </AppCard>
        </div>

        <AppCard v-if="usage" title="Utilização do plano" class="mt-4">
            <ul class="grid gap-5 sm:grid-cols-3">
                <li v-for="(item, key) in usage" :key="key">
                    <div class="flex justify-between text-sm">
                        <span class="font-medium text-slate-700">{{ item.label }}</span>
                        <span class="text-slate-500">{{ item.used ?? 0 }} / {{ item.limit ?? '∞' }}</span>
                    </div>
                    <div class="mt-2 h-2 rounded-full bg-slate-100">
                        <div class="h-2 rounded-full" :class="percent(item) >= 90 ? 'bg-red-600' : 'bg-brand-600'" :style="{ width: `${percent(item)}%` }" />
                    </div>
                </li>
            </ul>
        </AppCard>

        <AppAlert class="mt-6" title="Fase 1 — Fundação">
            Nesta versão estão disponíveis a gestão da empresa, utilizadores, perfis e permissões, auditoria, subscrição,
            acesso controlado do suporte técnico e exportação de dados. Os módulos de obras, diário, documentos e fiscalização
            chegam nas fases seguintes.
        </AppAlert>
    </div>
</template>
