<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { Project } from '@/api/types';
import { budgetsApi, formatPct, type BudgetSummary } from '@/api/economics';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { ApiError } from '@/api/http';
import { formatDate, formatMoney } from '@/utils/format';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const props = defineProps<{ project: Project }>();
const session = useSessionStore();
const toast = useToastStore();
const router = useRouter();
const canManage = computed(() => props.project.permissions.includes('budgets.manage') && props.project.status !== 'archived' && !session.isReadOnly);

const budgets = ref<BudgetSummary[]>([]);
const loading = ref(true);
onMounted(async () => {
    budgets.value = await budgetsApi.list(props.project.id);
    loading.value = false;
});
const statusColor = (s: string) => (s === 'approved' ? 'green' : s === 'draft' ? 'amber' : 'gray');
const open = (b: BudgetSummary) => router.push(`/app/obras/${props.project.id}/orcamentos/${b.id}`);

const creating = ref(false);
async function create(): Promise<void> {
    creating.value = true;
    try {
        const b = await budgetsApi.create(props.project.id, { name: 'Orçamento', overhead_pct: 10, profit_pct: 15 });
        router.push(`/app/obras/${props.project.id}/orcamentos/${b.id}`);
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível criar.');
    } finally {
        creating.value = false;
    }
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600">O orçamento aprovado é a base do contrato, dos autos de medição e do custo previsto. Para o mudar, faça uma revisão.</p>
            <AppButton v-if="canManage" :loading="creating" @click="create">Novo orçamento</AppButton>
        </div>
        <AppCard>
            <div v-if="loading" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
            <EmptyState v-else-if="!budgets.length" title="Sem orçamentos" :description="canManage ? 'Crie um e junte capítulos e artigos (da biblioteca de preços ou importados de Excel).' : undefined" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="b in budgets" :key="b.id">
                    <button type="button" class="flex w-full flex-wrap items-start justify-between gap-2 py-3 text-left hover:bg-slate-50" @click="open(b)">
                        <div>
                            <p class="font-medium text-slate-900"><span class="font-mono text-sm">{{ b.code }}</span> v{{ b.version }} · {{ b.name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ b.items_count }} artigos · custo {{ formatMoney(b.cost_cents) }} · venda {{ formatMoney(b.sale_cents) }}<template v-if="b.margin_pct !== null"> · margem {{ formatPct(b.margin_pct) }}</template>
                                <template v-if="b.approved_at"> · aprovado a {{ formatDate(b.approved_at) }}</template>
                            </p>
                        </div>
                        <div class="text-right">
                            <AppBadge :color="statusColor(b.status)">{{ b.status_label }}</AppBadge>
                            <p class="mt-1 text-sm font-semibold">{{ formatMoney(b.total_cents) }} <span class="text-xs font-normal text-slate-500">c/ IVA</span></p>
                        </div>
                    </button>
                </li>
            </ul>
        </AppCard>
    </div>
</template>
