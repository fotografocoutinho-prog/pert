import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogContent,
  DialogTitle,
  Stack,
  Typography,
} from '@mui/material';
import CameraIcon from '@mui/icons-material/CameraAlt';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import CleaningServicesIcon from '@mui/icons-material/CleaningServices';
import SyncIcon from '@mui/icons-material/Sync';
import type { Monitor, MonitorCommand, TelemetrySample } from '@signage/shared';
import { api, tokenStore } from '../api/client';

interface TelemetryResponse {
  latest: TelemetrySample | null;
  samples: TelemetrySample[];
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <Box sx={{ flex: 1, minWidth: 90 }}>
      <Typography variant="h6" fontWeight={700}>
        {value}
      </Typography>
      <Typography variant="caption" color="text.secondary">
        {label}
      </Typography>
    </Box>
  );
}

export default function MonitorDetailDialog({
  monitor,
  onClose,
}: {
  monitor: Monitor;
  onClose: () => void;
}) {
  const [shotVersion, setShotVersion] = useState(0);
  const [feedback, setFeedback] = useState<string | null>(null);

  const { data } = useQuery<TelemetryResponse>({
    queryKey: ['telemetry', monitor.id],
    queryFn: async () => (await api.get<TelemetryResponse>(`/api/monitors/${monitor.id}/telemetry`)).data,
    refetchInterval: 5_000,
  });

  const command = useMutation({
    mutationFn: async (cmd: MonitorCommand) => {
      await api.post(`/api/monitors/${monitor.id}/command`, { command: cmd });
    },
    onSuccess: (_r, cmd) => {
      setFeedback(`Command "${cmd}" sent`);
      if (cmd === 'screenshot') setTimeout(() => setShotVersion((v) => v + 1), 2500);
    },
    onError: () => setFeedback('Monitor is offline — command not delivered'),
  });

  const t = data?.latest;
  const bytes = (n: number | null | undefined) =>
    n == null ? '—' : `${(n / 1024 ** 3).toFixed(1)} GB`;
  const shotUrl = `${import.meta.env.VITE_API_URL ?? ''}/api/monitors/${monitor.id}/screenshot?token=${tokenStore.access}&v=${shotVersion}`;

  return (
    <Dialog open onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>{monitor.name}</DialogTitle>
      <DialogContent>
        <Stack spacing={2}>
          {feedback && <Alert severity="info" onClose={() => setFeedback(null)}>{feedback}</Alert>}

          <Stack direction="row" spacing={2} flexWrap="wrap" useFlexGap>
            <Metric label="CPU" value={t?.cpuPercent != null ? `${t.cpuPercent}%` : '—'} />
            <Metric label="RAM" value={t?.ramPercent != null ? `${t.ramPercent}%` : '—'} />
            <Metric label="Temp" value={t?.temperatureC != null ? `${t.temperatureC}°C` : '—'} />
            <Metric label="Free disk" value={bytes(t?.freeDiskBytes)} />
          </Stack>

          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
            <Button size="small" variant="outlined" startIcon={<CameraIcon />} onClick={() => command.mutate('screenshot')}>
              Screenshot
            </Button>
            <Button size="small" variant="outlined" startIcon={<SyncIcon />} onClick={() => command.mutate('update_content')}>
              Sync content
            </Button>
            <Button size="small" variant="outlined" startIcon={<CleaningServicesIcon />} onClick={() => command.mutate('clear_cache')}>
              Clear cache
            </Button>
            <Button size="small" variant="outlined" color="warning" startIcon={<RestartAltIcon />} onClick={() => command.mutate('restart')}>
              Restart
            </Button>
          </Stack>

          <Box
            sx={{
              width: '100%',
              aspectRatio: '16 / 9',
              bgcolor: 'action.hover',
              borderRadius: 1,
              overflow: 'hidden',
              display: 'grid',
              placeItems: 'center',
            }}
          >
            {shotVersion > 0 ? (
              <img
                src={shotUrl}
                alt="Latest screenshot"
                style={{ width: '100%', height: '100%', objectFit: 'contain' }}
                onError={(e) => ((e.target as HTMLImageElement).style.display = 'none')}
              />
            ) : (
              <Typography variant="body2" color="text.secondary">
                Press “Screenshot” to capture the screen
              </Typography>
            )}
          </Box>
        </Stack>
      </DialogContent>
    </Dialog>
  );
}
