<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { calendarsApi, clientsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { ClientOption, ClientType, WorkCalendar } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { CLIENT_TYPE_OPTIONS } from '@/utils/clients';
import { PROJECT_TYPE_OPTIONS, type ProjectFormData } from '@/utils/projects';
import AppButton from '@/components/ui/AppButton.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';

defineProps<{ errors: Record<string, string>; disabled?: boolean }>();
const data = defineModel<ProjectFormData>({ required: true });

const session = useSessionStore();
const clients = ref<ClientOption[]>([]);
const calendars = ref<WorkCalendar[]>([]);

onMounted(async () => {
    // Sem rede ou sem permissão, os campos ficam só com o valor atual.
    const [c, w] = await Promise.allSettled([clientsApi.options(data.value.client_id || null), calendarsApi.list()]);
    if (c.status === 'fulfilled') clients.value = c.value;
    if (w.status === 'fulfilled') calendars.value = w.value;
});

const clientOptions = computed(() => [
    { value: '', label: 'Sem cliente' },
    ...clients.value.map((c) => ({ value: c.id, label: c.archived ? `${c.name} (arquivado)` : c.name })),
]);
const calendarOptions = computed(() => {
    const fallback = calendars.value.find((c) => c.is_default);
    return [
        { value: '', label: fallback ? `O da empresa (${fallback.name})` : 'O da empresa' },
        ...calendars.value.map((c) => ({ value: c.id, label: c.name })),
    ];
});

// Cliente novo sem sair do formulário da obra.
const canCreateClient = computed(() => session.can('clients.manage') && !session.isReadOnly);
const newClient = ref<{ type: ClientType; name: string; nif: string } | null>(null);
const newClientError = ref<string | null>(null);
const creatingClient = ref(false);

async function createClient(): Promise<void> {
    if (!newClient.value) return;
    creatingClient.value = true;
    newClientError.value = null;
    try {
        const created = await clientsApi.create({ ...newClient.value, nif: newClient.value.nif || null });
        clients.value = [...clients.value, { id: created.id, name: created.name, type: created.type, archived: false }].sort((a, b) => a.name.localeCompare(b.name, 'pt'));
        data.value.client_id = created.id;
        newClient.value = null;
    } catch (e) {
        newClientError.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Não foi possível criar o cliente.';
    } finally {
        creatingClient.value = false;
    }
}
</script>

<template>
    <fieldset class="grid gap-4 sm:grid-cols-6" :disabled="disabled">
        <AppInput v-model="data.name" label="Nome da obra" required :maxlength="150" class="sm:col-span-4" :error="errors.name" />
        <AppInput v-model="data.code" label="Código" :maxlength="30" placeholder="Automático" class="sm:col-span-2" :error="errors.code" />

        <div class="sm:col-span-3">
            <AppSelect v-model="data.client_id" label="Cliente" :options="clientOptions" :error="errors.client_id" />
            <button
                v-if="canCreateClient && !newClient"
                type="button"
                class="mt-1 text-sm font-medium text-brand-700 hover:underline"
                @click="newClient = { type: 'private', name: '', nif: '' }"
            >
                + Novo cliente
            </button>
        </div>
        <AppInput
            v-model="data.owner_name"
            label="Dono de obra"
            hint="Só se não for o cliente (ex.: quando a empresa é subempreiteira)."
            :maxlength="150"
            class="sm:col-span-3"
            :error="errors.owner_name"
        />

        <!-- Enter aqui cria o cliente, não submete a obra. -->
        <div
            v-if="newClient"
            class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:col-span-6 sm:grid-cols-6"
            @keydown.enter.prevent="newClient.name.trim() && createClient()"
        >
            <AppSelect v-model="newClient.type" label="Tipo de cliente" :options="CLIENT_TYPE_OPTIONS" class="sm:col-span-2" />
            <AppInput v-model="newClient.name" label="Nome do cliente" :maxlength="150" class="sm:col-span-4" />
            <AppInput v-model="newClient.nif" label="NIF" inputmode="numeric" :maxlength="20" class="sm:col-span-2" />
            <div class="flex items-end gap-2 sm:col-span-4">
                <AppButton size="sm" :loading="creatingClient" :disabled="!newClient.name.trim()" @click="createClient">Criar e escolher</AppButton>
                <AppButton size="sm" variant="ghost" @click="newClient = null; newClientError = null">Cancelar</AppButton>
            </div>
            <p v-if="newClientError" class="text-sm text-red-600 sm:col-span-6">{{ newClientError }}</p>
            <p class="text-xs text-slate-500 sm:col-span-6">Os contactos e a morada completam-se depois, em Clientes.</p>
        </div>

        <AppSelect v-model="data.type" label="Tipo" :options="PROJECT_TYPE_OPTIONS" placeholder="—" class="sm:col-span-3" :error="errors.type" />
        <AppSelect
            v-model="data.work_calendar_id"
            label="Calendário de trabalho"
            :options="calendarOptions"
            class="sm:col-span-3"
            :error="errors.work_calendar_id"
        />
        <AppInput v-model="data.address" label="Morada da obra" :maxlength="255" class="sm:col-span-6" :error="errors.address" />
        <AppInput v-model="data.postal_code" label="Código postal" :maxlength="20" placeholder="0000-000" class="sm:col-span-2" :error="errors.postal_code" />
        <AppInput v-model="data.city" label="Localidade" :maxlength="100" class="sm:col-span-4" :error="errors.city" />
        <AppInput
            v-model="data.contract_value"
            label="Valor da adjudicação (€, sem IVA)"
            inputmode="numeric"
            placeholder="0,00"
            class="sm:col-span-2"
            :error="errors.contract_value_cents ?? errors.contract_value"
        />
        <AppInput v-model="data.starts_on" label="Início" type="date" class="sm:col-span-2" :error="errors.starts_on" />
        <AppInput v-model="data.planned_end_on" label="Fim previsto" type="date" class="sm:col-span-2" :error="errors.planned_end_on" />
        <AppTextarea v-model="data.description" label="Descrição" :rows="3" :maxlength="5000" class="sm:col-span-6" :error="errors.description" />
    </fieldset>
</template>
