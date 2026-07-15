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
  Switch,
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
import type { User, UserRole } from '@signage/shared';
import { api } from '../api/client';
import { useAuth } from '../store/auth';

const ROLES: UserRole[] = ['admin', 'operator', 'client'];

export default function Users() {
  const qc = useQueryClient();
  const { user: me } = useAuth();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ email: '', name: '', password: '', role: 'operator' as UserRole });
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading, error: loadError } = useQuery<User[]>({
    queryKey: ['users'],
    queryFn: async () => (await api.get<User[]>('/api/auth/users')).data,
  });

  const create = useMutation({
    mutationFn: async () => {
      await api.post('/api/auth/users', form);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['users'] });
      setOpen(false);
      setForm({ email: '', name: '', password: '', role: 'operator' });
      setError(null);
    },
    onError: (e: unknown) => {
      const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(msg ?? 'Failed to create user');
    },
  });

  const update = useMutation({
    mutationFn: async (payload: { id: string; role?: UserRole; active?: boolean }) => {
      await api.patch(`/api/auth/users/${payload.id}`, { role: payload.role, active: payload.active });
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
  });

  const remove = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/api/auth/users/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
  });

  if (loadError) return <Alert severity="error">Failed to load users (admin only)</Alert>;

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="h5" fontWeight={700}>
          Users {isLoading && '…'}
        </Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => setOpen(true)}>
          Add user
        </Button>
      </Stack>

      <Paper>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>Name</TableCell>
              <TableCell>Email</TableCell>
              <TableCell>Role</TableCell>
              <TableCell>Active</TableCell>
              <TableCell />
            </TableRow>
          </TableHead>
          <TableBody>
            {(data ?? []).map((u) => (
              <TableRow key={u.id} hover>
                <TableCell>
                  {u.name} {u.id === me?.id && <Chip size="small" label="you" sx={{ ml: 1 }} />}
                </TableCell>
                <TableCell>{u.email}</TableCell>
                <TableCell>
                  <TextField
                    select
                    size="small"
                    value={u.role}
                    disabled={u.id === me?.id}
                    onChange={(e) => update.mutate({ id: u.id, role: e.target.value as UserRole })}
                    variant="standard"
                  >
                    {ROLES.map((r) => (
                      <MenuItem key={r} value={r}>
                        {r}
                      </MenuItem>
                    ))}
                  </TextField>
                </TableCell>
                <TableCell>
                  <Switch
                    checked={u.active}
                    disabled={u.id === me?.id}
                    onChange={(e) => update.mutate({ id: u.id, active: e.target.checked })}
                  />
                </TableCell>
                <TableCell align="right">
                  <IconButton
                    size="small"
                    color="error"
                    disabled={u.id === me?.id}
                    onClick={() => remove.mutate(u.id)}
                  >
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Paper>

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="xs">
        <DialogTitle>Add user</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            {error && <Alert severity="error">{error}</Alert>}
            <TextField label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            <TextField label="Email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            <TextField
              label="Password"
              type="password"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              helperText="At least 8 characters"
            />
            <TextField
              select
              label="Role"
              value={form.role}
              onChange={(e) => setForm({ ...form, role: e.target.value as UserRole })}
            >
              {ROLES.map((r) => (
                <MenuItem key={r} value={r}>
                  {r}
                </MenuItem>
              ))}
            </TextField>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button
            variant="contained"
            disabled={!form.email || !form.name || form.password.length < 8 || create.isPending}
            onClick={() => create.mutate()}
          >
            Create
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
