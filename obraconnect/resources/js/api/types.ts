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
    department: { id: number; name: string } | null;
    is_me: boolean;
}

export interface Department {
    id: number;
    name: string;
    description: string | null;
    membership_ids: number[];
    members_count: number;
}

export interface SpecialtyRef {
    id: number;
    code: string;
    name: string;
    archived: boolean;
}

export interface Specialty extends SpecialtyRef {
    elements_count: number;
    documents_count: number;
}

export interface CostCenter {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    archived: boolean;
    projects_count: number;
    children_count: number;
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

export type ProjectStatus = 'planning' | 'active' | 'suspended' | 'completed' | 'archived';

export interface Project {
    id: number;
    code: string;
    name: string;
    client_id: number | null;
    client: { id: number; name: string; type: ClientType } | null;
    owner_name: string | null;
    type: string | null;
    type_label: string | null;
    address: string | null;
    postal_code: string | null;
    city: string | null;
    contract_value_cents: number | null;
    starts_on: string | null;
    planned_end_on: string | null;
    /** null = calendário por omissão da empresa. */
    work_calendar_id: number | null;
    work_calendar: { id: number; name: string } | null;
    cost_center_id: number | null;
    cost_center: { id: number; code: string; name: string } | null;
    status: ProjectStatus;
    status_label: string;
    description: string | null;
    team_count?: number;
    created_at: string | null;
    /** O que o utilizador pode fazer nesta obra (só para a interface; o servidor decide). */
    permissions: string[];
}

export type ClientType = 'private' | 'company' | 'public';

export interface Client {
    id: number;
    type: ClientType;
    type_label: string;
    name: string;
    nif: string | null;
    country_code: string;
    email: string | null;
    phone: string | null;
    contact_name: string | null;
    address: string | null;
    postal_code: string | null;
    city: string | null;
    notes: string | null;
    archived: boolean;
    archived_at: string | null;
    projects_count?: number;
    created_at: string | null;
}

export interface ClientOption {
    id: number;
    name: string;
    type: ClientType;
    archived: boolean;
}

/** Chaves ISO: "1" = segunda … "7" = domingo; 0 horas = não se trabalha. */
export type WeeklyHours = Record<'1' | '2' | '3' | '4' | '5' | '6' | '7', number>;

export interface WorkCalendar {
    id: number;
    name: string;
    weekly_hours: WeeklyHours;
    weekly_total: number;
    is_default: boolean;
    projects_count: number | null;
    national_holiday_years: number[];
}

export type HolidayKind = 'national' | 'optional' | 'municipal' | 'company';

export interface Holiday {
    id: number;
    date: string;
    name: string;
    kind: HolidayKind;
    kind_label: string;
}

export interface CalendarDay {
    date: string;
    weekday: number;
    working: boolean;
    hours: number;
    holiday: { name: string; kind: HolidayKind; kind_label: string } | null;
}

export interface ProjectCalendar {
    calendar: { id: number; name: string; is_default: boolean; chosen: boolean };
    month: string;
    today: string;
    days: CalendarDay[];
    working_days: number;
    missing_holiday_years: number[];
    /** Prazo entre o início e o fim previsto (null se faltar uma das datas). */
    term: {
        calendar_days: number;
        working_days: number | null;
        working_days_done: number | null;
        working_days_left: number | null;
        missing_holiday_years: number[];
    } | null;
}

export interface ProjectMember {
    id: number;
    membership_id: number;
    user: { id: number; name: string; email: string };
    membership_status: 'active' | 'suspended';
    job_title: string | null;
    company_role: { id: number; name: string };
    role: { id: number; key: string; name: string };
    added_at: string | null;
    is_me: boolean;
}

export type DiaryItemKind = 'workforce' | 'equipment' | 'material' | 'incident' | 'visit';

export interface DiaryItem {
    id?: number;
    kind: DiaryItemKind;
    kind_label?: string;
    description: string;
    quantity: number | null;
    unit: string | null;
    company: string | null;
    location_id?: number | null;
    element_id?: number | null;
}

export interface DiaryWeather {
    morning?: string | null;
    afternoon?: string | null;
    temp_min?: number | null;
    temp_max?: number | null;
}

export interface MediaItem {
    id: number;
    status: 'processing' | 'ready' | 'failed';
    status_label: string;
    caption: string | null;
    diary_entry_id?: number | null;
    diary_date?: string | null;
    location_id?: number | null;
    element_id?: number | null;
    original_name: string;
    mime: string;
    size_bytes: number;
    sha256: string;
    width: number | null;
    height: number | null;
    taken_at: string | null;
    uploaded_by?: string | null;
    created_at: string | null;
    /** Caminhos da API, relativos à instalação (usar appUrl). */
    urls: { thumb: string | null; preview: string | null; original: string };
}

export interface DiaryEntry {
    id: number;
    project_id: number;
    entry_date: string;
    status: 'draft' | 'submitted';
    status_label: string;
    weather: DiaryWeather | null;
    work_summary: string | null;
    notes: string | null;
    items?: DiaryItem[];
    items_count?: number;
    workforce_total?: number;
    photos?: MediaItem[];
    photos_count?: number;
    created_by?: string | null;
    updated_by?: string | null;
    submitted_by?: string | null;
    submitted_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface DocumentVersion {
    id: number;
    number: number;
    original_name: string;
    mime: string;
    size_bytes: number;
    sha256: string;
    note: string | null;
    uploaded_by?: string | null;
    created_at: string | null;
    downloads_count?: number;
    /** Caminhos da API (usar appUrl). open só para PDF e imagens. */
    urls: { open: string | null; download: string };
}

export interface ProjectDocument {
    id: number;
    title: string;
    description: string | null;
    folder_id: number | null;
    location_id?: number | null;
    element_id?: number | null;
    specialty_id?: number | null;
    created_by?: string | null;
    created_by_me: boolean;
    created_at: string | null;
    updated_at: string | null;
    versions_count?: number;
    current_version?: DocumentVersion | null;
    versions?: DocumentVersion[];
}

export interface DocumentFolderRef {
    id: number;
    name: string;
    parent_id?: number | null;
}

export interface DocumentListing {
    folder: DocumentFolderRef | null;
    path: DocumentFolderRef[];
    folders: { id: number; name: string; items_count: number }[];
    documents: ProjectDocument[];
    tree?: DocumentFolderRef[];
}

export type LocationKind = 'building' | 'floor' | 'zone';

export interface ProjectLocationItem {
    id: number;
    parent_id: number | null;
    kind: LocationKind;
    kind_label: string;
    name: string;
    sort_order: number;
}

export interface ConstructionElementItem {
    id: number;
    location_id: number;
    element_type: string;
    element_type_label: string;
    code: string;
    name: string | null;
    notes: string | null;
    specialty_id: number | null;
}

export interface ProjectStructure {
    locations: ProjectLocationItem[];
    elements: ConstructionElementItem[];
    element_types: { value: string; label: string; prefix: string }[];
    /** Pontos de paragem por libertar (versões antigas da cópia sem rede não têm). */
    holds?: { inspection_id: number; number: number; title: string; status_label: string; location_id: number | null; element_id: number | null }[];
    specialties: SpecialtyRef[];
}

export interface StructureLinks {
    /** null = sem permissão para consultar o diário (ou os documentos). */
    diary_items:
        | {
              id: number;
              entry_id: number;
              entry_date: string;
              kind_label: string;
              description: string;
              quantity: number | null;
              unit: string | null;
              company: string | null;
              location_id: number | null;
              element_id: number | null;
          }[]
        | null;
    photos: MediaItem[] | null;
    documents: ProjectDocument[] | null;
}

/** Ponto de uma lista de verificação (modelo ou inspeção) e as evidências que exige. */
export interface ChecklistItemDef {
    id?: number;
    text: string;
    guidance: string | null;
    requires_photo: boolean;
    min_photos: number;
    requires_measure: boolean;
    measure_unit: string | null;
    measure_min: number | null;
    measure_max: number | null;
}

export interface ChecklistTemplate {
    id: number;
    name: string;
    description: string | null;
    specialty_id: number | null;
    specialty: SpecialtyRef | null;
    version: number;
    hold_point: boolean;
    archived: boolean;
    items_count: number;
    inspections_count: number;
    updated_at: string | null;
    items?: (ChecklistItemDef & { id: number })[];
}

export type InspectionStatus =
    | 'draft'
    | 'requested'
    | 'scheduled'
    | 'in_progress'
    | 'submitted'
    | 'approved'
    | 'approved_with_reservations'
    | 'rejected'
    | 'cancelled';

export type InspectionResultValue = 'ok' | 'nok' | 'na';

export interface InspectionItem extends ChecklistItemDef {
    id: number;
    number: number;
    result: InspectionResultValue | null;
    result_label: string | null;
    measure_value: number | null;
    out_of_tolerance: boolean;
    notes: string | null;
    answered_by: string | null;
    answered_at: string | null;
    photos: MediaItem[];
    issues: { id: number; number: number; status: IssueStatus; status_label: string }[];
}

/** Índice de suficiência das evidências, explicado critério a critério. */
export interface EvidenceIndex {
    score: number | null;
    can_submit: boolean;
    criteria: { key: string; label: string; required: boolean; met: number; total: number; missing: number[] }[];
}

export interface InspectionEvent {
    sequence: number;
    action: string;
    label: string;
    from_status: InspectionStatus | null;
    to_status: InspectionStatus;
    user_name: string;
    reason: string | null;
    scheduled_for: string | null;
    evidence_score: number | null;
    hash: string;
    created_at: string;
}

export interface Inspection {
    id: number;
    project_id: number;
    number: number;
    title: string;
    status: InspectionStatus;
    status_label: string;
    remote: boolean;
    location_id: number | null;
    element_id: number | null;
    specialty_id: number | null;
    specialty?: SpecialtyRef | null;
    template?: { id: number; name: string; version: number | null } | null;
    scheduled_for: string | null;
    requested_at: string | null;
    started_at: string | null;
    submitted_at: string | null;
    decided_at: string | null;
    decision_note: string | null;
    evidence_score: number | null;
    hold_point: boolean;
    hold_released_at: string | null;
    hold_released_by?: string | null;
    /** Ponto de paragem por libertar: trabalhos parados. */
    holding_work: boolean;
    requested_by?: string | null;
    inspector?: string | null;
    decided_by?: string | null;
    reinspection_of?: { id: number; number: number } | null;
    items_count?: number;
    answered_count?: number;
    nok_count?: number;
    created_at: string | null;
}

/** Ficha completa (GET de uma inspeção). */
export interface InspectionDetail extends Inspection {
    description: string | null;
    created_by: string | null;
    items: InspectionItem[];
    reinspections: { id: number; number: number; status_label: string }[];
    evidence: EvidenceIndex;
    events: InspectionEvent[];
    chain: { valid: boolean; broken_at: number | null; last_hash: string | null };
    can: {
        edit: boolean;
        fill: boolean;
        reinspect: boolean;
        release_hold: boolean;
        raise_issue: boolean;
        has_nok: boolean;
        actions: { key: string; label: string; reason: boolean }[];
    };
}

export type IssueStatus = 'open' | 'in_progress' | 'resolved' | 'closed' | 'cancelled';

export interface Issue {
    id: number;
    project_id: number;
    number: number;
    reference: string;
    kind: 'nonconformity' | 'occurrence';
    kind_label: string;
    severity: 'minor' | 'major' | 'critical';
    severity_label: string;
    status: IssueStatus;
    status_label: string;
    title: string;
    location_id: number | null;
    element_id: number | null;
    specialty_id: number | null;
    specialty?: SpecialtyRef | null;
    assignee_user_id: number | null;
    assignee?: string | null;
    due_on: string | null;
    overdue: boolean;
    escalated_at: string | null;
    inspection?: { id: number; number: number; title: string } | null;
    photos_count?: number;
    created_at: string | null;
}

export interface IssueEventItem {
    id: number;
    type: string;
    label: string;
    user_name: string | null;
    body: string | null;
    payload: Record<string, unknown> | null;
    created_at: string;
}

export interface IssueDetail extends Issue {
    description: string | null;
    inspection_item_id: number | null;
    inspection_item_number: number | null;
    resolution_note: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
    closed_by: string | null;
    closed_at: string | null;
    created_by: string | null;
    photos: (MediaItem & { can_remove: boolean })[];
    events: IssueEventItem[];
    can: { edit: boolean; validate: boolean; comment: boolean; photos: boolean; actions: { key: string; label: string; reason: boolean }[] };
}

export interface IssueAssignee {
    user_id: number;
    name: string;
    role: string;
}
