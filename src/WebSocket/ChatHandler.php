<?php

declare(strict_types=1);

namespace Ravi\WebSocket;

use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use Ravi\Models\User;
use Ravi\Models\Project;
use Ravi\Models\Conversation;
use Ravi\Models\Message;
use Ravi\Core\Database;

/**
 * Handler de mensagens do chat via WebSocket
 */
class ChatHandler
{
    private ConnectionManager $connections;
    private array $runningTasks = [];
    private TerminalHandler $terminal;

    // Modo de execução: 'single' (AgentService) ou 'multi' (MultiAgentService)
    private string $agentMode = 'multi';

    public function __construct(ConnectionManager $connections)
    {
        $this->connections = $connections;
        $this->terminal = new TerminalHandler($connections);
    }

    /**
     * Define event loop
     */
    public function setLoop(LoopInterface $loop): void
    {
        $this->terminal->setLoop($loop);
    }

    /** Limpeza ao fechar a conexão (encerra terminal sob demanda, se houver). */
    public function onClose(ConnectionInterface $conn): void
    {
        $this->terminal->cleanup($conn);
    }
    
    /**
     * Define modo do agente
     */
    public function setAgentMode(string $mode): void
    {
        $this->agentMode = in_array($mode, ['single', 'multi']) ? $mode : 'multi';
    }
    
    /**
     * Processa mensagem recebida
     */
    public function handle(ConnectionInterface $conn, array $data): void
    {
        $this->connections->touch($conn);
        
        $type = $data['type'] ?? '';
        
        match($type) {
            'auth' => $this->handleAuth($conn, $data),
            'join_conversation' => $this->handleJoinConversation($conn, $data),
            'leave_conversation' => $this->handleLeaveConversation($conn, $data),
            'send_message' => $this->handleSendMessage($conn, $data),
            'cancel_task' => $this->handleCancelTask($conn, $data),
            'set_agent_mode' => $this->handleSetAgentMode($conn, $data),
            'term_start', 'term_input', 'term_resize', 'term_stop' => $this->terminal->handle($conn, $data),
            'ping' => $this->handlePing($conn),
            default => $this->sendError($conn, "Tipo de mensagem desconhecido: {$type}")
        };
    }
    
    /**
     * Autentica conexão usando token de sessão seguro
     */
    private function handleAuth(ConnectionInterface $conn, array $data): void
    {
        // Token do WebSocket (meta ws-token), gravado em user_sessions.ws_token pelo AuthMiddleware.
        // Nunca é o session_id: a página não expõe o cookie de sessão.
        $sessionToken = $data['ws_token'] ?? $data['session_token'] ?? null;
        
        if (!$sessionToken) {
            $this->sendError($conn, 'Token de sessão não fornecido');
            return;
        }
        
        // Busca sessão no banco (last_activity é timestamp UNIX/INT; compara com agora - 24h)
        $session = Database::getInstance()->fetch(
            "SELECT * FROM user_sessions WHERE ws_token = ? AND last_activity > ?",
            [$sessionToken, time() - 86400]
        );
        
        if (!$session) {
            // Fallback: tenta validar via API token
            $apiToken = $data['api_token'] ?? null;
            
            if ($apiToken) {
                $tokenHash = hash('sha256', $apiToken);
                $apiTokenRecord = Database::getInstance()->fetch(
                    "SELECT user_id FROM api_tokens WHERE token_hash = ? AND (expires_at IS NULL OR expires_at > NOW())",
                    [$tokenHash]
                );
                
                if ($apiTokenRecord) {
                    $userId = $apiTokenRecord['user_id'];
                    
                    // Atualiza last_used_at
                    Database::getInstance()->query(
                        "UPDATE api_tokens SET last_used_at = NOW() WHERE token_hash = ?",
                        [$tokenHash]
                    );
                } else {
                    $this->sendError($conn, 'Token inválido ou expirado');
                    return;
                }
            } else {
                $this->sendError($conn, 'Sessão inválida ou expirada. Faça login novamente.');
                return;
            }
        } else {
            // Extrai user_id do payload da sessão
            $payload = @unserialize($session['payload']);
            
            if (!$payload || !isset($payload['user']['id'])) {
                $this->sendError($conn, 'Sessão corrompida');
                return;
            }
            
            $userId = $payload['user']['id'];
            
            // Atualiza last_activity da sessão (timestamp UNIX)
            Database::getInstance()->query(
                "UPDATE user_sessions SET last_activity = ? WHERE id = ?",
                [time(), $session['id']]
            );
        }
        
        $user = User::find($userId);
        
        if (!$user || !$user->is_active) {
            $this->sendError($conn, 'Usuário não encontrado ou inativo');
            return;
        }
        
        $this->connections->authenticate($conn, $userId);
        
        $conn->send(json_encode([
            'type' => 'authenticated',
            'user' => $user->toSessionData(),
            'agent_mode' => $this->agentMode
        ]));
    }
    
