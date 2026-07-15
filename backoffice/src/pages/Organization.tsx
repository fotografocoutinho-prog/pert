import { useQuery } from '@tanstack/react-query';
import {
  Alert,
  Box,
  Card,
  CardContent,
  Chip,
  LinearProgress,
  Stack,
  Typography,
} from '@mui/material';
import type { License, Tenant } from '@signage/shared';
import { api } from '../api/client';

export default function Organization() {
  const { data: tenant, error: tErr } = useQuery<Tenant>({
    queryKey: ['tenant'],
    queryFn: async () => (await api.get<Tenant>('/api/tenants/me')).data,
  });
  const { data: license, error: lErr } = useQuery<License>({
    queryKey: ['license'],
    queryFn: async () => (await api.get<License>('/api/tenants/license')).data,
    refetchInterval: 15_000,
  });

  if (tErr || lErr) return <Alert severity="error">Failed to load organization</Alert>;

  const usagePercent =
    license && license.maxScreens ? (license.usedScreens / license.maxScreens) * 100 : 0;

  return (
    <Stack spacing={3}>
      <Typography variant="h5" fontWeight={700}>
        Organization
      </Typography>

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
        <Card>
          <CardContent>
            <Stack spacing={1}>
              <Typography variant="overline" color="text.secondary">
                Tenant
              </Typography>
              <Typography variant="h6" fontWeight={700}>
                {tenant?.name ?? '—'}
              </Typography>
              <Box>
                <Chip
                  size="small"
                  label={`${tenant?.plan ?? '—'} plan`}
                  color={tenant?.plan === 'enterprise' ? 'secondary' : tenant?.plan === 'pro' ? 'primary' : 'default'}
                />
              </Box>
            </Stack>
          </CardContent>
        </Card>

        <Card>
          <CardContent>
            <Stack spacing={1.5}>
              <Typography variant="overline" color="text.secondary">
                Screen licenses
              </Typography>
              <Typography variant="h6" fontWeight={700}>
                {license?.usedScreens ?? 0}
                {license?.maxScreens != null ? ` / ${license.maxScreens}` : ' / ∞'}
              </Typography>
              {license?.maxScreens != null && (
                <LinearProgress
                  variant="determinate"
                  value={Math.min(100, usagePercent)}
                  color={usagePercent >= 100 ? 'error' : usagePercent >= 80 ? 'warning' : 'primary'}
                />
              )}
              <Typography variant="body2" color="text.secondary">
                {license?.remainingScreens == null
                  ? 'Unlimited screens on this plan.'
                  : `${license.remainingScreens} screen(s) remaining.`}
              </Typography>
            </Stack>
          </CardContent>
        </Card>
      </Box>
    </Stack>
  );
}
