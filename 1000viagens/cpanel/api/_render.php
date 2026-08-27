<?php
/**
 * Renderização do conteúdo em servidor.
 *
 * O ficheiro index.html tem marcadores <!--SSR:…--> nos sítios que o
 * JavaScript preenche no browser. Aqui preenchemo-los antes de enviar a
 * página, para o Google (e quem não corre JavaScript) receber tudo em HTML.
 */

declare(strict_types=1);
require_once __DIR__ . '/_nucleo.php';

function e($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cenas(): array
{
    static $cenas = null;
    if ($cenas === null) $cenas = ler_json(BASE_DIR . '/cenas.json');
    return $cenas;
}

function icones(): array
{
    static $icones = null;
    if ($icones === null) $icones = ler_json(BASE_DIR . '/icones.json');
    return $icones;
}

function cena(string $nome, string $uid): string
{
    $todas = cenas();
    $svg = $todas[$nome] ?? ($todas['tropical'] ?? '');
    return str_replace('__UID__', preg_replace('/\W/', '', $uid), $svg);
}

function icone(string $nome): string
{
    $todos = icones();
    return $todos[$nome] ?? ($todos['plane'] ?? '');
}

/** Endereço da página de um destino (igual ao gerador em Node e ao site.js). */
function endereco_destino(string $nome): string
{
    $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) ?: $nome;
    $texto = strtolower($texto);
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto);
    return trim($texto, '-');
}

/* ─────────────────────────────  Fragmentos  ────────────────────────────── */

function render_numeros(array $stats): string
{
    $saida = '';
    foreach ($stats as $stat) {
        if (empty($stat['value'])) continue;
        $saida .= '<div class="hero__stat"><b>' . e($stat['value']) . '</b><span>' . e($stat['label'] ?? '') . '</span></div>';
    }
    return $saida;
}

function render_destinos(array $destinos): string
{
    $saida = '';
    foreach ($destinos as $indice => $destino) {
        if (empty($destino['name'])) continue;
        $slug = endereco_destino($destino['name']);
        $arte = !empty($destino['imageUrl'])
            ? '<img src="' . e($destino['imageUrl']) . '" alt="' . e($destino['name']) . '" loading="lazy">'
            : cena($destino['art'] ?? 'tropical', 'd' . $indice);

        $saida .= '<a class="destination reveal" href="/viagens/' . e($slug) . '/"'
            . ' aria-label="Viagens para ' . e($destino['name']) . ' — ver detalhes e pedir orçamento">'
            . '<div class="destination__art">' . $arte
            . (!empty($destino['tag']) ? '<span class="destination__tag">' . e($destino['tag']) . '</span>' : '')
            . '</div>'
            . '<div class="destination__body">'
            . '<span class="destination__region">' . e($destino['region'] ?? '') . '</span>'
            . '<h3 class="destination__name">' . e($destino['name']) . '</h3>'
            . '<p class="destination__nights">' . e($destino['nights'] ?? '') . '</p>'
            . '<div class="destination__foot">'
            . '<span class="destination__price">'
            . (!empty($destino['from']) ? '<small>desde</small><b>' . e($destino['from']) . ' €</b>' : '<b>sob consulta</b>')
            . '</span>'
            . '<span class="destination__cta">Ver e pedir orçamento</span>'
            . '</div></div></a>';
    }
    return $saida;
}

function render_passos(array $passos): string
{
    $saida = '';
    foreach ($passos as $passo) {
        $saida .= '<div class="step"><h3>' . e($passo['title'] ?? '') . '</h3><p>' . e($passo['text'] ?? '') . '</p></div>';
    }
    return $saida;
}

function render_vantagens(array $vantagens): string
{
    $saida = '';
    foreach ($vantagens as $vantagem) {
        $saida .= '<article class="highlight">'
            . '<div class="highlight__icon">' . icone($vantagem['icon'] ?? 'compass') . '</div>'
            . '<h3>' . e($vantagem['title'] ?? '') . '</h3>'
            . '<p>' . e($vantagem['text'] ?? '') . '</p></article>';
    }
    return $saida;
}

function render_testemunhos(array $testemunhos): string
{
    $saida = '';
    foreach ($testemunhos as $t) {
        $estrelas = max(1, min(5, (int)($t['rating'] ?? 5)));
        $iniciais = '';
        foreach (array_slice(preg_split('/\s+/u', trim((string)($t['name'] ?? ''))), 0, 2) as $palavra) {
            $iniciais .= mb_strtoupper(mb_substr($palavra, 0, 1));
        }
        $saida .= '<article class="testimonial">'
            . '<div class="testimonial__stars" aria-label="' . $estrelas . ' em 5 estrelas">' . str_repeat('★', $estrelas) . '</div>'
            . '<p class="testimonial__text">“' . e($t['text'] ?? '') . '”</p>'
            . '<div class="testimonial__who">'
            . '<span class="testimonial__avatar" aria-hidden="true">' . e($iniciais) . '</span>'
            . '<div><b>' . e($t['name'] ?? '') . '</b><span>' . e($t['trip'] ?? '') . '</span></div>'
            . '</div></article>';
    }
    return $saida;
}

function render_faq(array $perguntas): string
{
    $saida = '';
    foreach ($perguntas as $pergunta) {
        $saida .= '<details class="faq__item"><summary>' . e($pergunta['q'] ?? '') . '</summary>'
            . '<p>' . e($pergunta['a'] ?? '') . '</p></details>';
    }
    return $saida;
}

