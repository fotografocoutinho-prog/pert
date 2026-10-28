<script setup lang="ts">
import { useToastStore } from '@/stores/toast';

const toasts = useToastStore();
const styles = { success: 'bg-green-700', error: 'bg-red-700', info: 'bg-slate-800' };
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4" aria-live="polite">
        <div
            v-for="toast in toasts.items"
            :key="toast.id"
            :class="['pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-lg px-4 py-3 text-sm text-white shadow-lg', styles[toast.type]]"
        >
            <span class="flex-1">{{ toast.message }}</span>
            <button class="opacity-80 hover:opacity-100" aria-label="Fechar" @click="toasts.dismiss(toast.id)">✕</button>
        </div>
    </div>
</template>
