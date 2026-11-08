<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { projectsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Project } from '@/api/types';
import { centsToInput, equipmentApi, eurosToCents, type EquipmentDetail } from '@/api/economics';
import { useForm } from '@/composables/useForm';
import { appUrl } from '@/utils/base';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';

const route = useRoute();
const router = useRouter();

const item = ref<EquipmentDetail | null>(null);
const projects = ref<Project[]>([]);
const error = ref<string | null>(null);
const STATUSES = [
    { value: 'available', label: 'Disponível' },
    { value: 'in_use', label: 'Em obra' },
    { value: 'maintenance', label: 'Em manutenção' },
    { value: 'retired', label: 'Abatido' },
];

const form = useForm({ name: '', category: '', serial_number: '', cost: '', status: 'available', project_id: '' as number | '', notes: '' });
function fill(e: EquipmentDetail): void {
    item.value = e;
    form.reset({ name: e.name, category: e.category ?? '', serial_number: e.serial_number ?? '', cost: centsToInput(e.hourly_cost_cents), status: e.status, project_id: e.project_id ?? '', notes: e.notes ?? '' });
    document.title = `${e.code} · ObraConnect`;
}

async function load(): Promise<void> {
    error.value = null;
    try {
        // Vindo da etiqueta QR (/app/equipamentos/qr/:token) ou da lista (/app/equipamentos/:id).
        const e = route.params.token ? await equipmentApi.byToken(String(route.params.token)) : await equipmentApi.get(Number(route.params.id));
        fill(e);
        if (route.params.token) router.replace(`/app/equipamentos/${e.id}`);
        if (e.can_manage && !projects.value.length) projects.value = (await projectsApi.list({ status: 'active' })).data;
    } catch (e) {
        error.value = e instanceof ApiError && e.status === 404 ? 'Equipamento não encontrado.' : e instanceof ApiError ? e.message : 'Não foi possível abrir.';
    }
}
watch(() => [route.params.id, route.params.token], load, { immediate: true });

const projectOptions = computed(() => [{ value: '', label: '(no estaleiro central)' }, ...projects.value.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))]);

async function save(): Promise<void> {
    if (!item.value) return;
    const saved = await form.submit(
        () =>
            equipmentApi.update(item.value!.id, {
                name: form.data.name,
                category: form.data.category || null,
                serial_number: form.data.serial_number || null,
                hourly_cost_cents: eurosToCents(form.data.cost) ?? 0,
                status: form.data.status,
                project_id: form.data.project_id === '' ? null : Number(form.data.project_id),
                notes: form.data.notes || null,
            }),
        { success: 'Guardado.' },
    );
    if (saved) fill(saved);
}

const maint = useForm({ performed_on: new Date().toISOString().slice(0, 10), description: '', cost: '', next_due_on: '' });
async function addMaintenance(): Promise<void> {
    if (!item.value) return;
    const saved = await maint.submit(() =>
        equipmentApi.addMaintenance(item.value!.id, {
            performed_on: maint.data.performed_on,
            description: maint.data.description,
            cost_cents: eurosToCents(maint.data.cost) ?? 0,
            next_due_on: maint.data.next_due_on || null,
        }),
    );
    if (saved) {
        fill(saved);
        maint.reset();
    }
}
async function removeMaintenance(id: number): Promise<void> {
    if (item.value) fill(await equipmentApi.removeMaintenance(item.value.id, id));
}
function printLabel(): void {
    window.print();
}
</script>

