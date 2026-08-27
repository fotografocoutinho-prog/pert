<?php
/**
 * Pedidos de orçamento: validação, valor estimado, estatísticas e CSV.
 * Espelha server/leads.js — mesma estrutura de dados e mesmos resultados.
 */

declare(strict_types=1);

require_once __DIR__ . '/_nucleo.php';

const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

function referencia(int $ano, int $sequencia): string
{
    return sprintf('1V-%d-%04d', $ano, $sequencia);
}

/** "ilhas gregas" → "Ilhas Gregas" (com as partículas em minúsculas). */
function capitalizar_destino(string $texto): string
{
    $pequenas = ['de', 'da', 'do', 'das', 'dos', 'e', 'no', 'na', 'em', 'a', 'o'];
    $palavras = preg_split('/\s+/u', trim($texto));
    $saida = [];
    foreach ($palavras as $i => $palavra) {
        $minuscula = mb_strtolower($palavra);
        $saida[] = ($i > 0 && in_array($minuscula, $pequenas, true))
            ? $minuscula
            : mb_strtoupper(mb_substr($minuscula, 0, 1)) . mb_substr($minuscula, 1);
    }
    return implode(' ', $saida);
}

function data_valida($valor): string
{
    $texto = texto_limpo($valor, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) ? $texto : '';
}

function mes_valido($valor): string
{
    $texto = texto_limpo($valor, 7);
    return preg_match('/^\d{4}-\d{2}$/', $texto) ? $texto : '';
}

function meio_do_escalao(string $id)
{
    foreach (catalogo()['budgetRanges'] ?? [] as $escalao) {
        if ($escalao['id'] === $id) return $escalao['mid'];
    }
    return null;
}

function valor_estimado(array $pedido)
{
    $porPessoa = $pedido['budget']['perPerson'] ?? null;
    if ($porPessoa === null) $porPessoa = meio_do_escalao($pedido['budget']['range'] ?? '');
    if ($porPessoa === null) return null;
    return (int)round($porPessoa * max(1, $pedido['party']['travelers'] ?? 1));
}

/** Valor a usar nas contas: o orçamentado, se existir; senão o estimado. */
function valor_pedido(array $pedido)
{
    $orcamentado = $pedido['internal']['quotedValue'] ?? null;
    if (is_numeric($orcamentado) && $orcamentado > 0) return (float)$orcamentado;
    $estimado = $pedido['estimatedValue'] ?? null;
    return is_numeric($estimado) ? (float)$estimado : null;
}

/**
 * Valida e normaliza o que chega do formulário.
 * @return array{ok:bool, lead?:array, errors?:array}
 */
