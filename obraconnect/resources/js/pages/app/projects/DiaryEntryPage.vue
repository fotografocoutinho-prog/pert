<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { diaryApi, projectsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { DiaryEntry, DiaryItem, DiaryItemKind, Project } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDateTime } from '@/utils/format';
import { DIARY_SECTIONS, formatLongDate, todayIso, WEATHER_OPTIONS, weatherLabel } from '@/utils/projects';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import DiaryPhotos from '@/components/projects/DiaryPhotos.vue';

/** Linha em edição: números como texto para aceitar "12,5". */
interface ItemRow {
    uid: number;
    kind: DiaryItemKind;
    description: string;
    quantity: string;
    unit: string;
    company: string;
}

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const toast = useToastStore();

const project = ref<Project | null>(null);
const entry = ref<DiaryEntry | null>(null);
const error = ref<string | null>(null);

const projectId = computed(() => Number(route.params.id));
const isNew = computed(() => route.params.entryId === undefined);
const can = (permission: string) =>
    Boolean(project.value?.permissions.includes(permission)) && project.value?.status !== 'archived' && !session.isReadOnly;
const editable = computed(() => can('diary.write') && (isNew.value || entry.value?.status === 'draft'));

const form = useForm({
    entry_date: todayIso(),
    morning: null as string | null,
    afternoon: null as string | null,
    temp_min: '' as string | number,
    temp_max: '' as string | number,
    work_summary: '',
    notes: '',
    items: [] as ItemRow[],
});

let nextUid = 0;

function toRow(item: DiaryItem): ItemRow {
    return {
        uid: ++nextUid,
        kind: item.kind,
        description: item.description,
        quantity: item.quantity === null ? '' : String(item.quantity).replace('.', ','),
        unit: item.unit ?? '',
        company: item.company ?? '',
    };
}

function fill(e: DiaryEntry | null): void {
    form.reset({
        entry_date: e?.entry_date ?? todayIso(),
        morning: e?.weather?.morning ?? null,
        afternoon: e?.weather?.afternoon ?? null,
        temp_min: e?.weather?.temp_min ?? '',
        temp_max: e?.weather?.temp_max ?? '',
        work_summary: e?.work_summary ?? '',
        notes: e?.notes ?? '',
        items: (e?.items ?? []).map(toRow),
    });
}

async function load(): Promise<void> {
    error.value = null;
    try {
        project.value = await projectsApi.get(projectId.value);
        entry.value = isNew.value ? null : await diaryApi.get(projectId.value, Number(route.params.entryId));
        fill(entry.value);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível abrir o registo.';
    }
}

watch(() => route.fullPath, load, { immediate: true });

// Linhas por secção (as linhas vivem todas em form.data.items, pela ordem em que foram criadas).
const rowsOf = (kind: DiaryItemKind) => form.data.items.filter((i) => i.kind === kind);
const addRow = (kind: DiaryItemKind) => form.data.items.push({ uid: ++nextUid, kind, description: '', quantity: '', unit: '', company: '' });
const removeRow = (row: ItemRow) => form.data.items.splice(form.data.items.indexOf(row), 1);
const rowError = (row: ItemRow, field: string) => form.errors.value[`items.${form.data.items.indexOf(row)}.${field}`];

const number = (value: string | number): number | null => {
    const text = String(value).trim().replace(',', '.');
    return text === '' ? null : Number(text);
};

function payload(): Record<string, unknown> {
    // Linhas deixadas em branco não seguem (e saem do formulário, para os erros baterem com as posições).
    form.data.items = form.data.items.filter((i) => i.description.trim() !== '' || i.quantity.trim() !== '');

    return {
        ...(isNew.value ? { entry_date: form.data.entry_date } : {}),
        weather: {
            morning: form.data.morning || null,
            afternoon: form.data.afternoon || null,
            temp_min: number(form.data.temp_min),
            temp_max: number(form.data.temp_max),
        },
        work_summary: form.data.work_summary || null,
        notes: form.data.notes || null,
        items: form.data.items.map((i) => ({
            kind: i.kind,
            description: i.description.trim(),
            quantity: number(i.quantity),
            unit: i.unit.trim() || null,
            company: i.company.trim() || null,
        })),
    };
}

async function save(submitAfter = false): Promise<void> {
    if (submitAfter && !window.confirm('Depois de submetido, o registo fica fechado e não pode ser alterado. Submeter?')) return;

    const body = payload();
    const saved = await form.submit(() =>
        isNew.value ? diaryApi.create(projectId.value, body) : diaryApi.update(projectId.value, entry.value!.id, body),
    );
    if (!saved) return;

    let result = saved;
    if (submitAfter) {
        try {
            result = await diaryApi.submit(projectId.value, saved.id);
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : 'O rascunho foi guardado, mas não foi possível submeter.');
        }
    }
    toast.success(result.status === 'submitted' ? 'Registo submetido.' : 'Rascunho guardado.');

    if (isNew.value) {
        await router.replace(`/app/obras/${projectId.value}/diario/${result.id}`);
    } else {
        entry.value = result;
        fill(result);
    }
}

