<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { inspectionsApi, issuesApi, projectsApi, specialtiesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { ChecklistItemDef, EvidenceIndex, InspectionDetail, InspectionItem, Project, Specialty } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useStructurePlaces } from '@/composables/useStructurePlaces';
import { useToastStore } from '@/stores/toast';
import { formatDateTime } from '@/utils/format';
import { inspectionStatusColor } from '@/utils/inspections';
import ChecklistItemsEditor from '@/components/inspections/ChecklistItemsEditor.vue';
import InspectionItemCard from '@/components/inspections/InspectionItemCard.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';

const route = useRoute();
const router = useRouter();
const toast = useToastStore();

const projectId = computed(() => Number(route.params.id));
const project = ref<Project | null>(null);
const inspection = ref<InspectionDetail | null>(null);
const specialties = ref<Specialty[]>([]);
const error = ref<string | null>(null);
const { placeOptions, hasStructure, toValue, toIds, labelOf, load: loadPlaces } = useStructurePlaces(projectId);

const backTo = computed(() => `/app/obras/${projectId.value}?separador=inspecoes`);

async function load(): Promise<void> {
    error.value = null;
    try {
        const [p, i] = await Promise.all([projectsApi.get(projectId.value), inspectionsApi.get(projectId.value, Number(route.params.inspectionId))]);
        project.value = p;
        show(i);
        loadPlaces();
    } catch (e) {
        error.value = e instanceof ApiError && e.status === 403 ? 'Não tem acesso às inspeções desta obra.' : e instanceof ApiError ? e.message : 'Não foi possível abrir a inspeção.';
    }
}

function show(detail: InspectionDetail): void {
    inspection.value = detail;
    document.title = `Inspeção n.º ${detail.number} · ObraConnect`;
    if (detail.can.edit) {
        draft.reset({
            title: detail.title,
            description: detail.description ?? '',
            place: toValue(detail.location_id, detail.element_id),
            specialty_id: detail.specialty_id ?? '',
            scheduled_for: detail.scheduled_for ? detail.scheduled_for.slice(0, 16) : '',
            remote: detail.remote,
            hold_point: detail.hold_point,
            items: detail.items.map(({ text, guidance, requires_photo, min_photos, requires_measure, measure_unit, measure_min, measure_max }) => ({
                text,
                guidance,
                requires_photo,
                min_photos: min_photos || 1,
                requires_measure,
                measure_unit,
                measure_min,
                measure_max,
            })),
        });
        savedDraft.value = JSON.stringify(draft.data);
        if (!specialties.value.length) specialtiesApi.list().then((s) => (specialties.value = s));
    }
    schedulePoll();
}

