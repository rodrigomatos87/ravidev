<?php

declare(strict_types=1);

namespace Ravi\Services;

/**
 * Serviço de gerenciamento de sandboxes Docker
 */
class SandboxService
{
    private string $image;
    private string $workspaceBase;
    private int $timeout;
    private string $memoryLimit;
    private string $cpuLimit;
    private array $activeContainers = [];
    
    public function __construct()
    {
        $config = config('sandbox');
        
        $this->image = $config['image'];
        $this->workspaceBase = $config['workspace']['base_path'];
        $this->timeout = $config['timeout'];
        // Limites configuráveis pelo admin (Configurações → Recursos dos containers), com fallback
        // para o config/.env. Aplica-se a containers criados a partir de agora (não recria os ativos).
        $this->memoryLimit = \Ravi\Models\Setting::get('sandbox_memory', (string) $config['memory_limit']);
        $this->cpuLimit = \Ravi\Models\Setting::get('sandbox_cpu', (string) $config['cpu_limit']);
    }
    
    /**
     * Cria ou obtém container para um projeto
     */
    public function getOrCreateContainer(string $projectUuid): string
    {
        $containerName = "ravi-sandbox-{$projectUuid}";
        
        // Verifica se já existe
        if ($this->containerExists($containerName)) {
            if (!$this->isContainerRunning($containerName)) {
                $this->startContainer($containerName);
            }
            return $containerName;
        }
        
        // Cria novo container
        return $this->createContainer($projectUuid);
    }
    
    /**
     * Cria novo container Docker
     */
    public function createContainer(string $projectUuid): string
    {
        $containerName = "ravi-sandbox-{$projectUuid}";
        $workspacePath = $this->getWorkspacePath($projectUuid);
        
        // Garante que o diretório workspace existe e é gravável pelo usuário do container.
        // O container roda como 'sandbox' (uid 1000); o PHP roda como www-data (uid 33) e não
        // pode fazer chown sem root, então tornamos o diretório do workspace gravável (0777).
        if (!is_dir($workspacePath)) {
            mkdir($workspacePath, 0777, true);
        }
        @chmod($workspacePath, 0777);
        
        // Comando Docker para criar container
        $cmd = sprintf(
            'docker create ' .
            '--name %s ' .
            '--memory=%s ' .
            '--cpus=%s ' .
            '--network=none ' .  // Sem rede por padrão
            '--user=sandbox ' .
            '-v %s:/workspace:rw ' .
            '-w /workspace ' .
            '--read-only ' .
            '--tmpfs /tmp:rw,noexec,nosuid,size=256m ' .
            '%s',
            escapeshellarg($containerName),
            escapeshellarg($this->memoryLimit),
            escapeshellarg($this->cpuLimit),
            escapeshellarg($workspacePath),
            escapeshellarg($this->image)
        );
        
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \RuntimeException("Falha ao criar container: " . implode("\n", $output));
        }
        
        // Inicia o container
        $this->startContainer($containerName);
        
        logger()->info('Container criado', ['container' => $containerName, 'project' => $projectUuid]);
        
