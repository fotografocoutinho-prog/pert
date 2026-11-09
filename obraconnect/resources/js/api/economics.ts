import { getData, http } from './http';

/** Fase 4: biblioteca de preços, orçamentos, medições, autos, compras, subempreitadas, horas, equipamentos, custos e planeamento. */

export interface Option {
    value: string;
    label: string;
}

export interface PriceResource {
    id: number;
    kind: string;
    kind_label: string;
    code: string;
    name: string;
    unit: string;
    unit_cost_cents: number;
    archived: boolean;
    used: boolean;
}

export interface ArticleComponentRow {
    resource_id: number;
    code?: string;
    name?: string;
    kind?: string;
    unit?: string;
    quantity: number;
    unit_cost_cents?: number;
}

export interface Article {
    id: number;
    code: string;
    description: string;
    unit: string;
    composed: boolean;
    unit_cost_cents: number;
    notes: string | null;
    archived: boolean;
    components: ArticleComponentRow[];
}

export interface BudgetSummary {
    id: number;
    code: string;
    name: string;
    version: number;
    status: 'draft' | 'approved' | 'superseded';
    status_label: string;
    revision_of_id: number | null;
    approved_at: string | null;
    items_count: number;
    cost_cents: number;
    sale_cents: number;
    total_cents: number;
    margin_pct: number | null;
}

export interface BudgetComponent {
    resource_id: number | null;
    kind: string;
    kind_label?: string;
    name: string;
    unit: string;
    quantity: number;
    unit_cost_cents: number;
}

export interface BudgetItem {
    id: number;
    chapter_id: number;
    article_id: number | null;
    specialty_id: number | null;
    code: string;
    description: string;
    unit: string;
    quantity: number;
    unit_cost_cents: number;
    unit_price_cents: number | null;
    sale_unit_cents: number;
    cost_cents: number;
    sale_cents: number;
    measured_quantity: number | null;
    components: BudgetComponent[];
}

export interface BudgetChapter {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    cost_cents: number;
    sale_cents: number;
}

export interface Budget extends BudgetSummary {
    vat_mode: string;
    vat_mode_label: string;
    vat_rate: number;
    overhead_pct: number;
    profit_pct: number;
    notes: string | null;
    approved_by: string | null;
    created_by: string | null;
    vat_cents: number;
    by_category: Record<string, number>;
    chapters: BudgetChapter[];
    items: BudgetItem[];
    can: { edit: boolean; approve: boolean; revise: boolean };
}

export interface MeasurementRow {
    id: number;
    budget_item_id: number;
    item: { code: string; description: string; unit: string } | null;
    measured_on: string;
    description: string | null;
    count: number | null;
    length: number | null;
    width: number | null;
    height: number | null;
    quantity: number;
    location_id: number | null;
    element_id: number | null;
    certificate: { id: number; number: number; status: string } | null;
    created_by: string | null;
}

export interface MeasurementProgress {
    budget_item_id: number;
    code: string;
    description: string;
    unit: string;
    budget_quantity: number;
    measured_quantity: number;
    certified_quantity: number;
    measured_pct: number | null;
}

export interface CertificateSummary {
    id: number;
    number: number;
    kind: 'owner' | 'contract';
    contract: { id: number; code: string; title: string; partner: string | null } | null;
    period_start: string;
    period_end: string;
    status: 'draft' | 'submitted' | 'approved';
    status_label: string;
    amount_cents: number;
    retention_cents: number;
    net_cents: number;
    approved_at: string | null;
    /** Nome de quem o propôs no portal de subempreiteiros (null = feito pela empresa). */
    proposed_by?: string | null;
}

export interface CertificateLine {
    id: number;
    code: string | null;
    description: string;
    unit: string;
    unit_price_cents: number;
    qty_contract: number;
    qty_previous: number;
    qty_period: number;
    qty_accumulated: number;
    amount_cents: number;
    over_contract: boolean;
}

export interface Certificate extends CertificateSummary {
    notes: string | null;
    approved_by: string | null;
    created_by: string | null;
    previous_cents: number;
    lines: CertificateLine[];
    can: { edit: boolean; submit: boolean; approve: boolean; reopen: boolean; delete: boolean; invoice: boolean };
    invoice_draft: { id: number; code: string | null; status: string; status_label: string; official_number: string | null } | null;
}

export interface PartnerDocument {
    id: number;
    title: string;
    reference: string | null;
    expires_on: string | null;
    state: 'valid' | 'expiring' | 'expired';
    has_file?: boolean;
    original_name?: string | null;
    from_portal?: boolean;
}

