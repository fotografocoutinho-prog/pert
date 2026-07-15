import { useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, Chip, Stack, Typography } from '@mui/material';
import UploadIcon from '@mui/icons-material/Upload';
import type { Content } from '@signage/shared';
import { api } from '../api/client';

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

export default function Contents() {
  const qc = useQueryClient();
  const inputRef = useRef<HTMLInputElement>(null);

  const { data, isLoading, error } = useQuery<Content[]>({
    queryKey: ['contents'],
    queryFn: async () => (await api.get<Content[]>('/api/contents')).data,
  });

  const upload = useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData();
      form.append('file', file);
      await api.post('/api/contents', form);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['contents'] }),
  });

  if (error) return <Alert severity="error">Failed to load content</Alert>;

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="h5" fontWeight={700}>
          Content library {isLoading && '…'}
        </Typography>
        <Button
          variant="contained"
          startIcon={<UploadIcon />}
          disabled={upload.isPending}
          onClick={() => inputRef.current?.click()}
        >
          {upload.isPending ? 'Uploading…' : 'Upload'}
        </Button>
        <input
          ref={inputRef}
          type="file"
          hidden
          accept="image/*,video/*,audio/*,application/pdf"
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) upload.mutate(file);
            e.target.value = '';
          }}
        />
      </Stack>

      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: {
            xs: '1fr',
            sm: '1fr 1fr',
            md: 'repeat(3, 1fr)',
            lg: 'repeat(4, 1fr)',
          },
        }}
      >
        {(data ?? []).map((c) => (
          <Card key={c.id}>
            <CardContent>
              <Stack spacing={1}>
                <Box
                  sx={{
                    height: 120,
                    borderRadius: 1,
                    bgcolor: 'action.hover',
                    display: 'grid',
                    placeItems: 'center',
                  }}
                >
                  <Chip label={c.kind} size="small" />
                </Box>
                <Typography noWrap fontWeight={600} title={c.name}>
                  {c.name}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {formatBytes(c.sizeBytes)} · {c.mimeType}
                </Typography>
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Box>
      {data?.length === 0 && (
        <Typography sx={{ py: 4, textAlign: 'center', opacity: 0.6 }}>
          No content yet — upload an image, video, audio file or PDF.
        </Typography>
      )}
    </Stack>
  );
}
