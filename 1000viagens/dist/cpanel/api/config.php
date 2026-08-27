<?php
/** GET /api/public/config — definições públicas e catálogo do formulário. */
declare(strict_types=1);
require_once __DIR__ . '/_nucleo.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') erro(405, 'Método não permitido.');

responder(200, [
    'settings' => definicoes_publicas(definicoes()),
    'catalog' => catalogo(),
], ['Cache-Control' => 'no-cache']);
