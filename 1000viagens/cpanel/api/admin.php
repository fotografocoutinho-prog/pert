<?php
/**
 * /api/admin/* — todas as rotas do backoffice.
 * O .htaccess encaminha para aqui e o caminho original é lido do pedido.
 */
declare(strict_types=1);
require_once __DIR__ . '/_pedidos.php';

$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$rota = preg_replace('#^.*/api/admin/?#', '', $caminho);
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ───────────────────────────  Sessão (sem login)  ──────────────────────── */

if ($rota === 'login' && $metodo === 'POST') {
    if (!limitar('login:' . ip_cliente(), 10, 600)) erro(429, 'Demasiadas tentativas. Aguarde 10 minutos.');
    $corpo = corpo_json(4096);
    $sessao = entrar((string)($corpo['password'] ?? ''));
    if (!$sessao) erro(401, 'Password incorreta.');
    definir_cookie_sessao($sessao['token'], $sessao['expiresAt']);
    responder(200, ['ok' => true, 'mustChangePassword' => $sessao['mustChangePassword']]);
}

if ($rota === 'logout' && $metodo === 'POST') {
    terminar_sessao();
    limpar_cookie_sessao();
    responder(200, ['ok' => true]);
}

if ($rota === 'session' && $metodo === 'GET') {
    arrancar_auth();
    $sessao = sessao_atual();
    if (!$sessao) responder(200, ['authenticated' => false]);
    $auth = ler_json(ficheiro_auth());
    responder(200, [
        'authenticated' => true,
        'mustChangePassword' => (bool)($auth['mustChangePassword'] ?? false),
        'lastLoginAt' => $auth['lastLoginAt'] ?? '',
        'smtpConfigured' => function_exists('mail'),
        'expiresAt' => gmdate('c', (int)$sessao['expiresAt']),
    ]);
}

/* ──────────────────────  A partir daqui, só autenticado  ───────────────── */

exigir_sessao();

/* Definições */
if ($rota === 'settings' && $metodo === 'GET') {
    $revelar = ($_GET['reveal'] ?? '') === '1';
    responder(200, ['settings' => definicoes_admin(definicoes(), $revelar), 'catalog' => catalogo()]);
}

if ($rota === 'settings' && $metodo === 'PUT') {
    $remendo = corpo_json(1048576);
    $novas = guardar_definicoes($remendo);
    responder(200, ['ok' => true, 'settings' => definicoes_admin($novas)]);
}

/* Password */
if ($rota === 'password' && $metodo === 'POST') {
    $corpo = corpo_json(4096);
    $auth = ler_json(ficheiro_auth());
    if (!password_verify((string)($corpo['currentPassword'] ?? ''), (string)($auth['passwordHash'] ?? ''))) {
        erro(400, 'A password atual não está correta.');
    }
    $nova = (string)($corpo['newPassword'] ?? '');
    if (strlen($nova) < 8) erro(400, 'A nova password tem de ter pelo menos 8 caracteres.');
    atualizar_json(ficheiro_auth(), function (&$d) use ($nova) {
        $d['passwordHash'] = password_hash($nova, PASSWORD_DEFAULT);
        $d['mustChangePassword'] = false;
        $d['sessions'] = [];
        $d['passwordChangedAt'] = gmdate('c');
    });
    limpar_cookie_sessao();
    responder(200, ['ok' => true]);
}

