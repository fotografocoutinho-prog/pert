<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { checklistsApi, inspectionsApi, specialtiesApi } from '@/api';
import type { ChecklistItemDef, ChecklistTemplate, Inspection, Project, Specialty } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useStructurePlaces } from '@/composables/useStructurePlaces';
import { useSessionStore } from '@/stores/session';
import { formatDate, formatDateTime } from '@/utils/format';
import { emptyChecklistItem, inspectionStatusColor } from '@/utils/inspections';
import ChecklistItemsEditor from '@/components/inspections/ChecklistItemsEditor.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppPagination from '@/components/ui/AppPagination.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const props = defineProps<{ project: Project }>();

const session = useSessionStore();
const router = useRouter();
const { placeOptions, hasStructure, toIds, labelOf, load: loadPlaces } = useStructurePlaces(props.project.id);
loadPlaces();

const canRequest = computed(() => props.project.permissions.includes('inspections.request') && props.project.status !== 'archived' && !session.isReadOnly);

const GROUPS = [
    { key: 'open', label: 'Por fazer' },
    { key: 'review', label: 'Em apreciação' },
    { key: 'closed', label: 'Fechadas' },
    { key: 'all', label: 'Todas' },
];
const group = ref('all');
const search = ref('');
const page = ref(1);
const inspections = ref<Inspection[]>([]);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const result = await inspectionsApi.list(props.project.id, { group: group.value, search: search.value || undefined, page: page.value });
        inspections.value = result.data;
        lastPage.value = result.meta.last_page;
        total.value = result.meta.total;
    } finally {
        loading.value = false;
    }
}

let timer: number | undefined;
watch(search, () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
        page.value = 1;
        load();
    }, 300);
});
watch(group, () => {
    page.value = 1;
    load();
});
watch(page, load);
load();

const open = (inspection: Inspection) => router.push(`/app/obras/${props.project.id}/inspecoes/${inspection.id}`);

// ---- Nova inspeção ----
const creating = ref(false);
const templates = ref<ChecklistTemplate[]>([]);
const specialties = ref<Specialty[]>([]);
const form = useForm({
    checklist_template_id: '' as number | '',
    title: '',
    place: '',
    specialty_id: '' as number | '',
    scheduled_for: '',
    remote: false,
    items: [] as ChecklistItemDef[],
});

async function startCreate(): Promise<void> {
    form.reset({ items: [emptyChecklistItem()] });
    creating.value = true;
    [templates.value, specialties.value] = await Promise.all([checklistsApi.list(), specialtiesApi.list()]);
}

const templateOptions = computed(() => [
    { value: '', label: 'Sem modelo: escrevo os pontos' },
    ...templates.value.filter((t) => !t.archived && t.items_count > 0).map((t) => ({ value: t.id, label: `${t.name} (${t.items_count} pontos)` })),
]);
const specialtyOptions = computed(() => [{ value: '', label: '—' }, ...specialties.value.filter((s) => !s.archived).map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }))]);

// A especialidade do modelo passa para a inspeção (pode mudar-se).
watch(
    () => form.data.checklist_template_id,
    (id) => {
        const template = templates.value.find((t) => t.id === id);
        if (template?.specialty_id) form.data.specialty_id = template.specialty_id;
    },
);

