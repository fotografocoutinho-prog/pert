import { Box, Typography } from '@mui/material';

/** Minimal inline SVG sparkline — no chart dependency. */
export default function Sparkline({
  values,
  label,
  color = '#2563eb',
  unit = '',
  max,
}: {
  values: (number | null)[];
  label: string;
  color?: string;
  unit?: string;
  max?: number;
}) {
  const points = values.filter((v): v is number => v != null);
  const width = 160;
  const height = 40;

  let path = '';
  if (points.length >= 2) {
    const hi = max ?? Math.max(...points, 1);
    const lo = Math.min(...points, 0);
    const range = hi - lo || 1;
    path = points
      .map((v, i) => {
        const x = (i / (points.length - 1)) * width;
        const y = height - ((v - lo) / range) * height;
        return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
      })
      .join(' ');
  }
  const latest = points.at(-1);

  return (
    <Box>
      <Typography variant="caption" color="text.secondary">
        {label}
      </Typography>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <svg width={width} height={height} style={{ overflow: 'visible' }}>
          {path ? (
            <path d={path} fill="none" stroke={color} strokeWidth={2} strokeLinejoin="round" />
          ) : (
            <line x1={0} y1={height} x2={width} y2={height} stroke="#94a3b8" strokeDasharray="3 3" />
          )}
        </svg>
        <Typography variant="body2" fontWeight={700} sx={{ minWidth: 48 }}>
          {latest != null ? `${latest}${unit}` : '—'}
        </Typography>
      </Box>
    </Box>
  );
}
