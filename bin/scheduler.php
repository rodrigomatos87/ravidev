#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * #6 — Runner de agendamentos. Roda de minuto em minuto (cron) e executa os agendamentos vencidos.
 *   * * * * * cd /caminho/da/instalacao && php bin/scheduler.php >> storage/logs/scheduler.log 2>&1
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

try {
    $n = (new \Ravi\Services\ScheduleService())->runDue();
    if ($n > 0) {
        echo date('Y-m-d H:i:s') . " — executados: {$n}\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' — erro: ' . $e->getMessage() . "\n");
    exit(1);
}

// Agentes SSH das IDEs (chaves no host): ressuscita os que morreram (reboot/restart de serviço)
// e recolhe órfãos. Barato (1 ssh-add -l por sessão rodando); best-effort, nunca derruba o cron.
try {
    $r = (new \Ravi\Services\IdeSshAgentService())->reconcile();
    if (($r['revived'] ?? 0) > 0 || ($r['orphans'] ?? 0) > 0) {
        echo date('Y-m-d H:i:s') . " — ssh-agents: revividos {$r['revived']}, órfãos {$r['orphans']}\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' — ssh-agents erro: ' . $e->getMessage() . "\n");
}

// Licenças do ChatGPT (Codex) nas IDEs: puxa o auth.json que o Codex renovou (o refresh token
// antigo morre na renovação → o banco precisa da versão nova) e empurra o do banco para IDEs com
// cópia velha ou licença trocada no painel. Só IDEs em estado running; no-op sem licenças.
try {
    $g = \Ravi\Services\ChatgptLicenseService::reconcile();
    if (($g['pulled'] ?? 0) > 0 || ($g['pushed'] ?? 0) > 0 || ($g['removed'] ?? 0) > 0) {
        echo date('Y-m-d H:i:s') . " — chatgpt: verificadas {$g['checked']}, renovadas {$g['pulled']}, injetadas {$g['pushed']}, removidas {$g['removed']}\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' — chatgpt erro: ' . $e->getMessage() . "\n");
}

// Licenças do Claude nas IDEs: reamarra o handle (ide_proxy_handles) já vivo à conta ATUAL do
// usuário. Sem isso, trocar de conta em Configurações → Licenças com a IDE já aberta só valia na
// próxima abertura. Só atualiza uma linha no banco (o proxy resolve o token por requisição).
try {
    $c = (new \Ravi\Services\IdeService())->reconcileClaudeHandles();
    if (($c['changed'] ?? 0) > 0) {
        echo date('Y-m-d H:i:s') . " — claude: verificados {$c['checked']}, trocados {$c['changed']}\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' — claude licença erro: ' . $e->getMessage() . "\n");
}
