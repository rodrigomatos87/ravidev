<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

/**
 * Ferramenta de Sistema - Autonomia Total
 * 
 * Permite executar qualquer operação de sistema no sandbox:
 * - Instalar/desinstalar pacotes
 * - Gerenciar serviços
 * - Configurar ambiente
 * - Manipular permissões
 * - Operações de rede (dentro do sandbox)
 * - Compilar código
 * - Executar builds
 * 
 * IMPORTANTE: Roda apenas dentro do container sandbox isolado
 */
class SystemTool extends BaseTool
{
    public function getName(): string
    {
        return 'system_execute';
    }
    
    public function getDescription(): string
    {
        return 'Executa comandos de sistema com autonomia total no sandbox (instalar pacotes, gerenciar serviços, compilar, etc)';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Comando de sistema a executar'
                ],
                'as_root' => [
                    'type' => 'boolean',
                    'description' => 'Executar como root (sudo)',
                    'default' => false
                ],
                'working_dir' => [
                    'type' => 'string',
                    'description' => 'Diretório de trabalho',
                    'default' => '/workspace'
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout em segundos',
                    'default' => 300
                ],
                'env_vars' => [
                    'type' => 'object',
                    'description' => 'Variáveis de ambiente adicionais'
                ]
            ],
            'required' => ['command']
        ];
    }
    
    public function execute(array $input): string
    {
        $command = $input['command'];
        $asRoot = $input['as_root'] ?? false;
        $workingDir = $input['working_dir'] ?? '/workspace';
        $timeout = min($input['timeout'] ?? 300, 600); // Máximo 10 minutos
        $envVars = $input['env_vars'] ?? [];
        
        // Monta comando com diretório de trabalho
        $fullCommand = "cd '{$workingDir}' && ";
        
        // Adiciona variáveis de ambiente
        foreach ($envVars as $key => $value) {
            $fullCommand .= "{$key}='{$value}' ";
        }
        
        // Adiciona sudo se necessário
        if ($asRoot) {
            $fullCommand .= "sudo ";
        }
        
        $fullCommand .= $command;
        
        // Executa
        $startTime = microtime(true);
        
        $result = $this->sandbox->execute(
            $this->containerName,
            $fullCommand,
            $timeout
        );
        
        $duration = round(microtime(true) - $startTime, 2);
        
        $output = "";
        
        if ($result['success']) {
            $output .= "✅ **Comando executado com sucesso**\n\n";
            $output .= "```bash\n{$command}\n```\n\n";
            
            if (!empty($result['output'])) {
                // Limita output para não sobrecarregar
                $cmdOutput = $result['output'];
                if (strlen($cmdOutput) > 5000) {
                    $cmdOutput = substr($cmdOutput, 0, 2500) . "\n\n... (saída truncada) ...\n\n" . substr($cmdOutput, -2500);
                }
                
                $output .= "**Saída:**\n```\n{$cmdOutput}\n```\n";
            }
            
            $output .= "\n⏱️ Tempo: {$duration}s";
        } else {
            $output .= "❌ **Comando falhou**\n\n";
            $output .= "```bash\n{$command}\n```\n\n";
            
            if (!empty($result['error'])) {
                $output .= "**Erro:**\n```\n{$result['error']}\n```\n";
            }
            
            if (!empty($result['output'])) {
                $output .= "**Saída:**\n```\n{$result['output']}\n```\n";
            }
            
            $output .= "\n⏱️ Tempo: {$duration}s";
        }
        
        return $output;
    }
}