function normalizar_pedido(array $p, int $sequencia, ?int $momento = null): array
{
    $agora = $momento ?? time();
    $erros = [];

    $viagem = $p['trip'] ?? [];
    $grupo = $p['party'] ?? [];
    $orcamento = $p['budget'] ?? [];
    $prefs = $p['prefs'] ?? [];
    $contacto = $p['contact'] ?? [];

    $tipo = id_valido('tripTypes', $viagem['type'] ?? null);
    if ($tipo === '') $erros['trip.type'] = 'Escolha o tipo de viagem.';

    $indeciso = !empty($viagem['undecided']);
    $destino = texto_limpo($viagem['destination'] ?? '', 120);
    if (!$indeciso && mb_strlen($destino) < 2) {
        $erros['trip.destination'] = 'Indique o destino (ou escolha "ainda não sei").';
    }

    $nome = texto_limpo($contacto['name'] ?? '', 120);
    if (mb_strlen($nome) < 2) $erros['contact.name'] = 'Diga-nos como se chama.';

    $email = mb_strtolower(texto_limpo($contacto['email'] ?? '', 160));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros['contact.email'] = 'Indique um e-mail válido.';

    $telefone = texto_limpo($contacto['phone'] ?? '', 40);
    $canal = id_valido('contactChannels', $contacto['channel'] ?? null, 'email');
    if (($canal === 'telefone' || $canal === 'whatsapp') && strlen(preg_replace('/\D/', '', $telefone)) < 9) {
        $erros['contact.phone'] = 'Para contacto telefónico precisamos do seu número.';
    }

    if (($p['consent']['rgpd'] ?? null) !== true) {
        $erros['consent.rgpd'] = 'É necessário autorizar o tratamento dos dados.';
    }

    if ($erros) return ['ok' => false, 'errors' => $erros];

    $adultos = inteiro($grupo['adults'] ?? null, 1, 40, 2);
    $criancas = inteiro($grupo['children'] ?? null, 0, 20, 0);
    $idades = [];
    if (is_array($grupo['childrenAges'] ?? null)) {
        foreach (array_slice($grupo['childrenAges'], 0, $criancas) as $idade) {
            $idades[] = inteiro($idade, 0, 17, 0);
        }
    }

    $iso = gmdate('c', $agora);
    $pedido = [
        'id' => referencia((int)gmdate('Y', $agora), $sequencia),
        'createdAt' => $iso,
        'updatedAt' => $iso,
        'status' => 'novo',
        'trip' => [
            'type' => $tipo,
            'destination' => $indeciso ? '' : capitalizar_destino($destino),
            'destinationKey' => $indeciso ? 'indeciso' : dobrar($destino),
            'undecided' => $indeciso,
            'startDate' => data_valida($viagem['startDate'] ?? ''),
            'endDate' => data_valida($viagem['endDate'] ?? ''),
            'month' => mes_valido($viagem['month'] ?? ''),
            'flexible' => !empty($viagem['flexible']),
            'nights' => inteiro($viagem['nights'] ?? null, 0, 120, 0),
        ],
        'party' => [
            'adults' => $adultos,
            'children' => $criancas,
            'childrenAges' => $idades,
            'travelers' => $adultos + $criancas,
            'ageRange' => id_valido('ageRanges', $grupo['ageRange'] ?? null),
        ],
        'budget' => [
            'range' => id_valido('budgetRanges', $orcamento['range'] ?? null, 'sem-limite'),
            'perPerson' => numero($orcamento['perPerson'] ?? null, 0, 200000, null),
            'includes' => ids_validos('includes', $orcamento['includes'] ?? []),
            'currency' => 'EUR',
        ],
        'prefs' => [
            'hotelCategory' => id_valido('hotelCategories', $prefs['hotelCategory'] ?? null, 'indiferente'),
            'board' => id_valido('boards', $prefs['board'] ?? null),
            'pace' => id_valido('paces', $prefs['pace'] ?? null),
            'interests' => ids_validos('interests', $prefs['interests'] ?? []),
            'notes' => texto_multilinha($prefs['notes'] ?? '', 2000),
        ],
        'contact' => [
            'name' => $nome,
            'email' => $email,
            'phone' => $telefone,
            'channel' => $canal,
            'bestTime' => id_valido('bestTimes', $contacto['bestTime'] ?? null, 'qualquer'),
            'source' => id_valido('sources', $contacto['source'] ?? null),
        ],
        'consent' => [
            'rgpd' => true,
            'marketing' => ($p['consent']['marketing'] ?? null) === true,
            'at' => $iso,
        ],
        'internal' => [
            'notes' => '',
            'quotedValue' => null,
            'owner' => '',
            'history' => [['at' => $iso, 'status' => 'novo', 'note' => 'Pedido recebido pelo site.']],
        ],
    ];

    $pedido['estimatedValue'] = valor_estimado($pedido);
    return ['ok' => true, 'lead' => $pedido];
}

/* ─────────────────────────────  Estatísticas  ──────────────────────────── */

function mes_de_partida(array $pedido): string
{
    if (!empty($pedido['trip']['startDate'])) return substr($pedido['trip']['startDate'], 0, 7);
    if (!empty($pedido['trip']['month'])) return $pedido['trip']['month'];
    return '';
}