export interface Partner {
    id: number;
    kind: 'supplier' | 'subcontractor';
    kind_label: string;
    name: string;
    nif: string | null;
    email: string | null;
    phone: string | null;
    contact_name: string | null;
    address: string | null;
    notes: string | null;
    archived: boolean;
    specialties: { id: number; code: string; name: string }[];
    portal_users_count?: number;
    documents: PartnerDocument[];
    contracts_count: number;
    purchase_orders_count: number;
}

export interface ContractItem {
    id?: number;
    budget_item_id: number | null;
    description: string;
    unit: string;
    quantity: number;
    unit_price_cents: number;
    total_cents?: number;
}

export interface Contract {
    id: number;
    code: string;
    title: string;
    partner_id: number;
    partner: string | null;
    status: 'draft' | 'active' | 'closed';
    status_label: string;
    retention_pct: number;
    starts_on: string | null;
    ends_on: string | null;
    notes: string | null;
    value_cents: number;
    certified_cents: number;
    retained_cents: number;
    certified_pct: number | null;
    certificates_count: number;
    items: ContractItem[];
    can: { edit: boolean; activate: boolean; close: boolean; certify: boolean };
}

export interface PurchaseLine {
    id?: number;
    resource_id: number | null;
    budget_item_id: number | null;
    description: string;
    unit: string;
    quantity: number;
    unit_price_cents: number;
    received_quantity?: number;
    total_cents?: number;
}

export interface PurchaseOrder {
    id: number;
    number: number;
    partner_id: number;
    partner: string | null;
    status: 'draft' | 'approved' | 'received' | 'invoiced' | 'cancelled';
    status_label: string;
    category: string;
    category_label: string;
    cost_center: { id: number; code: string; name: string } | null;
    cost_center_id: number | null;
    ordered_on: string | null;
    expected_on: string | null;
    notes: string | null;
    total_cents: number;
    invoice_number: string | null;
    invoiced_on: string | null;
    invoiced_cents: number | null;
    lines: PurchaseLine[];
    can: { edit: boolean; approve: boolean; receive: boolean; invoice: boolean; cancel: boolean };
}

export interface CostReport {
    budget: { id: number; code: string; version: number; cost_cents: number; sale_cents: number } | null;
    ceiling_cents: number | null;
    ceiling_is_custom: boolean;
    planned_cents: number;
    committed_cents: number;
    actual_cents: number;
    forecast_cents: number;
    ceiling_used_pct: number | null;
    alert: 'ok' | 'warning' | 'over' | null;
    revenue: { contract_cents: number | null; certified_cents: number; certified_pct: number | null };
    margin_forecast_cents: number | null;
    by_category: { category: string; label: string; planned_cents: number; committed_cents: number; actual_cents: number; forecast_cents: number }[];
    by_cost_center: { cost_center_id: number | null; label: string; committed_cents: number; actual_cents: number }[];
    monthly_actual: { month: string; actual_cents: number }[];
    entries: {
        id: number;
        category: string;
        category_label: string;
        kind: 'committed' | 'actual';
        description: string;
        amount_cents: number;
        incurred_on: string;
        cost_center: string | null;
        created_by: string | null;
        supplier_document_id: number | null;
    }[];
    categories: Option[];
    can_manage: boolean;
}

export interface FinanceRow {
    id: number;
    code: string;
    name: string;
    status: string;
    status_label: string;
    contract_cents: number | null;
    certified_cents: number;
    certified_pct: number | null;
    invoiced_cents: number;
    actual_cents: number;
    committed_cents: number;
    forecast_cents: number;
    ceiling_cents: number | null;
    ceiling_used_pct: number | null;
    alert: 'ok' | 'warning' | 'over' | null;
    margin_forecast_cents: number | null;
    outlook: FinanceOutlook;
}

export interface FinanceOutlook {
    cost_available: boolean;
    eac_cents: number | null;
    method: 'trend' | 'budget' | null;
    confidence: ForecastConfidence | null;
    alert: 'ok' | 'warning' | 'over' | null;
    ceiling_used_pct: number | null;
    margin_cents: number | null;
    margin_pct: number | null;
    top_reason: string | null;
    schedule_available: boolean;
    schedule_status: ScheduleStatus | null;
    forecast_finish_on: string | null;
    contract_end_on: string | null;
    delay_contract_days: number | null;
    delay_plan_days: number | null;
    previous: { taken_on: string; eac_cents: number | null; forecast_finish_on: string | null } | null;
}

