<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { projectsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Project, ProjectMember } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const props = defineProps<{ project: Project }>();
const emit = defineEmits<{ changed: [] }>();

const session = useSessionStore();
const toast = useToastStore();

type Options = Awaited<ReturnType<typeof projectsApi.memberOptions>>;

const members = ref<ProjectMember[]>([]);
const options = ref<Options>({ members: [], roles: [] });
const canManage = computed(() => props.project.permissions.includes('projects.team') && props.project.status !== 'archived' && !session.isReadOnly);
const assignable = computed(() => new Set(options.value.roles.map((r) => r.id)));

async function load(): Promise<void> {
    const [list, opts] = await Promise.all([
        projectsApi.members(props.project.id),
        canManage.value ? projectsApi.memberOptions(props.project.id) : Promise.resolve(options.value),
    ]);
    members.value = list;
    options.value = opts;
}

onMounted(load);

// Adicionar
const addOpen = ref(false);
const form = useForm({ membership_id: null as number | null, role_id: null as number | null });
const memberOptions = computed(() =>
    options.value.members.map((m) => ({ value: m.membership_id, label: `${m.name} — ${m.company_role.name}` })),
);
const roleOptions = computed(() => options.value.roles.map((r) => ({ value: r.id, label: r.name })));

function openAdd(): void {
    form.reset();
    addOpen.value = true;
}

/** Por omissão, o perfil na obra é o da empresa (se o puder atribuir). */
function onPickMember(id: string | number | null | undefined): void {
    form.data.membership_id = id === null || id === undefined || id === '' ? null : Number(id);
    const picked = options.value.members.find((m) => m.membership_id === form.data.membership_id);
    form.data.role_id = picked && assignable.value.has(picked.company_role.id) ? picked.company_role.id : null;
}

async function add(): Promise<void> {
    const added = await form.submit(
        (d) => projectsApi.addMember(props.project.id, Number(d.membership_id), d.role_id ? Number(d.role_id) : null),
        { success: 'Adicionado à equipa.' },
    );
    if (added) {
        addOpen.value = false;
        await load();
        emit('changed');
    }
}

async function changeRole(member: ProjectMember, roleId: number): Promise<void> {
    await run(() => projectsApi.updateMember(props.project.id, member.id, roleId), 'Perfil na obra alterado.');
}

async function remove(member: ProjectMember): Promise<void> {
    if (!window.confirm(`Retirar ${member.user.name} da equipa desta obra?`)) return;
    await run(() => projectsApi.removeMember(props.project.id, member.id), 'Retirado da equipa.');
    emit('changed');
}

async function run(action: () => Promise<unknown>, success: string): Promise<void> {
    try {
        await action();
        toast.success(success);
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Operação falhou.');
    }
    await load();
}
</script>

<template>
    <div>
        <AppCard title="Equipa da obra" description="Quem trabalha nesta obra e com que perfil. O perfil na obra pode ser diferente do perfil na empresa.">
            <div v-if="canManage" class="mb-4">
                <AppButton :disabled="options.members.length === 0" @click="openAdd">Adicionar à equipa</AppButton>
                <p v-if="options.members.length === 0" class="mt-2 text-xs text-slate-500">
                    Todos os utilizadores ativos da empresa já estão nesta equipa. Para chamar alguém de fora (subempreiteiro,
                    fiscalização, dono de obra), convide-o primeiro em “Utilizadores”.
                </p>
            </div>

            <EmptyState v-if="members.length === 0" title="Ninguém na equipa" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="member in members" :key="member.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900">
                            {{ member.user.name }} <span v-if="member.is_me" class="text-xs text-slate-400">(eu)</span>
                        </p>
                        <p class="text-sm text-slate-500">{{ member.user.email }} · na empresa: {{ member.company_role.name }}</p>
                        <AppBadge v-if="member.membership_status !== 'active'" color="red" class="mt-1">Suspenso na empresa</AppBadge>
                    </div>
                    <div class="flex items-center gap-2">
                        <select
                            v-if="canManage && assignable.has(member.role.id)"
                            :value="member.role.id"
                            class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm"
                            :aria-label="`Perfil de ${member.user.name} nesta obra`"
                            @change="changeRole(member, Number(($event.target as HTMLSelectElement).value))"
                        >
                            <option v-for="role in options.roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                        </select>
                        <AppBadge v-else color="brand">{{ member.role.name }}</AppBadge>
                        <AppButton v-if="canManage && assignable.has(member.role.id)" size="sm" variant="ghost" class="text-red-700" @click="remove(member)">
                            Retirar
                        </AppButton>
                    </div>
                </li>
            </ul>
        </AppCard>

        <AppModal :open="addOpen" title="Adicionar à equipa" @close="addOpen = false">
            <form id="team-form" class="space-y-4" novalidate @submit.prevent="add">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <AppSelect
                    :model-value="form.data.membership_id"
                    label="Pessoa"
                    :options="memberOptions"
                    placeholder="Escolha um utilizador"
                    required
                    :error="form.errors.value.membership_id"
                    @update:model-value="onPickMember"
                />
                <AppSelect v-model="form.data.role_id" label="Perfil nesta obra" :options="roleOptions" placeholder="Escolha um perfil" required :error="form.errors.value.role_id" />
                <p class="text-xs text-slate-500">Só pode atribuir perfis com permissões que também tem.</p>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="addOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="team-form" :loading="form.processing.value" :disabled="!form.data.membership_id || !form.data.role_id">
                    Adicionar
                </AppButton>
            </template>
        </AppModal>
    </div>
</template>
