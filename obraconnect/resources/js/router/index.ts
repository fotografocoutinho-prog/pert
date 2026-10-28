import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { onApiError } from '@/api/http';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import AppShell from '@/components/layout/AppShell.vue';

declare module 'vue-router' {
    interface RouteMeta {
        guest?: boolean;
        auth?: boolean;
        tenant?: boolean;
        permission?: string;
        platform?: boolean;
        platformRoles?: string[];
        title?: string;
    }
}

const routes: RouteRecordRaw[] = [
    { path: '/', redirect: '/app' },

    // Autenticação
    { path: '/entrar', component: () => import('@/pages/auth/LoginPage.vue'), meta: { guest: true, title: 'Entrar' } },
    { path: '/registar', component: () => import('@/pages/auth/RegisterPage.vue'), meta: { guest: true, title: 'Criar conta' } },
    { path: '/recuperar-password', component: () => import('@/pages/auth/ForgotPasswordPage.vue'), meta: { guest: true, title: 'Recuperar password' } },
    { path: '/repor-password/:token', component: () => import('@/pages/auth/ResetPasswordPage.vue'), meta: { guest: true, title: 'Repor password' } },
    { path: '/2fa', component: () => import('@/pages/auth/TwoFactorChallengePage.vue'), meta: { guest: true, title: 'Verificação em dois passos' } },
    { path: '/verificar-email', component: () => import('@/pages/auth/VerifyEmailPage.vue'), meta: { auth: true, title: 'Confirmar email' } },
    { path: '/convite/:token', component: () => import('@/pages/auth/InvitationPage.vue'), meta: { title: 'Convite' } },

    // Aplicação da empresa
    {
        path: '/app',
        component: AppShell,
        meta: { auth: true },
        children: [
            { path: '', component: () => import('@/pages/app/DashboardPage.vue'), meta: { tenant: true, title: 'Painel' } },
            { path: 'empresa', component: () => import('@/pages/app/CompanyPage.vue'), meta: { tenant: true, permission: 'company.view', title: 'Empresa' } },
            { path: 'utilizadores', component: () => import('@/pages/app/MembersPage.vue'), meta: { tenant: true, permission: 'members.view', title: 'Utilizadores' } },
            { path: 'perfis', component: () => import('@/pages/app/RolesPage.vue'), meta: { tenant: true, permission: 'roles.view', title: 'Perfis e permissões' } },
            { path: 'auditoria', component: () => import('@/pages/app/AuditPage.vue'), meta: { tenant: true, permission: 'audit.view', title: 'Auditoria' } },
            { path: 'subscricao', component: () => import('@/pages/app/SubscriptionPage.vue'), meta: { tenant: true, permission: 'subscription.view', title: 'Subscrição' } },
            { path: 'suporte', component: () => import('@/pages/app/SupportAccessPage.vue'), meta: { tenant: true, permission: 'support.manage', title: 'Suporte técnico' } },
            { path: 'exportacoes', component: () => import('@/pages/app/ExportsPage.vue'), meta: { tenant: true, permission: 'data.export', title: 'Exportação de dados' } },
            { path: 'conta', component: () => import('@/pages/app/AccountPage.vue'), meta: { title: 'A minha conta' } },
            { path: 'sem-empresa', component: () => import('@/pages/app/NoCompanyPage.vue'), meta: { title: 'Sem empresa' } },
        ],
    },

    // Backoffice da plataforma
    {
        path: '/plataforma',
        component: AppShell,
        meta: { auth: true, platform: true },
        children: [
            { path: '', component: () => import('@/pages/platform/PlatformDashboardPage.vue'), meta: { title: 'Plataforma' } },
            { path: 'empresas', component: () => import('@/pages/platform/PlatformTenantsPage.vue'), meta: { title: 'Empresas' } },
            { path: 'empresas/:id', component: () => import('@/pages/platform/PlatformTenantPage.vue'), meta: { title: 'Empresa' } },
            { path: 'planos', component: () => import('@/pages/platform/PlatformPlansPage.vue'), meta: { platformRoles: ['billing'], title: 'Planos' } },
            { path: 'suporte', component: () => import('@/pages/platform/PlatformSupportPage.vue'), meta: { platformRoles: ['support'], title: 'Suporte' } },
            { path: 'seguranca', component: () => import('@/pages/platform/PlatformSecurityLogsPage.vue'), meta: { platformRoles: ['support'], title: 'Segurança' } },
        ],
    },

    { path: '/:pathMatch(.*)*', component: () => import('@/pages/NotFoundPage.vue'), meta: { title: 'Página não encontrada' } },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

/** Destino por omissão depois de entrar. */
export function homeFor(session: ReturnType<typeof useSessionStore>): string {
    if (session.current || session.supportSession) return '/app';
    if (session.isPlatformStaff) return '/plataforma';
    return '/app/sem-empresa';
}

router.beforeEach(async (to) => {
    const session = useSessionStore();
    await session.ensureLoaded();

    if (to.meta.guest && session.isAuthenticated) {
        return homeFor(session);
    }

    if (to.matched.some((r) => r.meta.auth) && !session.isAuthenticated) {
        return { path: '/entrar', query: { redirect: to.fullPath } };
    }

    if (!session.isAuthenticated) return true;

    const user = session.user!;

    if (to.matched.some((r) => r.meta.auth) && !user.email_verified && to.path !== '/verificar-email' && !session.isPlatformStaff) {
        return '/verificar-email';
    }

    if (to.matched.some((r) => r.meta.platform)) {
        if (!session.isPlatformStaff) return '/app';
        // O backoffice exige 2FA: encaminha para a conta para a ativar.
        if (!user.two_factor_enabled) {
            useToastStore().info('Ative a autenticação de dois fatores para aceder ao backoffice.');
            return '/app/conta';
        }
        const roles = to.meta.platformRoles;
        if (roles && !session.hasPlatformRole(...roles)) return '/plataforma';
    }

    if (to.meta.tenant) {
        if (!session.current && !session.supportSession) return homeFor(session);
        if (session.current?.tenant.requires_mfa && !user.two_factor_enabled) {
            useToastStore().info('A sua empresa exige autenticação de dois fatores. Ative-a para continuar.');
            return '/app/conta';
        }
        if (to.meta.permission && !session.can(to.meta.permission)) {
            useToastStore().error('Não tem permissão para aceder a esta área.');
            return '/app';
        }
    }

    return true;
});

router.afterEach((to) => {
    document.title = to.meta.title ? `${to.meta.title} · ObraConnect` : 'ObraConnect';
});

// Sessão terminada noutro separador ou expirada: volta ao login.
onApiError((error) => {
    if (error.status === 401) {
        const session = useSessionStore();
        const current = router.currentRoute.value;
        if (session.isAuthenticated && !current.meta.guest) {
            session.reset();
            router.push({ path: '/entrar', query: { redirect: current.fullPath } });
        }
    }
});
