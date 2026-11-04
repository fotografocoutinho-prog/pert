import { appUrl } from '@/utils/base';
import { cachedRead } from '@/offline/cache';
import { ensureCsrfCookie, getData, http } from './http';
import type {
    AuditEntry,
    Client,
    ClientOption,
    Company,
    CostCenter,
    DataExport,
    Department,
    DiaryEntry,
    DocumentListing,
    Holiday,
    Invitation,
    MediaItem,
    Member,
    MePayload,
    Paginated,
    PermissionModule,
    Plan,
    PlatformDashboard,
    PlatformTenant,
    PlatformTenantDetail,
    Project,
    ProjectCalendar,
    ProjectDocument,
    ProjectMember,
    ProjectStructure,
    Role,
    Specialty,
    StructureLinks,
    Subscription,
    SupportGrant,
    UsageItem,
    WeeklyHours,
    WorkCalendar,
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
    list: async (params: { search?: string; status?: string; page?: number; per_page?: number; department_id?: number | 'none' }) =>
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
    downloadUrl: (id: number) => appUrl(`/api/v1/exports/${id}/download`),
};

export const projectsApi = {
    // Leituras com cópia local: abrem sem rede com o que foi visto da última vez.
    list: (params: { search?: string; status?: string; page?: number; client_id?: number }) =>
        cachedRead(`projects:${JSON.stringify(params)}`, async () => (await http.get<Paginated<Project>>('/api/v1/projects', { params })).data),
    get: (id: number) => cachedRead(`project:${id}`, () => getData<Project>(`/api/v1/projects/${id}`)),
    create: async (payload: Record<string, unknown>) => (await http.post<{ data: Project }>('/api/v1/projects', payload)).data.data,
    update: async (id: number, payload: Record<string, unknown>) =>
        (await http.put<{ data: Project }>(`/api/v1/projects/${id}`, payload)).data.data,
    /** Mês do calendário da obra e prazo em dias úteis (com cópia local, para o diário sem rede). */
    calendar: (id: number, month?: string) =>
        cachedRead(`project-calendar:${id}:${month ?? ''}`, () => getData<ProjectCalendar>(`/api/v1/projects/${id}/calendar`, { month })),
    members: (id: number) => getData<ProjectMember[]>(`/api/v1/projects/${id}/members`),
    memberOptions: (id: number) =>
        getData<{
            members: { membership_id: number; name: string; email: string; company_role: { id: number; name: string } }[];
            roles: { id: number; key: string; name: string }[];
        }>(`/api/v1/projects/${id}/members/options`),
    addMember: async (id: number, membershipId: number, roleId: number | null) =>
        (await http.post<{ data: ProjectMember }>(`/api/v1/projects/${id}/members`, { membership_id: membershipId, role_id: roleId })).data.data,
    updateMember: async (id: number, memberId: number, roleId: number) =>
        (await http.patch<{ data: ProjectMember }>(`/api/v1/projects/${id}/members/${memberId}`, { role_id: roleId })).data.data,
    removeMember: (id: number, memberId: number) => http.delete(`/api/v1/projects/${id}/members/${memberId}`),
};

export const departmentsApi = {
    list: () => getData<Department[]>('/api/v1/departments'),
    create: async (payload: { name: string; description?: string | null }) =>
        (await http.post<{ data: Department }>('/api/v1/departments', payload)).data.data,
    update: async (id: number, payload: { name?: string; description?: string | null }) =>
        (await http.put<{ data: Department }>(`/api/v1/departments/${id}`, payload)).data.data,
    remove: (id: number) => http.delete(`/api/v1/departments/${id}`),
    setMembers: async (id: number, membershipIds: number[]) =>
        (await http.put<{ data: Department }>(`/api/v1/departments/${id}/members`, { membership_ids: membershipIds })).data.data,
};

export const specialtiesApi = {
    list: () => getData<Specialty[]>('/api/v1/specialties'),
    create: async (payload: { code: string; name: string }) => (await http.post<{ data: Specialty }>('/api/v1/specialties', payload)).data.data,
    update: async (id: number, payload: { code?: string; name?: string; archived?: boolean }) =>
        (await http.put<{ data: Specialty }>(`/api/v1/specialties/${id}`, payload)).data.data,
    remove: (id: number) => http.delete(`/api/v1/specialties/${id}`),
    addDefaults: async () => (await http.post<{ data: { added: number } }>('/api/v1/specialties/defaults')).data.data.added,
};

