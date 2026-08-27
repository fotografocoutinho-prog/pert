/**
 * 1000viagens — servidor.
 *
 * Serve o site público, o backoffice e a API. Sem dependências externas:
 * só módulos nativos do Node (>= 20). Arranque: `npm start`.
 */
import http from 'node:http';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

import { JsonStore, ensureDirSync } from './store.js';
import { AuthService, SESSION_COOKIE } from './auth.js';
import { DEFAULT_SETTINGS, mergeSettings, publicSettings, adminSettings } from './settings.js';
import { PUBLIC_CATALOG, validId } from './catalog.js';
import { computeStats, estimateValue, leadsToCsv, normalizeLead, foldKey } from './leads.js';
import { sendMail, smtpConfigured } from './mailer.js';
import {
  sendJson, sendText, readJsonBody, parseCookies, serializeCookie,
  cleanText, cleanMultiline, createRateLimiter, clientIp, toNumber, toInt,
} from './util.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const PUBLIC_DIR = path.join(ROOT, 'public');

const PORT = Number(process.env.PORT || 3000);
const DATA_DIR = path.resolve(ROOT, process.env.DATA_DIR || 'data');
const UPLOADS_DIR = path.join(DATA_DIR, 'uploads');
ensureDirSync(UPLOADS_DIR);

const settingsStore = new JsonStore(path.join(DATA_DIR, 'settings.json'), {});
const leadsStore = new JsonStore(path.join(DATA_DIR, 'leads.json'), { sequence: 0, items: [] });
const authStore = new JsonStore(path.join(DATA_DIR, 'auth.json'), { sessions: [] });
const auth = new AuthService(authStore);

const leadLimiter = createRateLimiter({ windowMs: 10 * 60_000, max: 6 });
const loginLimiter = createRateLimiter({ windowMs: 10 * 60_000, max: 10 });

/* ────────────────────────────────  Definições  ──────────────────────────────── */

async function getSettings() {
  const stored = await settingsStore.read();
  return mergeSettings(DEFAULT_SETTINGS, stored);
}

async function saveSettings(patch) {
  return settingsStore.update((data) => {
    const merged = mergeSettings(mergeSettings(DEFAULT_SETTINGS, data), patch);
    merged.meta = {
      ...merged.meta,
      createdAt: merged.meta?.createdAt || new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    };
    for (const key of Object.keys(data)) delete data[key];
    Object.assign(data, merged);
    return merged;
  });
}

/* ──────────────────────────────  Ficheiros estáticos  ────────────────────────── */

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.gif': 'image/gif',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
  '.xml': 'application/xml; charset=utf-8',
  '.webmanifest': 'application/manifest+json',
};

async function serveFile(res, filePath, { immutable = false, sandbox = false, req } = {}) {
  try {
    const stat = await fsp.stat(filePath);
    if (!stat.isFile()) throw Object.assign(new Error('não é ficheiro'), { code: 'ENOENT' });
    const ext = path.extname(filePath).toLowerCase();
    const etag = `"${stat.size.toString(16)}-${stat.mtimeMs.toString(16)}"`;
    const headers = {
      'Content-Type': MIME[ext] || 'application/octet-stream',
      'Content-Length': stat.size,
      'X-Content-Type-Options': 'nosniff',
      // Os uploads têm nome único (podem ficar em cache para sempre); o resto
      // é revalidado a cada pedido para uma alteração no backoffice aparecer já.
      'Cache-Control': immutable ? 'public, max-age=31536000, immutable' : 'no-cache',
      ETag: etag,
    };
    if (req && req.headers['if-none-match'] === etag) {
      res.writeHead(304, { ETag: etag, 'Cache-Control': headers['Cache-Control'] });
      res.end();
      return true;
    }
    // Uploads são conteúdo enviado pelo utilizador: bloquear qualquer script
    // (um SVG pode trazer <script> lá dentro).
    if (sandbox) headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; img-src data:";
    res.writeHead(200, headers);
    fs.createReadStream(filePath).pipe(res);
    return true;
  } catch {
    return false;
  }
}

function safeJoin(base, target) {
  const resolved = path.resolve(base, `.${path.posix.normalize(`/${target}`)}`);
  return resolved.startsWith(base) ? resolved : null;
}

