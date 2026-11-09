<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@/api/http';
import { portalApi, type PortalContractSummary } from '@/api/portal';
import { useSessionStore } from '@/stores/session';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';

const session = useSessionStore();
const route = useRoute();
const router = useRouter();
const selected = ref<number | null>(Number(route.query.acesso) || session.portal[0]?.id || null);
const data = ref<{ company: string; partner: { name: string }; contracts: PortalContractSummary[] } | null>(null);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    if (!selected.value) return;
    data.value = null;
    error.value = null;
    try {
        data.value = await portalApi.contracts(selected.value);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível abrir os contratos.';
    }
}
onMounted(load);
watch(selected, (v) => {
    router.replace({ query: v ? { acesso: String(v) } : {} });
    load();
});
</script>

<template>
    <div class="space-y-4">
        <div v-if="session.portal.length > 1" class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-slate-500">Empresa:</span>
            <button
                v-for="a in session.portal"
                :key="a.id"
                type="button"
                class="rounded-full border px-3 py-1"
                :class="a.id === selected ? 'border-brand-700 bg-brand-50 font-semibold text-brand-800' : 'border-slate-300 bg-white text-slate-700'"
                @click="selected = a.id"
            >
                {{ a.company }} <span class="text-slate-500">({{ a.partner }})</span>
            </button>
        </div>

        <AppAlert v-if="error" type="error">{{ error }}</AppAlert>
        <div v-else-if="!data" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
        <template v-else>
            <div>
                <h1 class="text-xl font-bold text-slate-900">Contratos com a {{ data.company }}</h1>
                <p class="text-sm text-slate-600">Os contratos de subempreitada em curso e fechados. Em cada um vê o que já foi autado e propõe o auto do período.</p>
            </div>
            <p v-if="!data.contracts.length" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">Ainda não há contratos em curso com esta empresa.</p>
            <ul v-else class="grid gap-3 md:grid-cols-2" data-test="portal-contracts">
                <li v-for="c in data.contracts" :key="c.id">
                    <RouterLink :to="`/portal/${selected}/contratos/${c.id}`" class="block rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900">{{ c.code }} · {{ c.title }}</p>
                                <p class="text-sm text-slate-500">Obra {{ c.project.code }} · {{ c.project.name }}</p>
                            </div>
                            <AppBadge :color="c.status === 'active' ? 'green' : 'gray'">{{ c.status_label }}</AppBadge>
                        </div>
                        <div class="mt-3 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-brand-600" :style="{ width: `${Math.min(100, c.certified_pct ?? 0)}%` }" /></div>
                        <p class="mt-2 text-sm text-slate-700">
                            Autado e aprovado <strong>{{ formatMoney(c.certified_cents) }}</strong> de {{ formatMoney(c.value_cents) }}
                            <span v-if="c.certified_pct !== null" class="text-slate-500">({{ c.certified_pct.toLocaleString('pt-PT') }} %)</span>
                        </p>
                        <p v-if="c.pending_certificate" class="mt-1 text-sm text-amber-700">Auto n.º {{ c.pending_certificate.number }}: {{ c.pending_certificate.status_label.toLowerCase() }}, à espera da empresa</p>
                        <p v-else-if="c.ends_on" class="mt-1 text-xs text-slate-500">Prazo até {{ formatDate(c.ends_on) }}</p>
                    </RouterLink>
                </li>
            </ul>
        </template>
    </div>
</template>
