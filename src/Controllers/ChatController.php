<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\{Request, Response, JsonResponse};
use Ravi\Models\{Project, Conversation, Message};
use Ravi\Services\{AgentService, ClaudeService};

/**
 * Chat Controller
 * Handles chat interactions and agent execution
 */
class ChatController extends Controller
{
    /**
     * Abre o chat de um projeto: usa a conversa mais recente ou cria uma nova
     */
    public function project(Request $request, string $projectUuid): Response
    {
        $this->requireAuth($request);

        $project = Project::findAccessible($projectUuid, (int) $this->user->id);

        if ($project === null) {
            $this->abort(404, 'Projeto não encontrado');
        }

        // Bloqueio: enquanto o projeto importado está em processamento, não abre chat —
        // manda para o hub do projeto, onde o progresso é monitorado.
        if ($this->importProcessing($project)) {
            return $this->redirect('/projects/' . $project->uuid);
        }

        $conversation = Conversation::where('project_id', '=', $project->id)
            ->orderBy('created_at', 'DESC')
            ->first();

        if ($conversation === null) {
            // NAO cria automaticamente: abre o chat vazio (a sidebar mostra "Nenhuma conversa ainda").
            // A primeira conversa nasce quando o usuario envia a primeira mensagem (chat.js).
            $conversation = new Conversation([
                'uuid' => '',
                'project_id' => $project->id,
                'title' => 'Nova conversa',
                'status' => 'pending',
            ]);
        }

        return $this->renderChat($project, $conversation);
    }

    /**
     * Abre uma conversa específica de um projeto
     */
    public function conversation(Request $request, string $projectUuid, string $conversationUuid): Response
    {
        $this->requireAuth($request);

        $project = Project::findAccessible($projectUuid, (int) $this->user->id);

        if ($project === null) {
            $this->abort(404, 'Projeto não encontrado');
        }

        if ($this->importProcessing($project)) {
            return $this->redirect('/projects/' . $project->uuid);
        }

        $conversation = Conversation::where('uuid', '=', $conversationUuid)
            ->where('project_id', '=', $project->id)
            ->first();

        if ($conversation === null) {
            $this->abort(404, 'Conversa não encontrada');
        }

        return $this->renderChat($project, $conversation);
    }

    /**
     * Renderiza a interface de chat para uma conversa
     */
    private function renderChat(Project $project, Conversation $conversation): Response
    {
        $messages = Message::where('conversation_id', '=', $conversation->id)
            ->orderBy('created_at', 'ASC')
            ->get();

        // Lista de conversas do projeto para a sidebar (um projeto tem vários chats).
        $conversations = Conversation::where('project_id', '=', $project->id)
            ->orderBy('updated_at', 'DESC')
            ->get();

        return $this->view('chat/index', [
            'conversation' => $conversation,
            'project' => $project,
            'conversations' => $conversations,
            'messages' => $messages,
            // Banner de entrega só aparece se ESTA conversa já concluiu (status 'completed').
            // As conversas do projeto dividem o mesmo workspace, então uma entrega antiga (ou
            // arquivos do estágio de plano) NÃO deve marcar uma conversa nova como "entregue".
            'delivery' => $conversation->status === 'completed'
                ? $this->deliveryInfo($project->uuid)
                : ['zip' => null, 'hasWebUi' => false],
            'hasApiKey' => $this->user->hasApiKey(),
            'wsUrl' => $this->websocketUrl(),
            'custoVisivel' => $this->custoVisivelNoChat(),
            'rotuloModelo' => $this->rotuloModeloEmUso(),
        ]);
    }

