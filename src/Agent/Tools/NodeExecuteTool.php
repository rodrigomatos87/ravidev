<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

/**
 * Ferramenta para executar código Node.js no sandbox
 */
class NodeExecuteTool extends BaseTool
{
    public function getName(): string
    {
        return 'node_execute';
    }
    
    public function getDescription(): string
    {
        return 'Executa código JavaScript/Node.js no sandbox.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'Código JavaScript a ser executado'
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout em segundos (padrão: 60)'
                ]
            ],
            'required' => ['code']
        ];
    }
    
    public function execute(array $input): string
    {
        $code = $input['code'];
        $timeout = min($input['timeout'] ?? 60, 300);
        
        if (!$this->sandbox || !$this->containerName) {
            return $this->error('Sandbox não configurado');
        }
        
        try {
            $result = $this->sandbox->executeNode(
                $this->containerName,
                $code,
                $timeout
            );
            
            $output = $this->truncate($result['output']);
            
            if ($result['success']) {
                return "📦 Node.js executado:\n\n{$output}";
            } else {
                return "📦 Node.js (exit code {$result['exit_code']}):\n\n{$output}";
            }
            
        } catch (\Exception $e) {
            return $this->error('Erro ao executar Node.js', $e->getMessage());
        }
    }
}
