<script setup lang="ts">
import { computed, watch } from 'vue';
import { projectsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Project, ProjectStatus } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatMoney } from '@/utils/format';
import { formToPayload, isClosedStatus, projectToForm } from '@/utils/projects';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import ProjectFormFields from '@/components/projects/ProjectFormFields.vue';

const props = defineProps<{ project: Project }>();
const emit = defineEmits<{ updated: [project: Project] }>();

const session = useSessionStore();
const toast = useToastStore();

const can = (permission: string) => props.project.permissions.includes(permission) && !session.isReadOnly;
const canEdit = computed(() => can('projects.update') && props.project.status !== 'archived');

const form = useForm(projectToForm(props.project));
watch(() => props.project, (p) => form.reset(projectToForm(p)));

async function save(): Promise<void> {
    const payload = formToPayload(form.data);
    if (!payload) {
        form.errors.value = { contract_value: 'Valor inválido. Use, por exemplo, 125000,00.' };
        return;
    }
    const updated = await form.submit(() => projectsApi.update(props.project.id, payload), { success: 'Dados da obra guardados.' });
    if (updated) emit('updated', updated);
}

/** Mudanças de estado possíveis a partir do estado atual, com a permissão que cada uma exige. */
const transitions = computed(() => {
    const from = props.project.status;
    const options: { to: ProjectStatus; label: string; variant: 'primary' | 'secondary' }[] = [];
    if (from === 'planning') options.push({ to: 'active', label: 'Iniciar obra', variant: 'primary' });
    if (from === 'active') options.push({ to: 'suspended', label: 'Suspender', variant: 'secondary' });
    if (from === 'suspended') options.push({ to: 'active', label: 'Retomar', variant: 'primary' });
    if (!isClosedStatus(from)) options.push({ to: 'completed', label: 'Concluir obra', variant: 'secondary' });
    if (from === 'completed') options.push({ to: 'archived', label: 'Arquivar', variant: 'secondary' });
    if (isClosedStatus(from)) options.push({ to: 'active', label: 'Reabrir', variant: 'primary' });

    return options.filter((o) => can(isClosedStatus(o.to) || isClosedStatus(from) ? 'projects.archive' : 'projects.update'));
});

async function changeStatus(to: ProjectStatus, label: string): Promise<void> {
    if (!window.confirm(`${label}: confirma?`)) return;
    try {
        emit('updated', await projectsApi.update(props.project.id, { status: to }));
        toast.success('Estado da obra atualizado.');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível mudar o estado.');
    }
}
</script>

<template>
    <div class="space-y-4">
        <AppCard v-if="transitions.length" title="Estado da obra">
            <div class="flex flex-wrap gap-2">
                <AppButton v-for="t in transitions" :key="t.to + t.label" :variant="t.variant" @click="changeStatus(t.to, t.label)">
                    {{ t.label }}
                </AppButton>
            </div>
            <p class="mt-3 text-xs text-slate-500">Obras concluídas e arquivadas não contam para o limite de obras ativas do plano.</p>
        </AppCard>

        <AppCard v-if="canEdit" title="Dados da obra">
            <form class="space-y-4" novalidate @submit.prevent="save">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <ProjectFormFields v-model="form.data" :errors="form.errors.value" />
                <div class="flex justify-end">
                    <AppButton type="submit" :loading="form.processing.value">Guardar</AppButton>
                </div>
            </form>
        </AppCard>

        <AppCard v-else title="Dados da obra">
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Tipo</dt><dd class="font-medium">{{ project.type_label ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Dono de obra</dt><dd class="font-medium">{{ project.owner_name ?? '—' }}</dd></div>
                <div class="sm:col-span-2">
                    <dt class="text-slate-500">Morada</dt>
                    <dd class="font-medium">{{ [project.address, project.postal_code, project.city].filter(Boolean).join(', ') || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Valor da adjudicação</dt>
                    <dd class="font-medium">{{ project.contract_value_cents === null ? '—' : formatMoney(project.contract_value_cents) }}</dd>
                </div>
                <div><dt class="text-slate-500">Início · fim previsto</dt><dd class="font-medium">{{ formatDate(project.starts_on) }} · {{ formatDate(project.planned_end_on) }}</dd></div>
                <div v-if="project.description" class="sm:col-span-2">
                    <dt class="text-slate-500">Descrição</dt><dd class="whitespace-pre-line">{{ project.description }}</dd>
                </div>
            </dl>
        </AppCard>
    </div>
</template>