        return $containerName;
    }
    
    /**
     * Inicia container
     */
    public function startContainer(string $containerName): void
    {
        exec("docker start {$containerName} 2>&1", $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \RuntimeException("Falha ao iniciar container: " . implode("\n", $output));
        }
    }
    
    /**
     * Para container
     */
    public function stopContainer(string $containerName): void
    {
        exec("docker stop -t 5 {$containerName} 2>&1", $output, $returnCode);
    }
    
    /**
     * Remove container
     */
    public function removeContainer(string $containerName): void
    {
        exec("docker rm -f {$containerName} 2>&1");
        logger()->info('Container removido', ['container' => $containerName]);
    }
    
    /**
     * Verifica se container existe
     */
    public function containerExists(string $containerName): bool
    {
        exec("docker inspect {$containerName} 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Verifica se container está rodando
     */
    public function isContainerRunning(string $containerName): bool
    {
        $output = [];
        exec("docker inspect -f '{{.State.Running}}' {$containerName} 2>&1", $output);
        return isset($output[0]) && trim($output[0]) === 'true';
    }
    
    /**
     * Executa comando no container
     */
    public function execute(
        string $containerName, 
        string $command, 
        string $workingDir = '/workspace',
        int $timeout = 60
    ): array {
        // Sanitiza comando
        $command = $this->sanitizeCommand($command);
        
        // Monta comando docker exec
        $dockerCmd = sprintf(
            'timeout %d docker exec -w %s %s bash -c %s 2>&1',
            $timeout,
            escapeshellarg($workingDir),
            escapeshellarg($containerName),
            escapeshellarg($command)
        );
        
        $output = [];
        $returnCode = 0;
        $startTime = microtime(true);
        
        exec($dockerCmd, $output, $returnCode);
        
        $duration = round(microtime(true) - $startTime, 3);
        
        $result = [
            'success' => $returnCode === 0,
            'exit_code' => $returnCode,
            'output' => implode("\n", $output),
            'duration' => $duration,
            'command' => $command
        ];
        
        // Timeout específico (código 124)
        if ($returnCode === 124) {
            $result['success'] = false;
            $result['output'] = "Comando excedeu o timeout de {$timeout} segundos.\n" . $result['output'];
        }
        
        logger()->debug('Comando executado', [
            'container' => $containerName,
            'command' => substr($command, 0, 100),
            'exit_code' => $returnCode,
            'duration' => $duration
        ]);
        
        return $result;
    }
    
    /**
     * Executa código Python
     */
    public function executePython(string $containerName, string $code, int $timeout = 60): array
    {
        // Salva código em arquivo temporário
        $tempFile = '/tmp/script_' . uniqid() . '.py';
        $this->writeFile($containerName, $tempFile, $code);
        
        $result = $this->execute($containerName, "python3 {$tempFile}", '/workspace', $timeout);
        
        // Remove arquivo temporário
        $this->execute($containerName, "rm -f {$tempFile}", '/tmp', 5);
        
        return $result;
    }
    
    /**
     * Executa código Node.js
     */
    public function executeNode(string $containerName, string $code, int $timeout = 60): array
    {
        $tempFile = '/tmp/script_' . uniqid() . '.js';
        $this->writeFile($containerName, $tempFile, $code);
        
        $result = $this->execute($containerName, "node {$tempFile}", '/workspace', $timeout);
        
        $this->execute($containerName, "rm -f {$tempFile}", '/tmp', 5);
        
        return $result;
    }
    
    /**
     * Lê arquivo do container
     */
    public function readFile(string $containerName, string $path): ?string
    {
        $result = $this->execute($containerName, "cat " . escapeshellarg($path), '/workspace', 10);
        
        if ($result['success']) {
            return $result['output'];
        }
        
        return null;
    }
    
    /**
     * Escreve arquivo no container
     */
    public function writeFile(string $containerName, string $path, string $content, bool $append = false): bool
    {
        // Garante que o diretório existe
        $dir = dirname($path);
        if ($dir !== '.' && $dir !== '/') {
            $this->execute($containerName, "mkdir -p " . escapeshellarg($dir), '/workspace', 5);
        }
        
        // Codifica conteúdo em base64 para evitar problemas com caracteres especiais
        $encoded = base64_encode($content);
        $operator = $append ? '>>' : '>';
        
        $cmd = "echo {$encoded} | base64 -d {$operator} " . escapeshellarg($path);
        $result = $this->execute($containerName, $cmd, '/workspace', 10);
        
        return $result['success'];
    }
    
    /**
     * Lista arquivos em diretório
     */
    public function listFiles(
        string $containerName, 
        string $path = '/workspace', 
        bool $recursive = false,
        bool $includeHidden = false
    ): array {
        $flags = '-la';
        if ($recursive) {
            $flags = '-laR';
        }
        
        $cmd = "ls {$flags} " . escapeshellarg($path);
        $result = $this->execute($containerName, $cmd, '/workspace', 30);
        
        if (!$result['success']) {
            return [];
        }
        
        // Parse da saída do ls
        $files = [];
        $lines = explode("\n", $result['output']);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, 'total')) {
                continue;
            }
            
            // Parse da linha do ls -la
            if (preg_match('/^([drwx\-]+)\s+\d+\s+\w+\s+\w+\s+(\d+)\s+\w+\s+\d+\s+[\d:]+\s+(.+)$/', $line, $matches)) {
                $permissions = $matches[1];
                $size = (int)$matches[2];
                $name = $matches[3];
                
                // Ignora . e ..
                if ($name === '.' || $name === '..') {
                    continue;
                }
                
                // Ignora ocultos se não solicitado
                if (!$includeHidden && str_starts_with($name, '.')) {
                    continue;
                }
                
                $files[] = [
                    'name' => $name,
                    'type' => str_starts_with($permissions, 'd') ? 'directory' : 'file',
                    'size' => $size,
                    'permissions' => $permissions
                ];
            }
        }
        
        return $files;
    }
    
    /**
     * Verifica se arquivo existe
     */
    public function fileExists(string $containerName, string $path): bool
    {
        $result = $this->execute($containerName, "test -e " . escapeshellarg($path) . " && echo 1 || echo 0", '/workspace', 5);
        return trim($result['output']) === '1';
    }
    
    /**
     * Deleta arquivo
     */
    public function deleteFile(string $containerName, string $path): bool
    {
        $result = $this->execute($containerName, "rm -f " . escapeshellarg($path), '/workspace', 5);
        return $result['success'];
    }
    
    /**
     * Copia arquivos do container para o host
     */
    public function copyFromContainer(string $containerName, string $containerPath, string $hostPath): bool
    {
        $cmd = sprintf(
            'docker cp %s:%s %s 2>&1',
            escapeshellarg($containerName),
            escapeshellarg($containerPath),
            escapeshellarg($hostPath)
        );
        
        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Copia arquivos do host para o container
     */
    public function copyToContainer(string $containerName, string $hostPath, string $containerPath): bool
    {
        $cmd = sprintf(
            'docker cp %s %s:%s 2>&1',
            escapeshellarg($hostPath),
            escapeshellarg($containerName),
            escapeshellarg($containerPath)
        );
        
        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Obtém caminho do workspace no host
     */
    public function getWorkspacePath(string $projectUuid): string
    {
        return rtrim($this->workspaceBase, '/') . '/' . $projectUuid;
    }
    
    /**
     * Sanitiza comando para segurança
     */
    private function sanitizeCommand(string $command): string
    {
        // Lista de comandos perigosos bloqueados
        $blockedPatterns = [
            '/\brm\s+(-rf?\s+)?\/\s*$/',  // rm -rf /
            '/\bmkfs/',                     // Formatação de disco
            '/\bdd\s+.*of=\/dev/',          // Escrita direta em dispositivos
            '/\b:[(]?[);]?\s*{/',           // Fork bomb
            '/\bchmod\s+777\s+\//',         // chmod 777 /
        ];
        
        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $command)) {
                throw new \RuntimeException("Comando bloqueado por segurança");
            }
        }
        
        return $command;
    }
    
    /**
     * Limpa containers antigos
     */
    public function cleanupOldContainers(int $maxAgeMinutes = 120): int
    {
        $output = [];
        exec('docker ps -a --filter "name=ravi-sandbox-" --format "{{.Names}}\t{{.Status}}"', $output);
        
        $removed = 0;
        
        foreach ($output as $line) {
            $parts = explode("\t", $line);
            if (count($parts) < 2) continue;
            
            $name = $parts[0];
            $status = $parts[1];
            
            // Verifica se está parado há muito tempo
            if (str_contains($status, 'Exited')) {
                if (preg_match('/Exited.*?(\d+)\s*(hours?|minutes?)/', $status, $matches)) {
                    $amount = (int)$matches[1];
                    $unit = $matches[2];
                    
                    $minutes = str_contains($unit, 'hour') ? $amount * 60 : $amount;
                    
                    if ($minutes > $maxAgeMinutes) {
                        $this->removeContainer($name);
                        $removed++;
                    }
                }
            }
        }
        
        return $removed;
    }
    
    /**
     * Habilita rede temporariamente para uma operação
     */
    public function withNetwork(string $containerName, callable $callback): mixed
    {
        // Conecta container à rede
        exec("docker network connect bridge {$containerName} 2>&1");
        
        try {
            return $callback();
        } finally {
            // Desconecta da rede
            exec("docker network disconnect bridge {$containerName} 2>&1");
        }
    }
}
