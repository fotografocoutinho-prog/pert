<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { calendarsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Holiday, HolidayKind, WeeklyHours, WorkCalendar } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();

const DAYS: { key: keyof WeeklyHours; short: string }[] = [
    { key: '1', short: 'Seg' },
    { key: '2', short: 'Ter' },
    { key: '3', short: 'Qua' },
    { key: '4', short: 'Qui' },
    { key: '5', short: 'Sex' },
    { key: '6', short: 'Sáb' },
    { key: '7', short: 'Dom' },
];
const WEEKDAYS: WeeklyHours = { '1': 8, '2': 8, '3': 8, '4': 8, '5': 8, '6': 0, '7': 0 };
const WITH_SATURDAY: WeeklyHours = { ...WEEKDAYS, '6': 8 };
/** App\Enums\HolidayKind (o nacional costuma vir do botão próprio). */
const KIND_OPTIONS: { value: HolidayKind; label: string }[] = [
    { value: 'municipal', label: 'Feriado municipal' },
    { value: 'company', label: 'Paragem da empresa' },
    { value: 'optional', label: 'Feriado facultativo' },
    { value: 'national', label: 'Feriado nacional' },
];
const FIRST_NATIONAL_YEAR = 2016;

const canManage = computed(() => session.can('calendars.manage') && !session.isReadOnly);
const thisYear = new Date().getFullYear();

const calendars = ref<WorkCalendar[]>([]);
const selectedId = ref<number | null>(null);
const loading = ref(true);
const selected = computed(() => calendars.value.find((c) => c.id === selectedId.value) ?? null);

async function loadCalendars(): Promise<void> {
    calendars.value = await calendarsApi.list();
    if (!calendars.value.some((c) => c.id === selectedId.value)) selectedId.value = calendars.value[0]?.id ?? null;
    loading.value = false;
}

onMounted(loadCalendars);

function replaceCalendar(updated: WorkCalendar): void {
    calendars.value = calendars.value.map((c) => (c.id === updated.id ? updated : c));
}

// Nome e horas por dia
const edit = reactive({ name: '', hours: { ...WEEKDAYS } as Record<string, string | number> });
const editErrors = ref<Record<string, string>>({});
const saving = ref(false);
watch(selected, (c) => {
    if (!c) return;
    edit.name = c.name;
    edit.hours = Object.fromEntries(DAYS.map((d) => [d.key, String(c.weekly_hours[d.key]).replace('.', ',')]));
    editErrors.value = {};
}, { immediate: true });

const toHours = (hours: Record<string, string | number>): WeeklyHours =>
    Object.fromEntries(DAYS.map((d) => [d.key, Number(String(hours[d.key] ?? 0).replace(',', '.')) || 0])) as WeeklyHours;

async function saveCalendar(): Promise<void> {
    if (!selected.value) return;
    saving.value = true;
    editErrors.value = {};
    try {
        replaceCalendar(await calendarsApi.update(selected.value.id, { name: edit.name, weekly_hours: toHours(edit.hours) }));
        toast.success('Calendário guardado.');
    } catch (e) {
        if (e instanceof ApiError) {
            editErrors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k.startsWith('weekly_hours') ? 'weekly_hours' : k, v[0] ?? '']));
            if (!Object.keys(editErrors.value).length) toast.error(e.message);
        }
    } finally {
        saving.value = false;
    }
}

async function makeDefault(): Promise<void> {
    if (!selected.value || !window.confirm(`Usar “${selected.value.name}” nas obras que não têm calendário escolhido?`)) return;
    try {
        await calendarsApi.update(selected.value.id, { is_default: true });
        await loadCalendars();
        toast.success('Calendário por omissão mudado.');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível mudar.');
    }
}

async function removeCalendar(): Promise<void> {
    if (!selected.value || !window.confirm(`Apagar o calendário “${selected.value.name}” com os seus feriados e paragens?`)) return;
    try {
        await calendarsApi.remove(selected.value.id);
        selectedId.value = null;
        await loadCalendars();
        toast.success('Calendário apagado.');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível apagar.');
    }
}

// Novo calendário
const createOpen = ref(false);
const createForm = useForm({ name: '', saturdays: false, with_national_holidays: true });

