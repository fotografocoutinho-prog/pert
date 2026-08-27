/**
 * Testes do pacote PHP (o que vai para o cPanel).
 *
 *   npm run test:cpanel
 *
 * Copia dist/cpanel para uma pasta temporária, arranca o servidor embutido do
 * PHP e verifica o mesmo que os testes da versão em Node: submissão de
 * pedidos, validação, autenticação, CSRF, mascaramento do código TravelPartner,
 * estatísticas, CSV e renderização em servidor.
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PORT = 3800 + Math.floor(Math.random() * 200);
const BASE = `http://127.0.0.1:${PORT}`;
const PASSWORD = 'password-de-teste-123';

let passou = 0;
let falhou = 0;
let cookie = '';

const check = (nome, condicao, detalhe = '') => {
  if (condicao) { passou += 1; console.log(`  ✓ ${nome}`); }
  else { falhou += 1; console.log(`  ✗ ${nome}${detalhe ? ` — ${detalhe}` : ''}`); }
};

const pedir = async (caminho, { method = 'GET', body, csrf = true, raw = false } = {}) => {
  const resposta = await fetch(`${BASE}${caminho}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      ...(cookie ? { Cookie: cookie } : {}),
      ...(csrf ? { 'X-Requested-With': '1000viagens' } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const setCookie = resposta.headers.get('set-cookie');
  if (setCookie) cookie = setCookie.split(';')[0];
  return { status: resposta.status, payload: raw ? await resposta.text() : await resposta.json().catch(() => ({})) };
};

const pedidoValido = (extra = {}) => ({
  trip: { type: 'praia', destination: 'Maldivas', nights: 7, startDate: '2027-05-10', flexible: true },
  party: { adults: 2, children: 1, childrenAges: [6], ageRange: '36-50' },
  budget: { range: '1500-2500', includes: ['voos', 'hotel'] },
  prefs: { hotelCategory: '5', board: 'tudo-incluido', interests: ['praia'], notes: 'Aniversário' },
  contact: { name: 'Cliente Teste', email: 'cliente@exemplo.pt', phone: '912345678', channel: 'email', source: 'google' },
  consent: { rgpd: true, marketing: false },
  elapsedMs: 30000,
  ...extra,
});

/* Preparar uma cópia limpa do pacote */
const TEMP = await fs.mkdtemp(path.join(os.tmpdir(), 'mv-php-'));
await fs.cp(path.join(ROOT, 'dist/cpanel'), TEMP, { recursive: true });
await fs.rm(path.join(TEMP, 'dados'), { recursive: true, force: true });
await fs.mkdir(path.join(TEMP, 'dados'), { recursive: true });

const servidor = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', TEMP, path.join(ROOT, 'scripts/router-local.php')], {
  env: { ...process.env, ADMIN_PASSWORD: PASSWORD },
  stdio: ['ignore', 'ignore', 'pipe'],
});
const registos = [];
servidor.stderr.on('data', (pedaco) => registos.push(String(pedaco)));

const parar = async () => {
  servidor.kill();
  await fs.rm(TEMP, { recursive: true, force: true }).catch(() => {});
};

for (let tentativa = 0; tentativa < 60; tentativa += 1) {
  try { await fetch(`${BASE}/api/public/config`); break; }
  catch { await new Promise((r) => setTimeout(r, 120)); }
}

console.log('\n1000viagens — testes do pacote PHP (cPanel)\n');

