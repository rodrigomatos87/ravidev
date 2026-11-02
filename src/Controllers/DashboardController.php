<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\{Request, Response, JsonResponse};
use Ravi\Models\{Project, Conversation};

/**
 * Dashboard Controller
 * Main dashboard with overview and statistics
 */
class DashboardController extends Controller
{
    /**
     * Show main dashboard
     */
    public function index(Request $request): Response
    {
        $this->requireAuth($request);
        
        // Projetos acessíveis (dono + compartilhados) com atividade recente
        $accIds = Project::accessibleIdsFor((int) $this->user->id);
        $projects = empty($accIds) ? [] : Project::whereIn('id', $accIds)
            ->where('status', '!=', 'deleted')
            ->orderBy('updated_at', 'DESC')
            ->limit(10)
            ->get();
        
        // Contagem de conversas por projeto (a lista "Projetos Recentes" mostra isso).
        foreach ($projects as &$p) {
            $p->conversations_count = Conversation::where('project_id', '=', $p->id)->count();
        }
        unset($p);

        // Get recent conversations across all projects
        $projectIds = array_column($projects, 'id');
        $recentConversations = [];
        
        if (!empty($projectIds)) {
            $recentConversations = Conversation::whereIn('project_id', $projectIds)
                ->orderBy('updated_at', 'DESC')
                ->limit(5)
                ->get();
        }
        
        // Calculate statistics
        $stats = $this->getUserStats();

        // Métricas do Gateway de API — só para admin (consumo + chaves ativas).
        $isAdmin = $this->user && $this->user->isAdmin();
        $gw = $isAdmin ? $this->gatewayStats() : ['spend' => 0.0, 'active_keys' => 0];

        // #2/#8 — custo/tokens das origens ATIVAS deste usuário + se ele pode ver os valores.
        $usageBreakdown = (new \Ravi\Services\UsageService())->breakdown((int) $this->user->id);
        $canViewUsage = canViewUsage();

        return $this->view('dashboard/index', [
            'projects' => $projects,
            'usageBreakdown' => $usageBreakdown,
            'canViewUsage' => $canViewUsage,
            'recentProjects' => $projects,
            'recentConversations' => $recentConversations,
            'recentActivity' => [],
            'stats' => [
                'total_projects' => $stats['projects']['total'] ?? 0,
                'total_conversations' => $stats['conversations']['total'] ?? 0,
                'total_files' => $stats['files']['total'] ?? 0,
                'tokens_used' => $stats['tokens']['total'] ?? 0,
                'cost_total' => $stats['tokens']['cost'] ?? 0,
            ],
            'isAdmin' => $isAdmin,
            'gwSpend' => $gw['spend'],
            'gwActiveKeys' => $gw['active_keys'],
            'server' => $isAdmin ? $this->serverStatsData() : null,
            'hasApiKey' => $this->user->hasApiKey(),
        ]);
    }

    /**
     * Métricas do servidor (CPU/RAM/disco) em JSON — para o polling ao vivo do dashboard.
     * Só admin.
     */
    public function serverStats(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        if (!($this->user && $this->user->isAdmin())) {
            return JsonResponse::error('Sem permissão.', 403);
        }
        return $this->json($this->serverStatsData());
    }

    /** Lê uso de CPU, RAM e disco do host (via /proc e disk_*_space). */
    private function serverStatsData(): array
    {
        // CPU: % de uso a partir de duas amostras de /proc/stat.
        $cpuPct = 0.0;
        $a = $this->cpuSample();
        if ($a) {
            usleep(150000);
            $b = $this->cpuSample();
            if ($b && ($b['total'] - $a['total']) > 0) {
                $dTotal = $b['total'] - $a['total'];
                $dIdle  = $b['idle'] - $a['idle'];
                $cpuPct = round(100 * ($dTotal - $dIdle) / $dTotal, 1);
            }
        }
        $cores = 0;
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo !== false) {
            $cores = preg_match_all('/^processor\s*:/m', $cpuinfo);
        }
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

        // RAM: /proc/meminfo (MemTotal - MemAvailable).
        $ramTotal = 0; $ramUsed = 0;
        $mem = @file_get_contents('/proc/meminfo');
        if ($mem !== false) {
            preg_match('/MemTotal:\s+(\d+)/', $mem, $mt);
            preg_match('/MemAvailable:\s+(\d+)/', $mem, $ma);
            $ramTotal = (int) ($mt[1] ?? 0) * 1024;
            $avail    = (int) ($ma[1] ?? 0) * 1024;
            $ramUsed  = max(0, $ramTotal - $avail);
        }

