<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

/**
 * Ferramenta para executar comandos bash no sandbox
 */
class BashExecuteTool extends BaseTool
{
    public function getName(): string
    {
        return 'bash_execute';
    }
    
    public function getDescription(): string
    {
        return 'Executa comandos bash/shell no ambiente sandbox. Use para instalar pacotes, compilar código, executar scripts, manipular arquivos via linha de comando.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Comando bash a ser executado'
                ],
                'working_directory' => [
                    'type' => 'string',
                    'description' => 'Diretório de trabalho (padrão: /workspace)'
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout em segundos (padrão: 60, máximo: 300)'
                ]
            ],
            'required' => ['command']
        ];
    }
    
    public function execute(array $input): string
    {
        $command = $input['command'];
        $workingDir = $input['working_directory'] ?? '/workspace';
        $timeout = min($input['timeout'] ?? 60, 300);
        
        if (!$this->sandbox || !$this->containerName) {
            return $this->error('Sandbox não configurado');
        }
        
        try {
            $result = $this->sandbox->execute(
                $this->containerName,
                $command,
                $workingDir,
                $timeout
            );
            
            $output = $this->truncate($result['output']);
            
            if ($result['success']) {
                return "$ {$command}\n{$output}";
            } else {
                return "$ {$command}\nExit code: {$result['exit_code']}\n{$output}";
            }
            
        } catch (\Exception $e) {
            return $this->error('Erro ao executar comando', $e->getMessage());
        }
    }
}