export const costCentersApi = {
    list: () => getData<CostCenter[]>('/api/v1/cost-centers'),
    create: async (payload: { code?: string | null; name: string; parent_id?: number | null }) =>
        (await http.post<{ data: CostCenter }>('/api/v1/cost-centers', payload)).data.data,
    update: async (id: number, payload: { code?: string | null; name?: string; parent_id?: number | null; archived?: boolean }) =>
        (await http.put<{ data: CostCenter }>(`/api/v1/cost-centers/${id}`, payload)).data.data,
    remove: (id: number) => http.delete(`/api/v1/cost-centers/${id}`),
};

export const clientsApi = {
    list: async (params: { search?: string; status?: string; page?: number }) => (await http.get<Paginated<Client>>('/api/v1/clients', { params })).data,
    options: (include?: number | null) => getData<ClientOption[]>('/api/v1/clients/options', include ? { include } : undefined),
    get: (id: number) => getData<Client>(`/api/v1/clients/${id}`),
    create: async (payload: Record<string, unknown>) => (await http.post<{ data: Client }>('/api/v1/clients', payload)).data.data,
    update: async (id: number, payload: Record<string, unknown>) => (await http.put<{ data: Client }>(`/api/v1/clients/${id}`, payload)).data.data,
    remove: (id: number) => http.delete(`/api/v1/clients/${id}`),
};

export const calendarsApi = {
    list: () => getData<WorkCalendar[]>('/api/v1/work-calendars'),
    create: async (payload: { name: string; weekly_hours: WeeklyHours; with_national_holidays?: boolean }) =>
        (await http.post<{ data: WorkCalendar }>('/api/v1/work-calendars', payload)).data.data,
    update: async (id: number, payload: { name?: string; weekly_hours?: WeeklyHours; is_default?: true }) =>
        (await http.put<{ data: WorkCalendar }>(`/api/v1/work-calendars/${id}`, payload)).data.data,
    remove: (id: number) => http.delete(`/api/v1/work-calendars/${id}`),
    holidays: (id: number, year: number) => getData<Holiday[]>(`/api/v1/work-calendars/${id}/holidays`, { year }),
    addDaysOff: async (id: number, payload: { date: string; until?: string | null; name: string; kind: string }) =>
        (await http.post<{ data: Holiday[] }>(`/api/v1/work-calendars/${id}/holidays`, payload)).data.data,
    removeDaysOff: (id: number, ids: number[]) => http.delete(`/api/v1/work-calendars/${id}/holidays`, { data: { ids } }),
    addNational: async (id: number, year: number, carnival: boolean) =>
        (await http.post<{ data: { added: number } }>(`/api/v1/work-calendars/${id}/national-holidays`, { year, carnival })).data.data.added,
};

export const diaryApi = {
    list: (projectId: number, params: { month?: string; page?: number }) =>
        cachedRead(`diary:${projectId}:${JSON.stringify(params)}`, async () =>
            (await http.get<Paginated<DiaryEntry>>(`/api/v1/projects/${projectId}/diary`, { params })).data),
    get: (projectId: number, entryId: number) =>
        cachedRead(`diary-entry:${projectId}:${entryId}`, () => getData<DiaryEntry>(`/api/v1/projects/${projectId}/diary/${entryId}`)),
    create: async (projectId: number, payload: Record<string, unknown>) =>
        (await http.post<{ data: DiaryEntry }>(`/api/v1/projects/${projectId}/diary`, payload)).data.data,
    update: async (projectId: number, entryId: number, payload: Record<string, unknown>) =>
        (await http.put<{ data: DiaryEntry }>(`/api/v1/projects/${projectId}/diary/${entryId}`, payload)).data.data,
    remove: (projectId: number, entryId: number) => http.delete(`/api/v1/projects/${projectId}/diary/${entryId}`),
    submit: async (projectId: number, entryId: number) =>
        (await http.post<{ data: DiaryEntry }>(`/api/v1/projects/${projectId}/diary/${entryId}/submit`)).data.data,
    uploadPhoto: async (
        projectId: number,
        entryId: number,
        file: File,
        onProgress?: (percent: number) => void,
        extra: Record<string, string | number> = {},
    ) => {
        const form = new FormData();
        form.append('photo', file);
        // client_uuid (fila sem rede) e local/elemento.
        for (const [k, v] of Object.entries(extra)) form.append(k, String(v));
        const { data } = await http.post<{ data: MediaItem }>(`/api/v1/projects/${projectId}/diary/${entryId}/photos`, form, {
            // Fotografias de telemóvel em rede móvel demoram mais do que os 30 s por omissão.
            timeout: 300_000,
            onUploadProgress: (e) => onProgress?.(e.total ? Math.round((e.loaded * 100) / e.total) : 0),
        });
        return data.data;
    },
    updatePhoto: async (
        projectId: number,
        entryId: number,
        photoId: number,
        payload: { caption?: string | null; location_id?: number | null; element_id?: number | null },
    ) => (await http.patch<{ data: MediaItem }>(`/api/v1/projects/${projectId}/diary/${entryId}/photos/${photoId}`, payload)).data.data,
    removePhoto: (projectId: number, entryId: number, photoId: number) =>
        http.delete(`/api/v1/projects/${projectId}/diary/${entryId}/photos/${photoId}`),
};