export type ForecastLevel = 'danger' | 'warning' | 'info' | 'good';
export type ForecastConfidence = 'low' | 'medium' | 'high';
export type ScheduleStatus = 'finished' | 'late' | 'behind' | 'on_track';

export interface ForecastReason {
    level: ForecastLevel;
    text: string;
    /** Separador da obra onde se trata o assunto. */
    tab: string | null;
}

export interface CostForecast {
    available: boolean;
    message?: string;
    budget?: { id: number; code: string; version: number };
    bac_cents: number;
    earned_cents: number;
    progress_pct: number;
    remaining_cents: number;
    actual_cents: number;
    incurred_cents: number;
    received_cents: number;
    incurred_to_measurement_cents: number;
    committed_cents: number;
    last_measured_on: string | null;
    measurement_age_days: number | null;
    cost_per_euro: number | null;
    method: 'trend' | 'budget';
    trend_available: boolean;
    forecast_cents: number;
    at_budget_cents: number;
    at_trend_cents: number | null;
    floor_cents: number;
    floor_binds: boolean;
    variance_cents: number;
    ceiling_cents: number | null;
    ceiling_used_pct: number | null;
    alert: 'ok' | 'warning' | 'over' | null;
    sale_cents: number;
    margin_cents: number;
    margin_pct: number | null;
    budget_margin_pct: number | null;
    confidence: ForecastConfidence;
    confidence_text: string;
    reasons: ForecastReason[];
}

export interface ScheduleForecastActivity {
    id: number;
    code: string;
    name: string;
    progress_pct: number;
    planned_pct: number;
    planned_start_on: string;
    planned_finish_on: string;
    forecast_start_on: string;
    forecast_finish_on: string;
    slip_days: number;
    critical: boolean;
    late_start: boolean;
    behind: boolean;
    waiting: boolean;
    holds: { id: number; number: number }[];
}

export interface ScheduleForecast {
    available: boolean;
    message?: string;
    started: boolean;
    finished: boolean;
    status: ScheduleStatus;
    start_on: string;
    planned_finish_on: string | null;
    forecast_finish_on: string | null;
    contract_end_on: string | null;
    delay_plan_days: number | null;
    delay_contract_days: number | null;
    planned_progress_pct: number;
    progress_pct: number;
    activities: ScheduleForecastActivity[];
    reasons: ForecastReason[];
}

export interface CostHistoryPoint {
    week_start: string;
    taken_on: string;
    forecast_cents: number;
    bac_cents: number | null;
    ceiling_cents: number | null;
    actual_cents: number | null;
    committed_cents: number | null;
    progress_pct: number | null;
    margin_pct: number | null;
    confidence: ForecastConfidence | null;
    current: boolean;
}

export interface ScheduleHistoryPoint {
    week_start: string;
    taken_on: string;
    forecast_finish_on: string;
    planned_finish_on: string | null;
    contract_end_on: string | null;
    progress_pct: number | null;
    planned_progress_pct: number | null;
    current: boolean;
}

export interface ProjectForecast {
    as_of: string;
    cost: CostForecast | null;
    schedule: ScheduleForecast | null;
    history: { cost: CostHistoryPoint[] | null; schedule: ScheduleHistoryPoint[] | null };
}

export type AlertTypeKey = 'cost_ceiling' | 'cost_worse' | 'finish_contract' | 'finish_worse' | 'measurements_stale' | 'hold_point' | 'supplier_docs';

export interface ProjectAlert {
    id: number;
    type: AlertTypeKey;
    type_label: string;
    level: 'danger' | 'warning';
    title: string;
    detail: string;
    project: { id: number; code: string; name: string } | null;
    path: string;
    opened_at: string;
}

export interface AlertRules {
    cost_ceiling: { on: boolean; pct: number };
    cost_worse: { on: boolean; pct: number };
    finish_contract: { on: boolean };
    finish_worse: { on: boolean; days: number };
    measurements_stale: { on: boolean; days: number };
    hold_point: { on: boolean; days: number };
    supplier_docs: { on: boolean; days: number };
}

export interface AlertSettings {
    enabled: boolean;
    email: boolean;
    rules: AlertRules;
}

export interface Worker {
    id: number;
    name: string;
    nif: string | null;
    category: string | null;
    hourly_cost_cents: number | null;
    partner_id: number | null;
    partner: string | null;
    user_id: number | null;
    user: { name: string; email: string } | null;
    active: boolean;
    time_entries_count: number;
}

