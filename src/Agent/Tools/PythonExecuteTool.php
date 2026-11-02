<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

/**
 * Ferramenta para executar código Python no sandbox
 */
class PythonExecuteTool extends BaseTool
{
    public function getName(): string
    {
        return 'python_execute';
    }
    
    public function getDescription(): string
    {
        return 'Executa código Python no sandbox.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'Código Python a ser executado'
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
            $result = $this->sandbox->executePython(
                $this->containerName,
                $code,
                $timeout
            );
            
            $output = $this->truncate($result['output']);
            
            if ($result['success']) {
                return "🐍 Python executado:\n\n{$output}";
            } else {
                return "🐍 Python (exit code {$result['exit_code']}):\n\n{$output}";
            }
            
        } catch (\Exception $e) {
            return $this->error('Erro ao executar Python', $e->getMessage());
        }
    }
}
