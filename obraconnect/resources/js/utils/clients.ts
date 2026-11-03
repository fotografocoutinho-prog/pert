import type { Client, ClientType } from '@/api/types';

/** App\Enums\ClientType */
export const CLIENT_TYPE_OPTIONS: { value: ClientType; label: string }[] = [
    { value: 'private', label: 'Particular' },
    { value: 'company', label: 'Empresa' },
    { value: 'public', label: 'Entidade pública' },
];

export interface ClientFormData extends Record<string, unknown> {
    type: ClientType;
    name: string;
    nif: string;
    country_code: string;
    contact_name: string;
    phone: string;
    email: string;
    address: string;
    postal_code: string;
    city: string;
    notes: string;
}

export function clientToForm(client?: Client | null): ClientFormData {
    return {
        type: client?.type ?? 'private',
        name: client?.name ?? '',
        nif: client?.nif ?? '',
        country_code: client?.country_code ?? 'PT',
        contact_name: client?.contact_name ?? '',
        phone: client?.phone ?? '',
        email: client?.email ?? '',
        address: client?.address ?? '',
        postal_code: client?.postal_code ?? '',
        city: client?.city ?? '',
        notes: client?.notes ?? '',
    };
}

/** Texto vazio vai como null; país vazio é Portugal. */
export function clientPayload(data: ClientFormData): Record<string, unknown> {
    const payload = Object.fromEntries(Object.entries(data).map(([key, value]) => [key, typeof value === 'string' && value.trim() === '' ? null : value]));
    payload.country_code = (data.country_code.trim() || 'PT').toUpperCase();
    return payload;
}