function chave_periodo(int $momento, string $granularidade): string
{
    if ($granularidade === 'day') return gmdate('Y-m-d', $momento);
    if ($granularidade === 'week') {
        $diaSemana = (int)gmdate('N', $momento) - 1;      // segunda = 0
        return gmdate('Y-m-d', $momento - $diaSemana * 86400);
    }
    return gmdate('Y-m', $momento);
}

function etiqueta_periodo(string $chave, string $granularidade): string
{
    $partes = explode('-', $chave);
    if ($granularidade === 'month') {
        return MESES[(int)$partes[1] - 1] . ' ' . substr($partes[0], 2);
    }
    return (int)$partes[2] . ' ' . MESES[(int)$partes[1] - 1];
}

function serie_temporal(array $pedidos, int $de, int $ate): array
{
    $dias = max(1, (int)round(($ate - $de) / 86400));
    $granularidade = $dias <= 31 ? 'day' : ($dias <= 130 ? 'week' : 'month');

    $baldes = [];
    $cursor = $de;
    $guarda = 0;
    while ($cursor <= $ate && $guarda++ < 800) {
        $chave = chave_periodo($cursor, $granularidade);
        if (!isset($baldes[$chave])) {
            $baldes[$chave] = ['key' => $chave, 'label' => etiqueta_periodo($chave, $granularidade), 'leads' => 0, 'value' => 0];
        }
        if ($granularidade === 'day') $cursor += 86400;
        elseif ($granularidade === 'week') $cursor += 7 * 86400;
        else $cursor = strtotime('+1 month', $cursor);
    }

    foreach ($pedidos as $pedido) {
        $chave = chave_periodo(strtotime($pedido['createdAt']), $granularidade);
        if (!isset($baldes[$chave])) continue;
        $baldes[$chave]['leads']++;
        $valor = valor_pedido($pedido);
        if ($valor) $baldes[$chave]['value'] += $valor;
    }

    return ['granularity' => $granularidade, 'points' => array_values($baldes)];
}

function top_destinos(array $pedidos, int $limite = 8): array
{
    $grupos = [];
    foreach ($pedidos as $pedido) {
        $indeciso = !empty($pedido['trip']['undecided']);
        $chave = $indeciso ? 'indeciso' : ($pedido['trip']['destinationKey'] ?? '');
        if ($chave === '') continue;
        if (!isset($grupos[$chave])) {
            $grupos[$chave] = [
                'key' => $chave,
                'label' => $indeciso ? 'Ainda sem destino' : $pedido['trip']['destination'],
                'value' => 0,
                'amount' => 0,
            ];
        }
        $grupos[$chave]['value']++;
        $valor = valor_pedido($pedido);
        if ($valor) $grupos[$chave]['amount'] += $valor;
    }

    $lista = array_values($grupos);
    usort($lista, fn($a, $b) => $b['value'] <=> $a['value'] ?: strcmp($a['label'], $b['label']));
    $topo = array_slice($lista, 0, $limite);
    $resto = array_slice($lista, $limite);
    if ($resto) {
        $topo[] = [
            'key' => 'outros',
            'label' => 'Outros (' . count($resto) . ')',
            'value' => array_sum(array_column($resto, 'value')),
            'amount' => array_sum(array_column($resto, 'amount')),
        ];
    }
    return $topo;
}

