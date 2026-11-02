<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Models\Conversation;
use Ravi\Models\Message;
use Ravi\Models\GeneratedFile;
use Ravi\Agent\ToolRegistry;
use Ravi\Agent\ToolExecutor;

/**
 * Serviço de orquestração do agente AI
 */
class AgentService
{
    private ClaudeService $claude;
    private SandboxService $sandbox;
    private ToolExecutor $toolExecutor;
    private ToolRegistry $toolRegistry;
    
    private int $maxIterations;
    private Conversation $conversation;
    private string $containerName;
    /** @var callable|null */
    private $onUpdate = null;
    
    public function __construct(
        ClaudeService $claude,
        SandboxService $sandbox,
        ToolRegistry $toolRegistry
    ) {
        $this->claude = $claude;
        $this->sandbox = $sandbox;
        $this->toolRegistry = $toolRegistry;
        $this->toolExecutor = new ToolExecutor($sandbox, $toolRegistry);
        
        $config = config('claude.agent');
        $this->maxIterations = $config['max_iterations'] ?? 50;
    }
    
    /**
     * Define callback para atualizações em tempo real
     */
    public function onUpdate(callable $callback): self
    {
        $this->onUpdate = $callback;
        return $this;
    }
    
    /**
     * Executa uma tarefa do usuário
     */
    public function executeTask(Conversation $conversation, string $userMessage): array
    {
        $this->conversation = $conversation;
        
        // Obtém ou cria container para o projeto
        $project = $conversation->project();
        $this->containerName = $this->sandbox->getOrCreateContainer($project->uuid);
        $this->toolExecutor->setContainer($this->containerName);
        
        // Marca conversa como rodando
        $conversation->status = 'running';
        $conversation->started_at = date('Y-m-d H:i:s');
        $conversation->save();
        
        $this->broadcast([
            'type' => 'status',
            'status' => 'running',
            'message' => 'Iniciando processamento...'
        ]);
        
        // Salva mensagem do usuário
        $userMsg = Message::createUserMessage($conversation->id, $userMessage);
        
        try {
            // Executa loop do agente
            $result = $this->runAgentLoop();
            
            // Marca como completo
            $conversation->status = 'completed';
            $conversation->completed_at = date('Y-m-d H:i:s');
            $conversation->save();
            
            $this->broadcast([
                'type' => 'status',
                'status' => 'completed',
                'message' => 'Tarefa concluída!'
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            // Marca como falha
            $conversation->status = 'failed';
            $conversation->error_message = $e->getMessage();
            $conversation->completed_at = date('Y-m-d H:i:s');
            $conversation->save();
            
            $this->broadcast([
                'type' => 'error',
                'message' => $e->getMessage()
            ]);
            
            logger()->error('Agent execution failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Loop principal do agente
     */
    private function runAgentLoop(): array
    {
        $iteration = 0;
        $isComplete = false;
        $taskResult = null;
        
        while ($iteration < $this->maxIterations && !$isComplete) {
            $iteration++;
            
            $this->broadcast([
                'type' => 'iteration',
                'iteration' => $iteration,
                'max' => $this->maxIterations
            ]);
            
            // Constrói mensagens para a API
            $messages = $this->buildMessages();
            $systemPrompt = $this->buildSystemPrompt();
            
            // Envia para Claude
            $response = $this->claude->sendMessage(
                $messages,
                $systemPrompt,
                function($chunk) {
                    $this->handleStreamChunk($chunk);
                }
            );
            
            // Atualiza tokens e custo estimado
            $inputTokens = $response['usage']['input_tokens'] ?? 0;
            $outputTokens = $response['usage']['output_tokens'] ?? 0;
            $cost = $this->claude->estimateCost($inputTokens, $outputTokens);
            $this->conversation->addUsage($inputTokens, $outputTokens, $cost);
            
            // Processa resposta
            $result = $this->processResponse($response);
            
            if ($result['completed']) {
                $isComplete = true;
                $taskResult = $result['result'];
            }
            
            // Verifica stop_reason
            if ($response['stop_reason'] === 'end_turn' && empty($result['tool_uses'])) {
                // Claude terminou sem usar ferramentas e sem task_complete
                // Considera como conclusão natural
                $isComplete = true;
            }
        }
        
        if (!$isComplete) {
            logger()->warning('Agent reached max iterations', [
                'conversation_id' => $this->conversation->id,
                'iterations' => $iteration
            ]);
        }
        
        // Coleta arquivos gerados
        $files = $this->collectGeneratedFiles();
        
        return [
            'completed' => $isComplete,
            'iterations' => $iteration,
            'task_result' => $taskResult,
            'files' => $files
        ];
    }
    
    /**
     * Constrói mensagens para envio à API
     */
    private function buildMessages(): array
    {
        $messages = [];
        $dbMessages = $this->conversation->messages();
        
        foreach ($dbMessages as $msg) {
            $messages[] = $msg->toApiFormat();
        }
        
        return $messages;
    }
    
    /**
     * Constrói system prompt
     */
    private function buildSystemPrompt(): string
    {
        $project = $this->conversation->project();
        
        // Lista arquivos existentes no workspace
        $files = $this->sandbox->listFiles($this->containerName, '/workspace', true);
        $filesList = array_map(fn($f) => $f['name'], $files);
        
        return $this->claude->getAgentSystemPrompt(
            $project->name,
            $filesList
        );
    }
    
    /**
     * Processa resposta do Claude
     */
    private function processResponse(array $response): array
    {
        $completed = false;
        $result = null;
        $toolUses = [];
        
        foreach ($response['content'] as $block) {
            if ($block['type'] === 'text') {
                // Salva texto como mensagem do assistente
                Message::createAssistantMessage(
                    $this->conversation->id,
                    $block['text'],
                    null,
                    $response['usage']['output_tokens'] ?? 0
                );
                
                $this->broadcast([
                    'type' => 'assistant_message',
                    'content' => $block['text']
                ]);
                
            } elseif ($block['type'] === 'tool_use') {
                $toolUses[] = $block;
                
                // Salva uso da ferramenta
                $toolUseMsg = Message::createToolUse(
                    $this->conversation->id,
                    $block['name'],
                    $block['input'],
                    $block['id']
                );
                
                $this->broadcast([
                    'type' => 'tool_start',
                    'tool_id' => $block['id'],
                    'tool_name' => $block['name'],
                    'input' => $block['input']
                ]);
                
                // Executa ferramenta
                $toolResult = $this->executeTool($block);
                
                // Salva resultado
                $outputStr = is_string($toolResult['output']) 
                    ? $toolResult['output'] 
                    : json_encode($toolResult['output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    
                Message::createToolResult(
                    $this->conversation->id,
                    $block['name'],
                    $outputStr,
                    $block['id'],
                    !$toolResult['success']
                );
                
                $this->broadcast([
                    'type' => 'tool_result',
                    'tool_id' => $block['id'],
                    'tool_name' => $block['name'],
                    'success' => $toolResult['success'],
                    'output' => $toolResult['output']
                ]);
                
                // Verifica se é task_complete
                if ($block['name'] === 'task_complete') {
                    $completed = true;
                    $result = $block['input'];
                }
            }
        }
        
        return [
            'completed' => $completed,
            'result' => $result,
            'tool_uses' => $toolUses
        ];
    }
    
    /**
     * Executa uma ferramenta
     */
    private function executeTool(array $toolUse): array
    {
        $toolName = $toolUse['name'];
        $input = $toolUse['input'];
        
        try {
            $output = $this->toolExecutor->execute($toolName, $input);
            
            return [
                'success' => true,
                'output' => $output
            ];
            
        } catch (\Exception $e) {
            logger()->error('Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'output' => "Erro ao executar {$toolName}: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Processa chunk de streaming
     */
    private function handleStreamChunk(array $chunk): void
    {
        if ($chunk['type'] === 'text') {
            $this->broadcast([
                'type' => 'text_delta',
                'text' => $chunk['text']
            ]);
        }
    }
    
    /**
     * Coleta arquivos gerados no workspace
     */
    private function collectGeneratedFiles(): array
    {
        $files = [];
        $workspaceFiles = $this->sandbox->listFiles($this->containerName, '/workspace', true);
        
        foreach ($workspaceFiles as $file) {
            if ($file['type'] === 'file') {
                $path = '/workspace/' . $file['name'];
                $content = $this->sandbox->readFile($this->containerName, $path);
                
                // Salva no banco
                $genFile = GeneratedFile::create([
                    'conversation_id' => $this->conversation->id,
                    'file_path' => $path,
                    'file_name' => $file['name'],
                    'file_type' => $this->getFileType($file['name']),
                    'file_size' => $file['size'],
                    'content' => $content,
                    'is_binary' => $this->isBinaryFile($file['name'])
                ]);
                
                $files[] = $genFile;
            }
        }
        
        return $files;
    }
    
    /**
     * Obtém tipo de arquivo pela extensão
     */
    private function getFileType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $types = [
            'php' => 'application/x-php',
            'js' => 'application/javascript',
            'ts' => 'application/typescript',
            'json' => 'application/json',
            'html' => 'text/html',
            'css' => 'text/css',
            'md' => 'text/markdown',
            'txt' => 'text/plain',
            'py' => 'text/x-python',
            'sql' => 'application/sql',
            'xml' => 'application/xml',
            'yaml' => 'application/yaml',
            'yml' => 'application/yaml',
            'sh' => 'application/x-sh',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip'
        ];
        
        return $types[$ext] ?? 'application/octet-stream';
    }
    
    /**
     * Verifica se arquivo é binário
     */
    private function isBinaryFile(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $binaryExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'pdf', 'zip', 'tar', 'gz', 'exe', 'dll', 'so'];
        
        return in_array($ext, $binaryExtensions);
    }
    
    /**
     * Broadcast de atualização via callback
     */
    private function broadcast(array $data): void
    {
        if ($this->onUpdate) {
            ($this->onUpdate)($data);
        }
    }
    
    /**
     * Cancela execução
     */
    public function cancel(): void
    {
        if (isset($this->conversation)) {
            $this->conversation->status = 'cancelled';
            $this->conversation->completed_at = date('Y-m-d H:i:s');
            $this->conversation->save();
        }
    }
}
