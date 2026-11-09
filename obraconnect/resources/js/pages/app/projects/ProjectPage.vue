<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { projectsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Project } from '@/api/types';
import { statusColor } from '@/utils/projects';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import ProjectDetailsTab from '@/components/projects/ProjectDetailsTab.vue';
import ProjectDiaryTab from '@/components/projects/ProjectDiaryTab.vue';
import ProjectDocumentsTab from '@/components/projects/ProjectDocumentsTab.vue';
import ProjectInspectionsTab from '@/components/projects/ProjectInspectionsTab.vue';
import ProjectIssuesTab from '@/components/projects/ProjectIssuesTab.vue';
import ProjectStructureTab from '@/components/projects/ProjectStructureTab.vue';
import ProjectTeamTab from '@/components/projects/ProjectTeamTab.vue';

const ProjectBudgetTab = defineAsyncComponent(() => import('@/components/projects/ProjectBudgetTab.vue'));
const ProjectCostsTab = defineAsyncComponent(() => import('@/components/projects/ProjectCostsTab.vue'));
const ProjectForecastTab = defineAsyncComponent(() => import('@/components/projects/ProjectForecastTab.vue'));
const ProjectMeasurementsTab = defineAsyncComponent(() => import('@/components/projects/ProjectMeasurementsTab.vue'));
const ProjectPlanningTab = defineAsyncComponent(() => import('@/components/projects/ProjectPlanningTab.vue'));
const ProjectPurchasesTab = defineAsyncComponent(() => import('@/components/projects/ProjectPurchasesTab.vue'));
const ProjectTimeTab = defineAsyncComponent(() => import('@/components/projects/ProjectTimeTab.vue'));

const route = useRoute();
const router = useRouter();

const project = ref<Project | null>(null);
const error = ref<string | null>(null);

const any = (...permissions: string[]) => permissions.some((p) => project.value?.permissions.includes(p));
const tabs = computed(() =>
    [
        { key: 'diario', label: 'Diário', show: project.value?.permissions.includes('diary.view') },
        { key: 'documentos', label: 'Documentos', show: project.value?.permissions.includes('documents.view') },
        {
            key: 'inspecoes',
            label: 'Inspeções',
            show: ['inspections.request', 'inspections.perform', 'inspections.approve'].some((p) => project.value?.permissions.includes(p)),
        },
        { key: 'ocorrencias', label: 'Ocorrências e NC', show: project.value?.permissions.includes('issues.view') },
        { key: 'orcamento', label: 'Orçamento', show: any('budgets.view', 'budgets.manage', 'costs.view') },
        { key: 'medicoes', label: 'Medições e autos', show: any('measurements.manage', 'certificates.approve', 'budgets.view', 'costs.view') },
        { key: 'compras', label: 'Compras e subempreitadas', show: any('purchases.manage', 'subcontractors.manage', 'costs.view') },
        { key: 'horas', label: 'Horas e equipamentos', show: any('workforce.view', 'time.register_team', 'time.register_own', 'time.approve', 'equipment.manage', 'costs.view') },
        { key: 'custos', label: 'Custos', show: any('costs.view', 'costs.manage', 'finance.view') },
        { key: 'planeamento', label: 'Planeamento', show: any('planning.view', 'planning.manage') },
        { key: 'previsoes', label: 'Previsões', show: any('costs.view', 'costs.manage', 'finance.view', 'planning.view', 'planning.manage') },
        { key: 'estrutura', label: 'Estrutura', show: true },
        { key: 'equipa', label: 'Equipa', show: true },
        { key: 'dados', label: 'Dados da obra', show: true },
    ].filter((t) => t.show),
);
const tab = computed(() => {
    const wanted = String(route.query.separador ?? '');
    return tabs.value.some((t) => t.key === wanted) ? wanted : (tabs.value[0]?.key ?? 'dados');
});

async function load(): Promise<void> {
    error.value = null;
    try {
        project.value = await projectsApi.get(Number(route.params.id));
        document.title = `${project.value.name} · ObraConnect`;
    } catch (e) {
        error.value = e instanceof ApiError && e.status === 403 ? 'Não tem acesso a esta obra.' : e instanceof ApiError ? e.message : 'Não foi possível abrir a obra.';
    }
}

watch(() => route.params.id, load, { immediate: true });

function select(key: string): void {
    router.replace({ query: { ...route.query, separador: key } });
}
</script>

<template>
    <div>
        <AppAlert v-if="error" type="error">{{ error }}</AppAlert>
        <div v-else-if="!project" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>

        <template v-else>
            <RouterLink to="/app/obras" class="text-sm font-medium text-brand-700 hover:underline">← Obras</RouterLink>
            <div class="mt-2 mb-5 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-medium tracking-wide text-slate-500">{{ project.code }}</p>
                    <h1 class="text-2xl font-bold text-slate-900">{{ project.name }}</h1>
                    <p class="text-sm text-slate-500">{{ [project.address, project.city].filter(Boolean).join(', ') || 'Sem morada' }}</p>
                </div>
                <AppBadge :color="statusColor(project.status)">{{ project.status_label }}</AppBadge>
            </div>

            <AppAlert v-if="project.status === 'archived'" class="mb-4">
                A obra está arquivada: pode consultar tudo, mas não alterar. Reabra-a em “Dados da obra” se precisar.
            </AppAlert>

            <nav class="mb-4 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-slate-200" aria-label="Separadores da obra">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap"
                    :class="tab === t.key ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    :aria-current="tab === t.key ? 'page' : undefined"
                    @click="select(t.key)"
                >
                    {{ t.label }}
                </button>
            </nav>

            <!-- Ao passar de uma obra para outra, o separador monta-se de novo (os dados são da outra obra). -->
            <div :key="project.id">
                <ProjectDiaryTab v-if="tab === 'diario'" :project="project" />
                <ProjectDocumentsTab v-else-if="tab === 'documentos'" :project="project" />
                <ProjectInspectionsTab v-else-if="tab === 'inspecoes'" :project="project" />
                <ProjectIssuesTab v-else-if="tab === 'ocorrencias'" :project="project" />
                <ProjectBudgetTab v-else-if="tab === 'orcamento'" :project="project" />
                <ProjectMeasurementsTab v-else-if="tab === 'medicoes'" :project="project" />
                <ProjectPurchasesTab v-else-if="tab === 'compras'" :project="project" />
                <ProjectTimeTab v-else-if="tab === 'horas'" :project="project" />
                <ProjectCostsTab v-else-if="tab === 'custos'" :project="project" />
                <ProjectPlanningTab v-else-if="tab === 'planeamento'" :project="project" />
                <ProjectForecastTab v-else-if="tab === 'previsoes'" :project="project" />
                <ProjectStructureTab v-else-if="tab === 'estrutura'" :project="project" />
                <ProjectTeamTab v-else-if="tab === 'equipa'" :project="project" @changed="load" />
                <ProjectDetailsTab v-else :project="project" @updated="(p) => (project = p)" />
            </div>
        </template>
    </div>
</template>