/* Imagens */
if ($rota === 'upload' && $metodo === 'POST') {
    $corpo = corpo_json(6291456);
    $dataUrl = (string)($corpo['dataUrl'] ?? '');
    if (!preg_match('#^data:(image/(png|jpeg|jpg|webp|gif|svg\+xml|x-icon));base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        erro(400, 'Imagem inválida. Use PNG, JPG, WEBP ou SVG.');
    }
    $binario = base64_decode($m[3], true);
    if ($binario === false) erro(400, 'Imagem inválida.');
    if (strlen($binario) > 4194304) erro(413, 'A imagem não pode exceder 4 MB.');

    $extensoes = [
        'image/png' => '.png', 'image/jpeg' => '.jpg', 'image/jpg' => '.jpg',
        'image/webp' => '.webp', 'image/gif' => '.gif', 'image/svg+xml' => '.svg', 'image/x-icon' => '.ico',
    ];
    $tipo = preg_replace('/[^a-z0-9-]/i', '', texto_limpo($corpo['kind'] ?? 'ficheiro', 24)) ?: 'ficheiro';
    $nome = $tipo . '-' . base_convert((string)time(), 10, 36) . '-' . bin2hex(random_bytes(4)) . $extensoes[$m[1]];

    if (!is_dir(UPLOADS_DIR)) @mkdir(UPLOADS_DIR, 0755, true);
    if (@file_put_contents(UPLOADS_DIR . '/' . $nome, $binario) === false) {
        erro(500, 'Não foi possível guardar a imagem. Verifique as permissões da pasta /uploads.');
    }
    responder(201, ['ok' => true, 'url' => '/uploads/' . $nome, 'bytes' => strlen($binario)]);
}

/* ─────────────────────────────  Pedidos  ───────────────────────────────── */

function periodo_pedido(): array
{
    $ate = isset($_GET['to']) ? strtotime($_GET['to'] . ' 23:59:59 UTC') : time();
    if (!$ate) $ate = time();
    if (isset($_GET['from'])) {
        $de = strtotime($_GET['from'] . ' 00:00:00 UTC') ?: ($ate - 365 * 86400);
    } else {
        $intervalo = $_GET['range'] ?? '365';
        $de = $intervalo === 'tudo' ? strtotime('2000-01-01 UTC') : $ate - inteiro($intervalo, 1, 3650, 365) * 86400;
    }
    return [$de, $ate];
}

function pedidos_filtrados(): array
{
    [$de, $ate] = periodo_pedido();
    $todos = ler_json(DADOS_DIR . '/leads.json', ['items' => []])['items'] ?? [];
    $estado = $_GET['status'] ?? '';
    $tipo = $_GET['type'] ?? '';
    $procura = dobrar((string)($_GET['q'] ?? ''));

    $filtrados = array_values(array_filter($todos, function ($p) use ($de, $ate, $estado, $tipo, $procura) {
        $criado = strtotime($p['createdAt']);
        if ($criado < $de || $criado > $ate) return false;
        if ($estado !== '' && $p['status'] !== $estado) return false;
        if ($tipo !== '' && $p['trip']['type'] !== $tipo) return false;
        if ($procura !== '') {
            $palheiro = dobrar(implode(' ', [
                $p['id'], $p['contact']['name'], $p['contact']['email'],
                $p['contact']['phone'], $p['trip']['destination'],
            ]));
            if (strpos($palheiro, $procura) === false) return false;
        }
        return true;
    }));

    usort($filtrados, fn($a, $b) => strtotime($b['createdAt']) <=> strtotime($a['createdAt']));
    return [$filtrados, $de, $ate];
}

if ($rota === 'leads' && $metodo === 'GET') {
    [$filtrados] = pedidos_filtrados();
    $limite = inteiro($_GET['limit'] ?? 100, 1, 500, 100);
    $salto = inteiro($_GET['offset'] ?? 0, 0, 100000, 0);
    responder(200, ['total' => count($filtrados), 'items' => array_slice($filtrados, $salto, $limite)]);
}

if (preg_match('#^leads/([\w-]+)$#', $rota, $m)) {
    $id = $m[1];
    $ficheiro = DADOS_DIR . '/leads.json';

    if ($metodo === 'GET') {
        $todos = ler_json($ficheiro, ['items' => []])['items'] ?? [];
        foreach ($todos as $pedido) {
            if ($pedido['id'] === $id) responder(200, ['lead' => $pedido]);
        }
        erro(404, 'Pedido não encontrado.');
    }

    if ($metodo === 'PATCH') {
        $corpo = corpo_json(65536);
        $atualizado = atualizar_json($ficheiro, function (&$d) use ($id, $corpo) {
            foreach ($d['items'] as &$pedido) {
                if ($pedido['id'] !== $id) continue;
                $pedido['internal'] = $pedido['internal'] ?? ['history' => []];

                if (!empty($corpo['status'])) {
                    $estado = id_valido('statuses', $corpo['status'], $pedido['status']);
                    if ($estado !== $pedido['status']) {
                        $pedido['status'] = $estado;
                        $pedido['internal']['history'][] = [
                            'at' => gmdate('c'),
                            'status' => $estado,
                            'note' => texto_limpo($corpo['historyNote'] ?? '', 200),
                        ];
                        $pedido['internal']['history'] = array_slice($pedido['internal']['history'], -40);
                    }
                }
                if (array_key_exists('notes', $corpo)) $pedido['internal']['notes'] = texto_multilinha($corpo['notes'], 4000);
                if (array_key_exists('owner', $corpo)) $pedido['internal']['owner'] = texto_limpo($corpo['owner'], 80);
                if (array_key_exists('quotedValue', $corpo)) {
                    $pedido['internal']['quotedValue'] = numero($corpo['quotedValue'], 0, 1000000, null);
                }
                $pedido['estimatedValue'] = valor_estimado($pedido);
                $pedido['updatedAt'] = gmdate('c');
                return $pedido;
            }
            return null;
        }, ['sequence' => 0, 'items' => []]);

        if (!$atualizado) erro(404, 'Pedido não encontrado.');
        responder(200, ['ok' => true, 'lead' => $atualizado]);
    }

    if ($metodo === 'DELETE') {
        $apagado = atualizar_json($ficheiro, function (&$d) use ($id) {
            $antes = count($d['items'] ?? []);
            $d['items'] = array_values(array_filter($d['items'] ?? [], fn($p) => $p['id'] !== $id));
            return $antes !== count($d['items']);
        }, ['sequence' => 0, 'items' => []]);
        if (!$apagado) erro(404, 'Pedido não encontrado.');
        responder(200, ['ok' => true]);
    }
}

/* Estatísticas */
if ($rota === 'stats' && $metodo === 'GET') {
    [$filtrados, $de, $ate] = pedidos_filtrados();
    responder(200, ['stats' => calcular_estatisticas($filtrados, $de, $ate)]);
}

/* Exportação */
if ($rota === 'export.csv' && $metodo === 'GET') {
    [$filtrados] = pedidos_filtrados();
    $csv = pedidos_para_csv($filtrados);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pedidos-1000viagens-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

erro(404, 'Rota desconhecida.');