// ---- Preparação (rascunho) ----
const draft = useForm({
    title: '',
    description: '',
    place: '',
    specialty_id: '' as number | '',
    scheduled_for: '',
    remote: false,
    hold_point: false,
    items: [] as ChecklistItemDef[],
});
const savedDraft = ref('');
const dirty = computed(() => inspection.value?.can.edit === true && JSON.stringify(draft.data) !== savedDraft.value);
const specialtyOptions = computed(() => [
    { value: '', label: '—' },
    ...specialties.value.filter((s) => !s.archived || s.id === inspection.value?.specialty_id).map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` })),
]);

async function saveDraft(quiet = false): Promise<boolean> {
    if (!inspection.value) return false;
    const saved = await draft.submit(
        () =>
            inspectionsApi.update(projectId.value, inspection.value!.id, {
                title: draft.data.title,
                description: draft.data.description || null,
                specialty_id: draft.data.specialty_id === '' ? null : Number(draft.data.specialty_id),
                scheduled_for: draft.data.scheduled_for || null,
                remote: draft.data.remote,
                hold_point: draft.data.hold_point,
                items: draft.data.items.filter((i) => i.text.trim() !== ''),
                ...toIds(draft.data.place),
            }),
        { success: quiet ? undefined : 'Preparação guardada.' },
    );
    if (saved) show(saved);
    return Boolean(saved);
}

async function removeDraft(): Promise<void> {
    if (!inspection.value || !window.confirm(`Apagar o rascunho da inspeção n.º ${inspection.value.number}?`)) return;
    try {
        await inspectionsApi.remove(projectId.value, inspection.value.id);
        toast.success('Rascunho apagado.');
        router.push(backTo.value);
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível apagar.');
    }
}

// ---- Passagens de estado ----
const acting = ref<string | null>(null);
const reasonFor = ref<{ key: string; label: string } | null>(null);
const reason = ref('');
const scheduling = ref(false);
const when = ref('');

const REASON_LABELS: Record<string, { field: string; hint: string }> = {
    return: { field: 'O que falta completar', hint: 'Quem inspeciona vê este texto e a inspeção volta a “em curso”.' },
    approve_with_reservations: { field: 'Reservas', hint: 'O que fica por corrigir ou acompanhar.' },
    reject: { field: 'Motivo da rejeição', hint: 'Depois pode pedir-se uma reinspeção com os mesmos pontos.' },
    cancel: { field: 'Motivo do cancelamento', hint: 'Fica no histórico da inspeção.' },
};

async function act(key: string, payload: { reason?: string | null; scheduled_for?: string } = {}): Promise<void> {
    if (!inspection.value) return;
    if (key === 'request' && dirty.value && !(await saveDraft(true))) return;
    if (key === 'submit' && !window.confirm('Submeter para apreciação? Depois disto os pontos e as fotografias ficam fechados.')) return;
    if (key === 'approve' && !window.confirm(`Aprovar a inspeção n.º ${inspection.value.number}? A decisão fica registada e não se altera.`)) return;
    acting.value = key;
    try {
        show(await inspectionsApi.act(projectId.value, inspection.value.id, key, payload));
        reasonFor.value = null;
        scheduling.value = false;
        toast.success(`${inspection.value.status_label}.`);
    } catch (e) {
        if (e instanceof ApiError && e.code === 'EVIDENCE_MISSING') {
            const criteria = (e.meta.criteria ?? null) as EvidenceIndex['criteria'] | null;
            if (criteria && inspection.value) inspection.value.evidence = { ...inspection.value.evidence, criteria, can_submit: false };
        }
        toast.error(e instanceof ApiError ? (e.firstError('reason') ?? e.firstError('scheduled_for') ?? e.message) : 'Não foi possível concluir.');
    } finally {
        acting.value = null;
    }
}

function choose(action: { key: string; label: string; reason: boolean }): void {
    if (action.key === 'schedule') {
        const tomorrow = new Date(Date.now() + 86400000);
        when.value = inspection.value?.scheduled_for?.slice(0, 16) ?? `${tomorrow.toISOString().slice(0, 10)}T09:00`;
        scheduling.value = true;
    } else if (action.reason) {
        reason.value = '';
        reasonFor.value = action;
    } else {
        act(action.key);
    }
}

async function reinspect(): Promise<void> {
    if (!inspection.value) return;
    try {
        const copy = await inspectionsApi.reinspect(projectId.value, inspection.value.id);
        toast.success(`Criado o rascunho da reinspeção n.º ${copy.number}.`);
        router.push(`/app/obras/${projectId.value}/inspecoes/${copy.id}`);
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível criar a reinspeção.');
    }
}

// ---- Ponto de paragem ----
const releasing = ref(false);
const releaseNote = ref('');
const releaseBusy = ref(false);
async function releaseHold(): Promise<void> {
    if (!inspection.value) return;
    releaseBusy.value = true;
    try {
        show(await inspectionsApi.releaseHold(projectId.value, inspection.value.id, releaseNote.value.trim() || null));
        releasing.value = false;
        toast.success('Ponto de paragem libertado: os trabalhos podem avançar.');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível libertar.');
    } finally {
        releaseBusy.value = false;
    }
}

// ---- Não conformidade a partir de um ponto ----
async function raiseIssue(item: InspectionItem): Promise<void> {
    try {
        const issue = await issuesApi.create(projectId.value, { inspection_item_id: item.id });
        toast.success(`Aberta a ${issue.reference}. Escolha o responsável e o prazo.`);
        router.push(`/app/obras/${projectId.value}/ocorrencias/${issue.id}`);
    } catch (e) {
        toast.error(e instanceof ApiError ? (e.firstError('inspection_item_id') ?? e.message) : 'Não foi possível abrir a não conformidade.');
    }
}

// ---- Pontos e evidências ----
function onItem(item: InspectionItem): void {
    if (!inspection.value) return;
    inspection.value.items = inspection.value.items.map((i) => (i.id === item.id ? item : i));
    schedulePoll();
}

function onEvidence(evidence: EvidenceIndex): void {
    if (inspection.value) inspection.value.evidence = evidence;
}

const SHORT: Record<string, string> = {
    photos: 'fotografias exigidas',
    measures: 'valor medido',
    nok_notes: 'descrição da não conformidade',
    nok_photos: 'fotografia da não conformidade (recomendado)',
    tolerance: 'valor fora dos limites marcado como conforme',
    remote_photos: 'fotografia (inspeção remota, recomendado)',
};
/** O que falta em cada ponto (por número), enquanto a inspeção está em curso. */
const missingByItem = computed(() => {
    const out = new Map<number, string[]>();
    if (!inspection.value?.can.fill) return out;
    for (const c of inspection.value.evidence.criteria) {
        if (!SHORT[c.key]) continue;
        for (const n of c.missing) out.set(n, [...(out.get(n) ?? []), SHORT[c.key]!]);
    }
    return out;
});

// As miniaturas das fotografias são geradas na fila do servidor (até cerca de um minuto).
let timer: number | undefined;
let polls = 0;
function schedulePoll(): void {
    window.clearTimeout(timer);
    const current = inspection.value;
    if (!current || !current.items.some((i) => i.photos.some((p) => p.status === 'processing')) || polls > 36) return;
    timer = window.setTimeout(async () => {
        polls++;
        try {
            const fresh = await inspectionsApi.get(projectId.value, current.id);
            if (inspection.value?.id !== fresh.id) return;
            // Só as fotografias: o resto pode estar a ser escrito.
            const photos = new Map(fresh.items.map((i) => [i.id, i.photos]));
            inspection.value.items = inspection.value.items.map((i) => ({ ...i, photos: photos.get(i.id) ?? i.photos }));
        } finally {
            schedulePoll();
        }
    }, 5000);
}
onBeforeUnmount(() => window.clearTimeout(timer));

// Depois de tudo declarado (o carregamento usa o formulário da preparação).
watch(() => route.params.inspectionId, load, { immediate: true });

const placeLabel = computed(() => (inspection.value ? labelOf(inspection.value.location_id, inspection.value.element_id) : ''));
const closedNote = computed(() => {
    const i = inspection.value;
    if (!i?.decided_at) return null;
    return `${i.status_label} por ${i.decided_by ?? '—'} a ${formatDateTime(i.decided_at)}`;
});
</script>

<template>
    <div>
        <RouterLink :to="backTo" class="text-sm font-medium text-brand-700 hover:underline">← {{ project?.name ?? 'Obra' }} · Inspeções</RouterLink>

        <AppAlert v-if="error" type="error" class="mt-3">{{ error }}</AppAlert>
        <div v-else-if="!inspection" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>

        <template v-else>
            <div class="mt-2 mb-4 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-medium tracking-wide text-slate-500">INSPEÇÃO N.º {{ inspection.number }}</p>
                    <h1 class="text-2xl font-bold text-slate-900">{{ inspection.title }}</h1>
                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600">
                        <span v-if="inspection.specialty" class="font-mono font-semibold">{{ inspection.specialty.code }}</span>
                        <span v-if="placeLabel">{{ placeLabel }}</span>
                        <span v-if="inspection.template">· lista “{{ inspection.template.name }}”, versão {{ inspection.template.version }}</span>
                        <span v-if="inspection.remote">· remota</span>
                    </p>
                    <p v-if="inspection.reinspection_of" class="mt-1 text-sm">
                        Reinspeção da
                        <RouterLink :to="`/app/obras/${projectId}/inspecoes/${inspection.reinspection_of.id}`" class="font-medium text-brand-700 hover:underline">
                            n.º {{ inspection.reinspection_of.number }}
                        </RouterLink>
                    </p>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <AppBadge v-if="inspection.holding_work" color="red">Ponto de paragem: trabalhos parados</AppBadge>
                    <AppBadge v-else-if="inspection.hold_point && inspection.hold_released_at" color="green">Ponto de paragem libertado</AppBadge>
                    <AppBadge :color="inspectionStatusColor(inspection.status)">{{ inspection.status_label }}</AppBadge>
                </div>
            </div>

            <dl class="mb-4 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div v-if="inspection.scheduled_for">
                    <dt class="text-xs text-slate-500">{{ ['draft', 'requested'].includes(inspection.status) ? 'Data pretendida' : 'Marcada para' }}</dt>
                    <dd>{{ formatDateTime(inspection.scheduled_for) }}</dd>
                </div>
                <div v-if="inspection.requested_by">
                    <dt class="text-xs text-slate-500">Pedida por</dt>
                    <dd>{{ inspection.requested_by }}<template v-if="inspection.requested_at">, {{ formatDateTime(inspection.requested_at) }}</template></dd>
                </div>
                <div v-if="inspection.inspector">
                    <dt class="text-xs text-slate-500">Inspeção feita por</dt>
                    <dd>{{ inspection.inspector }}</dd>
                </div>
                <div v-if="inspection.hold_released_at">
                    <dt class="text-xs text-slate-500">Ponto de paragem libertado</dt>
                    <dd>{{ inspection.hold_released_by ?? '—' }}, {{ formatDateTime(inspection.hold_released_at) }}</dd>
                </div>
                <div v-if="inspection.evidence_score !== null">
                    <dt class="text-xs text-slate-500">Índice de evidências (submissão)</dt>
                    <dd>{{ inspection.evidence_score }}%</dd>
                </div>
            </dl>

            <AppAlert v-if="closedNote" :type="inspection.status === 'approved' ? 'success' : inspection.status === 'rejected' ? 'error' : 'info'" class="mb-4">
                <p class="font-semibold">{{ closedNote }}</p>
                <p v-if="inspection.decision_note" class="mt-1 whitespace-pre-line">{{ inspection.decision_note }}</p>
            </AppAlert>
            <AppAlert v-if="project?.status === 'archived'" class="mb-4">A obra está arquivada: pode consultar, mas não alterar.</AppAlert>

            <!-- Ações -->
            <div
                v-if="inspection.can.actions.length || inspection.can.reinspect || inspection.can.edit || inspection.can.release_hold || inspection.reinspections.length"
                class="mb-5 flex flex-wrap items-center gap-2"
            >
                <AppButton v-if="inspection.can.release_hold" @click="(releaseNote = ''), (releasing = true)">Libertar ponto de paragem</AppButton>
                <AppButton
                    v-for="a in inspection.can.actions"
                    :key="a.key"
                    :variant="['cancel', 'return'].includes(a.key) ? 'secondary' : a.key === 'reject' ? 'danger' : a.key === 'approve' && inspection.can.has_nok ? 'secondary' : 'primary'"
                    :loading="acting === a.key"
                    :disabled="acting !== null || (a.key === 'approve' && inspection.can.has_nok) || (a.key === 'submit' && !inspection.evidence.can_submit)"
                    :title="a.key === 'approve' && inspection.can.has_nok ? 'Há pontos não conformes: aprove com reservas ou rejeite.' : undefined"
                    @click="choose(a)"
                >
                    {{ a.label }}
                </AppButton>
                <AppButton v-if="inspection.can.reinspect" variant="secondary" @click="reinspect">Pedir reinspeção</AppButton>
                <AppButton v-if="inspection.can.edit" variant="ghost" class="text-red-700" @click="removeDraft">Apagar rascunho</AppButton>
                <span v-for="r in inspection.reinspections" :key="r.id" class="text-sm text-slate-600">
                    Reinspeção:
                    <RouterLink :to="`/app/obras/${projectId}/inspecoes/${r.id}`" class="font-medium text-brand-700 hover:underline">n.º {{ r.number }}</RouterLink>
                    ({{ r.status_label }})
                </span>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <!-- Rascunho: preparar -->
                    <AppCard v-if="inspection.can.edit" title="Preparação" description="Enquanto é rascunho, pode mudar tudo. Ao pedir a inspeção, os pontos ficam fixos.">
                        <form class="space-y-4" novalidate @submit.prevent="saveDraft()">
                            <AppAlert v-if="draft.message.value" type="error">{{ draft.message.value }}</AppAlert>
                            <AppInput v-model="draft.data.title" label="Título" required :maxlength="200" :error="draft.errors.value.title" />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div v-if="hasStructure">
                                    <label class="mb-1 block text-sm font-medium text-slate-700" for="draft-place">Onde</label>
                                    <select id="draft-place" v-model="draft.data.place" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm">
                                        <option value="">(toda a obra)</option>
                                        <option v-for="o in placeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                                    </select>
                                </div>
                                <AppSelect v-model="draft.data.specialty_id" label="Especialidade" :options="specialtyOptions" :error="draft.errors.value.specialty_id" />
                                <AppInput v-model="draft.data.scheduled_for" type="datetime-local" label="Data pretendida" :error="draft.errors.value.scheduled_for" />
                                <div class="space-y-3 sm:pt-7">
                                    <AppCheckbox v-model="draft.data.remote" label="Inspeção remota" description="Feita por fotografias." />
                                    <AppCheckbox v-model="draft.data.hold_point" label="Ponto de paragem" description="Os trabalhos param até a inspeção ser aprovada e libertada." />
                                </div>
                            </div>
                            <AppTextarea v-model="draft.data.description" label="Descrição" :rows="2" :maxlength="5000" />
                            <div>
                                <h3 class="mb-2 text-sm font-semibold text-slate-800">Pontos a verificar</h3>
                                <ChecklistItemsEditor v-model="draft.data.items" :errors="draft.errors.value" />
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <AppButton type="submit" :loading="draft.processing.value" :disabled="!dirty">Guardar</AppButton>
                                <span v-if="dirty" class="text-sm text-amber-700">Alterações por guardar (guardam-se também ao pedir a inspeção).</span>
                            </div>
                        </form>
                    </AppCard>

                    <!-- Pontos -->
                    <div v-else>
                        <p v-if="inspection.description" class="mb-3 text-sm whitespace-pre-line text-slate-700">{{ inspection.description }}</p>
                        <AppAlert v-if="['requested', 'scheduled'].includes(inspection.status)" class="mb-3">
                            Os pontos verificam-se depois de começar a inspeção.
                        </AppAlert>
                        <ol class="space-y-3">
                            <InspectionItemCard
                                v-for="item in inspection.items"
                                :key="item.id"
                                :project-id="projectId"
                                :inspection-id="inspection.id"
                                :item="item"
                                :editable="inspection.can.fill"
                                :missing="missingByItem.get(item.number)"
                                :can-raise-issue="inspection.can.raise_issue && inspection.status !== 'draft'"
                                @changed="onItem"
                                @raise="raiseIssue"
                                @evidence="onEvidence"
                            />
                        </ol>
                    </div>
                </div>

                <div class="space-y-6">
                    <AppCard v-if="!inspection.can.edit" title="Evidências" description="Os obrigatórios impedem a submissão; os recomendados só baixam o índice. Quem decide é sempre uma pessoa.">
                        <p class="text-3xl font-bold text-slate-900">{{ inspection.evidence.score ?? '—' }}<span v-if="inspection.evidence.score !== null" class="text-lg">%</span></p>
                        <ul class="mt-3 space-y-2 text-sm">
                            <li v-for="c in inspection.evidence.criteria" :key="c.key">
                                <div class="flex items-center justify-between gap-2">
                                    <span :class="c.met === c.total ? 'text-slate-700' : c.required ? 'font-medium text-red-700' : 'text-amber-700'">
                                        {{ c.met === c.total ? '✓' : '•' }} {{ c.label }}
                                    </span>
                                    <span class="text-xs text-slate-500">{{ c.met }}/{{ c.total }}</span>
                                </div>
                                <p v-if="c.missing.length" class="ml-4 text-xs text-slate-500">
                                    {{ c.required ? 'Obrigatório' : 'Recomendado' }} · {{ c.missing.length === 1 ? 'ponto' : 'pontos' }} {{ c.missing.join(', ') }}
                                </p>
                            </li>
                        </ul>
                    </AppCard>

                    <AppCard title="Histórico" description="Cada passo fica selado com o hash do anterior: não se altera nem apaga.">
                        <ol class="space-y-3 text-sm">
                            <li v-for="e in inspection.events" :key="e.sequence" class="border-l-2 border-slate-200 pl-3">
                                <p class="font-medium text-slate-900">{{ e.label }}</p>
                                <p class="text-xs text-slate-500">{{ e.user_name }} · {{ formatDateTime(e.created_at) }}</p>
                                <p v-if="e.scheduled_for" class="text-xs text-slate-600">Para {{ formatDateTime(e.scheduled_for.replace(' ', 'T')) }}</p>
                                <p v-if="e.evidence_score !== null" class="text-xs text-slate-600">Índice de evidências: {{ e.evidence_score }}%</p>
                                <p v-if="e.reason" class="mt-0.5 text-xs whitespace-pre-line text-slate-700">“{{ e.reason }}”</p>
                                <p class="font-mono text-[10px] text-slate-400" :title="e.hash">{{ e.hash.slice(0, 16) }}…</p>
                            </li>
                        </ol>
                        <p v-if="inspection.chain.valid" class="mt-3 text-xs font-medium text-green-700">✓ Cadeia íntegra ({{ inspection.events.length === 1 ? '1 passo' : `${inspection.events.length} passos` }})</p>
                        <p v-else class="mt-3 text-sm font-semibold text-red-700">
                            A cadeia não bate a partir do passo {{ inspection.chain.broken_at }}: o histórico foi alterado fora da aplicação.
                        </p>
                    </AppCard>
                </div>
            </div>
        </template>

        <AppModal :open="releasing" title="Libertar ponto de paragem" @close="releasing = false">
            <p class="mb-3 text-sm text-slate-600">Os trabalhos que dependiam desta inspeção podem avançar. Fica registado no histórico, com o seu nome.</p>
            <AppTextarea v-model="releaseNote" label="Observação" hint="Opcional (ex.: pode betonar a partir das 14h)." :rows="3" :maxlength="2000" />
            <template #footer>
                <AppButton variant="secondary" @click="releasing = false">Voltar</AppButton>
                <AppButton :loading="releaseBusy" @click="releaseHold">Libertar</AppButton>
            </template>
        </AppModal>

        <AppModal :open="scheduling" title="Agendar inspeção" @close="scheduling = false">
            <AppInput v-model="when" type="datetime-local" label="Data e hora" required />
            <template #footer>
                <AppButton variant="secondary" @click="scheduling = false">Cancelar</AppButton>
                <AppButton :loading="acting === 'schedule'" :disabled="!when" @click="act('schedule', { scheduled_for: when })">Agendar</AppButton>
            </template>
        </AppModal>

        <AppModal :open="reasonFor !== null" :title="reasonFor?.label ?? ''" @close="reasonFor = null">
            <AppTextarea v-if="reasonFor" v-model="reason" :label="REASON_LABELS[reasonFor.key]?.field ?? 'Justificação'" :hint="REASON_LABELS[reasonFor.key]?.hint" :rows="4" :maxlength="2000" required />
            <template #footer>
                <AppButton variant="secondary" @click="reasonFor = null">Voltar</AppButton>
                <AppButton
                    :variant="reasonFor?.key === 'reject' ? 'danger' : 'primary'"
                    :loading="acting === reasonFor?.key"
                    :disabled="!reason.trim()"
                    @click="reasonFor && act(reasonFor.key, { reason })"
                >
                    {{ reasonFor?.label }}
                </AppButton>
            </template>
        </AppModal>
    </div>
</template>
