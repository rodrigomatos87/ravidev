<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

use Ravi\Agent\ToolInterface;
use Ravi\Services\SandboxService;
use Ravi\Agent\ToolExecutor;

/**
 * Classe base para ferramentas do agente
 */
abstract class BaseTool implements ToolInterface
{
    protected ?SandboxService $sandbox = null;
    protected string $containerName = '';
    protected ?ToolExecutor $executor = null;
    
    /**
     * Injeta SandboxService
     */
    public function setSandbox(SandboxService $sandbox): void
    {
        $this->sandbox = $sandbox;
    }
    
    /**
     * Define container
     */
    public function setContainer(string $containerName): void
    {
        $this->containerName = $containerName;
    }
    
    /**
     * Injeta executor
     */
    public function setExecutor(ToolExecutor $executor): void
    {
        $this->executor = $executor;
    }
    
    /**
     * Valida input - implementação padrão verifica campos required
     */
    public function validateInput(array $input): bool
    {
        $schema = $this->getInputSchema();
        $required = $schema['required'] ?? [];
        
        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '') {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Formata output de sucesso
     */
    protected function success(string $message, array $data = []): string
    {
        $output = "✅ {$message}";
        
        if (!empty($data)) {
            $output .= "\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        
        return $output;
    }
    
    /**
     * Formata output de erro
     */
    protected function error(string $message, ?string $details = null): string
    {
        $output = "❌ {$message}";
        
        if ($details) {
            $output .= "\n{$details}";
        }
        
        return $output;
    }
    
    /**
     * Trunca output se muito grande
     */
    protected function truncate(string $content, int $maxLength = 50000): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }
        
        $halfLength = (int)($maxLength / 2) - 50;
        
        return substr($content, 0, $halfLength) . 
               "\n\n... (output truncado - " . strlen($content) . " bytes total) ...\n\n" .
               substr($content, -$halfLength);
    }
}