export interface TimeEntry {
    id: number;
    worker_id: number;
    worker: string | null;
    category: string | null;
    work_date: string;
    hours: number;
    overtime_hours: number;
    activity: { id: number; code: string; name: string } | null;
    description: string | null;
    status: 'pending' | 'approved' | 'rejected';
    status_label: string;
    cost_cents: number | null;
    approved_by: string | null;
}

export interface TimeWeek {
    week_start: string;
    week_end: string;
    entries: TimeEntry[];
    pending_count: number;
    can: { register_team: boolean; register_own: boolean; approve: boolean };
    own_worker_ids: number[];
}

export interface EquipmentSummary {
    id: number;
    code: string;
    name: string;
    category: string | null;
    serial_number: string | null;
    hourly_cost_cents: number;
    status: 'available' | 'in_use' | 'maintenance' | 'retired';
    status_label: string;
    project: { id: number; code: string; name: string } | null;
    project_id: number | null;
    next_maintenance_on: string | null;
    maintenance_due: boolean;
}

export interface EquipmentDetail extends EquipmentSummary {
    notes: string | null;
    qr_url: string;
    hours_total: number;
    usages: { id: number; used_on: string; hours: number; project: string | null; notes: string | null }[];
    maintenance: { id: number; performed_on: string; description: string; cost_cents: number; next_due_on: string | null }[];
    can_manage: boolean;
}

export interface EquipmentUsage {
    id: number;
    equipment: { id: number; code: string; name: string } | null;
    used_on: string;
    hours: number;
    cost_cents: number;
    notes: string | null;
    created_by: string | null;
}

export interface PlanActivity {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    duration_days: number;
    optimistic_days: number | null;
    likely_days: number | null;
    pessimistic_days: number | null;
    start_no_earlier_than: string | null;
    location_id: number | null;
    element_id: number | null;
    completed_at: string | null;
    progress_pct: number;
    holds: { id: number; number: number }[];
    predecessors: { id: number; predecessor_id: number; type: string; lag_days: number }[];
    summary?: boolean;
    es?: number;
    ef?: number;
    total_float?: number;
    critical?: boolean;
    start_on?: string;
    finish_on?: string;
    pert_expected_days?: number | null;
}

export interface Plan {
    project: { starts_on: string; planned_end_on: string | null };
    start_on: string | null;
    finish_on: string | null;
    duration_days: number;
    late: boolean;
    pert: { expected_days: number; sigma_days: number } | null;
    error: string | null;
    activities: PlanActivity[];
    can_manage: boolean;
}

const p = (projectId: number) => `/api/v1/projects/${projectId}`;
const data = async <T>(promise: Promise<{ data: { data: T } }>) => (await promise).data.data;

export const priceLibraryApi = {
    get: () => getData<{ kinds: Option[]; resources: PriceResource[]; articles: Article[] }>('/api/v1/price-library'),
    createResource: (payload: Record<string, unknown>) => data<PriceResource>(http.post('/api/v1/price-library/resources', payload)),
    updateResource: (id: number, payload: Record<string, unknown>) => data<PriceResource>(http.put(`/api/v1/price-library/resources/${id}`, payload)),
    removeResource: (id: number) => http.delete(`/api/v1/price-library/resources/${id}`),
    createArticle: (payload: Record<string, unknown>) => data<Article>(http.post('/api/v1/price-library/articles', payload)),
    updateArticle: (id: number, payload: Record<string, unknown>) => data<Article>(http.put(`/api/v1/price-library/articles/${id}`, payload)),
    removeArticle: (id: number) => http.delete(`/api/v1/price-library/articles/${id}`),
};