try {
  console.log('Site público');
  {
    const { status, payload } = await pedir('/api/public/config');
    check('devolve definições e catálogo', status === 200 && payload.catalog?.tripTypes?.length > 0);
    check('não expõe segredos ao site público', !JSON.stringify(payload).includes('authorizationCode'));
  }
  {
    const { status, payload } = await pedir('/', { raw: true });
    check('a página inicial responde', status === 200);
    check('o conteúdo vem já em HTML (bom para o Google)',
      payload.includes('destination__name') && payload.includes('faq__item') && payload.includes('testimonial__text'));
    check('inclui dados estruturados de agência', payload.includes('"@type":"TravelAgency"'));
    check('inclui perguntas frequentes estruturadas', payload.includes('"@type":"FAQPage"'));
    check('as fichas de destino são ligações que o Google segue', payload.includes('href="/viagens/maldivas/"'));
  }
  {
    const { status, payload } = await pedir('/viagens/maldivas/', { raw: true });
    check('a página de destino responde', status === 200);
    check('tem título próprio orientado a pesquisa', /<title>Viagens para as Maldivas[^<]*<\/title>/.test(payload));
    check('tem migalhas e perguntas estruturadas',
      payload.includes('BreadcrumbList') && payload.includes('FAQPage'));
  }
  {
    const { status, payload } = await pedir('/sitemap.xml', { raw: true });
    check('o mapa do site inclui as páginas de destino',
      status === 200 && payload.includes('/viagens/maldivas/') && payload.includes('/viagens/'));
  }

  console.log('\nPedidos de orçamento');
  {
    const { status, payload } = await pedir('/api/leads', { method: 'POST', body: pedidoValido() });
    check('aceita um pedido válido', status === 201 && /^1V-\d{4}-0001$/.test(payload.id), payload.error);
  }
  {
    const mau = pedidoValido({ contact: { name: '', email: 'nao-e-email', channel: 'email' } });
    const { status, payload } = await pedir('/api/leads', { method: 'POST', body: mau });
    check('recusa dados inválidos', status === 422 && payload.errors?.['contact.email']);
  }
  {
    const { status } = await pedir('/api/leads', { method: 'POST', body: pedidoValido({ consent: { rgpd: false } }) });
    check('exige o consentimento RGPD', status === 422);
  }
  {
    const { payload } = await pedir('/api/leads', { method: 'POST', body: pedidoValido({ website: 'robô' }) });
    check('ignora submissões de robôs', payload.id === 'ignorado');
  }
  {
    const { status } = await pedir('/api/leads', { method: 'POST', body: pedidoValido({ elapsedMs: 500 }) });
    check('recusa submissões instantâneas', status === 400);
  }

  console.log('\nBackoffice');
  {
    const { status } = await pedir('/api/admin/leads');
    check('bloqueia o acesso sem sessão', status === 401);
  }
  {
    const { status } = await pedir('/api/admin/login', { method: 'POST', body: { password: 'errada' } });
    check('recusa password errada', status === 401);
  }
  {
    const { status, payload } = await pedir('/api/admin/login', { method: 'POST', body: { password: PASSWORD } });
    check('aceita a password correta', status === 200 && payload.ok, payload.error);
  }
  {
    const { status, payload } = await pedir('/api/admin/leads');
    check('lista os pedidos autenticado', status === 200 && payload.total === 1);
  }
  {
    const { status } = await pedir('/api/admin/settings', { method: 'PUT', body: { company: { city: 'Porto' } }, csrf: false });
    check('rejeita escrita sem cabeçalho anti-CSRF', status === 403);
  }

  console.log('\nDefinições e TravelPartner');
  {
    await pedir('/api/admin/settings', {
      method: 'PUT',
      body: { company: { city: 'Braga' }, brand: { siteUrl: 'https://exemplo.pt' },
        integrations: { travelPartner: { authorizationCode: 'TP-SEGREDO-4242' } } },
    });
    const { payload } = await pedir('/api/admin/settings');
    const tp = payload.settings.integrations.travelPartner;
    check('guarda as definições', payload.settings.company.city === 'Braga');
    check('mascara o código de autorização', tp.authorizationCode.includes('•') && tp.authorizationCode.endsWith('4242'));
    check('assinala que existe código guardado', tp.hasAuthorizationCode === true);
  }
  {
    const { payload } = await pedir('/api/admin/settings?reveal=1');
    check('revela o código quando pedido', payload.settings.integrations.travelPartner.authorizationCode === 'TP-SEGREDO-4242');
  }
  {
    await pedir('/api/admin/settings', { method: 'PUT', body: { integrations: { travelPartner: { authorizationCode: '••••••••4242' } } } });
    const { payload } = await pedir('/api/admin/settings?reveal=1');
    check('o valor mascarado não apaga o código', payload.settings.integrations.travelPartner.authorizationCode === 'TP-SEGREDO-4242');
  }
  {
    const { payload } = await pedir('/api/public/config');
    check('o código nunca chega ao site público', !JSON.stringify(payload).includes('TP-SEGREDO'));
  }
  {
    const { payload } = await pedir('/', { raw: true });
    check('a página usa o domínio configurado no canonical', payload.includes('rel="canonical" href="https://exemplo.pt/"'));
  }

  console.log('\nGestão e estatísticas');
  {
    const { payload } = await pedir('/api/admin/leads/1V-2026-0001', {
      method: 'PATCH', body: { status: 'ganho', quotedValue: 5400, notes: 'Reserva feita.' },
    });
    check('atualiza estado, valor e notas', payload.lead?.status === 'ganho' && payload.lead?.internal.quotedValue === 5400);
    check('regista o histórico', (payload.lead?.internal.history || []).length >= 2);
  }
  {
    const { payload } = await pedir('/api/admin/stats?range=365');
    const s = payload.stats;
    check('conta os pedidos', s.totals.leads === 1);
    check('usa o valor orçamentado', s.totals.wonValue === 5400);
    check('agrupa por destino', s.destinations[0]?.label === 'Maldivas');
    check('preenche as faixas etárias', s.ages.find((a) => a.id === '36-50')?.value === 1);
    check('preenche a série temporal', s.trend.points.length > 0);
    check('calcula a taxa de conversão', s.totals.conversionRate === 1);
  }
  {
    const { status, payload } = await pedir('/api/admin/export.csv', { raw: true });
    check('exporta CSV', status === 200 && payload.includes('Referência;Data do pedido') && payload.includes('cliente@exemplo.pt'));
  }
  {
    const { status } = await pedir('/api/admin/leads/1V-2026-0001', { method: 'DELETE' });
    const { payload } = await pedir('/api/admin/leads');
    check('apaga um pedido', status === 200 && payload.total === 0);
  }
  {
    await pedir('/api/admin/logout', { method: 'POST' });
    const { status } = await pedir('/api/admin/leads');
    check('termina a sessão', status === 401);
  }
} catch (err) {
  falhou += 1;
  console.error('\nErro inesperado:', err);
} finally {
  await parar();
}

const avisos = registos.join('').split('\n').filter((l) => /Warning|Fatal|Notice|Deprecated/.test(l));
if (avisos.length) {
  console.log('\nAvisos do PHP:');
  avisos.slice(0, 8).forEach((linha) => console.log('  ' + linha.trim()));
  falhou += avisos.length;
}

console.log(`\n${falhou === 0 ? '✓ Tudo passou' : '✗ Falhas encontradas'}: ${passou} testes bem, ${falhou} mal.\n`);
process.exit(falhou === 0 ? 0 : 1);
