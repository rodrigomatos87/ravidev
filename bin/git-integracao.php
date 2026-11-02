#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Gestão dos handles de INTEGRAÇÃO do proxy git (git_integration_handles): deixa outro sistema
 * integrador (outro sistema seu) usar git nos repos dos projetos do RaviDev via a instância LAN do
 * proxy (ravi-git-proxy-lan), sem nunca receber o token do GitHub.
 *
 * Uso (no HOST, como no restante de bin/):
 *   php bin/git-integracao.php criar <user_id> "<label>" [--escrita] [--repos=owner/a,owner/b]
 *       Gera o handle e o IMPRIME UMA ÚNICA VEZ (guarde no cofre do consumidor). Sem --repos,
 *       o escopo é TODOS os projetos git acessíveis ao usuário.
 *   php bin/git-integracao.php listar
 *   php bin/git-integracao.php revogar <id>
 */

require __DIR__ . '/../src/bootstrap.php';

use Ravi\Core\Database;
use Ravi\Models\User;

$acao = $argv[1] ?? '';
$db = Database::getInstance();

if ($acao === 'criar') {
    $userId = (int) ($argv[2] ?? 0);
    $label  = trim((string) ($argv[3] ?? ''));
    if ($userId <= 0 || $label === '' || !User::find($userId)) {
        fwrite(STDERR, "uso: criar <user_id> \"<label>\" [--escrita] [--repos=owner/a,owner/b]\n");
        exit(1);
    }
    $escrita = false;
    $repos = null;
    foreach (array_slice($argv, 4) as $arg) {
        if ($arg === '--escrita') {
            $escrita = true;
        } elseif (str_starts_with($arg, '--repos=')) {
            $lista = array_values(array_filter(array_map('trim', explode(',', substr($arg, 8)))));
            foreach ($lista as $r) {
                if (!preg_match('#^[\w.-]+/[\w.-]+$#', $r)) {
                    fwrite(STDERR, "repo invalido: $r (use owner/nome)\n");
                    exit(1);
                }
            }
            $repos = json_encode($lista, JSON_UNESCAPED_SLASHES);
        }
    }
    $handle = 'rgit_' . bin2hex(random_bytes(24));
    $db->query(
        'INSERT INTO git_integration_handles (handle, label, user_id, repos, can_write) VALUES (?, ?, ?, ?, ?)',
        [$handle, $label, $userId, $repos, $escrita ? 1 : 0]
    );
    echo "Integração criada para \"$label\" (user $userId, " . ($escrita ? 'leitura+escrita' : 'somente leitura')
        . ', escopo ' . ($repos === null ? 'TODOS os projetos git acessíveis' : $repos) . ").\n";
    echo "Handle (guarde AGORA; não é mostrado de novo):\n$handle\n";
    exit(0);
}

if ($acao === 'listar') {
    $rows = $db->fetchAll('SELECT id, handle, label, user_id, repos, can_write, last_used_at, revoked_at, created_at FROM git_integration_handles ORDER BY id');
    if (!$rows) {
        echo "nenhuma integração cadastrada\n";
        exit(0);
    }
    foreach ($rows as $r) {
        printf(
            "#%d %s [%s] user=%d escrita=%s escopo=%s criado=%s ultimo_uso=%s%s\n",
            (int) $r['id'],
            $r['label'],
            substr((string) $r['handle'], 0, 12) . '…',
            (int) $r['user_id'],
            ((int) $r['can_write']) ? 'sim' : 'nao',
            $r['repos'] === null ? 'todos' : (string) $r['repos'],
            (string) $r['created_at'],
            (string) ($r['last_used_at'] ?? '-'),
            $r['revoked_at'] !== null ? ' REVOGADO' : ''
        );
    }
    exit(0);
}

if ($acao === 'revogar') {
    $id = (int) ($argv[2] ?? 0);
    if ($id <= 0) {
        fwrite(STDERR, "uso: revogar <id>\n");
        exit(1);
    }
    $db->query('UPDATE git_integration_handles SET revoked_at = NOW() WHERE id = ?', [$id]);
    echo "integração #$id revogada (proxy recusa em até 30s)\n";
    exit(0);
}

fwrite(STDERR, "uso: php bin/git-integracao.php criar|listar|revogar ...\n");
exit(1);