export const budgetsApi = {
    list: (projectId: number) => getData<BudgetSummary[]>(`${p(projectId)}/budgets`),
    get: (projectId: number, id: number) => getData<Budget>(`${p(projectId)}/budgets/${id}`),
    create: (projectId: number, payload: Record<string, unknown>) => data<Budget>(http.post(`${p(projectId)}/budgets`, payload)),
    update: (projectId: number, id: number, payload: Record<string, unknown>) => data<Budget>(http.put(`${p(projectId)}/budgets/${id}`, payload)),
    remove: (projectId: number, id: number) => http.delete(`${p(projectId)}/budgets/${id}`),
    approve: (projectId: number, id: number) => data<Budget>(http.post(`${p(projectId)}/budgets/${id}/approve`)),
    revise: (projectId: number, id: number) => data<Budget>(http.post(`${p(projectId)}/budgets/${id}/revise`)),
    addChapter: (projectId: number, id: number, payload: Record<string, unknown>) => data<Budget>(http.post(`${p(projectId)}/budgets/${id}/chapters`, payload)),
    updateChapter: (projectId: number, id: number, chapterId: number, payload: Record<string, unknown>) =>
        data<Budget>(http.put(`${p(projectId)}/budgets/${id}/chapters/${chapterId}`, payload)),
    removeChapter: (projectId: number, id: number, chapterId: number) => data<Budget>(http.delete(`${p(projectId)}/budgets/${id}/chapters/${chapterId}`)),
    addItem: (projectId: number, id: number, payload: Record<string, unknown>) => data<Budget>(http.post(`${p(projectId)}/budgets/${id}/items`, payload)),
    updateItem: (projectId: number, id: number, itemId: number, payload: Record<string, unknown>) =>
        data<Budget>(http.put(`${p(projectId)}/budgets/${id}/items/${itemId}`, payload)),
    removeItem: (projectId: number, id: number, itemId: number) => data<Budget>(http.delete(`${p(projectId)}/budgets/${id}/items/${itemId}`)),
    import: async (projectId: number, id: number, file: File) => {
        const form = new FormData();
        form.append('file', file);
        const r = await http.post<{ data: Budget; meta: { imported: { chapters: number; items: number } } }>(`${p(projectId)}/budgets/${id}/import`, form, { timeout: 120_000 });
        return r.data;
    },
    exportUrl: (projectId: number, id: number) => `${p(projectId)}/budgets/${id}/export`,
};

export const measurementsApi = {
    list: (projectId: number, params: Record<string, unknown> = {}) =>
        getData<{ budget: { id: number; code: string; version: number; name: string } | null; progress: MeasurementProgress[]; measurements: MeasurementRow[] }>(
            `${p(projectId)}/measurements`,
            params,
        ),
    create: (projectId: number, payload: Record<string, unknown>) => data<MeasurementRow>(http.post(`${p(projectId)}/measurements`, payload)),
    remove: (projectId: number, id: number) => http.delete(`${p(projectId)}/measurements/${id}`),
};

export const certificatesApi = {
    list: (projectId: number, contractId?: number) => getData<CertificateSummary[]>(`${p(projectId)}/certificates`, contractId ? { contract_id: contractId } : undefined),
    get: (projectId: number, id: number) => getData<Certificate>(`${p(projectId)}/certificates/${id}`),
    create: (projectId: number, payload: Record<string, unknown>) => data<Certificate>(http.post(`${p(projectId)}/certificates`, payload)),
    updateLine: (projectId: number, id: number, lineId: number, qty: number) => data<Certificate>(http.put(`${p(projectId)}/certificates/${id}/lines/${lineId}`, { qty_period: qty })),
    act: (projectId: number, id: number, action: 'submit' | 'approve' | 'reopen') => data<Certificate>(http.post(`${p(projectId)}/certificates/${id}/${action}`)),
    remove: (projectId: number, id: number) => http.delete(`${p(projectId)}/certificates/${id}`),
};

export const partnersApi = {
    list: (kind?: string) => getData<Partner[]>('/api/v1/partners', kind ? { kind } : undefined),
    create: (payload: Record<string, unknown>) => data<Partner>(http.post('/api/v1/partners', payload)),
    update: (id: number, payload: Record<string, unknown>) => data<Partner>(http.put(`/api/v1/partners/${id}`, payload)),
    remove: (id: number) => http.delete(`/api/v1/partners/${id}`),
    addDocument: (id: number, payload: Record<string, unknown> | FormData) => data<Partner>(http.post(`/api/v1/partners/${id}/documents`, payload)),
    removeDocument: (id: number, documentId: number) => data<Partner>(http.delete(`/api/v1/partners/${id}/documents/${documentId}`)),
};

export const contractsApi = {
    list: (projectId: number) => getData<Contract[]>(`${p(projectId)}/contracts`),
    create: (projectId: number, payload: Record<string, unknown>) => data<Contract>(http.post(`${p(projectId)}/contracts`, payload)),
    update: (projectId: number, id: number, payload: Record<string, unknown>) => data<Contract>(http.put(`${p(projectId)}/contracts/${id}`, payload)),
    act: (projectId: number, id: number, action: 'activate' | 'close') => data<Contract>(http.post(`${p(projectId)}/contracts/${id}/actions/${action}`)),
    remove: (projectId: number, id: number) => http.delete(`${p(projectId)}/contracts/${id}`),
};

