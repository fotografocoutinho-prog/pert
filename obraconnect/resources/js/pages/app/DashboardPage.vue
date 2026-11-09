<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { subscriptionApi } from '@/api';
import { alertsApi, type ProjectAlert } from '@/api/economics';
import type { UsageItem } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { daysUntil, formatDate } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const usage = ref<Record<string, UsageItem> | null>(null);
const alerts = ref<ProjectAlert[] | null>(null);

const subscription = computed(() => session.current?.subscription ?? null);
const trialDays = computed(() => (subscription.value?.status === 'trialing' ? daysUntil(subscription.value.trial_ends_at) : null));

onMounted(async () => {
    alertsApi.list().then((a) => (alerts.value = a)).catch(() => (alerts.value = []));
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

        <AppCard title="Alertas das obras" class="mt-4" data-test="alerts">
            <p v-if="alerts === null" class="text-sm text-slate-500">A carregar…</p>
            <p v-else-if="!alerts.length" class="text-sm text-slate-600">Sem alertas abertos nas obras que acompanha.</p>
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="a in alerts" :key="a.id" class="flex gap-3 py-3 first:pt-0 last:pb-0">
                    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="a.level === 'danger' ? 'bg-red-600' : 'bg-amber-500'" :title="a.level === 'danger' ? 'Grave' : 'Aviso'" />
                    <div class="min-w-0 text-sm">
                        <RouterLink :to="a.path" class="font-semibold text-slate-900 hover:underline">{{ a.title }}</RouterLink>
                        <span v-if="a.project" class="text-slate-500"> · {{ a.project.code }} {{ a.project.name }}</span>
                        <p class="text-slate-600">{{ a.detail }}</p>
                    </div>
                </li>
            </ul>
            <p v-if="session.can('company.update')" class="mt-3 text-xs text-slate-500">
                Os alertas abrem e fecham-se sozinhos todos os dias às 7h. Regras em <RouterLink to="/app/empresa" class="font-medium text-brand-700 hover:underline">Empresa</RouterLink>.
            </p>
        </AppCard>

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

        <AppAlert class="mt-6" title="Novo: previsões e alertas automáticos">
            Em cada <RouterLink to="/app/obras" class="font-semibold underline">obra</RouterLink>, o separador <strong>Previsões</strong> diz quanto vai custar e
            quando acaba, com as razões e a evolução semana a semana. Quando uma obra se aproxima do teto, passa o prazo ou piora de uma semana para a outra, aparece
            um alerta aqui e o diretor de obra recebe um email.
            <template v-if="session.can('finance.view')">
                O resumo de todas as obras está no <RouterLink to="/app/financas" class="font-semibold underline">painel financeiro</RouterLink>.
            </template>
        </AppAlert>
    </div>
</template>
