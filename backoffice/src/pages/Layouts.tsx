import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Alert,
  Button,
  Card,
  CardActionArea,
  CardContent,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  MenuItem,
  Box,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import type { Layout, LayoutPreset } from '@signage/shared';
import { api } from '../api/client';
import ZonePreview from '../components/ZonePreview';

const PRESETS: { value: LayoutPreset; label: string }[] = [
  { value: 'single', label: '1 screen' },
  { value: 'two-zone', label: '2 zones' },
  { value: 'three-zone', label: '3 zones' },
  { value: 'four-zone', label: '4 zones' },
];

export default function Layouts() {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [preset, setPreset] = useState<LayoutPreset>('single');

  const { data, isLoading, error } = useQuery<Layout[]>({
    queryKey: ['layouts'],
    queryFn: async () => (await api.get<Layout[]>('/api/layouts')).data,
  });

  const create = useMutation({
    mutationFn: async () => (await api.post<Layout>('/api/layouts', { name, preset })).data,
    onSuccess: (layout) => {
      qc.invalidateQueries({ queryKey: ['layouts'] });
      setOpen(false);
      setName('');
      navigate(`/layouts/${layout.id}`);
    },
  });

  if (error) return <Alert severity="error">Failed to load layouts</Alert>;

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="h5" fontWeight={700}>
          Layouts {isLoading && '…'}
        </Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => setOpen(true)}>
          New layout
        </Button>
      </Stack>

      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', md: 'repeat(3, 1fr)' },
        }}
      >
        {(data ?? []).map((l) => (
          <Card key={l.id}>
            <CardActionArea onClick={() => navigate(`/layouts/${l.id}`)}>
              <CardContent>
                <Stack spacing={1}>
                  <ZonePreview zones={l.zones} />
                  <Stack direction="row" justifyContent="space-between" alignItems="center">
                    <Typography fontWeight={600}>{l.name}</Typography>
                    <Chip size="small" label={`${l.zones.length} zones`} />
                  </Stack>
                </Stack>
              </CardContent>
            </CardActionArea>
          </Card>
        ))}
      </Box>
      {data?.length === 0 && (
        <Typography sx={{ py: 4, textAlign: 'center', opacity: 0.6 }}>No layouts yet.</Typography>
      )}

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="xs">
        <DialogTitle>New layout</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} fullWidth autoFocus />
            <TextField
              select
              label="Preset"
              value={preset}
              onChange={(e) => setPreset(e.target.value as LayoutPreset)}
              fullWidth
            >
              {PRESETS.map((p) => (
                <MenuItem key={p.value} value={p.value}>
                  {p.label}
                </MenuItem>
              ))}
            </TextField>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button variant="contained" disabled={!name || create.isPending} onClick={() => create.mutate()}>
            Create
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
