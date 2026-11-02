<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;
use Ravi\Models\Project;

/**
 * #6 — Ambiente de agendamento. Calcula o próximo horário, executa os agendamentos vencidos
 * (comando no sandbox OU prompt no Claude) e grava o histórico de cada execução (schedule_runs).
 * Disparado de minuto em minuto por bin/scheduler.php (cron).
 */
class ScheduleService
{
    /** Próximo horário (Y-m-d H:i:s) a partir de $fromTs (default: agora). */
    public function computeNextRun(array $s, ?int $fromTs = null): string
    {
        $from = $fromTs ?? time();
        if (($s['schedule_type'] ?? 'interval') === 'daily') {
            $time = (string) ($s['daily_time'] ?? '08:00');
            [$h, $m] = array_pad(array_map('intval', explode(':', $time)), 2, 0);
            $next = mktime($h, $m, 0, (int) date('n', $from), (int) date('j', $from), (int) date('Y', $from));
            if ($next <= $from) {
                $next = strtotime('+1 day', $next);
            }
            return date('Y-m-d H:i:s', $next);
        }
        $min = max(1, (int) ($s['interval_minutes'] ?? 60));
        return date('Y-m-d H:i:s', $from + $min * 60);
    }

    /** Executa todos os agendamentos vencidos. Retorna quantos rodaram. */
    public function runDue(): int
    {
        $db = Database::getInstance();
        $due = $db->fetchAll(
            "SELECT * FROM schedules WHERE enabled = 1 AND (next_run_at IS NULL OR next_run_at <= NOW()) ORDER BY next_run_at ASC LIMIT 20"
        );
        $count = 0;
        foreach ($due as $s) {
            // Reagenda ANTES de rodar (evita repetição se a execução demorar).
            $next = $this->computeNextRun($s);
            $db->query("UPDATE schedules SET last_run_at = NOW(), next_run_at = ? WHERE id = ?", [$next, (int) $s['id']]);
            try {
                $this->runOne($s);
            } catch (\Throwable $e) {
                $this->record((int) $s['id'], 'error', null, 'Erro: ' . $e->getMessage(), 0);
            }
            $count++;
        }
        return $count;
    }

    /** Executa um agendamento e grava o histórico. */
    public function runOne(array $s): void
    {
        $project = Project::find((int) $s['project_id']);
        if (!$project) {
            $this->record((int) $s['id'], 'error', null, 'Projeto não encontrado.', 0);
            return;
        }
        $started = microtime(true);
        if (($s['kind'] ?? 'command') === 'claude') {
            $cc = new ClaudeCodeService(new SandboxService());
            $cc->actingUserId = (int) $s['user_id'];
            $ws = $cc->ensureWorkspace($project->uuid);
            $res = $cc->run($ws, $project->uuid, (string) $s['payload'], ['quiet' => true]);
            $out = trim((string) ($res['summary'] ?? '')) ?: '(sem resumo)';
            $ok = !empty($res['summary']) || empty($res['error']);
            $this->record((int) $s['id'], $ok ? 'ok' : 'error', null, mb_substr($out, 0, 8000), (int) ((microtime(true) - $started) * 1000));
            return;
        }
        // command: roda no sandbox do projeto.
        $sandbox = new SandboxService();
        $container = $sandbox->getOrCreateContainer($project->uuid);
        $res = $sandbox->execute($container, (string) $s['payload'], '/workspace', 600);
        $ok = !empty($res['success']) || (int) ($res['exit_code'] ?? 1) === 0;
        $this->record((int) $s['id'], $ok ? 'ok' : 'error', (int) ($res['exit_code'] ?? 0),
            mb_substr((string) ($res['output'] ?? ''), 0, 8000), (int) ((microtime(true) - $started) * 1000));
    }

    private function record(int $scheduleId, string $status, ?int $exit, string $output, int $durationMs): void
    {
        try {
            Database::getInstance()->query(
                "INSERT INTO schedule_runs (schedule_id, status, exit_code, output, duration_ms) VALUES (?, ?, ?, ?, ?)",
                [$scheduleId, $status, $exit, $output, $durationMs]
            );
            // Mantém no máx. 50 execuções por agendamento.
            Database::getInstance()->query(
                "DELETE FROM schedule_runs WHERE schedule_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM schedule_runs WHERE schedule_id = ? ORDER BY id DESC LIMIT 50) t)",
                [$scheduleId, $scheduleId]
            );
        } catch (\Throwable $e) { /* histórico nunca derruba o runner */ }
    }
}
