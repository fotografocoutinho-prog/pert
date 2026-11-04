import type { DiaryItemKind, Project, ProjectStatus } from '@/api/types';

/** Campos editáveis da obra, como texto (o valor vai em euros e é convertido para cêntimos). */
export interface ProjectFormData extends Record<string, unknown> {
    name: string;
    code: string;
    type: string | null;
    client_id: number | '';
    owner_name: string;
    address: string;
    postal_code: string;
    city: string;
    contract_value: string;
    starts_on: string;
    planned_end_on: string;
    work_calendar_id: number | '';
    cost_center_id: number | '';
    description: string;
}

export function projectToForm(project?: Project | null): ProjectFormData {
    return {
        name: project?.name ?? '',
        code: project?.code ?? '',
        type: project?.type ?? null,
        client_id: project?.client_id ?? '',
        owner_name: project?.owner_name ?? '',
        address: project?.address ?? '',
        postal_code: project?.postal_code ?? '',
        city: project?.city ?? '',
        contract_value: centsToEuros(project?.contract_value_cents),
        starts_on: project?.starts_on ?? '',
        planned_end_on: project?.planned_end_on ?? '',
        work_calendar_id: project?.work_calendar_id ?? '',
        cost_center_id: project?.cost_center_id ?? '',
        description: project?.description ?? '',
    };
}

/** Texto vazio vai como null; o valor passa a cêntimos. Devolve null se o valor for inválido. */
export function formToPayload(data: ProjectFormData): Record<string, unknown> | null {
    const cents = eurosToCents(data.contract_value);
    if (Number.isNaN(cents)) return null;

    const payload: Record<string, unknown> = { contract_value_cents: cents };
    for (const [key, value] of Object.entries(data)) {
        if (key !== 'contract_value') payload[key] = value === '' ? null : value;
    }
    return payload;
}

/** Rótulos iguais aos do servidor (App\Enums). */
export const PROJECT_STATUS_OPTIONS: { value: ProjectStatus; label: string }[] = [
    { value: 'planning', label: 'Em preparação' },
    { value: 'active', label: 'Em curso' },
    { value: 'suspended', label: 'Suspensa' },
    { value: 'completed', label: 'Concluída' },
    { value: 'archived', label: 'Arquivada' },
];

export const PROJECT_TYPE_OPTIONS = [
    { value: 'new_build', label: 'Construção nova' },
    { value: 'renovation', label: 'Reabilitação' },
    { value: 'extension', label: 'Ampliação' },
    { value: 'demolition', label: 'Demolição' },
    { value: 'infrastructure', label: 'Infraestruturas' },
    { value: 'maintenance', label: 'Manutenção' },
    { value: 'other', label: 'Outra' },
];

export function statusColor(status: ProjectStatus): 'gray' | 'green' | 'amber' | 'blue' {
    return ({ planning: 'blue', active: 'green', suspended: 'amber', completed: 'gray', archived: 'gray' } as const)[status];
}

export const isClosedStatus = (status: ProjectStatus): boolean => status === 'completed' || status === 'archived';

/** App\Models\DiaryEntry::WEATHER */
export const WEATHER_OPTIONS = [
    { value: 'limpo', label: 'Céu limpo' },
    { value: 'nublado', label: 'Nublado' },
    { value: 'chuva', label: 'Chuva' },
    { value: 'chuva_forte', label: 'Chuva forte' },
    { value: 'vento', label: 'Vento forte' },
    { value: 'nevoeiro', label: 'Nevoeiro' },
    { value: 'geada', label: 'Geada' },
    { value: 'neve', label: 'Neve' },
];

export const weatherLabel = (value: string | null | undefined): string =>
    WEATHER_OPTIONS.find((o) => o.value === value)?.label ?? '—';

/** Secções do registo diário. Todas as linhas têm os mesmos campos; muda o que cada um significa. */
export const DIARY_SECTIONS: {
    kind: DiaryItemKind;
    label: string;
    add: string;
    description: string;
    quantity: string | null;
    unit: boolean;
    company: string | null;
}[] = [
    { kind: 'workforce', label: 'Mão de obra', add: 'Adicionar equipa', description: 'Categoria (ex.: pedreiros)', quantity: 'N.º de pessoas', unit: false, company: 'Empresa' },
    { kind: 'equipment', label: 'Equipamentos', add: 'Adicionar equipamento', description: 'Equipamento', quantity: 'Quantidade', unit: true, company: 'Empresa' },
    { kind: 'material', label: 'Materiais recebidos', add: 'Adicionar material', description: 'Material', quantity: 'Quantidade', unit: true, company: 'Fornecedor' },
    { kind: 'incident', label: 'Ocorrências', add: 'Adicionar ocorrência', description: 'O que aconteceu', quantity: null, unit: false, company: null },
    { kind: 'visit', label: 'Visitas', add: 'Adicionar visita', description: 'Quem e motivo', quantity: null, unit: false, company: 'Entidade' },
];

/** "12 500,50" ou "12500.5" → cêntimos; vazio → null. */
export function eurosToCents(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined || String(value).trim() === '') return null;
    const normalized = String(value).replace(/\s/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
    const euros = Number(normalized);
    return Number.isFinite(euros) ? Math.round(euros * 100) : NaN;
}

export function centsToEuros(cents: number | null | undefined): string {
    return cents === null || cents === undefined ? '' : (cents / 100).toFixed(2).replace('.', ',');
}

/** Data local (não UTC) no formato da API. */
export function todayIso(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const weekday = new Intl.DateTimeFormat('pt-PT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

/** "segunda-feira, 3 de novembro de 2026" a partir de "2026-11-03", sem saltos de fuso. */
export function formatLongDate(iso: string): string {
    const [y, m, d] = iso.split('-').map(Number);
    return weekday.format(new Date(y, m - 1, d));
}
