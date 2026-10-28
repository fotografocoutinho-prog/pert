<?php

/*
| Adaptador para cPanel quando a raiz do domínio TEM de ser public_html e não é
| possível apontá-la para obraconnect/public nem criar um symlink.
|
| Estrutura esperada:
|   /home/UTILIZADOR/obraconnect/   ← aplicação (fora da pasta pública)
|   /home/UTILIZADOR/public_html/   ← conteúdo de obraconnect/public + este index.php
|
| Ajuste $app_path se a pasta da aplicação tiver outro nome.
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

$app_path = dirname(__DIR__).'/obraconnect';

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
