import { useQuery } from '@tanstack/react-query';
import { Alert, Box, Card, CardContent, Stack, Typography } from '@mui/material';
import MonitorIcon from '@mui/icons-material/DesktopWindows';
import WifiIcon from '@mui/icons-material/Wifi';
import WifiOffIcon from '@mui/icons-material/WifiOff';
import StorageIcon from '@mui/icons-material/Storage';
import PlaylistPlayIcon from '@mui/icons-material/PlaylistPlay';
import { api } from '../api/client';

interface Stats {
  monitors: { total: number; online: number; offline: number };
  storageBytes: number;
  activePlaylists: number;
  alerts: { level: string; message: string }[];
}

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

function StatCard({
  label,
  value,
  icon,
  color,
}: {
  label: string;
  value: string | number;
  icon: React.ReactNode;
  color: string;
}) {
  return (
    <Card>
      <CardContent>
        <Stack direction="row" spacing={2} alignItems="center">
          <Box sx={{ color, display: 'flex' }}>{icon}</Box>
          <Box>
            <Typography variant="h4" fontWeight={700}>
              {value}
            </Typography>
            <Typography variant="body2" color="text.secondary">
              {label}
            </Typography>
          </Box>
        </Stack>
      </CardContent>
    </Card>
  );
}

export default function Dashboard() {
  const { data, isLoading, error } = useQuery<Stats>({
    queryKey: ['dashboard-stats'],
    queryFn: async () => (await api.get<Stats>('/api/dashboard/stats')).data,
    refetchInterval: 10_000,
  });

  if (error) return <Alert severity="error">Failed to load dashboard</Alert>;

  const s = data ?? { monitors: { total: 0, online: 0, offline: 0 }, storageBytes: 0, activePlaylists: 0, alerts: [] };

  return (
    <Stack spacing={3}>
      <Typography variant="h5" fontWeight={700}>
        Dashboard {isLoading && '…'}
      </Typography>

      {s.alerts.map((a, i) => (
        <Alert key={i} severity={a.level === 'warning' ? 'warning' : 'info'}>
          {a.message}
        </Alert>
      ))}

      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', md: 'repeat(4, 1fr)' },
        }}
      >
        <StatCard label="Screens" value={s.monitors.total} icon={<MonitorIcon fontSize="large" />} color="#2563eb" />
        <StatCard label="Online" value={s.monitors.online} icon={<WifiIcon fontSize="large" />} color="#16a34a" />
        <StatCard label="Offline" value={s.monitors.offline} icon={<WifiOffIcon fontSize="large" />} color="#dc2626" />
        <StatCard label="Active playlists" value={s.activePlaylists} icon={<PlaylistPlayIcon fontSize="large" />} color="#7c3aed" />
        <StatCard label="Storage used" value={formatBytes(s.storageBytes)} icon={<StorageIcon fontSize="large" />} color="#ea580c" />
      </Box>
    </Stack>
  );
}
