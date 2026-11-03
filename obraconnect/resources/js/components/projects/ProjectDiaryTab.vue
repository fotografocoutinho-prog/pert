<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { diaryApi, projectsApi } from '@/api';
import type { CalendarDay, DiaryEntry, Project, ProjectCalendar } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { isNetworkError } from '@/offline/cache';
import { useSyncStore } from '@/stores/sync';
import { formatLongDate, todayIso, weatherLabel } from '@/utils/projects';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const props = defineProps<{ project: Project }>();

const session = useSessionStore();
const sync = useSyncStore();
sync.refresh();
const route = useRoute();
const router = useRouter();

const entries = ref<DiaryEntry[]>([]);
const calendar = ref<ProjectCalendar | null>(null);
const loading = ref(true);
const month = ref(typeof route.query.mes === 'string' && /^\d{4}-\d{2}$/.test(route.query.mes) ? route.query.mes : todayIso().slice(0, 7));

const canWrite = computed(() => props.project.permissions.includes('diary.write') && props.project.status !== 'archived' && !session.isReadOnly);
const monthLabel = computed(() => {
    const [y, m] = month.value.split('-').map(Number);
    const label = new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric' }).format(new Date(y, m - 1, 1));
    return label.charAt(0).toUpperCase() + label.slice(1);
});
const isCurrentMonth = computed(() => month.value === todayIso().slice(0, 7));
/** Registos deste mês guardados neste telemóvel (modo sem rede). */
const localThisMonth = computed(() => sync.pending.filter((p) => p.projectId === props.project.id && p.date.startsWith(month.value)));
const pendingFor = (entry: DiaryEntry) => localThisMonth.value.find((p) => p.entryId === entry.id || p.date === entry.entry_date);
/** Só no telemóvel (ainda não chegaram ao servidor). */
const localOnly = computed(() => localThisMonth.value.filter((p) => !entries.value.some((e) => e.id === p.entryId || e.entry_date === p.date)));
/** Dias do mês no calendário da obra (feriados, paragens, dias sem trabalho). */
const days = computed(() => new Map<string, CalendarDay>((calendar.value?.days ?? []).map((d) => [d.date, d])));
const hasRecord = (date: string) => entries.value.some((e) => e.entry_date === date) || localOnly.value.some((p) => p.date === date);
const todayMissing = computed(() => isCurrentMonth.value && days.value.get(todayIso())?.working !== false && !hasRecord(todayIso()));
/** Dias úteis já passados (desde o início da obra) sem registo, só com a obra em curso. */
const missingDays = computed(() => {
    if (props.project.status !== 'active' || !calendar.value) return [];
    const since = props.project.starts_on ?? props.project.created_at?.slice(0, 10) ?? '';
    const today = todayIso();
    return calendar.value.days.filter((d) => d.working && d.date < today && d.date >= since && !hasRecord(d.date)).map((d) => d.date);
});
const dayOfMonth = (iso: string) => Number(iso.slice(8, 10));

async function load(): Promise<void> {
    loading.value = true;
    // O calendário só ajuda: se falhar, o diário mostra-se na mesma.
    projectsApi.calendar(props.project.id, month.value).then(
        (c) => (calendar.value = c),
        () => (calendar.value = null),
    );
    try {
        entries.value = (await diaryApi.list(props.project.id, { month: month.value })).data;
    } catch (e) {
        // Sem rede e sem cópia deste mês: mostra só o que está guardado no telemóvel.
        if (!isNetworkError(e)) throw e;
        entries.value = [];
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

        <div v-if="!loading && missingDays.length && canWrite" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ missingDays.length === 1 ? 'Dia útil sem registo:' : `${missingDays.length} dias úteis sem registo:` }}
            <template v-for="(date, i) in missingDays.slice(0, 12)" :key="date">
                <RouterLink :to="`/app/obras/${project.id}/diario/novo?data=${date}`" class="font-semibold underline">{{ dayOfMonth(date) }}</RouterLink>{{ i < Math.min(missingDays.length, 12) - 1 ? ', ' : '' }}
            </template>
            <template v-if="missingDays.length > 12"> …</template>
        </div>

        <div v-if="loading" class="flex justify-center py-10"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <EmptyState v-else-if="entries.length === 0 && localOnly.length === 0" title="Sem registos neste mês" />
        <ul v-else class="divide-y divide-slate-100">
            <li v-for="p in localOnly" :key="p.key">
                <RouterLink
                    :to="p.entryId ? `/app/obras/${project.id}/diario/${p.entryId}` : `/app/obras/${project.id}/diario/novo?data=${p.date}`"
                    class="-mx-2 flex flex-wrap items-center justify-between gap-3 rounded-lg px-2 py-3 hover:bg-slate-50"
                >
                    <div>
                        <p class="font-semibold text-slate-900 first-letter:uppercase">{{ formatLongDate(p.date) }}</p>
                        <p class="text-sm text-slate-500">
                            Guardado neste telemóvel{{ p.photos.length ? ` · ${p.photos.length} ${p.photos.length === 1 ? 'foto' : 'fotos'}` : '' }}
                        </p>
                    </div>
                    <AppBadge :color="p.status === 'conflict' ? 'red' : 'blue'">{{ p.status === 'conflict' ? 'Com problema' : 'No telemóvel' }}</AppBadge>
                </RouterLink>
            </li>
            <li v-for="entry in entries" :key="entry.id">
                <RouterLink
                    :to="`/app/obras/${project.id}/diario/${entry.id}`"
                    class="-mx-2 flex flex-wrap items-center justify-between gap-3 rounded-lg px-2 py-3 hover:bg-slate-50"
                >
                    <div>
                        <p class="font-semibold text-slate-900 first-letter:uppercase">
                            {{ formatLongDate(entry.entry_date) }}
                            <span v-if="days.get(entry.entry_date)?.holiday" class="ml-1 text-sm font-normal text-amber-700">· {{ days.get(entry.entry_date)!.holiday!.name }}</span>
                        </p>
                        <p class="text-sm text-slate-500">
                            {{ [weatherSummary(entry), entry.workforce_total ? `${entry.workforce_total} ${entry.workforce_total === 1 ? 'pessoa' : 'pessoas'}` : null, entry.photos_count ? `${entry.photos_count} ${entry.photos_count === 1 ? 'foto' : 'fotos'}` : null, entry.created_by].filter(Boolean).join(' · ') || '—' }}
                        </p>
                    </div>
                    <span class="flex items-center gap-1.5">
                        <AppBadge v-if="pendingFor(entry)" :color="pendingFor(entry)!.status === 'conflict' ? 'red' : 'blue'">
                            {{ pendingFor(entry)!.status === 'conflict' ? 'Com problema' : 'Por enviar' }}
                        </AppBadge>
                        <AppBadge :color="entry.status === 'submitted' ? 'green' : 'amber'">{{ entry.status_label }}</AppBadge>
                    </span>
                </RouterLink>
            </li>
        </ul>
    </AppCard>
</template>
