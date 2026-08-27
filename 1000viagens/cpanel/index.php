<?php
/**
 * Página inicial — servida com o conteúdo já em HTML.
 *
 * O ficheiro index.html continua a ser a única fonte da estrutura; aqui apenas
 * se preenchem os marcadores com o que está guardado no backoffice.
 */
declare(strict_types=1);
require_once __DIR__ . '/api/_render.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');

echo render_pagina_inicial();
