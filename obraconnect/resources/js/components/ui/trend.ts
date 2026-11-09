/** Pontos e linhas de referência do TrendChart. */
export interface TrendPoint {
    label: string;
    value: number | null;
    title: string;
    current?: boolean;
}

export interface TrendReference {
    value: number;
    label: string;
    color: 'red' | 'slate' | 'amber';
}

/** Data ISO (AAAA-MM-DD) para número de dias, para pôr datas num eixo. */
export const dayNumber = (iso: string): number => {
    const [y, m, d] = iso.split('-').map(Number);
    return Date.UTC(y, m - 1, d) / 86400000;
};

/** Marcas de um eixo de datas: inícios de mês (de dois em dois em períodos longos) ou segundas-feiras em períodos curtos. */
export const dateTicks = (min: number, max: number): number[] => {
    const out: number[] = [];
    const span = max - min;
    if (span > 75) {
        const step = span > 240 ? 2 : 1;
        const start = new Date(Math.ceil(min) * 86400000);
        for (let i = 1; ; i += step) {
            const v = Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + i, 1) / 86400000;
            if (v > max) break;
            out.push(v);
        }
        return out;
    }
    // 1970-01-05 (dia 4) foi segunda-feira.
    const step = span > 35 ? 14 : 7;
    for (let v = Math.ceil((min - 4) / 7) * 7 + 4; v <= max; v += step) out.push(v);
    return out;
};

/** O contrário de dayNumber, em dd/mm/aa. */
export const dayLabel = (n: number): string => {
    const date = new Date(Math.round(n) * 86400000);
    const dd = String(date.getUTCDate()).padStart(2, '0');
    const mm = String(date.getUTCMonth() + 1).padStart(2, '0');
    return `${dd}/${mm}/${String(date.getUTCFullYear()).slice(2)}`;
};
