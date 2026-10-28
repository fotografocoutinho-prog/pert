<script setup lang="ts">
import BrandMark from './BrandMark.vue';

defineProps<{ title: string; subtitle?: string }>();
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <RouterLink to="/" class="mb-8"><BrandMark /></RouterLink>
        <main class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <h1 class="text-xl font-bold text-slate-900">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-sm text-slate-600">{{ subtitle }}</p>
            <div class="mt-6"><slot /></div>
        </main>
        <div class="mt-6 text-center text-sm text-slate-600"><slot name="footer" /></div>
    </div>
</template>
