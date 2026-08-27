/**
 * Gera as páginas de destino e o mapa do site.
 *
 *   npm run build:seo
 *
 * Lê as definições (o que está no backoffice) e o texto editorial de
 * content/destinos.json, e escreve:
 *
 *   public/viagens/index.html            — índice de destinos
 *   public/viagens/<destino>/index.html  — uma página por destino
 *   public/sitemap.xml                   — mapa do site com todos os endereços
 *   public/robots.txt                    — com a indicação do mapa
 *
 * Estas páginas são HTML completo: o Google lê o conteúdo sem depender de
 * JavaScript, que é o que faz a diferença nas pesquisas por "viagens para X".
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { DEFAULT_SETTINGS, mergeSettings } from '../server/settings.js';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PUBLIC_DIR = path.join(ROOT, 'public');
const DATA_DIR = path.resolve(ROOT, process.env.DATA_DIR || 'data');

/* ── utilitários ────────────────────────────────────────────────────────── */

export const slugify = (text) =>
  String(text)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');

const esc = (text) =>
  String(text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const attr = (text) => esc(text).replace(/'/g, '&#39;');

/** Carrega as ilustrações SVG (o ficheiro do browser, avaliado aqui). */
async function loadArt() {
  const source = await fs.readFile(path.join(PUBLIC_DIR, 'assets/js/scenes.js'), 'utf8');
  const fakeWindow = {};
  new Function('window', source)(fakeWindow);
  return fakeWindow.MVArt;
}

async function loadSettings() {
  let stored = {};
  try {
    stored = JSON.parse(await fs.readFile(path.join(DATA_DIR, 'settings.json'), 'utf8'));
  } catch { /* ainda sem definições guardadas — usam-se as de origem */ }
  return mergeSettings(DEFAULT_SETTINGS, stored);
}

/* ── conteúdo por destino ───────────────────────────────────────────────── */

/** Texto genérico para destinos ainda sem página escrita à mão. */
function genericContent(dest) {
  const nights = dest.nights || 'viagem à medida';
  return {
    title: `Viagens para ${dest.name}`,
    metaDescription: `Viagens para ${dest.name}${dest.region ? ` (${dest.region})` : ''} com voos, alojamento e transferes tratados por nós. Orçamento à medida em 24 horas, grátis e sem compromisso.`,
    intro: `Quer viajar para ${dest.name}? Preparamos a viagem de porta a porta — voos, alojamento${dest.region ? ` em ${dest.region}` : ''}, transferes e seguro — a partir do que nos disser sobre datas, viajantes e orçamento. Em menos de 24 horas úteis recebe uma proposta com duas ou três alternativas para comparar.`,
    why: [
      'Proposta feita à medida, não um pacote fechado igual para todos.',
      'Comparação entre operadores, companhias aéreas e hotéis antes de lhe apresentarmos preços.',
      'Pagamento faseado até à data da viagem, com sinal reduzido na reserva.',
      'Assistência em português antes, durante e depois da viagem.',
    ],
    bestTime: `A melhor altura para viajar para ${dest.name} depende do clima, dos preços e do que quiser fazer. Diga-nos as datas em que pode viajar e dizemos-lhe o que esperar nessa época — e se compensa antecipar ou adiar uns dias.`,
    practical: {
      'Duração típica': nights,
      'Como se chega': 'Indicamos as melhores ligações a partir de Lisboa, Porto ou Faro no orçamento.',
      Documentos: 'Confirmamos consigo os documentos necessários antes da reserva.',
    },
    included: [
      'Voos com bagagem',
      'Alojamento escolhido pela localização e pelo tipo de viagem',
      'Transferes, quando fazem sentido',
      'Seguro de viagem',
      'Assistência em português durante toda a viagem',
    ],
    faq: [
      {
        q: `Quanto custa uma viagem para ${dest.name}?`,
        a: dest.from
          ? `Os nossos programas começam em cerca de ${dest.from} € por pessoa. O valor final depende das datas, do número de viajantes e do tipo de alojamento — peça orçamento e recebe valores reais para as suas datas.`
          : 'O valor depende das datas, do número de viajantes e do tipo de alojamento. Peça orçamento e recebe valores reais para as suas datas, sem compromisso.',
      },
      { q: 'Pedir orçamento tem algum custo?', a: 'Não. O pedido e a proposta são gratuitos e sem compromisso. Só paga se decidir avançar com a reserva.' },
      { q: 'Em quanto tempo recebo resposta?', a: 'Em menos de 24 horas úteis. Se a viagem for para muito breve, diga-o nas notas do pedido e damos prioridade.' },
    ],
  };
}

/* ── modelo da página ───────────────────────────────────────────────────── */

function pageShell({ settings, title, description, canonical, bodyClass = '', head = '', body, jsonLd }) {
  const { brand, company } = settings;
  const base = (brand.siteUrl || '').replace(/\/$/, '');
  const logo = brand.logoUrl
    ? `<img class="logo__img" src="${attr(brand.logoUrl)}" alt="${attr(brand.name)}">`
    : `<svg class="logo__mark" viewBox="0 0 48 48" fill="none" aria-hidden="true">
        <circle cx="24" cy="24" r="23" stroke="currentColor" stroke-opacity=".28" stroke-width="1.5"/>
        <path d="M24 5c6.5 5.2 9.8 11.6 9.8 19S30.5 37.8 24 43c-6.5-5.2-9.8-11.6-9.8-19S17.5 10.2 24 5Z" stroke="currentColor" stroke-opacity=".28" stroke-width="1.5"/>
        <path d="M5.5 24h37" stroke="currentColor" stroke-opacity=".28" stroke-width="1.5"/>
        <path d="M33.4 14.6 26.2 26.2 14.6 33.4l7.2-11.6 11.6-7.2Z" fill="#F07A3C"/>
        <circle cx="24" cy="24" r="2.4" fill="currentColor"/>
      </svg>
      <span class="logo__text">
        <span class="logo__name">1000<em>viagens</em></span>
        <span class="logo__tag">Travel Booking</span>
      </span>`;

  const socials = Object.values(company.socials || {}).filter(Boolean);
  const contactos = [
    company.phone ? `<li><a href="tel:${attr(company.phone.replace(/[^\d+]/g, ''))}">${esc(company.phone)}</a></li>` : '',
    company.email ? `<li><a href="mailto:${attr(company.email)}">${esc(company.email)}</a></li>` : '',
    company.city ? `<li>${esc([company.address, company.postalCode, company.city].filter(Boolean).join(', '))}</li>` : '',
    company.hours ? `<li>${esc(company.hours)}</li>` : '',
  ].filter(Boolean).join('\n          ');

  return `<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title)}</title>
<meta name="description" content="${attr(description)}">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
<meta name="theme-color" content="${attr(brand.primaryColor || '#0E4F6B')}">
<link rel="canonical" href="${attr(canonical)}">
<meta property="og:type" content="article">
<meta property="og:locale" content="pt_PT">
<meta property="og:site_name" content="${attr(brand.name)}">
<meta property="og:title" content="${attr(title)}">
<meta property="og:description" content="${attr(description)}">
<meta property="og:url" content="${attr(canonical)}">
<meta property="og:image" content="${attr(`${base}/assets/img/og.png`)}">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="${attr(brand.faviconUrl || '/assets/img/favicon.svg')}" type="image/svg+xml">
<link rel="manifest" href="/site.webmanifest">
<script>document.documentElement.classList.add('js');</script>
<link rel="stylesheet" href="/assets/css/site.css">
<link rel="stylesheet" href="/assets/css/destino.css">
${head}
<script type="application/ld+json">${JSON.stringify(jsonLd)}</script>
</head>
<body class="${bodyClass}">

<a class="sr-only" href="#pedir">Saltar para o pedido de orçamento</a>

<header class="header is-stuck">
  <div class="header__inner">
    <a class="logo" href="/">${logo}</a>
    <nav class="nav" aria-label="Navegação principal">
      <a href="/viagens/">Destinos</a>
      <a href="/#como-funciona">Como funciona</a>
      <a href="/#testemunhos">Testemunhos</a>
      <a href="/#faq">Perguntas</a>
    </nav>
    ${company.phone ? `<a class="header__phone" href="tel:${attr(company.phone.replace(/[^\d+]/g, ''))}"><span>${esc(company.phone)}</span></a>` : ''}
    <a class="btn btn--accent btn--sm" href="/#pedido">Pedir orçamento</a>
  </div>
</header>

<main>
${body}
</main>

<footer class="footer">
  <div class="container">
    <div class="footer__grid">
      <div>
        <span class="logo">${logo}</span>
        <p class="footer__about">${esc(brand.tagline || '')}</p>
      </div>
      <div>
        <h4>Destinos</h4>
        <ul id="footer-destinos"></ul>
      </div>
      <div>
        <h4>Contactos</h4>
        <ul>
          ${contactos || '<li>Peça orçamento pelo site</li>'}
        </ul>
      </div>
      <div>
        <h4>Informação legal</h4>
        <ul>
          <li>${esc(company.legalName || brand.name)}</li>
          ${company.nif ? `<li>NIF ${esc(company.nif)}</li>` : ''}
          ${company.rnavt ? `<li>RNAVT ${esc(company.rnavt)}</li>` : ''}
          <li><a href="/privacidade">Política de privacidade</a></li>
          <li><a href="https://www.livroreclamacoes.pt" target="_blank" rel="noopener">Livro de reclamações</a></li>
        </ul>
      </div>
    </div>
    <div class="footer__bottom">
      <span>© <span id="ano">${new Date().getFullYear()}</span> ${esc(brand.name)}. Todos os direitos reservados.</span>
      ${socials.length ? `<span>${socials.map((url) => `<a href="${attr(url)}" target="_blank" rel="noopener">${esc(new URL(url).hostname.replace('www.', ''))}</a>`).join(' · ')}</span>` : ''}
    </div>
  </div>
</footer>

<script>
  // Preços e destinos do rodapé sempre em dia com o backoffice
  (async function () {
    try {
      const { settings } = await (await fetch('/api/public/config')).json();
      const slug = (t) => String(t).normalize('NFD').replace(/[\\u0300-\\u036f]/g, '')
        .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-\$/g, '');
      const lista = document.getElementById('footer-destinos');
      if (lista) {
        lista.innerHTML = '';
        (settings.content.destinations || []).slice(0, 6).forEach((d) => {
          if (!d.name) return;
          const li = document.createElement('li');
          const a = document.createElement('a');
          a.href = '/viagens/' + slug(d.name) + '/';
          a.textContent = d.name;
          li.appendChild(a);
          lista.appendChild(li);
        });
      }
      document.querySelectorAll('[data-preco]').forEach((node) => {
        const dest = (settings.content.destinations || []).find((d) => slug(d.name) === node.dataset.preco);
        if (dest && dest.from) node.textContent = dest.from + ' €';
      });
    } catch (err) { /* offline: fica o que foi gerado */ }
  })();
</script>
</body>
</html>
`;
}

/* ── página de um destino ───────────────────────────────────────────────── */

function destinationPage({ settings, dest, content, slug, art, related }) {
  const base = (settings.brand.siteUrl || '').replace(/\/$/, '');
  const canonical = `${base}/viagens/${slug}/`;
  const titulo = content.title || `Viagens para ${dest.name}`;
  const tituloSeo = dest.from
    ? `${titulo} — pacotes desde ${dest.from} € | ${settings.brand.name}`
    : `${titulo} | ${settings.brand.name}`;

  const jsonLd = {
    '@context': 'https://schema.org',
    '@graph': [
      {
        '@type': 'BreadcrumbList',
        itemListElement: [
          { '@type': 'ListItem', position: 1, name: 'Início', item: `${base}/` },
          { '@type': 'ListItem', position: 2, name: 'Destinos', item: `${base}/viagens/` },
          { '@type': 'ListItem', position: 3, name: titulo, item: canonical },
        ],
      },
      {
        '@type': 'TouristTrip',
        name: titulo,
        description: content.metaDescription,
        url: canonical,
        touristType: dest.tag || 'Viagem à medida',
        provider: { '@type': 'TravelAgency', name: settings.brand.name, url: `${base}/` },
        ...(dest.from
          ? {
              offers: {
                '@type': 'Offer',
                price: String(dest.from).replace(/\./g, '').replace(',', '.'),
                priceCurrency: 'EUR',
                availability: 'https://schema.org/InStock',
                url: `${base}/?destino=${encodeURIComponent(dest.name)}#pedido`,
                description: `Preço por pessoa, desde. ${dest.nights || ''}`.trim(),
              },
            }
          : {}),
      },
      {
        '@type': 'FAQPage',
        mainEntity: content.faq.map((item) => ({
          '@type': 'Question',
          name: item.q,
          acceptedAnswer: { '@type': 'Answer', text: item.a },
        })),
      },
    ],
  };

  const arte = dest.imageUrl
    ? `<img src="${attr(dest.imageUrl)}" alt="${attr(dest.name)}" loading="eager" width="1200" height="900">`
    : art.scene(dest.art || 'tropical', `hero${slug.replace(/-/g, '')}`);

  const body = `
<article class="destino">
  <div class="destino__hero">
    <div class="destino__art" aria-hidden="true">${arte}</div>
    <div class="destino__veil"></div>
    <div class="container destino__hero-inner">
      <nav class="crumbs" aria-label="Caminho">
        <a href="/">Início</a> <span aria-hidden="true">›</span>
        <a href="/viagens/">Destinos</a> <span aria-hidden="true">›</span>
        <span aria-current="page">${esc(dest.name)}</span>
      </nav>
      ${dest.region ? `<p class="eyebrow hero__eyebrow">${esc(dest.region)}</p>` : ''}
      <h1>${esc(titulo)}</h1>
      <p class="destino__lede">${esc(content.intro)}</p>
      <div class="destino__facts">
        ${dest.from ? `<div class="destino__fact"><b><span data-preco="${attr(slug)}">${esc(dest.from)} €</span></b><span>por pessoa, desde</span></div>` : ''}
        ${dest.nights ? `<div class="destino__fact"><b>${esc(String(dest.nights).split('·')[0].trim())}</b><span>${esc(String(dest.nights).split('·').slice(1).join('·').trim() || 'programa sugerido')}</span></div>` : ''}
        <div class="destino__fact"><b>24 h</b><span>resposta ao pedido</span></div>
      </div>
      <p class="destino__cta">
        <a class="btn btn--accent" href="/?destino=${encodeURIComponent(dest.name)}#pedido">Pedir orçamento para ${esc(dest.name)}</a>
      </p>
    </div>
  </div>

  <div class="container destino__body">
    <div class="destino__main">
      <section>
        <h2>Porquê ${esc(dest.name)}</h2>
        <ul class="ticks">
          ${content.why.map((item) => `<li>${esc(item)}</li>`).join('\n          ')}
        </ul>
      </section>

      <section>
        <h2>Melhor altura para viajar</h2>
        <p>${esc(content.bestTime)}</p>
      </section>

      <section>
        <h2>O que incluímos no orçamento</h2>
        <ul class="ticks">
          ${content.included.map((item) => `<li>${esc(item)}</li>`).join('\n          ')}
        </ul>
        <p class="destino__note">Não precisa de tudo? Diga-nos o que quer de fora — o orçamento é feito à medida do que pedir.</p>
      </section>

      <section>
        <h2>Perguntas frequentes sobre ${esc(dest.name)}</h2>
        <div class="faq">
          ${content.faq.map((item) => `<details class="faq__item">
            <summary>${esc(item.q)}</summary>
            <p>${esc(item.a)}</p>
          </details>`).join('\n          ')}
        </div>
      </section>
    </div>

    <aside class="destino__aside">
      <div class="destino__card" id="pedir">
        <h3>Peça o orçamento</h3>
        <p>Três minutos a preencher, resposta em 24 horas úteis. Grátis e sem compromisso.</p>
        <a class="btn btn--accent btn--block" href="/?destino=${encodeURIComponent(dest.name)}#pedido">Começar o pedido</a>
        ${settings.company.phone ? `<p class="destino__phone">ou ligue <a href="tel:${attr(settings.company.phone.replace(/[^\d+]/g, ''))}">${esc(settings.company.phone)}</a></p>` : ''}
      </div>

      <div class="destino__card">
        <h3>Informação prática</h3>
        <dl class="destino__facts-list">
          ${Object.entries(content.practical).map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('\n          ')}
        </dl>
      </div>
    </aside>
  </div>

  ${related.length ? `<div class="container">
    <section class="destino__related">
      <h2>Outros destinos que costumam interessar</h2>
      <div class="destinations">
        ${related.map((other, index) => `<a class="destination" href="/viagens/${attr(other.slug)}/">
          <div class="destination__art">${other.imageUrl ? `<img src="${attr(other.imageUrl)}" alt="${attr(other.name)}" loading="lazy">` : art.scene(other.art || 'tropical', `rel${index}${slug.replace(/-/g, '')}`)}</div>
          <div class="destination__body">
            <span class="destination__region">${esc(other.region || '')}</span>
            <h3 class="destination__name">${esc(other.name)}</h3>
            <p class="destination__nights">${esc(other.nights || '')}</p>
            <div class="destination__foot">
              <span class="destination__price">${other.from ? `<small>desde</small><b>${esc(other.from)} €</b>` : '<b>sob consulta</b>'}</span>
              <span class="destination__cta">Ver destino</span>
            </div>
          </div>
        </a>`).join('\n        ')}
      </div>
    </section>
  </div>` : ''}
</article>
`;

  return pageShell({
    settings,
    title: tituloSeo,
    description: content.metaDescription,
    canonical,
    bodyClass: 'page-destino',
    body,
    jsonLd,
  });
}

/* ── índice de destinos ─────────────────────────────────────────────────── */

function indexPage({ settings, destinos, art }) {
  const base = (settings.brand.siteUrl || '').replace(/\/$/, '');
  const canonical = `${base}/viagens/`;
  const jsonLd = {
    '@context': 'https://schema.org',
    '@graph': [
      {
        '@type': 'BreadcrumbList',
        itemListElement: [
          { '@type': 'ListItem', position: 1, name: 'Início', item: `${base}/` },
          { '@type': 'ListItem', position: 2, name: 'Destinos', item: canonical },
        ],
      },
      {
        '@type': 'ItemList',
        name: 'Destinos de viagem',
        itemListElement: destinos.map((dest, index) => ({
          '@type': 'ListItem',
          position: index + 1,
          name: `Viagens para ${dest.name}`,
          url: `${base}/viagens/${dest.slug}/`,
        })),
      },
    ],
  };

  const body = `
<div class="container destino__index">
  <nav class="crumbs crumbs--dark" aria-label="Caminho">
    <a href="/">Início</a> <span aria-hidden="true">›</span>
    <span aria-current="page">Destinos</span>
  </nav>
  <h1>Destinos de viagem</h1>
  <p class="lede">
    Praia, cidade, cruzeiro, neve ou safari: estes são os destinos que mais nos pedem.
    Não encontra o seu? Organizamos viagens para todo o mundo — diga-nos onde quer ir.
  </p>

  <div class="destinations">
    ${destinos.map((dest, index) => `<a class="destination" href="/viagens/${attr(dest.slug)}/">
      <div class="destination__art">
        ${dest.imageUrl ? `<img src="${attr(dest.imageUrl)}" alt="${attr(dest.name)}" loading="lazy">` : art.scene(dest.art || 'tropical', `idx${index}`)}
        ${dest.tag ? `<span class="destination__tag">${esc(dest.tag)}</span>` : ''}
      </div>
      <div class="destination__body">
        <span class="destination__region">${esc(dest.region || '')}</span>
        <h2 class="destination__name">${esc(dest.name)}</h2>
        <p class="destination__nights">${esc(dest.nights || '')}</p>
        <div class="destination__foot">
          <span class="destination__price">${dest.from ? `<small>desde</small><b><span data-preco="${attr(dest.slug)}">${esc(dest.from)} €</span></b>` : '<b>sob consulta</b>'}</span>
          <span class="destination__cta">Ver destino</span>
        </div>
      </div>
    </a>`).join('\n    ')}
  </div>

  <div class="destino__band">
    <h2>Não está aqui o destino que procura?</h2>
    <p>Organizamos viagens para todo o mundo. Escreva-nos o destino no pedido de orçamento e tratamos do resto.</p>
    <a class="btn btn--accent" href="/#pedido">Pedir orçamento</a>
  </div>
</div>
`;

  return pageShell({
    settings,
    title: `Destinos de viagem — pacotes e preços | ${settings.brand.name}`,
    description: 'Praia, city break, cruzeiro, neve ou safari: veja os destinos mais pedidos, com preços desde e o que está incluído. Orçamento à medida em 24 horas.',
    canonical,
    bodyClass: 'page-destinos',
    body,
    jsonLd,
  });
}

/* ── execução ───────────────────────────────────────────────────────────── */

export async function build({ quiet = false } = {}) {
  const settings = await loadSettings();
  const art = await loadArt();
  const editorial = JSON.parse(await fs.readFile(path.join(ROOT, 'content/destinos.json'), 'utf8'));
  const base = (settings.brand.siteUrl || '').replace(/\/$/, '');

  const destinos = (settings.content.destinations || [])
    .filter((dest) => dest && dest.name)
    .map((dest) => ({ ...dest, slug: slugify(dest.name) }));

  const escritos = [];
  for (const dest of destinos) {
    const content = { ...genericContent(dest), ...(editorial[dest.slug] || {}) };
    const related = destinos.filter((other) => other.slug !== dest.slug).slice(0, 3);
    const html = destinationPage({ settings, dest, content, slug: dest.slug, art, related });
    const dir = path.join(PUBLIC_DIR, 'viagens', dest.slug);
    await fs.mkdir(dir, { recursive: true });
    await fs.writeFile(path.join(dir, 'index.html'), html);
    escritos.push({ url: `${base}/viagens/${dest.slug}/`, priority: '0.8', changefreq: 'monthly' });
    if (!quiet) console.log(`  · /viagens/${dest.slug}/`);
  }

  await fs.mkdir(path.join(PUBLIC_DIR, 'viagens'), { recursive: true });
  await fs.writeFile(path.join(PUBLIC_DIR, 'viagens/index.html'), indexPage({ settings, destinos, art }));

  const hoje = new Date().toISOString().slice(0, 10);
  const urls = [
    { url: `${base}/`, priority: '1.0', changefreq: 'weekly' },
    { url: `${base}/viagens/`, priority: '0.9', changefreq: 'weekly' },
    ...escritos,
    { url: `${base}/privacidade`, priority: '0.2', changefreq: 'yearly' },
  ];

  await fs.writeFile(path.join(PUBLIC_DIR, 'sitemap.xml'), `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls.map(({ url, priority, changefreq }) => `  <url>
    <loc>${url}</loc>
    <lastmod>${hoje}</lastmod>
    <changefreq>${changefreq}</changefreq>
    <priority>${priority}</priority>
  </url>`).join('\n')}
</urlset>
`);

  await fs.writeFile(path.join(PUBLIC_DIR, 'robots.txt'), `# 1000viagens
User-agent: *
Allow: /
Disallow: /admin
Disallow: /backoffice
Disallow: /api/
Disallow: /uploads/

Sitemap: ${base}/sitemap.xml
`);

  if (!quiet) {
    console.log(`\n✓ ${destinos.length} páginas de destino + índice, mapa do site e robots.txt`);
    console.log(`  Domínio usado: ${base || '(não definido — preencha "Endereço do site" no backoffice)'}`);
  }
  return { destinos, urls };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  console.log('\nA gerar as páginas para pesquisa…\n');
  await build();
}