/* ─────────────────────────────────  Sessões  ────────────────────────────────── */

async function requireAuth(req, res) {
  const token = parseCookies(req)[SESSION_COOKIE];
  const session = await auth.resolve(token);
  if (!session) {
    sendJson(res, 401, { error: 'Sessão expirada. Volte a entrar.' });
    return null;
  }
  // CSRF: pedidos que alteram estado têm de vir de código nosso (fetch),
  // e um <form> de outro site não consegue definir este cabeçalho.
  if (req.method !== 'GET' && req.headers['x-requested-with'] !== '1000viagens') {
    sendJson(res, 403, { error: 'Pedido inválido.' });
    return null;
  }
  return { session, token };
}

/* ──────────────────────────────────  Pedidos  ───────────────────────────────── */

function parseRange(url) {
  const now = new Date();
  const preset = url.searchParams.get('range') || '365';
  const to = url.searchParams.get('to') ? new Date(`${url.searchParams.get('to')}T23:59:59Z`) : now;
  let from;
  if (url.searchParams.get('from')) {
    from = new Date(`${url.searchParams.get('from')}T00:00:00Z`);
  } else if (preset === 'tudo') {
    from = new Date('2000-01-01T00:00:00Z');
  } else {
    const days = toInt(preset, { min: 1, max: 3650, fallback: 365 });
    from = new Date(to.getTime() - days * 86_400_000);
  }
  if (Number.isNaN(from.getTime())) from = new Date(to.getTime() - 365 * 86_400_000);
  if (Number.isNaN(to.getTime())) return { from, to: now };
  return { from, to };
}

function filterLeads(items, url) {
  const { from, to } = parseRange(url);
  const status = url.searchParams.get('status') || '';
  const type = url.searchParams.get('type') || '';
  const query = foldKey(url.searchParams.get('q') || '');

  const filtered = items.filter((lead) => {
    const created = new Date(lead.createdAt);
    if (created < from || created > to) return false;
    if (status && lead.status !== status) return false;
    if (type && lead.trip.type !== type) return false;
    if (query) {
      const haystack = foldKey(
        [lead.id, lead.contact.name, lead.contact.email, lead.contact.phone, lead.trip.destination].join(' '),
      );
      if (!haystack.includes(query)) return false;
    }
    return true;
  });
  return { filtered, from, to };
}

async function createLead(req, res, url) {
  const ip = clientIp(req);
  if (!leadLimiter(ip).allowed) {
    return sendJson(res, 429, { error: 'Recebemos vários pedidos deste dispositivo. Tente de novo dentro de alguns minutos.' });
  }
  const payload = await readJsonBody(req, { limit: 256 * 1024 });

  // Armadilhas anti-robô: campo escondido preenchido ou formulário submetido
  // em menos de 3 segundos.
  if (cleanText(payload.website, 50)) return sendJson(res, 200, { ok: true, id: 'ignorado' });
  if (typeof payload.elapsedMs === 'number' && payload.elapsedMs < 3000) {
    return sendJson(res, 400, { error: 'Formulário submetido demasiado depressa.' });
  }

  const store = await leadsStore.read();
  const result = normalizeLead(payload, { sequence: (store.sequence || 0) + 1 });
  if (!result.ok) return sendJson(res, 422, { error: 'Faltam dados obrigatórios.', errors: result.errors });

  const lead = result.lead;
  await leadsStore.update((data) => {
    data.sequence = (data.sequence || 0) + 1;
    lead.id = lead.id.replace(/\d{4}$/, String(data.sequence).padStart(4, '0'));
    data.items.push(lead);
  });

  notifyNewLead(lead).catch((err) => console.warn('[aviso] falhou o envio da notificação:', err.message));
  return sendJson(res, 201, { ok: true, id: lead.id, message: 'Pedido registado.' });
}

