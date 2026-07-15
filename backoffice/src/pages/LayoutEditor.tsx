import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Alert,
  Box,
  Button,
  Divider,
  IconButton,
  MenuItem,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/Delete';
import SaveIcon from '@mui/icons-material/Save';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import type { Layout, Playlist, Zone, ZoneKind } from '@signage/shared';
import { MEDIA_ZONE_KINDS } from '@signage/shared';
import { api } from '../api/client';
import ZonePreview from '../components/ZonePreview';

const KINDS: ZoneKind[] = [
  'video',
  'image',
  'clock',
  'text',
  'rss',
  'news',
  'html',
  'website',
  'youtube',
  'weather',
];

// Config fields rendered per zone kind.
const CONFIG_FIELDS: Partial<Record<ZoneKind, { key: string; label: string }[]>> = {
  website: [{ key: 'url', label: 'Website URL' }],
  youtube: [{ key: 'url', label: 'YouTube URL or video ID' }],
  rss: [{ key: 'url', label: 'RSS feed URL' }],
  news: [{ key: 'url', label: 'News feed URL' }],
  weather: [{ key: 'city', label: 'City' }],
  text: [{ key: 'text', label: 'Text' }],
  html: [{ key: 'html', label: 'HTML' }],
  clock: [{ key: 'timezone', label: 'Timezone (e.g. Europe/Lisbon)' }],
};

function newZone(): Zone {
  return {
    id: crypto.randomUUID(),
    kind: 'image',
    x: 25,
    y: 25,
    width: 50,
    height: 50,
    config: {},
    playlistId: null,
  };
}

export default function LayoutEditor() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data, isLoading, error } = useQuery<Layout>({
    queryKey: ['layout', id],
    queryFn: async () => (await api.get<Layout>(`/api/layouts/${id}`)).data,
    enabled: !!id,
  });

  const { data: playlists } = useQuery<Playlist[]>({
    queryKey: ['playlists'],
    queryFn: async () => (await api.get<Playlist[]>('/api/playlists')).data,
  });

  const [name, setName] = useState('');
  const [zones, setZones] = useState<Zone[]>([]);
  const [selectedId, setSelectedId] = useState<string | null>(null);

  useEffect(() => {
    if (data) {
      setName(data.name);
      setZones(data.zones);
      setSelectedId(data.zones[0]?.id ?? null);
    }
  }, [data]);

  const save = useMutation({
    mutationFn: async () => {
      await api.patch(`/api/layouts/${id}`, { name, zones, preset: 'custom' });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['layouts'] });
      qc.invalidateQueries({ queryKey: ['layout', id] });
    },
  });

  if (error) return <Alert severity="error">Failed to load layout</Alert>;

  const selected = zones.find((z) => z.id === selectedId) ?? null;

  const patchZone = (patch: Partial<Zone>) => {
    setZones((zs) => zs.map((z) => (z.id === selectedId ? { ...z, ...patch } : z)));
  };

  const patchConfig = (key: string, value: string) => {
    setZones((zs) =>
      zs.map((z) => (z.id === selectedId ? { ...z, config: { ...z.config, [key]: value } } : z)),
    );
  };

  const numberField = (label: string, key: 'x' | 'y' | 'width' | 'height') => (
    <TextField
      label={label}
      type="number"
      size="small"
      value={selected?.[key] ?? 0}
      onChange={(e) => patchZone({ [key]: Math.max(0, Math.min(100, Number(e.target.value))) })}
      inputProps={{ min: 0, max: 100 }}
    />
  );

  return (
    <Stack spacing={3}>
      <Stack direction="row" spacing={2} alignItems="center">
        <IconButton onClick={() => navigate('/layouts')}>
          <ArrowBackIcon />
        </IconButton>
        <TextField
          value={name}
          onChange={(e) => setName(e.target.value)}
          variant="standard"
          sx={{ flexGrow: 1, '& input': { fontSize: 22, fontWeight: 700 } }}
        />
        <Button
          variant="contained"
          startIcon={<SaveIcon />}
          disabled={save.isPending || isLoading}
          onClick={() => save.mutate()}
        >
          {save.isPending ? 'Saving…' : 'Save'}
        </Button>
      </Stack>
      {save.isSuccess && <Alert severity="success">Layout saved</Alert>}

      <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', md: '2fr 1fr' } }}>
        <Paper sx={{ p: 2 }}>
          <ZonePreview
            zones={zones}
            selectedId={selectedId ?? undefined}
            onSelect={setSelectedId}
          />
          <Stack direction="row" spacing={1} sx={{ mt: 2 }}>
            <Button
              size="small"
              startIcon={<AddIcon />}
              onClick={() => {
                const z = newZone();
                setZones((zs) => [...zs, z]);
                setSelectedId(z.id);
              }}
            >
              Add zone
            </Button>
          </Stack>
        </Paper>

        <Paper sx={{ p: 2 }}>
          {!selected ? (
            <Typography color="text.secondary">Select a zone to edit it.</Typography>
          ) : (
            <Stack spacing={2}>
              <Typography variant="subtitle1" fontWeight={700}>
                Zone properties
              </Typography>
              <TextField
                select
                label="Type"
                size="small"
                value={selected.kind}
                onChange={(e) => patchZone({ kind: e.target.value as ZoneKind })}
              >
                {KINDS.map((k) => (
                  <MenuItem key={k} value={k}>
                    {k}
                  </MenuItem>
                ))}
              </TextField>

              <Box sx={{ display: 'grid', gap: 1, gridTemplateColumns: '1fr 1fr' }}>
                {numberField('X %', 'x')}
                {numberField('Y %', 'y')}
                {numberField('Width %', 'width')}
                {numberField('Height %', 'height')}
              </Box>

              {MEDIA_ZONE_KINDS.includes(selected.kind) && (
                <TextField
                  select
                  label="Playlist"
                  size="small"
                  value={selected.playlistId ?? ''}
                  onChange={(e) => patchZone({ playlistId: e.target.value || null })}
                >
                  <MenuItem value="">— none —</MenuItem>
                  {(playlists ?? []).map((p) => (
                    <MenuItem key={p.id} value={p.id}>
                      {p.name}
                    </MenuItem>
                  ))}
                </TextField>
              )}

              {(CONFIG_FIELDS[selected.kind] ?? []).map((f) => (
                <TextField
                  key={f.key}
                  label={f.label}
                  size="small"
                  multiline={f.key === 'html' || f.key === 'text'}
                  value={(selected.config[f.key] as string) ?? ''}
                  onChange={(e) => patchConfig(f.key, e.target.value)}
                />
              ))}

              <Divider />
              <Button
                color="error"
                startIcon={<DeleteIcon />}
                onClick={() => {
                  setZones((zs) => zs.filter((z) => z.id !== selectedId));
                  setSelectedId(null);
                }}
              >
                Delete zone
              </Button>
            </Stack>
          )}
        </Paper>
      </Box>
    </Stack>
  );
}
