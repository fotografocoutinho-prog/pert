<?php

/*
| Adaptador para cPanel quando a raiz do domínio TEM de ser public_html e não é
| possível apontá-la para obraconnect/public nem criar um symlink.
|
| Estrutura esperada (a aplicação fica sempre fora da pasta pública):
|   /home/UTILIZADOR/obraconnect/                       ← aplicação
|   /home/UTILIZADOR/public_html/                       ← raiz do domínio, ou
|   /home/UTILIZADOR/public_html/obras/obraconnect/     ← subpasta (ex.: www.coda.pt/obras/obraconnect)
| com o conteúdo de obraconnect/public + este index.php.
|
| A pasta "obraconnect" é procurada nas pastas acima desta. Se tiver outro nome
| ou outro sítio, defina $app_path manualmente.
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

$app_path = null;
for ($dir = dirname(__DIR__); $dir !== dirname($dir); $dir = dirname($dir)) {
    if (is_file($dir.'/obraconnect/bootstrap/app.php') && realpath($dir.'/obraconnect') !== realpath(__DIR__)) {
        $app_path = $dir.'/obraconnect';
        break;
    }
}
if ($app_path === null) {
    http_response_code(500);
    exit('ObraConnect: pasta da aplicação não encontrada. Edite $app_path em index.php.');
}

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = $app_path.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_path.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_path.'/bootstrap/app.php';

// Os ficheiros compilados (build/) e o manifesto ficam nesta pasta.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
