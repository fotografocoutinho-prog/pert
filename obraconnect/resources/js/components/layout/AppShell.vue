<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useSessionStore } from '@/stores/session';
import { useSyncStore } from '@/stores/sync';
import { useToastStore } from '@/stores/toast';
import { platformApi } from '@/api';
import { daysUntil, formatDateTime } from '@/utils/format';
import BrandMark from './BrandMark.vue';
import TenantSwitcher from './TenantSwitcher.vue';

interface NavItem {
    to: string;
    label: string;
    show: boolean;
}

const session = useSessionStore();
const sync = useSyncStore();
onMounted(() => sync.start());
const route = useRoute();
const router = useRouter();
const menuOpen = ref(false);

watch(() => route.fullPath, () => (menuOpen.value = false));

const anyOf = (...permissions: string[]) => permissions.some((p) => session.can(p));
const tenantNav = computed<NavItem[]>(() => [
    { to: '/app', label: 'Painel', show: true },
    { to: '/app/obras', label: 'Obras', show: session.can('projects.view') },
    { to: '/app/clientes', label: 'Clientes', show: session.can('clients.view') },
    { to: '/app/financas', label: 'Painel financeiro', show: session.can('finance.view') },
    { to: '/app/faturacao', label: 'Faturação', show: anyOf('invoicing.view', 'invoicing.manage') },
    { to: '/app/faturas-fornecedores', label: 'Faturas de fornecedores', show: session.can('supplier_docs.manage') },
    { to: '/app/precos', label: 'Biblioteca de preços', show: anyOf('budgets.view', 'budgets.manage') },
    { to: '/app/fornecedores', label: 'Fornecedores e subempreiteiros', show: anyOf('purchases.manage', 'subcontractors.manage', 'costs.view', 'workforce.manage') },
    { to: '/app/trabalhadores', label: 'Trabalhadores', show: anyOf('workforce.view', 'workforce.manage', 'time.register_team', 'time.approve') },
    { to: '/app/equipamentos', label: 'Equipamentos', show: anyOf('equipment.manage', 'costs.view', 'workforce.view') },
    { to: '/app/calendarios', label: 'Calendários de trabalho', show: session.can('company.view') },
    { to: '/app/empresa', label: 'Empresa', show: session.can('company.view') },
    { to: '/app/organizacao', label: 'Organização', show: session.can('company.view') },
    { to: '/app/listas-verificacao', label: 'Listas de verificação', show: session.can('checklists.manage') },
    { to: '/app/utilizadores', label: 'Utilizadores', show: session.can('members.view') },
    { to: '/app/perfis', label: 'Perfis e permissões', show: session.can('roles.view') },
    { to: '/app/auditoria', label: 'Auditoria', show: session.can('audit.view') },
    { to: '/app/subscricao', label: 'Subscrição', show: session.can('subscription.view') },
    { to: '/app/suporte', label: 'Suporte técnico', show: session.can('support.manage') },
    { to: '/app/exportacoes', label: 'Exportação de dados', show: session.can('data.export') },
]);

const platformNav = computed<NavItem[]>(() => [
    { to: '/plataforma', label: 'Painel da plataforma', show: session.isPlatformStaff },
    { to: '/plataforma/empresas', label: 'Empresas', show: session.isPlatformStaff },
    { to: '/plataforma/planos', label: 'Planos e preços', show: session.hasPlatformRole('billing') },
    { to: '/plataforma/suporte', label: 'Pedidos de suporte', show: session.hasPlatformRole('support') },
    { to: '/plataforma/seguranca', label: 'Logs de segurança', show: session.hasPlatformRole('support') },
]);

const hasTenantContext = computed(() => session.current !== null || session.supportSession !== null);

const trialDays = computed(() => {
    const sub = session.current?.subscription;
    return sub?.status === 'trialing' ? daysUntil(sub.trial_ends_at) : null;
});

function isActive(to: string): boolean {
    return to === '/app' || to === '/plataforma' ? route.path === to : route.path.startsWith(to);
}

async function leaveSupport(): Promise<void> {
    await platformApi.leaveSupport();
    await session.refresh();
    useToastStore().info('Saiu do modo de suporte.');
    await router.push('/plataforma/suporte');
}

async function logout(): Promise<void> {
    await sync.refresh();
    if (sync.pending.length && !window.confirm(`Há ${sync.pending.length} registo(s) do diário guardados neste telemóvel por enviar. Ficam guardados e são enviados quando voltar a entrar. Sair?`)) {
        return;
    }
    await session.logout();
    await router.push('/entrar');
}
</script>