<template>
    <div>
        <RouterLink to="/app/equipamentos" class="text-sm font-medium text-brand-700 hover:underline print:hidden">← Equipamentos</RouterLink>
        <AppAlert v-if="error" type="error" class="mt-3">{{ error }}</AppAlert>
        <div v-else-if="!item" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <template v-else>
            <div class="mt-2 mb-4 flex flex-wrap items-start justify-between gap-3 print:hidden">
                <div>
                    <p class="font-mono text-xs font-semibold text-slate-500">{{ item.code }}</p>
                    <h1 class="text-2xl font-bold text-slate-900">{{ item.name }}</h1>
                    <p class="text-sm text-slate-600">{{ item.project ? `Em obra: ${item.project.code} · ${item.project.name}` : 'No estaleiro central' }} · {{ item.hours_total }} h registadas</p>
                </div>
                <div class="flex gap-1.5">
                    <AppBadge v-if="item.maintenance_due" color="red">Manutenção em atraso</AppBadge>
                    <AppBadge>{{ item.status_label }}</AppBadge>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2 print:hidden">
                    <AppCard v-if="item.can_manage" title="Ficha">
                        <form class="space-y-4" novalidate @submit.prevent="save">
                            <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <AppInput v-model="form.data.name" label="Nome" :error="form.errors.value.name" />
                                <AppInput v-model="form.data.cost" label="Custo/hora (€)" inputmode="decimal" />
                                <AppInput v-model="form.data.category" label="Categoria" />
                                <AppInput v-model="form.data.serial_number" label="N.º de série" />
                                <AppSelect v-model="form.data.project_id" label="Obra onde está" :options="projectOptions" />
                                <AppSelect v-model="form.data.status" label="Situação" :options="STATUSES" />
                            </div>
                            <AppTextarea v-model="form.data.notes" label="Notas" :rows="2" />
                            <AppButton type="submit" :loading="form.processing.value">Guardar</AppButton>
                        </form>
                    </AppCard>

                    <AppCard title="Manutenções">
                        <ul class="mb-4 divide-y divide-slate-100 text-sm">
                            <li v-for="m in item.maintenance" :key="m.id" class="flex justify-between gap-2 py-2">
                                <span>
                                    <strong>{{ formatDate(m.performed_on) }}</strong> · {{ m.description }}<template v-if="m.cost_cents"> · {{ formatMoney(m.cost_cents) }}</template>
                                    <span v-if="m.next_due_on" class="block text-xs text-slate-500">Próxima: {{ formatDate(m.next_due_on) }}</span>
                                </span>
                                <button v-if="item.can_manage" type="button" class="text-xs text-red-700 hover:underline" @click="removeMaintenance(m.id)">Retirar</button>
                            </li>
                            <li v-if="!item.maintenance.length" class="py-2 text-slate-500">Sem manutenções registadas.</li>
                        </ul>
                        <form v-if="item.can_manage" class="grid gap-3 sm:grid-cols-4" novalidate @submit.prevent="addMaintenance">
                            <AppInput v-model="maint.data.performed_on" type="date" label="Data" />
                            <AppInput v-model="maint.data.description" class="sm:col-span-2" label="O que se fez" :error="maint.errors.value.description" />
                            <AppInput v-model="maint.data.cost" label="Custo (€)" inputmode="decimal" />
                            <AppInput v-model="maint.data.next_due_on" type="date" label="Próxima" :error="maint.errors.value.next_due_on" />
                            <div class="sm:col-span-4"><AppButton type="submit" size="sm" :loading="maint.processing.value" :disabled="!maint.data.description">Registar manutenção</AppButton></div>
                        </form>
                    </AppCard>

                    <AppCard title="Utilização recente">
                        <ul class="divide-y divide-slate-100 text-sm">
                            <li v-for="u in item.usages" :key="u.id" class="py-2">{{ formatDate(u.used_on) }} · {{ u.hours }} h · {{ u.project }}<template v-if="u.notes"> · {{ u.notes }}</template></li>
                            <li v-if="!item.usages.length" class="py-2 text-slate-500">Sem horas registadas (registam-se no separador Horas da obra).</li>
                        </ul>
                    </AppCard>
                </div>

                <AppCard title="Etiqueta QR" description="Imprima e cole no equipamento: abre esta ficha no telemóvel (com sessão iniciada).">
                    <div class="flex flex-col items-center text-center">
                        <img :src="appUrl(equipmentApi.qrUrl(item.id))" :alt="`QR de ${item.code}`" class="h-48 w-48" />
                        <p class="mt-2 font-mono text-lg font-bold">{{ item.code }}</p>
                        <p class="text-sm">{{ item.name }}</p>
                        <AppButton class="mt-3 print:hidden" size="sm" variant="secondary" @click="printLabel">Imprimir etiqueta</AppButton>
                    </div>
                </AppCard>
            </div>
        </template>
    </div>
</template>