export const purchasesApi = {
    list: (projectId: number) => getData<PurchaseOrder[]>(`${p(projectId)}/purchase-orders`),
    create: (projectId: number, payload: Record<string, unknown>) => data<PurchaseOrder>(http.post(`${p(projectId)}/purchase-orders`, payload)),
    update: (projectId: number, id: number, payload: Record<string, unknown>) => data<PurchaseOrder>(http.put(`${p(projectId)}/purchase-orders/${id}`, payload)),
    act: (projectId: number, id: number, action: string, payload: Record<string, unknown> = {}) =>
        data<PurchaseOrder>(http.post(`${p(projectId)}/purchase-orders/${id}/actions/${action}`, payload)),
    remove: (projectId: number, id: number) => http.delete(`${p(projectId)}/purchase-orders/${id}`),
};

export const costsApi = {
    get: (projectId: number) => getData<CostReport>(`${p(projectId)}/costs`),
    setCeiling: (projectId: number, cents: number | null) => data<CostReport>(http.put(`${p(projectId)}/costs/ceiling`, { cost_ceiling_cents: cents })),
    addEntry: (projectId: number, payload: Record<string, unknown>) => data<CostReport>(http.post(`${p(projectId)}/cost-entries`, payload)),
    removeEntry: (projectId: number, id: number) => data<CostReport>(http.delete(`${p(projectId)}/cost-entries/${id}`)),
    exportUrl: (projectId: number) => `${p(projectId)}/costs/export`,
    finance: () => getData<{ projects: FinanceRow[]; totals: Record<string, number> }>('/api/v1/finance'),
};

export const forecastApi = {
    get: (projectId: number) => getData<ProjectForecast>(`${p(projectId)}/forecast`),
};

export const alertsApi = {
    list: (projectId?: number) => getData<ProjectAlert[]>('/api/v1/alerts' + (projectId ? `?project_id=${projectId}` : '')),
    settings: () => getData<AlertSettings>('/api/v1/company/alerts'),
    saveSettings: (payload: AlertSettings) => data<AlertSettings>(http.put('/api/v1/company/alerts', payload)),
};

export const workersApi = {
    list: () => getData<Worker[]>('/api/v1/workers'),
    create: (payload: Record<string, unknown>) => data<Worker>(http.post('/api/v1/workers', payload)),
    update: (id: number, payload: Record<string, unknown>) => data<Worker>(http.put(`/api/v1/workers/${id}`, payload)),
    remove: (id: number) => http.delete(`/api/v1/workers/${id}`),
    payrollUrl: (month: string) => `/api/v1/time-entries/payroll?month=${month}`,
};

export const timeApi = {
    week: (projectId: number, week: string) => getData<TimeWeek>(`${p(projectId)}/time-entries`, { week }),
    save: (projectId: number, payload: Record<string, unknown>) => data<{ saved: number }>(http.post(`${p(projectId)}/time-entries`, payload)),
    decide: (projectId: number, decision: 'approve' | 'reject', ids: number[]) => data<{ updated: number }>(http.post(`${p(projectId)}/time-entries/${decision}`, { ids })),
    remove: (projectId: number, id: number) => http.delete(`${p(projectId)}/time-entries/${id}`),
};

export const equipmentApi = {
    list: () => getData<EquipmentSummary[]>('/api/v1/equipment'),
    get: (id: number) => getData<EquipmentDetail>(`/api/v1/equipment/${id}`),
    byToken: (token: string) => getData<EquipmentDetail>(`/api/v1/equipment/qr/${token}`),
    create: (payload: Record<string, unknown>) => data<EquipmentDetail>(http.post('/api/v1/equipment', payload)),
    update: (id: number, payload: Record<string, unknown>) => data<EquipmentDetail>(http.put(`/api/v1/equipment/${id}`, payload)),
    remove: (id: number) => http.delete(`/api/v1/equipment/${id}`),
    qrUrl: (id: number) => `/api/v1/equipment/${id}/qr`,
    addMaintenance: (id: number, payload: Record<string, unknown>) => data<EquipmentDetail>(http.post(`/api/v1/equipment/${id}/maintenance`, payload)),
    removeMaintenance: (id: number, recordId: number) => data<EquipmentDetail>(http.delete(`/api/v1/equipment/${id}/maintenance/${recordId}`)),
    usages: (projectId: number) => getData<{ usages: EquipmentUsage[]; on_site: EquipmentSummary[]; can_register: boolean }>(`${p(projectId)}/equipment-usages`),
    addUsage: (projectId: number, payload: Record<string, unknown>) => data<EquipmentUsage>(http.post(`${p(projectId)}/equipment-usages`, payload)),
    removeUsage: (projectId: number, id: number) => http.delete(`${p(projectId)}/equipment-usages/${id}`),
};

