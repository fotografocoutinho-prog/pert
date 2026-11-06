<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { issuesApi, projectsApi, specialtiesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { IssueAssignee, IssueDetail, IssueEventItem, MediaItem, Project, Specialty } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useStructurePlaces } from '@/composables/useStructurePlaces';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatBytes, formatDate, formatDateTime } from '@/utils/format';
import { ISSUE_KINDS, ISSUE_SEVERITIES, issueStatusColor, severityColor } from '@/utils/inspections';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';

const route = useRoute();
const toast = useToastStore();

const projectId = computed(() => Number(route.params.id));
const project = ref<Project | null>(null);
const issue = ref<IssueDetail | null>(null);
const error = ref<string | null>(null);
const { placeOptions, hasStructure, toValue, toIds, labelOf, load: loadPlaces } = useStructurePlaces(projectId);

async function load(): Promise<void> {
    error.value = null;
    try {
        const [p, i] = await Promise.all([projectsApi.get(projectId.value), issuesApi.get(projectId.value, Number(route.params.issueId))]);
        project.value = p;
        show(i);
        loadPlaces();
    } catch (e) {
        error.value = e instanceof ApiError && e.status === 403 ? 'Não tem acesso às ocorrências desta obra.' : e instanceof ApiError ? e.message : 'Não foi possível abrir a ocorrência.';
    }
}

function show(detail: IssueDetail): void {
    issue.value = detail;
    document.title = `${detail.reference} · ObraConnect`;
    schedulePoll();
}

const fail = (e: unknown, fallback: string) => toast.error(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : fallback);

// ---- Passagens de estado ----
const acting = ref<string | null>(null);
const reasonFor = ref<{ key: string; label: string } | null>(null);
const reason = ref('');
const REASONS: Record<string, { field: string; hint: string }> = {
    resolve: { field: 'O que foi feito', hint: 'Quem valida lê isto antes de fechar. Junte fotografias do resultado, se ajudar.' },
    reopen: { field: 'Porque é reaberta', hint: 'Volta a “aberta” e o responsável vê este texto.' },
    cancel: { field: 'Motivo da anulação', hint: 'Ex.: registada em duplicado.' },
};

async function act(key: string, text: string | null = null): Promise<void> {
    if (!issue.value) return;
    acting.value = key;
    try {
        show(await issuesApi.act(projectId.value, issue.value.id, key, text));
        reasonFor.value = null;
        toast.success(`${issue.value.reference}: ${issue.value.status_label.toLowerCase()}.`);
    } catch (e) {
        fail(e, 'Não foi possível concluir.');
    } finally {
        acting.value = null;
    }
}

function choose(action: { key: string; label: string; reason: boolean }): void {
    if (action.reason) {
        reason.value = '';
        reasonFor.value = action;
    } else {
        act(action.key);
    }
}

// ---- Editar ----
const editing = ref(false);
const assignees = ref<IssueAssignee[]>([]);
const specialties = ref<Specialty[]>([]);
const form = useForm({
    kind: '',
    severity: '',
    title: '',
    description: '',
    place: '',
    specialty_id: '' as number | '',
    assignee_user_id: '' as number | '',
    due_on: '',
});

async function startEdit(): Promise<void> {
    const i = issue.value;
    if (!i) return;
    form.reset({
        kind: i.kind,
        severity: i.severity,
        title: i.title,
        description: i.description ?? '',
        place: toValue(i.location_id, i.element_id),
        specialty_id: i.specialty_id ?? '',
        assignee_user_id: i.assignee_user_id ?? '',
        due_on: i.due_on ?? '',
    });
    editing.value = true;
    [assignees.value, specialties.value] = await Promise.all([issuesApi.assignees(projectId.value), specialtiesApi.list()]);
}

