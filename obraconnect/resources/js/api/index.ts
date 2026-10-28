import { ensureCsrfCookie, getData, http } from './http';
import type {
    AuditEntry,
    Company,
    DataExport,
    Invitation,
    Member,
    MePayload,
    Paginated,
    PermissionModule,
    Plan,
    PlatformDashboard,
    PlatformTenant,
    PlatformTenantDetail,
    Role,
    Subscription,
    SupportGrant,
    UsageItem,
} from './types';

/** Rotas do Fortify (prefixo /auth). Cada escrita garante o cookie CSRF. */
export const authApi = {
    async login(email: string, password: string, remember: boolean): Promise<{ two_factor: boolean }> {
        await ensureCsrfCookie();
        const { data } = await http.post('/auth/login', { email, password, remember });
        return { two_factor: Boolean(data?.two_factor) };
    },
    async twoFactorChallenge(payload: { code?: string; recovery_code?: string }): Promise<void> {
        await ensureCsrfCookie();
        await http.post('/auth/two-factor-challenge', payload);
    },
    async register(payload: Record<string, unknown>): Promise<void> {
        await ensureCsrfCookie();
        await http.post('/auth/register', payload);
    },
    async logout(): Promise<void> {
        await ensureCsrfCookie();
        await http.post('/auth/logout');
    },
    async forgotPassword(email: string): Promise<string> {
        await ensureCsrfCookie();
        const { data } = await http.post('/auth/forgot-password', { email });
        return data?.message ?? '';
    },
    async resetPassword(payload: { token: string; email: string; password: string; password_confirmation: string }): Promise<void> {
        await ensureCsrfCookie();
        await http.post('/auth/reset-password', payload);
    },
    async resendVerification(): Promise<void> {
        await ensureCsrfCookie();
        await http.post('/auth/email/verification-notification');
    },
    async updateProfile(payload: { name: string; email: string }): Promise<void> {
        await ensureCsrfCookie();
        await http.put('/auth/user/profile-information', payload);
    },
    async updatePassword(payload: { current_password: string; password: string; password_confirmation: string }): Promise<void> {
        await ensureCsrfCookie();
        await http.put('/auth/user/password', payload);
    },
    async passwordConfirmed(): Promise<boolean> {
        const { data } = await http.get('/auth/user/confirmed-password-status');
        return Boolean(data?.confirmed);
    },
    async confirmPassword(password: string): Promise<void> {
        await ensureCsrfCookie();
        await http.post('/auth/user/confirm-password', { password });
    },
    async enableTwoFactor(): Promise<void> {
        await http.post('/auth/user/two-factor-authentication');
    },
    async twoFactorQrCode(): Promise<{ svg: string; url: string }> {
        const { data } = await http.get('/auth/user/two-factor-qr-code');
        return data;
    },
    async twoFactorSecretKey(): Promise<string> {
        const { data } = await http.get('/auth/user/two-factor-secret-key');
        return data?.secretKey ?? '';
    },
    async confirmTwoFactor(code: string): Promise<void> {
        await http.post('/auth/user/confirmed-two-factor-authentication', { code });
    },
    async recoveryCodes(): Promise<string[]> {
        const { data } = await http.get('/auth/user/two-factor-recovery-codes');
        return data;
    },
    async regenerateRecoveryCodes(): Promise<void> {
        await http.post('/auth/user/two-factor-recovery-codes');
    },
    async disableTwoFactor(): Promise<void> {
        await http.delete('/auth/user/two-factor-authentication');
    },
};

export const meApi = {
    get: () => getData<MePayload>('/api/v1/me'),
    switchTenant: (tenantId: number) => http.post(`/api/v1/me/tenants/${tenantId}/switch`),
    acceptInvitation: (token: string) => http.post('/api/v1/invitations/accept', { token }),
    lookupInvitation: (token: string) =>
        getData<{ company: string; role: string; email: string; expires_at: string; account_exists: boolean }>(
            `/api/v1/invitations/lookup/${token}`,
        ),
};

export const companyApi = {
    get: () => getData<Company>('/api/v1/company'),
    update: async (payload: Record<string, unknown>) =>
        (await http.put<{ data: Company }>('/api/v1/company', payload)).data.data,
    updateSecurity: async (requireMfa: boolean) =>
        (await http.put<{ data: Company }>('/api/v1/company/security', { require_mfa: requireMfa })).data.data,
    uploadLogo: async (file: File) => {
        const form = new FormData();
        form.append('logo', file);
        return (await http.post<{ data: Company }>('/api/v1/company/logo', form)).data.data;
    },
    deleteLogo: async () => (await http.delete<{ data: Company }>('/api/v1/company/logo')).data.data,
};