async function create(): Promise<void> {
    const fromTemplate = form.data.checklist_template_id !== '';
    const payload: Record<string, unknown> = {
        title: form.data.title || null,
        specialty_id: form.data.specialty_id === '' ? null : Number(form.data.specialty_id),
        scheduled_for: form.data.scheduled_for || null,
        remote: form.data.remote,
        ...toIds(form.data.place),
    };
    if (fromTemplate) payload.checklist_template_id = Number(form.data.checklist_template_id);
    else payload.items = form.data.items.filter((i) => i.text.trim() !== '');

    const created = await form.submit(() => inspectionsApi.create(props.project.id, payload));
    if (created) {
        creating.value = false;
        router.push(`/app/obras/${props.project.id}/inspecoes/${created.id}`);
    }
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1" role="group" aria-label="Filtrar inspeções">
                <button
                    v-for="g in GROUPS"
                    :key="g.key"
                    type="button"
                    class="rounded-full border px-3 py-1.5 text-sm font-medium"
                    :class="group === g.key ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                    :aria-pressed="group === g.key"
                    @click="group = g.key"
                >
                    {{ g.label }}
                </button>
            </div>
            <div class="flex flex-wrap gap-2">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Procurar (título ou n.º)"
                    aria-label="Procurar inspeções"
                    class="w-56 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
                />
                <AppButton v-if="canRequest" @click="startCreate">Nova inspeção</AppButton>
            </div>
        </div>

        <AppCard>
            <div v-if="loading && inspections.length === 0" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
            <EmptyState
                v-else-if="inspections.length === 0"
                title="Sem inspeções"
                :description="canRequest && group === 'all' && !search ? 'Prepare uma inspeção a partir de uma lista de verificação da empresa ou escreva os pontos à mão.' : undefined"
            />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="i in inspections" :key="i.id">
                    <button type="button" class="flex w-full flex-wrap items-start justify-between gap-2 py-3 text-left hover:bg-slate-50" @click="open(i)">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900">
                                <span class="text-slate-500">N.º {{ i.number }}</span> · {{ i.title }}
                            </p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                <span v-if="i.specialty" class="font-mono font-semibold">{{ i.specialty.code }}</span>
                                <span v-if="labelOf(i.location_id, i.element_id)">{{ labelOf(i.location_id, i.element_id) }}</span>
                                <span v-if="i.scheduled_for">· {{ i.status === 'draft' || i.status === 'requested' ? 'pretendida' : 'marcada' }} para {{ formatDateTime(i.scheduled_for) }}</span>
                                <span v-if="i.inspector">· {{ i.inspector }}</span>
                                <span v-if="i.remote">· remota</span>
                                <span v-if="i.decided_at">· {{ formatDate(i.decided_at) }}</span>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span v-if="i.items_count" class="text-xs text-slate-500">{{ i.answered_count }}/{{ i.items_count }}</span>
                            <AppBadge v-if="i.nok_count" color="red">{{ i.nok_count }} NC</AppBadge>
                            <AppBadge v-if="i.evidence_score !== null" color="blue" :title="'Índice de evidências'">{{ i.evidence_score }}%</AppBadge>
                            <AppBadge :color="inspectionStatusColor(i.status)">{{ i.status_label }}</AppBadge>
                        </div>
                    </button>
                </li>
            </ul>
            <AppPagination v-if="lastPage > 1" class="mt-3" :page="page" :last-page="lastPage" :total="total" @change="(p) => (page = p)" />
        </AppCard>

        <AppModal :open="creating" title="Nova inspeção" size="lg" @close="creating = false">
            <form id="inspection-form" class="space-y-4" novalidate @submit.prevent="create">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <AppSelect v-model="form.data.checklist_template_id" label="Lista de verificação" :options="templateOptions" :error="form.errors.value.checklist_template_id" />
                <AppInput
                    v-model="form.data.title"
                    label="Título"
                    :maxlength="200"
                    :placeholder="form.data.checklist_template_id ? 'O nome da lista, se ficar vazio' : 'Ex.: Laje do piso 1'"
                    :error="form.errors.value.title"
                />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-if="hasStructure">
                        <label class="mb-1 block text-sm font-medium text-slate-700" for="inspection-place">Onde</label>
                        <select id="inspection-place" v-model="form.data.place" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm">
                            <option value="">(toda a obra)</option>
                            <option v-for="o in placeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                    </div>
                    <AppSelect v-model="form.data.specialty_id" label="Especialidade" :options="specialtyOptions" :error="form.errors.value.specialty_id" />
                    <AppInput v-model="form.data.scheduled_for" type="datetime-local" label="Data pretendida" hint="Quem inspeciona confirma ao agendar." :error="form.errors.value.scheduled_for" />
                    <div class="sm:pt-7">
                        <AppCheckbox v-model="form.data.remote" label="Inspeção remota" description="Feita por fotografias: cada ponto deve ter pelo menos uma." />
                    </div>
                </div>
                <div v-if="form.data.checklist_template_id === ''">
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Pontos a verificar</h3>
                    <ChecklistItemsEditor v-model="form.data.items" :errors="form.errors.value" />
                </div>
                <p v-else class="text-sm text-slate-600">Os pontos da lista são copiados para a inspeção. Ainda os pode mudar antes de a pedir.</p>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="creating = false">Cancelar</AppButton>
                <AppButton type="submit" form="inspection-form" :loading="form.processing.value">Criar rascunho</AppButton>
            </template>
        </AppModal>
    </div>
</template>
