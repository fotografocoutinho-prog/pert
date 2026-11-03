<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { projectsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Project, ProjectCalendar, ProjectStatus } from '@/api/types';
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

// Prazo em dias úteis, no calendário da obra.
const calendar = ref<ProjectCalendar | null>(null);
watch(
    () => [props.project.id, props.project.starts_on, props.project.planned_end_on, props.project.work_calendar_id],
    async () => {
        try {
            calendar.value = await projectsApi.calendar(props.project.id);
        } catch {
            calendar.value = null;
        }
    },
    { immediate: true },
);
const term = computed(() => calendar.value?.term ?? null);
const progress = computed(() =>
    term.value?.working_days ? Math.min(100, Math.round(((term.value.working_days_done ?? 0) / term.value.working_days) * 100)) : 0,
);

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

        <AppCard v-if="calendar" title="Prazo" :description="`Calendário: ${calendar.calendar.name}${calendar.calendar.chosen ? '' : ' (o da empresa)'}`">
            <template v-if="term">
                <p class="text-sm text-slate-700">
                    <span class="text-2xl font-bold text-slate-900">{{ term.working_days ?? '—' }}</span> dias úteis
                    <span class="text-slate-500">· {{ term.calendar_days }} dias de calendário, de {{ formatDate(project.starts_on) }} a {{ formatDate(project.planned_end_on) }}</span>
                </p>
                <template v-if="term.working_days">
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full rounded-full bg-brand-600" :style="{ width: `${progress}%` }" />
                    </div>
                    <p class="mt-2 text-sm text-slate-600">
                        {{ term.working_days_done }} decorridos até hoje · faltam {{ term.working_days_left }} até ao fim previsto
                    </p>
                </template>
                <p v-if="term.missing_holiday_years.length" class="mt-3 text-sm text-amber-800">
                    O calendário ainda não tem os feriados de {{ term.missing_holiday_years.join(', ') }}: esses anos contam feriados como dias úteis.
                    <RouterLink v-if="session.can('company.view')" to="/app/calendarios" class="font-semibold underline">Calendários</RouterLink>
                </p>
            </template>
            <p v-else class="text-sm text-slate-500">Indique o início e o fim previsto para ver o prazo em dias úteis.</p>
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
                <div>
                    <dt class="text-slate-500">Cliente</dt>
                    <dd class="font-medium">
                        <RouterLink v-if="project.client && session.can('clients.view')" :to="`/app/clientes/${project.client.id}`" class="text-brand-700 hover:underline">
                            {{ project.client.name }}
                        </RouterLink>
                        <template v-else>{{ project.client?.name ?? '—' }}</template>
                    </dd>
                </div>
                <div v-if="project.owner_name"><dt class="text-slate-500">Dono de obra</dt><dd class="font-medium">{{ project.owner_name }}</dd></div>
                <div><dt class="text-slate-500">Tipo</dt><dd class="font-medium">{{ project.type_label ?? '—' }}</dd></div>
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