/** Avisos: webhook (Zapier/Make/n8n) + e-mail, ambos opcionais. */
async function notifyNewLead(lead) {
  const settings = await getSettings();
  const webhook = settings.integrations.webhookUrl || process.env.LEADS_WEBHOOK_URL || '';
  if (webhook) {
    try {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 8000);
      await fetch(webhook, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ source: '1000viagens', lead }),
        signal: controller.signal,
      });
      clearTimeout(timer);
    } catch (err) {
      console.warn('[webhook] não foi possível entregar o pedido:', err.message);
    }
  }

  const to = settings.integrations.notificationEmail;
  if (to && smtpConfigured()) {
    const destino = lead.trip.undecided ? 'Ainda sem destino' : lead.trip.destination;
    await sendMail({
      to,
      subject: `Novo pedido ${lead.id} — ${destino}`,
      text: [
        `Novo pedido de orçamento em 1000viagens.`,
        ``,
        `Referência: ${lead.id}`,
        `Nome: ${lead.contact.name}`,
        `E-mail: ${lead.contact.email}`,
        `Telefone: ${lead.contact.phone || '—'}`,
        `Destino: ${destino}`,
        `Viajantes: ${lead.party.adults} adulto(s), ${lead.party.children} criança(s)`,
        `Valor estimado: ${lead.estimatedValue ? `${lead.estimatedValue} €` : '—'}`,
        ``,
        `Abra o backoffice para ver o pedido completo.`,
      ].join('\n'),
    });
  }
}

/* ────────────────────────────────────  API  ─────────────────────────────────── */

