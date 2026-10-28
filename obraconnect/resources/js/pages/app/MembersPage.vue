<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { invitationsApi, membersApi, rolesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Invitation, Member, Role } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatDateTime } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppPagination from '@/components/ui/AppPagination.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();

const members = ref<Member[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const invitations = ref<Invitation[]>([]);
const roles = ref<Role[]>([]);
const search = ref('');
const page = ref(1);

const canInvite = computed(() => session.can('members.invite') && !session.isReadOnly);
const canManage = computed(() => session.can('members.manage') && !session.isReadOnly);
// Só perfis cujas permissões o utilizador também tem (o servidor aplica a mesma regra).
const assignableRoles = computed(() => {
    const mine = new Set(session.current?.permissions ?? []);
    return roles.value.filter((r) => r.permissions.every((p) => mine.has(p)));
});
const roleOptions = computed(() => assignableRoles.value.map((r) => ({ value: r.id, label: r.name })));

async function load(): Promise<void> {
    const result = await membersApi.list({ search: search.value || undefined, page: page.value });
    members.value = result.data;
    meta.value = result.meta;
}

async function loadInvitations(): Promise<void> {
    invitations.value = (await invitationsApi.list('pending')).data;
}

onMounted(async () => {
    const needsRoles = session.can('roles.view') || canInvite.value || canManage.value;
    await Promise.all([load(), loadInvitations(), needsRoles ? rolesApi.list().then((r) => (roles.value = r)) : null]);
});

let timer: number | undefined;
watch(search, () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
        page.value = 1;
        load();
    }, 300);
});

// Convites
const inviteOpen = ref(false);
const inviteForm = useForm({ email: '', role_id: null as number | null });

function openInvite(): void {
    inviteForm.reset({ role_id: assignableRoles.value.find((r) => r.key === 'trabalhador')?.id ?? null });
    inviteOpen.value = true;
}

async function sendInvite(): Promise<void> {
    const created = await inviteForm.submit((d) => invitationsApi.create(d.email, Number(d.role_id)), { success: 'Convite enviado.' });
    if (created) {
        inviteOpen.value = false;
        await loadInvitations();
    }
}

async function resend(invitation: Invitation): Promise<void> {
    await run(() => invitationsApi.resend(invitation.id), 'Convite reenviado.');
    await loadInvitations();
}

async function revoke(invitation: Invitation): Promise<void> {
    if (!window.confirm(`Anular o convite para ${invitation.email}?`)) return;
    await run(() => invitationsApi.revoke(invitation.id), 'Convite anulado.');
    await loadInvitations();
}

// Membros
async function changeRole(member: Member, roleId: number): Promise<void> {
    await run(() => membersApi.update(member.id, { role_id: roleId }), 'Perfil alterado.');
    await load();
}

async function toggleStatus(member: Member): Promise<void> {
    const status = member.status === 'active' ? 'suspended' : 'active';
    await run(() => membersApi.update(member.id, { status }), status === 'active' ? 'Utilizador reativado.' : 'Utilizador suspenso.');
    await load();
}

async function remove(member: Member): Promise<void> {
    if (!window.confirm(`Remover ${member.user.name} da empresa? A conta pessoal não é apagada.`)) return;
    await run(() => membersApi.remove(member.id), 'Utilizador removido.');
    await load();
}

async function run(action: () => Promise<unknown>, success: string): Promise<void> {
    try {
        await action();
        toast.success(success);
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Operação falhou.');
    }
}
</script>