    /**
     * Entra em uma conversa
     */
    private function handleJoinConversation(ConnectionInterface $conn, array $data): void
    {
        $meta = $this->connections->getMetadata($conn);
        
        if (!$meta || !$meta['user_id']) {
            $this->sendError($conn, 'Não autenticado');
            return;
        }
        
        $conversationUuid = $data['conversation_uuid'] ?? null;
        
        if (!$conversationUuid) {
            $this->sendError($conn, 'conversation_uuid não fornecido');
            return;
        }
        
        $conversation = Conversation::findByUuid($conversationUuid);
        
        if (!$conversation) {
            $this->sendError($conn, 'Conversa não encontrada');
            return;
        }
        
        // Verifica permissão
        $project = $conversation->project();
        if ($project->user_id !== $meta['user_id']) {
            $this->sendError($conn, 'Sem permissão para esta conversa');
            return;
        }
        
        $this->connections->joinConversation($conn, $conversation->id);
        
        // Envia histórico de mensagens
        $messages = $conversation->messages();
        
        $conn->send(json_encode([
            'type' => 'joined_conversation',
            'conversation' => [
                'uuid' => $conversation->uuid,
                'title' => $conversation->title,
                'status' => $conversation->status
            ],
            'messages' => array_map(function ($m) {
                // metadata pode vir já como ARRAY (cast 'json' do Model) ou como string JSON.
                $meta = $m->metadata ?? null;
                if (is_string($meta)) { $meta = json_decode($meta, true); }
                if (!is_array($meta)) { $meta = null; }
                return [
                    'uuid' => $m->uuid,
                    'role' => $m->role,
                    'content' => $m->content,
                    'tool_name' => $m->tool_name,
                    'tool_output' => $m->tool_output,
                    'created_at' => $m->created_at,
                    // Identidade do agente (cor/nome) e narração persistidas → reconstroem o
                    // histórico fiel no reload (cada agente com sua cara, e o "ver processo").
                    'agent' => (is_array($meta) && !empty($meta['agent'])) ? $meta['agent'] : null,
                    'narration' => (is_array($meta) && !empty($meta['narration'])) ? $meta['narration'] : null,
                ];
            }, $messages),
            'agent_mode' => $this->agentMode
        ]));
    }
    
    /**
     * Sai de uma conversa
     */
    private function handleLeaveConversation(ConnectionInterface $conn, array $data): void
    {
        $this->connections->leaveConversation($conn);
        
        $conn->send(json_encode([
            'type' => 'left_conversation'
        ]));
    }
    