async function createCalendar(): Promise<void> {
    const created = await createForm.submit(
        () =>
            calendarsApi.create({
                name: createForm.data.name,
                weekly_hours: createForm.data.saturdays ? WITH_SATURDAY : WEEKDAYS,
                with_national_holidays: createForm.data.with_national_holidays,
            }),
        { success: 'Calendário criado.' },
    );
    if (created) {
        createOpen.value = false;
        calendars.value.push(created);
        selectedId.value = created.id;
    }
}

// Feriados e paragens do ano
const year = ref(thisYear);
const daysOff = ref<Holiday[]>([]);
const loadingDays = ref(false);
const hasNational = computed(() => daysOff.value.some((h) => h.kind === 'national'));
const nextYearMissing = computed(() => !!selected.value && !selected.value.national_holiday_years.includes(thisYear + 1));

async function loadDaysOff(): Promise<void> {
    if (!selected.value) return;
    loadingDays.value = true;
    try {
        daysOff.value = await calendarsApi.holidays(selected.value.id, year.value);
    } finally {
        loadingDays.value = false;
    }
}

watch([selectedId, year], loadDaysOff, { immediate: true });

interface DaysOffGroup {
    ids: number[];
    from: string;
    to: string;
    count: number;
    name: string;
    kind_label: string;
}

const dayNumber = (iso: string) => Date.UTC(Number(iso.slice(0, 4)), Number(iso.slice(5, 7)) - 1, Number(iso.slice(8, 10))) / 86_400_000;

/** Dias seguidos com o mesmo nome (ex.: férias coletivas, que saltam fins de semana) numa só linha. */
const groups = computed<DaysOffGroup[]>(() => {
    const out: DaysOffGroup[] = [];
    for (const h of daysOff.value) {
        const last = out[out.length - 1];
        if (last && last.name === h.name && last.kind_label === h.kind_label && dayNumber(h.date) - dayNumber(last.to) <= 3) {
            last.ids.push(h.id);
            last.to = h.date;
            last.count++;
        } else {
            out.push({ ids: [h.id], from: h.date, to: h.date, count: 1, name: h.name, kind_label: h.kind_label });
        }
    }
    return out;
});

const longDay = new Intl.DateTimeFormat('pt-PT', { weekday: 'short', day: 'numeric', month: 'long' });
const shortDay = new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'long' });
const asDate = (iso: string) => new Date(Number(iso.slice(0, 4)), Number(iso.slice(5, 7)) - 1, Number(iso.slice(8, 10)));
const groupLabel = (g: DaysOffGroup) => (g.from === g.to ? longDay.format(asDate(g.from)) : `${shortDay.format(asDate(g.from))} a ${shortDay.format(asDate(g.to))}`);

async function removeGroup(g: DaysOffGroup): Promise<void> {
    if (!selected.value || !window.confirm(`Retirar “${g.name}” (${groupLabel(g)})?`)) return;
    try {
        await calendarsApi.removeDaysOff(selected.value.id, g.ids);
        await loadDaysOff();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível retirar.');
    }
}

const carnival = ref(false);
const addingNational = ref(false);
async function addNational(targetYear: number): Promise<void> {
    if (!selected.value) return;
    addingNational.value = true;
    try {
        const added = await calendarsApi.addNational(selected.value.id, targetYear, carnival.value);
        toast.success(added ? `${added} feriado(s) de ${targetYear} juntado(s).` : `Os feriados nacionais de ${targetYear} já estavam todos.`);
        await loadCalendars();
        if (year.value === targetYear) await loadDaysOff();
        else year.value = targetYear;
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível juntar os feriados.');
    } finally {
        addingNational.value = false;
    }
}

const dayOffForm = useForm({ date: '', until: '', name: '', kind: 'municipal' as HolidayKind });
async function addDayOff(): Promise<void> {
    if (!selected.value) return;
    const created = await dayOffForm.submit(() =>
        calendarsApi.addDaysOff(selected.value!.id, { ...dayOffForm.data, until: dayOffForm.data.until || null }),
    );
    if (created) {
        toast.success(created.length === 1 ? 'Dia marcado.' : `${created.length} dias de trabalho marcados.`);
        const createdYear = Number(created[0].date.slice(0, 4));
        dayOffForm.reset({ kind: dayOffForm.data.kind });
        if (createdYear === year.value) await loadDaysOff();
        else year.value = createdYear;
    }
}
</script>

