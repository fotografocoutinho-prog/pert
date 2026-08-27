<?php
/**
 * 1000viagens — núcleo do servidor em PHP (para alojamento com cPanel).
 *
 * Mesmas rotas, mesmos ficheiros de dados e mesmo comportamento da versão
 * em Node: o site e o backoffice são exatamente os mesmos ficheiros.
 *
 * Requisitos: PHP 7.4 ou superior. Sem extensões fora do comum.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
setlocale(LC_ALL, 'pt_PT.UTF-8', 'pt_PT', 'Portuguese');

define('RAIZ', dirname(__DIR__));
define('DADOS_DIR', RAIZ . '/dados');
define('UPLOADS_DIR', RAIZ . '/uploads');
define('BASE_DIR', __DIR__ . '/base');
define('COOKIE_SESSAO', 'mv_session');
define('DURACAO_SESSAO', 12 * 60 * 60);

if (!is_dir(DADOS_DIR)) @mkdir(DADOS_DIR, 0750, true);
if (!is_dir(UPLOADS_DIR)) @mkdir(UPLOADS_DIR, 0755, true);

/* ─────────────────────────────  Respostas  ─────────────────────────────── */

function responder(int $estado, array $dados, array $cabecalhos = []): void
{
    http_response_code($estado);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($cabecalhos as $nome => $valor) {
        header($nome . ': ' . $valor, false);
    }
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erro(int $estado, string $mensagem, array $extra = []): void
{
    responder($estado, array_merge(['error' => $mensagem], $extra));
}

/** Corpo do pedido em JSON, com limite de tamanho. */
function corpo_json(int $limite = 6291456): array
{
    $bruto = file_get_contents('php://input', false, null, 0, $limite + 1);
    if ($bruto === false || $bruto === '') return [];
    if (strlen($bruto) > $limite) erro(413, 'Pedido demasiado grande.');
    $dados = json_decode($bruto, true);
    if (!is_array($dados)) erro(400, 'JSON inválido.');
    return $dados;
}

/* ────────────────────────────  Ficheiros JSON  ─────────────────────────── */

function ler_json(string $ficheiro, array $omissao = []): array
{
    if (!is_file($ficheiro)) return $omissao;
    $bruto = @file_get_contents($ficheiro);
    if ($bruto === false || $bruto === '') return $omissao;
    $dados = json_decode($bruto, true);
    if (!is_array($dados)) {
        @rename($ficheiro, $ficheiro . '.corrompido-' . time());
        return $omissao;
    }
    return $dados;
}

/** Escrita atómica: ficheiro temporário + rename (não corrompe com acessos simultâneos). */
function gravar_json(string $ficheiro, array $dados): bool
{
    $pasta = dirname($ficheiro);
    if (!is_dir($pasta)) @mkdir($pasta, 0750, true);
    $temporario = $ficheiro . '.' . getmypid() . '.tmp';
    $conteudo = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($conteudo === false) return false;
    if (@file_put_contents($temporario, $conteudo, LOCK_EX) === false) return false;
    return @rename($temporario, $ficheiro);
}

/** Muta um ficheiro JSON com bloqueio, evitando escritas perdidas. */
function atualizar_json(string $ficheiro, callable $mutador, array $omissao = [])
{
    $trinco = fopen($ficheiro . '.lock', 'c');
    if ($trinco) flock($trinco, LOCK_EX);
    try {
        $dados = ler_json($ficheiro, $omissao);
        $resultado = $mutador($dados);
        gravar_json($ficheiro, $dados);
        return $resultado;
    } finally {
        if ($trinco) { flock($trinco, LOCK_UN); fclose($trinco); }
    }
}

/* ────────────────────────  Catálogo e definições  ──────────────────────── */

function catalogo(): array
{
    static $catalogo = null;
    if ($catalogo === null) $catalogo = ler_json(BASE_DIR . '/catalogo.json');
    return $catalogo;
}

function definicoes_origem(): array
{
    static $origem = null;
    if ($origem === null) $origem = ler_json(BASE_DIR . '/definicoes.json');
    return $origem;
}

/** Fusão profunda: o guardado por cima do de origem (listas substituem). */
function fundir(array $base, $novo): array
{
    if (!is_array($novo)) return $base;
    $resultado = $base;
    foreach ($novo as $chave => $valor) {
        if (is_array($valor) && isset($base[$chave]) && is_array($base[$chave])
            && !array_is_list_compat($valor) && !array_is_list_compat($base[$chave])) {
            $resultado[$chave] = fundir($base[$chave], $valor);
        } else {
            $resultado[$chave] = $valor;
        }
    }
    return $resultado;
}

function array_is_list_compat(array $lista): bool
{
    if (function_exists('array_is_list')) return array_is_list($lista);
    return $lista === [] || array_keys($lista) === range(0, count($lista) - 1);
}

function definicoes(): array
{
    static $definicoes = null;
    if ($definicoes === null) {
        $definicoes = fundir(definicoes_origem(), ler_json(DADOS_DIR . '/settings.json'));
    }
    return $definicoes;
}

function guardar_definicoes(array $remendo): array
{
    $atuais = definicoes();

    // O código do TravelPartner chega mascarado quando não foi alterado
    $codigo = $remendo['integrations']['travelPartner']['authorizationCode'] ?? null;
    if (is_string($codigo)) {
        if (strpos($codigo, '•') !== false) {
            $remendo['integrations']['travelPartner']['authorizationCode']
                = $atuais['integrations']['travelPartner']['authorizationCode'] ?? '';
        } else {
            $remendo['integrations']['travelPartner']['updatedAt'] = gmdate('c');
        }
    }

    $novas = fundir($atuais, $remendo);
    $novas['meta']['updatedAt'] = gmdate('c');
    if (empty($novas['meta']['createdAt'])) $novas['meta']['createdAt'] = gmdate('c');

    gravar_json(DADOS_DIR . '/settings.json', $novas);
    $GLOBALS['__definicoes_cache'] = $novas;
    return $novas;
}

/** O que o site público pode ver (sem segredos). */
function definicoes_publicas(array $d): array
{
    return [
        'brand' => $d['brand'],
        'company' => array_diff_key($d['company'], []),
        'content' => $d['content'],
        'integrations' => ['analyticsSnippet' => $d['integrations']['analyticsSnippet'] ?? ''],
    ];
}

function mascarar(string $valor): string
{
    if (strlen($valor) <= 4) return '••••';
    return str_repeat('•', min(12, strlen($valor) - 4)) . substr($valor, -4);
}

/** Projeção para o backoffice: código mascarado, salvo quando pedido revelar. */
function definicoes_admin(array $d, bool $revelar = false): array
{
    $tp = $d['integrations']['travelPartner'] ?? [];
    $codigo = (string)($tp['authorizationCode'] ?? '');
    $d['integrations']['travelPartner']['hasAuthorizationCode'] = $codigo !== '';
    if (!$revelar) {
        $d['integrations']['travelPartner']['authorizationCode'] = $codigo === '' ? '' : mascarar($codigo);
    }
    return $d;
}

/* ────────────────────────────  Autenticação  ───────────────────────────── */

function ficheiro_auth(): string { return DADOS_DIR . '/auth.php.json'; }

function arrancar_auth(): void
{
    $auth = ler_json(ficheiro_auth());
    if (!empty($auth['passwordHash'])) return;

    $inicial = getenv('ADMIN_PASSWORD') ?: '1000viagens';
    gravar_json(ficheiro_auth(), [
        'passwordHash' => password_hash($inicial, PASSWORD_DEFAULT),
        'mustChangePassword' => $inicial === '1000viagens',
        'createdAt' => gmdate('c'),
        'sessions' => [],
    ]);
}

function entrar(string $password): ?array
{
    arrancar_auth();
    $auth = ler_json(ficheiro_auth());
    if (!password_verify($password, (string)($auth['passwordHash'] ?? ''))) {
        atualizar_json(ficheiro_auth(), function (&$d) {
            $d['failedAttempts'] = ($d['failedAttempts'] ?? 0) + 1;
            $d['lastFailedAt'] = gmdate('c');
        });
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $expira = time() + DURACAO_SESSAO;
    atualizar_json(ficheiro_auth(), function (&$d) use ($token, $expira) {
        $agora = time();
        $d['sessions'] = array_values(array_filter($d['sessions'] ?? [], fn($s) => ($s['expiresAt'] ?? 0) > $agora));
        $d['sessions'][] = [
            'digest' => hash('sha256', $token),
            'createdAt' => gmdate('c'),
            'expiresAt' => $expira,
            'ip' => ip_cliente(),
            'agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
        ];
        if (count($d['sessions']) > 20) $d['sessions'] = array_slice($d['sessions'], -20);
        $d['failedAttempts'] = 0;
        $d['lastLoginAt'] = gmdate('c');
    });

    return ['token' => $token, 'expiresAt' => $expira, 'mustChangePassword' => (bool)($auth['mustChangePassword'] ?? false)];
}

function sessao_atual(): ?array
{
    $token = $_COOKIE[COOKIE_SESSAO] ?? '';
    if ($token === '') return null;
    $auth = ler_json(ficheiro_auth());
    $procurado = hash('sha256', $token);
    foreach (($auth['sessions'] ?? []) as $sessao) {
        if (hash_equals((string)($sessao['digest'] ?? ''), $procurado) && ($sessao['expiresAt'] ?? 0) > time()) {
            return $sessao;
        }
    }
    return null;
}

function terminar_sessao(): void
{
    $token = $_COOKIE[COOKIE_SESSAO] ?? '';
    if ($token === '') return;
    $procurado = hash('sha256', $token);
    atualizar_json(ficheiro_auth(), function (&$d) use ($procurado) {
        $d['sessions'] = array_values(array_filter(
            $d['sessions'] ?? [],
            fn($s) => !hash_equals((string)($s['digest'] ?? ''), $procurado)
        ));
    });
}

function definir_cookie_sessao(string $token, int $expira): void
{
    $seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $partes = [
        COOKIE_SESSAO . '=' . rawurlencode($token),
        'Max-Age=' . max(0, $expira - time()),
        'Path=/',
        'HttpOnly',
        'SameSite=Lax',
    ];
    if ($seguro) $partes[] = 'Secure';
    header('Set-Cookie: ' . implode('; ', $partes), false);
}

function limpar_cookie_sessao(): void
{
    header('Set-Cookie: ' . COOKIE_SESSAO . '=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax', false);
}

/** Exige sessão válida e, nas escritas, o cabeçalho anti-CSRF. */
function exigir_sessao(): array
{
    $sessao = sessao_atual();
    if (!$sessao) erro(401, 'Sessão expirada. Volte a entrar.');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '1000viagens') {
        erro(403, 'Pedido inválido.');
    }
    return $sessao;
}

/* ──────────────────────────────  Auxiliares  ───────────────────────────── */

function ip_cliente(): string
{
    $encaminhado = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($encaminhado !== '') return trim(explode(',', $encaminhado)[0]);
    return $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
}

/** Limitador simples por IP, guardado em ficheiro. */
function limitar(string $chave, int $maximo, int $janela): bool
{
    $ficheiro = DADOS_DIR . '/limites.json';
    $agora = time();
    $permitido = true;
    atualizar_json($ficheiro, function (&$d) use ($chave, $maximo, $janela, $agora, &$permitido) {
        foreach ($d as $k => $registo) {
            if (($registo['inicio'] ?? 0) < $agora - 86400) unset($d[$k]);
        }
        $registo = $d[$chave] ?? null;
        if (!$registo || ($registo['inicio'] + $janela) < $agora) {
            $d[$chave] = ['inicio' => $agora, 'contagem' => 1];
            return;
        }
        $d[$chave]['contagem']++;
        if ($d[$chave]['contagem'] > $maximo) $permitido = false;
    });
    return $permitido;
}

function texto_limpo($valor, int $maximo = 500): string
{
    $texto = is_scalar($valor) ? (string)$valor : '';
    $texto = preg_replace('/\s+/u', ' ', $texto);
    return mb_substr(trim($texto), 0, $maximo);
}

function texto_multilinha($valor, int $maximo = 4000): string
{
    $texto = is_scalar($valor) ? (string)$valor : '';
    $texto = str_replace("\r\n", "\n", $texto);
    $texto = preg_replace('/[ \t]+/u', ' ', $texto);
    $texto = preg_replace('/\n{3,}/u', "\n\n", $texto);
    return mb_substr(trim($texto), 0, $maximo);
}

function inteiro($valor, int $min, int $max, int $omissao = 0): int
{
    if (!is_numeric($valor)) return $omissao;
    return max($min, min($max, (int)$valor));
}

function numero($valor, float $min = 0, float $max = 10000000, $omissao = null)
{
    if ($valor === '' || $valor === null || !is_numeric($valor)) return $omissao;
    return max($min, min($max, (float)$valor));
}

/** Remove acentos e maiúsculas (procuras e agrupamento de destinos). */
function dobrar(string $texto): string
{
    $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    if ($t === false) $t = $texto;
    $t = strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
    return trim($t);
}

function id_valido(string $tipo, $valor, string $omissao = ''): string
{
    $lista = catalogo()[$tipo] ?? [];
    foreach ($lista as $item) {
        if (($item['id'] ?? null) === $valor) return (string)$valor;
    }
    return $omissao;
}

function ids_validos(string $tipo, $valores, int $maximo = 20): array
{
    if (!is_array($valores)) return [];
    $resultado = [];
    foreach ($valores as $valor) {
        $id = id_valido($tipo, $valor);
        if ($id !== '' && !in_array($id, $resultado, true)) {
            $resultado[] = $id;
            if (count($resultado) >= $maximo) break;
        }
    }
    return $resultado;
}

function etiqueta(string $tipo, $id, string $omissao = '—'): string
{
    foreach ((catalogo()[$tipo] ?? []) as $item) {
        if (($item['id'] ?? null) === $id) return (string)$item['label'];
    }
    return $omissao;
}
