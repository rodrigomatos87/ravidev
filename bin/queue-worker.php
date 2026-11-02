#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ravi Developer - Queue Worker
 * 
 * Processes background jobs from the queue.
 * Currently uses database-based queue, can be switched to Redis.
 * 
 * Usage:
 *   php bin/queue-worker.php
 *   php bin/queue-worker.php --queue=default
 *   php bin/queue-worker.php --sleep=3 --tries=3
 */

// ============================================================================
// BOOTSTRAP
// ============================================================================

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// ============================================================================
// CONSTANTS
// ============================================================================

define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// ============================================================================
// LOAD HELPERS
// ============================================================================

require_once SRC_PATH . '/Helpers/helpers.php';
require_once SRC_PATH . '/Helpers/uuid.php';
require_once SRC_PATH . '/Helpers/formatting.php';

// ============================================================================
// APPLICATION (container + logger) — ANTES de qualquer saída (Session::start usa ini_set)
// ============================================================================

foreach (['app', 'database', 'claude', 'sandbox', 'tools', 'github'] as $cfg) {
    $cfgPath = CONFIG_PATH . '/' . $cfg . '.php';
    if (is_file($cfgPath)) { $GLOBALS['config'][$cfg] = require $cfgPath; }
}
\Ravi\Core\Logger::getInstance()->setLogPath(STORAGE_PATH . '/logs');
$app = \Ravi\Core\Application::create(ROOT_PATH);
$app->bind('logger', fn() => \Ravi\Core\Logger::getInstance());
$app->bind('config', fn() => $GLOBALS['config'] ?? []);
$app->bind('db', fn() => \Ravi\Core\Database::getInstance());

// ============================================================================
// CONFIGURATION
// ============================================================================

$options = getopt('', ['queue:', 'sleep:', 'tries:', 'timeout:', 'once', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Ravi Developer Queue Worker

Usage:
  php bin/queue-worker.php [options]

Options:
  --queue=NAME   Queue name to process (default: default)
  --sleep=SEC    Seconds to sleep when no jobs (default: 3)
  --tries=NUM    Maximum attempts per job (default: 3)
  --timeout=SEC  Maximum job execution time (default: 60)
  --once         Process one job and exit
  --help         Show this help message

Examples:
  php bin/queue-worker.php
  php bin/queue-worker.php --queue=agent --tries=5
  php bin/queue-worker.php --once

HELP;
    exit(0);
}

$queueName = $options['queue'] ?? 'default';
$sleepSeconds = (int) ($options['sleep'] ?? 3);
$maxTries = (int) ($options['tries'] ?? 3);
$timeout = (int) ($options['timeout'] ?? 60);
$runOnce = isset($options['once']);

// ============================================================================
// TIMEZONE
// ============================================================================

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

// ============================================================================
// LOGGING
// ============================================================================

function queueLog(string $level, string $message, array $context = []): void
{
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = empty($context) ? '' : ' ' . json_encode($context);
    $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";
    
    echo $line;
    
    $logFile = STORAGE_PATH . '/logs/queue.log';
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// ============================================================================
// SIGNAL HANDLERS
// ============================================================================

$shouldQuit = false;

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shouldQuit) {
        queueLog('info', 'Received SIGTERM, finishing current job...');
        $shouldQuit = true;
    });
    
    pcntl_signal(SIGINT, function () use (&$shouldQuit) {
        queueLog('info', 'Received SIGINT, finishing current job...');
        $shouldQuit = true;
    });
}

// ============================================================================
// BANNER
// ============================================================================

echo <<<BANNER

  ╔═══════════════════════════════════════════════════════════════╗
  ║                                                               ║
  ║   🌟  RAVI DEVELOPER - Queue Worker                           ║
  ║                                                               ║
  ╚═══════════════════════════════════════════════════════════════╝

BANNER;

queueLog('info', 'Starting queue worker...', [
    'queue' => $queueName,
    'sleep' => $sleepSeconds,
    'max_tries' => $maxTries
]);

// ============================================================================
// DATABASE CONNECTION
// ============================================================================

