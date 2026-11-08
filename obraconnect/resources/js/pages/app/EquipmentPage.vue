<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { projectsApi } from '@/api';
import type { Project } from '@/api/types';
import { equipmentApi, eurosToCents, type EquipmentSummary } from '@/api/economics';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const router = useRouter();
const canManage = computed(() => session.can('equipment.manage') && !session.isReadOnly);

const list = ref<EquipmentSummary[]>([]);
const projects = ref<Project[]>([]);
const loading = ref(true);
async function load(): Promise<void> {
    list.value = await equipmentApi.list();
    loading.value = false;
}
onMounted(load);

const statusColor = (s: string) => ({ available: 'green', in_use: 'brand', maintenance: 'amber', retired: 'gray' })[s] as 'green' | 'brand' | 'amber' | 'gray';

const open = ref(false);
const form = useForm({ code: '', name: '', category: '', serial_number: '', cost: '', project_id: '' as number | '' });
async function create(): Promise<void> {
    form.reset();
    open.value = true;
    if (!projects.value.length) projects.value = (await projectsApi.list({ status: 'active' })).data;
}
async function save(): Promise<void> {
    const saved = await form.submit(
        () =>
            equipmentApi.create({
                code: form.data.code,
                name: form.data.name,
                category: form.data.category || null,
                serial_number: form.data.serial_number || null,
                hourly_cost_cents: eurosToCents(form.data.cost) ?? 0,
                project_id: form.data.project_id === '' ? null : Number(form.data.project_id),
            }),
        { success: 'Equipamento criado.' },
    );
    if (saved) {
        open.value = false;
        router.push(`/app/equipamentos/${saved.id}`);
    }
}
</script>

<template>
    <div>
        <PageHeader title="Equipamentos" description="Equipamentos da empresa: onde estão, horas por obra (entram no custo), manutenções e etiqueta QR que abre a ficha no telemóvel.">
            <AppButton v-if="canManage" @click="create">Novo equipamento</AppButton>
        </PageHeader>
        <div v-if="loading" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <AppCard v-else>
            <EmptyState v-if="!list.length" title="Sem equipamentos" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="e in list" :key="e.id">
                    <RouterLink :to="`/app/equipamentos/${e.id}`" class="flex flex-wrap items-start justify-between gap-2 py-3 hover:bg-slate-50">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900"><span class="font-mono text-sm">{{ e.code }}</span> · {{ e.name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ [e.category, e.project ? `em ${e.project.code} · ${e.project.name}` : null, `${formatMoney(e.hourly_cost_cents)}/h`].filter(Boolean).join(' · ') }}
                                <template v-if="e.next_maintenance_on"> · manutenção {{ formatDate(e.next_maintenance_on) }}</template>
                            </p>
                        </div>
                        <div class="flex gap-1.5">
                            <AppBadge v-if="e.maintenance_due" color="red">Manutenção em atraso</AppBadge>
                            <AppBadge :color="statusColor(e.status)">{{ e.status_label }}</AppBadge>
                        </div>
                    </RouterLink>
                </li>
            </ul>
        </AppCard>

        <AppModal :open="open" title="Novo equipamento" @close="open = false">
            <form id="equipment-form" class="space-y-4" novalidate @submit.prevent="save">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.data.code" label="Código" required placeholder="GR-01" :error="form.errors.value.code" />
                    <AppInput v-model="form.data.cost" label="Custo/hora (€)" inputmode="decimal" :error="form.errors.value.hourly_cost_cents" />
                </div>
                <AppInput v-model="form.data.name" label="Nome" required placeholder="Grua torre" :error="form.errors.value.name" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.data.category" label="Categoria" placeholder="Elevação" />
                    <AppInput v-model="form.data.serial_number" label="N.º de série" />
                </div>
                <AppSelect v-model="form.data.project_id" label="Obra onde está" :options="[{ value: '', label: '(no estaleiro central)' }, ...projects.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))]" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="open = false">Cancelar</AppButton>
                <AppButton type="submit" form="equipment-form" :loading="form.processing.value">Criar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
