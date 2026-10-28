<script setup lang="ts">
import { useId } from 'vue';

defineProps<{ label?: string; error?: string; disabled?: boolean; description?: string }>();

const model = defineModel<boolean>();
const id = useId();
</script>

<template>
    <div>
        <label :for="id" class="flex cursor-pointer items-start gap-3">
            <input
                :id="id"
                v-model="model"
                type="checkbox"
                :disabled="disabled"
                class="mt-0.5 h-5 w-5 rounded border-slate-300 text-brand-700 accent-brand-700 focus:ring-brand-500"
            />
            <span class="text-sm text-slate-700">
                <slot>{{ label }}</slot>
                <span v-if="description" class="block text-xs text-slate-500">{{ description }}</span>
            </span>
        </label>
        <p v-if="error" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