    /** O custo da conversa aparece no cabeçalho do chat? (chave 'chat' das flags de uso) */
    private function custoVisivelNoChat(): bool
    {
        try {
            return (bool) ((new \Ravi\Services\UsageService())->flags()['chat'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Modelo REAL em uso (resolvido como o motor resolve): Arquiteto, mais os agentes se diferirem. */
    private function rotuloModeloEmUso(): string
    {
        $uid = (int) ($this->user->id ?? 0);
        $arquiteto = \Ravi\Services\ModelService::forUser($uid, 'autonomous');
        $agentes = \Ravi\Services\ModelService::forUser($uid, \Ravi\Services\ModelService::SCOPE_AGENTS);
        return \Ravi\Services\ModelService::label($arquiteto)
            . ($agentes !== $arquiteto ? ' · agentes: ' . \Ravi\Services\ModelService::label($agentes) : '');
    }

    /**
     * Estado de entrega do projeto (persistente no disco): existe um .zip em delivery/?
     * tem interface web pra preview? Usado para renderizar o banner de entrega no SERVIDOR,
     * garantindo que ele apareça sempre ao abrir a conversa (sem depender de JS/WebSocket).
     */
    private function deliveryInfo(string $projectUuid): array
    {
        $base = $this->workspacePath($projectUuid);
        $zip  = null;
        foreach (glob($base . '/delivery/*.zip') ?: [] as $f) {
            $zip = 'delivery/' . basename($f);
            break;
        }

        $hasWebUi = false;
        $isMobile = false;
        $demo     = null;
        $m        = $this->readPreviewMeta($base);
        if (is_array($m)) {
            $hasWebUi = !empty($m['has_web_ui']);
            $isMobile = ($m['type'] ?? '') === 'mobile';
            // Credenciais de demonstração (para o usuário acessar e testar) — mostradas no card.
            $dc = $m['demo_credentials'] ?? null;
            if (is_array($dc) && !empty($dc['user'])) {
                $demo = ['user' => (string) $dc['user'], 'password' => (string) ($dc['password'] ?? '')];
            }
        }
        if (!$hasWebUi && !$isMobile) {
            $docroot  = is_dir($base . '/public') ? 'public' : '.';
            $hasWebUi = is_file($base . '/' . $docroot . '/index.php') || is_file($base . '/' . $docroot . '/index.html');
        }

        // Mobile não tem "web UI" no sentido clássico, mas é previewável (export web em molduras).
        return ['zip' => $zip, 'hasWebUi' => $hasWebUi, 'mobile' => $isMobile, 'previewable' => $hasWebUi || $isMobile, 'demo' => $demo];
    }

    /**
     * Lê a metadata de preview (.maestro/app.json). Rede de segurança: se faltar, cai para um
     * app.json na RAIZ do projeto QUANDO ele é claramente metadata de preview web (o arquiteto às
     * vezes grava no lugar errado) — e não um app.config do Expo (que traz a chave 'expo').
     */
    private function readPreviewMeta(string $base): ?array
    {
        $f = $base . '/.maestro/app.json';
        if (is_file($f)) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d)) {
                return $d;
            }
        }
        $root = $base . '/app.json';
        if (is_file($root)) {
            $d = json_decode((string) file_get_contents($root), true);
            if (is_array($d) && empty($d['expo'])
                && (isset($d['start_command']) || isset($d['demo_credentials']) || array_key_exists('has_web_ui', $d))) {
                return $d;
            }
        }
        return null;
    }

    /**
     * Monta a URL do WebSocket (wss em HTTPS, via proxy /ws do nginx)
     */
    private function websocketUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);