async function remove(): Promise<void> {
    if (!entry.value || !window.confirm('Apagar este rascunho?')) return;
    try {
        await diaryApi.remove(projectId.value, entry.value.id);
        toast.success('Rascunho apagado.');
        await router.push(`/app/obras/${projectId.value}?separador=diario`);
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível apagar.');
    }
}

/** Equipas e equipamentos costumam repetir-se de um dia para o outro. */
const copying = ref(false);
async function copyFromLast(): Promise<void> {
    copying.value = true;
    try {
        const latest = (await diaryApi.list(projectId.value, { page: 1 })).data.find((e) => e.entry_date < form.data.entry_date);
        if (!latest) {
            toast.info('Não há registos anteriores nesta obra.');
            return;
        }
        const full = await diaryApi.get(projectId.value, latest.id);
        const copied = (full.items ?? []).filter((i) => i.kind === 'workforce' || i.kind === 'equipment').map(toRow);
        form.data.items.push(...copied);
        toast.success(copied.length ? `Copiadas ${copied.length} linhas de ${formatLongDate(latest.entry_date)}.` : 'O último registo não tinha mão de obra nem equipamentos.');
    } finally {
        copying.value = false;
    }
}

const itemsOf = (kind: DiaryItemKind) => (entry.value?.items ?? []).filter((i) => i.kind === kind);
const quantityText = (i: DiaryItem) => (i.quantity === null ? '' : `${String(i.quantity).replace('.', ',')}${i.unit ? ` ${i.unit}` : ''}`);
</script>

