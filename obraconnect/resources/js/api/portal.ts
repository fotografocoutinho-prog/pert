import { getData, http } from './http';

/** Portal de subempreiteiros (lado do subempreiteiro) e gestão dos acessos (lado da empresa). */

export interface PortalContractSummary {
    id: number;
    code: string;
    title: string;
    project: { code: string; name: string };
    status: 'active' | 'closed';
    status_label: string;
    starts_on: string | null;
    ends_on: string | null;
    retention_pct: number;
    value_cents: number;
    certified_cents: number;
    retained_cents: number;
    certified_pct: number | null;
    pending_certificate: { number: number; status: string; status_label: string } | null;
}

export interface PortalContract extends PortalContractSummary {
    items: { id: number; description: string; unit: string; quantity: number; unit_price_cents: number; value_cents: number; certified_qty: number; remaining_qty: number }[];
    certificates: {
        id: number;
        number: number;
        period_start: string;
        period_end: string;
        status: 'draft' | 'submitted' | 'approved';
        status_label: string;
        amount_cents: number;
        retention_cents: number;
        net_cents: number;
        proposed_here: boolean;
        submitted_at: string | null;
        approved_at: string | null;
        lines: { description: string; unit: string; qty_period: number; amount_cents: number }[];
    }[];
    can_propose: boolean;
}

export interface PortalDocument {
    id: number;
    title: string;
    reference: string | null;
    expires_on: string | null;
    state: 'valid' | 'expiring' | 'expired';
    has_file: boolean;
    original_name: string | null;
    size_bytes: number | null;
    from_portal: boolean;
    created_at: string | null;
}

export interface PortalUserRow {
    id: number;
    name: string;
    email: string;
    status: 'active' | 'pending' | 'expired' | 'revoked';
    status_label: string;
    invited_at: string | null;
    accepted_at: string | null;
    expires_at: string | null;
    last_seen_at: string | null;
}

const a = (access: number | string) => `/api/v1/portal/${access}`;
const data = async <T>(promise: Promise<{ data: { data: T } }>) => (await promise).data.data;

export const portalApi = {
    lookupInvitation: (token: string) =>
        getData<{ company: string; partner: string; name: string; email: string; expires_at: string; account_exists: boolean }>(`/api/v1/portal-invitations/${token}`),
    acceptInvitation: (token: string) => data<{ access_id: number }>(http.post('/api/v1/portal-invitations/accept', { token })),
    contracts: (access: number | string) =>
        getData<{ company: string; partner: { id: number; name: string; nif: string | null }; contracts: PortalContractSummary[] }>(`${a(access)}/contracts`),
    contract: (access: number | string, id: number | string) => getData<PortalContract>(`${a(access)}/contracts/${id}`),
    propose: (access: number | string, id: number | string, payload: { period_start: string; period_end: string; notes: string | null; lines: { contract_item_id: number; qty_period: number }[] }) =>
        data<PortalContract>(http.post(`${a(access)}/contracts/${id}/proposals`, payload)),
    documents: (access: number | string) => getData<PortalDocument[]>(`${a(access)}/documents`),
    uploadDocument: (access: number | string, form: FormData) => data<PortalDocument>(http.post(`${a(access)}/documents`, form)),
    documentUrl: (access: number | string, id: number) => `${a(access)}/documents/${id}/file`,
};

export const partnerPortalApi = {
    list: (partner: number) => getData<PortalUserRow[]>(`/api/v1/partners/${partner}/portal-users`),
    invite: (partner: number, payload: { email: string; name: string }) => data<PortalUserRow[]>(http.post(`/api/v1/partners/${partner}/portal-users`, payload)),
    resend: (partner: number, id: number) => data<PortalUserRow[]>(http.post(`/api/v1/partners/${partner}/portal-users/${id}/resend`)),
    revoke: (partner: number, id: number) => data<PortalUserRow[]>(http.delete(`/api/v1/partners/${partner}/portal-users/${id}`)),
    documentUrl: (partner: number, document: number) => `/api/v1/partners/${partner}/documents/${document}/file`,
};