export const planningApi = {
    get: (projectId: number) => getData<Plan>(`${p(projectId)}/planning`),
    addActivity: (projectId: number, payload: Record<string, unknown>) => data<Plan>(http.post(`${p(projectId)}/activities`, payload)),
    updateActivity: (projectId: number, id: number, payload: Record<string, unknown>) => data<Plan>(http.put(`${p(projectId)}/activities/${id}`, payload)),
    removeActivity: (projectId: number, id: number) => data<Plan>(http.delete(`${p(projectId)}/activities/${id}`)),
    progress: (projectId: number, id: number, pct: number) => data<Plan>(http.post(`${p(projectId)}/activities/${id}/progress`, { progress_pct: pct })),
    addDependency: (projectId: number, payload: Record<string, unknown>) => data<Plan>(http.post(`${p(projectId)}/dependencies`, payload)),
    removeDependency: (projectId: number, id: number) => data<Plan>(http.delete(`${p(projectId)}/dependencies/${id}`)),
};

export type InvoiceDraftStatus = 'draft' | 'ready' | 'invoiced' | 'cancelled';

export interface InvoiceDraftSummary {
    id: number;
    code: string | null;
    status: InvoiceDraftStatus;
    status_label: string;
    issue_date: string | null;
    due_date: string | null;
    client_name: string;
    client_nif: string | null;
    project: { id: number; code: string; name: string } | null;
    reference: string | null;
    certificate_id: number | null;
    net_cents: number;
    vat_cents: number;
    total_cents: number;
    official_number: string | null;
    official_date: string | null;
}

export interface InvoiceDraftLine {
    id?: number;
    code: string | null;
    description: string;
    unit: string;
    quantity: number;
    unit_price_cents: number;
    discount_pct: number;
    vat_rate: number;
    net_cents?: number;
    vat_cents?: number;
}

export interface InvoiceDraft extends InvoiceDraftSummary {
    client_id: number | null;
    project_id: number | null;
    client_address: string | null;
    client_postal_code: string | null;
    client_city: string | null;
    client_country: string;
    client_email: string | null;
    vat_exemption_code: string | null;
    vat_exemption_reason: string | null;
    notes: string | null;
    certificate: { id: number; project_id: number; number: number } | null;
    by_rate: { rate: number; net_cents: number; vat_cents: number }[];
    lines: InvoiceDraftLine[];
    created_by: string | null;
    invoiced_by: string | null;
    can: { edit: boolean; ready: boolean; reopen: boolean; invoice: boolean; cancel: boolean; delete: boolean };
}

const query = (params: Record<string, unknown>) =>
    Object.entries(params)
        .flatMap(([k, v]) => (Array.isArray(v) ? v.map((x) => `${k}[]=${encodeURIComponent(String(x))}`) : v === undefined || v === null || v === '' ? [] : [`${k}=${encodeURIComponent(String(v))}`]))
        .join('&');

export const invoicingApi = {
    list: (params: Record<string, unknown> = {}) => getData<InvoiceDraftSummary[]>('/api/v1/invoice-drafts', params),
    get: (id: number) => getData<InvoiceDraft>(`/api/v1/invoice-drafts/${id}`),
    create: (payload: Record<string, unknown>) => data<InvoiceDraft>(http.post('/api/v1/invoice-drafts', payload)),
    update: (id: number, payload: Record<string, unknown>) => data<InvoiceDraft>(http.put(`/api/v1/invoice-drafts/${id}`, payload)),
    remove: (id: number) => http.delete(`/api/v1/invoice-drafts/${id}`),
    act: (id: number, action: 'ready' | 'reopen' | 'invoice' | 'cancel', payload: Record<string, unknown> = {}) =>
        data<InvoiceDraft>(http.post(`/api/v1/invoice-drafts/${id}/actions/${action}`, payload)),
    exportUrl: (format: 'xlsx' | 'csv' | 'json', params: Record<string, unknown> = {}) => `/api/v1/invoice-drafts/export?${query({ format, ...params })}`,
    pdfUrl: (id: number) => `/api/v1/invoice-drafts/${id}/pdf`,
};

export type SupplierDocStatus = 'reading' | 'to_review' | 'posted' | 'rejected';

export interface SupplierDocSummary {
    id: number;
    kind: string;
    kind_label: string;
    status: SupplierDocStatus;
    status_label: string;
    supplier: string | null;
    supplier_nif: string | null;
    number: string | null;
    issue_date: string | null;
    net_cents: number | null;
    gross_cents: number | null;
    project: { id: number; code: string; name: string } | null;
    reader: string | null;
    confidence: number | null;
    attention: boolean;
    original_name: string;
    created_at: string | null;
}

