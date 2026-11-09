<script setup lang="ts">
import { computed } from 'vue';
import type { TrendPoint, TrendReference } from './trend';

/**
 * Gráfico de linha simples (SVG, sem bibliotecas) para a evolução semanal de um valor, com linhas de
 * referência (orçamento, teto, prazo). O último ponto é o de hoje.
 */

const props = defineProps<{
    points: TrendPoint[];
    references?: TrendReference[];
    format: (v: number) => string;
    label: string;
    /** Marcas próprias do eixo (ex.: inícios de mês num eixo de datas). */
    tickValues?: (min: number, max: number) => number[];
}>();

const W = 600;
const H = 220;
const pad = { left: 86, right: 14, top: 14, bottom: 30 };

const values = computed(() => props.points.map((p) => p.value).filter((v): v is number => v !== null));
const range = computed(() => {
    const all = [...values.value, ...(props.references ?? []).map((r) => r.value)];
    if (!all.length) return { min: 0, max: 1 };
    let min = Math.min(...all);
    let max = Math.max(...all);
    if (min === max) {
        min -= Math.abs(min) * 0.05 || 1;
        max += Math.abs(max) * 0.05 || 1;
    }
    const margin = (max - min) * 0.08;
    return { min: min - margin, max: max + margin };
});
const x = (i: number) => {
    const n = props.points.length;
    const inner = W - pad.left - pad.right;
    return n <= 1 ? pad.left + inner / 2 : pad.left + (inner * i) / (n - 1);
};
const y = (v: number) => {
    const { min, max } = range.value;
    return pad.top + (H - pad.top - pad.bottom) * (1 - (v - min) / (max - min));
};

const path = computed(() =>
    props.points
        .map((p, i) => (p.value === null ? null : `${x(i).toFixed(1)},${y(p.value).toFixed(1)}`))
        .filter(Boolean)
        .map((pt, i) => (i === 0 ? `M${pt}` : `L${pt}`))
        .join(' '),
);
// Marcas do eixo em números redondos (1, 2 ou 5 × 10ⁿ).
const ticks = computed(() => {
    const { min, max } = range.value;
    if (props.tickValues) return props.tickValues(min, max);
    const raw = (max - min) / 3;
    const mag = Math.pow(10, Math.floor(Math.log10(raw)));
    const r = raw / mag;
    const step = (r < 1.5 ? 1 : r < 3 ? 2 : r < 7 ? 5 : 10) * mag;
    const out: number[] = [];
    for (let v = Math.ceil(min / step) * step; v <= max; v += step) out.push(v);
    return out;
});
// Rótulos das linhas de referência sem se sobreporem: o de baixo desce para debaixo da sua linha.
const refLabels = computed(() => {
    const rows = (props.references ?? []).map((r) => ({ ...r, lineY: y(r.value), textY: y(r.value) - 5 })).sort((a, b) => a.lineY - b.lineY);
    for (let i = 1; i < rows.length; i++) {
        if (rows[i].textY - rows[i - 1].textY < 14) rows[i].textY = Math.max(rows[i].lineY + 14, rows[i - 1].textY + 14);
    }
    return rows;
});
// Rótulos do eixo dos x: todos se couberem, senão um em cada n (o último aparece sempre).
const every = computed(() => Math.max(1, Math.ceil(props.points.length / 8)));
const showLabel = (i: number) => i === props.points.length - 1 || (i % every.value === 0 && props.points.length - 1 - i >= every.value / 2);
const refColor: Record<TrendReference['color'], string> = { red: '#dc2626', slate: '#64748b', amber: '#d97706' };
</script>

<template>
    <svg :viewBox="`0 0 ${W} ${H}`" class="h-auto w-full" role="img" :aria-label="label">
        <g class="text-slate-400">
            <g v-for="(t, i) in ticks" :key="`t${i}`">
                <line :x1="pad.left" :x2="W - pad.right" :y1="y(t)" :y2="y(t)" stroke="#e2e8f0" stroke-width="1" />
                <text :x="pad.left - 6" :y="y(t) + 4" text-anchor="end" font-size="11" fill="#64748b">{{ format(t) }}</text>
            </g>
        </g>
        <line
            v-for="(r, i) in refLabels"
            :key="`r${i}`"
            :x1="pad.left"
            :x2="W - pad.right"
            :y1="r.lineY"
            :y2="r.lineY"
            :stroke="refColor[r.color]"
            stroke-width="1.5"
            stroke-dasharray="5 4"
        />
        <path v-if="path" :d="path" fill="none" stroke="#1d4ed8" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
        <text
            v-for="(r, i) in refLabels"
            :key="`rl${i}`"
            :x="pad.left + 6"
            :y="r.textY"
            font-size="11"
            font-weight="600"
            :fill="refColor[r.color]"
            stroke="#fff"
            stroke-width="3"
            paint-order="stroke"
        >
            {{ r.label }}
        </text>
        <g v-for="(p, i) in points" :key="`p${i}`">
            <circle v-if="p.value !== null" :cx="x(i)" :cy="y(p.value)" :r="p.current ? 5.5 : 3.5" :fill="p.current ? '#1d4ed8' : '#fff'" stroke="#1d4ed8" stroke-width="2">
                <title>{{ p.title }}</title>
            </circle>
            <text v-if="showLabel(i)" :x="x(i)" :y="H - 10" text-anchor="middle" font-size="11" :fill="p.current ? '#0f172a' : '#64748b'" :font-weight="p.current ? 600 : 400">
                {{ p.label }}
            </text>
        </g>
    </svg>
</template>
