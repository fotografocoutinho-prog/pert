<script setup lang="ts">
import { useId } from 'vue';

withDefaults(
    defineProps<{
        label?: string;
        type?: string;
        error?: string;
        hint?: string;
        required?: boolean;
        autocomplete?: string;
        placeholder?: string;
        inputmode?: 'text' | 'numeric' | 'email' | 'tel' | 'url';
        maxlength?: number;
        disabled?: boolean;
    }>(),
    { type: 'text' },
);

const model = defineModel<string | number | null>();
const id = useId();
</script>

<template>
    <div>
        <label v-if="label" :for="id" class="mb-1 block text-sm font-medium text-slate-700">
            {{ label }}<span v-if="required" class="text-red-600"> *</span>
        </label>
        <input
            :id="id"
            v-model="model"
            :type="type"
            :required="required"
            :autocomplete="autocomplete"
            :placeholder="placeholder"
            :inputmode="inputmode"
            :maxlength="maxlength"
            :disabled="disabled"
            :aria-invalid="Boolean(error)"
            :aria-describedby="error || hint ? `${id}-help` : undefined"
            class="block w-full rounded-lg border px-3 py-2.5 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-200 focus:outline-none disabled:bg-slate-100 sm:text-sm"
            :class="error ? 'border-red-500' : 'border-slate-300'"
        />
        <p v-if="error" :id="`${id}-help`" class="mt-1 text-sm text-red-700">{{ error }}</p>
        <p v-else-if="hint" :id="`${id}-help`" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
    </div>
</template>
