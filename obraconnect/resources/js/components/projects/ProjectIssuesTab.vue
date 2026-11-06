<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { issuesApi, specialtiesApi } from '@/api';
import type { Issue, IssueAssignee, Project, Specialty } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useStructurePlaces } from '@/composables/useStructurePlaces';
import { useSessionStore } from '@/stores/session';
import { formatDate } from '@/utils/format';
import { ISSUE_KINDS, ISSUE_SEVERITIES, issueStatusColor, severityColor } from '@/utils/inspections';
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
import AppTextarea from '@/components/ui/AppTextarea.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const props = defineProps<{ project: Project }>();

const session = useSessionStore();
const router = useRouter();
const { placeOptions, hasStructure, toIds, labelOf, load: loadPlaces } = useStructurePlaces(props.project.id);
loadPlaces();

const canCreate = computed(() => props.project.permissions.includes('issues.create') && props.project.status !== 'archived' && !session.isReadOnly);

const GROUPS = [
    { key: 'open', label: 'Por resolver' },
    { key: 'review', label: 'Por validar' },
    { key: 'overdue', label: 'Atrasadas' },
    { key: 'closed', label: 'Fechadas' },
    { key: 'all', label: 'Todas' },
];
const group = ref('open');
const mine = ref(false);
const search = ref('');
const page = ref(1);
const issues = ref<Issue[]>([]);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const result = await issuesApi.list(props.project.id, { group: group.value, mine: mine.value ? 1 : undefined, search: search.value || undefined, page: page.value });
        issues.value = result.data;
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
watch([group, mine], () => {
    page.value = 1;
    load();
});
watch(page, load);
load();

const open = (issue: Issue) => router.push(`/app/obras/${props.project.id}/ocorrencias/${issue.id}`);

// ---- Nova ocorrência ----
const creating = ref(false);
const assignees = ref<IssueAssignee[]>([]);
const specialties = ref<Specialty[]>([]);
const form = useForm({
    kind: 'nonconformity',
    severity: 'minor',
    title: '',
    description: '',
    place: '',
    specialty_id: '' as number | '',
    assignee_user_id: '' as number | '',
    due_on: '',
});

async function startCreate(): Promise<void> {
    form.reset();
    creating.value = true;
    [assignees.value, specialties.value] = await Promise.all([issuesApi.assignees(props.project.id), specialtiesApi.list()]);
}

const assigneeOptions = computed(() => [{ value: '', label: '(ninguém, por agora)' }, ...assignees.value.map((a) => ({ value: a.user_id, label: `${a.name} · ${a.role}` }))]);
const specialtyOptions = computed(() => [{ value: '', label: '—' }, ...specialties.value.filter((s) => !s.archived).map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }))]);

async function create(): Promise<void> {
    const created = await form.submit(() =>
        issuesApi.create(props.project.id, {
            kind: form.data.kind,
            severity: form.data.severity,
            title: form.data.title,
            description: form.data.description || null,
            specialty_id: form.data.specialty_id === '' ? null : Number(form.data.specialty_id),
            assignee_user_id: form.data.assignee_user_id === '' ? null : Number(form.data.assignee_user_id),
            due_on: form.data.due_on || null,
            ...toIds(form.data.place),
        }),
    );
    if (created) {
        creating.value = false;
        router.push(`/app/obras/${props.project.id}/ocorrencias/${created.id}`);
    }
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1" role="group" aria-label="Filtrar ocorrências">
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
                <div class="ml-2"><AppCheckbox v-model="mine" label="Só as minhas" /></div>
            </div>
            <div class="flex flex-wrap gap-2">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Procurar (título ou n.º)"
                    aria-label="Procurar ocorrências"
                    class="w-56 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
                />
                <AppButton v-if="canCreate" @click="startCreate">Nova ocorrência</AppButton>
            </div>
        </div>

        <AppCard>
            <div v-if="loading && issues.length === 0" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
            <EmptyState
                v-else-if="issues.length === 0"
                :title="group === 'open' ? 'Nada por resolver' : 'Sem ocorrências'"
                :description="canCreate && group === 'open' && !search ? 'Registe não conformidades e ocorrências com responsável e prazo. Também se abrem a partir dos pontos não conformes das inspeções.' : undefined"
            />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="i in issues" :key="i.id">
                    <button type="button" class="flex w-full flex-wrap items-start justify-between gap-2 py-3 text-left hover:bg-slate-50" @click="open(i)">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900"><span class="text-slate-500">{{ i.reference }}</span> · {{ i.title }}</p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                <span v-if="i.specialty" class="font-mono font-semibold">{{ i.specialty.code }}</span>
                                <span v-if="labelOf(i.location_id, i.element_id)">{{ labelOf(i.location_id, i.element_id) }}</span>
                                <span>· {{ i.assignee ?? 'sem responsável' }}</span>
                                <span v-if="i.due_on" :class="i.overdue ? 'font-semibold text-red-700' : ''">· prazo {{ formatDate(i.due_on) }}</span>
                                <span v-if="i.inspection">· inspeção n.º {{ i.inspection.number }}</span>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <AppBadge v-if="i.overdue" color="red">Atrasada</AppBadge>
                            <AppBadge :color="severityColor(i.severity)">{{ i.severity_label }}</AppBadge>
                            <AppBadge :color="issueStatusColor(i.status)">{{ i.status_label }}</AppBadge>
                        </div>
                    </button>
                </li>
            </ul>
            <AppPagination v-if="lastPage > 1" class="mt-3" :page="page" :last-page="lastPage" :total="total" @change="(p) => (page = p)" />
        </AppCard>

        <AppModal :open="creating" title="Nova ocorrência" size="lg" @close="creating = false">
            <form id="issue-form" class="space-y-4" novalidate @submit.prevent="create">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppSelect v-model="form.data.kind" label="Tipo" :options="ISSUE_KINDS" :error="form.errors.value.kind" />
                    <AppSelect v-model="form.data.severity" label="Gravidade" :options="ISSUE_SEVERITIES" :error="form.errors.value.severity" />
                </div>
                <AppInput v-model="form.data.title" label="Título" required :maxlength="200" placeholder="Ex.: Fissura na parede da cozinha" :error="form.errors.value.title" />
                <AppTextarea v-model="form.data.description" label="Descrição" :rows="3" :maxlength="10000" :error="form.errors.value.description" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-if="hasStructure">
                        <label class="mb-1 block text-sm font-medium text-slate-700" for="issue-place">Onde</label>
                        <select id="issue-place" v-model="form.data.place" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm">
                            <option value="">(toda a obra)</option>
                            <option v-for="o in placeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                    </div>
                    <AppSelect v-model="form.data.specialty_id" label="Especialidade" :options="specialtyOptions" :error="form.errors.value.specialty_id" />
                    <AppSelect v-model="form.data.assignee_user_id" label="Responsável" :options="assigneeOptions" :error="form.errors.value.assignee_user_id" />
                    <AppInput
                        v-model="form.data.due_on"
                        type="date"
                        label="Prazo"
                        :hint="`Passado o prazo, o responsável e o diretor de obra recebem um email.`"
                        :error="form.errors.value.due_on"
                    />
                </div>
                <p class="text-sm text-slate-600">Depois de registar pode juntar fotografias.</p>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="creating = false">Cancelar</AppButton>
                <AppButton type="submit" form="issue-form" :loading="form.processing.value">Registar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
