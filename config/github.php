<?php

declare(strict_types=1);

/**
 * Integração GitHub (OAuth App clássico).
 *
 * SETUP (uma vez, pelo admin da plataforma):
 *   1. https://github.com/settings/developers → "New OAuth App"
 *   2. Homepage URL:        https://seu-dominio.com.br (o APP_URL)
 *      Authorization callback URL: https://seu-dominio.com.br/settings/github/callback
 *   3. Copie Client ID e gere um Client Secret → cole no .env:
 *        GITHUB_CLIENT_ID=...
 *        GITHUB_CLIENT_SECRET=...
 *
 * Depois disso é self-service: cada usuário conecta a própria conta em /settings/github.
 */
$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');

return [
    'client_id'     => (string) env('GITHUB_CLIENT_ID', ''),
    'client_secret' => (string) env('GITHUB_CLIENT_SECRET', ''),
    // 'repo' cobre repositórios privados (necessário p/ clonar/empurrar privados).
    'scope'         => (string) env('GITHUB_SCOPE', 'repo'),
    'redirect_uri'  => $appUrl . '/settings/github/callback',
];