export interface SupplierDocLine {
    code: string | null;
    description: string;
    quantity: number | null;
    unit: string | null;
    unit_price: number | null;
    vat_rate: number | null;
    total: number | null;
    arithmetic_ok: boolean | null;
}

export interface SupplierDoc extends SupplierDocSummary {
    net_effective_cents: number | null;
    category: string;
    category_label: string;
    file: { name: string; mime: string; size_bytes: number; is_pdf: boolean };
    reader_document_id: string | null;
    review_reasons: string[];
    read_error: string | null;
    read_at: string | null;
    lines: SupplierDocLine[];
    supplier_name: string | null;
    customer_nif: string | null;
    atcud: string | null;
    due_date: string | null;
    order_reference: string | null;
    vat_cents: number | null;
    notes: string | null;
    project_id: number | null;
    partner_id: number | null;
    partner: { id: number; name: string; nif: string | null; kind: string } | null;
    purchase_order_id: number | null;
    purchase_order: { id: number; number: number; status: string; status_label: string; total_cents: number; project_id: number } | null;
    cost_entry_id: number | null;
    cost_center_id: number | null;
    cost_center: string | null;
    posted_at: string | null;
    posted_by: string | null;
    created_by: string | null;
    warnings: string[];
    suggestions: {
        purchase_orders: { id: number; number: number; project: { id: number; code: string; name: string }; status_label: string; total_cents: number; reason: string | null }[];
    };
    options: { kinds: Option[]; categories: Option[] };
    can: { edit: boolean; post: boolean; unpost: boolean; reject: boolean; reopen: boolean; read: boolean; delete: boolean; create_supplier: boolean };
}

export interface SupplierDocList {
    data: SupplierDocSummary[];
    meta: { counts: Record<SupplierDocStatus, number>; reader_enabled: boolean; can_upload: boolean };
}

export const supplierDocsApi = {
    list: async (params: Record<string, unknown> = {}) => (await http.get<SupplierDocList>('/api/v1/supplier-documents', { params })).data,
    get: (id: number) => getData<SupplierDoc>(`/api/v1/supplier-documents/${id}`),
    upload: async (file: File, projectId?: number | null, onProgress?: (percent: number) => void) => {
        const form = new FormData();
        form.append('file', file);
        if (projectId) form.append('project_id', String(projectId));
        const r = await http.post<{ data: SupplierDoc }>('/api/v1/supplier-documents', form, {
            timeout: 600_000,
            onUploadProgress: (e) => onProgress?.(e.total ? Math.round((e.loaded * 100) / e.total) : 0),
        });
        return r.data.data;
    },
    update: (id: number, payload: Record<string, unknown>) => data<SupplierDoc>(http.put(`/api/v1/supplier-documents/${id}`, payload)),
    act: (id: number, action: 'post' | 'unpost' | 'reject' | 'reopen' | 'read', payload: Record<string, unknown> = {}) =>
        data<SupplierDoc>(http.post(`/api/v1/supplier-documents/${id}/actions/${action}`, payload, { timeout: 180_000 })),
    remove: (id: number) => http.delete(`/api/v1/supplier-documents/${id}`),
    fileUrl: (id: number) => `/api/v1/supplier-documents/${id}/file`,
};

/** "1.234,5" → 123450 (euros escritos → cêntimos); vazio → null. */
export function eurosToCents(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined || String(value).trim() === '') return null;
    const text = String(value).replace(/\s|€/g, '');
    const normalized = text.includes(',') ? text.replace(/\./g, '').replace(',', '.') : text;
    const n = Number(normalized);
    return Number.isFinite(n) ? Math.round(n * 100) : null;
}

/** 123450 → "1234,50" (para pôr num campo). */
export function centsToInput(cents: number | null | undefined): string {
    return cents === null || cents === undefined ? '' : (cents / 100).toFixed(2).replace('.', ',');
}

/** "12,5" → 12.5. */
export function parseNumber(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined || String(value).trim() === '') return null;
    const n = Number(String(value).replace(/\s/g, '').replace(',', '.'));
    return Number.isFinite(n) ? n : null;
}

/** 17.34 → "17,3%"; null → "—". */
export function formatPct(value: number | null | undefined, digits = 1): string {
    return value === null || value === undefined ? '—' : `${value.toLocaleString('pt-PT', { maximumFractionDigits: digits })}%`;
}

export function formatQty(value: number | null | undefined, digits = 3): string {
    return value === null || value === undefined ? '' : value.toLocaleString('pt-PT', { maximumFractionDigits: digits });
}
