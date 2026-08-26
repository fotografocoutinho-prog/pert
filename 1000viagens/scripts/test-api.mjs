/**
 * Testes de fumo da API — arrancam o servidor numa pasta temporária e
 * verificam o essencial: submissão de pedidos, validação, autenticação,
 * proteção CSRF, mascaramento do código TravelPartner, estatísticas e CSV.
 *
 *   npm test
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PORT = 3600 + Math.floor(Math.random() * 300);
const BASE = `http://127.0.0.1:${PORT}`;
const DATA_DIR = await fs.mkdtemp(path.join(os.tmpdir(), 'mv-teste-'));
const PASSWORD = 'password-de-teste-123';

let passed = 0;
let failed = 0;
let cookie = '';

const check = (name, condition, detail = '') => {
  if (condition) {
    passed += 1;
    console.log(`  ✓ ${name}`);
  } else {
    failed += 1;
    console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
  }
};

const request = async (path_, { method = 'GET', body, csrf = true, raw = false } = {}) => {
  const response = await fetch(`${BASE}${path_}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      ...(cookie ? { Cookie: cookie } : {}),
      ...(csrf ? { 'X-Requested-With': '1000viagens' } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const setCookie = response.headers.get('set-cookie');
  if (setCookie) cookie = setCookie.split(';')[0];
  const payload = raw ? await response.text() : await response.json().catch(() => ({}));
  return { status: response.status, payload };
};

const validLead = (overrides = {}) => ({
  trip: { type: 'praia', destination: 'Maldivas', nights: 7, startDate: '2027-05-10', flexible: true },
  party: { adults: 2, children: 1, childrenAges: [6], ageRange: '36-50' },
  budget: { range: '1500-2500', includes: ['voos', 'hotel'] },
  prefs: { hotelCategory: '5', board: 'tudo-incluido', interests: ['praia'], notes: 'Aniversário' },
  contact: { name: 'Cliente Teste', email: 'cliente@exemplo.pt', phone: '912345678', channel: 'email', source: 'google' },
  consent: { rgpd: true, marketing: false },
  elapsedMs: 30000,
  ...overrides,
});

const server = spawn(process.execPath, ['server/index.js'], {
  cwd: ROOT,
  env: { ...process.env, PORT: String(PORT), DATA_DIR, ADMIN_PASSWORD: PASSWORD, NODE_ENV: 'test' },
  stdio: ['ignore', 'pipe', 'pipe'],
});
server.stderr.on('data', (chunk) => process.stderr.write(`[servidor] ${chunk}`));

const stop = async () => {
  server.kill();
  await fs.rm(DATA_DIR, { recursive: true, force: true }).catch(() => {});
};

// Espera o servidor responder
for (let attempt = 0; attempt < 60; attempt += 1) {
  try {
    await fetch(`${BASE}/api/public/config`);
    break;
  } catch {
    await new Promise((resolve) => setTimeout(resolve, 120));
  }
}

console.log('\n1000viagens — testes da API\n');

try {
  console.log('Site público');
  {
    const { status, payload } = await request('/api/public/config');
    check('devolve as definições e o catálogo', status === 200 && payload.catalog?.tripTypes?.length > 0);
    check('o site público não recebe segredos', !JSON.stringify(payload).includes('authorizationCode'));
  }
  {
    const { status } = await request('/', { raw: true });
    check('a página inicial responde', status === 200);
  }
  {
    const { status } = await request('/admin', { raw: true });
    check('o backoffice responde', status === 200);
  }

  console.log('\nPedidos de orçamento');
  {
    const { status, payload } = await request('/api/leads', { method: 'POST', body: validLead() });
    check('aceita um pedido válido', status === 201 && /^1V-\d{4}-0001$/.test(payload.id), payload.error);
  }
  {
    const bad = validLead({ contact: { name: '', email: 'nao-e-email', channel: 'email' } });
    const { status, payload } = await request('/api/leads', { method: 'POST', body: bad });
    check('recusa dados inválidos', status === 422 && payload.errors['contact.email']);
  }
  {
    const { status, payload } = await request('/api/leads', {
      method: 'POST', body: validLead({ consent: { rgpd: false } }),
    });
    check('exige o consentimento RGPD', status === 422 && payload.errors['consent.rgpd']);
  }
  {
    const { payload } = await request('/api/leads', { method: 'POST', body: validLead({ website: 'robô' }) });
    check('ignora submissões de robôs (campo-armadilha)', payload.id === 'ignorado');
  }
  {
    const { status } = await request('/api/leads', { method: 'POST', body: validLead({ elapsedMs: 500 }) });
    check('recusa submissões instantâneas', status === 400);
  }

  console.log('\nAutenticação do backoffice');
  {
    const { status } = await request('/api/admin/leads');
    check('bloqueia o acesso sem sessão', status === 401);
  }
  {
    const { status } = await request('/api/admin/login', { method: 'POST', body: { password: 'errada' } });
    check('recusa password errada', status === 401);
  }
  {
    const { status, payload } = await request('/api/admin/login', { method: 'POST', body: { password: PASSWORD } });
    check('aceita a password correta', status === 200 && payload.ok);
  }
  {
    const { status, payload } = await request('/api/admin/leads');
    check('lista os pedidos autenticado', status === 200 && payload.total === 1);
  }
  {
    const { status } = await request('/api/admin/settings', {
      method: 'PUT', body: { company: { city: 'Porto' } }, csrf: false,
    });
    check('rejeita escrita sem cabeçalho anti-CSRF', status === 403);
  }

  console.log('\nDefinições e TravelPartner');
  {
    await request('/api/admin/settings', {
      method: 'PUT',
      body: { company: { city: 'Braga' }, integrations: { travelPartner: { authorizationCode: 'TP-SEGREDO-4242' } } },
    });
    const { payload } = await request('/api/admin/settings');
    const tp = payload.settings.integrations.travelPartner;
    check('guarda as definições', payload.settings.company.city === 'Braga');
    check('mascara o código de autorização', tp.authorizationCode.includes('•') && tp.authorizationCode.endsWith('4242'));
    check('assinala que existe código guardado', tp.hasAuthorizationCode === true);
  }
  {
    const { payload } = await request('/api/admin/settings?reveal=1');
    check('revela o código quando pedido explicitamente',
      payload.settings.integrations.travelPartner.authorizationCode === 'TP-SEGREDO-4242');
  }
  {
    // Gravar com o valor mascarado não pode destruir o código guardado
    await request('/api/admin/settings', {
      method: 'PUT', body: { integrations: { travelPartner: { authorizationCode: '••••••••4242' } } },
    });
    const { payload } = await request('/api/admin/settings?reveal=1');
    check('o valor mascarado não apaga o código guardado',
      payload.settings.integrations.travelPartner.authorizationCode === 'TP-SEGREDO-4242');
  }
  {
    const { payload } = await request('/api/public/config');
    check('o código nunca chega ao site público', !JSON.stringify(payload).includes('TP-SEGREDO'));
  }

  console.log('\nGestão de pedidos e estatísticas');
  {
    const { payload } = await request('/api/admin/leads/1V-2026-0001', {
      method: 'PATCH', body: { status: 'ganho', quotedValue: 5400, notes: 'Reserva feita.' },
    });
    check('atualiza estado, valor e notas',
      payload.lead.status === 'ganho' && payload.lead.internal.quotedValue === 5400);
    check('regista o histórico', payload.lead.internal.history.length >= 2);
  }
  {
    const { payload } = await request('/api/admin/stats?range=365');
    const stats = payload.stats;
    check('conta os pedidos', stats.totals.leads === 1);
    check('usa o valor orçamentado no total', stats.totals.wonValue === 5400);
    check('agrupa por destino', stats.destinations[0].label === 'Maldivas');
    check('preenche as faixas etárias', stats.ages.find((a) => a.id === '36-50').value === 1);
    check('preenche a série temporal', stats.trend.points.length > 0);
    check('calcula a taxa de conversão', stats.totals.conversionRate === 1);
  }
  {
    const { status, payload } = await request('/api/admin/export.csv', { raw: true });
    check('exporta CSV com cabeçalhos', status === 200 && payload.includes('Referência;Data do pedido'));
    check('o CSV inclui o pedido', payload.includes('cliente@exemplo.pt'));
  }
  {
    const { status } = await request('/api/admin/leads/1V-2026-0001', { method: 'DELETE' });
    const { payload } = await request('/api/admin/leads');
    check('apaga um pedido', status === 200 && payload.total === 0);
  }
  {
    await request('/api/admin/logout', { method: 'POST' });
    const { status } = await request('/api/admin/leads');
    check('termina a sessão', status === 401);
  }
} catch (err) {
  failed += 1;
  console.error('\nErro inesperado durante os testes:', err);
} finally {
  await stop();
}

console.log(`\n${failed === 0 ? '✓ Tudo passou' : '✗ Falhas encontradas'}: ${passed} testes bem, ${failed} mal.\n`);
process.exit(failed === 0 ? 0 : 1);
