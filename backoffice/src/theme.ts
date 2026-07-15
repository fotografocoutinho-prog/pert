import { createTheme } from '@mui/material/styles';

export function buildTheme(mode: 'light' | 'dark') {
  return createTheme({
    palette: {
      mode,
      primary: { main: '#2563eb' },
      secondary: { main: '#7c3aed' },
      background:
        mode === 'dark'
          ? { default: '#0f172a', paper: '#1e293b' }
          : { default: '#f8fafc', paper: '#ffffff' },
    },
    shape: { borderRadius: 10 },
    typography: {
      fontFamily: 'Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif',
    },
  });
}