<template>
    <div>
        <AppAlert v-if="error" type="error">{{ error }}</AppAlert>
        <div v-else-if="!project" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>

        <template v-else>
            <RouterLink :to="`/app/obras/${project.id}?separador=diario`" class="text-sm font-medium text-brand-700 hover:underline">
                ← {{ project.name }}
            </RouterLink>
            <div class="mt-2 mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-medium tracking-wide text-slate-500">Diário de obra · {{ project.code }}</p>
                    <h1 class="text-2xl font-bold text-slate-900 first-letter:uppercase">
                        {{ isNew ? 'Novo registo' : formatLongDate(entry?.entry_date ?? form.data.entry_date) }}
                    </h1>
                </div>
                <AppBadge v-if="entry" :color="entry.status === 'submitted' ? 'green' : 'amber'">{{ entry.status_label }}</AppBadge>
            </div>

            <!-- Edição (novo ou rascunho) -->
            <form v-if="editable" class="space-y-4" novalidate @submit.prevent="save(false)">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>

                <AppCard title="Dia e tempo">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <AppInput v-if="isNew" v-model="form.data.entry_date" label="Data" type="date" required class="col-span-2 sm:col-span-4" :error="form.errors.value.entry_date" />
                        <AppSelect v-model="form.data.morning" label="Manhã" :options="WEATHER_OPTIONS" placeholder="—" :error="form.errors.value['weather.morning']" />
                        <AppSelect v-model="form.data.afternoon" label="Tarde" :options="WEATHER_OPTIONS" placeholder="—" :error="form.errors.value['weather.afternoon']" />
                        <AppInput v-model="form.data.temp_min" label="Mín. (°C)" inputmode="numeric" :error="form.errors.value['weather.temp_min']" />
                        <AppInput v-model="form.data.temp_max" label="Máx. (°C)" inputmode="numeric" :error="form.errors.value['weather.temp_max']" />
                    </div>
                </AppCard>

                <AppCard title="Trabalhos executados">
                    <AppTextarea v-model="form.data.work_summary" aria-label="Trabalhos executados" :rows="4" :maxlength="10000" :error="form.errors.value.work_summary" />
                </AppCard>

                <AppCard title="Fotografias">
                    <DiaryPhotos v-if="entry" :project-id="project.id" :entry-id="entry.id" :photos="entry.photos ?? []" editable />
                    <template v-else>
                        <p class="mb-3 text-sm text-slate-600">As fotografias ficam ligadas ao registo: guarde o rascunho primeiro.</p>
                        <AppButton type="submit" variant="secondary" :loading="form.processing.value">Guardar rascunho e juntar fotografias</AppButton>
                    </template>
                </AppCard>

                <AppCard v-for="section in DIARY_SECTIONS" :key="section.kind" :title="section.label">
                    <template v-if="section.kind === 'workforce' && isNew">
                        <AppButton size="sm" variant="secondary" class="mb-3" :loading="copying" @click="copyFromLast">
                            Copiar mão de obra e equipamentos do último registo
                        </AppButton>
                    </template>
                    <ul class="space-y-3">
                        <li v-for="row in rowsOf(section.kind)" :key="row.uid" class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12 sm:items-start sm:border-0 sm:p-0">
                            <AppInput v-model="row.description" :placeholder="section.description" :aria-label="section.description" :maxlength="255" class="sm:col-span-5" :error="rowError(row, 'description')" />
                            <AppInput v-if="section.quantity" v-model="row.quantity" :placeholder="section.quantity" :aria-label="section.quantity" inputmode="decimal" class="sm:col-span-2" :error="rowError(row, 'quantity')" />
                            <AppInput v-if="section.unit" v-model="row.unit" placeholder="Unid." aria-label="Unidade" :maxlength="20" class="sm:col-span-1" :error="rowError(row, 'unit')" />
                            <AppInput
                                v-if="section.company"
                                v-model="row.company"
                                :placeholder="section.company"
                                :aria-label="section.company"
                                :maxlength="150"
                                :class="section.unit ? 'sm:col-span-3' : section.quantity ? 'sm:col-span-4' : 'sm:col-span-6'"
                                :error="rowError(row, 'company')"
                            />
                            <AppButton size="sm" variant="ghost" class="justify-self-end text-red-700 sm:col-span-1 sm:mt-1" :aria-label="`Remover linha de ${section.label}`" @click="removeRow(row)">
                                Remover
                            </AppButton>
                        </li>
                    </ul>
                    <AppButton size="sm" variant="secondary" :class="rowsOf(section.kind).length ? 'mt-3' : ''" @click="addRow(section.kind)">+ {{ section.add }}</AppButton>
                </AppCard>

                <AppCard title="Observações">
                    <AppTextarea v-model="form.data.notes" aria-label="Observações" :rows="3" :maxlength="10000" :error="form.errors.value.notes" />
                </AppCard>

                <div class="sticky bottom-0 -mx-4 flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-lg sm:border">
                    <AppButton v-if="entry" variant="ghost" class="mr-auto text-red-700" @click="remove">Apagar rascunho</AppButton>
                    <AppButton type="submit" variant="secondary" :loading="form.processing.value">Guardar rascunho</AppButton>
                    <AppButton v-if="can('diary.submit')" :loading="form.processing.value" @click="save(true)">Guardar e submeter</AppButton>
                </div>
            </form>

            <!-- Consulta (submetido ou sem permissão de escrita) -->
            <div v-else-if="entry" class="space-y-4">
                <AppCard title="Tempo">
                    <p class="text-sm">
                        Manhã: <strong>{{ weatherLabel(entry.weather?.morning) }}</strong> · Tarde: <strong>{{ weatherLabel(entry.weather?.afternoon) }}</strong>
                        <template v-if="entry.weather?.temp_min != null || entry.weather?.temp_max != null">
                            · {{ entry.weather?.temp_min ?? '—' }} a {{ entry.weather?.temp_max ?? '—' }} °C
                        </template>
                    </p>
                </AppCard>
                <AppCard title="Trabalhos executados">
                    <p class="text-sm whitespace-pre-line">{{ entry.work_summary || '—' }}</p>
                </AppCard>
                <AppCard v-if="entry.photos?.length" title="Fotografias">
                    <DiaryPhotos :project-id="project.id" :entry-id="entry.id" :photos="entry.photos" :editable="false" />
                </AppCard>
                <AppCard v-for="section in DIARY_SECTIONS.filter((s) => itemsOf(s.kind).length)" :key="section.kind" :title="section.label">
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="item in itemsOf(section.kind)" :key="item.id" class="flex flex-wrap justify-between gap-2 py-2">
                            <span>{{ item.description }}<span v-if="item.company" class="text-slate-500"> · {{ item.company }}</span></span>
                            <span class="font-medium">{{ quantityText(item) }}</span>
                        </li>
                    </ul>
                </AppCard>
                <AppCard v-if="entry.notes" title="Observações">
                    <p class="text-sm whitespace-pre-line">{{ entry.notes }}</p>
                </AppCard>
                <p class="text-xs text-slate-500">
                    Registado por {{ entry.created_by ?? '—' }}<template v-if="entry.submitted_at"> · submetido por {{ entry.submitted_by ?? '—' }} em {{ formatDateTime(entry.submitted_at) }}</template>.
                </p>
            </div>

            <AppAlert v-else type="warning">Não tem permissão para escrever no diário desta obra.</AppAlert>
        </template>
    </div>
</template>
