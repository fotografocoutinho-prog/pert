<script setup lang="ts">
defineProps<{ page: number; lastPage: number; total?: number }>();
const emit = defineEmits<{ change: [page: number] }>();
</script>

<template>
    <nav v-if="lastPage > 1" class="mt-4 flex items-center justify-between text-sm" aria-label="Paginação">
        <span class="text-slate-500">Página {{ page }} de {{ lastPage }}<template v-if="total"> · {{ total }} registos</template></span>
        <div class="flex gap-2">
            <button class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-50" :disabled="page <= 1" @click="emit('change', page - 1)">Anterior</button>
            <button class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-50" :disabled="page >= lastPage" @click="emit('change', page + 1)">Seguinte</button>
        </div>
    </nav>
</template>