function calcular_estatisticas(array $pedidos, int $de, int $ate): array
{
    $valores = [];
    $porPessoa = [];
    $viajantes = 0;
    $noites = [];
    $porEstado = [];
    $ganhos = [];
    $abertos = 0.0;

    foreach ($pedidos as $pedido) {
        $valor = valor_pedido($pedido);
        if ($valor) {
            $valores[] = $valor;
            $porPessoa[] = $valor / max(1, $pedido['party']['travelers']);
        }
        $viajantes += $pedido['party']['travelers'];
        if (!empty($pedido['trip']['nights'])) $noites[] = $pedido['trip']['nights'];
        $estado = $pedido['status'];
        $porEstado[$estado] = ($porEstado[$estado] ?? 0) + 1;
        if ($estado === 'ganho') $ganhos[] = $valor ?: 0;
        if ($estado !== 'ganho' && $estado !== 'perdido' && $valor) $abertos += $valor;
    }

    $fechados = ($porEstado['ganho'] ?? 0) + ($porEstado['perdido'] ?? 0);
    $total = array_sum($valores);

    $idades = [];
    foreach (catalogo()['ageRanges'] as $faixa) {
        $idades[] = [
            'id' => $faixa['id'],
            'label' => $faixa['label'],
            'value' => count(array_filter($pedidos, fn($p) => ($p['party']['ageRange'] ?? '') === $faixa['id'])),
        ];
    }

    $tipos = [];
    foreach ($pedidos as $pedido) {
        $tipo = $pedido['trip']['type'];
        $tipos[$tipo] = ($tipos[$tipo] ?? 0) + 1;
    }
    $listaTipos = [];
    foreach ($tipos as $id => $contagem) {
        $listaTipos[] = ['id' => $id, 'label' => etiqueta('tripTypes', $id), 'value' => $contagem];
    }
    usort($listaTipos, fn($a, $b) => $b['value'] <=> $a['value']);

    $escaloes = [];
    foreach (catalogo()['budgetRanges'] as $escalao) {
        $contagem = count(array_filter($pedidos, fn($p) => ($p['budget']['range'] ?? '') === $escalao['id']));
        if ($contagem > 0 || $escalao['id'] !== 'sem-limite') {
            $escaloes[] = ['id' => $escalao['id'], 'label' => $escalao['label'], 'value' => $contagem];
        }
    }

    $sazonalidade = array_fill(0, 12, 0);
    foreach ($pedidos as $pedido) {
        $mes = mes_de_partida($pedido);
        if ($mes === '') continue;
        $indice = (int)substr($mes, 5, 2) - 1;
        if ($indice >= 0 && $indice < 12) $sazonalidade[$indice]++;
    }
    $listaSazonalidade = [];
    foreach (MESES as $indice => $nome) {
        $listaSazonalidade[] = ['id' => $nome, 'label' => $nome, 'value' => $sazonalidade[$indice]];
    }

    $funil = [];
    foreach (catalogo()['statuses'] as $estado) {
        $funil[] = ['id' => $estado['id'], 'label' => $estado['label'], 'value' => $porEstado[$estado['id']] ?? 0];
    }

    $origens = [];
    foreach ($pedidos as $pedido) {
        $origem = $pedido['contact']['source'] ?? '';
        if ($origem === '') continue;
        $origens[$origem] = ($origens[$origem] ?? 0) + 1;
    }
    $listaOrigens = [];
    foreach ($origens as $id => $contagem) {
        $listaOrigens[] = ['id' => $id, 'label' => etiqueta('sources', $id), 'value' => $contagem];
    }
    usort($listaOrigens, fn($a, $b) => $b['value'] <=> $a['value']);

    $estadosCompletos = [];
    foreach (catalogo()['statuses'] as $estado) {
        $estadosCompletos[$estado['id']] = $porEstado[$estado['id']] ?? 0;
    }

    return [
        'range' => ['from' => gmdate('c', $de), 'to' => gmdate('c', $ate)],
        'totals' => [
            'leads' => count($pedidos),
            'travelers' => $viajantes,
            'totalValue' => $total,
            'wonValue' => array_sum($ganhos),
            'openValue' => $abertos,
            'won' => count($ganhos),
            'closed' => $fechados,
            'conversionRate' => $fechados ? count($ganhos) / $fechados : null,
            'byStatus' => $estadosCompletos,
        ],
        'kpis' => [
            'avgPerLead' => $valores ? (int)round($total / count($valores)) : null,
            'avgPerPerson' => $porPessoa ? (int)round(array_sum($porPessoa) / count($porPessoa)) : null,
            'avgTravelers' => $pedidos ? round($viajantes / count($pedidos), 1) : null,
            'avgNights' => $noites ? (int)round(array_sum($noites) / count($noites)) : null,
        ],
        'destinations' => top_destinos($pedidos),
        'trend' => serie_temporal($pedidos, $de, $ate),
        'ages' => $idades,
        'tripTypes' => $listaTipos,
        'budgets' => $escaloes,
        'season' => $listaSazonalidade,
        'pipeline' => $funil,
        'sources' => $listaOrigens,
    ];
}

