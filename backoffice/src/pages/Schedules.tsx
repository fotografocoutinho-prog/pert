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
  TableHead,
  TableRow,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/Delete';
import type { Monitor, Playlist, Schedule, Weekday } from '@signage/shared';
import { api } from '../api/client';

const WEEKDAYS: { value: Weekday; label: string }[] = [
  { value: 1, label: 'Mon' },
  { value: 2, label: 'Tue' },
  { value: 3, label: 'Wed' },
  { value: 4, label: 'Thu' },
  { value: 5, label: 'Fri' },
  { value: 6, label: 'Sat' },
  { value: 0, label: 'Sun' },
];

export default function Schedules() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({
    name: '',
    playlistId: '',
    monitorId: '',
    priority: 0,
    startTime: '',
    endTime: '',
    weekdays: [] as Weekday[],
  });

  const { data, isLoading, error } = useQuery<Schedule[]>({
    queryKey: ['schedules'],
    queryFn: async () => (await api.get<Schedule[]>('/api/schedules')).data,
  });
  const { data: playlists } = useQuery<Playlist[]>({
    queryKey: ['playlists'],
    queryFn: async () => (await api.get<Playlist[]>('/api/playlists')).data,
  });
  const { data: monitors } = useQuery<Monitor[]>({
    queryKey: ['monitors'],
    queryFn: async () => (await api.get<Monitor[]>('/api/monitors')).data,
  });

  const create = useMutation({
    mutationFn: async () => {
      await api.post('/api/schedules', {
        name: form.name,
        playlistId: form.playlistId,
        monitorId: form.monitorId || null,
        priority: Number(form.priority),
        startTime: form.startTime || null,
        endTime: form.endTime || null,
        weekdays: form.weekdays,
      });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['schedules'] });
      setOpen(false);
      setForm({ name: '', playlistId: '', monitorId: '', priority: 0, startTime: '', endTime: '', weekdays: [] });
    },
  });

  const remove = useMutation({
    mutationFn: async (scheduleId: string) => {
      await api.delete(`/api/schedules/${scheduleId}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['schedules'] }),
  });

  if (error) return <Alert severity="error">Failed to load schedules</Alert>;

  const playlistName = (pid: string) => playlists?.find((p) => p.id === pid)?.name ?? pid;
  const monitorName = (mid: string | null) =>
    mid ? monitors?.find((m) => m.id === mid)?.name ?? mid : 'any (by group)';

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="h5" fontWeight={700}>
          Schedules {isLoading && '…'}
        </Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => setOpen(true)}>
          New schedule
        </Button>
      </Stack>

      <Paper>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>Name</TableCell>
              <TableCell>Playlist</TableCell>
              <TableCell>Target</TableCell>
              <TableCell>Time</TableCell>
              <TableCell>Days</TableCell>
              <TableCell align="right">Priority</TableCell>
              <TableCell />
            </TableRow>
          </TableHead>
          <TableBody>
            {(data ?? []).map((s) => (
              <TableRow key={s.id} hover>
                <TableCell>{s.name}</TableCell>
                <TableCell>{playlistName(s.playlistId)}</TableCell>
                <TableCell>{monitorName(s.monitorId)}</TableCell>
                <TableCell>{s.startTime && s.endTime ? `${s.startTime}–${s.endTime}` : 'all day'}</TableCell>
                <TableCell>
                  {s.weekdays.length === 0
                    ? 'every day'
                    : s.weekdays.map((d) => WEEKDAYS.find((w) => w.value === d)?.label).join(' ')}
                </TableCell>
                <TableCell align="right">
                  <Chip size="small" label={s.priority} />
                </TableCell>
                <TableCell align="right">
                  <IconButton size="small" color="error" onClick={() => remove.mutate(s.id)}>
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </TableCell>
              </TableRow>
            ))}
            {data?.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} align="center" sx={{ py: 4, opacity: 0.6 }}>
                  No schedules yet.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </Paper>

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm">
        <DialogTitle>New schedule</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            <TextField
              select
              label="Playlist"
              value={form.playlistId}
              onChange={(e) => setForm({ ...form, playlistId: e.target.value })}
            >
              {(playlists ?? []).map((p) => (
                <MenuItem key={p.id} value={p.id}>
                  {p.name}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label="Monitor"
              value={form.monitorId}
              onChange={(e) => setForm({ ...form, monitorId: e.target.value })}
            >
              {(monitors ?? []).map((m) => (
                <MenuItem key={m.id} value={m.id}>
                  {m.name}
                </MenuItem>
              ))}
            </TextField>
            <Stack direction="row" spacing={2}>
              <TextField
                label="Start time"
                type="time"
                value={form.startTime}
                onChange={(e) => setForm({ ...form, startTime: e.target.value })}
                InputLabelProps={{ shrink: true }}
                fullWidth
              />
              <TextField
                label="End time"
                type="time"
                value={form.endTime}
                onChange={(e) => setForm({ ...form, endTime: e.target.value })}
                InputLabelProps={{ shrink: true }}
                fullWidth
              />
              <TextField
                label="Priority"
                type="number"
                value={form.priority}
                onChange={(e) => setForm({ ...form, priority: Number(e.target.value) })}
                sx={{ width: 120 }}
              />
            </Stack>
            <Typography variant="body2" color="text.secondary">
              Days (none = every day)
            </Typography>
            <ToggleButtonGroup
              value={form.weekdays}
              onChange={(_e, value: Weekday[]) => setForm({ ...form, weekdays: value })}
              size="small"
            >
              {WEEKDAYS.map((w) => (
                <ToggleButton key={w.value} value={w.value}>
                  {w.label}
                </ToggleButton>
              ))}
            </ToggleButtonGroup>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button
            variant="contained"
            disabled={!form.name || !form.playlistId || !form.monitorId || create.isPending}
            onClick={() => create.mutate()}
          >
            Create
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
