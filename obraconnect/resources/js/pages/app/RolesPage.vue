<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { rolesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { PermissionModule, Role } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();
const roles = ref<Role[]>([]);
const modules = ref<PermissionModule[]>([]);
const selectedId = ref<number | 'new' | null>(null);
const canManage = computed(() => session.can('roles.manage') && !session.isReadOnly);
const myPermissions = computed(() => new Set(session.current?.permissions ?? []));

const selected = computed(() => roles.value.find((r) => r.id === selectedId.value) ?? null);
const editable = computed(() => canManage.value && (selectedId.value === 'new' || (selected.value !== null && !selected.value.is_locked)));

const form = useForm({ name: '', description: '', permissions: [] as string[] });

async function load(): Promise<void> {
    [roles.value, modules.value] = await Promise.all([rolesApi.list(), rolesApi.permissions()]);
}

onMounted(async () => {
    await load();
    select(roles.value[0]?.id ?? null);
});

function select(id: number | 'new' | null): void {
    selectedId.value = id;
    const role = roles.value.find((r) => r.id === id);
    form.reset({ name: role?.name ?? '', description: role?.description ?? '', permissions: [...(role?.permissions ?? [])] });
}

function toggle(key: string, checked: boolean): void {
    const set = new Set(form.data.permissions);
    if (checked) set.add(key);
    else set.delete(key);
    form.data.permissions = [...set];
}

async function save(): Promise<void> {
    const payload = { name: form.data.name, description: form.data.description || null, permissions: form.data.permissions };
    const role = await form.submit(
        () => (selectedId.value === 'new' ? rolesApi.create(payload) : rolesApi.update(Number(selectedId.value), payload)),
        { success: 'Perfil guardado.' },
    );
    if (role) {
        await load();
        select(role.id);
        await session.refresh();
    }
}

async function reset(): Promise<void> {
    if (!selected.value || !window.confirm('Repor as permissões originais deste perfil?')) return;
    try {
        const role = await rolesApi.reset(selected.value.id);
        await load();
        select(role.id);
        toast.success('Perfil reposto.');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Falhou.');
    }
}

async function remove(): Promise<void> {
    if (!selected.value || !window.confirm(`Apagar o perfil "${selected.value.name}"?`)) return;
    try {
        await rolesApi.remove(selected.value.id);
        await load();
        select(roles.value[0]?.id ?? null);
        toast.success('Perfil apagado.');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Falhou.');
    }
}
</script>

<template>
    <div>
        <PageHeader title="Perfis e permissões" description="Cada utilizador tem um perfil. As permissões definem o que pode ver e fazer.">
            <AppButton v-if="canManage" @click="select('new')">Novo perfil</AppButton>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <AppCard title="Perfis">
                <ul class="-mx-2 space-y-1">
                    <li v-for="role in roles" :key="role.id">
                        <button
                            :class="['flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm', selectedId === role.id ? 'bg-brand-50 text-brand-900' : 'hover:bg-slate-50']"
                            @click="select(role.id)"
                        >
                            <span class="font-medium">{{ role.name }}</span>
                            <span class="flex items-center gap-2">
                                <AppBadge v-if="role.is_locked" color="gray">Bloqueado</AppBadge>
                                <span class="text-xs text-slate-500">{{ role.members_count ?? 0 }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </AppCard>

            <AppCard v-if="selectedId !== null" :title="selectedId === 'new' ? 'Novo perfil' : selected?.name" class="lg:col-span-2">
                <form class="space-y-5" novalidate @submit.prevent="save">
                    <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                    <AppAlert v-if="selected?.is_locked" type="info">O perfil de administrador tem sempre todas as permissões e não pode ser alterado.</AppAlert>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppInput v-model="form.data.name" label="Nome" required :disabled="!editable" :error="form.errors.value.name" />
                        <AppInput v-model="form.data.description" label="Descrição" :disabled="!editable" />
                    </div>

                    <div v-for="mod in modules" :key="mod.module">
                        <h3 class="text-sm font-semibold text-slate-900">{{ mod.label }}</h3>
                        <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                            <li v-for="permission in mod.permissions" :key="permission.key">
                                <label class="flex items-start gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 h-4 w-4 accent-brand-700"
                                        :checked="form.data.permissions.includes(permission.key)"
                                        :disabled="!editable || !myPermissions.has(permission.key)"
                                        @change="toggle(permission.key, ($event.target as HTMLInputElement).checked)"
                                    />
                                    <span>{{ permission.label }} <code class="text-xs text-slate-400">{{ permission.key }}</code></span>
                                </label>
                            </li>
                        </ul>
                    </div>
                    <p class="text-xs text-slate-500">Só pode conceder ou retirar permissões que também tem. As permissões por obra chegam com o módulo de obras.</p>

                    <div v-if="editable" class="flex flex-wrap gap-2">
                        <AppButton type="submit" :loading="form.processing.value">Guardar perfil</AppButton>
                        <AppButton v-if="selected?.template_key" variant="secondary" @click="reset">Repor modelo</AppButton>
                        <AppButton v-if="selected && !selected.is_locked" variant="ghost" class="text-red-700" @click="remove">Apagar</AppButton>
                    </div>
                </form>
            </AppCard>
        </div>
    </div>
</template>
