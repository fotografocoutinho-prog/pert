// Self-contained renderer: no imports so it runs as a classic browser script.
// Types are declared locally to avoid a module system in the renderer bundle.

interface PlayerConfig {
  apiUrl: string;
  wsUrl: string;
  monitorId: string;
  token: string;
}

type Orientation = 'landscape' | 'portrait';
type ScaleMode = 'fit' | 'fill' | 'stretch';

interface ResolvedItem {
  contentId: string;
  kind: 'image' | 'video' | 'audio' | 'pdf';
  mimeType: string;
  durationSeconds: number;
  scaleMode: ScaleMode;
  transition: string;
  checksum: string;
}

interface ResolvedPlaylist {
  id: string;
  loop: boolean;
  shuffle: boolean;
  items: ResolvedItem[];
}

interface ResolvedZone {
  id: string;
  kind: string;
  x: number;
  y: number;
  width: number;
  height: number;
  config: Record<string, unknown>;
  playlist: ResolvedPlaylist | null;
}

interface PlayerState {
  monitorId: string;
  orientation: Orientation;
  resolution: string | null;
  layout: { id: string; zones: ResolvedZone[] } | null;
  fallbackPlaylist: ResolvedPlaylist | null;
  activeScheduleId: string | null;
}

interface SignageBridge {
  onConfig(cb: (config: PlayerConfig) => void): void;
  restart(): void;
  reload(): void;
  getTelemetry(): Promise<Record<string, unknown>>;
  captureScreenshot(): Promise<string | null>;
  cacheContents(items: { contentId: string; checksum: string }[]): Promise<Record<string, string>>;
  checkUpdate(): Promise<{ version: string } | null>;
}

const signage = (window as unknown as { signage: SignageBridge }).signage;

const stage = document.getElementById('stage') as HTMLDivElement;
const splash = document.getElementById('splash') as HTMLDivElement;
const statusEl = document.getElementById('status') as HTMLDivElement;

let config: PlayerConfig | null = null;
let socket: WebSocket | null = null;
const startedAt = Date.now();
let generation = 0; // bumped on each state load to cancel running loops
const timers: number[] = [];
let localUrls: Record<string, string> = {}; // contentId -> file:// URL when cached

function setStatus(text: string): void {
  statusEl.textContent = text;
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => timers.push(window.setTimeout(resolve, ms)));
}

function clearTimers(): void {
  while (timers.length) window.clearTimeout(timers.pop());
}

async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(`${config!.apiUrl}${path}`, {
    headers: { Authorization: `Bearer ${config!.token}` },
  });
  if (!res.ok) throw new Error(`GET ${path} -> ${res.status}`);
  return (await res.json()) as T;
}

// Prefer the offline cache; fall back to the authenticated remote URL.
function contentUrl(id: string): string {
  return (
    localUrls[id] ??
    `${config!.apiUrl}/api/contents/${id}/download?token=${encodeURIComponent(config!.token)}`
  );
}

/** Collects every content item referenced by the state, for the offline cache. */
function collectCacheItems(state: PlayerState): { contentId: string; checksum: string }[] {
  const items: { contentId: string; checksum: string }[] = [];
  const add = (pl: ResolvedPlaylist | null) => {
    for (const it of pl?.items ?? []) items.push({ contentId: it.contentId, checksum: it.checksum });
  };
  add(state.fallbackPlaylist);
  for (const z of state.layout?.zones ?? []) add(z.playlist);
  return items;
}

function shuffled<T>(arr: T[]): T[] {
  const copy = [...arr];
  for (let i = copy.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [copy[i], copy[j]] = [copy[j], copy[i]];
  }
  return copy;
}

// ---------------------------------------------------------------------------
// Orientation
// ---------------------------------------------------------------------------
function applyOrientation(orientation: Orientation): void {
  if (orientation === 'portrait') {
    Object.assign(stage.style, {
      width: '100vh',
      height: '100vw',
      position: 'absolute',
      top: '50%',
      left: '50%',
      transform: 'translate(-50%, -50%) rotate(90deg)',
      transformOrigin: 'center center',
    });
  } else {
    Object.assign(stage.style, {
      width: '100vw',
      height: '100vh',
      position: 'absolute',
      top: '0',
      left: '0',
      transform: 'none',
    });
  }
}

