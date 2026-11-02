#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Alerta de trabalho longo na IDE. Roda de minuto em minuto (cron) e, lendo o estado de
 * cada IDE (working/waiting/idle) sem interferir nos containers, dispara um WhatsApp quando
 * um trabalho que durou MAIS que o limite do usuário (default 10 min) termina (idle) ou pausa
 * aguardando o usuário (waiting). Diferencia os dois pela bolinha verde/azul que já usamos.
 *
 *   * * * * * cd /caminho/da/instalacao && php bin/ide-alert-monitor.php >> storage/logs/ide-alert.log 2>&1
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

require_once SRC_PATH . '/Helpers/helpers.php';
require_once SRC_PATH . '/Helpers/uuid.php';
require_once SRC_PATH . '/Helpers/formatting.php';

foreach (['app', 'database', 'claude', 'sandbox', 'tools', 'github'] as $cfg) {
    $cfgPath = CONFIG_PATH . '/' . $cfg . '.php';
    if (is_file($cfgPath)) { $GLOBALS['config'][$cfg] = require $cfgPath; }
}
\Ravi\Core\Logger::getInstance()->setLogPath(STORAGE_PATH . '/logs');
$app = \Ravi\Core\Application::create(ROOT_PATH);
$app->bind('logger', fn () => \Ravi\Core\Logger::getInstance());

use Ravi\Core\Database;
use Ravi\Models\UserPref;
use Ravi\Services\IdeService;
use Ravi\Services\NotificationService;

try {
    $db = Database::getInstance();

    // Sessões marcadas como rodando + dono + projeto (nome/uuid para a mensagem).
    $rows = $db->fetchAll(
        "SELECT s.id, s.container, s.user_id, s.working_since, p.uuid, p.name
           FROM ide_sessions s
           JOIN projects p ON p.id = s.project_id
          WHERE s.status = 'running'"
    );
    if (!$rows) { exit(0); }

    // Containers REALMENTE rodando agora (evita falso "finalizou" quando o container morreu/pausou).
    $running = [];
    $psOut = shell_exec("docker ps --format '{{.Names}}' 2>/dev/null") ?: '';
    foreach (explode("\n", trim($psOut)) as $n) {
        if ($n !== '') { $running[$n] = true; }
    }

    // Estado (working/waiting/idle) em LOTE só dos que estão vivos — mesma leitura do poll da UI.
    $live = [];
    foreach ($rows as $r) {
        if (isset($running[$r['container']])) { $live[] = $r['container']; }
    }
    $states = $live ? (new IdeService())->containerStates($live) : [];

    $now  = date('Y-m-d H:i:s');
    $sent = 0;

    foreach ($rows as $r) {
        $alive = isset($running[$r['container']]);
        $state = $alive ? ($states[$r['container']] ?? 'idle') : 'gone';
        $since = $r['working_since'] ?? null;

        if ($state === 'working') {
            // Abre a janela de trabalho na primeira amostra que vê "working".
            if (empty($since)) {
                $db->query("UPDATE ide_sessions SET working_since = ? WHERE id = ?", [$now, (int) $r['id']]);
            }
            continue;
        }

        // Não está working. Sem janela aberta → nada a avaliar.
        if (empty($since)) { continue; }

        // A janela fechou: zera sempre.
        $db->query("UPDATE ide_sessions SET working_since = NULL WHERE id = ?", [(int) $r['id']]);

        // Container morreu/pausou no meio: não é um fim de trabalho real → não alerta.
        if ($state === 'gone') { continue; }

        $elapsedMin = (int) floor((strtotime($now) - strtotime((string) $since)) / 60);
        $threshold  = UserPref::ideAlertMinutes((int) $r['user_id']);
        if ($elapsedMin >= $threshold) {
            (new NotificationService())->ideLongJobEnded(
                (int) $r['user_id'],
                (string) $r['name'],
                (string) $r['uuid'],
                $state === 'waiting' ? 'waiting' : 'idle',
                $elapsedMin
            );
            $sent++;
        }
    }

    if ($sent > 0) {
        echo date('Y-m-d H:i:s') . " — alertas enviados: {$sent}\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' — erro: ' . $e->getMessage() . "\n");
    exit(1);
}
