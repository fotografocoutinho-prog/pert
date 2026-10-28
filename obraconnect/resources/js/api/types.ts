export interface ApiErrorBody {
    message: string;
    code?: string;
    errors?: Record<string, string[]>;
    meta?: Record<string, unknown>;
}

export interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; total: number; per_page?: number };
}

export interface SessionUser {
    id: number;
    name: string;
    email: string;
    email_verified: boolean;
    two_factor_enabled: boolean;
    two_factor_pending_confirmation: boolean;
    platform_role: 'super_admin' | 'support' | 'billing' | null;
    platform_role_label: string | null;
    locale: string;
}

export interface TenantOption {
    id: number;
    name: string;
    status: TenantStatus;
    role: string | null;
    is_current: boolean;
}

export type TenantStatus = 'active' | 'read_only' | 'suspended';

export interface CurrentTenant {
    tenant: {
        id: number;
        name: string;
        status: TenantStatus;
        status_label: string;
        requires_mfa: boolean;
        logo_url: string | null;
    };
    membership_id: number;
    role: { id: number; key: string | null; name: string | null };
    permissions: string[];
    subscription: {
        status: string;
        status_label: string;
        plan: string;
        plan_code: string;
        trial_ends_at: string | null;
        features: string[];
    } | null;
}

export interface SupportSession {
    grant_id: number;
    tenant: { id: number; name: string | null };
    expires_at: string;
    access_level: string;
}

export interface MePayload {
    user: SessionUser;
    tenants: TenantOption[];
    current: CurrentTenant | null;
    support_session: SupportSession | null;
}

export interface Company {
    id: number;
    name: string;
    legal_name: string | null;
    nif: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    address_line1: string | null;
    address_line2: string | null;
    postal_code: string | null;
    city: string | null;
    country_code: string;
    has_logo: boolean;
    logo_url: string | null;
    status: TenantStatus;
    status_label: string;
    settings: { timezone: string; require_mfa: boolean };
    storage_used_bytes: number;
    created_at: string | null;
}

export interface Member {
    id: number;
    status: 'active' | 'suspended';
    status_label: string;
    job_title: string | null;
    joined_at: string | null;
    user: { id: number; name: string; email: string; two_factor_enabled: boolean; last_login_at: string | null };
    role: { id: number; key: string; name: string };
    is_me: boolean;
}

export interface Invitation {
    id: number;
    email: string;
    status: 'pending' | 'accepted' | 'revoked' | 'expired';
    role: { id: number; name: string };
    invited_by: string | null;
    expires_at: string;
    accepted_at: string | null;
    created_at: string | null;
}

export interface Role {
    id: number;
    key: string;
    name: string;
    description: string | null;
    template_key: string | null;
    is_locked: boolean;
    permissions: string[];
    members_count?: number;
}

export interface PermissionModule {
    module: string;
    label: string;
    permissions: { key: string; label: string }[];
}

export interface AuditEntry {
    id: number;
    event: string;
    actor_type: 'user' | 'platform' | 'support' | 'system';
    actor: { id: number; name: string; email: string } | null;
    subject_type: string | null;
    subject_id: number | null;
    properties: Record<string, unknown> | null;
    ip_address: string | null;
    request_id: string | null;
    tenant_id?: number | null;
    created_at: string;
}

export interface Plan {
    id: number;
    code: string;
    name: string;
    description: string | null;
    price_monthly_cents: number | null;
    price_yearly_cents: number | null;
    currency: string;
    prices_exclude_vat: boolean;
    trial_days: number;
    limits: Record<string, number | null>;
    features: string[];
    is_public?: boolean;
    is_active?: boolean;
    sort_order: number;
    subscriptions_count?: number;
}

export interface Subscription {
    id: number;
    status: 'trialing' | 'active' | 'past_due' | 'canceled' | 'expired';
    status_label: string;
    billing_cycle: 'monthly' | 'yearly';
    plan?: Plan;
    trial_ends_at: string | null;
    current_period_starts_at: string | null;
    current_period_ends_at: string | null;
    canceled_at: string | null;
    ends_at: string | null;
    custom_limits?: Record<string, number | null> | null;
    provider?: string;
    notes?: string | null;
    created_at: string | null;
}

export interface UsageItem {
    label: string;
    limit: number | null;
    used: number | null;
}

export interface SupportGrant {
    id: number;
    reason: string;
    access_level: string;
    is_active: boolean;
    granted_by: string | null;
    expires_at: string;
    revoked_at: string | null;
    last_used_at: string | null;
    last_used_by: string | null;
    tenant?: { id: number; name: string | null };
    created_at: string | null;
}

export interface DataExport {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed' | 'expired';
    requested_by: string | null;
    size_bytes: number | null;
    checksum_sha256: string | null;
    is_downloadable: boolean;
    error: string | null;
    completed_at: string | null;
    expires_at: string | null;
    created_at: string | null;
}

export interface PlatformTenant {
    id: number;
    name: string;
    legal_name: string | null;
    nif: string | null;
    email: string | null;
    phone: string | null;
    status: TenantStatus;
    status_label: string;
    status_reason: string | null;
    members_count: number | null;
    storage_used_bytes: number;
    plan: string | null;
    plan_id: number | null;
    subscription_status: string | null;
    trial_ends_at: string | null;
    created_at: string | null;
}

export interface PlatformTenantDetail extends PlatformTenant {
    admins: { name: string; email: string }[];
    usage: Record<string, UsageItem>;
    subscriptions: Subscription[];
    platform_events: AuditEntry[];
}

export interface PlatformDashboard {
    tenants: { total: number; by_status: Record<string, number> };
    users: { total: number; active_30d: number };
    subscriptions: { by_plan: { name: string; status: string; total: number }[]; trials_ending_7d: number };
    signups_30d: Record<string, number>;
}