const assigneeOptions = computed(() => [{ value: '', label: '(ninguém)' }, ...assignees.value.map((a) => ({ value: a.user_id, label: `${a.name} · ${a.role}` }))]);
const specialtyOptions = computed(() => [
    { value: '', label: '—' },
    ...specialties.value.filter((s) => !s.archived || s.id === issue.value?.specialty_id).map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` })),
]);

async function save(): Promise<void> {
    if (!issue.value) return;
    const saved = await form.submit(
        () =>
            issuesApi.update(projectId.value, issue.value!.id, {
                kind: form.data.kind,
                severity: form.data.severity,
                title: form.data.title,
                description: form.data.description || null,
                specialty_id: form.data.specialty_id === '' ? null : Number(form.data.specialty_id),
                assignee_user_id: form.data.assignee_user_id === '' ? null : Number(form.data.assignee_user_id),
                due_on: form.data.due_on || null,
                ...toIds(form.data.place),
            }),
        { success: 'Ocorrência guardada.' },
    );
    if (saved) {
        show(saved);
        editing.value = false;
    }
}

// ---- Comentários ----
const comment = ref('');
const commenting = ref(false);
async function sendComment(): Promise<void> {
    if (!issue.value || !comment.value.trim()) return;
    commenting.value = true;
    try {
        show(await issuesApi.comment(projectId.value, issue.value.id, comment.value.trim()));
        comment.value = '';
    } catch (e) {
        fail(e, 'Não foi possível enviar o comentário.');
    } finally {
        commenting.value = false;
    }
}

// ---- Fotografias ----
const MAX_MB = 20;
const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const queue = ref<{ key: number; name: string; progress: number; error: string | null }[]>([]);
let nextKey = 0;

async function onFiles(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = [...(input.files ?? [])];
    input.value = '';
    for (const file of files) {
        const key = ++nextKey;
        queue.value.push({ key, name: file.name, progress: 0, error: null });
        const job = () => queue.value.find((q) => q.key === key);
        if (!TYPES.includes(file.type) || file.size > MAX_MB * 1048576) {
            job()!.error = !TYPES.includes(file.type) ? 'Só JPEG, PNG ou WebP.' : `Tem ${formatBytes(file.size)}; o máximo é ${MAX_MB} MB.`;
            continue;
        }
        try {
            const photo = await issuesApi.uploadPhoto(projectId.value, issue.value!.id, file, (p) => {
                const live = job();
                if (live) live.progress = Math.max(p, 1);
            });
            issue.value!.photos = [...issue.value!.photos, photo];
            queue.value = queue.value.filter((q) => q.key !== key);
        } catch (e) {
            const live = job();
            if (live) live.error = e instanceof ApiError ? (e.firstError('photo') ?? e.message) : 'Falhou o envio.';
        }
    }
    schedulePoll();
}

async function removePhoto(photo: MediaItem): Promise<void> {
    if (!issue.value || !window.confirm('Retirar esta fotografia?')) return;
    try {
        await issuesApi.removePhoto(projectId.value, issue.value.id, photo.id);
        issue.value.photos = issue.value.photos.filter((p) => p.id !== photo.id);
    } catch (e) {
        fail(e, 'Não foi possível retirar.');
    }
}

const viewing = ref<MediaItem | null>(null);

// As miniaturas são geradas na fila do servidor (até cerca de um minuto).
let timer: number | undefined;
let polls = 0;
function schedulePoll(): void {
    window.clearTimeout(timer);
    const current = issue.value;
    if (!current || !current.photos.some((p) => p.status === 'processing') || polls > 36) return;
    timer = window.setTimeout(async () => {
        polls++;
        try {
            const fresh = await issuesApi.get(projectId.value, current.id);
            if (issue.value?.id === fresh.id) issue.value.photos = fresh.photos;
        } finally {
            schedulePoll();
        }
    }, 5000);
}
onBeforeUnmount(() => window.clearTimeout(timer));

watch(() => route.params.issueId, load, { immediate: true });

/** Detalhe de cada linha do historial (responsável, prazo, quem foi avisado). */
function eventDetail(e: IssueEventItem): string | null {
    const p = e.payload ?? {};
    if (e.type === 'assigned') return `Agora: ${(p.assignee as string | null) ?? 'ninguém'}`;
    if (e.type === 'due_changed') return `${p.from ? formatDate(p.from as string) : 'sem prazo'} → ${p.to ? formatDate(p.to as string) : 'sem prazo'}`;
    if (e.type === 'escalated') {
        const names = (p.notified as string[] | undefined) ?? [];
        return names.length ? `Avisados por email: ${names.join(', ')}` : 'Ninguém para avisar (sem responsável nem diretor de obra).';
    }
    if (e.type === 'created' && p.inspection) return `A partir da inspeção n.º ${p.inspection}`;
    return null;
}
</script>

<template>
    <div>
        <RouterLink :to="`/app/obras/${projectId}?separador=ocorrencias`" class="text-sm font-medium text-brand-700 hover:underline">
            ← {{ project?.name ?? 'Obra' }} · Ocorrências e NC
        </RouterLink>

        <AppAlert v-if="error" type="error" class="mt-3">{{ error }}</AppAlert>
        <div v-else-if="!issue" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>

        <template v-else>
            <div class="mt-2 mb-4 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">{{ issue.kind_label }} · {{ issue.reference }}</p>
                    <h1 class="text-2xl font-bold text-slate-900">{{ issue.title }}</h1>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <AppBadge v-if="issue.overdue" color="red">Prazo ultrapassado</AppBadge>
                    <AppBadge :color="severityColor(issue.severity)">{{ issue.severity_label }}</AppBadge>
                    <AppBadge :color="issueStatusColor(issue.status)">{{ issue.status_label }}</AppBadge>
                </div>
            </div>

            <dl class="mb-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs text-slate-500">Responsável</dt>
                    <dd>{{ issue.assignee ?? 'Ninguém atribuído' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Prazo</dt>
                    <dd :class="issue.overdue ? 'font-semibold text-red-700' : ''">
                        {{ issue.due_on ? formatDate(issue.due_on) : 'Sem prazo' }}<template v-if="issue.escalated_at"> · escalonada a {{ formatDate(issue.escalated_at) }}</template>
                    </dd>
                </div>
                <div v-if="labelOf(issue.location_id, issue.element_id)">
                    <dt class="text-xs text-slate-500">Onde</dt>
                    <dd>{{ labelOf(issue.location_id, issue.element_id) }}</dd>
                </div>
                <div v-if="issue.specialty">
                    <dt class="text-xs text-slate-500">Especialidade</dt>
                    <dd>{{ issue.specialty.code }} · {{ issue.specialty.name }}</dd>
                </div>
                <div v-if="issue.inspection">
                    <dt class="text-xs text-slate-500">Origem</dt>
                    <dd>
                        <RouterLink :to="`/app/obras/${projectId}/inspecoes/${issue.inspection.id}`" class="font-medium text-brand-700 hover:underline">
                            Inspeção n.º {{ issue.inspection.number }}</RouterLink
                        ><template v-if="issue.inspection_item_number">, ponto {{ issue.inspection_item_number }}</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Registada</dt>
                    <dd>{{ issue.created_by ?? '—' }}, {{ formatDateTime(issue.created_at) }}</dd>
                </div>
            </dl>

            <p v-if="issue.description" class="mb-4 text-sm whitespace-pre-line text-slate-700">{{ issue.description }}</p>

            <AppAlert v-if="issue.resolution_note" :type="issue.status === 'closed' ? 'success' : 'warning'" class="mb-4">
                <p class="font-semibold">Resolvida por {{ issue.resolved_by ?? '—' }} a {{ formatDateTime(issue.resolved_at) }}</p>
                <p class="mt-1 whitespace-pre-line">{{ issue.resolution_note }}</p>
                <p v-if="issue.closed_at && issue.status === 'closed'" class="mt-1 text-xs">Validada por {{ issue.closed_by }} a {{ formatDateTime(issue.closed_at) }}.</p>
            </AppAlert>
            <AppAlert v-if="issue.status === 'cancelled'" class="mb-4">Anulada por {{ issue.closed_by ?? '—' }} a {{ formatDateTime(issue.closed_at) }}.</AppAlert>
            <AppAlert v-if="project?.status === 'archived'" class="mb-4">A obra está arquivada: pode consultar, mas não alterar.</AppAlert>

            <div v-if="issue.can.actions.length || issue.can.edit" class="mb-5 flex flex-wrap gap-2">
                <AppButton
                    v-for="a in issue.can.actions"
                    :key="a.key"
                    :variant="['reopen', 'cancel'].includes(a.key) ? 'secondary' : 'primary'"
                    :loading="acting === a.key"
                    :disabled="acting !== null"
                    @click="choose(a)"
                >
                    {{ a.label }}
                </AppButton>
                <AppButton v-if="issue.can.edit" variant="ghost" @click="startEdit">Editar</AppButton>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <AppCard title="Fotografias">
                        <div v-if="issue.can.photos" class="mb-3 flex flex-wrap gap-2">
                            <label class="inline-flex cursor-pointer items-center rounded-lg bg-brand-700 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-800">
                                Tirar fotografia
                                <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="sr-only" @change="onFiles" />
                            </label>
                            <label class="inline-flex cursor-pointer items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                                Escolher
                                <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" @change="onFiles" />
                            </label>
                        </div>
                        <ul v-if="queue.length" class="mb-2 space-y-1 text-xs" aria-live="polite">
                            <li v-for="job in queue" :key="job.key" class="flex justify-between gap-2 rounded border px-2 py-1" :class="job.error ? 'border-red-200 bg-red-50 text-red-800' : 'border-slate-200'">
                                <span class="truncate">{{ job.name }}<template v-if="job.error"> — {{ job.error }}</template></span>
                                <span v-if="!job.error">{{ job.progress ? `${job.progress}%` : 'à espera' }}</span>
                                <button v-else type="button" class="underline" @click="queue = queue.filter((q) => q.key !== job.key)">Fechar</button>
                            </li>
                        </ul>
                        <p v-if="!issue.photos.length && !queue.length" class="text-sm text-slate-500">Sem fotografias.</p>
                        <ul v-else class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                            <li v-for="photo in issue.photos" :key="photo.id" class="relative">
                                <button type="button" class="block aspect-[4/3] w-full overflow-hidden rounded bg-slate-100" :aria-label="`Ver ${photo.original_name}`" @click="viewing = photo">
                                    <img v-if="photo.urls.thumb" :src="appUrl(photo.urls.thumb)" :alt="photo.original_name" loading="lazy" class="h-full w-full object-cover" />
                                    <span v-else class="flex h-full items-center justify-center px-1 text-center text-xs text-slate-500">{{ photo.status_label }}…</span>
                                </button>
                                <button
                                    v-if="photo.can_remove"
                                    type="button"
                                    class="absolute top-1 right-1 rounded bg-white/90 px-1.5 text-xs font-semibold text-red-700 shadow"
                                    :aria-label="`Retirar ${photo.original_name}`"
                                    @click="removePhoto(photo)"
                                >
                                    ✕
                                </button>
                            </li>
                        </ul>
                    </AppCard>
                </div>

                <AppCard title="Historial">
                    <ol class="space-y-3 text-sm">
                        <li v-for="e in issue.events" :key="e.id" class="border-l-2 pl-3" :class="e.type === 'escalated' ? 'border-red-300' : e.type === 'comment' ? 'border-brand-200' : 'border-slate-200'">
                            <p class="font-medium text-slate-900">{{ e.label }}</p>
                            <p class="text-xs text-slate-500">{{ e.user_name ?? 'ObraConnect (automático)' }} · {{ formatDateTime(e.created_at) }}</p>
                            <p v-if="eventDetail(e)" class="text-xs text-slate-600">{{ eventDetail(e) }}</p>
                            <p v-if="e.body" class="mt-0.5 text-sm whitespace-pre-line text-slate-700">{{ e.body }}</p>
                        </li>
                    </ol>
                    <form v-if="issue.can.comment" class="mt-4 space-y-2" @submit.prevent="sendComment">
                        <AppTextarea v-model="comment" aria-label="Comentário" :rows="2" :maxlength="5000" />
                        <AppButton type="submit" size="sm" :loading="commenting" :disabled="!comment.trim()">Comentar</AppButton>
                    </form>
                </AppCard>
            </div>
        </template>

        <AppModal :open="reasonFor !== null" :title="reasonFor?.label ?? ''" @close="reasonFor = null">
            <AppTextarea v-if="reasonFor" v-model="reason" :label="REASONS[reasonFor.key]?.field ?? 'Motivo'" :hint="REASONS[reasonFor.key]?.hint" :rows="4" :maxlength="5000" required />
            <template #footer>
                <AppButton variant="secondary" @click="reasonFor = null">Voltar</AppButton>
                <AppButton :loading="acting === reasonFor?.key" :disabled="!reason.trim()" @click="reasonFor && act(reasonFor.key, reason.trim())">{{ reasonFor?.label }}</AppButton>
            </template>
        </AppModal>

        <AppModal :open="editing" :title="`Editar ${issue?.reference ?? ''}`" size="lg" @close="editing = false">
            <form id="issue-edit-form" class="space-y-4" novalidate @submit.prevent="save">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppSelect v-model="form.data.kind" label="Tipo" :options="ISSUE_KINDS" :error="form.errors.value.kind" />
                    <AppSelect v-model="form.data.severity" label="Gravidade" :options="ISSUE_SEVERITIES" :error="form.errors.value.severity" />
                </div>
                <AppInput v-model="form.data.title" label="Título" required :maxlength="200" :error="form.errors.value.title" />
                <AppTextarea v-model="form.data.description" label="Descrição" :rows="3" :maxlength="10000" :error="form.errors.value.description" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-if="hasStructure">
                        <label class="mb-1 block text-sm font-medium text-slate-700" for="issue-edit-place">Onde</label>
                        <select id="issue-edit-place" v-model="form.data.place" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm">
                            <option value="">(toda a obra)</option>
                            <option v-for="o in placeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                    </div>
                    <AppSelect v-model="form.data.specialty_id" label="Especialidade" :options="specialtyOptions" :error="form.errors.value.specialty_id" />
                    <AppSelect v-model="form.data.assignee_user_id" label="Responsável" :options="assigneeOptions" :error="form.errors.value.assignee_user_id" />
                    <AppInput v-model="form.data.due_on" type="date" label="Prazo" hint="Mudar o prazo permite voltar a escalonar quando passar." :error="form.errors.value.due_on" />
                </div>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="editing = false">Cancelar</AppButton>
                <AppButton type="submit" form="issue-edit-form" :loading="form.processing.value">Guardar</AppButton>
            </template>
        </AppModal>

        <AppModal :open="viewing !== null" :title="viewing?.original_name ?? ''" size="lg" @close="viewing = null">
            <template v-if="viewing">
                <img v-if="viewing.urls.preview" :src="appUrl(viewing.urls.preview)" :alt="viewing.original_name" class="mx-auto max-h-[70vh] rounded" />
                <p v-else class="text-sm text-slate-600">Ainda não há pré-visualização. Pode descarregar o original.</p>
                <p class="mt-3 text-xs break-all text-slate-500">
                    {{ formatBytes(viewing.size_bytes) }}<template v-if="viewing.uploaded_by"> · enviada por {{ viewing.uploaded_by }}</template> · SHA-256 {{ viewing.sha256 }}
                </p>
            </template>
            <template #footer>
                <a v-if="viewing" :href="appUrl(viewing.urls.original)" class="text-sm font-semibold text-brand-700 hover:underline">Descarregar original</a>
                <AppButton variant="secondary" @click="viewing = null">Fechar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
