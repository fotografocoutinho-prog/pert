<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { diaryApi } from '@/api';
import type { DiaryEntry, Project } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { formatLongDate, todayIso, weatherLabel } from '@/utils/projects';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const props = defineProps<{ project: Project }>();

const session = useSessionStore();
const route = useRoute();
const router = useRouter();

const entries = ref<DiaryEntry[]>([]);
const loading = ref(true);
const month = ref(typeof route.query.mes === 'string' && /^\d{4}-\d{2}$/.test(route.query.mes) ? route.query.mes : todayIso().slice(0, 7));

const canWrite = computed(() => props.project.permissions.includes('diary.write') && props.project.status !== 'archived' && !session.isReadOnly);
const monthLabel = computed(() => {
    const [y, m] = month.value.split('-').map(Number);
    const label = new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric' }).format(new Date(y, m - 1, 1));
    return label.charAt(0).toUpperCase() + label.slice(1);
});
const isCurrentMonth = computed(() => month.value === todayIso().slice(0, 7));
const todayMissing = computed(() => isCurrentMonth.value && !entries.value.some((e) => e.entry_date === todayIso()));

async function load(): Promise<void> {
    loading.value = true;
    try {
        entries.value = (await diaryApi.list(props.project.id, { month: month.value })).data;
    } finally {
        loading.value = false;
    }
}

watch(month, (value) => {
    router.replace({ query: { ...route.query, mes: value } });
    load();
}, { immediate: true });

function shift(delta: number): void {
    const [y, m] = month.value.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    month.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function weatherSummary(entry: DiaryEntry): string {
    const w = entry.weather;
    if (!w) return '';
    const parts = [w.morning ? weatherLabel(w.morning) : null, w.afternoon && w.afternoon !== w.morning ? weatherLabel(w.afternoon) : null].filter(Boolean);
    const temps = w.temp_min !== undefined && w.temp_min !== null && w.temp_max !== undefined && w.temp_max !== null ? `${w.temp_min}–${w.temp_max} °C` : null;
    return [parts.join(' / '), temps].filter(Boolean).join(' · ');
}
</script>

<template>
    <AppCard>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <AppButton size="sm" variant="secondary" aria-label="Mês anterior" @click="shift(-1)">‹</AppButton>
                <span class="min-w-40 text-center font-semibold">{{ monthLabel }}</span>
                <AppButton size="sm" variant="secondary" aria-label="Mês seguinte" :disabled="isCurrentMonth" @click="shift(1)">›</AppButton>
            </div>
            <AppButton v-if="canWrite" @click="router.push(`/app/obras/${project.id}/diario/novo`)">Novo registo</AppButton>
        </div>

        <div v-if="todayMissing && canWrite" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Ainda não há registo de hoje.
            <RouterLink :to="`/app/obras/${project.id}/diario/novo`" class="font-semibold underline">Registar agora</RouterLink>
        </div>

        <div v-if="loading" class="flex justify-center py-10"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <EmptyState v-else-if="entries.length === 0" title="Sem registos neste mês" />
        <ul v-else class="divide-y divide-slate-100">
            <li v-for="entry in entries" :key="entry.id">
                <RouterLink
                    :to="`/app/obras/${project.id}/diario/${entry.id}`"
                    class="-mx-2 flex flex-wrap items-center justify-between gap-3 rounded-lg px-2 py-3 hover:bg-slate-50"
                >
                    <div>
                        <p class="font-semibold text-slate-900 first-letter:uppercase">{{ formatLongDate(entry.entry_date) }}</p>
                        <p class="text-sm text-slate-500">
                            {{ [weatherSummary(entry), entry.workforce_total ? `${entry.workforce_total} ${entry.workforce_total === 1 ? 'pessoa' : 'pessoas'}` : null, entry.created_by].filter(Boolean).join(' · ') || '—' }}
                        </p>
                    </div>
                    <AppBadge :color="entry.status === 'submitted' ? 'green' : 'amber'">{{ entry.status_label }}</AppBadge>
                </RouterLink>
            </li>
        </ul>
    </AppCard>
</template>
