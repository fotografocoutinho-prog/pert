import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/Delete';
import type { PlayerRelease } from '@signage/shared';
import { api } from '../api/client';

export default function Releases() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ version: '', url: '', checksum: '', notes: '' });

  const { data, isLoading, error } = useQuery<PlayerRelease[]>({
    queryKey: ['releases'],
    queryFn: async () => (await api.get<PlayerRelease[]>('/api/updates/player')).data,
  });

  const create = useMutation({
    mutationFn: async () => {
      await api.post('/api/updates/player', {
        version: form.version,
        url: form.url,
        checksum: form.checksum,
        notes: form.notes || null,
      });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['releases'] });
      setOpen(false);
      setForm({ version: '', url: '', checksum: '', notes: '' });
    },
  });

  const remove = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/api/updates/player/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['releases'] }),
  });

  if (error) return <Alert severity="error">Failed to load releases</Alert>;

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="h5" fontWeight={700}>
          Player releases (OTA) {isLoading && '…'}
        </Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => setOpen(true)}>
          Publish release
        </Button>
      </Stack>

      <Alert severity="info">
        The newest release is served to players as the update manifest. Players compare it to their
        running version, download and verify the bundle by checksum, then relaunch — rolling back
        automatically if the new version fails to boot.
      </Alert>

      <Paper>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>Version</TableCell>
              <TableCell>URL</TableCell>
              <TableCell>Checksum</TableCell>
              <TableCell>Published</TableCell>
              <TableCell />
            </TableRow>
          </TableHead>
          <TableBody>
            {(data ?? []).map((r, i) => (
              <TableRow key={r.id} hover>
                <TableCell>
                  {r.version} {i === 0 && <em>(latest)</em>}
                </TableCell>
                <TableCell sx={{ maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {r.url}
                </TableCell>
                <TableCell sx={{ fontFamily: 'monospace', fontSize: 12 }}>{r.checksum.slice(0, 12)}…</TableCell>
                <TableCell>{new Date(r.createdAt).toLocaleString()}</TableCell>
                <TableCell align="right">
                  <IconButton size="small" color="error" onClick={() => remove.mutate(r.id)}>
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </TableCell>
              </TableRow>
            ))}
            {data?.length === 0 && (
              <TableRow>
                <TableCell colSpan={5} align="center" sx={{ py: 4, opacity: 0.6 }}>
                  No releases published.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </Paper>

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm">
        <DialogTitle>Publish player release</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField label="Version (e.g. 0.3.1)" value={form.version} onChange={(e) => setForm({ ...form, version: e.target.value })} />
            <TextField label="Bundle URL" value={form.url} onChange={(e) => setForm({ ...form, url: e.target.value })} />
            <TextField label="SHA-256 checksum" value={form.checksum} onChange={(e) => setForm({ ...form, checksum: e.target.value })} />
            <TextField label="Notes" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} multiline />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button
            variant="contained"
            disabled={!form.version || !form.url || !form.checksum || create.isPending}
            onClick={() => create.mutate()}
          >
            Publish
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