/** Envio de ficheiros com progresso e tempo largo (rede móvel). */
function upload<T>(url: string, form: FormData, onProgress?: (percent: number) => void): Promise<T> {
    return http
        .post<{ data: T }>(url, form, {
            timeout: 600_000,
            onUploadProgress: (e) => onProgress?.(e.total ? Math.round((e.loaded * 100) / e.total) : 0),
        })
        .then((r) => r.data.data);
}

export const documentsApi = {
    list: (projectId: number, params: { folder_id?: number; search?: string; specialty_id?: number; tree?: 1 }) =>
        getData<DocumentListing>(`/api/v1/projects/${projectId}/documents`, params),
    get: (projectId: number, id: number) => getData<ProjectDocument>(`/api/v1/projects/${projectId}/documents/${id}`),
    upload: (projectId: number, file: File, folderId: number | null, onProgress?: (percent: number) => void, specialtyId?: number | null) => {
        const form = new FormData();
        form.append('file', file);
        if (folderId) form.append('folder_id', String(folderId));
        if (specialtyId) form.append('specialty_id', String(specialtyId));
        return upload<ProjectDocument>(`/api/v1/projects/${projectId}/documents`, form, onProgress);
    },
    addVersion: (projectId: number, id: number, file: File, note: string | null, onProgress?: (percent: number) => void) => {
        const form = new FormData();
        form.append('file', file);
        if (note) form.append('note', note);
        return upload<ProjectDocument>(`/api/v1/projects/${projectId}/documents/${id}/versions`, form, onProgress);
    },
    update: async (
        projectId: number,
        id: number,
        payload: {
            title?: string;
            description?: string | null;
            folder_id?: number | null;
            location_id?: number | null;
            element_id?: number | null;
            specialty_id?: number | null;
        },
    ) =>
        (await http.patch<{ data: ProjectDocument }>(`/api/v1/projects/${projectId}/documents/${id}`, payload)).data.data,
    remove: (projectId: number, id: number) => http.delete(`/api/v1/projects/${projectId}/documents/${id}`),
    createFolder: (projectId: number, name: string, parentId: number | null) =>
        http.post(`/api/v1/projects/${projectId}/folders`, { name, parent_id: parentId }),
    renameFolder: (projectId: number, id: number, name: string) => http.patch(`/api/v1/projects/${projectId}/folders/${id}`, { name }),
    removeFolder: (projectId: number, id: number) => http.delete(`/api/v1/projects/${projectId}/folders/${id}`),
};

export const structureApi = {
    get: (projectId: number) => cachedRead(`structure:${projectId}`, () => getData<ProjectStructure>(`/api/v1/projects/${projectId}/structure`)),
    createLocation: (projectId: number, payload: { kind: string; name: string; parent_id: number | null }) =>
        http.post(`/api/v1/projects/${projectId}/locations`, payload),
    updateLocation: (projectId: number, id: number, payload: { name?: string; sort_order?: number }) =>
        http.patch(`/api/v1/projects/${projectId}/locations/${id}`, payload),
    removeLocation: (projectId: number, id: number) => http.delete(`/api/v1/projects/${projectId}/locations/${id}`),
    createElement: (projectId: number, payload: Record<string, unknown>) => http.post(`/api/v1/projects/${projectId}/elements`, payload),
    updateElement: (projectId: number, id: number, payload: Record<string, unknown>) =>
        http.patch(`/api/v1/projects/${projectId}/elements/${id}`, payload),
    removeElement: (projectId: number, id: number) => http.delete(`/api/v1/projects/${projectId}/elements/${id}`),
    elementLinks: (projectId: number, id: number) => getData<StructureLinks>(`/api/v1/projects/${projectId}/elements/${id}/links`),
    locationLinks: (projectId: number, id: number) => getData<StructureLinks>(`/api/v1/projects/${projectId}/locations/${id}/links`),
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
