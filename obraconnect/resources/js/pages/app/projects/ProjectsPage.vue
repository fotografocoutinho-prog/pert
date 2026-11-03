<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { projectsApi } from '@/api';
import type { Project } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { formatDate, formatMoney } from '@/utils/format';
import { formToPayload, PROJECT_STATUS_OPTIONS, projectToForm, statusColor } from '@/utils/projects';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppPagination from '@/components/ui/AppPagination.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ProjectFormFields from '@/components/projects/ProjectFormFields.vue';

const session = useSessionStore();
const router = useRouter();

const projects = ref<Project[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const search = ref('');
const status = ref('open');
const page = ref(1);

const canCreate = computed(() => session.can('projects.create') && !session.isReadOnly);
const filters = [{ value: 'open', label: 'Abertas' }, { value: '', label: 'Todas' }, ...PROJECT_STATUS_OPTIONS];

async function load(): Promise<void> {
    loading.value = true;
    try {
        const result = await projectsApi.list({ search: search.value || undefined, status: status.value || undefined, page: page.value });
        projects.value = result.data;
        meta.value = result.meta;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

let timer: number | undefined;
watch(search, () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
        page.value = 1;
        load();
    }, 300);
});
watch(status, () => {
    page.value = 1;
    load();
});

// Nova obra
const createOpen = ref(false);
const form = useForm(projectToForm());

function openCreate(): void {
    form.reset();
    createOpen.value = true;
}

async function create(): Promise<void> {
    const payload = formToPayload(form.data);
    if (!payload) {
        form.errors.value = { contract_value: 'Valor inválido. Use, por exemplo, 125000,00.' };
        return;
    }
    const created = await form.submit(() => projectsApi.create(payload), { success: 'Obra criada.' });
    if (created) {
        createOpen.value = false;
        await router.push(`/app/obras/${created.id}`);
    }
}
</script>

<template>
    <div>
        <PageHeader title="Obras" description="As obras a que tem acesso: todas, ou só aquelas de cuja equipa faz parte.">
            <AppButton v-if="canCreate" @click="openCreate">Nova obra</AppButton>
        </PageHeader>

        <AppCard>
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <AppInput v-model="search" placeholder="Pesquisar por nome, código ou localidade" class="w-full max-w-sm" />
                <div class="flex flex-wrap gap-1" role="group" aria-label="Filtrar por estado">
                    <button
                        v-for="f in filters"
                        :key="f.value"
                        type="button"
                        class="rounded-full px-3 py-1 text-sm font-medium"
                        :class="status === f.value ? 'bg-brand-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                        :aria-pressed="status === f.value"
                        @click="status = f.value"
                    >
                        {{ f.label }}
                    </button>
                </div>
            </div>

            <div v-if="loading" class="flex justify-center py-10"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
            <EmptyState
                v-else-if="projects.length === 0"
                title="Sem obras"
                :description="canCreate ? 'Crie a primeira obra com o botão “Nova obra”.' : 'Ainda não faz parte da equipa de nenhuma obra.'"
            />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="project in projects" :key="project.id">
                    <RouterLink :to="`/app/obras/${project.id}`" class="-mx-2 flex flex-wrap items-center justify-between gap-3 rounded-lg px-2 py-3 hover:bg-slate-50">
                        <div class="min-w-0">
                            <p class="text-xs font-medium tracking-wide text-slate-500">{{ project.code }}</p>
                            <p class="truncate font-semibold text-slate-900">{{ project.name }}</p>
                            <p class="text-sm text-slate-500">
                                {{ [project.city, project.client?.name ?? project.owner_name].filter(Boolean).join(' · ') || '—' }}
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-1 text-right text-sm">
                            <AppBadge :color="statusColor(project.status)">{{ project.status_label }}</AppBadge>
                            <span class="text-slate-500">
                                <template v-if="project.planned_end_on">Fim previsto {{ formatDate(project.planned_end_on) }}</template>
                                <template v-if="project.contract_value_cents !== null"> · {{ formatMoney(project.contract_value_cents) }}</template>
                            </span>
                        </div>
                    </RouterLink>
                </li>
            </ul>
            <AppPagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="(p) => { page = p; load(); }" />
        </AppCard>

        <AppModal :open="createOpen" title="Nova obra" size="lg" @close="createOpen = false">
            <form id="project-form" class="space-y-4" novalidate @submit.prevent="create">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <ProjectFormFields v-model="form.data" :errors="form.errors.value" />
                <p class="text-xs text-slate-500">Fica na equipa da obra. Pode juntar outras pessoas a seguir, no separador “Equipa”.</p>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="createOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="project-form" :loading="form.processing.value">Criar obra</AppButton>
            </template>
        </AppModal>
    </div>
</template>