try {
    $db = \Ravi\Core\Database::getInstance();
    queueLog('info', 'Database connection established');
} catch (\Exception $e) {
    queueLog('error', 'Database connection failed', ['error' => $e->getMessage()]);
    exit(1);
}

// ============================================================================
// JOB HANDLERS
// ============================================================================

/**
 * Register job handlers here
 */
$jobHandlers = [
    'process_agent_task' => function (array $payload) use ($db) {
        $conversationId = $payload['conversation_id'] ?? null;
        $message = $payload['content'] ?? $payload['message'] ?? '';
        $userId = $payload['user_id'] ?? null;
        $mode = $payload['mode'] ?? 'multi';

        if (!$conversationId) {
            throw new \Exception('Missing conversation_id in payload');
        }

        queueLog('info', 'Processing agent task', ['conversation_id' => $conversationId, 'mode' => $mode]);

        $conversation = \Ravi\Models\Conversation::find($conversationId);
        if (!$conversation) {
            throw new \Exception("Conversation not found: {$conversationId}");
        }
        $user = $userId ? \Ravi\Models\User::find($userId) : null;

        // Canal de eventos -> tabela agent_events (o servidor WebSocket drena e envia ao cliente)
        $emit = function (array $data) use ($db, $conversationId) {
            try {
                $db->query(
                    "INSERT INTO agent_events (conversation_id, payload) VALUES (?, ?)",
                    [$conversationId, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]
                );
            } catch (\Throwable $e) { /* não interrompe o agente */ }
            // Espelho de observação (não compete com o DELETE do WebSocket). Útil para acompanhar runs.
            // Rotação simples: ao passar de ~5MB, gira para .1 (mantém no máximo ~10MB no total).
            $streamLog = STORAGE_PATH . '/logs/maestro-stream.log';
            if (@filesize($streamLog) > 5_000_000) {
                @rename($streamLog, $streamLog . '.1');
            }
            @file_put_contents(
                $streamLog,
                date('H:i:s') . ' c' . $conversationId . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );
        };

        $sandbox = new \Ravi\Services\SandboxService();

        // ── Maestro (cérebro autônomo: plano → loop de fases com portões) ────────
        if (filter_var(env('MAESTRO_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $emit(['type' => 'agent_mode_info', 'mode' => 'maestro', 'message' => 'Arquiteto — desenvolvimento autônomo até a entrega']);
            try {
                $maestro = new \Ravi\Services\MaestroService($sandbox);
                $maestro->setActingUser((int) ($userId ?? 0)); // custo atribuído a quem disparou a tarefa
                // Coleta a narração (passos/fala dos agentes) para PERSISTIR no chat — hoje some no reload.
                $narration = [];
                // Persiste CADA fala de agente como mensagem real (reconstrói o histórico no reload).
                $lastAgentText = '';
                // Arquivo de narração AO VIVO: gravado durante o build (throttle) para que, ao
                // recarregar a página no meio da tarefa, a narração até aqui seja restaurada.
                $liveNarrFile = null;
                try {
                    $liveProj = \Ravi\Models\Project::find($conversation->project_id);
                    if ($liveProj) {
                        $liveDir = STORAGE_PATH . '/sandbox/' . $liveProj->uuid . '/.maestro';
                        @mkdir($liveDir, 0777, true);
                        $liveNarrFile = $liveDir . '/live-' . $conversationId . '.json';
                    }
                } catch (\Throwable $e) {}
                $lastLiveWrite = 0.0;
                $maestro->onUpdate(function ($data) use ($emit, &$narration, $liveNarrFile, &$lastLiveWrite, $conversation, &$lastAgentText) {
                    $emit($data);
                    $t = $data['type'] ?? '';

                    // Só as falas do ARQUITETO para o USUÁRIO (marcadas com persist=true: plano,
                    // entrega, respostas, recados incorporados) viram mensagem permanente. A narração
                    // interna dos agentes NÃO persiste — flui só como status efêmero (atualiza e some).
                    if ($t === 'agent_text' && !empty($data['persist'])) {
                        $atext = trim((string) ($data['text'] ?? ''));
                        if ($atext !== '' && $atext !== $lastAgentText) {
                            $meta = null;
                            if (!empty($data['agent']) && is_array($data['agent'])) {
                                $meta = ['agent' => [
                                    'key'     => (string) ($data['agent']['key'] ?? ''),
                                    'name'    => (string) ($data['agent']['name'] ?? ''),
                                    'initial' => (string) ($data['agent']['initial'] ?? ''),
                                    'color'   => (string) ($data['agent']['color'] ?? ''),
                                ]];
                            }
                            try { \Ravi\Models\Message::createAssistantMessage($conversation->id, $atext, null, 0, $meta); } catch (\Throwable $eM) {}
                            $lastAgentText = $atext;
                        }
                    }

                    if (in_array($t, ['step', 'agent_text', 'phase'], true)) {
                        $text = ($t === 'phase')
                            ? trim((string) ($data['phase'] ?? '') . ' — ' . (string) ($data['status'] ?? ''), ' —')
                            : trim((string) ($data['text'] ?? ''));
                        if ($text !== '') {
                            $line = ['type' => $t, 'text' => mb_substr($text, 0, 600)];
                            if (!empty($data['agent']) && is_array($data['agent'])) {
                                $line['agent'] = [
                                    'name'    => (string) ($data['agent']['name'] ?? ''),
                                    'initial' => (string) ($data['agent']['initial'] ?? ''),
                                    'color'   => (string) ($data['agent']['color'] ?? ''),
                                ];
                            }
                            $narration[] = $line;
                            if (count($narration) > 400) { array_shift($narration); }

                            // Persiste ao vivo (no máx. a cada ~2s) para sobreviver ao reload.
                            if ($liveNarrFile) {
                                $now = microtime(true);
                                if ($now - $lastLiveWrite > 2.0) {
                                    $lastLiveWrite = $now;
                                    @file_put_contents($liveNarrFile, json_encode($narration, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                                }
                            }
                        }
                    }
                });
                // Zera o painel de agentes ao vivo no cliente ANTES de começar: se um run anterior
                // morreu sem emitir agent_done (ex.: worker reiniciou no meio), os cards ficam órfãos
                // na tela. Este reset garante que a barra comece limpa a cada run/retomada.
                $emit(['type' => 'agents_reset']);
                $result = $maestro->executeTask($conversation, $message);

                // Libera o status da conversa — não pode ficar travado em 'running'.
                // Entrega concluída → 'completed'; plano aguardando aprovação, cancelado ou
                // sem entrega → 'active' (ocioso, aceita novas mensagens).
                try {
                    if (!empty($result['completed'])) {
                        $conversation->complete();
                    } else {
                        $conversation->update(['status' => 'active']);
                    }
                } catch (\Throwable $eStatus) {
                    queueLog('warning', 'Falha ao resetar status da conversa', ['conversation_id' => $conversationId, 'error' => $eStatus->getMessage()]);
                }

                // Notificação por e-mail de "tarefa concluída" (respeita o toggle do usuário + SMTP).
                if (!empty($result['completed'])) {
                    try { (new \Ravi\Services\NotificationService())->taskCompleted((int) ($userId ?? 0), $conversation); } catch (\Throwable $eN) {}
                }

                // Persiste a resposta do assistente no histórico (senão o reload perde tudo).
                $assistantText = (string) ($result['summary'] ?? '');
                if ($assistantText === '' && !empty($result['completed'])) {
                    $assistantText = '🎉 Projeto entregue! Os arquivos estão no painel à direita para download.';
                }
                // Se o resumo final já foi salvo como a última fala do agente, não duplica —
                // só anexa a narração (passos) à mensagem que já existe seria ideal, mas evitar o
                // dup já resolve o histórico repetido. Salva só quando for um texto novo.
                if ($assistantText !== '' && trim($assistantText) === trim($lastAgentText)) {
                    $assistantText = '';
                }
                if ($assistantText !== '') {
                    try {
                        \Ravi\Models\Message::createAssistantMessage(
                            $conversation->id,
                            $assistantText,
                            null,
                            0,
                            !empty($narration) ? ['narration' => $narration] : null
                        );
                    } catch (\Throwable $e) {}
                }

                // Narração ao vivo já não é mais necessária (a final foi persistida na mensagem).
                if ($liveNarrFile) { @unlink($liveNarrFile); }

                // Não emite "task_completed" quando é só o plano aguardando aprovação
                // (senão a UI mostra "Tarefa concluída" antes de começar o desenvolvimento).
                if (empty($result['awaiting_approval'])) {
                    $emit(['type' => 'task_completed', 'result' => $result, 'agent_mode' => 'maestro']);
                }
                queueLog('info', 'Maestro task finished', ['conversation_id' => $conversationId, 'stage' => $result['stage'] ?? '?']);
                return $result;
            } catch (\Throwable $e) {
                if (!empty($liveNarrFile)) { @unlink($liveNarrFile); }
                try { $conversation->update(['status' => 'failed']); } catch (\Throwable $e2) {}
                if (((int) ($payload['_attempt'] ?? 1)) >= ((int) ($payload['_max_attempts'] ?? 1))) {
                    try { (new \Ravi\Services\NotificationService())->taskFailed((int) ($userId ?? 0), $conversation, $e->getMessage()); } catch (\Throwable $eN) {}
                }
                $emit(['type' => 'error', 'message' => 'Erro no Maestro: ' . $e->getMessage()]);
                queueLog('error', 'Maestro task failed', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
                throw $e;
            }
        }

        // ── Caminho Claude Code (Opus 4.8 no sandbox) ────────────────────────────
        // Quando habilitado, ignora o ClaudeService/MultiAgentService e roda o
        // Claude Code CLI dentro do container do projeto (próprio loop + ferramentas).
        if (filter_var(env('CLAUDE_CODE_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $emit(['type' => 'agent_mode_info', 'mode' => 'claude_code', 'message' => '🤖 Claude Code (Opus 4.8) no sandbox isolado']);
            try {
                $runner = new \Ravi\Services\ClaudeCodeService($sandbox);
                $runner->onUpdate(function ($data) use ($emit) { $emit($data); });
                $result = $runner->executeTask($conversation, $message);
                $emit(['type' => 'task_completed', 'result' => $result, 'agent_mode' => 'claude_code']);
                queueLog('info', 'Claude Code task completed', ['conversation_id' => $conversationId]);
                return $result;
            } catch (\Throwable $e) {
                $emit(['type' => 'error', 'message' => 'Erro no Claude Code: ' . $e->getMessage()]);
                queueLog('error', 'Claude Code task failed', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
                throw $e;
            }
        }

        // Usa a API key do usuário (ou a padrão do sistema)
        $claude = ($user && $user->api_key_encrypted)
            ? new \Ravi\Services\ClaudeService($user->api_key_encrypted)
            : new \Ravi\Services\ClaudeService();
        $registry = \Ravi\Agent\ToolRegistry::getInstance();
        $claude->setTools($registry->getApiDefinitions());

        try {
            if ($mode === 'single') {
                $emit(['type' => 'agent_mode_info', 'mode' => 'single', 'message' => '🤖 Iniciando agente único']);
                $agent = new \Ravi\Services\AgentService($claude, $sandbox, $registry);
            } else {
                $emit(['type' => 'agent_mode_info', 'mode' => 'multi', 'message' => '🤖 Iniciando sistema multi-agente (Planner → Coder → Reviewer → Tester)']);
                $agent = new \Ravi\Services\MultiAgentService($claude, $sandbox, $registry);
            }

            $agent->onUpdate(function ($data) use ($emit) { $emit($data); });

            $result = $agent->executeTask($conversation, $message);

            $emit(['type' => 'task_completed', 'result' => $result, 'agent_mode' => $mode]);
            queueLog('info', 'Agent task completed', ['conversation_id' => $conversationId]);
            return $result;
        } catch (\Throwable $e) {
            $emit(['type' => 'error', 'message' => 'Erro ao executar tarefa: ' . $e->getMessage()]);
            queueLog('error', 'Agent task failed', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
            throw $e;
        }
    },
    
    // ── Importação inteligente de projeto (.zip) — roda em background ─────────
    'process_project_import' => function (array $payload) {
        $projectId   = (int) ($payload['project_id'] ?? 0);
        $projectUuid = (string) ($payload['project_uuid'] ?? '');
        $source      = (string) ($payload['source'] ?? 'zip');
        $zipPath     = (string) ($payload['zip_path'] ?? '');

        if ($projectUuid === '' || ($source === 'zip' && $zipPath === '')) {
            queueLog('error', 'Import job inválido', ['project_id' => $projectId]);
            return ['ok' => false, 'error' => 'payload inválido'];
        }

        queueLog('info', 'Project import started', ['project_id' => $projectId, 'uuid' => $projectUuid, 'source' => $source]);
        try {
            $sandbox = new \Ravi\Services\SandboxService();
            $importer = new \Ravi\Services\ProjectImportService($sandbox);
            if ($source === 'git') {
                // Clona do GitHub usando o token do DONO (carregado aqui, nunca no payload).
                $project = \Ravi\Models\Project::find($projectId);
                $owner   = $project ? $project->user() : null;
                $token   = $owner ? $owner->getGithubToken() : null;
                if (!$token) {
                    throw new \Exception('Dono sem GitHub conectado para clonar.');
                }
                $result = $importer->processGit(
                    $projectUuid,
                    (string) ($payload['repo_full_name'] ?? ''),
                    $token,
                    (string) ($payload['branch'] ?? 'main'),
                    (string) ($payload['instructions'] ?? '')
                );
                queueLog('info', 'Project import finished', ['uuid' => $projectUuid, 'stage' => $result['stage'] ?? '?']);
                return $result;
            }
            $result = $importer->process($projectUuid, $zipPath, (string) ($payload['instructions'] ?? ''));
            queueLog('info', 'Project import finished', ['uuid' => $projectUuid, 'stage' => $result['stage'] ?? '?']);
            return $result;
        } catch (\Throwable $e) {
            // Garante que o estado não fique preso em "running" (a tela libera os chats).
            try {
                $ws = STORAGE_PATH . '/sandbox/' . $projectUuid;
                $sf = $ws . '/.maestro/import.json';
                if (is_file($sf)) {
                    $data = json_decode((string) @file_get_contents($sf), true) ?: [];
                    $data['status'] = 'failed';
                    $data['error']  = $e->getMessage();
                    $data['finished_at'] = date('c');
                    @file_put_contents($sf, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            } catch (\Throwable $ignore) {}
            @unlink($zipPath);
            queueLog('error', 'Project import failed', ['uuid' => $projectUuid, 'error' => $e->getMessage()]);
            throw $e;
        }
    },

    'send_email' => function (array $payload) {
        $to = $payload['to'] ?? '';
        $subject = $payload['subject'] ?? '';
        $body = $payload['body'] ?? '';
        
        queueLog('info', 'Sending email', ['to' => $to, 'subject' => $subject]);
        
        // Implement email sending logic here
        // mail($to, $subject, $body);
        
        return ['sent' => true];
    },
    
    'cleanup_sandbox' => function (array $payload) {
        $projectUuid = $payload['project_uuid'] ?? null;

        queueLog('info', 'Cleaning up sandbox', ['project_uuid' => $projectUuid]);

        $sandboxService = new \Ravi\Services\SandboxService();
        $sandboxService->removeContainer("ravi-sandbox-{$projectUuid}");

        return ['cleaned' => true];
    },

    // Sincronização Git (commit + push no host com o token do dono). Enfileirado pelo
    // botão "Sincronizar" do projeto e pelo auto-sync do Maestro na entrega.
    'git_sync' => function (array $payload) {
        $projectId = (int) ($payload['project_id'] ?? 0);
        queueLog('info', 'Git sync', ['project_id' => $projectId]);
        $project = \Ravi\Models\Project::find($projectId);
        if (!$project) {
            return ['ok' => false, 'error' => 'projeto não encontrado'];
        }
        $message = isset($payload['message']) ? (string) $payload['message'] : null;
        $actor   = isset($payload['triggered_by']) ? (int) $payload['triggered_by'] : null;
        $res = (new \Ravi\Services\GitSyncService())->sync($project, $message, $actor);
        queueLog($res['ok'] ? 'info' : 'error', 'Git sync result', $res);
        return $res;
    },

    // Reverter o projeto para um commit anterior (cria um commit novo no topo; o autor é
    // quem clicou em "Voltar para esta versão").
    'git_revert' => function (array $payload) {
        $projectId = (int) ($payload['project_id'] ?? 0);
        $sha       = (string) ($payload['sha'] ?? '');
        $actor     = isset($payload['triggered_by']) ? (int) $payload['triggered_by'] : null;
        queueLog('info', 'Git revert', ['project_id' => $projectId, 'sha' => $sha]);
        $project = \Ravi\Models\Project::find($projectId);
        if (!$project) {
            return ['ok' => false, 'error' => 'projeto não encontrado'];
        }
        $res = (new \Ravi\Services\GitSyncService())->revertTo($project, $sha, $actor);
        queueLog($res['ok'] ? 'info' : 'error', 'Git revert result', $res);
        return $res;
    },

    // Deploy automático: sincroniza (rsync) o workspace do projeto num servidor externo (SSH)
    // marcado como auto_deploy, a cada entrega — além do git.
    'server_deploy' => function (array $payload) {
        $projectId = (int) ($payload['project_id'] ?? 0);
        $serverId  = (int) ($payload['server_id'] ?? 0);
        $convId    = (int) ($payload['conv_id'] ?? 0);
        queueLog('info', 'Server deploy', ['project_id' => $projectId, 'server_id' => $serverId]);
        $project = \Ravi\Models\Project::find($projectId);
        if (!$project) {
            return ['ok' => false, 'error' => 'projeto não encontrado'];
        }
        $res = (new \Ravi\Services\ServerDeployService())->deploy($project, $serverId);
        queueLog($res['ok'] ? 'info' : 'error', 'Server deploy result', $res);
        // Reporta de volta no chat (UI "Sincronizado no servidor X" / falha), se houve conversa.
        if ($convId > 0) {
            try {
                $evt = $res['ok']
                    ? ['type' => 'server_deployed', 'server' => (string) ($res['server'] ?? '')]
                    : ['type' => 'server_deploy_failed', 'server' => (string) ($res['server'] ?? ''),
                       'error' => mb_substr((string) ($res['error'] ?? 'falhou'), 0, 200)];
                \Ravi\Core\Database::getInstance()->query(
                    "INSERT INTO agent_events (conversation_id, payload) VALUES (?, ?)",
                    [$convId, json_encode($evt, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]
                );
            } catch (\Throwable $e) { /* UI best-effort, não afeta o deploy */ }
        }
        return $res;
    }
];

// ============================================================================
// QUEUE FUNCTIONS
// ============================================================================

/**
 * Get the next available job from the queue
 */
function getNextJob(\Ravi\Core\Database $db, string $queue): ?array
{
    $sql = "SELECT * FROM job_queue
            WHERE queue = :queue
            AND available_at <= NOW()
            AND (reserved_at IS NULL OR reserved_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
            AND attempts < max_attempts
            ORDER BY priority DESC, created_at ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED";

    return $db->fetch($sql, ['queue' => $queue]);
}

/**
 * Reserve a job for processing
 */
function reserveJob(\Ravi\Core\Database $db, int $jobId): bool
{
    $sql = "UPDATE job_queue SET reserved_at = NOW(), attempts = attempts + 1 WHERE id = :id";
    $db->query($sql, ['id' => $jobId]);
    return true;
}

/**
 * Mark job as completed
 */
function completeJob(\Ravi\Core\Database $db, int $jobId): bool
{
    $sql = "DELETE FROM job_queue WHERE id = :id";
    $db->query($sql, ['id' => $jobId]);
    return true;
}

/**
 * Mark job as failed
 */
function failJob(\Ravi\Core\Database $db, int $jobId, string $error): bool
{
    $sql = "UPDATE job_queue SET 
            reserved_at = NULL, 
            last_error = :error,
            failed_at = CASE WHEN attempts >= max_attempts THEN NOW() ELSE NULL END
            WHERE id = :id";
    $db->query($sql, ['id' => $jobId, 'error' => $error]);
    return true;
}

// ============================================================================
// SCHEMA DA FILA
// ============================================================================
// A tabela `job_queue` é criada pela migration (database/migrations/001_create_tables.sql),
// que é a fonte da verdade do schema (inclui uuid, priority, available_at).
// Execute as migrations antes de iniciar o worker: php bin/db-init.php --migrate

// ============================================================================
// MAIN LOOP
// ============================================================================

queueLog('info', "Listening on queue: {$queueName}");
echo "\n";

$processedCount = 0;
$errorCount = 0;

// RESILIÊNCIA: ao (re)iniciar, reabre jobs que ficaram RESERVADOS por uma instância anterior
// que morreu no meio (worker reiniciado durante um job longo). Só os que ainda têm tentativas e
// não concluíram/falharam. Como há um único worker, ao subir não há outro processando — é seguro.
try {
    // OBS: jobs concluídos são DELETADOS da fila (não há coluna completed_at). Logo, qualquer linha
    // ainda presente, reservada, sem falha definitiva e com tentativas restantes é um job órfão.
    $st = $db->query(
        "UPDATE job_queue SET reserved_at = NULL
         WHERE reserved_at IS NOT NULL AND failed_at IS NULL AND attempts < max_attempts"
    );
    $n = $st ? $st->rowCount() : 0;
    if ($n > 0) {
        queueLog('info', "Retomada: {$n} job(s) órfão(s) reabertos para reprocessamento");
    }
} catch (\Throwable $e) {
    queueLog('warning', 'Falha ao reabrir jobs órfãos no startup', ['error' => $e->getMessage()]);
}

while (!$shouldQuit) {
    // Check signals
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    
    try {
        // Get next job
        $job = getNextJob($db, $queueName);
        
        if (!$job) {
            if ($runOnce) {
                queueLog('info', 'No jobs found, exiting (--once mode)');
                break;
            }
            
            sleep($sleepSeconds);
            continue;
        }
        
        $jobId = $job['id'];
        $jobType = $job['job_type'];
        $payload = json_decode($job['payload'], true) ?? [];
        $attempt = $job['attempts'] + 1;
        
        queueLog('info', "Processing job #{$jobId}", [
            'type' => $jobType,
            'attempt' => $attempt
        ]);
        
        // Reserve the job
        reserveJob($db, $jobId);
        
        // Check if handler exists
        if (!isset($jobHandlers[$jobType])) {
            throw new \Exception("Unknown job type: {$jobType}");
        }
        
        // Execute job with timeout
        $startTime = time();
        
        try {
            // Tentativa atual no payload: a notificacao de falha so dispara na ULTIMA
            // tentativa (evita tempestade de alertas WhatsApp/e-mail num erro que se repete).
            $payload['_attempt'] = $attempt;
            $payload['_max_attempts'] = (int) $job['max_attempts'];
            $result = $jobHandlers[$jobType]($payload);
            
            // Mark as completed
            completeJob($db, $jobId);
            $processedCount++;
            
            queueLog('info', "Job #{$jobId} completed", [
                'duration' => (time() - $startTime) . 's'
            ]);
            
        } catch (\Throwable $e) {
            // Job failed
            $errorMessage = $e->getMessage();
            failJob($db, $jobId, $errorMessage);
            $errorCount++;
            
            queueLog('error', "Job #{$jobId} failed", [
                'error' => $errorMessage,
                'attempt' => $attempt,
                'max_attempts' => $job['max_attempts']
            ]);
        }
        
        if ($runOnce) {
            break;
        }
        
    } catch (\Throwable $e) {
        queueLog('error', 'Queue worker error', ['error' => $e->getMessage()]);
        sleep($sleepSeconds);
    }
}

// ============================================================================
// SUMMARY
// ============================================================================

echo "\n";
queueLog('info', '=== Worker Summary ===');
queueLog('info', "Jobs processed: {$processedCount}");
queueLog('info', "Errors: {$errorCount}");
queueLog('info', 'Worker stopped.');

exit($errorCount > 0 ? 1 : 0);