export const membersApi = {
    list: async (params: { search?: string; status?: string; page?: number }) =>
        (await http.get<Paginated<Member>>('/api/v1/members', { params })).data,
    update: async (id: number, payload: { role_id?: number; status?: string; job_title?: string | null }) =>
        (await http.patch<{ data: Member }>(`/api/v1/members/${id}`, payload)).data.data,
    remove: (id: number) => http.delete(`/api/v1/members/${id}`),
};

export const invitationsApi = {
    list: async (status: 'pending' | 'all' = 'pending') =>
        (await http.get<Paginated<Invitation>>('/api/v1/invitations', { params: { status } })).data,
    create: async (email: string, roleId: number) =>
        (await http.post<{ data: Invitation }>('/api/v1/invitations', { email, role_id: roleId })).data.data,
    resend: (id: number) => http.post(`/api/v1/invitations/${id}/resend`),
    revoke: (id: number) => http.delete(`/api/v1/invitations/${id}`),
};

export const rolesApi = {
    list: () => getData<Role[]>('/api/v1/roles'),
    permissions: () => getData<PermissionModule[]>('/api/v1/permissions'),
    create: async (payload: { name: string; description?: string | null; permissions: string[] }) =>
        (await http.post<{ data: Role }>('/api/v1/roles', payload)).data.data,
    update: async (id: number, payload: { name: string; description?: string | null; permissions: string[] }) =>
        (await http.put<{ data: Role }>(`/api/v1/roles/${id}`, payload)).data.data,
    reset: async (id: number) => (await http.post<{ data: Role }>(`/api/v1/roles/${id}/reset`)).data.data,
    remove: (id: number) => http.delete(`/api/v1/roles/${id}`),
};

export const auditApi = {
    list: async (params: { event?: string; from?: string; to?: string; page?: number }) =>
        (await http.get<Paginated<AuditEntry>>('/api/v1/audit-logs', { params })).data,
};

export const subscriptionApi = {
    get: () =>
        getData<{ subscription: Subscription | null; usage: Record<string, UsageItem>; tenant_status: string }>(
            '/api/v1/subscription',
        ),
    publicPlans: () => getData<Plan[]>('/api/v1/plans'),
};

export const supportApi = {
    list: () => getData<SupportGrant[]>('/api/v1/support-grants'),
    create: async (reason: string, hours: number) =>
        (await http.post<{ data: SupportGrant }>('/api/v1/support-grants', { reason, hours })).data.data,
    revoke: (id: number) => http.delete(`/api/v1/support-grants/${id}`),
};

export const exportsApi = {
    list: () => getData<DataExport[]>('/api/v1/exports'),
    create: async () => (await http.post<{ data: DataExport }>('/api/v1/exports')).data.data,
    downloadUrl: (id: number) => `/api/v1/exports/${id}/download`,
};

export const platformApi = {
    dashboard: () => getData<PlatformDashboard>('/api/v1/platform/dashboard'),
    tenants: async (params: { search?: string; status?: string; page?: number }) =>
        (await http.get<Paginated<PlatformTenant>>('/api/v1/platform/tenants', { params })).data,
    tenant: (id: number) => getData<PlatformTenantDetail>(`/api/v1/platform/tenants/${id}`),
    suspend: (id: number, reason: string) => http.post(`/api/v1/platform/tenants/${id}/suspend`, { reason }),
    reactivate: (id: number) => http.post(`/api/v1/platform/tenants/${id}/reactivate`),
    updateSubscription: (id: number, payload: Record<string, unknown>) =>
        http.put(`/api/v1/platform/tenants/${id}/subscription`, payload),
    plans: () => getData<Plan[]>('/api/v1/platform/plans'),
    createPlan: (payload: Record<string, unknown>) => http.post('/api/v1/platform/plans', payload),
    updatePlan: (id: number, payload: Record<string, unknown>) => http.put(`/api/v1/platform/plans/${id}`, payload),
    supportGrants: () => getData<SupportGrant[]>('/api/v1/platform/support-grants'),
    enterSupport: (grantId: number) => http.post(`/api/v1/platform/support-grants/${grantId}/enter`),
    leaveSupport: () => http.post('/api/v1/platform/support/leave'),
    securityLogs: async (params: { event?: string; tenant_id?: string; page?: number }) =>
        (await http.get<Paginated<AuditEntry>>('/api/v1/platform/security-logs', { params })).data,
};
