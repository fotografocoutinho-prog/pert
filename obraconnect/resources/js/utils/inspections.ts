import type { ChecklistItemDef, InspectionStatus } from '@/api/types';

export function inspectionStatusColor(status: InspectionStatus): 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand' {
    return {
        draft: 'gray',
        requested: 'blue',
        scheduled: 'blue',
        in_progress: 'brand',
        submitted: 'amber',
        approved: 'green',
        approved_with_reservations: 'amber',
        rejected: 'red',
        cancelled: 'gray',
    }[status] as 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand';
}

export const RESULT_OPTIONS = [
    { value: 'ok', label: 'Conforme', short: 'OK', active: 'bg-green-700 text-white border-green-700' },
    { value: 'nok', label: 'Não conforme', short: 'NC', active: 'bg-red-700 text-white border-red-700' },
    { value: 'na', label: 'Não aplicável', short: 'N/A', active: 'bg-slate-600 text-white border-slate-600' },
] as const;

/** 25 → "25"; 2.5 → "2,5". */
export function formatMeasure(value: number | null | undefined): string {
    return value === null || value === undefined ? '' : value.toLocaleString('pt-PT', { maximumFractionDigits: 4 });
}

/** "Fotografia (2)", "Medição em mm, 25 a 40" — o que o ponto exige, numa linha. */
export function requirementsText(item: ChecklistItemDef): string[] {
    const out: string[] = [];
    if (item.requires_photo) out.push(item.min_photos > 1 ? `${item.min_photos} fotografias` : 'Fotografia');
    if (item.requires_measure) {
        const unit = item.measure_unit ? ` (${item.measure_unit})` : '';
        const min = item.measure_min !== null ? formatMeasure(item.measure_min) : null;
        const max = item.measure_max !== null ? formatMeasure(item.measure_max) : null;
        const range = min !== null && max !== null ? `: ${min} a ${max}` : min !== null ? `: mínimo ${min}` : max !== null ? `: máximo ${max}` : '';
        out.push(`Medição${unit}${range}`);
    }
    return out;
}

export function emptyChecklistItem(): ChecklistItemDef {
    return { text: '', guidance: null, requires_photo: false, min_photos: 1, requires_measure: false, measure_unit: null, measure_min: null, measure_max: null };
}

const ISSUE_STATUS_COLORS: Record<string, 'gray' | 'green' | 'amber' | 'red' | 'brand'> = {
    open: 'red',
    in_progress: 'brand',
    resolved: 'amber',
    closed: 'green',
    cancelled: 'gray',
};

export function issueStatusColor(status: string): 'gray' | 'green' | 'amber' | 'red' | 'brand' {
    return ISSUE_STATUS_COLORS[status] ?? 'gray';
}

const SEVERITY_COLORS: Record<string, 'gray' | 'amber' | 'red'> = { minor: 'gray', major: 'amber', critical: 'red' };

export function severityColor(severity: string): 'gray' | 'amber' | 'red' {
    return SEVERITY_COLORS[severity] ?? 'gray';
}

export const APPROVAL_OPTIONS = [
    { value: 1, label: '1 aprovação' },
    { value: 2, label: '2 aprovações (pessoas diferentes)' },
    { value: 3, label: '3 aprovações (pessoas diferentes)' },
];

export const ISSUE_KINDS = [
    { value: 'nonconformity', label: 'Não conformidade' },
    { value: 'occurrence', label: 'Ocorrência' },
];

export const ISSUE_SEVERITIES = [
    { value: 'minor', label: 'Menor' },
    { value: 'major', label: 'Maior' },
    { value: 'critical', label: 'Crítica' },
];
