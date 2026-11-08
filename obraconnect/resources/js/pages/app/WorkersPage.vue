<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { membersApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Member } from '@/api/types';
import { centsToInput, eurosToCents, partnersApi, workersApi, type Partner, type Worker } from '@/api/economics';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();
const canManage = computed(() => session.can('workforce.manage') && !session.isReadOnly);
const canPayroll = computed(() => session.can('time.export_payroll'));

const workers = ref<Worker[]>([]);
const subs = ref<Partner[]>([]);
const members = ref<Member[]>([]);
const loading = ref(true);
const month = ref(new Date().toISOString().slice(0, 7));

async function load(): Promise<void> {
    workers.value = await workersApi.list();
    loading.value = false;
}
onMounted(load);

const open = ref(false);
const editing = ref<Worker | null>(null);
const form = useForm({ name: '', nif: '', category: '', cost: '', partner_id: '' as number | '', user_id: '' as number | '', active: true });
async function edit(w: Worker | null): Promise<void> {
    editing.value = w;
    form.reset(
        w
            ? { name: w.name, nif: w.nif ?? '', category: w.category ?? '', cost: centsToInput(w.hourly_cost_cents), partner_id: w.partner_id ?? '', user_id: w.user_id ?? '', active: w.active }
            : { active: true },
    );
    open.value = true;
    if (!subs.value.length) subs.value = await partnersApi.list('subcontractor').catch(() => []);
    if (!members.value.length) members.value = (await membersApi.list({ status: 'active', per_page: 100 })).data;
}
async function save(): Promise<void> {
    const payload = {
        name: form.data.name,
        nif: form.data.nif || null,
        category: form.data.category || null,
        hourly_cost_cents: eurosToCents(form.data.cost) ?? 0,
        partner_id: form.data.partner_id === '' ? null : Number(form.data.partner_id),
        user_id: form.data.user_id === '' ? null : Number(form.data.user_id),
        active: form.data.active,
    };
    const id = editing.value?.id;
    const saved = await form.submit(() => (id ? workersApi.update(id, payload) : workersApi.create(payload)), { success: 'Trabalhador guardado.' });
    if (saved) {
        open.value = false;
        await load();
    }
}
async function remove(w: Worker): Promise<void> {
    if (!window.confirm(`Apagar ${w.name}?`)) return;
    try {
        await workersApi.remove(w.id);
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível.');
    }
}
</script>

<template>
    <div>
        <PageHeader title="Trabalhadores" description="Pessoas cujas horas se registam nas obras (da empresa ou de subempreiteiros), com o custo/hora usado no mapa de custos.">
            <template v-if="canPayroll">
                <input v-model="month" type="month" aria-label="Mês a exportar" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <a :href="appUrl(workersApi.payrollUrl(month))" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50">Exportar horas (CSV)</a>
            </template>
            <AppButton v-if="canManage" @click="edit(null)">Novo trabalhador</AppButton>
        </PageHeader>

        <div v-if="loading" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <AppCard v-else>
            <EmptyState v-if="!workers.length" title="Sem trabalhadores" />
            <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-slate-500">
                    <tr>
                        <th class="py-2 pr-3">Nome</th>
                        <th class="py-2 pr-3">Categoria</th>
                        <th class="py-2 pr-3">Empresa</th>
                        <th class="py-2 pr-3 text-right">Custo/hora</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="w in workers" :key="w.id" :class="w.active ? '' : 'text-slate-400'">
                        <td class="py-2 pr-3">
                            {{ w.name }} <AppBadge v-if="!w.active">Inativo</AppBadge>
                            <span v-if="w.user" class="block text-xs text-slate-500">Regista as próprias horas ({{ w.user.email }})</span>
                        </td>
                        <td class="py-2 pr-3">{{ w.category }}</td>
                        <td class="py-2 pr-3">{{ w.partner ?? 'Própria' }}</td>
                        <td class="py-2 pr-3 text-right">{{ w.hourly_cost_cents === null ? '—' : formatMoney(w.hourly_cost_cents) }}</td>
                        <td class="py-2 text-right whitespace-nowrap">
                            <template v-if="canManage">
                                <AppButton size="sm" variant="ghost" @click="edit(w)">Editar</AppButton>
                                <AppButton v-if="!w.time_entries_count" size="sm" variant="ghost" class="text-red-700" @click="remove(w)">Apagar</AppButton>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
        </AppCard>

        <AppModal :open="open" :title="editing ? `Editar ${editing.name}` : 'Novo trabalhador'" @close="open = false">
            <form id="worker-form" class="space-y-4" novalidate @submit.prevent="save">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <AppInput v-model="form.data.name" label="Nome" required :maxlength="150" :error="form.errors.value.name" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.data.category" label="Categoria" placeholder="Pedreiro de 1.ª" :error="form.errors.value.category" />
                    <AppInput v-model="form.data.cost" label="Custo/hora (€)" inputmode="decimal" :error="form.errors.value.hourly_cost_cents" />
                    <AppInput v-model="form.data.nif" label="NIF" inputmode="numeric" hint="Guardado cifrado." :error="form.errors.value.nif" />
                    <AppSelect
                        v-model="form.data.partner_id"
                        label="Empresa"
                        :options="[{ value: '', label: 'Própria' }, ...subs.map((s) => ({ value: s.id, label: s.name }))]"
                        :error="form.errors.value.partner_id"
                    />
                </div>
                <AppSelect
                    v-model="form.data.user_id"
                    label="Conta na aplicação (para registar as próprias horas)"
                    :options="[{ value: '', label: '(nenhuma)' }, ...members.map((m) => ({ value: m.user.id, label: `${m.user.name} · ${m.user.email}` }))]"
                    :error="form.errors.value.user_id"
                />
                <AppCheckbox v-model="form.data.active" label="Ativo" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="open = false">Cancelar</AppButton>
                <AppButton type="submit" form="worker-form" :loading="form.processing.value">Guardar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
