import { useMemo, useState } from 'react';
import { Navigate, Route, BrowserRouter, Routes } from 'react-router-dom';
import { CssBaseline, ThemeProvider } from '@mui/material';
import { CircularProgress, Box } from '@mui/material';
import { buildTheme } from './theme';
import { AuthProvider, useAuth } from './store/auth';
import Layout from './components/Layout';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import Monitors from './pages/Monitors';
import Contents from './pages/Contents';
import Playlists from './pages/Playlists';
import Layouts from './pages/Layouts';
import LayoutEditor from './pages/LayoutEditor';
import Schedules from './pages/Schedules';
import Logs from './pages/Logs';
import Releases from './pages/Releases';
import Stats from './pages/Stats';
import Organization from './pages/Organization';
import Users from './pages/Users';

function Protected({
  children,
  mode,
  onToggleMode,
}: {
  children: React.ReactNode;
  mode: 'light' | 'dark';
  onToggleMode: () => void;
}) {
  const { user, loading } = useAuth();
  if (loading) {
    return (
      <Box sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center' }}>
        <CircularProgress />
      </Box>
    );
  }
  if (!user) return <Navigate to="/login" replace />;
  return (
    <Layout mode={mode} onToggleMode={onToggleMode}>
      {children}
    </Layout>
  );
}

export default function App() {
  const [mode, setMode] = useState<'light' | 'dark'>(
    (localStorage.getItem('signage.theme') as 'light' | 'dark') ?? 'dark',
  );
  const theme = useMemo(() => buildTheme(mode), [mode]);
  const toggle = () => {
    setMode((m) => {
      const next = m === 'dark' ? 'light' : 'dark';
      localStorage.setItem('signage.theme', next);
      return next;
    });
  };

  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route
              path="/"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Dashboard />
                </Protected>
              }
            />
            <Route
              path="/monitors"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Monitors />
                </Protected>
              }
            />
            <Route
              path="/contents"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Contents />
                </Protected>
              }
            />
            <Route
              path="/playlists"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Playlists />
                </Protected>
              }
            />
            <Route
              path="/layouts"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Layouts />
                </Protected>
              }
            />
            <Route
              path="/layouts/:id"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <LayoutEditor />
                </Protected>
              }
            />
            <Route
              path="/schedules"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Schedules />
                </Protected>
              }
            />
            <Route
              path="/logs"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Logs />
                </Protected>
              }
            />
            <Route
              path="/releases"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Releases />
                </Protected>
              }
            />
            <Route
              path="/stats"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Stats />
                </Protected>
              }
            />
            <Route
              path="/organization"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Organization />
                </Protected>
              }
            />
            <Route
              path="/users"
              element={
                <Protected mode={mode} onToggleMode={toggle}>
                  <Users />
                </Protected>
              }
            />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </ThemeProvider>
  );
}