/* ────────────────────────────────  CSV  ────────────────────────────────── */

function celula_csv($valor): string
{
    $texto = $valor === null ? '' : (string)$valor;
    return preg_match('/[";\n]/', $texto) ? '"' . str_replace('"', '""', $texto) . '"' : $texto;
}

function pedidos_para_csv(array $pedidos): string
{
    $colunas = [
        'Referência' => fn($p) => $p['id'],
        'Data do pedido' => fn($p) => date('d/m/Y H:i', strtotime($p['createdAt'])),
        'Estado' => fn($p) => etiqueta('statuses', $p['status']),
        'Nome' => fn($p) => $p['contact']['name'],
        'E-mail' => fn($p) => $p['contact']['email'],
        'Telefone' => fn($p) => $p['contact']['phone'],
        'Canal preferido' => fn($p) => etiqueta('contactChannels', $p['contact']['channel']),
        'Melhor horário' => fn($p) => etiqueta('bestTimes', $p['contact']['bestTime']),
        'Como nos conheceu' => fn($p) => etiqueta('sources', $p['contact']['source'], ''),
        'Tipo de viagem' => fn($p) => etiqueta('tripTypes', $p['trip']['type']),
        'Destino' => fn($p) => $p['trip']['undecided'] ? 'Ainda sem destino' : $p['trip']['destination'],
        'Data de ida' => fn($p) => $p['trip']['startDate'],
        'Data de volta' => fn($p) => $p['trip']['endDate'],
        'Mês aproximado' => fn($p) => $p['trip']['month'],
        'Datas flexíveis' => fn($p) => $p['trip']['flexible'] ? 'Sim' : 'Não',
        'Noites' => fn($p) => $p['trip']['nights'] ?: '',
        'Adultos' => fn($p) => $p['party']['adults'],
        'Crianças' => fn($p) => $p['party']['children'],
        'Idades das crianças' => fn($p) => implode(' / ', $p['party']['childrenAges']),
        'Faixa etária' => fn($p) => etiqueta('ageRanges', $p['party']['ageRange'], ''),
        'Orçamento por pessoa' => fn($p) => etiqueta('budgetRanges', $p['budget']['range'], ''),
        'Orçamento indicado (€)' => fn($p) => $p['budget']['perPerson'] ?? '',
        'Valor estimado (€)' => fn($p) => $p['estimatedValue'] ?? '',
        'Valor orçamentado (€)' => fn($p) => $p['internal']['quotedValue'] ?? '',
        'Inclui' => fn($p) => implode(' / ', array_map(fn($i) => etiqueta('includes', $i), $p['budget']['includes'])),
        'Categoria de hotel' => fn($p) => etiqueta('hotelCategories', $p['prefs']['hotelCategory'], ''),
        'Regime' => fn($p) => etiqueta('boards', $p['prefs']['board'], ''),
        'Ritmo' => fn($p) => etiqueta('paces', $p['prefs']['pace'], ''),
        'Interesses' => fn($p) => implode(' / ', array_map(fn($i) => etiqueta('interests', $i), $p['prefs']['interests'])),
        'Notas do cliente' => fn($p) => $p['prefs']['notes'],
        'Notas internas' => fn($p) => $p['internal']['notes'] ?? '',
        'Marketing autorizado' => fn($p) => $p['consent']['marketing'] ? 'Sim' : 'Não',
    ];

    $linhas = [implode(';', array_map('celula_csv', array_keys($colunas)))];
    foreach ($pedidos as $pedido) {
        $celulas = [];
        foreach ($colunas as $obter) $celulas[] = celula_csv($obter($pedido));
        $linhas[] = implode(';', $celulas);
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $linhas) . "\r\n";
}