    /**
     * Define modo do agente via mensagem
     */
    private function handleSetAgentMode(ConnectionInterface $conn, array $data): void
    {
        $mode = $data['mode'] ?? 'multi';
        
        if (!in_array($mode, ['single', 'multi'])) {
            $this->sendError($conn, 'Modo inválido. Use "single" ou "multi".');
            return;
        }
        
        $this->agentMode = $mode;
        
        $conn->send(json_encode([
            'type' => 'agent_mode_changed',
            'mode' => $mode,
            'description' => $mode === 'multi' 
                ? 'Multi-agente: Planner → Coder → Reviewer → Tester'
                : 'Agente único com loop de ferramentas'
        ]));
    }
    
    /**
     * Processa mensagem do usuário
     */
    private function handleSendMessage(ConnectionInterface $conn, array $data): void
    {
        $meta = $this->connections->getMetadata($conn);
        
        if (!$meta || !$meta['user_id']) {
            $this->sendError($conn, 'Não autenticado');
            return;
        }
        
        if (!$meta['conversation_id']) {
            $this->sendError($conn, 'Não está em uma conversa');
            return;
        }
        
        $content = trim($data['content'] ?? '');
        
        if (empty($content)) {
            $this->sendError($conn, 'Mensagem vazia');
            return;
        }
        
        $conversationId = $meta['conversation_id'];
        $conversation = Conversation::find($conversationId);
        
        if (!$conversation) {
            $this->sendError($conn, 'Conversa não encontrada');
            return;
        }

        // STEERING ao vivo: se já há um build rodando, NÃO rejeita nem cancela. O recado vai para a
        // inbox do build; o arquiteto lê entre as ondas e incorpora. Os agentes seguem trabalhando.
        // (Garante UM build por conversa: aqui não enfileiramos um 2º job.)
        if (($conversation->status ?? '') === 'running') {
            try {
                $proj = Project::find($conversation->project_id);
                if ($proj) {
                    $base = config('sandbox.workspace.base_path') ?: (dirname(__DIR__, 2) . '/storage/sandbox');
                    $ws   = rtrim((string) $base, '/') . '/' . $proj->uuid;
                    @mkdir($ws . '/.maestro', 0777, true);
                    @file_put_contents(
                        $ws . '/.maestro/inbox-' . (int) $conversationId . '.jsonl',
                        json_encode(['t' => date('c'), 'text' => $content], JSON_UNESCAPED_UNICODE) . "\n",
                        FILE_APPEND
                    );
                }
                \Ravi\Models\Message::createUserMessage($conversationId, $content);
            } catch (\Throwable $e) {
                logger()->warning('Falha ao registrar recado de steering: ' . $e->getMessage());
            }
            $conn->send(json_encode(['type' => 'message_received', 'content' => $content, 'agent_mode' => $this->agentMode]));
            $conn->send(json_encode(['type' => 'status', 'message' => '📨 Anotado — o arquiteto vai ver sua mensagem entre as etapas e seguir, sem parar o time.']));
            return;
        }

        // Gate: não aceita mensagens enquanto o projeto importado está em processamento.
        $gateProject = Project::find($conversation->project_id);
        if ($gateProject) {
            $base = config('sandbox.workspace.base_path') ?: (dirname(__DIR__, 2) . '/storage/sandbox');
            $ws   = rtrim((string) $base, '/') . '/' . $gateProject->uuid;
            if (\Ravi\Services\ProjectImportService::isProcessing($ws)) {
                $this->sendError($conn, 'O projeto ainda está sendo processado. Aguarde a conclusão para conversar.');
                return;
            }
        }

        // Persiste a mensagem do usuário no histórico (senão o reload perde tudo).
        // NAO renomeia a conversa aqui: o titulo so e definido quando um plano e APROVADO
        // (gatilho no runAutonomous), conforme o fluxo canonico do Arquiteto.
        try {
            \Ravi\Models\Message::createUserMessage($conversationId, $content);
        } catch (\Throwable $e) {
            logger()->warning('Falha ao salvar mensagem do usuário: ' . $e->getMessage());
        }

        // Verifica se já tem uma tarefa rodando nesta conversa
        if (isset($this->runningTasks[$conversationId])) {
            $this->sendError($conn, 'Já existe uma tarefa em execução nesta conversa');
            return;
        }
        
        // Verifica limite de tokens do usuário
        $user = User::find($meta['user_id']);
        if ($user && $user->hasReachedTokenLimit()) {
            $this->sendError($conn, 'Você atingiu seu limite de tokens. Faça upgrade do seu plano.');
            return;
        }
        
        // Permite override do modo via mensagem
        $messageMode = $data['agent_mode'] ?? $this->agentMode;
        
        // Confirma recebimento
        $conn->send(json_encode([
            'type' => 'message_received',
            'content' => $content,
            'agent_mode' => $messageMode
        ]));

        // Enfileira a tarefa para o queue-worker (processo separado).
        // NÃO roda o agente aqui — isso bloquearia o event loop do WebSocket.
        // O worker grava os eventos em agent_events e o servidor WS os entrega ao cliente.
        try {
            Database::getInstance()->query(
                "INSERT INTO job_queue (uuid, queue, job_type, payload, max_attempts) VALUES (?, 'default', 'process_agent_task', ?, 1)",
                [uuid(), json_encode([
                    'conversation_id' => $conversationId,
                    'content' => $content,
                    'user_id' => $meta['user_id'],
                    'mode' => $messageMode,
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]
            );
            $conversation->update(['status' => 'running']);
        } catch (\Throwable $e) {
            $this->sendError($conn, 'Erro ao enfileirar a tarefa: ' . $e->getMessage());
        }
    }
    
    /**
     * Cancela tarefa em execução
     */
    private function handleCancelTask(ConnectionInterface $conn, array $data): void
    {
        $meta = $this->connections->getMetadata($conn);
        
        if (!$meta || !$meta['conversation_id']) {
            $this->sendError($conn, 'Não está em uma conversa');
            return;
        }
        
        $conversationId = $meta['conversation_id'];
        $conversation = Conversation::find($conversationId);
        if (!$conversation) {
            $this->sendError($conn, 'Conversa não encontrada');
            return;
        }

        // 1. Remove jobs pendentes desta conversa da fila.
        try {
            Database::getInstance()->query(
                "DELETE FROM job_queue WHERE payload LIKE ?",
                ['%"conversation_id":' . (int) $conversationId . '%']
            );
        } catch (\Throwable $e) { /* segue */ }

        // 2. Sinaliza cancelamento e mata o container do agente (interrompe a execução em curso).
        try {
            $project = Project::find($conversation->project_id);
            if ($project) {
                $base = config('sandbox.workspace.base_path') ?: (dirname(__DIR__, 2) . '/storage/sandbox');
                $ws = rtrim($base, '/') . '/' . $project->uuid;
                @mkdir($ws . '/.maestro', 0777, true);
                @file_put_contents($ws . '/.maestro/cancel', (string) time());
                $prefix = 'ravi-cc-' . substr($project->uuid, 0, 8);
                @exec('docker ps --filter ' . escapeshellarg('name=' . $prefix) . ' -q | xargs -r docker rm -f 2>&1');
            }
        } catch (\Throwable $e) { /* segue */ }

        // 3. Libera a conversa para novas mensagens (não fica travada).
        $conversation->update(['status' => 'active']);
        unset($this->runningTasks[$conversationId]);

        $conn->send(json_encode([
            'type' => 'task_completed',
            'result' => ['cancelled' => true],
            'message' => 'Tarefa interrompida. Pode mandar uma nova instrução quando quiser.'
        ]));
    }
    
    /**
     * Responde ping
     */
    private function handlePing(ConnectionInterface $conn): void
    {
        $conn->send(json_encode([
            'type' => 'pong',
            'timestamp' => time()
        ]));
    }
    
    /**
     * Envia erro
     */
    private function sendError(ConnectionInterface $conn, string $message): void
    {
        $conn->send(json_encode([
            'type' => 'error',
            'message' => $message
        ]));
    }
}
