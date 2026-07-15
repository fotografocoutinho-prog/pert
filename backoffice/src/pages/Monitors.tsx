import { useQuery } from '@tanstack/react-query';
import {
  Alert,
  Chip,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import type { Monitor } from '@signage/shared';
import { api } from '../api/client';

export default function Monitors() {
  const { data, isLoading, error } = useQuery<Monitor[]>({
    queryKey: ['monitors'],
    queryFn: async () => (await api.get<Monitor[]>('/api/monitors')).data,
    refetchInterval: 10_000,
  });

  if (error) return <Alert severity="error">Failed to load monitors</Alert>;

  return (
    <Stack spacing={3}>
      <Typography variant="h5" fontWeight={700}>
        Monitors {isLoading && '…'}
      </Typography>
      <TableContainer component={Paper}>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>Name</TableCell>
              <TableCell>Group</TableCell>
              <TableCell>Location</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Player</TableCell>
              <TableCell>OS</TableCell>
              <TableCell>Last seen</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {(data ?? []).map((m) => (
              <TableRow key={m.id} hover>
                <TableCell>{m.name}</TableCell>
                <TableCell>{m.groupName ?? '—'}</TableCell>
                <TableCell>{m.location ?? '—'}</TableCell>
                <TableCell>
                  <Chip
                    size="small"
                    label={m.status}
                    color={m.status === 'online' ? 'success' : 'default'}
                  />
                </TableCell>
                <TableCell>{m.playerVersion ?? '—'}</TableCell>
                <TableCell>{m.os ?? '—'}</TableCell>
                <TableCell>{m.lastSeenAt ? new Date(m.lastSeenAt).toLocaleString() : '—'}</TableCell>
              </TableRow>
            ))}
            {data?.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} align="center" sx={{ py: 4, opacity: 0.6 }}>
                  No monitors yet. Add one via the API or a future "Add monitor" dialog.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </TableContainer>
    </Stack>
  );
}
