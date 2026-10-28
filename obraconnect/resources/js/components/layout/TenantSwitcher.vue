<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { ApiError } from '@/api/http';

const session = useSessionStore();
const router = useRouter();
const switching = ref(false);

const options = computed(() => session.tenants);
const currentId = computed(() => session.current?.tenant.id ?? null);

async function change(event: Event): Promise<void> {
    const id = Number((event.target as HTMLSelectElement).value);
    if (!id || id === currentId.value) return;
    switching.value = true;
    try {
        await session.switchTenant(id);
        await router.push('/app');
        useToastStore().success(`Empresa ativa: ${session.current?.tenant.name ?? ''}`);
    } catch (error) {
        useToastStore().error(error instanceof ApiError ? error.message : 'Não foi possível mudar de empresa.');
    } finally {
        switching.value = false;
    }
}
</script>

<template>
    <div v-if="options.length > 1">
        <label for="tenant-switcher" class="sr-only">Empresa ativa</label>
        <select
            id="tenant-switcher"
            :value="currentId ?? ''"
            :disabled="switching"
            class="w-full max-w-56 rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm"
            @change="change"
        >
            <option v-for="tenant in options" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option>
        </select>
    </div>
    <span v-else-if="session.current" class="truncate text-sm font-medium text-slate-700">{{ session.current.tenant.name }}</span>
</template>
