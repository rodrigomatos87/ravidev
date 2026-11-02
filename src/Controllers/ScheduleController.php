<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\{Request, Response, JsonResponse, Database};
use Ravi\Models\Project;
use Ravi\Services\ScheduleService;

/**
 * #6 — Agendamentos do usuário: rodar um arquivo/comando do projeto a cada X (ou em horário fixo),
 * ou acionar o Claude com um prompt num horário, com histórico de execuções.
 */
class ScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requireAuth($request);
        $uid = (int) $this->user->id;
        $schedules = Database::getInstance()->fetchAll(
            "SELECT s.*, p.name AS project_name, p.uuid AS project_uuid
             FROM schedules s JOIN projects p ON p.id = s.project_id
             WHERE s.user_id = ? ORDER BY s.created_at DESC", [$uid]
        );
        // Projetos acessíveis (para o seletor do formulário).
        $accIds = Project::accessibleIdsFor($uid);
        $projects = [];
        foreach ($accIds as $pid) {
            $p = Project::find((int) $pid);
            if ($p && ($p->status ?? '') !== 'deleted') {
                $projects[] = ['id' => (int) $p->id, 'name' => (string) $p->name];
            }
        }
        return $this->view('schedules/index', [
            'title' => 'Agendamentos',
            'schedules' => $schedules,
            'projects' => $projects,
            'currentPage' => 'schedules',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $uid = (int) $this->user->id;
        $projectId = (int) $request->input('project_id', 0);
        if (!in_array($projectId, Project::accessibleIdsFor($uid), true)) {
            return $this->jsonError('Projeto inválido.', 422);
        }
        $kind = $request->input('kind', 'command') === 'claude' ? 'claude' : 'command';
        $title = trim((string) $request->input('title', ''));
        $payload = trim((string) $request->input('payload', ''));
        if ($title === '' || $payload === '') {
            return $this->jsonError($kind === 'claude' ? 'Informe um título e o que pedir ao Claude.' : 'Informe um título e o comando/arquivo a executar.', 422);
        }
        $type = $request->input('schedule_type', 'interval') === 'daily' ? 'daily' : 'interval';
        $interval = max(1, (int) $request->input('interval_minutes', 60));
        $dailyTime = preg_replace('/[^0-9:]/', '', (string) $request->input('daily_time', '08:00')) ?: '08:00';

        $svc = new ScheduleService();
        $row = ['schedule_type' => $type, 'interval_minutes' => $interval, 'daily_time' => $dailyTime];
        $next = $svc->computeNextRun($row);

        Database::getInstance()->query(
            "INSERT INTO schedules (user_id, project_id, kind, title, payload, schedule_type, interval_minutes, daily_time, enabled, next_run_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)",
            [$uid, $projectId, $kind, mb_substr($title, 0, 200), $payload, $type, $interval, $type === 'daily' ? $dailyTime : null, $next]
        );
        return $this->json(['ok' => true], 'Agendamento criado.');
    }

    public function toggle(Request $request, string $id): JsonResponse
    {
        $this->requireAuth($request);
        $s = $this->owned((int) $id);
        if (!$s) {
            return $this->jsonError('Não encontrado.', 404);
        }
        $on = !((int) $s['enabled'] === 1);
        Database::getInstance()->query("UPDATE schedules SET enabled = ? WHERE id = ?", [$on ? 1 : 0, (int) $id]);
        return $this->json(['enabled' => $on]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->requireAuth($request);
        if ($this->owned((int) $id)) {
            Database::getInstance()->query("DELETE FROM schedules WHERE id = ?", [(int) $id]);
        }
        return $this->json(['ok' => true], 'Removido.');
    }

    public function runs(Request $request, string $id): JsonResponse
    {
        $this->requireAuth($request);
        if (!$this->owned((int) $id)) {
            return $this->jsonError('Não encontrado.', 404);
        }
        $runs = Database::getInstance()->fetchAll(
            "SELECT status, exit_code, output, duration_ms, created_at FROM schedule_runs WHERE schedule_id = ? ORDER BY id DESC LIMIT 30", [(int) $id]
        );
        return $this->json(['runs' => $runs]);
    }

    /** Roda agora (best-effort, síncrono — pode demorar para o modo Claude). */
    public function runNow(Request $request, string $id): JsonResponse
    {
        $this->requireAuth($request);
        $s = $this->owned((int) $id);
        if (!$s) {
            return $this->jsonError('Não encontrado.', 404);
        }
        try {
            (new ScheduleService())->runOne($s);
        } catch (\Throwable $e) {
            return $this->jsonError('Falha ao executar: ' . $e->getMessage(), 500);
        }
        return $this->json(['ok' => true], 'Executado.');
    }

    private function owned(int $id): ?array
    {
        $s = Database::getInstance()->fetch("SELECT * FROM schedules WHERE id = ?", [$id]);
        return ($s && (int) $s['user_id'] === (int) $this->user->id) ? $s : null;
    }
}