        // Disco: partição raiz.
        $diskTotal = (int) @disk_total_space('/');
        $diskFree  = (int) @disk_free_space('/');
        $diskUsed  = max(0, $diskTotal - $diskFree);

        return [
            'cpu' => [
                'pct'   => $cpuPct,
                'cores' => (int) $cores,
                'load'  => round((float) ($load[0] ?? 0), 2),
            ],
            'ram' => [
                'pct'   => $ramTotal > 0 ? round(100 * $ramUsed / $ramTotal, 1) : 0.0,
                'used'  => $ramUsed,
                'total' => $ramTotal,
            ],
            'disk' => [
                'pct'   => $diskTotal > 0 ? round(100 * $diskUsed / $diskTotal, 1) : 0.0,
                'used'  => $diskUsed,
                'total' => $diskTotal,
            ],
        ];
    }

    /** Uma amostra de /proc/stat (idle inclui iowait). */
    private function cpuSample(): ?array
    {
        $lines = @file('/proc/stat');
        if (!$lines) {
            return null;
        }
        $p = preg_split('/\s+/', trim($lines[0]));
        if (($p[0] ?? '') !== 'cpu') {
            return null;
        }
        $vals  = array_map('intval', array_slice($p, 1));
        $idle  = (int) ($vals[3] ?? 0) + (int) ($vals[4] ?? 0); // idle + iowait
        $total = array_sum($vals);
        return ['idle' => $idle, 'total' => $total];
    }

    /**
     * Consumo estimado do Gateway de API + nº de chaves ativas (admin).
     * Custo estimado por modelo (USD/1M tokens), igual ao painel da API.
     */
    private function gatewayStats(): array
    {
        try {
            $db = \Ravi\Core\Database::getInstance();
            $keys = (int) $db->fetchColumn(
                "SELECT COUNT(*) FROM gw_tokens WHERE is_active = 1 AND revoked_at IS NULL"
            );
            $rows = $db->fetchAll(
                "SELECT model_served, SUM(input_tokens) AS i, SUM(output_tokens) AS o
                 FROM gw_requests GROUP BY model_served"
            );
            $spend = 0.0;
            foreach ($rows as $r) {
                $m = strtolower((string) ($r['model_served'] ?? ''));
                $pin = 3.0; $pout = 15.0; // sonnet (default)
                if (str_contains($m, 'opus')) { $pin = 15.0; $pout = 75.0; }
                elseif (str_contains($m, 'haiku')) { $pin = 0.80; $pout = 4.0; }
                $spend += ((float) $r['i'] * $pin + (float) $r['o'] * $pout) / 1_000_000;
            }
            return ['spend' => $spend, 'active_keys' => $keys];
        } catch (\Throwable $e) {
            return ['spend' => 0.0, 'active_keys' => 0];
        }
    }
    
    /**
     * Get dashboard stats via API
     */
    public function stats(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        
        return $this->json($this->getUserStats());
    }
    
    /**
     * Notificações (sininho do topo): tarefas recentes concluídas ou que falharam, nos projetos
     * acessíveis ao usuário. Usado pelo dropdown de notificações no header.
     */
    public function notifications(Request $request): JsonResponse
    {
        $this->requireAuth($request);

        $accIds = Project::accessibleIdsFor((int) $this->user->id);
        if (empty($accIds)) {
            return $this->json(['items' => []]);
        }
        $projects = Project::whereIn('id', $accIds)->where('status', '!=', 'deleted')->pluck('id');
        if (empty($projects)) {
            return $this->json(['items' => []]);
        }

        $convs = Conversation::whereIn('project_id', $projects)
            ->whereIn('status', ['completed', 'failed'])
            ->orderBy('updated_at', 'DESC')
            ->limit(15)
            ->get();

        $items = [];
        foreach ($convs as $conv) {
            $project = Project::find($conv->project_id);
            $at = $conv->completed_at ?: $conv->updated_at;
            $items[] = [
                'status'     => $conv->status, // completed | failed
                'title'      => (string) ($conv->title ?: 'Tarefa'),
                'project'    => $project?->name,
                'url'        => $project ? ('/chat/' . $project->uuid . '/' . $conv->uuid) : null,
                'error'      => $conv->status === 'failed' ? (string) ($conv->error_message ?? '') : '',
                'at'         => $at,
                'at_ts'      => $at ? strtotime((string) $at) : 0,
            ];
        }

        return $this->json(['items' => $items]);
    }

    /**
     * Quick search
     */
    public function search(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        
        $query = trim((string)$request->get('q', ''));
        
        if (strlen($query) < 2) {
            return $this->json([
                'projects' => [],
                'conversations' => []
            ]);
        }
        
        $searchPattern = '%' . $query . '%';

        // Projetos acessíveis (dono + compartilhados), não deletados
        $accIds = Project::accessibleIdsFor((int) $this->user->id);

        // Search projects
        $projects = empty($accIds) ? [] : Project::whereIn('id', $accIds)
            ->where('status', '!=', 'deleted')
            ->where('name', 'LIKE', $searchPattern)
            ->orderBy('name', 'ASC')
            ->limit(5)
            ->get();

        // Search conversations
        $userProjectIds = empty($accIds) ? [] : Project::whereIn('id', $accIds)
            ->where('status', '!=', 'deleted')
            ->pluck('id');

        $conversations = [];
        if (!empty($userProjectIds)) {
            $conversations = Conversation::whereIn('project_id', $userProjectIds)
                ->where('title', 'LIKE', $searchPattern)
                ->orderBy('updated_at', 'DESC')
                ->limit(5)
                ->get();
        }
        
        return $this->json([
            'projects' => array_map(fn($p) => [
                'id' => $p->uuid,
                'name' => $p->name,
                'icon' => $p->icon,
                'url' => '/projects/' . $p->uuid,
            ], $projects),
            'conversations' => array_map(function($c) {
                $project = Project::find($c->project_id);
                return [
                    'id' => $c->uuid,
                    'title' => $c->title,
                    'status' => $c->status,
                    'project_name' => $project?->name,
                    'url' => $project ? '/chat/' . $project->uuid . '/' . $c->uuid : '/dashboard',
                ];
            }, $conversations),
        ]);
    }
    
    /**
     * Calculate user statistics
     */
    private function getUserStats(): array
    {
        // Gasto REAL do usuário (histórico/ledger) — cobre projetos próprios e compartilhados.
        $userUsage = $this->user->getUsageTotals();

        // Projetos acessíveis (dono + compartilhados)
        $accIds = Project::accessibleIdsFor((int) $this->user->id);
        $totalProjects = empty($accIds) ? 0 : Project::whereIn('id', $accIds)
            ->where('status', '=', 'active')
            ->count();
        $projectIds = $accIds;
        
        $totalConversations = 0;
        $completedTasks = 0;
        $runningTasks = 0;
        $failedTasks = 0;
        $totalTokensInput = 0;
        $totalTokensOutput = 0;
        $totalCost = 0.0;

        if (!empty($projectIds)) {
            // Count conversations
            $totalConversations = Conversation::whereIn('project_id', $projectIds)->count();
            
            // Count by status
            $completedTasks = Conversation::whereIn('project_id', $projectIds)
                ->where('status', '=', 'completed')
                ->count();
            
            $runningTasks = Conversation::whereIn('project_id', $projectIds)
                ->where('status', '=', 'running')
                ->count();
            
            $failedTasks = Conversation::whereIn('project_id', $projectIds)
                ->where('status', '=', 'failed')
                ->count();
            
            // Sum tokens (using raw query for efficiency)
            $db = \Ravi\Core\Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            
            $tokenStats = $db->queryOne(
                "SELECT
                    COALESCE(SUM(total_tokens_input), 0) as input,
                    COALESCE(SUM(total_tokens_output), 0) as output,
                    COALESCE(SUM(total_cost), 0) as cost
                 FROM conversations
                 WHERE project_id IN ({$placeholders})",
                $projectIds
            );

            $totalTokensInput = (int)($tokenStats['input'] ?? 0);
            $totalTokensOutput = (int)($tokenStats['output'] ?? 0);
            // Custo REAL acumulado (gravado com realApiCost — cache descontado). NÃO re-estimar
            // com estimateApiCost sobre total_tokens_input, que inclui cache e inflaria ~5-10x.
            $totalCost = round((float)($tokenStats['cost'] ?? 0), 6);
        }
        
        return [
            'projects' => [
                'total' => $totalProjects,
            ],
            'conversations' => [
                'total' => $totalConversations,
                'completed' => $completedTasks,
                'running' => $runningTasks,
                'failed' => $failedTasks,
            ],
            'tokens' => [
                'input' => $userUsage['input'],
                'output' => $userUsage['output'],
                'total' => $userUsage['tokens'],
                'used' => $userUsage['tokens'], // gasto REAL do usuário (do histórico/ledger)
                'cost' => round((float) $userUsage['cost'], 6),
            ],
            'plan' => $this->user->plan,
        ];
    }
}
