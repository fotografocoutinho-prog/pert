<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { checklistsApi, specialtiesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { ChecklistItemDef, ChecklistTemplate, Specialty } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { emptyChecklistItem, requirementsText } from '@/utils/inspections';
import { formatDate } from '@/utils/format';
import ChecklistItemsEditor from '@/components/inspections/ChecklistItemsEditor.vue';
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
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();
const canManage = computed(() => session.can('checklists.manage') && !session.isReadOnly);

const templates = ref<ChecklistTemplate[]>([]);
const specialties = ref<Specialty[]>([]);
const loading = ref(true);
const showArchived = ref(false);

onMounted(async () => {
    [templates.value, specialties.value] = await Promise.all([checklistsApi.list(), specialtiesApi.list()]);
    loading.value = false;
});

const visible = computed(() => templates.value.filter((t) => showArchived.value || !t.archived));
const archivedCount = computed(() => templates.value.filter((t) => t.archived).length);

function replace(saved: ChecklistTemplate): void {
    templates.value = [...templates.value.filter((t) => t.id !== saved.id), saved].sort((a, b) => a.name.localeCompare(b.name, 'pt'));
}

// ---- Editor ----
const open = ref(false);
const editing = ref<ChecklistTemplate | null>(null);
const loadingTemplate = ref(false);
const form = useForm({ name: '', description: '', specialty_id: '' as number | '', items: [] as ChecklistItemDef[] });

async function openEditor(template: ChecklistTemplate | null): Promise<void> {
    editing.value = template;
    form.reset({ name: '', description: '', specialty_id: '', items: [emptyChecklistItem()] });
    open.value = true;
    if (!template) return;
    loadingTemplate.value = true;
    try {
        const full = await checklistsApi.get(template.id);
        form.reset({
            name: full.name,
            description: full.description ?? '',
            specialty_id: full.specialty_id ?? '',
            items: (full.items ?? []).map((item) => ({ ...item, min_photos: item.min_photos || 1 })),
        });
    } finally {
        loadingTemplate.value = false;
    }
}

const specialtyOptions = computed(() => [
    { value: '', label: '—' },
    ...specialties.value
        .filter((s) => !s.archived || s.id === form.data.specialty_id)
        .map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` })),
]);

async function save(): Promise<void> {
    const payload = {
        name: form.data.name,
        description: form.data.description || null,
        specialty_id: form.data.specialty_id === '' ? null : Number(form.data.specialty_id),
        items: form.data.items.filter((i) => i.text.trim() !== ''),
    };
    const id = editing.value?.id;
    const before = editing.value?.version;
    const saved = await form.submit(() => (id ? checklistsApi.update(id, payload) : checklistsApi.create(payload)));
    if (!saved) return;
    replace(saved);
    open.value = false;
    toast.success(!id ? 'Lista de verificação criada.' : saved.version !== before ? `Guardada como versão ${saved.version}.` : 'Lista de verificação guardada.');
}

async function setArchived(template: ChecklistTemplate, archived: boolean): Promise<void> {
    try {
        replace(await checklistsApi.update(template.id, { archived }));
        toast.success(archived ? 'Arquivada: deixa de aparecer ao criar inspeções.' : 'Reativada.');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível guardar.');
    }
}

async function remove(template: ChecklistTemplate): Promise<void> {
    if (!window.confirm(`Apagar a lista “${template.name}”?`)) return;
    try {
        await checklistsApi.remove(template.id);
        templates.value = templates.value.filter((t) => t.id !== template.id);
        toast.success('Lista de verificação apagada.');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível apagar.');
    }
}

// ---- Ver ----
const viewing = ref<ChecklistTemplate | null>(null);
async function view(template: ChecklistTemplate): Promise<void> {
    viewing.value = template;
    viewing.value = await checklistsApi.get(template.id);
}
</script>

<template>
    <div>
        <PageHeader title="Listas de verificação" description="Modelos com os pontos a verificar em cada tipo de inspeção. Cada inspeção guarda uma cópia: mudar o modelo não altera as que já existem.">
            <AppButton v-if="canManage" @click="openEditor(null)">Nova lista</AppButton>
        </PageHeader>

        <div v-if="loading" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <AppCard v-else>
            <EmptyState
                v-if="visible.length === 0"
                title="Sem listas de verificação"
                :description="canManage ? 'Ex.: Betonagem de lajes, Impermeabilização de coberturas, Instalação elétrica antes de fechar paredes.' : undefined"
            />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="t in visible" :key="t.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <button type="button" class="min-w-0 text-left" @click="view(t)">
                        <p class="font-medium hover:underline" :class="t.archived ? 'text-slate-400' : 'text-slate-900'">{{ t.name }}</p>
                        <p class="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                            <AppBadge v-if="t.archived">Arquivada</AppBadge>
                            <span v-if="t.specialty" class="font-mono font-semibold">{{ t.specialty.code }}</span>
                            <span>{{ t.items_count === 1 ? '1 ponto' : `${t.items_count} pontos` }}</span>
                            <span>· versão {{ t.version }}</span>
                            <span v-if="t.inspections_count">· usada em {{ t.inspections_count === 1 ? '1 inspeção' : `${t.inspections_count} inspeções` }}</span>
                            <span v-if="t.updated_at">· alterada a {{ formatDate(t.updated_at) }}</span>
                        </p>
                    </button>
                    <div v-if="canManage" class="flex flex-wrap gap-1">
                        <AppButton size="sm" variant="ghost" @click="openEditor(t)">Editar</AppButton>
                        <AppButton size="sm" variant="ghost" @click="setArchived(t, !t.archived)">{{ t.archived ? 'Reativar' : 'Arquivar' }}</AppButton>
                        <AppButton v-if="!t.inspections_count" size="sm" variant="ghost" class="text-red-700" @click="remove(t)">Apagar</AppButton>
                    </div>
                </li>
            </ul>
            <div v-if="archivedCount" class="mt-3 border-t border-slate-100 pt-3">
                <AppCheckbox v-model="showArchived" :label="`Mostrar arquivadas (${archivedCount})`" />
            </div>
        </AppCard>

        <AppModal :open="open" :title="editing ? 'Editar lista de verificação' : 'Nova lista de verificação'" size="lg" @close="open = false">
            <div v-if="loadingTemplate" class="flex justify-center py-8"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
            <form v-else id="checklist-form" class="space-y-4" novalidate @submit.prevent="save">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <AppAlert v-if="editing && editing.inspections_count">
                    Usada em {{ editing.inspections_count === 1 ? '1 inspeção' : `${editing.inspections_count} inspeções` }}. Se mudar os pontos, fica a versão {{ editing.version + 1 }};
                    as inspeções já criadas mantêm os pontos que tinham.
                </AppAlert>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.data.name" label="Nome" required :maxlength="150" placeholder="Ex.: Betonagem de lajes" :error="form.errors.value.name" />
                    <AppSelect v-model="form.data.specialty_id" label="Especialidade" :options="specialtyOptions" :error="form.errors.value.specialty_id" />
                </div>
                <AppTextarea v-model="form.data.description" label="Descrição" :rows="2" :maxlength="2000" hint="Quando se usa, o que abrange (opcional)." />
                <div>
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Pontos a verificar</h3>
                    <ChecklistItemsEditor v-model="form.data.items" :errors="form.errors.value" />
                </div>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="open = false">Cancelar</AppButton>
                <AppButton type="submit" form="checklist-form" :loading="form.processing.value" :disabled="loadingTemplate">Guardar</AppButton>
            </template>
        </AppModal>

        <AppModal :open="viewing !== null" :title="viewing?.name ?? ''" size="lg" @close="viewing = null">
            <template v-if="viewing">
                <p v-if="viewing.description" class="mb-3 text-sm whitespace-pre-line text-slate-700">{{ viewing.description }}</p>
                <div v-if="!viewing.items" class="flex justify-center py-8"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
                <ol v-else class="space-y-2">
                    <li v-for="(item, i) in viewing.items" :key="item.id" class="flex gap-2 text-sm">
                        <span class="w-6 shrink-0 text-right font-semibold text-slate-500">{{ i + 1 }}.</span>
                        <div>
                            <p class="text-slate-900">{{ item.text }}</p>
                            <p v-if="item.guidance" class="text-xs text-slate-500">{{ item.guidance }}</p>
                            <p v-if="requirementsText(item).length" class="mt-0.5 flex flex-wrap gap-1">
                                <AppBadge v-for="r in requirementsText(item)" :key="r" color="blue">{{ r }}</AppBadge>
                            </p>
                        </div>
                    </li>
                </ol>
            </template>
            <template #footer>
                <AppButton variant="secondary" @click="viewing = null">Fechar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
