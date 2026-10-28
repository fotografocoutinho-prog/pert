<script setup lang="ts">
import { useId } from 'vue';

defineProps<{ label?: string; error?: string; rows?: number; required?: boolean; maxlength?: number; hint?: string }>();

const model = defineModel<string | null>();
const id = useId();
</script>

<template>
    <div>
        <label v-if="label" :for="id" class="mb-1 block text-sm font-medium text-slate-700">
            {{ label }}<span v-if="required" class="text-red-600"> *</span>
        </label>
        <textarea
            :id="id"
            v-model="model"
            :rows="rows ?? 3"
            :required="required"
            :maxlength="maxlength"
            class="block w-full rounded-lg border px-3 py-2.5 text-base text-slate-900 shadow-sm focus:border-brand-600 focus:ring-2 focus:ring-brand-200 focus:outline-none sm:text-sm"
            :class="error ? 'border-red-500' : 'border-slate-300'"
        />
        <p v-if="error" class="mt-1 text-sm text-red-700">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
    </div>
</template>