<template>
    <div>
        <PageHeader
            title="Calendários de trabalho"
            description="Dias e horas de trabalho, feriados e paragens. Servem para contar os dias úteis do prazo de cada obra e os dias do diário."
        >
            <AppButton v-if="canManage" @click="createForm.reset(); createOpen = true">Novo calendário</AppButton>
        </PageHeader>

        <div v-if="loading" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <div v-else class="grid gap-4 lg:grid-cols-3">
            <AppCard title="Calendários">
                <ul class="space-y-1">
                    <li v-for="c in calendars" :key="c.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left"
                            :class="c.id === selectedId ? 'bg-brand-50 ring-1 ring-brand-200' : 'hover:bg-slate-50'"
                            :aria-pressed="c.id === selectedId"
                            @click="selectedId = c.id"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-slate-900">{{ c.name }}</span>
                                <span class="block text-xs text-slate-500">
                                    {{ String(c.weekly_total).replace('.', ',') }} h/semana<template v-if="c.projects_count"> · {{ c.projects_count === 1 ? '1 obra' : `${c.projects_count} obras` }}</template>
                                </span>
                            </span>
                            <AppBadge v-if="c.is_default" color="brand">Por omissão</AppBadge>
                        </button>
                    </li>
                </ul>
                <p class="mt-3 text-xs text-slate-500">O calendário por omissão vale para as obras sem calendário escolhido nos dados da obra.</p>
            </AppCard>

            <div v-if="selected" class="space-y-4 lg:col-span-2">
                <AppAlert v-if="nextYearMissing && canManage" type="warning">
                    Este calendário ainda não tem os feriados nacionais de {{ thisYear + 1 }}.
                    <button type="button" class="font-semibold underline" :disabled="addingNational" @click="addNational(thisYear + 1)">Juntar agora</button>
                </AppAlert>

                <AppCard :title="selected.name" description="Horas de trabalho em cada dia da semana (0 = não se trabalha).">
                    <template #actions>
                        <div v-if="canManage" class="flex flex-wrap gap-2">
                            <AppButton v-if="!selected.is_default" size="sm" variant="secondary" @click="makeDefault">Tornar por omissão</AppButton>
                            <AppButton v-if="!selected.is_default" size="sm" variant="danger" @click="removeCalendar">Apagar</AppButton>
                        </div>
                    </template>
                    <form class="space-y-4" novalidate @submit.prevent="saveCalendar">
                        <fieldset class="space-y-4" :disabled="!canManage">
                            <AppInput v-if="canManage" v-model="edit.name" label="Nome" :maxlength="100" required :error="editErrors.name" />
                            <div>
                                <div class="grid grid-cols-7 gap-1.5 sm:gap-2">
                                    <label v-for="d in DAYS" :key="d.key" class="text-center">
                                        <span class="mb-1 block text-xs font-medium text-slate-600">{{ d.short }}</span>
                                        <input
                                            v-model="edit.hours[d.key]"
                                            inputmode="decimal"
                                            :aria-label="`Horas de ${d.short}`"
                                            class="block w-full rounded-lg border border-slate-300 bg-white px-1 py-2 text-center text-base text-slate-900 shadow-sm focus:border-brand-600 focus:ring-2 focus:ring-brand-200 focus:outline-none disabled:bg-slate-50 sm:text-sm"
                                        />
                                    </label>
                                </div>
                                <p v-if="editErrors.weekly_hours" class="mt-1 text-sm text-red-600">{{ editErrors.weekly_hours }}</p>
                            </div>
                        </fieldset>
                        <div v-if="canManage" class="flex justify-end">
                            <AppButton type="submit" :loading="saving">Guardar</AppButton>
                        </div>
                    </form>
                </AppCard>

                <AppCard title="Feriados e paragens" description="Dias sem trabalho neste calendário.">
                    <template #actions>
                        <div class="flex items-center gap-2">
                            <AppButton size="sm" variant="secondary" aria-label="Ano anterior" @click="year--">‹</AppButton>
                            <span class="w-12 text-center font-semibold">{{ year }}</span>
                            <AppButton size="sm" variant="secondary" aria-label="Ano seguinte" @click="year++">›</AppButton>
                        </div>
                    </template>

                    <div v-if="!hasNational && !loadingDays && year >= FIRST_NATIONAL_YEAR" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        Não há feriados nacionais de {{ year }} neste calendário: as contas de dias úteis desse ano tratam-nos como dias de trabalho.
                    </div>

                    <div v-if="loadingDays" class="flex justify-center py-8"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
                    <EmptyState v-else-if="groups.length === 0" :title="`Sem feriados nem paragens em ${year}`" />
                    <ul v-else class="divide-y divide-slate-100">
                        <li v-for="g in groups" :key="g.ids[0]" class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900 first-letter:uppercase">{{ groupLabel(g) }}</p>
                                <p class="text-sm text-slate-500">
                                    {{ g.name }} · {{ g.kind_label }}<template v-if="g.count > 1"> · {{ g.count }} dias de trabalho</template>
                                </p>
                            </div>
                            <AppButton v-if="canManage" size="sm" variant="ghost" @click="removeGroup(g)">Retirar</AppButton>
                        </li>
                    </ul>

                    <div v-if="canManage" class="mt-5 space-y-5 border-t border-slate-100 pt-5">
                        <div v-if="year >= FIRST_NATIONAL_YEAR" class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <AppButton size="sm" variant="secondary" :loading="addingNational" @click="addNational(year)">
                                Juntar feriados nacionais de {{ year }}
                            </AppButton>
                            <AppCheckbox v-model="carnival" label="Também a Terça-feira de Carnaval" />
                        </div>
                        <p class="text-xs text-slate-500">
                            Os 13 feriados obrigatórios (art. 234.º do Código do Trabalho), com Páscoa e Corpo de Deus calculados para o ano. O Carnaval é facultativo
                            e o feriado municipal depende do concelho: marque-os abaixo.
                        </p>

                        <form class="grid gap-3 sm:grid-cols-6" novalidate @submit.prevent="addDayOff">
                            <p class="text-sm font-semibold text-slate-900 sm:col-span-6">Marcar dia ou período</p>
                            <AppAlert v-if="dayOffForm.message.value" type="error" class="sm:col-span-6">{{ dayOffForm.message.value }}</AppAlert>
                            <AppInput v-model="dayOffForm.data.date" type="date" label="Dia" required class="sm:col-span-2" :error="dayOffForm.errors.value.date" />
                            <AppInput
                                v-model="dayOffForm.data.until"
                                type="date"
                                label="Até (período)"
                                hint="Só os dias de trabalho ficam marcados."
                                class="sm:col-span-2"
                                :error="dayOffForm.errors.value.until"
                            />
                            <AppSelect v-model="dayOffForm.data.kind" label="Tipo" :options="KIND_OPTIONS" class="sm:col-span-2" :error="dayOffForm.errors.value.kind" />
                            <AppInput
                                v-model="dayOffForm.data.name"
                                label="Nome"
                                required
                                :maxlength="100"
                                placeholder="Ex.: São João, Férias coletivas"
                                class="sm:col-span-4"
                                :error="dayOffForm.errors.value.name"
                            />
                            <div class="flex items-end sm:col-span-2">
                                <AppButton type="submit" block :loading="dayOffForm.processing.value">Marcar</AppButton>
                            </div>
                        </form>
                    </div>
                </AppCard>
            </div>
        </div>

        <AppModal :open="createOpen" title="Novo calendário" @close="createOpen = false">
            <form id="calendar-form" class="space-y-4" novalidate @submit.prevent="createCalendar">
                <AppAlert v-if="createForm.message.value" type="error">{{ createForm.message.value }}</AppAlert>
                <AppInput v-model="createForm.data.name" label="Nome" required :maxlength="100" placeholder="Ex.: Obras com sábados" :error="createForm.errors.value.name" />
                <AppCheckbox v-model="createForm.data.saturdays" label="Trabalha-se ao sábado" description="Segunda a sexta, 8 horas; com esta opção também ao sábado. Pode afinar as horas depois." />
                <AppCheckbox
                    v-model="createForm.data.with_national_holidays"
                    :label="`Com os feriados nacionais de ${thisYear} e ${thisYear + 1}`"
                />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="createOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="calendar-form" :loading="createForm.processing.value">Criar calendário</AppButton>
            </template>
        </AppModal>
    </div>
</template>
