<script setup lang="ts">
withDefaults(defineProps<{ type?: 'info' | 'success' | 'warning' | 'error'; title?: string }>(), { type: 'info' });

const styles = {
    info: 'border-sky-200 bg-sky-50 text-sky-900',
    success: 'border-green-200 bg-green-50 text-green-900',
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
    error: 'border-red-200 bg-red-50 text-red-900',
};
</script>

<template>
    <div :class="['rounded-lg border px-4 py-3 text-sm', styles[type]]" :role="type === 'error' ? 'alert' : 'status'">
        <p v-if="title" class="font-semibold">{{ title }}</p>
        <div :class="title ? 'mt-1' : ''"><slot /></div>
    </div>
</template>
