// Self-contained renderer: no imports so it runs as a classic browser script.
// Types are declared locally to avoid a module system in the renderer bundle.

interface PlayerConfig {
  apiUrl: string;
  wsUrl: string;
  monitorId: string;
  token: string;
}

interface PlaylistItem {
  contentId: string;
  durationSeconds: number;
  scaleMode: 'fit' | 'fill' | 'stretch';
  transition: string;
}

interface Playlist {
  id: string;
  loop: boolean;
  shuffle: boolean;
  items: PlaylistItem[];
}

interface Content {
  id: string;
  kind: 'image' | 'video' | 'audio' | 'pdf';
  mimeType: string;
}

interface SignageBridge {
  onConfig(cb: (config: PlayerConfig) => void): void;
  restart(): void;
  reload(): void;
}

const signage = (window as unknown as { signage: SignageBridge }).signage;

const stage = document.getElementById('stage') as HTMLDivElement;
const splash = document.getElementById('splash') as HTMLDivElement;
const statusEl = document.getElementById('status') as HTMLDivElement;

let config: PlayerConfig | null = null;
let socket: WebSocket | null = null;
let playlist: Playlist | null = null;
const contentCache = new Map<string, Content>();
const startedAt = Date.now();
let playbackToken = 0; // invalidates in-flight playback loops on resync

function setStatus(text: string): void {
  statusEl.textContent = text;
}

async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(`${config!.apiUrl}${path}`, {
    headers: { Authorization: `Bearer ${config!.token}` },
  });
  if (!res.ok) throw new Error(`GET ${path} -> ${res.status}`);
  return (await res.json()) as T;
}

function contentUrl(id: string): string {
  return `${config!.apiUrl}/api/contents/${id}/download`;
}

/** Loads the monitor's assigned playlist and its content metadata. */
async function loadAssignment(): Promise<void> {
  const monitor = await apiGet<{ playlistId: string | null }>(
    `/api/monitors/${config!.monitorId}`,
  );
  if (!monitor.playlistId) {
    playlist = null;
    splash.style.display = 'grid';
    splash.textContent = 'No playlist assigned';
    return;
  }
  playlist = await apiGet<Playlist>(`/api/playlists/${monitor.playlistId}`);
  for (const item of playlist.items) {
    if (!contentCache.has(item.contentId)) {
      contentCache.set(item.contentId, await apiGet<Content>(`/api/contents/${item.contentId}`));
    }
  }
  splash.style.display = 'none';
  void runPlaylist();
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function shuffled<T>(arr: T[]): T[] {
  const copy = [...arr];
  for (let i = copy.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [copy[i], copy[j]] = [copy[j], copy[i]];
  }
  return copy;
}

/** Cross-fades a freshly built media element into view and removes old layers. */
function showLayer(el: HTMLElement, scaleMode: string): void {
  const layer = document.createElement('div');
  layer.className = `layer ${scaleMode}`;
  layer.appendChild(el);
  stage.appendChild(layer);
  // Force reflow so the opacity transition runs.
  void layer.offsetWidth;
  layer.classList.add('visible');
  // Remove layers underneath after the fade completes.
  const stale = Array.from(stage.children).filter((c) => c !== layer);
  setTimeout(() => stale.forEach((c) => c.remove()), 700);
}

async function playItem(item: PlaylistItem, token: number): Promise<void> {
  const content = contentCache.get(item.contentId);
  if (!content) return;
  const url = contentUrl(item.contentId);

  if (content.kind === 'video') {
    const video = document.createElement('video');
    video.src = url;
    video.autoplay = true;
    video.muted = true;
    video.playsInline = true;
    showLayer(video, item.scaleMode);
    await new Promise<void>((resolve) => {
      video.onended = () => resolve();
      video.onerror = () => resolve();
    });
  } else if (content.kind === 'image') {
    const img = document.createElement('img');
    img.src = url;
    showLayer(img, item.scaleMode);
    await delay(item.durationSeconds * 1000);
  } else {
    // audio / pdf: honour duration, rendering handled in a later phase.
    await delay(item.durationSeconds * 1000);
  }
  void token;
}

async function runPlaylist(): Promise<void> {
  const token = ++playbackToken;
  do {
    if (!playlist || playlist.items.length === 0) return;
    const items = playlist.shuffle ? shuffled(playlist.items) : playlist.items;
    for (const item of items) {
      if (token !== playbackToken) return; // superseded by a resync
      await playItem(item, token);
    }
  } while (playlist?.loop && token === playbackToken);
}

// ---------------------------------------------------------------------------
// WebSocket channel
// ---------------------------------------------------------------------------
function connect(): void {
  const url = `${config!.wsUrl}/ws?token=${encodeURIComponent(config!.token)}&monitorId=${encodeURIComponent(config!.monitorId)}`;
  socket = new WebSocket(url);

  socket.onopen = () => {
    setStatus('online');
    send({ type: 'hello', monitorId: config!.monitorId, playerVersion: '0.1.0', os: navigator.platform });
    void loadAssignment();
  };

  socket.onmessage = (event) => {
    let msg: Record<string, unknown>;
    try {
      msg = JSON.parse(event.data as string);
    } catch {
      return;
    }
    handleMessage(msg);
  };

  socket.onclose = () => {
    setStatus('reconnecting…');
    setTimeout(connect, 3_000); // sync when the network returns
  };

  socket.onerror = () => socket?.close();
}

function send(message: Record<string, unknown>): void {
  if (socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify(message));
}

function handleMessage(msg: Record<string, unknown>): void {
  switch (msg.type) {
    case 'welcome':
      startHeartbeat(Number(msg.heartbeatIntervalMs) || 15_000);
      break;
    case 'sync':
      void loadAssignment();
      break;
    case 'command':
      handleCommand(String(msg.command), String(msg.commandId));
      break;
  }
}

function handleCommand(command: string, commandId: string): void {
  switch (command) {
    case 'restart':
      signage.restart();
      break;
    case 'update_content':
      void loadAssignment();
      break;
    case 'clear_cache':
      contentCache.clear();
      void loadAssignment();
      break;
    case 'screenshot':
      // A real capture is added in a later phase; ack so the server knows.
      send({ type: 'ack', command, commandId, ok: true });
      return;
  }
  send({ type: 'ack', command, commandId, ok: true });
}

function startHeartbeat(intervalMs: number): void {
  window.setInterval(() => {
    send({
      type: 'heartbeat',
      telemetry: { online: true, uptimeSeconds: Math.floor((Date.now() - startedAt) / 1000) },
    });
  }, intervalMs);
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------
signage.onConfig((cfg) => {
  config = cfg;
  if (!cfg.monitorId || !cfg.token) {
    splash.textContent = 'Player not paired. Set SIGNAGE_MONITOR_ID and SIGNAGE_TOKEN.';
    return;
  }
  connect();
});
