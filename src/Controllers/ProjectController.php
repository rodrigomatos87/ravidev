<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\{Request, Response, JsonResponse};
use Ravi\Models\{Project, Conversation, User};

/**
 * Project Controller
 * CRUD operations for projects
 */
class ProjectController extends Controller
{
    /**
     * List all projects
     */
    public function index(Request $request): Response|JsonResponse
    {
        $this->requireAuth($request);
        
        $status = $request->get('status', 'active');
        $sort = $request->get('sort', 'updated_at');
        $order = strtoupper($request->get('order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSorts = ['name', 'created_at', 'updated_at'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'updated_at';
        }
        
        // SOMENTE ADMINISTRADOR: ver os projetos de TODOS os usuários do sistema (?all=1).
        // Por padrão (mesmo para o admin) a lista é só o que é dele + compartilhado com ele.
        $isAdmin  = $this->user->isAdmin();
        $allUsers = $isAdmin && Project::adminSeesAll((int) $this->user->id);
        if ($isAdmin && $request->get('all') !== null) {
            $allUsers = (string) $request->get('all') === '1'; // ?all=1 / ?all=0 força nesta visita
        }

        if ($allUsers) {
            $projects = Project::where('status', '=', $status)
                ->orderBy($sort, $order)
                ->get();
        } else {
            // Projetos acessíveis (dono + compartilhados) com o status pedido.
            $accessibleIds = Project::accessibleIdsFor((int) $this->user->id);
            $projects = empty($accessibleIds) ? [] : Project::whereIn('id', $accessibleIds)
                ->where('status', '=', $status)
                ->orderBy($sort, $order)
                ->get();
        }

        // Nome do dono em cada card (importa na visão de todos os usuários; nos demais casos
        // só aparece quando o projeto não é do próprio usuário, ou seja, foi compartilhado).
        $ownerNames = [];
        foreach ($projects as $p) {
            $ownerNames[(int) $p->user_id] = null;
        }
        if ($ownerNames) {
            $ids = implode(',', array_map('intval', array_keys($ownerNames)));
            foreach (\Ravi\Core\Database::getInstance()->fetchAll("SELECT id, name, email FROM users WHERE id IN ($ids)") as $r) {
                $ownerNames[(int) $r['id']] = (string) ($r['name'] ?: $r['email']);
            }
        }

        // Add conversation counts + flag "trabalhando" (agente em andamento em background).
        foreach ($projects as &$project) {
            $project->owner_name = $ownerNames[(int) $project->user_id] ?? null;
            $project->is_mine    = (int) $project->user_id === (int) $this->user->id;
            $project->conversation_count = Conversation::where('project_id', '=', $project->id)->count();
            $project->active_conversations = Conversation::where('project_id', '=', $project->id)
                ->whereIn('status', ['pending', 'running'])
                ->count();
            // "Trabalhando" = tem chat com status 'running' (tarefa rodando), ou o projeto está sendo importado.
            $running = Conversation::where('project_id', '=', $project->id)
                ->where('status', '=', 'running')
                ->count();
            $project->is_working = $running > 0
                || \Ravi\Services\ProjectImportService::isProcessing($this->projectWorkspace($project->uuid));
            // Custo do projeto = soma do custo de todas as conversas.
            $project->total_cost = 0.0;
            foreach (Conversation::where('project_id', '=', $project->id)->get() as $c) {
                $project->total_cost += (float) ($c->total_cost ?? 0);
            }
        }
        unset($project);
        
        if ($this->wantsJson($request)) {
            return $this->json([
                'projects' => array_map(fn($p) => $this->formatProject($p), $projects),
                'all_users' => $allUsers,
            ]);
        }
        
        // Agrupado por dono: os do próprio usuário primeiro; depois "compartilhados comigo" (visão
        // normal) ou cada usuário do sistema em ordem alfabética (visão de todos os usuários).
        $groups = [];
        foreach ($projects as $p) {
            $key = $allUsers ? 'u' . (int) $p->user_id : ($p->is_mine ? 'mine' : 'shared');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $p->is_mine ? 'Meus projetos' : ($allUsers ? (string) ($p->owner_name ?? 'Usuário') : 'Compartilhados comigo'),
                    'mine' => (bool) $p->is_mine,
                    'projects' => [],
                ];
            }
            $groups[$key]['projects'][] = $p;
        }
        uasort($groups, function ($a, $b) {
            if ($a['mine'] !== $b['mine']) {
                return $a['mine'] ? -1 : 1;
            }
            return strcasecmp($a['label'], $b['label']);
        });