async function handleApi(req, res, url) {
  const { pathname } = url;

  /* — público — */
  if (pathname === '/api/public/config' && req.method === 'GET') {
    const settings = await getSettings();
    return sendJson(res, 200, { settings: publicSettings(settings), catalog: PUBLIC_CATALOG }, {
      'Cache-Control': 'no-cache',
    });
  }

  if (pathname === '/api/leads' && req.method === 'POST') return createLead(req, res, url);

  /* — sessão — */
  if (pathname === '/api/admin/login' && req.method === 'POST') {
    const ip = clientIp(req);
    if (!loginLimiter(ip).allowed) {
      return sendJson(res, 429, { error: 'Demasiadas tentativas. Aguarde 10 minutos.' });
    }
    const body = await readJsonBody(req, { limit: 4096 });
    const session = await auth.login(body.password, { ip, agent: req.headers['user-agent'] });
    if (!session) return sendJson(res, 401, { error: 'Password incorreta.' });
    const status = await auth.status();
    return sendJson(res, 200, { ok: true, mustChangePassword: status.mustChangePassword }, {
      'Set-Cookie': serializeCookie(SESSION_COOKIE, session.token, {
        maxAge: 12 * 60 * 60,
        secure: url.protocol === 'https:' || req.headers['x-forwarded-proto'] === 'https',
      }),
    });
  }

  if (pathname === '/api/admin/logout' && req.method === 'POST') {
    await auth.logout(parseCookies(req)[SESSION_COOKIE]);
    return sendJson(res, 200, { ok: true }, {
      'Set-Cookie': serializeCookie(SESSION_COOKIE, '', { maxAge: 0 }),
    });
  }

  if (pathname === '/api/admin/session' && req.method === 'GET') {
    const token = parseCookies(req)[SESSION_COOKIE];
    const session = await auth.resolve(token);
    if (!session) return sendJson(res, 200, { authenticated: false });
    const status = await auth.status();
    return sendJson(res, 200, {
      authenticated: true,
      mustChangePassword: status.mustChangePassword,
      lastLoginAt: status.lastLoginAt,
      smtpConfigured: smtpConfigured(),
      expiresAt: new Date(session.expiresAt).toISOString(),
    });
  }

  /* — a partir daqui, só autenticado — */
  if (pathname.startsWith('/api/admin/')) {
    const ctx = await requireAuth(req, res);
    if (!ctx) return true;

    if (pathname === '/api/admin/settings' && req.method === 'GET') {
      const settings = await getSettings();
      return sendJson(res, 200, {
        settings: adminSettings(settings, { reveal: url.searchParams.get('reveal') === '1' }),
        catalog: PUBLIC_CATALOG,
      });
    }

    if (pathname === '/api/admin/settings' && req.method === 'PUT') {
      const patch = await readJsonBody(req, { limit: 1024 * 1024 });
      const current = await getSettings();
      // O código do TravelPartner chega mascarado quando não foi alterado —
      // nesse caso mantém-se o valor guardado.
      const incoming = patch?.integrations?.travelPartner?.authorizationCode;
      if (typeof incoming === 'string' && incoming.includes('•')) {
        patch.integrations.travelPartner.authorizationCode = current.integrations.travelPartner.authorizationCode;
      } else if (typeof incoming === 'string') {
        patch.integrations.travelPartner.updatedAt = new Date().toISOString();
      }
      const saved = await saveSettings(patch);
      return sendJson(res, 200, { ok: true, settings: adminSettings(saved) });
    }

    if (pathname === '/api/admin/password' && req.method === 'POST') {
      const body = await readJsonBody(req, { limit: 4096 });
      const result = await auth.changePassword(body.currentPassword, body.newPassword);
      if (!result.ok) return sendJson(res, 400, { error: result.error });
      return sendJson(res, 200, { ok: true }, {
        'Set-Cookie': serializeCookie(SESSION_COOKIE, '', { maxAge: 0 }),
      });
    }

    if (pathname === '/api/admin/upload' && req.method === 'POST') {
      const body = await readJsonBody(req, { limit: 6 * 1024 * 1024 });
      const match = /^data:(image\/(png|jpeg|jpg|webp|gif|svg\+xml|x-icon));base64,([A-Za-z0-9+/=]+)$/.exec(
        String(body.dataUrl || ''),
      );
      if (!match) return sendJson(res, 400, { error: 'Imagem inválida. Use PNG, JPG, WEBP ou SVG.' });
      const buffer = Buffer.from(match[3], 'base64');
      if (buffer.length > 4 * 1024 * 1024) return sendJson(res, 413, { error: 'A imagem não pode exceder 4 MB.' });
      const ext = { 'image/png': '.png', 'image/jpeg': '.jpg', 'image/jpg': '.jpg', 'image/webp': '.webp', 'image/gif': '.gif', 'image/svg+xml': '.svg', 'image/x-icon': '.ico' }[match[1]];
      const kind = (cleanText(body.kind, 24) || 'ficheiro').replace(/[^a-z0-9-]/gi, '');
      const name = `${kind}-${Date.now().toString(36)}-${crypto.randomBytes(4).toString('hex')}${ext}`;
      await fsp.writeFile(path.join(UPLOADS_DIR, name), buffer);
      return sendJson(res, 201, { ok: true, url: `/uploads/${name}`, bytes: buffer.length });
    }

    if (pathname === '/api/admin/leads' && req.method === 'GET') {
      const store = await leadsStore.read();
      const { filtered } = filterLeads(store.items, url);
      const sorted = filtered.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
      const limit = toInt(url.searchParams.get('limit'), { min: 1, max: 500, fallback: 100 });
      const offset = toInt(url.searchParams.get('offset'), { min: 0, max: 100_000, fallback: 0 });
      return sendJson(res, 200, {
        total: sorted.length,
        items: sorted.slice(offset, offset + limit),
      });
    }

    const leadMatch = /^\/api\/admin\/leads\/([\w-]+)$/.exec(pathname);
    if (leadMatch) {
      const id = leadMatch[1];
      if (req.method === 'GET') {
        const store = await leadsStore.read();
        const lead = store.items.find((l) => l.id === id);
        if (!lead) return sendJson(res, 404, { error: 'Pedido não encontrado.' });
        return sendJson(res, 200, { lead });
      }
      if (req.method === 'PATCH') {
        const body = await readJsonBody(req, { limit: 64 * 1024 });
        const updated = await leadsStore.update((data) => {
          const lead = data.items.find((l) => l.id === id);
          if (!lead) return null;
          lead.internal = lead.internal || { history: [] };
          if (body.status) {
            const status = validId('statuses', body.status, lead.status);
            if (status !== lead.status) {
              lead.status = status;
              lead.internal.history = [
                ...(lead.internal.history || []),
                { at: new Date().toISOString(), status, note: cleanText(body.historyNote, 200) },
              ].slice(-40);
            }
          }
          if (body.notes !== undefined) lead.internal.notes = cleanMultiline(body.notes, 4000);
          if (body.owner !== undefined) lead.internal.owner = cleanText(body.owner, 80);
          if (body.quotedValue !== undefined) {
            lead.internal.quotedValue = toNumber(body.quotedValue, { min: 0, max: 1_000_000, fallback: null });
          }
          lead.estimatedValue = estimateValue(lead);
          lead.updatedAt = new Date().toISOString();
          return lead;
        });
        if (!updated) return sendJson(res, 404, { error: 'Pedido não encontrado.' });
        return sendJson(res, 200, { ok: true, lead: updated });
      }
      if (req.method === 'DELETE') {
        const removed = await leadsStore.update((data) => {
          const index = data.items.findIndex((l) => l.id === id);
          if (index < 0) return false;
          data.items.splice(index, 1);
          return true;
        });
        if (!removed) return sendJson(res, 404, { error: 'Pedido não encontrado.' });
        return sendJson(res, 200, { ok: true });
      }
    }

    if (pathname === '/api/admin/stats' && req.method === 'GET') {
      const store = await leadsStore.read();
      const { filtered, from, to } = filterLeads(store.items, url);
      return sendJson(res, 200, { stats: computeStats(filtered, { from, to }) });
    }

    if (pathname === '/api/admin/export.csv' && req.method === 'GET') {
      const store = await leadsStore.read();
      const { filtered } = filterLeads(store.items, url);
      const csv = leadsToCsv(filtered.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt)));
      return sendText(res, 200, csv, {
        'Content-Type': 'text/csv; charset=utf-8',
        'Content-Disposition': `attachment; filename="pedidos-1000viagens-${new Date().toISOString().slice(0, 10)}.csv"`,
      });
    }

    return sendJson(res, 404, { error: 'Rota desconhecida.' });
  }

  return false;
}

