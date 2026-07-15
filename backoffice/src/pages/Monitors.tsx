import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Alert,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  MenuItem,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import MonitorHeartIcon from '@mui/icons-material/MonitorHeart';
import type { Layout, Monitor, Orientation, Playlist } from '@signage/shared';
import { api } from '../api/client';
import MonitorDetailDialog from '../components/MonitorDetailDialog';

export default function Monitors() {
  const qc = useQueryClient();
  const [editing, setEditing] = useState<Monitor | null>(null);
  const [detail, setDetail] = useState<Monitor | null>(null);
  const [creating, setCreating] = useState(false);
  const [newName, setNewName] = useState('');

  const { data, isLoading, error } = useQuery<Monitor[]>({
    queryKey: ['monitors'],
    queryFn: async () => (await api.get<Monitor[]>('/api/monitors')).data,
    refetchInterval: 10_000,
  });
  const { data: layouts } = useQuery<Layout[]>({
    queryKey: ['layouts'],
    queryFn: async () => (await api.get<Layout[]>('/api/layouts')).data,
  });
  const { data: playlists } = useQuery<Playlist[]>({
    queryKey: ['playlists'],
    queryFn: async () => (await api.get<Playlist[]>('/api/playlists')).data,
  });

  const create = useMutation({
    mutationFn: async () => {
      await api.post('/api/monitors', { name: newName });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['monitors'] });
      setCreating(false);
      setNewName('');
    },
  });

  const update = useMutation({
    mutationFn: async (m: Monitor) => {
      await api.patch(`/api/monitors/${m.id}`, {
        orientation: m.orientation,
        layoutId: m.layoutId,
        playlistId: m.playlistId,
        groupName: m.groupName,
      });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['monitors'] });
      setEditing(null);
    },
  });

  if (error) return <Alert severity="error">Failed to load monitors</Alert>;

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="h5" fontWeight={700}>
          Monitors {isLoading && '…'}
        </Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => setCreating(true)}>
          Add monitor
        </Button>
      </Stack>

      <TableContainer component={Paper}>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>Name</TableCell>
              <TableCell>Group</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Orientation</TableCell>
              <TableCell>Layout</TableCell>
              <TableCell>Pairing</TableCell>
              <TableCell>Last seen</TableCell>
              <TableCell />
            </TableRow>
          </TableHead>
          <TableBody>
            {(data ?? []).map((m) => (
              <TableRow key={m.id} hover>
                <TableCell>{m.name}</TableCell>
                <TableCell>{m.groupName ?? '—'}</TableCell>
                <TableCell>
                  <Chip size="small" label={m.status} color={m.status === 'online' ? 'success' : 'default'} />
                </TableCell>
                <TableCell>{m.orientation}</TableCell>
                <TableCell>{layouts?.find((l) => l.id === m.layoutId)?.name ?? '—'}</TableCell>
                <TableCell>{m.pairingCode ?? '—'}</TableCell>
                <TableCell>{m.lastSeenAt ? new Date(m.lastSeenAt).toLocaleString() : '—'}</TableCell>
                <TableCell align="right">
                  <IconButton size="small" onClick={() => setDetail(m)} title="Health & control">
                    <MonitorHeartIcon fontSize="small" />
                  </IconButton>
                  <IconButton size="small" onClick={() => setEditing(m)} title="Edit">
                    <EditIcon fontSize="small" />
                  </IconButton>
                </TableCell>
              </TableRow>
            ))}
            {data?.length === 0 && (
              <TableRow>
                <TableCell colSpan={8} align="center" sx={{ py: 4, opacity: 0.6 }}>
                  No monitors yet.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </TableContainer>

      {/* Create dialog */}
      <Dialog open={creating} onClose={() => setCreating(false)} fullWidth maxWidth="xs">
        <DialogTitle>Add monitor</DialogTitle>
        <DialogContent>
          <TextField
            autoFocus
            fullWidth
            label="Name"
            value={newName}
            onChange={(e) => setNewName(e.target.value)}
            sx={{ mt: 1 }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCreating(false)}>Cancel</Button>
          <Button variant="contained" disabled={!newName || create.isPending} onClick={() => create.mutate()}>
            Create
          </Button>
        </DialogActions>
      </Dialog>

      {/* Edit / assign dialog */}
      <Dialog open={!!editing} onClose={() => setEditing(null)} fullWidth maxWidth="xs">
        <DialogTitle>Edit {editing?.name}</DialogTitle>
        <DialogContent>
          {editing && (
            <Stack spacing={2} sx={{ mt: 1 }}>
              <TextField
                label="Group"
                value={editing.groupName ?? ''}
                onChange={(e) => setEditing({ ...editing, groupName: e.target.value || null })}
              />
              <TextField
                select
                label="Orientation"
                value={editing.orientation}
                onChange={(e) => setEditing({ ...editing, orientation: e.target.value as Orientation })}
              >
                <MenuItem value="landscape">Landscape</MenuItem>
                <MenuItem value="portrait">Portrait</MenuItem>
              </TextField>
              <TextField
                select
                label="Layout"
                value={editing.layoutId ?? ''}
                onChange={(e) => setEditing({ ...editing, layoutId: e.target.value || null })}
              >
                <MenuItem value="">— none —</MenuItem>
                {(layouts ?? []).map((l) => (
                  <MenuItem key={l.id} value={l.id}>
                    {l.name}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                select
                label="Default playlist"
                value={editing.playlistId ?? ''}
                onChange={(e) => setEditing({ ...editing, playlistId: e.target.value || null })}
              >
                <MenuItem value="">— none —</MenuItem>
                {(playlists ?? []).map((p) => (
                  <MenuItem key={p.id} value={p.id}>
                    {p.name}
                  </MenuItem>
                ))}
              </TextField>
            </Stack>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setEditing(null)}>Cancel</Button>
          <Button variant="contained" disabled={update.isPending} onClick={() => editing && update.mutate(editing)}>
            Save
          </Button>
        </DialogActions>
      </Dialog>

      {detail && <MonitorDetailDialog monitor={detail} onClose={() => setDetail(null)} />}
    </Stack>
  );
}
