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
    can: { edit: boolean; submit: boolean; approve: boolean; reopen: boolean; delete: boolean };
}

export interface PartnerDocument {
    id: number;
    title: string;
    reference: string | null;
    expires_on: string | null;
    state: 'valid' | 'expiring' | 'expired';
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
    actual_cents: number;
    committed_cents: number;
    forecast_cents: number;
    ceiling_cents: number | null;
    ceiling_used_pct: number | null;
    alert: 'ok' | 'warning' | 'over' | null;
    margin_forecast_cents: number | null;
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
    addDocument: (id: number, payload: Record<string, unknown>) => data<Partner>(http.post(`/api/v1/partners/${id}/documents`, payload)),
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
