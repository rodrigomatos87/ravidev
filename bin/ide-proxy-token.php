#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Resolver de handle → token real, para o PROXY da IDE (bin/ide-proxy.py).
 *
 * O proxy do host NUNCA decifra credencial: ele passa o HANDLE recebido do container aqui, e este
 * script (que roda no host, fora de qualquer container) devolve o CLAUDE_CODE_OAUTH_TOKEN real da
 * licença amarrada ao handle. Assim o decrypt (APP_KEY) fica 100% no PHP — padrão do projeto.
 *
 * Uso:  php bin/ide-proxy-token.php <handle>
 * Saída: o token cru em stdout (sem newline) + exit 0; ou nada + exit 1 se handle inválido/revogado
 *        ou licença inativa; ou nada + exit 3 se a licença está no TETO de uso configurado pelo
 *        admin (stderr traz limit_block=<uso>:<teto>:<reset> e o proxy devolve 429 claro).
 *        Nunca loga o token.
 *
 * Só deve ser chamado no HOST (não é exposto pela web; fica em bin/, fora do document root).
 */

require __DIR__ . '/../src/bootstrap.php';

use Ravi\Core\Database;
use Ravi\Services\LicenseService;

$handle = $argv[1] ?? '';
if ($handle === '' || strlen($handle) > 128) {
    fwrite(STDERR, "handle ausente/invalido\n");
    exit(1);
}

try {
    $row = Database::getInstance()->fetch(
        'SELECT license_id FROM ide_proxy_handles WHERE handle = ? AND revoked_at IS NULL LIMIT 1',
        [$handle]
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'erro db: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$row) {
    exit(1); // handle desconhecido ou revogado
}

$licId = (int) $row['license_id'];
$token = LicenseService::token($licId); // '' se licença inativa/ausente
if ($token === '') {
    exit(1);
}

// Teto de uso configurado pelo admin (IDE/Dev Autônomo): licença no teto → exit 3 (código
// próprio) e o proxy devolve 429 com mensagem clara, em vez de 401 de handle inválido.
// Checagem SEM sonda (cache + leitura de passagem): este script está no caminho do request.
$limit = LicenseService::usageLimitPct($licId);
if ($limit !== null) {
    fwrite(STDERR, 'usage_limit=' . $limit . "\n"); // o proxy usa para o corte ao vivo
    $blk = LicenseService::usageBlocked($licId);
    if ($blk !== null) {
        fwrite(STDERR, 'limit_block=' . (int) $blk['pct'] . ':' . $limit . ':' . (int) ($blk['reset'] ?? 0) . "\n");
        exit(3);
    }
}

// Marca uso (best-effort; não bloqueia a resposta).
try {
    Database::getInstance()->query('UPDATE ide_proxy_handles SET last_used_at = NOW() WHERE handle = ?', [$handle]);
} catch (\Throwable $e) { /* ignore */ }

// Id da licença vai por STDERR (stdout continua sendo só o token): o proxy usa para anotar,
// por licença, os headers de uso do plano das respostas (gráficos de Admin → Licenças).
fwrite(STDERR, 'license_id=' . $licId . "\n");
fwrite(STDOUT, $token);
exit(0);