// ---------------------------------------------------------------------------
// State loading & rendering
// ---------------------------------------------------------------------------
async function loadState(): Promise<void> {
  const gen = ++generation;
  clearTimers();
  try {
    const state = await apiGet<PlayerState>(`/api/player/${config!.monitorId}/state`);
    if (gen !== generation) return;

    // Pre-cache media for offline playback; ignore failures (falls back to remote).
    try {
      localUrls = await signage.cacheContents(collectCacheItems(state));
    } catch {
      localUrls = {};
    }
    if (gen !== generation) return;

    applyOrientation(state.orientation);
    stage.innerHTML = '';
    splash.style.display = 'none';

    if (state.layout && state.layout.zones.length > 0) {
      for (const zone of state.layout.zones) renderZone(zone, gen);
    } else if (state.fallbackPlaylist) {
      renderZone(
        {
          id: 'fullscreen',
          kind: 'video',
          x: 0,
          y: 0,
          width: 100,
          height: 100,
          config: {},
          playlist: state.fallbackPlaylist,
        },
        gen,
      );
    } else {
      splash.style.display = 'grid';
      splash.textContent = 'Nothing scheduled';
    }
  } catch (err) {
    setStatus('state error');
    console.error(err);
  }
}

function makeZoneEl(zone: ResolvedZone): HTMLDivElement {
  const el = document.createElement('div');
  Object.assign(el.style, {
    position: 'absolute',
    left: `${zone.x}%`,
    top: `${zone.y}%`,
    width: `${zone.width}%`,
    height: `${zone.height}%`,
    overflow: 'hidden',
    background: '#000',
  });
  stage.appendChild(el);
  return el;
}

function renderZone(zone: ResolvedZone, gen: number): void {
  const el = makeZoneEl(zone);
  switch (zone.kind) {
    case 'video':
    case 'image':
      if (zone.playlist) void runMediaZone(el, zone.playlist, gen);
      break;
    case 'clock':
      renderClock(el, zone, gen);
      break;
    case 'text':
      renderText(el, zone);
      break;
    case 'website':
      renderIframe(el, String(zone.config.url ?? ''));
      break;
    case 'youtube':
      renderIframe(el, youtubeEmbed(String(zone.config.url ?? '')));
      break;
    case 'html':
      el.innerHTML = String(zone.config.html ?? '');
      break;
    case 'rss':
    case 'news':
      void renderFeed(el, String(zone.config.url ?? ''), gen);
      break;
    case 'weather':
      void renderWeather(el, String(zone.config.city ?? ''), gen);
      break;
  }
}

// ---------------------------------------------------------------------------
// Media playback (per zone)
// ---------------------------------------------------------------------------
function showLayer(container: HTMLElement, media: HTMLElement, scaleMode: ScaleMode): void {
  const layer = document.createElement('div');
  Object.assign(layer.style, {
    position: 'absolute',
    inset: '0',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    opacity: '0',
    transition: 'opacity 600ms ease',
    background: '#000',
  });
  const objectFit = scaleMode === 'fill' ? 'cover' : scaleMode === 'stretch' ? 'fill' : 'contain';
  Object.assign(media.style, { width: '100%', height: '100%', objectFit });
  layer.appendChild(media);
  container.appendChild(layer);
  void layer.offsetWidth; // reflow so the fade runs
  layer.style.opacity = '1';
  const stale = Array.from(container.children).filter((c) => c !== layer);
  window.setTimeout(() => stale.forEach((c) => c.remove()), 700);
}

async function runMediaZone(el: HTMLElement, playlist: ResolvedPlaylist, gen: number): Promise<void> {
  do {
    if (gen !== generation) return;
    const items = playlist.shuffle ? shuffled(playlist.items) : playlist.items;
    for (const item of items) {
      if (gen !== generation) return;
      await playItem(el, item, gen);
    }
  } while (playlist.loop && gen === generation && playlist.items.length > 0);
}

