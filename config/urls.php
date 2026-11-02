<?php

declare(strict_types=1);

/**
 * Endereços derivados de APP_URL.
 *
 * Nada aqui depende de uma instalação específica: cada valor pode ser fixado
 * no .env e, se ficar vazio, nasce de APP_URL.
 *   IDE_BASE_URL      padrão APP_URL/ide
 *   PREVIEW_BASE_URL  padrão APP_URL/p
 *   e-mail noreply    padrão noreply@<host de APP_URL>
 */
$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$host   = (string) (parse_url($appUrl, PHP_URL_HOST) ?: 'localhost');

$ide     = trim((string) env('IDE_BASE_URL', ''));
$preview = trim((string) env('PREVIEW_BASE_URL', ''));

return [
    'app'     => $appUrl,
    'host'    => $host,
    'ide'     => rtrim($ide !== '' ? $ide : $appUrl . '/ide', '/'),
    'preview' => rtrim($preview !== '' ? $preview : $appUrl . '/p', '/'),
    'noreply' => 'noreply@' . $host,
];
