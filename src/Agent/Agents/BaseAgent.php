<?php

declare(strict_types=1);

namespace Ravi\Agent\Agents;

use Ravi\Services\ClaudeService;
use Ravi\Agent\ToolExecutor;

/**
 * Classe base para agentes especializados
 * 
 * Cada agente tem:
 * - Nome e descrição
 * - System prompt especializado
 * - Ferramentas específicas que pode usar
 * - Modelo Claude configurável (Opus para Orchestrator, Sonnet para workers)
 * - Capacidade de se comunicar com outros agentes via Orchestrator
 */
abstract class BaseAgent
{
    protected ClaudeService $claude;
    protected ToolExecutor $toolExecutor;
    /** @var callable|null */
    protected $onUpdate = null;
    protected array $memory = [];
    protected int $maxIterations = 10;

    /** Uso de tokens/custo da última chamada a execute() */
    protected array $lastUsage = ['input_tokens' => 0, 'output_tokens' => 0, 'estimated_cost' => 0.0];
    
    // Modelos disponíveis (atualizados para a geração mais recente — jul/2026)
    public const MODEL_OPUS = 'claude-opus-5';                  // Opus 5 — mais inteligente, usado no Orchestrator
    public const MODEL_SONNET = 'claude-sonnet-5';              // Sonnet 5 — equilíbrio custo/qualidade, usado nos workers
    public const MODEL_HAIKU = 'claude-haiku-4-5-20251001';    // Haiku 4.5 — mais rápido e barato
    
    // Modelo padrão para workers
    protected string $model = self::MODEL_SONNET;
    
    public function __construct(ClaudeService $claude, ToolExecutor $toolExecutor)
    {
        $this->claude = $claude;
        $this->toolExecutor = $toolExecutor;
    }
    
    /**
     * Nome do agente
     */
    abstract public function getName(): string;
    
    /**
     * Descrição do que o agente faz
     */
    abstract public function getDescription(): string;
    
    /**
     * System prompt especializado
     */
    abstract public function getSystemPrompt(): string;
    
    /**
     * Lista de ferramentas que este agente pode usar
     */
    abstract public function getAllowedTools(): array;
    
    /**
     * Retorna o modelo que este agente usa
     */
    public function getModel(): string
    {
        return $this->model;
    }
    
