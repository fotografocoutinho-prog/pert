<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { clientsApi } from '@/api';
import type { Client } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { clientPayload, clientToForm } from '@/utils/clients';
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
import ClientFormFields from '@/components/clients/ClientFormFields.vue';

const session = useSessionStore();
const router = useRouter();

const clients = ref<Client[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const search = ref('');
const status = ref('active');
const page = ref(1);

const canManage = computed(() => session.can('clients.manage') && !session.isReadOnly);
const filters = [
    { value: 'active', label: 'Ativos' },
    { value: 'archived', label: 'Arquivados' },
    { value: 'all', label: 'Todos' },
];

async function load(): Promise<void> {
    loading.value = true;
    try {
        const result = await clientsApi.list({ search: search.value || undefined, status: status.value, page: page.value });
        clients.value = result.data;
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

const createOpen = ref(false);
const form = useForm(clientToForm());

function openCreate(): void {
    form.reset();
    createOpen.value = true;
}

async function create(): Promise<void> {
    const created = await form.submit(() => clientsApi.create(clientPayload(form.data)), { success: 'Cliente criado.' });
    if (created) {
        createOpen.value = false;
        await router.push(`/app/clientes/${created.id}`);
    }
}
</script>

<template>
    <div>
        <PageHeader title="Clientes" description="Quem encomenda as obras. Escolhe-se o cliente nos dados de cada obra.">
            <AppButton v-if="canManage" @click="openCreate">Novo cliente</AppButton>
        </PageHeader>

        <AppCard>
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <AppInput v-model="search" placeholder="Pesquisar por nome, NIF, contacto ou localidade" class="w-full max-w-sm" />
                <div class="flex flex-wrap gap-1" role="group" aria-label="Filtrar">
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
                v-else-if="clients.length === 0"
                title="Sem clientes"
                :description="canManage && !search && status === 'active' ? 'Crie o primeiro com o botão “Novo cliente”.' : undefined"
            />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="client in clients" :key="client.id">
                    <RouterLink :to="`/app/clientes/${client.id}`" class="-mx-2 flex flex-wrap items-center justify-between gap-3 rounded-lg px-2 py-3 hover:bg-slate-50">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-slate-900">{{ client.name }}</p>
                            <p class="text-sm text-slate-500">
                                {{ [client.type_label, client.nif ? `NIF ${client.nif}` : null, client.contact_name, client.city].filter(Boolean).join(' · ') }}
                            </p>
                        </div>
                        <span class="flex items-center gap-2 text-sm text-slate-500">
                            <AppBadge v-if="client.archived">Arquivado</AppBadge>
                            {{ client.projects_count === 1 ? '1 obra' : `${client.projects_count ?? 0} obras` }}
                        </span>
                    </RouterLink>
                </li>
            </ul>
            <AppPagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="(p) => { page = p; load(); }" />
        </AppCard>

        <AppModal :open="createOpen" title="Novo cliente" size="lg" @close="createOpen = false">
            <form id="client-form" class="space-y-4" novalidate @submit.prevent="create">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <ClientFormFields v-model="form.data" :errors="form.errors.value" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="createOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="client-form" :loading="form.processing.value">Criar cliente</AppButton>
            </template>
        </AppModal>
    </div>
</template>
