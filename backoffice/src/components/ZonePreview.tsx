import { Box } from '@mui/material';
import type { Zone, ZoneKind } from '@signage/shared';

const ZONE_COLORS: Record<ZoneKind, string> = {
  video: '#2563eb',
  image: '#7c3aed',
  clock: '#0891b2',
  news: '#ca8a04',
  rss: '#ca8a04',
  html: '#db2777',
  website: '#059669',
  youtube: '#dc2626',
  weather: '#0284c7',
  text: '#4b5563',
};

/** Renders a scaled 16:9 preview of a layout's zones. */
export default function ZonePreview({
  zones,
  aspect = '16 / 9',
  selectedId,
  onSelect,
}: {
  zones: Zone[];
  aspect?: string;
  selectedId?: string;
  onSelect?: (id: string) => void;
}) {
  return (
    <Box
      sx={{
        position: 'relative',
        width: '100%',
        aspectRatio: aspect,
        bgcolor: '#0f172a',
        borderRadius: 1,
        overflow: 'hidden',
      }}
    >
      {zones.map((z) => (
        <Box
          key={z.id}
          onClick={onSelect ? () => onSelect(z.id) : undefined}
          sx={{
            position: 'absolute',
            left: `${z.x}%`,
            top: `${z.y}%`,
            width: `${z.width}%`,
            height: `${z.height}%`,
            bgcolor: `${ZONE_COLORS[z.kind]}33`,
            border: 2,
            borderColor: z.id === selectedId ? '#fff' : ZONE_COLORS[z.kind],
            boxSizing: 'border-box',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: '#e2e8f0',
            fontSize: 11,
            fontWeight: 600,
            textTransform: 'uppercase',
            cursor: onSelect ? 'pointer' : 'default',
            userSelect: 'none',
          }}
        >
          {z.kind}
        </Box>
      ))}
    </Box>
  );
}