<template>
    <div class="min-h-screen lg:flex">
        <!-- Barra lateral -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-40 w-72 transform border-r border-slate-200 bg-white transition-transform lg:static lg:translate-x-0',
                menuOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div class="flex h-16 items-center border-b border-slate-100 px-5">
                <RouterLink to="/app"><BrandMark /></RouterLink>
            </div>
            <nav class="space-y-6 overflow-y-auto px-3 py-5" aria-label="Navegação principal">
                <div v-if="hasTenantContext">
                    <p class="px-3 text-xs font-semibold tracking-wide text-slate-400 uppercase">Empresa</p>
                    <ul class="mt-2 space-y-1">
                        <li v-for="item in tenantNav.filter((i) => i.show)" :key="item.to">
                            <RouterLink
                                :to="item.to"
                                :class="[
                                    'block rounded-lg px-3 py-2 text-sm font-medium',
                                    isActive(item.to) ? 'bg-brand-50 text-brand-800' : 'text-slate-700 hover:bg-slate-100',
                                ]"
                            >
                                {{ item.label }}
                            </RouterLink>
                        </li>
                    </ul>
                </div>
                <div v-if="session.isPlatformStaff">
                    <p class="px-3 text-xs font-semibold tracking-wide text-slate-400 uppercase">Plataforma</p>
                    <ul class="mt-2 space-y-1">
                        <li v-for="item in platformNav.filter((i) => i.show)" :key="item.to">
                            <RouterLink
                                :to="item.to"
                                :class="[
                                    'block rounded-lg px-3 py-2 text-sm font-medium',
                                    isActive(item.to) ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100',
                                ]"
                            >
                                {{ item.label }}
                            </RouterLink>
                        </li>
                    </ul>
                </div>
                <div>
                    <p class="px-3 text-xs font-semibold tracking-wide text-slate-400 uppercase">Conta</p>
                    <ul class="mt-2 space-y-1">
                        <li>
                            <RouterLink
                                to="/app/conta"
                                :class="['block rounded-lg px-3 py-2 text-sm font-medium', isActive('/app/conta') ? 'bg-brand-50 text-brand-800' : 'text-slate-700 hover:bg-slate-100']"
                            >
                                A minha conta
                            </RouterLink>
                        </li>
                        <li>
                            <button class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-100" @click="logout">Sair</button>
                        </li>
                    </ul>
                </div>
            </nav>
        </aside>
        <div v-if="menuOpen" class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" @click="menuOpen = false" />

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6">
                <button class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Abrir menu" @click="menuOpen = true">
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                </button>
                <div class="min-w-0 flex-1"><TenantSwitcher /></div>
                <button
                    v-if="!sync.online || sync.pending.length"
                    type="button"
                    class="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                    :class="sync.conflicts.length ? 'bg-red-100 text-red-800' : !sync.online ? 'bg-slate-800 text-white' : 'bg-amber-100 text-amber-900'"
                    :title="sync.conflicts.length ? 'Há registos que não foi possível enviar' : 'Enviar agora'"
                    @click="sync.sync()"
                >
                    <span v-if="!sync.online">Sem rede</span>
                    <span v-if="sync.waiting.length">{{ sync.syncing ? 'A enviar…' : `${sync.waiting.length} por enviar` }}</span>
                    <span v-if="sync.conflicts.length">{{ sync.conflicts.length }} com problema</span>
                </button>
                <span class="hidden text-sm text-slate-600 sm:inline">{{ session.user?.name }}</span>
            </header>
            <div v-if="session.offlineSession" class="bg-slate-800 px-4 py-2 text-sm text-white sm:px-6">
                Sem ligação ao servidor: está a ver a última cópia guardada neste telemóvel. O que escrever no diário fica guardado e é enviado quando houver rede.
            </div>

            <!-- Avisos de contexto -->
            <div v-if="session.supportSession" class="bg-slate-900 px-4 py-2 text-sm text-white sm:px-6">
                Modo de suporte (só leitura) em <strong>{{ session.supportSession.tenant.name }}</strong> até {{ formatDateTime(session.supportSession.expires_at) }}.
                <button class="ml-2 underline" @click="leaveSupport">Sair do modo de suporte</button>
            </div>
            <div v-else-if="session.current?.tenant.status === 'read_only'" class="bg-amber-100 px-4 py-2 text-sm text-amber-900 sm:px-6">
                A empresa está em <strong>só leitura</strong> porque a subscrição expirou. Pode consultar e exportar os dados.
            </div>
            <div v-else-if="trialDays !== null && trialDays <= 7" class="bg-brand-50 px-4 py-2 text-sm text-brand-900 sm:px-6">
                O período experimental termina dentro de {{ Math.max(trialDays, 0) }} dia(s).
            </div>

            <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6 lg:py-8">
                <!-- Ao mudar de empresa, as páginas montam-se de novo (os dados são de outra empresa). -->
                <RouterView :key="session.current?.tenant.id ?? 0" />
            </main>
        </div>
    </div>
</template>