        return $this->view('projects/index', [
            'projects' => $projects,
            'groups' => array_values($groups),
            'currentStatus' => $status,
            'is_admin' => $isAdmin,
            'all_users' => $allUsers,
        ]);
    }
    
    /**
     * SOMENTE ADMINISTRADOR: liga/desliga a visão "projetos de todos os usuários". Fica guardada
     * como preferência do usuário e vale para a barra lateral e para a lista de projetos.
     */
    public function toggleAllUsers(Request $request): JsonResponse
    {
        if ($this->user === null) {
            return JsonResponse::unauthorized();
        }
        if (!$this->user->isAdmin()) {
            return $this->json(['ok' => false, 'error' => 'Somente administrador.'], 'Erro', 403);
        }
        $on = $request->input('on', '0') === '1' || $request->input('on') === true;
        \Ravi\Models\UserPref::set((int) $this->user->id, Project::ADMIN_ALL_PREF, $on);
        return $this->json(['ok' => true, 'on' => $on]);
    }

    /**
     * Show create form
     */
    public function create(Request $request): Response
    {
        $this->requireAuth($request);
        
        return $this->view('projects/create', [
            'github_connected' => $this->user->hasGithub(),
        ]);
    }
    
    /**
     * Store new project
     */
    public function store(Request $request): Response|JsonResponse
    {
        $this->requireAuth($request);
        
        if (!$this->verifyCsrf($request) && !$this->wantsJson($request)) {
            return $this->redirectWithError('/projects/create', 'Token de segurança inválido.');
        }
        
        $validation = $this->validate($request, [
            'name' => 'required|min:2|max:200',
            'description' => 'nullable|max:2000',
            'type' => 'nullable|in:web,app,both',
            'icon' => 'nullable|max:50',
            'color' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
        ]);
        
        if (!$validation['valid']) {
            if ($this->wantsJson($request)) {
                return JsonResponse::validationError($validation['errors']);
            }
            return $this->back();
        }
        
        $project = Project::create([
            'user_id' => $this->user->id,
            'name' => $validation['data']['name'],
            'description' => $validation['data']['description'] ?? null,
            'type' => in_array($validation['data']['type'] ?? 'web', ['web', 'app', 'both'], true)
                ? ($validation['data']['type'] ?? 'web') : 'web',
            'icon' => projectIcon($validation['data']['icon'] ?? null),
            'color' => $validation['data']['color'] ?: '#6366f1',
            'status' => 'active',
            'settings' => json_encode([]),
        ]);
        
        if ($project === null) {
            if ($this->wantsJson($request)) {
                return $this->jsonError('Erro ao criar projeto', 500);
            }
            return $this->redirectWithError('/projects', 'Erro ao criar projeto. Tente novamente.');
        }

        // O workspace nasce junto com o projeto (qualquer tipo/origem): IDE, preview e APK
        // funcionam de imediato, sem depender do primeiro chat criar o diretório.
        $wsDir = $this->projectWorkspace($project->uuid);
        if (!is_dir($wsDir)) {
            @mkdir($wsDir, 0777, true);
            @chmod($wsDir, 0777);
        }

        // Chave SSH única do projeto (para "fontes externas" via chave). Best-effort.
        try {
            $project->ensureSshKey();
        } catch (\Throwable $e) {
            // não bloqueia a criação
        }

        // Origem "repositório Git": vincula o projeto e clona/prepara em background (mesmo
        // onboarding do .zip). Usa o token do DONO (o criador) — carregado no worker.
        $source = (string) $request->input('source', '');
        $repoFullName = trim((string) $request->input('git_repo_full_name', ''));
        if ($source === 'git' && $repoFullName !== '' && $this->user->hasGithub()) {
            try {
                $gh = new \Ravi\Services\GitHubService();
                $info = $gh->getRepo($this->user->getGithubToken(), $repoFullName);
                $branch = !empty($info['ok']) ? ($info['default_branch'] ?: 'main') : 'main';
                $project->linkRepo($repoFullName, $branch, (bool) $request->input('git_auto_sync', false));

                $base = $this->projectWorkspace($project->uuid);
                (new \Ravi\Services\ProjectImportService(new \Ravi\Services\SandboxService()))->markQueuedGit($base);

                \Ravi\Core\Database::getInstance()->query(
                    "INSERT INTO job_queue (uuid, queue, job_type, payload, max_attempts) VALUES (?, 'default', 'process_project_import', ?, 2)",
                    [uuid(), json_encode([
                        'project_id'     => $project->id,
                        'project_uuid'   => $project->uuid,
                        'source'         => 'git',
                        'repo_full_name' => $repoFullName,
                        'branch'         => $branch,
                        'instructions'   => trim((string) $request->input('instructions', '')),
                    ], JSON_UNESCAPED_UNICODE)]
                );
            } catch (\Throwable $e) {
                // Se falhar ao enfileirar o clone, o projeto já existe (vazio) — usuário pode tentar de novo.
                logger()->error('Falha ao iniciar clone do Git', ['error' => $e->getMessage()]);
            }
            if ($this->wantsJson($request)) {
                return $this->json([
                    'uuid'       => $project->uuid,
                    'processing' => true,
                    'redirect'   => '/projects/' . $project->uuid,
                ], 'Projeto criado — clonando do GitHub…', 201);
            }
            return $this->redirectWithSuccess('/projects/' . $project->uuid, 'Projeto criado — clonando do GitHub…');
        }

        // Origem "criar repositório NOVO no GitHub": cria o repo na conta do dono (público/privado)
        // e já vincula ao projeto em branco. O conteúdo é empurrado depois (sync/IDE) — markGitPending.
        if ($source === 'github_new' && $this->user->hasGithub()) {
            $msg = 'Projeto criado com sucesso!';
            try {
                $gh       = new \Ravi\Services\GitHubService();
                $repoName = trim((string) $request->input('gh_new_name', '')) ?: (string) $project->name;
                $private  = $request->input('gh_new_private', '1') !== '0';
                $res      = $gh->createRepo($this->user->getGithubToken(), $repoName, $private, (string) ($project->description ?? ''));
                if (!empty($res['ok'])) {
                    $project->linkRepo($res['full_name'], $res['default_branch'] ?: 'main', (bool) $request->input('gh_new_auto_sync', false));
                    $project->markGitPending();
                    $msg = 'Projeto criado e repositório “' . $res['full_name'] . '” criado no GitHub!';
                } else {
                    $msg = 'Projeto criado, mas falhou ao criar o repositório no GitHub: ' . ($res['error'] ?? 'erro desconhecido');
                }
            } catch (\Throwable $e) {
                logger()->error('Falha ao criar repo no GitHub na criação do projeto', ['error' => $e->getMessage()]);
                $msg = 'Projeto criado, mas houve um erro ao criar o repositório no GitHub.';
            }
            if ($this->wantsJson($request)) {
                return $this->json([
                    'uuid'     => $project->uuid,
                    'redirect' => '/projects/' . $project->uuid,
                ], $msg, 201);
            }
            return $this->redirectWithSuccess('/projects/' . $project->uuid, $msg);
        }

        if ($this->wantsJson($request)) {
            return $this->json($this->formatProject($project), 'Projeto criado com sucesso', 201);
        }
        
        return $this->redirectWithSuccess(
            '/projects/' . $project->uuid,
            'Projeto criado com sucesso!'
        );
    }
    
    /**
     * Show single project
     */
    public function show(Request $request, string $uuid): Response|JsonResponse
    {
        $this->requireAuth($request);
        
        $project = $this->findProjectByUuid($uuid);
        
        if ($project === null) {
            if ($this->wantsJson($request)) {
                return JsonResponse::notFound('Projeto não encontrado');
            }
            $this->abort(404, 'Projeto não encontrado');
        }
        
        // Get conversations for this project
        $conversations = Conversation::where('project_id', '=', $project->id)
            ->orderBy('updated_at', 'DESC')
            ->get();

        // Stats REAIS. Os ARQUIVOS são do PROJETO (workspace compartilhado por todos os chats
        // do projeto) — contamos os arquivos em disco, não a tabela generated_files (que fica vazia).
        $base        = $this->projectWorkspace($project->uuid);
        $totalTokens = 0;
        $totalCost   = 0.0;
        $completed   = 0;
        foreach ($conversations as $c) {
            $totalTokens += (int) ($c->total_tokens_input ?? 0) + (int) ($c->total_tokens_output ?? 0);
            $totalCost   += (float) ($c->total_cost ?? 0); // custo REAL acumulado (com cache)
            $done = ($c->status ?? '') === 'completed';
            $sf   = $base . '/.maestro/conv-' . $c->id . '.json';
            if (!$done && is_file($sf)) {
                $st   = json_decode((string) @file_get_contents($sf), true);
                $done = is_array($st) && ($st['phase'] ?? '') === 'done';
            }
            if ($done) {
                $completed++;
            }
        }
        $stats = [
            'conversation_count' => count($conversations),
            'completed_count'    => $completed,
            'files_count'        => $this->countWorkspaceFiles($base),
            'total_tokens'       => $totalTokens,
            'total_cost'         => round($totalCost, 6),
            'dev_seconds'        => array_sum(array_map(fn($c) => (int) ($c->work_seconds ?? 0), $conversations)),
        ];

        if ($this->wantsJson($request)) {
            return $this->json([
                'project' => $this->formatProject($project),
                'conversations' => array_map(fn($c) => $this->formatConversation($c), $conversations),
                'stats' => $stats,
            ]);
        }

        return $this->view('projects/show', [
            'project' => $project,
            'conversations' => $conversations,
            'stats' => $stats,
            'import' => \Ravi\Services\ProjectImportService::readStatus($base),
            'members' => $project->members(),
            'is_owner' => (int) $project->user_id === (int) $this->user->id,
            'is_admin' => $this->user->isAdmin(),
            // Admin abrindo projeto de outro usuário (não é dono nem membro): a tela avisa.
            'admin_view' => $this->user->isAdmin() && $this->adminViewOnly($project),
            'owner_name' => (function () use ($project) {
                $owner = $project->user();
                return $owner ? (string) ($owner->name ?: $owner->email) : null;
            })(),
            'context_access' => $project->userHasContextAccess((int) $this->user->id),
            'hasApiKey' => $this->user->hasApiKey(),
            'git' => $project->gitState(),
            // Push usa o token do DONO; a UI guia a conectar quando o dono não tem GitHub.
            'gitOwnerConnected' => (function () use ($project) {
                $owner = $project->user();
                return $owner && $owner->hasGithub();
            })(),
            'gitConfigured' => (new \Ravi\Services\GitHubService())->isConfigured(),
            'contributions' => $this->memberContributions($project),
            'efficiency' => $this->memberEfficiency($project, (int) ($stats['dev_seconds'] ?? 0)),
        ]);
    }

    /**
     * Eficiência por membro: tempo trabalhado na IDE (acumulado pelo sensor "working") + tokens +
     * nº de desenvolvimentos, por usuário. Une as duas fontes (ide_work_seconds e usage_ledger).
     * @return array<int,array{name:string,initial:string,seconds:int,tasks:int,tokens:int,cost:float}>
     */
    private function memberEfficiency(Project $project, int $devSeconds = 0): array
    {
        $pid = (int) $project->id;
        $ledger = [];
        try {
            foreach (\Ravi\Core\Database::getInstance()->fetchAll(
                "SELECT user_id, COUNT(DISTINCT conversation_id) AS tasks,
                        COALESCE(SUM(cost_usd),0) AS cost,
                        COALESCE(SUM(input_tokens + output_tokens),0) AS tokens
                 FROM usage_ledger WHERE project_id = ? GROUP BY user_id",
                [$pid]
            ) as $r) {
                $ledger[(int) $r['user_id']] = $r;
            }
        } catch (\Throwable $e) {
            // sem ledger → segue só com tempo trabalhado
        }

        $worked = (new \Ravi\Services\IdeService())->workSecondsByUser($pid);

        // Mostra TODOS os membros do projeto (dono + compartilhados), mesmo com zero de atividade,
        // além de quem tiver dado no ledger/tempo. Assim a equipe inteira aparece no card.
        $memberUids = [(int) $project->user_id];
        foreach ($project->members() as $m) {
            $memberUids[] = (int) $m['user_id'];
        }
        $uids = array_values(array_unique(array_merge($memberUids, array_keys($ledger), array_keys($worked))));
        if (empty($uids)) {
            return [];
        }

        $out = [];
        foreach ($uids as $uid) {
            $u = User::find((int) $uid);
            $name = $u ? ($u->name ?: $u->email) : ('Usuário #' . $uid);
            $l = $ledger[(int) $uid] ?? ['tasks' => 0, 'cost' => 0, 'tokens' => 0];
            $out[] = [
                'name'    => $name,
                'initial' => strtoupper(mb_substr($name, 0, 1)),
                'seconds' => (int) ($worked[(int) $uid] ?? 0),
                'tasks'   => (int) $l['tasks'],
                'tokens'  => (int) $l['tokens'],
                'cost'    => round((float) $l['cost'], 4),
                'is_agent' => false,
            ];
        }

        // O Dev autônomo (chat/Maestro) entra como um "participante" do projeto, com o tempo que
        // trabalhou (soma de conversations.work_seconds). Aparece como mais uma barra do gráfico.
        $out[] = [
            'name'     => 'Dev autônomo',
            'initial'  => 'IA',
            'seconds'  => $devSeconds,
            'tasks'    => 0,
            'tokens'   => 0,
            'cost'     => 0.0,
            'is_agent' => true,
        ];

        usort($out, fn ($a, $b) => $b['seconds'] <=> $a['seconds']);
        return $out;
    }

    /**
     * #5 — Contribuição por membro: índice ponderado (tarefas + custo) por usuário, a partir do
     * usage_ledger do projeto. "Tarefas" = conversas distintas que o membro movimentou; "custo" = USD
     * estimado atribuído a quem disparou. Índice = 50% participação em tarefas + 50% em custo (commits
     * ficariam de fora pois exigiriam chamada síncrona à API do GitHub a cada carga da página).
     * @return array<int,array{name:string,initial:string,tasks:int,cost:float,pct:float}>
     */
    private function memberContributions(Project $project): array
    {
        try {
            $rows = \Ravi\Core\Database::getInstance()->fetchAll(
                "SELECT user_id, COUNT(DISTINCT conversation_id) AS tasks,
                        COALESCE(SUM(cost_usd),0) AS cost,
                        COALESCE(SUM(input_tokens + output_tokens),0) AS tokens
                 FROM usage_ledger WHERE project_id = ? GROUP BY user_id",
                [(int) $project->id]
            );
        } catch (\Throwable $e) {
            return [];
        }
        if (empty($rows)) {
            return [];
        }
        $totalTasks = 0;
        $totalCost  = 0.0;
        foreach ($rows as $r) {
            $totalTasks += (int) $r['tasks'];
            $totalCost  += (float) $r['cost'];
        }
        $out = [];
        foreach ($rows as $r) {
            $uid  = (int) $r['user_id'];
            $u    = User::find($uid);
            $name = $u ? ($u->name ?: $u->email) : ('Usuário #' . $uid);
            $tShare = $totalTasks > 0 ? ((int) $r['tasks'] / $totalTasks) : 0.0;
            $cShare = $totalCost  > 0 ? ((float) $r['cost'] / $totalCost) : 0.0;
            $pct = $totalCost > 0 ? (0.5 * $tShare + 0.5 * $cShare) : $tShare;
            $out[] = [
                'name'    => $name,
                'initial' => strtoupper(mb_substr($name, 0, 1)),
                'tasks'   => (int) $r['tasks'],
                'cost'    => round((float) $r['cost'], 4),
                'pct'     => round($pct * 100, 1),
            ];
        }
        usort($out, fn ($a, $b) => $b['pct'] <=> $a['pct']);
        return $out;
    }

    // ----------------------------------------------------------------------
    // Git/GitHub por projeto
    // ----------------------------------------------------------------------

    /** Lista os repositórios do DONO (token do dono) para o seletor. Só o dono. */
    public function gitRepos(Request $request, string $uuid): JsonResponse
    {
        $project = $this->ownerOnlyProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $token = $this->user->getGithubToken();
        if (!$token) {
            return $this->json(['ok' => false, 'error' => 'Conecte sua conta GitHub no seu perfil primeiro.']);
        }
        $repos = (new \Ravi\Services\GitHubService())->listRepos($token);
        return $this->json(['ok' => true, 'repos' => $repos]);
    }

    /** Liga o projeto a um repo existente OU cria um repo novo. Só o dono. */
    public function gitLink(Request $request, string $uuid): JsonResponse
    {
        $project = $this->ownerOnlyProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $token = $this->user->getGithubToken();
        if (!$token) {
            return $this->json(['ok' => false, 'error' => 'Conecte sua conta GitHub no seu perfil primeiro.']);
        }
        $gh = new \Ravi\Services\GitHubService();
        $mode     = (string) $request->input('mode', 'existing');
        $autoSync = (bool) $request->input('auto_sync', false);

        if ($mode === 'create') {
            $name = trim((string) $request->input('name', ''));
            if ($name === '') {
                return $this->json(['ok' => false, 'error' => 'Informe o nome do repositório.']);
            }
            $private = $request->input('private', '1') !== '0';
            $res = $gh->createRepo($token, $name, $private, (string) ($project->description ?? ''));
            if (empty($res['ok'])) {
                return $this->json(['ok' => false, 'error' => 'Não foi possível criar o repositório: ' . ($res['error'] ?? '')]);
            }
            $project->linkRepo($res['full_name'], $res['default_branch'] ?: 'main', $autoSync);
            // Repo novo está vazio → há conteúdo local a empurrar.
            $project->markGitPending();
            return $this->json(['ok' => true, 'git' => $project->gitState()], 'Repositório criado e vinculado');
        }

        // Ligar a um repo existente
        $fullName = trim((string) $request->input('repo_full_name', ''));
        if ($fullName === '') {
            return $this->json(['ok' => false, 'error' => 'Selecione um repositório.']);
        }
        $info = $gh->getRepo($token, $fullName);
        if (empty($info['ok'])) {
            return $this->json(['ok' => false, 'error' => $info['error'] ?? 'Repositório inválido.']);
        }
        $branch = trim((string) $request->input('branch', '')) ?: ($info['default_branch'] ?: 'main');
        $project->linkRepo($info['full_name'], $branch, $autoSync);
        $project->markGitPending();
        return $this->json(['ok' => true, 'git' => $project->gitState()], 'Repositório vinculado');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Contexto permanente: memórias + fontes externas (SSH) + acesso por membro
    // ──────────────────────────────────────────────────────────────────────────

    /** Projeto p/ endpoints de contexto: exige acesso (dono OU membro liberado). */
    private function contextProject(string $uuid, ?JsonResponse &$err, bool $ownerOnly = false): ?Project
    {
        if ($this->user === null) {
            $err = JsonResponse::unauthorized();
            return null;
        }
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            $err = JsonResponse::notFound('Projeto não encontrado');
            return null;
        }
        $isOwner = (int) $project->user_id === (int) $this->user->id;
        if ($ownerOnly && !$isOwner) {
            $err = $this->json(['ok' => false, 'error' => 'Apenas o dono do projeto pode fazer isso.'], 'Erro', 403);
            return null;
        }
        if (!$project->userHasContextAccess((int) $this->user->id)) {
            $err = $this->json(['ok' => false, 'error' => 'Você não tem acesso às memórias e servidores deste projeto.'], 'Erro', 403);
            return null;
        }
        $err = null;
        return $project;
    }

    /**
     * Projeto para gerir LIGAÇÕES: o dono, ou o ADMINISTRADOR em qualquer projeto do sistema.
     * (Memórias/servidores seguem em contextProject(), que continua só do dono/membro liberado.)
     */
    private function linkProject(string $uuid, ?JsonResponse &$err): ?Project
    {
        if ($this->user !== null && $this->user->isAdmin()) {
            $project = $this->findProjectByUuid($uuid);
            if ($project === null) {
                $err = JsonResponse::notFound('Projeto não encontrado');
                return null;
            }
            $err = null;
            return $project;
        }
        return $this->contextProject($uuid, $err, true);
    }

    /** Aplica o contexto nas IDEs rodando + atualiza o sftp.json (após qualquer alteração). */
    private function refreshContext(Project $project): void
    {
        try {
            (new \Ravi\Services\ProjectContextService())->refreshRunningIdes($project, $this->projectWorkspace($project->uuid));
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /** Lista as memórias do projeto. Dono ou membro liberado. */
    public function memories(Request $request, string $uuid): JsonResponse
    {
        $project = $this->contextProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $out = (new \Ravi\Services\ProjectContextService())->listMemories($project->uuid);
        return $this->json(['ok' => true, 'memories' => $out, 'is_owner' => (int) $project->user_id === (int) $this->user->id]);
    }

    /** Cria ou atualiza uma memória (arquivo .md na memória nativa do Claude Code, compartilhada). */
    public function memorySave(Request $request, string $uuid): JsonResponse
    {
        $project = $this->contextProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $name = trim((string) $request->input('name', ''));
        $content = (string) $request->input('content', '');
        if ($name === '') {
            return $this->json(['ok' => false, 'error' => 'Dê um nome à memória.']);
        }
        $res = (new \Ravi\Services\ProjectContextService())->saveMemory($project->uuid, (string) $request->input('id', ''), $name, $content);
        if (empty($res['ok'])) {
            return $this->json(['ok' => false, 'error' => $res['error'] ?? 'Falha ao salvar.']);
        }
        return $this->json(['ok' => true], 'Memória salva');
    }

    /** Exclui uma memória. */
    public function memoryDelete(Request $request, string $uuid): JsonResponse
    {
        $project = $this->contextProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        (new \Ravi\Services\ProjectContextService())->deleteMemory($project->uuid, (string) $request->input('id', ''));
        return $this->json(['ok' => true], 'Memória excluída');
    }

    /** Lista os servidores (SEM senha). Dono ou membro liberado. */
    public function servers(Request $request, string $uuid): JsonResponse
    {
        $project = $this->contextProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $out = [];
        foreach (\Ravi\Models\ProjectSshSource::forProject((int) $project->id) as $s) {
            $out[] = [
                'id' => (int) $s->id, 'name' => (string) $s->name, 'host' => (string) $s->host,
                'protocol' => $s->isFtp() ? 'ftp' : 'ssh',
                'port' => (int) $s->port, 'username' => (string) $s->username,
                'remote_dir' => (string) $s->remote_dir,
                'enabled' => (int) $s->enabled === 1, 'auto_deploy' => (int) $s->auto_deploy === 1,
                'auth_type' => $s->isKeyAuth() ? 'key' : 'password',
                'has_password' => !empty($s->password_encrypted),
                'has_key' => !empty($s->private_key_encrypted),
            ];
        }
        $project->ensureSshKey();
        return $this->json([
            'ok' => true,
            'servers' => $out,
            'is_owner' => (int) $project->user_id === (int) $this->user->id,
            'public_key' => (string) ($project->getSshPublicKey() ?? ''),
            'install_command' => $project->sshInstallCommand(),
        ]);
    }

    /** Cria/atualiza um servidor. Senha vazia em edição = mantém a atual. */
    public function serverSave(Request $request, string $uuid): JsonResponse
    {
        $project = $this->contextProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $protocol = $request->input('protocol', 'ssh') === 'ftp' ? 'ftp' : 'ssh';
        $name = trim((string) $request->input('name', ''));
        $host = trim((string) $request->input('host', ''));
        $user = trim((string) $request->input('username', ''));
        $defaultPort = $protocol === 'ftp' ? 21 : 22;
        $port = (int) ($request->input('port', $defaultPort) ?: $defaultPort);
        $dir  = trim((string) $request->input('remote_dir', ''));
        $pass = (string) $request->input('password', '');
        // FTP é sempre por senha (sem chave SSH).
        $authType   = ($protocol === 'ssh' && $request->input('auth_type', 'password') === 'key') ? 'key' : 'password';
        $privateKey = (string) $request->input('private_key', '');
        $passphrase = (string) $request->input('passphrase', '');
        if ($name === '' || $host === '' || $user === '') {
            return $this->json(['ok' => false, 'error' => 'Preencha nome, host e usuário.']);
        }
        $id = (int) $request->input('id', 0);
        if ($id > 0) {
            $s = \Ravi\Models\ProjectSshSource::find($id);
            if (!$s || (int) $s->project_id !== (int) $project->id) {
                return $this->json(['ok' => false, 'error' => 'Servidor não encontrado.'], 'Erro', 404);
            }
        } else {
            $s = new \Ravi\Models\ProjectSshSource(['project_id' => (int) $project->id, 'enabled' => 1, 'auto_deploy' => 0]);
        }
        $s->protocol = $protocol;
        $s->name = $name; $s->host = $host; $s->username = $user; $s->port = $port; $s->remote_dir = $dir;
        $s->auth_type = $authType;
        if ($authType === 'key') {
            // Auth por CHAVE = a chave ÚNICA do projeto (não há chave por servidor). Garante que existe.
            $project->ensureSshKey();
            $s->password_encrypted = null;
            $s->private_key_encrypted = null;
            $s->key_passphrase_encrypted = null;
        } else {
            if ($pass !== '') {
                $s->setPassword($pass);
            }
            $s->private_key_encrypted = null;
            $s->key_passphrase_encrypted = null;
            if (empty($s->password_encrypted)) {
                return $this->json(['ok' => false, 'error' => 'Informe a senha do servidor.']);
            }
        }
        $s->save();
        $this->refreshContext($project);
        return $this->json(['ok' => true], 'Servidor salvo');
    }

    /** Exclui um servidor. */
    public function serverDelete(Request $request, string $uuid): JsonResponse
    {
        $project = $this->contextProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $s = \Ravi\Models\ProjectSshSource::find((int) $request->input('id', 0));
        if ($s && (int) $s->project_id === (int) $project->id) {
            $s->delete();
            $this->refreshContext($project);
        }
        return $this->json(['ok' => true], 'Servidor removido');
    }

    /** Testa a conexão SSH com os valores do formulário (senha vazia + id = usa a salva). */
    public function serverTest(Request $request, string $uuid): JsonResponse
    {
        $project = $this->contextProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $protocol = $request->input('protocol', 'ssh') === 'ftp' ? 'ftp' : 'ssh';
        $authType = ($protocol === 'ssh' && $request->input('auth_type', 'password') === 'key') ? 'key' : 'password';
        $cfg = [
            'protocol'    => $protocol,
            'host'        => trim((string) $request->input('host', '')),
            'port'        => (int) ($request->input('port', $protocol === 'ftp' ? 21 : 22) ?: ($protocol === 'ftp' ? 21 : 22)),
            'user'        => trim((string) $request->input('username', '')),
            'dir'         => trim((string) $request->input('remote_dir', '')),
            'auth_type'   => $authType,
            'password'    => (string) $request->input('password', ''),
            'private_key' => (string) $request->input('private_key', ''),
            'passphrase'  => (string) $request->input('passphrase', ''),
        ];
        if ($protocol === 'ftp') {
            // FTP: senha em branco editando → usa a salva.
            $id = (int) $request->input('id', 0);
            if ($id > 0 && $cfg['password'] === '') {
                $s = \Ravi\Models\ProjectSshSource::find($id);
                if ($s && (int) $s->project_id === (int) $project->id) {
                    $cfg['password'] = (string) ($s->getPassword() ?? '');
                }
            }
        } elseif ($authType === 'key') {
            // Chave = a ÚNICA do projeto (o usuário instala a pública no servidor; nada de colar privada).
            $project->ensureSshKey();
            $cfg['private_key'] = (string) ($project->getSshPrivateKey() ?? '');
            $cfg['passphrase']  = '';
        } else {
            // Senha em branco editando → usa a salva.
            $id = (int) $request->input('id', 0);
            if ($id > 0 && $cfg['password'] === '') {
                $s = \Ravi\Models\ProjectSshSource::find($id);
                if ($s && (int) $s->project_id === (int) $project->id) {
                    $cfg['password'] = (string) ($s->getPassword() ?? '');
                }
            }
        }
        $result = (new \Ravi\Services\ServerDeployService())->testConnection($cfg);
        // Persiste os IPs descobertos (inclui VPN) no servidor já salvo, para o ravi-ssh usar como fallback.
        if ($result['ok'] && !empty($result['ips'])) {
            $id = (int) $request->input('id', 0);
            if ($id > 0) {
                $s = \Ravi\Models\ProjectSshSource::find($id);
                if ($s && (int) $s->project_id === (int) $project->id) {
                    $s->discovered_ips = implode(',', $result['ips']);
                    $s->save();
                    // Reflete nas IDEs rodando (servers.json) sem reabrir.
                    $ctx = new \Ravi\Services\ProjectContextService();
                    $ctx->refreshRunningIdes($project, $ctx->workspacePath((string) $project->uuid));
                }
            }
        }
        return $this->json($result);
    }

    /** Liga/desliga um toggle do servidor: field = 'enabled' (Claude acessa) ou 'auto_deploy'. */
    public function serverToggle(Request $request, string $uuid): JsonResponse
    {
        $project = $this->contextProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $field = (string) $request->input('field', '');
        if (!in_array($field, ['enabled', 'auto_deploy'], true)) {
            return $this->json(['ok' => false, 'error' => 'Campo inválido.']);
        }
        $s = \Ravi\Models\ProjectSshSource::find((int) $request->input('id', 0));
        if (!$s || (int) $s->project_id !== (int) $project->id) {
            return $this->json(['ok' => false, 'error' => 'Servidor não encontrado.'], 'Erro', 404);
        }
        $on = $request->input('on', '0') === '1' || $request->input('on') === true;
        $s->{$field} = $on ? 1 : 0;
        $s->save();
        $this->refreshContext($project);
        return $this->json(['ok' => true]);
    }

    /** Dono liga/desliga o acesso de um MEMBRO às memórias/servidores. Só o dono. */
    public function contextMemberAccess(Request $request, string $uuid): JsonResponse
    {
        $project = $this->contextProject($uuid, $err, true);
        if (!$project) {
            return $err;
        }
        $memberId = (int) $request->input('user_id', 0);
        $on = $request->input('on', '0') === '1' || $request->input('on') === true;
        $project->setMemberContextAccess($memberId, $on);
        $this->refreshContext($project);
        return $this->json(['ok' => true]);
    }

    // ----------------------------------------------------------------------
    // #3 — Ligações entre projetos (o Claude de A acessa os arquivos de B). Só o dono de A gere.
    // ----------------------------------------------------------------------

    /** Lista as ligações do projeto + os projetos que podem ser ligados (acessíveis ao usuário). */
    public function projectLinks(Request $request, string $uuid): JsonResponse
    {
        $project = $this->linkProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $ctx = new \Ravi\Services\ProjectContextService();
        $links = [];
        foreach (\Ravi\Models\ProjectLink::forProject((int) $project->id) as $ln) {
            $b = Project::find((int) $ln->linked_project_id);
            if (!$b) {
                continue;
            }
            $links[] = [
                'id' => (int) $ln->id, 'project_id' => (int) $b->id, 'uuid' => (string) $b->uuid,
                'name' => (string) $b->name, 'icon' => (string) ($b->icon ?? '📁'),
                'access' => $ln->isWrite() ? 'write' : 'read', 'enabled' => (int) $ln->enabled === 1,
                'mount' => $ctx->mountPathFor($b),
                // Dono do projeto ligado quando NÃO é o próprio usuário (ligação de outro usuário).
                'owner' => (int) $b->user_id === (int) $this->user->id ? null : $this->ownerLabel($b),
            ];
        }
        // Projetos que o usuário pode ligar (acessíveis, ativos, != este, ainda não ligados).
        $linkedIds = array_column($links, 'project_id');
        $available = [];
        $accIds = Project::accessibleIdsFor((int) $this->user->id);
        foreach ($accIds as $pid) {
            if ((int) $pid === (int) $project->id || in_array((int) $pid, $linkedIds, true)) {
                continue;
            }
            $b = Project::find((int) $pid);
            if (!$b || ($b->status ?? '') === 'deleted') {
                continue;
            }
            $available[] = ['id' => (int) $b->id, 'uuid' => (string) $b->uuid, 'name' => (string) $b->name, 'icon' => (string) ($b->icon ?? '📁')];
        }
        // SOMENTE ADMINISTRADOR: projetos de TODOS os outros usuários (os que não entraram acima),
        // com o nome do dono, para a opção "Todos os usuários" do painel.
        $others = [];
        $isAdmin = $this->user->isAdmin();
        if ($isAdmin) {
            $skip = array_merge([(int) $project->id], $linkedIds, array_map('intval', $accIds));
            foreach (Project::findAllForAdmin() as $b) {
                if (in_array((int) $b->id, $skip, true)) {
                    continue;
                }
                $others[] = [
                    'id' => (int) $b->id, 'uuid' => (string) $b->uuid, 'name' => (string) $b->name,
                    'icon' => (string) ($b->icon ?? '📁'), 'owner' => $this->ownerLabel($b),
                ];
            }
            usort($others, fn($a, $b) => strcasecmp($a['owner'] . ' ' . $a['name'], $b['owner'] . ' ' . $b['name']));
        }
        return $this->json(['ok' => true, 'links' => $links, 'available' => $available, 'is_admin' => $isAdmin, 'others' => $others]);
    }

    /** Nome (ou e-mail) do dono de um projeto, para rotular projetos de outros usuários. */
    private function ownerLabel(Project $p): string
    {
        static $cache = [];
        $uid = (int) $p->user_id;
        if (!array_key_exists($uid, $cache)) {
            $u = User::find($uid);
            $cache[$uid] = $u ? (string) ($u->name ?: $u->email) : 'usuário #' . $uid;
        }
        return $cache[$uid];
    }

    /** Cria/atualiza uma ligação. Só o dono de A; o alvo precisa ser acessível ao usuário. */
    public function linkSave(Request $request, string $uuid): JsonResponse
    {
        $project = $this->linkProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $targetId = (int) $request->input('linked_project_id', 0);
        $access   = $request->input('access', 'read') === 'write' ? 'write' : 'read';
        if ($targetId <= 0 || $targetId === (int) $project->id) {
            return $this->json(['ok' => false, 'error' => 'Projeto inválido.']);
        }
        // Alvo precisa ser acessível ao usuário. SOMENTE ADMINISTRADOR: qualquer projeto do sistema.
        $target = Project::find($targetId);
        if (!$target || ($target->status ?? '') === 'deleted') {
            return $this->json(['ok' => false, 'error' => 'Projeto inválido.']);
        }
        if (!in_array($targetId, Project::accessibleIdsFor((int) $this->user->id), true) && !$this->user->isAdmin()) {
            return $this->json(['ok' => false, 'error' => 'Você não tem acesso a esse projeto.'], 'Erro', 403);
        }
        $existing = \Ravi\Models\ProjectLink::where('project_id', '=', (int) $project->id)
            ->where('linked_project_id', '=', $targetId)->first();
        if ($existing) {
            $existing->access = $access;
            $existing->enabled = 1;
            $existing->save();
        } else {
            \Ravi\Models\ProjectLink::create([
                'project_id' => (int) $project->id, 'linked_project_id' => $targetId,
                'access' => $access, 'enabled' => 1,
            ]);
        }
        $this->refreshContext($project);
        return $this->json(['ok' => true], 'Projeto ligado.');
    }

    /** Liga/desliga ou troca o acesso de uma ligação. */
    public function linkToggle(Request $request, string $uuid): JsonResponse
    {
        $project = $this->linkProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $ln = \Ravi\Models\ProjectLink::find((int) $request->input('id', 0));
        if (!$ln || (int) $ln->project_id !== (int) $project->id) {
            return $this->json(['ok' => false, 'error' => 'Ligação não encontrada.'], 'Erro', 404);
        }
        $field = (string) $request->input('field', 'enabled');
        if ($field === 'access') {
            $ln->access = $request->input('value', 'read') === 'write' ? 'write' : 'read';
        } else {
            $ln->enabled = ($request->input('on', '0') === '1' || $request->input('on') === true) ? 1 : 0;
        }
        $ln->save();
        $this->refreshContext($project);
        return $this->json(['ok' => true]);
    }

    /** Remove uma ligação. */
    public function linkDelete(Request $request, string $uuid): JsonResponse
    {
        $project = $this->linkProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $ln = \Ravi\Models\ProjectLink::find((int) $request->input('id', 0));
        if ($ln && (int) $ln->project_id === (int) $project->id) {
            $ln->delete();
            $this->refreshContext($project);
        }
        return $this->json(['ok' => true], 'Ligação removida');
    }

    /** Desvincula o Git do projeto (não mexe no repo remoto). Só o dono. */
    public function gitUnlink(Request $request, string $uuid): JsonResponse
    {
        $project = $this->ownerOnlyProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        $project->unlinkGit();
        return $this->json(['ok' => true, 'git' => $project->gitState()], 'Git desvinculado');
    }

    /** Liga/desliga o auto-sync. Só o dono. */
    public function gitAutoSync(Request $request, string $uuid): JsonResponse
    {
        $project = $this->ownerOnlyProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        if (!$project->gitEnabled()) {
            return $this->json(['ok' => false, 'error' => 'Ligue um repositório primeiro.']);
        }
        $on = $request->input('on', '0') === '1' || $request->input('on') === true;
        $project->setAutoSync($on);
        return $this->json(['ok' => true, 'git' => $project->gitState()], $on ? 'Auto-sync ligado' : 'Auto-sync desligado');
    }

    /** Estado do Git do projeto (para polling da UI). Dono ou membro. */
    public function gitStatus(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        return $this->json(['ok' => true, 'git' => $project->gitState()]);
    }

    /**
     * Sincroniza (commit + push) sob demanda. Roda em BACKGROUND (push no host com o token
     * do DONO). Dono ou membro podem disparar; o token usado é sempre o do dono.
     */
    public function gitSync(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        if (!$project->gitEnabled()) {
            return $this->json(['ok' => false, 'error' => 'Este projeto não está ligado a um repositório.']);
        }
        $owner = $project->user();
        if (!$owner || !$owner->hasGithub()) {
            return $this->json(['ok' => false, 'error' => 'O dono do projeto precisa conectar o GitHub no perfil dele.']);
        }
        try {
            \Ravi\Core\Database::getInstance()->query(
                "INSERT INTO job_queue (uuid, queue, job_type, payload, max_attempts) VALUES (?, 'default', 'git_sync', ?, 2)",
                [uuid(), json_encode([
                    'project_id'   => $project->id,
                    'project_uuid' => $project->uuid,
                    'triggered_by' => (int) $this->user->id,
                ], JSON_UNESCAPED_UNICODE)]
            );
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'Erro ao enfileirar a sincronização: ' . $e->getMessage()]);
        }
        return $this->json(['ok' => true, 'syncing' => true], 'Sincronização em andamento…');
    }

    /** Histórico de commits do repo (para "voltar para esta versão"). Dono ou membro. */
    public function gitCommits(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        if (!$project->gitEnabled()) {
            return $this->json(['ok' => false, 'error' => 'Projeto não está ligado a um repositório.']);
        }
        $owner = $project->user();
        $token = $owner ? $owner->getGithubToken() : null;
        if (!$token) {
            return $this->json(['ok' => false, 'error' => 'O dono do projeto precisa conectar o GitHub.']);
        }
        $commits = (new \Ravi\Services\GitHubService())->listCommits(
            $token,
            (string) $project->git_repo_full_name,
            (string) ($project->git_branch ?: 'main'),
            20
        );
        return $this->json(['ok' => true, 'commits' => $commits]);
    }

    /** Volta o projeto para um commit anterior (em background). Só o dono. */
    public function gitRevert(Request $request, string $uuid): JsonResponse
    {
        $project = $this->ownerOnlyProject($uuid, $err);
        if (!$project) {
            return $err;
        }
        if (!$project->gitEnabled()) {
            return $this->json(['ok' => false, 'error' => 'Projeto não está ligado a um repositório.']);
        }
        $sha = trim((string) $request->input('sha', ''));
        if (!preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
            return $this->json(['ok' => false, 'error' => 'Commit inválido.']);
        }
        // Marca pendente para o polling da UI detectar a conclusão (revertTo zera ao terminar).
        $project->markGitPending();
        try {
            \Ravi\Core\Database::getInstance()->query(
                "INSERT INTO job_queue (uuid, queue, job_type, payload, max_attempts) VALUES (?, 'default', 'git_revert', ?, 1)",
                [uuid(), json_encode([
                    'project_id'   => $project->id,
                    'sha'          => $sha,
                    'triggered_by' => (int) $this->user->id,
                ], JSON_UNESCAPED_UNICODE)]
            );
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'Erro ao enfileirar a reversão: ' . $e->getMessage()]);
        }
        return $this->json(['ok' => true, 'reverting' => true], 'Revertendo para a versão escolhida…');
    }

    // ----------------------------------------------------------------------
    // IDE no navegador (code-server por projeto)
    // ----------------------------------------------------------------------

    /** Abre (ou reaproveita) a IDE do projeto PARA O USUÁRIO ATUAL (uma por usuário/projeto). */
    public function ideStart(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $theme = $request->input('theme') === 'light' ? 'light' : 'dark';

        // Git na IDE para QUALQUER ator do projeto (dono E membros), desde que o DONO tenha GitHub
        // conectado e o repo ligado. NENHUM token entra no container: o push/pull passa pelo proxy
        // git do host (handle → token do dono, com checagem de dono/membro a cada operação). A
        // identidade é a do ATOR — commits do membro aparecem no histórico como ele mesmo.
        $git = null;
        $owner = $project->user();
        if ($project->gitEnabled() && $owner && $owner->hasGithub()) {
            $login = (string) ($this->user->github_login ?? '');
            $git = [
                'remote' => 'https://github.com/' . $project->git_repo_full_name . '.git',
                'branch' => (string) ($project->git_branch ?: 'main'),
                'name'   => (string) ($this->user->name ?: ($login ?: 'Ravi Developer')),
                'email'  => $login !== ''
                    ? ($login . '@users.noreply.github.com')
                    : ('user' . (int) $this->user->id . '@' . (string) config('urls.host')),
            ];
        }

        $res = (new \Ravi\Services\IdeService())->start($project->uuid, (int) $this->user->id, $theme, $git);
        if (empty($res['ok'])) {
            return $this->json(['ok' => false, 'error' => $res['error'] ?? 'Falha ao abrir a IDE.']);
        }
        return $this->json(['ok' => true, 'url' => $res['url']], 'IDE pronta');
    }

    /** Estado da IDE do usuário atual neste projeto (rodando? url?). */
    public function ideStatus(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $ide = (new \Ravi\Services\IdeService())->getByProjectUser((int) $project->id, (int) $this->user->id);
        if (!$ide) {
            return $this->json(['ok' => true, 'running' => false]);
        }
        return $this->json([
            'ok'      => true,
            'running' => true,
            'url'     => (string) config('urls.ide') . '/' . $ide['token'] . '/',
        ]);
    }

    /** Troca o tema (dark/light) da IDE do usuário neste projeto AO VIVO (sem recriar). */
    public function ideTheme(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $theme = $request->input('theme') === 'light' ? 'light' : 'dark';
        $svc = new \Ravi\Services\IdeService();
        $ide = $svc->getByProjectUser((int) $project->id, (int) $this->user->id);
        if (!$ide) {
            return $this->json(['ok' => false, 'error' => 'IDE não está aberta.']);
        }
        $svc->setThemeLive((string) $ide['token'], $theme);
        return $this->json(['ok' => true, 'theme' => $theme]);
    }

    /** Para a IDE do usuário atual neste projeto. */
    public function ideStop(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $ide = (new \Ravi\Services\IdeService())->getByProjectUser((int) $project->id, (int) $this->user->id);
        if ($ide) {
            (new \Ravi\Services\IdeService())->stop($ide['token']);
        }
        // Auto-sync ao FECHAR a IDE (quando o auto-sync está ligado): empurra o trabalho manual
        // feito na IDE para o repositório antes de encerrar.
        if ($project->git_auto_sync) {
            \Ravi\Services\GitSyncService::enqueueSync($project, (int) $this->user->id, 'Sincronização automática ao fechar a IDE');
        }
        return $this->json(['ok' => true], 'IDE encerrada');
    }

    /**
     * Helper: carrega o projeto e exige que o usuário atual seja o DONO.
     * Em falha, popula $err com a JsonResponse e retorna null.
     */
    private function ownerOnlyProject(string $uuid, ?JsonResponse &$err): ?Project
    {
        if ($this->user === null) {
            $err = JsonResponse::unauthorized();
            return null;
        }
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            $err = JsonResponse::notFound('Projeto não encontrado');
            return null;
        }
        if ((int) $project->user_id !== (int) $this->user->id) {
            $err = $this->json(['ok' => false, 'error' => 'Apenas o dono do projeto configura o Git.'], 'Erro', 403);
            return null;
        }
        $err = null;
        return $project;
    }

    /** Caminho do workspace do projeto (compartilhado por todos os chats do projeto). */
    private function projectWorkspace(string $uuid): string
    {
        $base = config('sandbox.workspace.base_path');
        if (!$base) {
            $base = (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 2) . '/storage') . '/sandbox';
        }
        return rtrim((string) $base, '/') . '/' . $uuid;
    }

    /** Conta os arquivos do workspace do projeto (ignora ruído de build/processo). */
    private function countWorkspaceFiles(string $base): int
    {
        if (!is_dir($base)) {
            return 0;
        }
        $skip  = ['.maestro', '.git', 'vendor', 'node_modules', '__pycache__', '.pytest_cache', '.phpunit.cache', '.cache', '.idea'];
        $count = 0;
        $walk  = function (string $dir) use (&$walk, &$count, $skip): void {
            foreach (@scandir($dir) ?: [] as $e) {
                if ($e === '.' || $e === '..' || in_array($e, $skip, true) || $count > 3000) {
                    continue;
                }
                $full = $dir . '/' . $e;
                if (is_dir($full)) {
                    $walk($full);
                } else {
                    $count++;
                }
            }
        };
        $walk($base);
        return $count;
    }

    /**
     * Importa um projeto pronto: recebe um .zip, extrai e organiza no workspace do projeto,
     * deixando tudo pronto para iniciar um chat com o código já no lugar.
     */
    public function upload(Request $request, string $uuid): Response|JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }

        $file = $_FILES['archive'] ?? null;
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $msg = ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
                ? 'Arquivo maior que o limite do servidor.'
                : 'Nenhum arquivo enviado.';
            return $this->json(['ok' => false, 'error' => $msg]);
        }
        if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'zip') {
            return $this->json(['ok' => false, 'error' => 'Envie um arquivo .zip.']);
        }
        if ((int) ($file['size'] ?? 0) > 100 * 1024 * 1024) {
            return $this->json(['ok' => false, 'error' => 'Arquivo muito grande (máx. 100MB).']);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $this->json(['ok' => false, 'error' => 'Upload inválido.']);
        }

        $base = $this->projectWorkspace($project->uuid);
        if (!is_dir($base)) {
            @mkdir($base, 0777, true);
        }

        // Salva o .zip num local persistente (o tmp some ao fim da requisição) e processa em
        // BACKGROUND: sistemas grandes demoram para descompactar/analisar/configurar. A tela do
        // projeto acompanha o progresso (import.json) e bloqueia chats até concluir.
        $uploadsDir = (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 2) . '/storage') . '/uploads';
        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0777, true);
        }
        $zipPath = $uploadsDir . '/import-' . $project->uuid . '-' . uniqid() . '.zip';
        if (!@move_uploaded_file($tmp, $zipPath)) {
            return $this->json(['ok' => false, 'error' => 'Não consegui salvar o arquivo enviado.']);
        }

        try {
            // Marca "na fila" já — a UI bloqueia os chats imediatamente.
            (new \Ravi\Services\ProjectImportService(new \Ravi\Services\SandboxService()))->markQueued($base);

            // Instruções opcionais do usuário sobre o que fazer com o projeto importado
            // (ex.: "use isto como modelo para construir um projeto novo que faz X").
            $instructions = trim((string) $request->post('instructions', ''));
            if (mb_strlen($instructions) > 8000) {
                $instructions = mb_substr($instructions, 0, 8000);
            }

            \Ravi\Core\Database::getInstance()->query(
                "INSERT INTO job_queue (uuid, queue, job_type, payload, max_attempts) VALUES (?, 'default', 'process_project_import', ?, 2)",
                [uuid(), json_encode([
                    'project_id'    => $project->id,
                    'project_uuid'  => $project->uuid,
                    'zip_path'      => $zipPath,
                    'instructions'  => $instructions,
                ], JSON_UNESCAPED_UNICODE)]
            );
            $project->update(['last_activity_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            @unlink($zipPath);
            return $this->json(['ok' => false, 'error' => 'Erro ao enfileirar o processamento: ' . $e->getMessage()]);
        }

        return $this->json([
            'ok'         => true,
            'processing' => true,
            'message'    => 'Upload recebido. Processando o projeto em segundo plano…',
        ]);
    }

    /** Terminal web do projeto (sob demanda, montando o workspace compartilhado). */
    public function terminal(Request $request, string $uuid): Response
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            $this->abort(404, 'Projeto não encontrado');
        }
        // Não abre terminal enquanto o projeto está sendo importado/processado.
        if (\Ravi\Services\ProjectImportService::isProcessing($this->projectWorkspace($project->uuid))) {
            return $this->redirect('/projects/' . $project->uuid);
        }
        return $this->view('projects/terminal', ['project' => $project]);
    }

    /** Compartilha o projeto com um usuário (por e-mail). Só o DONO pode. */
    public function share(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        if ((int) $project->user_id !== (int) $this->user->id) {
            return $this->json(['ok' => false, 'error' => 'Apenas o dono pode compartilhar.'], 'Erro', 403);
        }
        $email = trim((string) $request->input('email', ''));
        if ($email === '') {
            return $this->json(['ok' => false, 'error' => 'Informe um e-mail.']);
        }
        $res = $project->shareWithEmail($email);
        if (empty($res['ok'])) {
            return $this->json(['ok' => false, 'error' => $res['error'] ?? 'Falha ao compartilhar.']);
        }
        // Notifica o novo membro por e-mail (respeita o toggle dele + SMTP).
        try {
            (new \Ravi\Services\NotificationService())->projectShared(
                (int) ($res['user']['id'] ?? 0),
                $project,
                (string) ($this->user->name ?? 'Um colega')
            );
        } catch (\Throwable $e) {}
        return $this->json(['ok' => true, 'user' => $res['user']], 'Projeto compartilhado');
    }

    /** Busca usuários para compartilhar (por nome/e-mail), excluindo o dono e quem já é membro. */
    public function shareUsers(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        if ((int) $project->user_id !== (int) $this->user->id) {
            return $this->json(['ok' => false, 'error' => 'Apenas o dono pode compartilhar.'], 'Erro', 403);
        }
        $exclude = [(int) $project->user_id];
        foreach ($project->members() as $m) {
            $exclude[] = (int) $m['user_id'];
        }
        $q = trim((string) $request->input('q', ''));
        $like = '%' . $q . '%';
        $sql = "SELECT id, name, email, avatar_url FROM users WHERE is_active = 1 AND (name LIKE ? OR email LIKE ?)";
        $args = [$like, $like];
        if ($exclude) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
            $args = array_merge($args, $exclude);
        }
        $sql .= ' ORDER BY name ASC LIMIT 8';
        $rows = \Ravi\Core\Database::getInstance()->fetchAll($sql, $args);
        $out = array_map(fn ($r) => [
            'id'      => (int) $r['id'],
            'name'    => (string) $r['name'],
            'email'   => (string) $r['email'],
            'avatar'  => $r['avatar_url'] ?: null,
            'initial' => strtoupper(mb_substr(($r['name'] ?: $r['email']), 0, 1)),
        ], $rows);
        return $this->json(['users' => $out]);
    }

    /** Remove o acesso de um membro. Só o DONO pode. */
    public function unshare(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        if ((int) $project->user_id !== (int) $this->user->id) {
            return $this->json(['ok' => false, 'error' => 'Apenas o dono pode gerenciar membros.'], 'Erro', 403);
        }
        $project->removeMember((int) $request->input('user_id', 0));
        return $this->json(['ok' => true], 'Acesso removido');
    }

    /**
     * Transfere a titularidade do projeto para outro usuário. Só o DONO pode, e a UI exige
     * confirmação por código (proteção contra clique acidental). O dono antigo vira membro.
     */
    public function transferOwnership(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        if ((int) $project->user_id !== (int) $this->user->id) {
            return $this->json(['ok' => false, 'error' => 'Apenas o dono pode transferir a titularidade.'], 'Erro', 403);
        }
        $newOwnerId = (int) $request->input('user_id', 0);
        if ($newOwnerId <= 0) {
            return $this->json(['ok' => false, 'error' => 'Selecione para quem transferir.']);
        }
        $res = $project->transferOwnershipTo($newOwnerId);
        if (empty($res['ok'])) {
            return $this->json(['ok' => false, 'error' => $res['error'] ?? 'Falha ao transferir.']);
        }
        // Avisa o novo dono por e-mail (respeita o toggle dele + SMTP). Best-effort.
        try {
            (new \Ravi\Services\NotificationService())->projectTransferred(
                (int) ($res['new_owner']['id'] ?? 0),
                $project,
                (string) ($this->user->name ?? 'Um colega')
            );
        } catch (\Throwable $e) {}
        return $this->json(['ok' => true, 'new_owner' => $res['new_owner']], 'Titularidade transferida.');
    }

    /** Um MEMBRO sai de um projeto compartilhado (remove o próprio acesso). O dono não usa isto. */
    public function leave(Request $request, string $uuid): Response
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return $this->redirectWithError('/projects', 'Projeto não encontrado.');
        }
        if ((int) $project->user_id === (int) $this->user->id) {
            return $this->redirectWithError('/projects/' . $project->uuid, 'Você é o dono — exclua o projeto em vez de sair.');
        }
        $project->removeMember((int) $this->user->id);
        return $this->redirectWithSuccess('/projects', 'Você saiu do projeto compartilhado.');
    }

    /** Estado do processamento do import (polling da tela do projeto). */
    public function importStatus(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = $this->findProjectByUuid($uuid);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $status = \Ravi\Services\ProjectImportService::readStatus($this->projectWorkspace($project->uuid));
        return $this->json(['ok' => true, 'import' => $status]);
    }

    /** Extrai um .zip no destino com sanitização (anti path-traversal) e remoção de pasta-wrapper única. */
    private function extractZipInto(string $zipPath, string $dest): array
    {
        if (!class_exists('\ZipArchive')) {
            return ['ok' => false, 'error' => 'Suporte a ZIP indisponível no servidor.'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'Não consegui abrir o .zip (corrompido?).'];
        }

        // Pasta-wrapper única (ex.: "meu-projeto/...") → extrai o conteúdo na raiz do workspace.
        $top = null; $single = true;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n     = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $first = explode('/', ltrim($n, '/'))[0] ?? '';
            if ($first === '' || $first === '__MACOSX') { continue; }
            if ($top === null) { $top = $first; }
            elseif ($top !== $first) { $single = false; break; }
        }
        $strip    = ($single && $top !== null) ? ($top . '/') : '';
        $destReal = rtrim($dest, '/');
        $count    = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (strpos($name, '__MACOSX/') === 0) { continue; }
            if ($strip !== '' && strpos($name, $strip) === 0) {
                $name = substr($name, strlen($strip));
            }
            $name = ltrim($name, '/');
            if ($name === '' || strpos($name, '..') !== false || strpos($name, "\0") !== false) {
                continue;
            }
            $target = $destReal . '/' . $name;
            if (substr($name, -1) === '/') {
                @mkdir($target, 0777, true);
                continue;
            }
            @mkdir(dirname($target), 0777, true);
            $data = $zip->getFromIndex($i);
            if ($data !== false && @file_put_contents($target, $data) !== false) {
                $count++;
            }
        }
        $zip->close();

        return $count === 0
            ? ['ok' => false, 'error' => 'O .zip está vazio ou sem arquivos válidos.']
            : ['ok' => true, 'count' => $count];
    }
    
    /**
     * Update project
     */
    public function update(Request $request, string $uuid): Response|JsonResponse
    {
        $this->requireAuth($request);
        
        $project = $this->findProjectByUuid($uuid);
        
        if ($project === null) {
            if ($this->wantsJson($request)) {
                return JsonResponse::notFound('Projeto não encontrado');
            }
            $this->abort(404, 'Projeto não encontrado');
        }
        
        if (!$this->verifyCsrf($request) && !$this->wantsJson($request)) {
            return $this->redirectWithError('/projects/' . $uuid . '/edit', 'Token de segurança inválido.');
        }
        
        $validation = $this->validate($request, [
            'name' => 'required|min:2|max:200',
            'description' => 'nullable|max:2000',
            'type' => 'nullable|in:web,app,both',
            'icon' => 'nullable|max:50',
            'color' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
        ]);
        
        if (!$validation['valid']) {
            if ($this->wantsJson($request)) {
                return JsonResponse::validationError($validation['errors']);
            }
            return $this->back();
        }
        
        $project->update([
            'name' => $validation['data']['name'],
            'description' => $validation['data']['description'] ?? null,
            'icon' => projectIcon($validation['data']['icon'] ?: $project->icon),
            'color' => $validation['data']['color'] ?: $project->color,
        ]);
        
        if ($this->wantsJson($request)) {
            return $this->json($this->formatProject($project), 'Projeto atualizado com sucesso');
        }
        
        return $this->redirectWithSuccess(
            '/projects/' . $uuid,
            'Projeto atualizado com sucesso!'
        );
    }
    
    /**
     * Archive project
     */
    public function archive(Request $request, string $uuid): Response|JsonResponse
    {
        $this->requireAuth($request);
        
        $project = $this->findProjectByUuid($uuid);
        
        if ($project === null) {
            if ($this->wantsJson($request)) {
                return JsonResponse::notFound('Projeto não encontrado');
            }
            $this->abort(404, 'Projeto não encontrado');
        }
        
        $project->archive();
        
        if ($this->wantsJson($request)) {
            return $this->json($this->formatProject($project), 'Projeto arquivado com sucesso');
        }
        
        return $this->redirectWithSuccess('/projects', 'Projeto arquivado com sucesso.');
    }
    
    /**
     * Restore archived project
     */
    public function restore(Request $request, string $uuid): Response|JsonResponse
    {
        $this->requireAuth($request);
        
        $project = Project::where('user_id', '=', $this->user->id)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'archived')
            ->first();
        
        if ($project === null) {
            if ($this->wantsJson($request)) {
                return JsonResponse::notFound('Projeto não encontrado');
            }
            $this->abort(404, 'Projeto não encontrado');
        }
        
        $project->update(['status' => 'active']);
        
        if ($this->wantsJson($request)) {
            return $this->json($this->formatProject($project), 'Projeto restaurado com sucesso');
        }
        
        return $this->redirectWithSuccess('/projects/' . $uuid, 'Projeto restaurado com sucesso!');
    }
    
    /**
     * Delete project (soft delete)
     */
    /**
     * #4 — Clona um projeto: cria um novo projeto (do usuário atual) e copia SÓ os arquivos do
     * workspace (o código). NÃO herda GitHub nem servidores remotos — o clone nasce sem git e sem
     * fontes externas (ganha a própria chave SSH). Útil para testar variações sem afetar o original.
     */
    public function cloneProject(Request $request, string $uuid): Response|JsonResponse
    {
        $this->requireAuth($request);
        $src = $this->findProjectByUuid($uuid);
        if ($src === null) {
            return $this->wantsJson($request) ? JsonResponse::notFound('Projeto não encontrado') : $this->redirectWithError('/projects', 'Projeto não encontrado.');
        }

        $new = Project::create([
            'user_id'     => $this->user->id,
            'name'        => mb_substr(trim((string) $src->name) . ' (cópia)', 0, 200),
            'description' => $src->description,
            'type'        => in_array($src->type ?? 'web', ['web', 'app', 'both'], true) ? $src->type : 'web',
            'icon'        => projectIcon($src->icon),
            'color'       => $src->color ?: '#6366f1',
            'status'      => 'active',
            'settings'    => $src->settings ?: json_encode([]),
            // git_* e ssh_* ficam NO PADRÃO (desligado/nulo) → sem GitHub, sem chave herdada.
        ]);
        if ($new === null) {
            return $this->wantsJson($request) ? $this->jsonError('Erro ao clonar projeto', 500) : $this->redirectWithError('/projects/' . $src->uuid, 'Erro ao clonar o projeto.');
        }
        // Chave SSH PRÓPRIA do clone (não herda a do original).
        try { $new->ensureSshKey(); } catch (\Throwable $e) {}

        // Copia o CÓDIGO do workspace (sem .git → sem histórico/associação; sem estado de processo).
        $from = $this->projectWorkspace($src->uuid);
        $to   = $this->projectWorkspace($new->uuid);
        @mkdir($to, 0777, true);
        if (is_dir($from)) {
            $ex = '--exclude=.git --exclude=.maestro --exclude=.ravi --exclude=.vscode/sftp.json';
            @exec('rsync -a ' . $ex . ' ' . escapeshellarg(rtrim($from, '/') . '/') . ' ' . escapeshellarg(rtrim($to, '/') . '/') . ' 2>&1', $o, $rc);
            if ($rc !== 0) { // fallback sem rsync
                @exec('cp -a ' . escapeshellarg(rtrim($from, '/') . '/.') . ' ' . escapeshellarg(rtrim($to, '/') . '/') . ' 2>/dev/null');
                @exec('rm -rf ' . escapeshellarg(rtrim($to, '/') . '/.git') . ' ' . escapeshellarg(rtrim($to, '/') . '/.maestro') . ' 2>/dev/null');
            }
            @exec('chmod -R a+rwX ' . escapeshellarg($to) . ' 2>/dev/null');
        }
        // NÃO copiamos project_ssh_sources (servidores) nem o vínculo git — clone nasce limpo.

        if ($this->wantsJson($request)) {
            return $this->json(['ok' => true, 'uuid' => $new->uuid, 'redirect' => '/projects/' . $new->uuid], 'Projeto clonado.');
        }
        return $this->redirectWithSuccess('/projects/' . $new->uuid, 'Projeto clonado (sem GitHub e sem servidores). Configure o que precisar.');
    }

    public function destroy(Request $request, string $uuid): Response|JsonResponse
    {
        $this->requireAuth($request);

        $project = $this->findProjectByUuid($uuid);

        if ($project === null) {
            if ($this->wantsJson($request)) {
                return JsonResponse::notFound('Projeto não encontrado');
            }
            $this->abort(404, 'Projeto não encontrado');
        }
        
        // Limpa o sandbox: remove container persistente (se houver) e os arquivos do workspace.
        try {
            $sandbox = new \Ravi\Services\SandboxService();
            $sandbox->removeContainer('ravi-sandbox-' . $project->uuid);
            $base = config('sandbox.workspace.base_path') ?: (dirname(__DIR__, 2) . '/storage/sandbox');
            $ws = realpath(rtrim($base, '/') . '/' . $project->uuid);
            if ($ws !== false && strpos($ws, realpath($base) . DIRECTORY_SEPARATOR) === 0) {
                $this->removeDirectory($ws);
            }
        } catch (\Throwable $e) {
            logger()->warning('Falha ao limpar sandbox do projeto excluído: ' . $e->getMessage());
        }

        // Remove as IDEs (code-server) do projeto — containers + registros de TODOS os membros.
        // Necessário porque a pausa-não-destrói não expira sozinha por dias (reap), e o destroy do
        // projeto deve liberar tudo na hora.
        try {
            $ide = new \Ravi\Services\IdeService();
            foreach (\Ravi\Core\Database::getInstance()->fetchAll("SELECT token FROM ide_sessions WHERE project_id = ?", [(int) $project->id]) as $s) {
                $ide->stop((string) $s['token']);
            }
        } catch (\Throwable $e) {
            logger()->warning('Falha ao remover IDEs do projeto excluído: ' . $e->getMessage());
        }

        $project->softDelete();

        if ($this->wantsJson($request)) {
            return $this->json(null, 'Projeto excluído com sucesso');
        }
        
        return $this->redirectWithSuccess('/projects', 'Projeto excluído com sucesso.');
    }
    
    /**
     * Remove um diretório recursivamente (usado na limpeza do workspace ao excluir projeto).
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Find project by UUID belonging to current user
     */
    private function findProjectByUuid(string $uuid): ?Project
    {
        // Dono OU membro compartilhado (acesso de trabalho). O administrador acessa qualquer projeto.
        $p = Project::findAccessible($uuid, (int) $this->user->id);
        return ($p && ($p->status ?? '') !== 'deleted') ? $p : null;
    }

    /** O usuário atual só chegou a este projeto por ser ADMIN (não é dono nem membro dele)? */
    private function adminViewOnly(Project $project): bool
    {
        if ((int) $project->user_id === (int) $this->user->id) {
            return false;
        }
        foreach ($project->members() as $m) {
            if ((int) $m['user_id'] === (int) $this->user->id) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Format project for JSON response
     */
    private function formatProject(Project $project): array
    {
        return [
            'id' => $project->uuid,
            'name' => $project->name,
            'description' => $project->description,
            'icon' => $project->icon,
            'color' => $project->color,
            'status' => $project->status,
            'settings' => $project->getSettings(),
            'conversation_count' => $project->conversation_count ?? 0,
            'active_conversations' => $project->active_conversations ?? 0,
            'owner_name' => $project->owner_name ?? null,
            'is_mine' => isset($project->is_mine) ? (bool) $project->is_mine : null,
            'created_at' => $project->created_at,
            'updated_at' => $project->updated_at,
        ];
    }
    
    /**
     * Format conversation for JSON response
     */
    private function formatConversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->uuid,
            'title' => $conversation->title,
            'status' => $conversation->status,
            'model' => $conversation->model_used,
            'tokens' => $conversation->totalTokens(),
            'duration' => $conversation->getDuration(),
            'created_at' => $conversation->created_at,
            'updated_at' => $conversation->updated_at,
        ];
    }
}
