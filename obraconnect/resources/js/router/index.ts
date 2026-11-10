import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { onApiError } from '@/api/http';
import { BASE_PATH } from '@/utils/base';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import AppShell from '@/components/layout/AppShell.vue';

declare module 'vue-router' {
    interface RouteMeta {
        guest?: boolean;
        auth?: boolean;
        tenant?: boolean;
        permission?: string;
        /** Basta uma destas. */
        anyPermission?: string[];
        platform?: boolean;
        platformRoles?: string[];
        title?: string;
    }
}

const EQUIPMENT = ['equipment.manage', 'costs.view', 'workforce.view'];

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
    { path: '/portal/convite/:token', component: () => import('@/pages/portal/PortalInvitationPage.vue'), meta: { title: 'Convite para o portal' } },

    // Portal de subempreiteiros (sem empresa: o acesso diz a empresa e o subempreiteiro)
    {
        path: '/portal',
        component: () => import('@/pages/portal/PortalShell.vue'),
        meta: { auth: true },
        children: [
            { path: '', component: () => import('@/pages/portal/PortalHomePage.vue'), meta: { title: 'Portal de subempreiteiros' } },
            { path: ':access/contratos/:contract', component: () => import('@/pages/portal/PortalContractPage.vue'), meta: { title: 'Contrato' } },
            { path: ':access/documentos', component: () => import('@/pages/portal/PortalDocumentsPage.vue'), meta: { title: 'Documentos' } },
        ],
    },

    // Aplicação da empresa
    {
        path: '/app',
        component: AppShell,
        meta: { auth: true },
        children: [
            { path: '', component: () => import('@/pages/app/DashboardPage.vue'), meta: { tenant: true, title: 'Painel' } },
            { path: 'obras', component: () => import('@/pages/app/projects/ProjectsPage.vue'), meta: { tenant: true, permission: 'projects.view', title: 'Obras' } },
            { path: 'obras/:id', component: () => import('@/pages/app/projects/ProjectPage.vue'), meta: { tenant: true, permission: 'projects.view', title: 'Obra' } },
            { path: 'obras/:id/diario/novo', component: () => import('@/pages/app/projects/DiaryEntryPage.vue'), meta: { tenant: true, permission: 'projects.view', title: 'Diário de obra' } },
            { path: 'obras/:id/diario/:entryId', component: () => import('@/pages/app/projects/DiaryEntryPage.vue'), meta: { tenant: true, permission: 'projects.view', title: 'Diário de obra' } },
            { path: 'obras/:id/inspecoes/:inspectionId', component: () => import('@/pages/app/projects/InspectionPage.vue'), meta: { tenant: true, permission: 'projects.view', title: 'Inspeção' } },
            { path: 'obras/:id/ocorrencias/:issueId', component: () => import('@/pages/app/projects/IssuePage.vue'), meta: { tenant: true, permission: 'projects.view', title: 'Ocorrência' } },
            { path: 'obras/:id/orcamentos/:budgetId', component: () => import('@/pages/app/projects/BudgetPage.vue'), meta: { tenant: true, permission: 'projects.view', title: 'Orçamento' } },
            { path: 'obras/:id/autos/:certId', component: () => import('@/pages/app/projects/CertificatePage.vue'), meta: { tenant: true, permission: 'projects.view', title: 'Auto de medição' } },
            { path: 'financas', component: () => import('@/pages/app/FinancePage.vue'), meta: { tenant: true, permission: 'finance.view', title: 'Painel financeiro' } },
            { path: 'faturacao', component: () => import('@/pages/app/InvoicesPage.vue'), meta: { tenant: true, anyPermission: ['invoicing.view', 'invoicing.manage'], title: 'Faturação' } },
            { path: 'faturacao/:id', component: () => import('@/pages/app/InvoicePage.vue'), meta: { tenant: true, anyPermission: ['invoicing.view', 'invoicing.manage'], title: 'Pré-fatura' } },
            {
                path: 'faturas-fornecedores',
                component: () => import('@/pages/app/SupplierDocumentsPage.vue'),
                meta: { tenant: true, permission: 'supplier_docs.manage', title: 'Faturas de fornecedores' },
            },
            {
                path: 'faturas-fornecedores/:id',
                component: () => import('@/pages/app/SupplierDocumentPage.vue'),
                meta: { tenant: true, anyPermission: ['supplier_docs.manage', 'purchases.manage', 'costs.manage'], title: 'Documento de fornecedor' },
            },
            { path: 'precos', component: () => import('@/pages/app/PriceLibraryPage.vue'), meta: { tenant: true, anyPermission: ['budgets.view', 'budgets.manage'], title: 'Biblioteca de preços' } },
            {
                path: 'fornecedores',
                component: () => import('@/pages/app/PartnersPage.vue'),
                meta: { tenant: true, anyPermission: ['purchases.manage', 'subcontractors.manage', 'costs.view', 'workforce.manage'], title: 'Fornecedores e subempreiteiros' },
            },
            {
                path: 'trabalhadores',
                component: () => import('@/pages/app/WorkersPage.vue'),
                meta: { tenant: true, anyPermission: ['workforce.view', 'workforce.manage', 'time.register_team', 'time.approve'], title: 'Trabalhadores' },
            },
            { path: 'equipamentos', component: () => import('@/pages/app/EquipmentPage.vue'), meta: { tenant: true, anyPermission: EQUIPMENT, title: 'Equipamentos' } },
            { path: 'equipamentos/qr/:token', component: () => import('@/pages/app/EquipmentDetailPage.vue'), meta: { tenant: true, anyPermission: EQUIPMENT, title: 'Equipamento' } },
            { path: 'equipamentos/:id', component: () => import('@/pages/app/EquipmentDetailPage.vue'), meta: { tenant: true, anyPermission: EQUIPMENT, title: 'Equipamento' } },
            { path: 'clientes', component: () => import('@/pages/app/clients/ClientsPage.vue'), meta: { tenant: true, permission: 'clients.view', title: 'Clientes' } },
            { path: 'clientes/:id', component: () => import('@/pages/app/clients/ClientPage.vue'), meta: { tenant: true, permission: 'clients.view', title: 'Cliente' } },
            { path: 'organizacao', component: () => import('@/pages/app/OrganizationPage.vue'), meta: { tenant: true, permission: 'company.view', title: 'Organização' } },
            { path: 'listas-verificacao', component: () => import('@/pages/app/ChecklistsPage.vue'), meta: { tenant: true, permission: 'checklists.manage', title: 'Listas de verificação' } },
            { path: 'calendarios', component: () => import('@/pages/app/CalendarsPage.vue'), meta: { tenant: true, permission: 'company.view', title: 'Calendários de trabalho' } },
            { path: 'empresa', component: () => import('@/pages/app/CompanyPage.vue'), meta: { tenant: true, permission: 'company.view', title: 'Empresa' } },
            { path: 'utilizadores', component: () => import('@/pages/app/MembersPage.vue'), meta: { tenant: true, permission: 'members.view', title: 'Utilizadores' } },
            { path: 'perfis', component: () => import('@/pages/app/RolesPage.vue'), meta: { tenant: true, permission: 'roles.view', title: 'Perfis e permissões' } },
            { path: 'auditoria', component: () => import('@/pages/app/AuditPage.vue'), meta: { tenant: true, permission: 'audit.view', title: 'Auditoria' } },
            { path: 'subscricao', component: () => import('@/pages/app/SubscriptionPage.vue'), meta: { tenant: true, permission: 'subscription.view', title: 'Subscrição' } },
            { path: 'suporte', component: () => import('@/pages/app/SupportAccessPage.vue'), meta: { tenant: true, permission: 'support.manage', title: 'Suporte técnico' } },
            { path: 'integracoes', component: () => import('@/pages/app/IntegrationsPage.vue'), meta: { tenant: true, permission: 'integrations.manage', title: 'Integrações (API e webhooks)' } },
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
            { path: 'sistema', component: () => import('@/pages/platform/PlatformSystemPage.vue'), meta: { title: 'Saúde do sistema' } },
            { path: 'empresas/:id', component: () => import('@/pages/platform/PlatformTenantPage.vue'), meta: { title: 'Empresa' } },
            { path: 'planos', component: () => import('@/pages/platform/PlatformPlansPage.vue'), meta: { platformRoles: ['billing'], title: 'Planos' } },
            { path: 'pedidos', component: () => import('@/pages/platform/PlatformOrdersPage.vue'), meta: { platformRoles: ['billing'], title: 'Pedidos de subscrição' } },
            { path: 'cupoes', component: () => import('@/pages/platform/PlatformCouponsPage.vue'), meta: { platformRoles: ['billing'], title: 'Cupões' } },
            { path: 'suporte', component: () => import('@/pages/platform/PlatformSupportPage.vue'), meta: { platformRoles: ['support'], title: 'Suporte' } },
            { path: 'seguranca', component: () => import('@/pages/platform/PlatformSecurityLogsPage.vue'), meta: { platformRoles: ['support'], title: 'Segurança' } },
        ],
    },

    { path: '/:pathMatch(.*)*', component: () => import('@/pages/NotFoundPage.vue'), meta: { title: 'Página não encontrada' } },
];

export const router = createRouter({
    history: createWebHistory(`${BASE_PATH}/`),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

/** Destino por omissão depois de entrar. */
export function homeFor(session: ReturnType<typeof useSessionStore>): string {
    if (session.current || session.supportSession) return '/app';
    if (session.isPlatformStaff) return '/plataforma';
    if (session.portal.length) return '/portal';
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
        if ((to.meta.permission && !session.can(to.meta.permission)) || (to.meta.anyPermission && !to.meta.anyPermission.some((p) => session.can(p)))) {
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