async function playItem(el: HTMLElement, item: ResolvedItem, gen: number): Promise<void> {
  const url = contentUrl(item.contentId);
  if (item.kind === 'video') {
    const video = document.createElement('video');
    video.src = url;
    video.autoplay = true;
    video.muted = true;
    (video as HTMLVideoElement).playsInline = true;
    showLayer(el, video, item.scaleMode);
    await new Promise<void>((resolve) => {
      video.onended = () => resolve();
      video.onerror = () => resolve();
    });
  } else if (item.kind === 'image') {
    const img = document.createElement('img');
    img.src = url;
    showLayer(el, img, item.scaleMode);
    await delay(item.durationSeconds * 1000);
  } else {
    await delay(item.durationSeconds * 1000);
  }
  void gen;
}

// ---------------------------------------------------------------------------
// Widgets
// ---------------------------------------------------------------------------
function centeredBox(el: HTMLElement): HTMLDivElement {
  const box = document.createElement('div');
  Object.assign(box.style, {
    width: '100%',
    height: '100%',
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    justifyContent: 'center',
    color: '#e2e8f0',
    fontFamily: 'system-ui, sans-serif',
    textAlign: 'center',
    padding: '4%',
    boxSizing: 'border-box',
  });
  el.appendChild(box);
  return box;
}

function renderClock(el: HTMLElement, zone: ResolvedZone, gen: number): void {
  const box = centeredBox(el);
  const time = document.createElement('div');
  const date = document.createElement('div');
  Object.assign(time.style, { fontSize: 'min(18vw, 18vh)', fontWeight: '700', lineHeight: '1' });
  Object.assign(date.style, { fontSize: 'min(5vw, 5vh)', opacity: '0.7', marginTop: '2%' });
  box.append(time, date);
  const tz = (zone.config.timezone as string) || undefined;
  const tick = () => {
    if (gen !== generation) return;
    const now = new Date();
    time.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: tz });
    date.textContent = now.toLocaleDateString([], {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      timeZone: tz,
    });
    timers.push(window.setTimeout(tick, 1000));
  };
  tick();
}

function renderText(el: HTMLElement, zone: ResolvedZone): void {
  const text = String(zone.config.text ?? '');
  const wrap = document.createElement('div');
  Object.assign(wrap.style, {
    width: '100%',
    height: '100%',
    display: 'flex',
    alignItems: 'center',
    overflow: 'hidden',
    color: '#e2e8f0',
    fontFamily: 'system-ui, sans-serif',
    fontSize: 'min(8vh, 6vw)',
    whiteSpace: 'nowrap',
  });
  const span = document.createElement('span');
  span.textContent = text;
  span.style.display = 'inline-block';
  span.style.paddingLeft = '100%';
  span.style.animation = 'marquee 15s linear infinite';
  ensureMarqueeKeyframes();
  wrap.appendChild(span);
  el.appendChild(wrap);
}

function ensureMarqueeKeyframes(): void {
  if (document.getElementById('marquee-kf')) return;
  const style = document.createElement('style');
  style.id = 'marquee-kf';
  style.textContent = '@keyframes marquee { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }';
  document.head.appendChild(style);
}

function renderIframe(el: HTMLElement, url: string): void {
  if (!url) return;
  const iframe = document.createElement('iframe');
  iframe.src = url;
  Object.assign(iframe.style, { width: '100%', height: '100%', border: '0' });
  iframe.setAttribute('allow', 'autoplay; encrypted-media');
  el.appendChild(iframe);
}

function youtubeEmbed(input: string): string {
  if (!input) return '';
  const idMatch = input.match(/(?:v=|youtu\.be\/|embed\/)([\w-]{11})/);
  const id = idMatch ? idMatch[1] : input;
  return `https://www.youtube.com/embed/${id}?autoplay=1&mute=1&loop=1&playlist=${id}&controls=0`;
}