        // Em HTTPS usa wss:// pelo proxy /ws do nginx (evita mixed-content e porta interna)
        return $isHttps ? "wss://{$host}/ws" : "ws://{$host}:" . config('app.websocket_port', 8081);
    }

    /**
     * Start new conversation from project context
     */
    public function startNew(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $this->requireApiKey($request);
        
        $validation = $this->validate($request, [
            'project_id' => 'required',
            'message' => 'required|min:1|max:50000',
            'title' => 'nullable|max:300',
        ]);
        
        if (!$validation['valid']) {
            return JsonResponse::validationError($validation['errors']);
        }
        
        // Find project (dono ou membro)
        $project = Project::findAccessible((string) $validation['data']['project_id'], (int) $this->user->id);
        if ($project && ($project->status ?? '') === 'deleted') { $project = null; }

        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }

        if ($this->importProcessing($project)) {
            return $this->jsonError('O projeto ainda está sendo processado. Aguarde a conclusão para iniciar um chat.', 409);
        }

        // Generate title from message if not provided
        $title = $validation['data']['title'] ?? $this->generateTitle($validation['data']['message']);
        
        // Create conversation
        $conversation = Conversation::create([
            'project_id' => $project->id,
            'title' => $title,
            'status' => 'pending',
            'model_used' => config('claude.model', 'claude-sonnet-5'),
        ]);
        
        if ($conversation === null) {
            return $this->jsonError('Erro ao criar conversa', 500);
        }
        
        // Create initial message
        $message = Message::createUserMessage(
            $conversation->id,
            $validation['data']['message']
        );
        
        if ($message === null) {
            $conversation->delete();
            return $this->jsonError('Erro ao criar mensagem', 500);
        }
        
        return $this->json([
            'conversation' => [
                'id' => $conversation->uuid,
                'title' => $conversation->title,
                'status' => $conversation->status,
            ],
            'message' => [
                'id' => $message->uuid,
                'content' => $message->content,
            ],
            'redirect' => '/chat/' . $conversation->uuid,
        ], 'Conversa iniciada', 201);
    }
    
    /**
     * Lista os arquivos gerados no workspace do projeto (para o painel de arquivos do chat).
     */
    public function files(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);

        $project = Project::findAccessible($projectUuid, (int) $this->user->id);

        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }

        $base = $this->workspacePath($project->uuid);
        $files = [];
        $zip = null;

        if (is_dir($base)) {
            // Pula dependências, processo (.maestro), a CÓPIA de entrega (delivery/ = duplicata do projeto
            // que vira o .zip) e sessões/caches de runtime — senão o painel infla a contagem com lixo.
            $skip = ['.maestro', '__pycache__', '.pytest_cache', '.git', 'node_modules', 'vendor', '.cache', '.idea', 'delivery', 'sessions'];
            $walk = function (string $dir, string $rel) use (&$walk, &$files, &$zip, $skip): void {
                $entries = @scandir($dir) ?: [];
                sort($entries);
                foreach ($entries as $e) {
                    if ($e === '.' || $e === '..' || in_array($e, $skip, true)) {
                        continue;
                    }
                    if (count($files) >= 500) {
                        return;
                    }
                    $full = $dir . '/' . $e;
                    $r = $rel === '' ? $e : $rel . '/' . $e;
                    if (is_dir($full)) {
                        $walk($full, $r);
                    } else {
                        // Ignora artefatos de runtime (arquivos de sessão e bancos de teste).
                        if (strncmp($e, 'sess_', 5) === 0 || preg_match('/\.(sqlite3?|db)$/i', $e)) {
                            continue;
                        }
                        $files[] = ['path' => $r, 'size' => (int) (@filesize($full) ?: 0)];
                        if ($zip === null && preg_match('/\.zip$/i', $e)) {
                            $zip = $r;
                        }
                    }
                }
            };
            $walk($base, '');
        }

        // O .zip de entrega fica em delivery/ (pulado no walk acima) — localiza-o para o download.
        if ($zip === null && is_dir($base)) {
            foreach (glob($base . '/delivery/*.zip') ?: [] as $zf) {
                $zip = 'delivery/' . basename($zf);
                break;
            }
        }

        // Tamanho REAL do projeto no servidor (inclui deps/.git/delivery — é o footprint em disco).
        // du é instantâneo; fallback soma os arquivos listados se du não estiver disponível.
        $totalSize = 0;
        $real = is_dir($base) ? realpath($base) : false;
        if ($real !== false) {
            $duOut = [];
            @exec('du -sb ' . escapeshellarg($real) . ' 2>/dev/null', $duOut, $duRc);
            if ($duRc === 0 && !empty($duOut[0])) {
                $totalSize = (int) strtok(trim($duOut[0]), " \t");
            } else {
                foreach ($files as $f) { $totalSize += (int) $f['size']; }
            }
        }

        return $this->json([
            'files'      => $files,
            'zip'        => $zip,
            'count'      => count($files),
            'total_size' => $totalSize,
        ]);
    }

    /**
     * Lista o conteúdo IMEDIATO de um diretório do workspace (navegação por pastas).
     * GET ?path=<rel>. No root também devolve o .zip de entrega e o tamanho total em disco.
     */
    public function lsDir(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $base = is_dir($this->workspacePath($project->uuid)) ? realpath($this->workspacePath($project->uuid)) : false;
        if ($base === false) {
            return $this->json(['ok' => true, 'path' => '', 'dirs' => [], 'files' => [], 'zip' => null, 'total_size' => 0]);
        }

        // Resolve o diretório pedido, confinado ao workspace (anti path-traversal).
        $rel = trim((string) $request->input('path', ''), '/');
        $dir = $base;
        if ($rel !== '' && strpos($rel, '..') === false) {
            $cand = realpath($base . '/' . $rel);
            if ($cand !== false && strpos($cand, $base . DIRECTORY_SEPARATOR) === 0 && is_dir($cand)) {
                $dir = $cand;
            } else {
                $rel = '';
            }
        } else {
            $rel = '';
        }

        // Dirs que não vale navegar (processo/deps/saída) — ficam ocultos da árvore.
        $skipDirs = ['.maestro', '__pycache__', '.pytest_cache', '.cache', '.idea', 'sessions', '.git', 'node_modules', 'vendor', 'delivery'];
        $dirs = [];
        $files = [];
        foreach (@scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $full = $dir . '/' . $e;
            if (is_dir($full)) {
                if (in_array($e, $skipDirs, true)) {
                    continue;
                }
                $dirs[] = ['name' => $e];
            } else {
                if (strncmp($e, 'sess_', 5) === 0 || preg_match('/\.(sqlite3?|db)$/i', $e)) {
                    continue;
                }
                $files[] = ['name' => $e, 'size' => (int) (@filesize($full) ?: 0)];
            }
        }
        usort($dirs, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        $out = ['ok' => true, 'path' => $rel, 'dirs' => $dirs, 'files' => $files];
        if ($rel === '') {
            $zip = null;
            foreach (glob($base . '/delivery/*.zip') ?: [] as $zf) {
                $zip = 'delivery/' . basename($zf);
                break;
            }
            if ($zip === null) {
                foreach (glob($base . '/*.zip') ?: [] as $zf) {
                    $zip = basename($zf);
                    break;
                }
            }
            $totalSize = 0;
            $duOut = [];
            @exec('du -sb ' . escapeshellarg($base) . ' 2>/dev/null', $duOut, $duRc);
            if ($duRc === 0 && !empty($duOut[0])) {
                $totalSize = (int) strtok(trim($duOut[0]), " \t");
            }
            $out['zip'] = $zip;
            $out['total_size'] = $totalSize;
        }
        return $this->json($out);
    }

    /**
     * Baixa um arquivo do workspace (ou o ZIP de entrega) com proteção contra path traversal.
     */
    public function download(Request $request, string $projectUuid)
    {
        $this->requireAuth($request);

        $project = Project::findAccessible($projectUuid, (int) $this->user->id);

        if ($project === null) {
            $this->abort(404, 'Projeto não encontrado');
        }

        $base = realpath($this->workspacePath($project->uuid));
        $rel  = (string) $request->get('file', '');

        if ($base === false || $rel === '') {
            $this->abort(404, 'Arquivo não encontrado');
        }

        $target = realpath($base . '/' . $rel);
        // Garante que o alvo está DENTRO do workspace do projeto (anti path-traversal).
        if ($target === false || strpos($target, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($target)) {
            $this->abort(404, 'Arquivo não encontrado');
        }

        $mime = 'application/octet-stream';
        if (preg_match('/\.zip$/i', $target)) {
            $mime = 'application/zip';
        } elseif (preg_match('/\.apk$/i', $target)) {
            $mime = 'application/vnd.android.package-archive';
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . basename($target) . '"');
        header('Content-Length: ' . filesize($target));
        header('X-Content-Type-Options: nosniff');
        readfile($target);
        exit;
    }

    /**
     * Retorna o conteúdo de um arquivo do workspace (para a modal de visualização/edição).
     */
    public function viewFile(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);

        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }

        $target = $this->resolveWorkspaceFile($project->uuid, (string) $request->get('file', ''));
        if ($target === null) {
            return JsonResponse::notFound('Arquivo não encontrado');
        }

        $size = (int) (@filesize($target) ?: 0);
        if ($size > 2 * 1024 * 1024) {
            return $this->json(['path' => $request->get('file'), 'binary' => true, 'reason' => 'Arquivo muito grande (> 2MB).', 'size' => $size]);
        }

        $content = (string) @file_get_contents($target);
        // Heurística simples de binário: presença de byte nulo.
        if (strpos($content, "\0") !== false) {
            return $this->json(['path' => $request->get('file'), 'binary' => true, 'reason' => 'Arquivo binário.', 'size' => $size]);
        }

        return $this->json([
            'path'    => $request->get('file'),
            'content' => $content,
            'size'    => $size,
            'binary'  => false,
        ]);
    }

    /**
     * Salva o conteúdo editado de um arquivo existente do workspace.
     */
    public function saveFile(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);

        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }

        $rel = (string) $request->input('file', '');
        $target = $this->resolveWorkspaceFile($project->uuid, $rel);
        if ($target === null) {
            return JsonResponse::notFound('Arquivo não encontrado');
        }

        $content = (string) $request->input('content', '');
        if (@file_put_contents($target, $content) === false) {
            return $this->jsonError('Não foi possível salvar o arquivo (verifique as permissões).', 500);
        }

        return $this->json(['saved' => true, 'path' => $rel, 'size' => strlen($content)], 'Arquivo salvo');
    }

    /**
     * Anexa um arquivo enviado no chat ao workspace do projeto (em uploads/), para o agente usar.
     */
    public function attach(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);

        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->json(['ok' => false, 'error' => 'Nenhum arquivo enviado.']);
        }
        if ((int) ($file['size'] ?? 0) > 50 * 1024 * 1024) {
            return $this->json(['ok' => false, 'error' => 'Arquivo muito grande (máx. 50MB).']);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $this->json(['ok' => false, 'error' => 'Upload inválido.']);
        }

        // Sanitiza o nome (sem caminho, sem caracteres perigosos) e evita colisão.
        $orig = (string) ($file['name'] ?? 'arquivo');
        $name = preg_replace('/[^\w.\- ]+/u', '_', basename($orig));
        $name = trim($name) !== '' ? trim($name) : 'arquivo';
        $name = mb_substr($name, 0, 120);

        $dir = $this->workspacePath($project->uuid) . '/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777);

        $dest = $dir . '/' . $name;
        if (is_file($dest)) {
            $dot = strrpos($name, '.');
            $stem = $dot === false ? $name : substr($name, 0, $dot);
            $ext  = $dot === false ? '' : substr($name, $dot);
            $name = $stem . '-' . substr((string) time(), -5) . $ext;
            $dest = $dir . '/' . $name;
        }
        if (!@move_uploaded_file($tmp, $dest)) {
            return $this->json(['ok' => false, 'error' => 'Não consegui salvar o anexo.']);
        }
        @chmod($dest, 0666);

        return $this->json([
            'ok'   => true,
            'name' => $name,
            'path' => 'uploads/' . $name,
            'size' => (int) ($file['size'] ?? 0),
        ], 'Anexo recebido');
    }

    /**
     * Envia (upload) um arquivo individual para o workspace pelo navegador de arquivos do hub.
     * Aceita um diretório de destino relativo (opcional, validado contra path traversal).
     */
    public function uploadFile(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);

        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->json(['ok' => false, 'error' => 'Nenhum arquivo enviado.']);
        }
        if ((int) ($file['size'] ?? 0) > 50 * 1024 * 1024) {
            return $this->json(['ok' => false, 'error' => 'Arquivo muito grande (máx. 50MB).']);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $this->json(['ok' => false, 'error' => 'Upload inválido.']);
        }

        $base = realpath($this->workspacePath($project->uuid));
        if ($base === false) {
            @mkdir($this->workspacePath($project->uuid), 0777, true);
            $base = realpath($this->workspacePath($project->uuid));
        }
        // Diretório de destino (relativo) — sanitizado e confinado ao workspace.
        $relDir = trim((string) $request->input('path', ''), '/');
        $destDir = $base;
        if ($relDir !== '' && strpos($relDir, '..') === false) {
            $cand = $base . '/' . $relDir;
            @mkdir($cand, 0777, true);
            $real = realpath($cand);
            if ($real !== false && strpos($real, $base . DIRECTORY_SEPARATOR) === 0) {
                $destDir = $real;
            }
        }

        $orig = (string) ($file['name'] ?? 'arquivo');
        $name = preg_replace('/[^\w.\- ]+/u', '_', basename($orig));
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 120) : 'arquivo';
        $dest = $destDir . '/' . $name;
        // Já existe? Sem overwrite=1 → pede confirmação (o navegador mostra o modal de substituir).
        $overwrite = (string) $request->input('overwrite', '0') === '1';
        if (is_file($dest) && !$overwrite) {
            return $this->json(['ok' => false, 'conflict' => true, 'name' => $name], 'Arquivo já existe');
        }
        if (!@move_uploaded_file($tmp, $dest)) {
            return $this->json(['ok' => false, 'error' => 'Não consegui salvar o arquivo.']);
        }
        @chmod($dest, 0666);

        $rel = ltrim(str_replace($base, '', $dest), '/');
        return $this->json(['ok' => true, 'path' => $rel, 'name' => $name], 'Arquivo enviado');
    }

    /**
     * Exclui um arquivo do workspace (navegador de arquivos do hub). Anti path-traversal.
     */
    public function deleteFile(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);

        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }

        $rel = (string) $request->input('file', '');
        $target = $this->resolveWorkspaceFile($project->uuid, $rel);
        if ($target === null) {
            return JsonResponse::notFound('Arquivo não encontrado');
        }

        if (!@unlink($target)) {
            return $this->jsonError('Não foi possível excluir o arquivo (verifique as permissões).', 500);
        }

        return $this->json(['deleted' => true, 'path' => $rel], 'Arquivo excluído');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Preview ao vivo (abrir o sistema rodando num link)
    // ──────────────────────────────────────────────────────────────────────────

    public function previewStart(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        try {
            $res = (new \Ravi\Services\PreviewService())->start($project->uuid);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'Erro ao abrir o preview: ' . $e->getMessage()]);
        }
        return $this->json($res);
    }

    public function previewStop(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $svc = new \Ravi\Services\PreviewService();
        $cur = $svc->getByProject($project->uuid);
        if ($cur) {
            $svc->stop($cur['token']);
        }
        return $this->json(['ok' => true]);
    }

    public function previewStatus(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $cur = (new \Ravi\Services\PreviewService())->getByProject($project->uuid);
        if (!$cur) {
            return $this->json(['ok' => true, 'running' => false]);
        }
        return $this->json([
            'ok'             => true,
            'running'        => true,
            'url'            => $cur['url'],
            'token'          => $cur['token'],
            'mobile'         => in_array((string) $project->type, ['app', 'both'], true),
            'requires_login' => (bool) $cur['requires_login'],
            'demo'           => !empty($cur['demo_user']) ? ['user' => $cur['demo_user'], 'password' => $cur['demo_password']] : null,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // QA Visual ao vivo (screencast do navegador real durante a verificação)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Serve o FRAME mais recente do screencast (o navegador de QA navegando ao vivo).
     * É um único arquivo (frame.jpg) constantemente sobrescrito pelo harness — a UI o
     * busca ~4fps com cache-buster. Sem frame ainda → 204 (a UI tenta de novo, sem 404).
     */
    public function screencast(Request $request, string $projectUuid)
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            $this->abort(404, 'Projeto não encontrado');
        }

        $file = $this->workspacePath($project->uuid) . '/.maestro/screencast/frame.jpg';
        if (!is_file($file) || filesize($file) === 0) {
            http_response_code(204);
            header('Cache-Control: no-store');
            exit;
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    /**
     * Estado do screencast (url corrente + legenda + done). Lido junto com o frame para a
     * barra de URL e a legenda do card. Também permite reidratar o card após um reload se o
     * QA ainda estiver ativo (frame recente, < 25s).
     */
    public function screencastStatus(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }

        $dir        = $this->workspacePath($project->uuid) . '/.maestro/screencast';
        $frame      = $dir . '/frame.jpg';
        $statusFile = $dir . '/status.json';

        $status = null;
        if (is_file($statusFile)) {
            $decoded = json_decode((string) @file_get_contents($statusFile), true);
            if (is_array($decoded)) {
                $status = $decoded;
            }
        }
        // "Ativo" = há um frame recente e o harness ainda não marcou done.
        $fresh   = is_file($frame) && (time() - (int) @filemtime($frame) <= 25);
        $running = $fresh && (!is_array($status) || empty($status['done']));

        return $this->json([
            'ok'      => true,
            'running' => $running,
            'url'     => is_array($status) ? ($status['url'] ?? null) : null,
            'caption' => is_array($status) ? ($status['caption'] ?? null) : null,
            'done'    => is_array($status) ? (bool) ($status['done'] ?? false) : false,
        ]);
    }

    /**
     * Página de MOLDURAS do preview mobile: mostra o app (export web) rodando dentro de duas
     * molduras (Android + iPhone), lado a lado e navegáveis. Aberta a partir do card do chat.
     */
    public function mobileFrames(Request $request, string $token): Response
    {
        $this->requireAuth($request);
        $token = preg_replace('/[^a-f0-9]/', '', $token) ?? '';
        $row = \Ravi\Core\Database::getInstance()->fetch(
            "SELECT * FROM previews WHERE token = ? AND status = 'running'",
            [$token]
        );
        if (!$row) {
            return new Response('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;background:#0b0b0f;color:#eee;display:flex;align-items:center;justify-content:center;height:100vh;margin:0"><div>Este preview não está mais ativo. Volte ao chat e abra novamente.</div></body>', 404);
        }
        $appUrl = rtrim((string) config('urls.preview'), '/') . '/' . $token . '/';
        return $this->view('preview/mobile-frames', [
            'appUrl' => $appUrl,
            'token'  => $token,
            'title'  => 'Preview Mobile',
        ]);
    }

    /** Dispara o build do APK Android em segundo plano (apps mobile). */
    public function apkBuild(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        try {
            $res = (new \Ravi\Services\ApkBuildService())->start($project->uuid);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'Erro ao iniciar o build: ' . $e->getMessage()]);
        }
        return $this->json($res);
    }

    /** Estado do build do APK (idle|building|ready|error) + caminho para download quando pronto. */
    public function apkStatus(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $st = (new \Ravi\Services\ApkBuildService())->status($project->uuid);
        return $this->json(['ok' => true] + $st);
    }

    /** Inicia a sessão Expo Go (Metro + túnel) para testar no celular real via QR. */
    public function expoStart(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        try {
            $res = (new \Ravi\Services\ExpoGoService())->start($project->uuid);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'Erro ao iniciar o Expo Go: ' . $e->getMessage()]);
        }
        return $this->json($res);
    }

    /** Estado da sessão Expo Go (idle|starting|ready) + URL exp:// para o QR. */
    public function expoStatus(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $st = (new \Ravi\Services\ExpoGoService())->status($project->uuid);
        return $this->json(['ok' => true] + $st);
    }

    /** Para a sessão Expo Go. */
    public function expoStop(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        (new \Ravi\Services\ExpoGoService())->stop($project->uuid);
        return $this->json(['ok' => true]);
    }

    /**
     * Estado do Maestro para a conversa (usado no load para restaurar o card de aprovação,
     * que é efêmero e some no refresh). Recebe ?c={conversationUuid}.
     */
    public function maestroStatus(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $convUuid = (string) $request->get('c');
        $conv = $convUuid !== '' ? \Ravi\Models\Conversation::findByUuid($convUuid) : null;
        if (!$conv || (int) $conv->project_id !== (int) $project->id) {
            return $this->json(['ok' => true, 'awaiting_approval' => false]);
        }
        $stateFile = $this->workspacePath($project->uuid) . '/.maestro/conv-' . $conv->id . '.json';
        $phase = 'new';
        $files = [];
        $questions = [];
        if (is_file($stateFile)) {
            $st = json_decode((string) file_get_contents($stateFile), true);
            if (is_array($st)) {
                $phase = $st['phase'] ?? 'new';
                if (!empty($st['files']) && is_array($st['files'])) {
                    $files = array_values(array_filter(array_map(fn($p) => trim(ltrim((string) $p, '/')), $st['files'])));
                }
                if ($phase === 'awaiting_clarification' && !empty($st['questions']) && is_array($st['questions'])) {
                    $questions = $st['questions'];
                }
            }
        }
        return $this->json([
            'ok' => true,
            'phase' => $phase,
            'awaiting_approval' => ($phase === 'awaiting_approval'),
            'awaiting_clarification' => ($phase === 'awaiting_clarification'),
            'questions' => $questions,
            'files' => $files,
        ]);
    }

    /**
     * Narração AO VIVO de uma tarefa em andamento (passos/fala dos agentes que o worker grava
     * em tempo real). Usado para restaurar o histórico ao recarregar a página no meio do build.
     */
    public function liveNarration(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $project = Project::findAccessible($projectUuid, (int) $this->user->id);
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        $convUuid = (string) $request->get('c');
        $conv = $convUuid !== '' ? \Ravi\Models\Conversation::findByUuid($convUuid) : null;
        if (!$conv || (int) $conv->project_id !== (int) $project->id) {
            return $this->json(['running' => false, 'narration' => []]);
        }
        $running = ($conv->status === 'running');
        $narration = [];
        $f = $this->workspacePath($project->uuid) . '/.maestro/live-' . (int) $conv->id . '.json';
        if (is_file($f)) {
            $data = json_decode((string) file_get_contents($f), true);
            if (is_array($data)) {
                $narration = $data;
            }
        }
        return $this->json(['running' => $running, 'narration' => $narration]);
    }

    /**
     * Resolve um caminho relativo para um arquivo DENTRO do workspace do projeto
     * (anti path-traversal). Retorna o caminho absoluto ou null se inválido.
     */
    private function resolveWorkspaceFile(string $projectUuid, string $rel): ?string
    {
        $base = realpath($this->workspacePath($projectUuid));
        if ($base === false || $rel === '') {
            return null;
        }
        $target = realpath($base . '/' . $rel);
        if ($target === false || strpos($target, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($target)) {
            return null;
        }
        return $target;
    }

    /** true se o projeto ainda está sendo importado/processado (gate de chat). */
    private function importProcessing(Project $project): bool
    {
        return \Ravi\Services\ProjectImportService::isProcessing($this->workspacePath($project->uuid));
    }

    /**
     * Caminho do workspace do projeto no host.
     */
    private function workspacePath(string $projectUuid): string
    {
        $base = config('sandbox.workspace.base_path');
        if (!$base) {
            $base = dirname(__DIR__, 2) . '/storage/sandbox';
        }
        return rtrim($base, '/') . '/' . $projectUuid;
    }

    /**
     * Generate a title from message content
     */
    private function generateTitle(string $message): string
    {
        // Take first line or first 100 chars
        $lines = explode("\n", $message);
        $title = trim($lines[0]);
        
        if (strlen($title) > 100) {
            $title = substr($title, 0, 97) . '...';
        }
        
        if (empty($title)) {
            $title = 'Nova tarefa - ' . date('d/m/Y H:i');
        }
        
        return $title;
    }
}
