<?php
/** POST /api/leads — recebe um pedido de orçamento do site. */
declare(strict_types=1);
require_once __DIR__ . '/_pedidos.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') erro(405, 'Método não permitido.');

if (!limitar('pedido:' . ip_cliente(), 6, 600)) {
    erro(429, 'Recebemos vários pedidos deste dispositivo. Tente de novo dentro de alguns minutos.');
}

$corpo = corpo_json(262144);

// Armadilhas anti-robô: campo escondido preenchido, ou formulário instantâneo
if (texto_limpo($corpo['website'] ?? '', 50) !== '') {
    responder(200, ['ok' => true, 'id' => 'ignorado']);
}
if (isset($corpo['elapsedMs']) && is_numeric($corpo['elapsedMs']) && $corpo['elapsedMs'] < 3000) {
    erro(400, 'Formulário submetido demasiado depressa.');
}

$ficheiro = DADOS_DIR . '/leads.json';
$resultado = null;

$pedido = atualizar_json($ficheiro, function (&$dados) use ($corpo, &$resultado) {
    $sequencia = (int)($dados['sequence'] ?? 0) + 1;
    $resultado = normalizar_pedido($corpo, $sequencia);
    if (!$resultado['ok']) return null;
    $dados['sequence'] = $sequencia;
    $dados['items'] = $dados['items'] ?? [];
    $dados['items'][] = $resultado['lead'];
    return $resultado['lead'];
}, ['sequence' => 0, 'items' => []]);

if (!$pedido) {
    erro(422, 'Faltam dados obrigatórios.', ['errors' => $resultado['errors'] ?? []]);
}

avisar_novo_pedido($pedido);
responder(201, ['ok' => true, 'id' => $pedido['id'], 'message' => 'Pedido registado.']);

/* ─────────────────────────────  Avisos  ────────────────────────────────── */

function avisar_novo_pedido(array $pedido): void
{
    $d = definicoes();
    $destino = $pedido['trip']['undecided'] ? 'Ainda sem destino' : $pedido['trip']['destination'];

    // Webhook (Zapier, Make, n8n, CRM…)
    $webhook = $d['integrations']['webhookUrl'] ?? '';
    if ($webhook !== '' && filter_var($webhook, FILTER_VALIDATE_URL)) {
        $carga = json_encode(['source' => '1000viagens', 'lead' => $pedido], JSON_UNESCAPED_UNICODE);
        if (function_exists('curl_init')) {
            $ch = curl_init($webhook);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $carga,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
            ]);
            @curl_exec($ch);
            curl_close($ch);
        } else {
            @file_get_contents($webhook, false, stream_context_create([
                'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $carga, 'timeout' => 8],
            ]));
        }
    }

    // E-mail para a agência (o cPanel tem sempre função de envio)
    $para = $d['integrations']['notificationEmail'] ?? '';
    if ($para !== '' && filter_var($para, FILTER_VALIDATE_EMAIL)) {
        $assunto = "Novo pedido {$pedido['id']} — {$destino}";
        $linhas = [
            'Novo pedido de orçamento em ' . ($d['brand']['name'] ?? '1000viagens') . '.',
            '',
            'Referência: ' . $pedido['id'],
            'Nome: ' . $pedido['contact']['name'],
            'E-mail: ' . $pedido['contact']['email'],
            'Telefone: ' . ($pedido['contact']['phone'] ?: '—'),
            'Canal preferido: ' . etiqueta('contactChannels', $pedido['contact']['channel']),
            '',
            'Destino: ' . $destino,
            'Tipo: ' . etiqueta('tripTypes', $pedido['trip']['type']),
            'Quando: ' . ($pedido['trip']['startDate'] ?: $pedido['trip']['month'] ?: 'sem datas'),
            'Viajantes: ' . $pedido['party']['adults'] . ' adulto(s), ' . $pedido['party']['children'] . ' criança(s)',
            'Orçamento: ' . etiqueta('budgetRanges', $pedido['budget']['range'], '—'),
            'Valor estimado: ' . ($pedido['estimatedValue'] ? $pedido['estimatedValue'] . ' €' : '—'),
            '',
            ($pedido['prefs']['notes'] ? "Notas do cliente:\n" . $pedido['prefs']['notes'] . "\n" : ''),
            'Abra o backoffice para ver o pedido completo: ' . rtrim($d['brand']['siteUrl'] ?? '', '/') . '/admin',
        ];

        $remetente = $d['company']['email'] ?? ('nao-responder@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $cabecalhos = [
            'From: ' . mb_encode_mimeheader(($d['brand']['name'] ?? '1000viagens'), 'UTF-8') . ' <' . $remetente . '>',
            'Reply-To: ' . $pedido['contact']['name'] . ' <' . $pedido['contact']['email'] . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'MIME-Version: 1.0',
        ];
        @mail($para, mb_encode_mimeheader($assunto, 'UTF-8'), implode("\n", $linhas), implode("\r\n", $cabecalhos));
    }
}
