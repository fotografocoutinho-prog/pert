<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { platformApi, type SystemCheck, type SystemStatus } from '@/api';
import { ApiError } from '@/api/http';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDateTime } from '@/utils/format';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();
const data = ref<SystemStatus | null>(null);
const loading = ref(false);
const busy = ref<string | null>(null);
const canManage = computed(() => session.hasPlatformRole('super_admin'));

async function load(): Promise<void> {
    loading.value = true;
    try {
        data.value = await platformApi.system();
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const statusStyle: Record<SystemCheck['status'], { color: 'green' | 'amber' | 'red'; label: string; dot: string }> = {
    ok: { color: 'green', label: 'OK', dot: 'bg-green-600' },
    warning: { color: 'amber', label: 'Atenção', dot: 'bg-amber-500' },
    failing: { color: 'red', label: 'A falhar', dot: 'bg-red-600' },
};
const overall = computed(() => {
    const checks = data.value?.checks ?? [];
    if (checks.some((c) => c.status === 'failing')) return { color: 'red' as const, text: `${checks.filter((c) => c.status === 'failing').length} a falhar` };
    if (checks.some((c) => c.status === 'warning')) return { color: 'amber' as const, text: `${checks.filter((c) => c.status === 'warning').length} com atenção` };
    return { color: 'green' as const, text: 'Tudo a funcionar' };
});
const groups = computed(() => {
    const out = new Map<string, SystemCheck[]>();
    for (const c of data.value?.checks ?? []) out.set(c.group, [...(out.get(c.group) ?? []), c]);
    return [...out.entries()];
});

async function retry(uuid: string): Promise<void> {
    busy.value = uuid;
    try {
        await platformApi.retryFailedJob(uuid);
        toast.success('Trabalho posto outra vez na fila.');
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível.');
    } finally {
        busy.value = null;
    }
}
async function forget(uuid: string): Promise<void> {
    if (!confirm('Apagar este trabalho falhado? Não volta a correr.')) return;
    busy.value = uuid;
    try {
        await platformApi.forgetFailedJob(uuid);
        toast.success('Trabalho falhado apagado.');
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível.');
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader title="Saúde do sistema" description="Cron, tarefas agendadas, fila, DocIntel e configuração. Verificado a cada 10 minutos; esta página verifica agora.">
            <AppButton size="sm" variant="secondary" :loading="loading" @click="load">Verificar outra vez</AppButton>
        </PageHeader>

        <div v-if="!data" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
        <div v-else class="space-y-4" data-test="system">
            <AppCard>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <AppBadge :color="overall.color">{{ overall.text }}</AppBadge>
                        <span class="text-sm text-slate-500">verificado a {{ formatDateTime(data.checked_at) }}</span>
                    </div>
                    <p class="text-xs text-slate-500">
                        ObraConnect {{ data.info.version }} · PHP {{ data.info.php }} · Laravel {{ data.info.laravel }} · {{ data.info.environment }} · emails por {{ data.info.mailer }} · fila
                        {{ data.info.queue }}
                    </p>
                </div>
                <p v-if="data.info.alert_recipients === 0" class="mt-2 text-sm text-amber-800">
                    Ninguém recebe os avisos por email: falta um super administrador da plataforma ou MONITORING_EMAILS no .env.
                </p>
            </AppCard>

            <AppCard v-for="[group, checks] in groups" :key="group" :title="group">
                <ul class="divide-y divide-slate-100">
                    <li v-for="c in checks" :key="c.key" class="flex gap-3 py-2.5 first:pt-0 last:pb-0">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="statusStyle[c.status].dot" :title="statusStyle[c.status].label" />
                        <div class="min-w-0 text-sm">
                            <p class="font-medium text-slate-900">{{ c.label }}</p>
                            <p class="text-slate-600">{{ c.detail }}</p>
                            <p v-if="c.status !== 'ok' && c.since" class="text-xs text-slate-500">assim desde {{ formatDateTime(c.since) }}</p>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AppCard title="Trabalhos falhados" description="Depois de 3 tentativas. Emails e leituras de faturas que não saíram.">
                <p v-if="!data.failed_jobs.length" class="text-sm text-slate-600">Nenhum.</p>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="j in data.failed_jobs" :key="j.uuid" class="flex flex-wrap items-start justify-between gap-2 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium">{{ j.job }} <span class="text-xs font-normal text-slate-500">· {{ formatDateTime(j.failed_at) }}</span></p>
                            <p class="break-words text-slate-600">{{ j.error }}</p>
                        </div>
                        <div v-if="canManage" class="flex gap-2">
                            <AppButton size="sm" variant="secondary" :loading="busy === j.uuid" @click="retry(j.uuid)">Repetir</AppButton>
                            <AppButton size="sm" variant="ghost" :disabled="busy === j.uuid" @click="forget(j.uuid)">Apagar</AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <details class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700 shadow-sm">
                <summary class="cursor-pointer font-semibold text-slate-900">Como funciona</summary>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Todas as tarefas agendadas ficam registadas quando correm. O sinal do cron corre a cada minuto: se parar, nada do resto corre.</li>
                    <li>A cada 10 minutos as verificações correm sozinhas; quando uma passa a <strong>falhar</strong>, os super administradores recebem um email (e outro quando volta ao normal; enquanto durar, um por dia). Os avisos amarelos não geram email.</li>
                    <li>
                        Se o cron parar, também não saem estes emails. Para isso há o endereço público <code>/api/v1/health</code>, que responde 503 quando o cron ou a fila param: ponha-o num
                        serviço de monitorização externo (por exemplo, o UptimeRobot gratuito, a cada 5 minutos).
                    </li>
                </ul>
            </details>
        </div>
    </div>
</template>
