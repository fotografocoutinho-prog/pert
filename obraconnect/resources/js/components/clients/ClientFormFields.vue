<script setup lang="ts">
import { computed } from 'vue';
import { CLIENT_TYPE_OPTIONS, type ClientFormData } from '@/utils/clients';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';

defineProps<{ errors: Record<string, string>; disabled?: boolean }>();
const data = defineModel<ClientFormData>({ required: true });

const isPortugal = computed(() => data.value.country_code.trim().toUpperCase() === 'PT');
</script>

<template>
    <fieldset class="grid gap-4 sm:grid-cols-6" :disabled="disabled">
        <AppSelect v-model="data.type" label="Tipo" :options="CLIENT_TYPE_OPTIONS" required class="sm:col-span-2" :error="errors.type" />
        <AppInput
            v-model="data.name"
            :label="data.type === 'private' ? 'Nome' : 'Nome ou denominação'"
            required
            :maxlength="150"
            class="sm:col-span-4"
            :error="errors.name"
        />
        <AppInput
            v-model="data.nif"
            :label="isPortugal ? 'NIF' : 'N.º fiscal / IVA'"
            :maxlength="20"
            :inputmode="isPortugal ? 'numeric' : 'text'"
            class="sm:col-span-2"
            :error="errors.nif"
        />
        <AppInput v-model="data.country_code" label="País" :maxlength="2" hint="PT, ES, FR…" class="sm:col-span-1" :error="errors.country_code" />
        <AppInput v-model="data.contact_name" label="Pessoa de contacto" :maxlength="150" class="sm:col-span-3" :error="errors.contact_name" />
        <AppInput v-model="data.phone" label="Telefone" type="tel" inputmode="tel" :maxlength="30" class="sm:col-span-2" :error="errors.phone" />
        <AppInput v-model="data.email" label="Email" type="email" inputmode="email" :maxlength="255" class="sm:col-span-4" :error="errors.email" />
        <AppInput v-model="data.address" label="Morada" :maxlength="255" class="sm:col-span-6" :error="errors.address" />
        <AppInput
            v-model="data.postal_code"
            label="Código postal"
            :maxlength="20"
            :placeholder="isPortugal ? '0000-000' : ''"
            class="sm:col-span-2"
            :error="errors.postal_code"
        />
        <AppInput v-model="data.city" label="Localidade" :maxlength="100" class="sm:col-span-4" :error="errors.city" />
        <AppTextarea v-model="data.notes" label="Notas" :rows="3" :maxlength="5000" class="sm:col-span-6" :error="errors.notes" />
    </fieldset>
</template>