async function renderFeed(el: HTMLElement, url: string, gen: number): Promise<void> {
  const box = centeredBox(el);
  box.style.justifyContent = 'flex-start';
  box.style.alignItems = 'flex-start';
  box.style.textAlign = 'left';
  if (!url) {
    box.textContent = 'RSS: set a feed URL';
    return;
  }
  try {
    const res = await fetch(url);
    const xml = new DOMParser().parseFromString(await res.text(), 'text/xml');
    const titles = Array.from(xml.querySelectorAll('item > title, entry > title'))
      .map((n) => n.textContent ?? '')
      .filter(Boolean)
      .slice(0, 8);
    if (gen !== generation) return;
    box.innerHTML = '';
    for (const t of titles) {
      const row = document.createElement('div');
      row.textContent = `• ${t}`;
      Object.assign(row.style, { fontSize: 'min(4vh, 3vw)', margin: '1% 0' });
      box.appendChild(row);
    }
  } catch {
    box.textContent = 'RSS unavailable';
  }
}

async function renderWeather(el: HTMLElement, city: string, gen: number): Promise<void> {
  const box = centeredBox(el);
  if (!city) {
    box.textContent = 'Weather: set a city';
    return;
  }
  try {
    const geo = await fetch(
      `https://geocoding-api.open-meteo.com/v1/search?name=${encodeURIComponent(city)}&count=1`,
    ).then((r) => r.json());
    const place = geo.results?.[0];
    if (!place) throw new Error('city not found');
    const wx = await fetch(
      `https://api.open-meteo.com/v1/forecast?latitude=${place.latitude}&longitude=${place.longitude}&current=temperature_2m`,
    ).then((r) => r.json());
    if (gen !== generation) return;
    box.innerHTML = '';
    const name = document.createElement('div');
    name.textContent = place.name;
    Object.assign(name.style, { fontSize: 'min(6vh, 4vw)', opacity: '0.8' });
    const temp = document.createElement('div');
    temp.textContent = `${Math.round(wx.current?.temperature_2m)}°C`;
    Object.assign(temp.style, { fontSize: 'min(14vh, 10vw)', fontWeight: '700' });
    box.append(name, temp);
  } catch {
    box.textContent = 'Weather unavailable';
  }
}

// ---------------------------------------------------------------------------
// WebSocket channel
// ---------------------------------------------------------------------------
function connect(): void {
  const url = `${config!.wsUrl}/ws?token=${encodeURIComponent(config!.token)}&monitorId=${encodeURIComponent(config!.monitorId)}`;
  socket = new WebSocket(url);

  socket.onopen = () => {
    setStatus('online');
    send({ type: 'hello', monitorId: config!.monitorId, playerVersion: '0.2.0', os: navigator.platform });
    void loadState();
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
    window.setTimeout(connect, 3_000);
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
      void loadState();
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
      return;
    case 'update_content':
    case 'clear_cache':
      void loadState();
      break;
    case 'screenshot':
      void captureAndSend(commandId);
      return;
    case 'update_player':
      void applyUpdate();
      break;
  }
  send({ type: 'ack', command, commandId, ok: true });
}

async function captureAndSend(commandId: string): Promise<void> {
  try {
    const dataUrl = await signage.captureScreenshot();
    if (dataUrl) send({ type: 'screenshot', commandId, dataUrl });
    send({ type: 'ack', command: 'screenshot', commandId, ok: !!dataUrl });
  } catch {
    send({ type: 'ack', command: 'screenshot', commandId, ok: false });
  }
}

async function applyUpdate(): Promise<void> {
  try {
    const applied = await signage.checkUpdate();
    if (applied) signage.restart();
  } catch {
    /* ignore; will retry on next command */
  }
}

function startHeartbeat(intervalMs: number): void {
  const beat = async () => {
    let telemetry: Record<string, unknown>;
    try {
      telemetry = await signage.getTelemetry();
    } catch {
      telemetry = { online: true, uptimeSeconds: Math.floor((Date.now() - startedAt) / 1000) };
    }
    send({ type: 'heartbeat', telemetry });
  };
  void beat();
  window.setInterval(() => void beat(), intervalMs);
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
