<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { clientsApi, projectsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Client, Project } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate } from '@/utils/format';
import { clientPayload, clientToForm } from '@/utils/clients';
import { statusColor } from '@/utils/projects';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ClientFormFields from '@/components/clients/ClientFormFields.vue';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const toast = useToastStore();

const client = ref<Client | null>(null);
const projects = ref<Project[]>([]);
const loading = ref(true);
const canManage = computed(() => session.can('clients.manage') && !session.isReadOnly);
const form = useForm(clientToForm());

async function load(): Promise<void> {
    loading.value = true;
    try {
        client.value = await clientsApi.get(Number(route.params.id));
        form.reset(clientToForm(client.value));
        // Só as obras a que quem consulta tem acesso.
        projects.value = session.can('projects.view') ? (await projectsApi.list({ client_id: client.value.id, status: '' })).data : [];
    } catch (e) {
        if (e instanceof ApiError && e.status === 404) {
            toast.error('Cliente não encontrado.');
            await router.replace('/app/clientes');
            return;
        }
        throw e;
    } finally {
        loading.value = false;
    }
}

watch(() => route.params.id, load, { immediate: true });

async function save(): Promise<void> {
    const updated = await form.submit(() => clientsApi.update(client.value!.id, clientPayload(form.data)), { success: 'Dados do cliente guardados.' });
    if (updated) client.value = updated;
}

async function setArchived(archived: boolean): Promise<void> {
    if (archived && !window.confirm('Arquivar este cliente? Deixa de aparecer para escolher em obras novas; as obras que já o têm continuam a mostrá-lo.')) return;
    try {
        client.value = await clientsApi.update(client.value!.id, { archived });
        toast.success(archived ? 'Cliente arquivado.' : 'Cliente reativado.');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível alterar o cliente.');
    }
}

async function remove(): Promise<void> {
    if (!window.confirm(`Apagar o cliente “${client.value!.name}”? Não pode ser desfeito.`)) return;
    try {
        await clientsApi.remove(client.value!.id);
        toast.success('Cliente apagado.');
        await router.push('/app/clientes');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível apagar o cliente.');
    }
}
</script>

<template>
    <div>
        <div v-if="loading && !client" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <template v-else-if="client">
            <RouterLink to="/app/clientes" class="mb-2 inline-block text-sm font-medium text-brand-700 hover:underline">‹ Clientes</RouterLink>
            <PageHeader :title="client.name" :description="[client.type_label, client.nif ? `NIF ${client.nif}` : null].filter(Boolean).join(' · ')">
                <AppBadge v-if="client.archived">Arquivado</AppBadge>
            </PageHeader>

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-2">
                    <AppCard v-if="canManage" title="Dados do cliente">
                        <form class="space-y-4" novalidate @submit.prevent="save">
                            <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                            <ClientFormFields v-model="form.data" :errors="form.errors.value" />
                            <div class="flex justify-end">
                                <AppButton type="submit" :loading="form.processing.value">Guardar</AppButton>
                            </div>
                        </form>
                    </AppCard>

                    <AppCard v-else title="Dados do cliente">
                        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                            <div><dt class="text-slate-500">Pessoa de contacto</dt><dd class="font-medium">{{ client.contact_name ?? '—' }}</dd></div>
                            <div>
                                <dt class="text-slate-500">Telefone</dt>
                                <dd class="font-medium"><a v-if="client.phone" :href="`tel:${client.phone}`" class="text-brand-700 hover:underline">{{ client.phone }}</a><template v-else>—</template></dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-slate-500">Email</dt>
                                <dd class="font-medium"><a v-if="client.email" :href="`mailto:${client.email}`" class="text-brand-700 hover:underline">{{ client.email }}</a><template v-else>—</template></dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-slate-500">Morada</dt>
                                <dd class="font-medium">{{ [client.address, client.postal_code, client.city].filter(Boolean).join(', ') || '—' }}</dd>
                            </div>
                            <div v-if="client.notes" class="sm:col-span-2"><dt class="text-slate-500">Notas</dt><dd class="whitespace-pre-line">{{ client.notes }}</dd></div>
                        </dl>
                    </AppCard>
                </div>

                <div class="space-y-4">
                    <AppCard title="Obras" :description="client.projects_count ? `${client.projects_count} no total` : undefined">
                        <EmptyState v-if="projects.length === 0" :title="client.projects_count ? 'Sem obras a que tenha acesso' : 'Ainda sem obras'" />
                        <ul v-else class="divide-y divide-slate-100">
                            <li v-for="p in projects" :key="p.id">
                                <RouterLink :to="`/app/obras/${p.id}`" class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 hover:bg-slate-50">
                                    <span class="min-w-0">
                                        <span class="block text-xs font-medium text-slate-500">{{ p.code }}</span>
                                        <span class="block truncate font-medium text-slate-900">{{ p.name }}</span>
                                        <span v-if="p.planned_end_on" class="block text-xs text-slate-500">Fim previsto {{ formatDate(p.planned_end_on) }}</span>
                                    </span>
                                    <AppBadge :color="statusColor(p.status)">{{ p.status_label }}</AppBadge>
                                </RouterLink>
                            </li>
                        </ul>
                    </AppCard>

                    <AppCard v-if="canManage" title="Arquivar ou apagar">
                        <p class="mb-3 text-sm text-slate-600">
                            <template v-if="client.projects_count">Com obras, o cliente não se apaga: arquive-o se já não trabalha com ele.</template>
                            <template v-else>Sem obras, pode apagar o cliente.</template>
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <AppButton v-if="client.archived" @click="setArchived(false)">Reativar</AppButton>
                            <AppButton v-else variant="secondary" @click="setArchived(true)">Arquivar</AppButton>
                            <AppButton v-if="!client.projects_count" variant="danger" @click="remove">Apagar</AppButton>
                        </div>
                    </AppCard>
                </div>
            </div>
        </template>
    </div>
</template>
