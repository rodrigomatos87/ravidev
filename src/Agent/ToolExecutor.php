<?php

declare(strict_types=1);

namespace Ravi\Agent;

use Ravi\Services\SandboxService;

/**
 * Executor de ferramentas do agente
 */
class ToolExecutor
{
    private SandboxService $sandbox;
    private ToolRegistry $registry;
    private string $containerName = '';
    
    public function __construct(SandboxService $sandbox, ToolRegistry $registry)
    {
        $this->sandbox = $sandbox;
        $this->registry = $registry;
    }
    
    /**
     * Define container atual
     */
    public function setContainer(string $containerName): self
    {
        $this->containerName = $containerName;
        return $this;
    }
    
    /**
     * Obtém sandbox service
     */
    public function getSandbox(): SandboxService
    {
        return $this->sandbox;
    }
    
    /**
     * Obtém nome do container
     */
    public function getContainerName(): string
    {
        return $this->containerName;
    }
    
    /**
     * Executa uma ferramenta
     */
    public function execute(string $toolName, array $input): string
    {
        $tool = $this->registry->get($toolName);
        
        if (!$tool) {
            throw new \RuntimeException("Ferramenta não encontrada: {$toolName}");
        }
        
        // Valida input
        if (!$tool->validateInput($input)) {
            throw new \InvalidArgumentException("Input inválido para ferramenta: {$toolName}");
        }
        
        // Injeta dependências na tool se necessário
        if (method_exists($tool, 'setSandbox')) {
            $tool->setSandbox($this->sandbox);
        }
        if (method_exists($tool, 'setContainer')) {
            $tool->setContainer($this->containerName);
        }
        if (method_exists($tool, 'setExecutor')) {
            $tool->setExecutor($this);
        }
        
        // Log da execução
        logger()->debug('Executing tool', [
            'tool' => $toolName,
            'input' => $this->sanitizeForLog($input)
        ]);
        
        $startTime = microtime(true);
        
        try {
            $result = $tool->execute($input);
            
            $duration = round(microtime(true) - $startTime, 3);
            
            logger()->debug('Tool executed', [
                'tool' => $toolName,
                'duration' => $duration,
                'result_length' => strlen($result)
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            logger()->error('Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Executa múltiplas ferramentas em sequência
     */
    public function executeMultiple(array $toolCalls): array
    {
        $results = [];
        
        foreach ($toolCalls as $call) {
            $results[] = [
                'tool' => $call['name'],
                'tool_use_id' => $call['id'] ?? null,
                'result' => $this->execute($call['name'], $call['input'])
            ];
        }
        
        return $results;
    }
    
    /**
     * Sanitiza input para log (remove conteúdo muito grande)
     */
    private function sanitizeForLog(array $input): array
    {
        $sanitized = [];
        
        foreach ($input as $key => $value) {
            if (is_string($value) && strlen($value) > 500) {
                $sanitized[$key] = substr($value, 0, 500) . '... (truncated)';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeForLog($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
}
