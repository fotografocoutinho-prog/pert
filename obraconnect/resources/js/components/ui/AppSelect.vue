<script setup lang="ts">
import { useId } from 'vue';

defineProps<{
    label?: string;
    error?: string;
    options: { value: string | number; label: string }[];
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
}>();

const model = defineModel<string | number | null>();
const id = useId();
</script>

<template>
    <div>
        <label v-if="label" :for="id" class="mb-1 block text-sm font-medium text-slate-700">
            {{ label }}<span v-if="required" class="text-red-600"> *</span>
        </label>
        <select
            :id="id"
            v-model="model"
            :required="required"
            :disabled="disabled"
            class="block w-full rounded-lg border bg-white px-3 py-2.5 text-base text-slate-900 shadow-sm focus:border-brand-600 focus:ring-2 focus:ring-brand-200 focus:outline-none sm:text-sm"
            :class="error ? 'border-red-500' : 'border-slate-300'"
        >
            <option v-if="placeholder" :value="null" disabled>{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <p v-if="error" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
