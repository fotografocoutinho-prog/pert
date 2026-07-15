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
  List,
  ListItem,
  ListItemText,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import type { Playlist } from '@signage/shared';
import { api } from '../api/client';

export default function Playlists() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');

  const { data, isLoading, error } = useQuery<Playlist[]>({
    queryKey: ['playlists'],
    queryFn: async () => (await api.get<Playlist[]>('/api/playlists')).data,
  });

  const create = useMutation({
    mutationFn: async (playlistName: string) => {
      await api.post('/api/playlists', { name: playlistName });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['playlists'] });
      setOpen(false);
      setName('');
    },
  });

  if (error) return <Alert severity="error">Failed to load playlists</Alert>;

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="h5" fontWeight={700}>
          Playlists {isLoading && '…'}
        </Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => setOpen(true)}>
          New playlist
        </Button>
      </Stack>

      <Paper>
        <List>
          {(data ?? []).map((p) => (
            <ListItem
              key={p.id}
              secondaryAction={<Chip size="small" label={`${p.items.length} items`} />}
            >
              <ListItemText
                primary={p.name}
                secondary={`priority ${p.priority} · ${p.loop ? 'loop' : 'once'} · ${
                  p.active ? 'active' : 'inactive'
                }`}
              />
            </ListItem>
          ))}
          {data?.length === 0 && (
            <ListItem>
              <ListItemText sx={{ textAlign: 'center', opacity: 0.6 }} primary="No playlists yet" />
            </ListItem>
          )}
        </List>
      </Paper>

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="xs">
        <DialogTitle>New playlist</DialogTitle>
        <DialogContent>
          <TextField
            autoFocus
            fullWidth
            label="Name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            sx={{ mt: 1 }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button variant="contained" disabled={!name || create.isPending} onClick={() => create.mutate(name)}>
            Create
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
