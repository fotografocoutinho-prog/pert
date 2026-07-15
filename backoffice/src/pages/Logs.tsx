import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  Alert,
  Chip,
  MenuItem,
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
import type { LogEntry, LogLevel } from '@signage/shared';
import { api } from '../api/client';

interface LogsResponse {
  items: LogEntry[];
  total: number;
}

const LEVEL_COLOR: Record<LogLevel, 'default' | 'warning' | 'error'> = {
  info: 'default',
  warn: 'warning',
  error: 'error',
};

export default function Logs() {
  const [level, setLevel] = useState<'' | LogLevel>('');

  const { data, isLoading, error } = useQuery<LogsResponse>({
    queryKey: ['logs', level],
    queryFn: async () =>
      (await api.get<LogsResponse>('/api/logs', { params: { limit: 100, level: level || undefined } }))
        .data,
    refetchInterval: 10_000,
  });

  if (error) return <Alert severity="error">Failed to load logs</Alert>;

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="h5" fontWeight={700}>
          Audit log {isLoading && '…'}
        </Typography>
        <TextField
          select
          size="small"
          label="Level"
          value={level}
          onChange={(e) => setLevel(e.target.value as '' | LogLevel)}
          sx={{ width: 160 }}
        >
          <MenuItem value="">All</MenuItem>
          <MenuItem value="info">Info</MenuItem>
          <MenuItem value="warn">Warn</MenuItem>
          <MenuItem value="error">Error</MenuItem>
        </TextField>
      </Stack>

      <Paper>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Time</TableCell>
              <TableCell>Level</TableCell>
              <TableCell>Action</TableCell>
              <TableCell>Detail</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {(data?.items ?? []).map((l) => (
              <TableRow key={l.id} hover>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>{new Date(l.createdAt).toLocaleString()}</TableCell>
                <TableCell>
                  <Chip size="small" label={l.level} color={LEVEL_COLOR[l.level]} />
                </TableCell>
                <TableCell>{l.action}</TableCell>
                <TableCell sx={{ fontFamily: 'monospace', fontSize: 12, opacity: 0.8 }}>
                  {l.detail ? JSON.stringify(l.detail) : '—'}
                </TableCell>
              </TableRow>
            ))}
            {data?.items.length === 0 && (
              <TableRow>
                <TableCell colSpan={4} align="center" sx={{ py: 4, opacity: 0.6 }}>
                  No log entries.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </Paper>
    </Stack>
  );
}