function render_contactos(array $empresa): string
{
    $saida = '';
    $digitos = fn($v) => preg_replace('/[^\d+]/', '', (string)$v);
    if (!empty($empresa['phone'])) {
        $saida .= '<li><a href="tel:' . e($digitos($empresa['phone'])) . '">' . e($empresa['phone']) . '</a></li>';
    }
    if (!empty($empresa['email'])) {
        $saida .= '<li><a href="mailto:' . e($empresa['email']) . '">' . e($empresa['email']) . '</a></li>';
    }
    $morada = trim(implode(', ', array_filter([
        $empresa['address'] ?? '',
        trim(($empresa['postalCode'] ?? '') . ' ' . ($empresa['city'] ?? '')),
    ])), ', ');
    if ($morada !== '') {
        $saida .= !empty($empresa['mapsUrl'])
            ? '<li><a href="' . e($empresa['mapsUrl']) . '" target="_blank" rel="noopener">' . e($morada) . '</a></li>'
            : '<li>' . e($morada) . '</li>';
    }
    if (!empty($empresa['hours'])) $saida .= '<li>' . e($empresa['hours']) . '</li>';
    return $saida;
}

/* ─────────────────────────  Dados estruturados  ────────────────────────── */

function render_jsonld(array $d): string
{
    $base = rtrim((string)($d['brand']['siteUrl'] ?? ''), '/');
    $empresa = $d['company'];
    $grafo = [];

    $agencia = array_filter([
        '@type' => 'TravelAgency',
        '@id' => $base . '/#organizacao',
        'name' => $d['brand']['name'] ?? '1000viagens',
        'description' => $d['brand']['tagline'] ?? '',
        'url' => $base . '/',
        'email' => $empresa['email'] ?? null,
        'telephone' => $empresa['phone'] ?? null,
        'openingHours' => $empresa['hours'] ?? null,
        'areaServed' => $empresa['country'] ?? 'Portugal',
        'priceRange' => '€€',
        'image' => $base . '/assets/img/og.png',
        'sameAs' => array_values(array_filter($empresa['socials'] ?? [])),
    ], fn($v) => $v !== null && $v !== '' && $v !== []);

    if (!empty($empresa['address'])) {
        $agencia['address'] = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $empresa['address'],
            'postalCode' => $empresa['postalCode'] ?? null,
            'addressLocality' => $empresa['city'] ?? null,
            'addressCountry' => 'PT',
        ]);
    }
    $grafo[] = $agencia;

    $faq = array_values(array_filter($d['content']['faq'] ?? [], fn($p) => !empty($p['q']) && !empty($p['a'])));
    if ($faq) {
        $grafo[] = [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn($p) => [
                '@type' => 'Question',
                'name' => $p['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p['a']],
            ], $faq),
        ];
    }

    $destinos = array_values(array_filter($d['content']['destinations'] ?? [], fn($x) => !empty($x['name'])));
    if ($destinos) {
        $itens = [];
        foreach ($destinos as $indice => $destino) {
            $itens[] = [
                '@type' => 'ListItem',
                'position' => $indice + 1,
                'name' => 'Viagens para ' . $destino['name'],
                'url' => $base . '/viagens/' . endereco_destino($destino['name']) . '/',
            ];
        }
        $grafo[] = ['@type' => 'ItemList', 'name' => 'Destinos em destaque', 'itemListElement' => $itens];
    }

    return json_encode(['@context' => 'https://schema.org', '@graph' => $grafo],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ──────────────────────────  Página completa  ──────────────────────────── */

function render_pagina_inicial(): string
{
    $html = @file_get_contents(RAIZ . '/index.html');
    if ($html === false) return '<h1>Falta o ficheiro index.html</h1>';

    $d = definicoes();
    $conteudo = $d['content'];
    $base = rtrim((string)($d['brand']['siteUrl'] ?? ''), '/');

    $substituicoes = [
        '<!--SSR:hero-stats-->' => render_numeros($conteudo['hero']['stats'] ?? []),
        '<!--SSR:destinations-->' => render_destinos($conteudo['destinations'] ?? []),
        '<!--SSR:steps-->' => render_passos($conteudo['steps'] ?? []),
        '<!--SSR:highlights-->' => render_vantagens($conteudo['highlights'] ?? []),
        '<!--SSR:testimonials-->' => render_testemunhos($conteudo['testimonials'] ?? []),
        '<!--SSR:faq-->' => render_faq($conteudo['faq'] ?? []),
        '<!--SSR:contacts-->' => render_contactos($d['company']),
        '<!--SSR:head-->' => ($d['integrations']['analyticsSnippet'] ?? ''),
    ];
    $html = str_replace(array_keys($substituicoes), array_values($substituicoes), $html);

    // Dados estruturados prontos no HTML (o JavaScript volta a escrevê-los depois)
    $html = preg_replace(
        '#<script type="application/ld\+json" id="schema-org">.*?</script>#s',
        '<script type="application/ld+json" id="schema-org">' . render_jsonld($d) . '</script>',
        $html,
        1
    );

    // Domínio real nos endereços canónicos e de partilha
    if ($base !== '') {
        $html = str_replace('https://www.1000viagens.pt', $base, $html);
    }

    // Nome da marca no título e nas partilhas, se tiver sido mudado
    $nome = (string)($d['brand']['name'] ?? '1000viagens');
    if ($nome !== '' && $nome !== '1000viagens') {
        $html = str_replace('| 1000viagens</title>', '| ' . e($nome) . '</title>', $html);
        $html = str_replace('content="1000viagens"', 'content="' . e($nome) . '"', $html);
    }

    return $html;
}
