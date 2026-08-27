<?php
/**
 * Encaminhador para pré-visualizar o pacote cPanel localmente:
 *
 *   php -S localhost:8000 -t dist/cpanel scripts/router-local.php
 *
 * Faz o mesmo que as regras do .htaccess (que o servidor embutido do PHP
 * não lê). Não é usado no alojamento real.
 */
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raiz = $_SERVER['DOCUMENT_ROOT'];

$mapa = [
    '/api/public/config' => '/api/config.php',
    '/api/leads' => '/api/leads.php',
    '/admin' => '/admin.html',
    '/backoffice' => '/admin.html',
    '/privacidade' => '/privacidade.html',
    '/politica-de-privacidade' => '/privacidade.html',
];

$normalizado = rtrim($caminho, '/') ?: '/';
if (isset($mapa[$normalizado])) {
    $_SERVER['SCRIPT_NAME'] = $mapa[$normalizado];
    if (substr($mapa[$normalizado], -4) === '.php') {
        require $raiz . $mapa[$normalizado];
        return true;
    }
    readfile($raiz . $mapa[$normalizado]);
    header('Content-Type: text/html; charset=utf-8');
    return true;
}

if (strpos($caminho, '/api/admin') === 0) {
    require $raiz . '/api/admin.php';
    return true;
}

if ($caminho === '/' || $caminho === '') {
    require $raiz . '/index.php';
    return true;
}

// Pastas → index.html (páginas de destino)
if (is_dir($raiz . $caminho) && is_file(rtrim($raiz . $caminho, '/') . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(rtrim($raiz . $caminho, '/') . '/index.html');
    return true;
}

return false;   // ficheiro estático: o servidor embutido trata dele
