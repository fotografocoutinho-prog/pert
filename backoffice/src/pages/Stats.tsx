import { useQuery } from '@tanstack/react-query';
import {
  Alert,
  Box,
  Card,
  CardContent,
  LinearProgress,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import type { PlayStats } from '@signage/shared';
import { api } from '../api/client';

function formatDuration(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (h > 0) return `${h}h ${m}m`;
  const s = Math.floor(seconds % 60);
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

export default function Stats() {
  const { data, isLoading, error } = useQuery<PlayStats>({
    queryKey: ['play-stats'],
    queryFn: async () => (await api.get<PlayStats>('/api/stats/play')).data,
    refetchInterval: 15_000,
  });

  if (error) return <Alert severity="error">Failed to load statistics</Alert>;

  const maxPlays = Math.max(1, ...(data?.byContent ?? []).map((c) => c.plays));

  return (
    <Stack spacing={3}>
      <Typography variant="h5" fontWeight={700}>
        Proof of play {isLoading && '…'}
      </Typography>

      <Card sx={{ maxWidth: 280 }}>
        <CardContent>
          <Typography variant="h3" fontWeight={700}>
            {data?.totalPlays ?? 0}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            plays in the last 7 days
          </Typography>
        </CardContent>
      </Card>

      <Paper>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>Content</TableCell>
              <TableCell>Plays</TableCell>
              <TableCell align="right">Total screen time</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {(data?.byContent ?? []).map((c) => (
              <TableRow key={c.contentId} hover>
                <TableCell>{c.contentName}</TableCell>
                <TableCell sx={{ width: '50%' }}>
                  <Stack direction="row" spacing={1} alignItems="center">
                    <Box sx={{ flexGrow: 1 }}>
                      <LinearProgress variant="determinate" value={(c.plays / maxPlays) * 100} />
                    </Box>
                    <Typography variant="body2" sx={{ minWidth: 32 }}>
                      {c.plays}
                    </Typography>
                  </Stack>
                </TableCell>
                <TableCell align="right">{formatDuration(c.totalSeconds)}</TableCell>
              </TableRow>
            ))}
            {data?.byContent.length === 0 && (
              <TableRow>
                <TableCell colSpan={3} align="center" sx={{ py: 4, opacity: 0.6 }}>
                  No play events recorded yet.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </Paper>
    </Stack>
  );
}