/* ───────────────────────────────────  Router  ───────────────────────────────── */

const PAGES = {
  '/': 'index.html',
  '/admin': 'admin.html',
  '/backoffice': 'admin.html',
  '/privacidade': 'privacidade.html',
  '/politica-de-privacidade': 'privacidade.html',
};

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  try {
    if (url.pathname.startsWith('/api/')) {
      const handled = await handleApi(req, res, url);
      if (handled === false) sendJson(res, 404, { error: 'Rota desconhecida.' });
      return;
    }

    if (url.pathname.startsWith('/uploads/')) {
      const file = safeJoin(UPLOADS_DIR, url.pathname.slice('/uploads/'.length));
      if (file && (await serveFile(res, file, { immutable: true, sandbox: true, req }))) return;
      return sendText(res, 404, 'Ficheiro não encontrado');
    }

    const page = PAGES[url.pathname.replace(/\/+$/, '') || '/'];
    if (page) {
      const served = await serveFile(res, path.join(PUBLIC_DIR, page), { req });
      if (served) return;
    }

    const asset = safeJoin(PUBLIC_DIR, url.pathname);
    if (asset) {
      if (await serveFile(res, asset, { req })) return;
      // Pastas: /viagens/maldivas/ → /viagens/maldivas/index.html
      if (await serveFile(res, path.join(asset, 'index.html'), { req })) return;
    }

    // 404 com a cara do site
    const notFound = await serveFile(res, path.join(PUBLIC_DIR, '404.html'));
    if (!notFound) sendText(res, 404, 'Página não encontrada');
  } catch (err) {
    const status = err.status || 500;
    if (status >= 500) console.error('[erro]', err);
    if (!res.headersSent) sendJson(res, status, { error: err.message || 'Erro interno.' });
    else res.end();
  }
});

const bootstrapResult = await auth.bootstrap(process.env.ADMIN_PASSWORD);
await getSettings();

server.listen(PORT, () => {
  const line = '─'.repeat(58);
  console.log(`\n${line}`);
  console.log('  1000viagens está a correr');
  console.log(`  Site      → http://localhost:${PORT}/`);
  console.log(`  Backoffice→ http://localhost:${PORT}/admin`);
  console.log(`  Dados     → ${DATA_DIR}`);
  if (bootstrapResult?.created) {
    console.log(
      bootstrapResult.usedFallback
        ? '  ⚠ Password inicial: 1000viagens  (mude-a em Backoffice → Segurança)'
        : '  ✓ Password definida a partir de ADMIN_PASSWORD',
    );
  }
  if (!smtpConfigured()) console.log('  ℹ Envio de e-mail desligado (configure SMTP_* para receber avisos)');
  console.log(`${line}\n`);
});