<template>
    <div>
        <PageHeader title="Utilizadores" description="Pessoas com acesso à empresa e respetivos perfis.">
            <AppButton v-if="canInvite" @click="openInvite">Convidar utilizador</AppButton>
        </PageHeader>

        <AppCard>
            <AppInput v-model="search" placeholder="Pesquisar por nome ou email" class="mb-4 max-w-sm" />

            <EmptyState v-if="members.length === 0" title="Sem resultados" />
            <div v-else class="-mx-5 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-3">Nome</th>
                            <th class="px-5 py-3">Perfil</th>
                            <th class="px-5 py-3">Estado</th>
                            <th class="hidden px-5 py-3 md:table-cell">Último acesso</th>
                            <th v-if="canManage" class="px-5 py-3"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="member in members" :key="member.id">
                            <td class="px-5 py-3">
                                <p class="font-medium text-slate-900">{{ member.user.name }} <span v-if="member.is_me" class="text-xs text-slate-400">(eu)</span></p>
                                <p class="text-slate-500">{{ member.user.email }}</p>
                                <AppBadge v-if="!member.user.two_factor_enabled" color="amber" class="mt-1">Sem 2FA</AppBadge>
                            </td>
                            <td class="px-5 py-3">
                                <select
                                    v-if="canManage && roles.length"
                                    :value="member.role.id"
                                    class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm"
                                    :aria-label="`Perfil de ${member.user.name}`"
                                    @change="changeRole(member, Number(($event.target as HTMLSelectElement).value))"
                                >
                                    <option v-for="role in assignableRoles" :key="role.id" :value="role.id">{{ role.name }}</option>
                                    <option v-if="!assignableRoles.some((r) => r.id === member.role.id)" :value="member.role.id" disabled>{{ member.role.name }}</option>
                                </select>
                                <span v-else>{{ member.role.name }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <AppBadge :color="member.status === 'active' ? 'green' : 'red'">{{ member.status_label }}</AppBadge>
                            </td>
                            <td class="hidden px-5 py-3 text-slate-500 md:table-cell">{{ formatDateTime(member.user.last_login_at) }}</td>
                            <td v-if="canManage" class="px-5 py-3 text-right whitespace-nowrap">
                                <template v-if="!member.is_me">
                                    <AppButton size="sm" variant="ghost" @click="toggleStatus(member)">{{ member.status === 'active' ? 'Suspender' : 'Reativar' }}</AppButton>
                                    <AppButton size="sm" variant="ghost" class="text-red-700" @click="remove(member)">Remover</AppButton>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <AppPagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="(p) => { page = p; load(); }" />
        </AppCard>

        <AppCard title="Convites pendentes" class="mt-4">
            <EmptyState v-if="invitations.length === 0" title="Nenhum convite pendente" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="invitation in invitations" :key="invitation.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div>
                        <p class="font-medium">{{ invitation.email }}</p>
                        <p class="text-sm text-slate-500">{{ invitation.role.name }} · expira a {{ formatDate(invitation.expires_at) }}</p>
                    </div>
                    <div v-if="canInvite" class="flex gap-2">
                        <AppButton size="sm" variant="secondary" @click="resend(invitation)">Reenviar</AppButton>
                        <AppButton size="sm" variant="ghost" class="text-red-700" @click="revoke(invitation)">Anular</AppButton>
                    </div>
                </li>
            </ul>
        </AppCard>

        <AppModal :open="inviteOpen" title="Convidar utilizador" @close="inviteOpen = false">
            <form id="invite-form" class="space-y-4" novalidate @submit.prevent="sendInvite">
                <AppAlert v-if="inviteForm.message.value" type="error">{{ inviteForm.message.value }}</AppAlert>
                <AppInput v-model="inviteForm.data.email" label="Email" type="email" required :error="inviteForm.errors.value.email" />
                <AppSelect v-model="inviteForm.data.role_id" label="Perfil" :options="roleOptions" placeholder="Escolha um perfil" required :error="inviteForm.errors.value.role_id" />
                <p class="text-xs text-slate-500">O convite é válido durante 7 dias. Só pode atribuir perfis com permissões que também tem.</p>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="inviteOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="invite-form" :loading="inviteForm.processing.value">Enviar convite</AppButton>
            </template>
        </AppModal>
    </div>
</template>
