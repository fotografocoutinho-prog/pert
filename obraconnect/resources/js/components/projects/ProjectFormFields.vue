<script setup lang="ts">
import { PROJECT_TYPE_OPTIONS, type ProjectFormData } from '@/utils/projects';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';

defineProps<{ errors: Record<string, string>; disabled?: boolean }>();
const data = defineModel<ProjectFormData>({ required: true });
</script>

<template>
    <fieldset class="grid gap-4 sm:grid-cols-6" :disabled="disabled">
        <AppInput v-model="data.name" label="Nome da obra" required :maxlength="150" class="sm:col-span-4" :error="errors.name" />
        <AppInput v-model="data.code" label="Código" :maxlength="30" placeholder="Automático" class="sm:col-span-2" :error="errors.code" />
        <AppSelect v-model="data.type" label="Tipo" :options="PROJECT_TYPE_OPTIONS" placeholder="—" class="sm:col-span-3" :error="errors.type" />
        <AppInput v-model="data.owner_name" label="Dono de obra" :maxlength="150" class="sm:col-span-3" :error="errors.owner_name" />
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