    /**
     * Define modelo específico para este agente
     */
    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }
    
    /**
     * Define callback para atualizações
     */
    public function onUpdate(callable $callback): self
    {
        $this->onUpdate = $callback;
        return $this;
    }
    
    /**
     * Adiciona à memória do agente
     */
    public function remember(string $key, mixed $value): void
    {
        $this->memory[$key] = $value;
    }
    
    /**
     * Recupera da memória
     */
    public function recall(string $key, mixed $default = null): mixed
    {
        return $this->memory[$key] ?? $default;
    }
    
    /**
     * Limpa memória
     */
    public function forget(?string $key = null): void
    {
        if ($key === null) {
            $this->memory = [];
        } else {
            unset($this->memory[$key]);
        }
    }
    
    /**
     * Executa uma tarefa específica
     */
    public function execute(string $task, array $context = []): array
    {
        $messages = $this->buildMessages($task, $context);
        $systemPrompt = $this->getSystemPrompt();
        
        // Filtra ferramentas permitidas
        $allowedTools = $this->getAllowedTools();
        $tools = array_filter(
            config('tools.schemas') ?? [],
            fn($tool) => in_array($tool['name'], $allowedTools)
        );
        
        // Configura modelo específico do agente
        $this->claude->setModel($this->model);
        $this->claude->setTools(array_values($tools));
        
        $this->broadcast([
            'type' => 'agent_model',
            'agent' => $this->getName(),
            'model' => $this->model
        ]);
        
        $iteration = 0;
        $result = null;
        $completed = false;
        $allResponses = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        
        while ($iteration < $this->maxIterations && !$completed) {
            $iteration++;
            
            $this->broadcast([
                'type' => 'agent_iteration',
                'agent' => $this->getName(),
                'iteration' => $iteration,
                'model' => $this->model
            ]);
            
            $response = $this->claude->sendMessage(
                $messages,
                $systemPrompt,
                function($chunk) {
                    $this->handleStreamChunk($chunk);
                }
            );
            
            // Acumula tokens
            $totalInputTokens += $response['usage']['input_tokens'] ?? 0;
            $totalOutputTokens += $response['usage']['output_tokens'] ?? 0;
            
            $allResponses[] = $response;
            
            // Processa resposta
            $processResult = $this->processResponse($response, $messages);
            
            if ($processResult['completed']) {
                $completed = true;
                $result = $processResult['result'];
            }
            
            // Atualiza mensagens para próxima iteração
            $messages = $processResult['messages'];
            
            // Se não usou ferramentas e terminou, considera completo
            if ($response['stop_reason'] === 'end_turn' && empty($processResult['tool_uses'])) {
                $completed = true;
            }
        }
        
        $this->lastUsage = [
            'input_tokens' => $totalInputTokens,
            'output_tokens' => $totalOutputTokens,
            'estimated_cost' => $this->claude->estimateCost($totalInputTokens, $totalOutputTokens),
        ];

        return [
            'agent' => $this->getName(),
            'model' => $this->model,
            'completed' => $completed,
            'iterations' => $iteration,
            'result' => $result,
            'responses' => $allResponses,
            'usage' => $this->lastUsage,
        ];
    }

    /**
     * Retorna o uso de tokens/custo da última execução deste agente
     */
    public function getLastUsage(): array
    {
        return $this->lastUsage;
    }
    
    /**
     * Constrói mensagens iniciais
     */
    protected function buildMessages(string $task, array $context): array
    {
        $messages = [];
        
        // Adiciona contexto se fornecido
        if (!empty($context)) {
            $contextStr = "Contexto:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $messages[] = [
                'role' => 'user',
                'content' => $contextStr
            ];
            $messages[] = [
                'role' => 'assistant',
                'content' => 'Entendido. Recebi o contexto.'
            ];
        }
        
        // Adiciona memória relevante
        if (!empty($this->memory)) {
            $memoryStr = "Informações que lembro:\n" . json_encode($this->memory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $messages[] = [
                'role' => 'user',
                'content' => $memoryStr
            ];
            $messages[] = [
                'role' => 'assistant',
                'content' => 'Entendido. Vou considerar essas informações.'
            ];
        }
        
        // Adiciona tarefa
        $messages[] = [
            'role' => 'user',
            'content' => $task
        ];
        
        return $messages;
    }
    
    /**
     * Processa resposta do Claude
     */
    protected function processResponse(array $response, array $messages): array
    {
        $completed = false;
        $result = null;
        $toolUses = [];
        
        // Adiciona resposta às mensagens
        $assistantContent = [];
        
        foreach ($response['content'] as $block) {
            if ($block['type'] === 'text') {
                $assistantContent[] = $block;
                $result = $block['text'];
                
                $this->broadcast([
                    'type' => 'agent_text',
                    'agent' => $this->getName(),
                    'text' => $block['text']
                ]);
                
            } elseif ($block['type'] === 'tool_use') {
                $assistantContent[] = $block;
                $toolUses[] = $block;
                
                // Verifica se é permitida
                if (!in_array($block['name'], $this->getAllowedTools())) {
                    $this->broadcast([
                        'type' => 'agent_error',
                        'agent' => $this->getName(),
                        'error' => "Ferramenta não permitida para este agente: {$block['name']}"
                    ]);
                    continue;
                }
                
                $this->broadcast([
                    'type' => 'agent_tool_start',
                    'agent' => $this->getName(),
                    'tool' => $block['name'],
                    'tool_id' => $block['id'],
                    'input' => $block['input']
                ]);
                
                // Executa ferramenta
                try {
                    $toolOutput = $this->toolExecutor->execute($block['name'], $block['input']);
                    
                    $this->broadcast([
                        'type' => 'agent_tool_result',
                        'agent' => $this->getName(),
                        'tool' => $block['name'],
                        'tool_id' => $block['id'],
                        'success' => true,
                        'output' => $toolOutput
                    ]);
                    
                    // Adiciona resultado às mensagens
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $assistantContent
                    ];
                    $messages[] = [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'tool_result',
                                'tool_use_id' => $block['id'],
                                'content' => $toolOutput
                            ]
                        ]
                    ];
                    $assistantContent = [];
                    
                } catch (\Exception $e) {
                    $this->broadcast([
                        'type' => 'agent_tool_result',
                        'agent' => $this->getName(),
                        'tool' => $block['name'],
                        'tool_id' => $block['id'],
                        'success' => false,
                        'error' => $e->getMessage()
                    ]);
                    
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $assistantContent
                    ];
                    $messages[] = [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'tool_result',
                                'tool_use_id' => $block['id'],
                                'content' => "Erro: " . $e->getMessage(),
                                'is_error' => true
                            ]
                        ]
                    ];
                    $assistantContent = [];
                }
                
                // Verifica task_complete
                if ($block['name'] === 'task_complete') {
                    $completed = true;
                    $result = $block['input'];
                }
            }
        }
        
        // Se sobrou conteúdo não adicionado
        if (!empty($assistantContent)) {
            $messages[] = [
                'role' => 'assistant',
                'content' => $assistantContent
            ];
        }
        
        return [
            'completed' => $completed,
            'result' => $result,
            'messages' => $messages,
            'tool_uses' => $toolUses
        ];
    }
    
    /**
     * Processa chunk de streaming
     */
    protected function handleStreamChunk(array $chunk): void
    {
        if ($chunk['type'] === 'text') {
            $this->broadcast([
                'type' => 'agent_text_delta',
                'agent' => $this->getName(),
                'text' => $chunk['text']
            ]);
        }
    }
    
    /**
     * Broadcast de atualização
     */
    protected function broadcast(array $data): void
    {
        if ($this->onUpdate) {
            ($this->onUpdate)($data);
        }
    }
}
