<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Models\Conversation;
use Ravi\Models\Message;
use Ravi\Models\GeneratedFile;
use Ravi\Agent\ToolRegistry;
use Ravi\Agent\ToolExecutor;
use Ravi\Agent\Agents\OrchestratorAgent;

/**
 * Serviço Multi-Agente
 * 
 * Orquestra o sistema de múltiplos agentes para desenvolvimento autônomo.
 * Similar ao Manus AI - recebe um prompt e trabalha até completar.
 */
class MultiAgentService
{
    private ClaudeService $claude;
    private SandboxService $sandbox;
    private ToolExecutor $toolExecutor;
    private ToolRegistry $toolRegistry;
    private OrchestratorAgent $orchestrator;
    
    private Conversation $conversation;
    private string $containerName;
    /** @var callable|null */
    private $onUpdate = null;
    
    // Configurações
    private bool $enableReview = true;
    private bool $enableTesting = true;
    private int $maxRetries = 3;
    
    public function __construct(
        ClaudeService $claude,
        SandboxService $sandbox,
        ToolRegistry $toolRegistry
    ) {
        $this->claude = $claude;
        $this->sandbox = $sandbox;
        $this->toolRegistry = $toolRegistry;
        $this->toolExecutor = new ToolExecutor($sandbox, $toolRegistry);
        
        // Cria orquestrador com todos os agentes
        $this->orchestrator = new OrchestratorAgent($claude, $this->toolExecutor);
    }
    
    /**
     * Define callback para atualizações em tempo real
     */
    public function onUpdate(callable $callback): self
    {
        $this->onUpdate = $callback;
        
        // Propaga para o orquestrador
        $this->orchestrator->onUpdate(function($data) use ($callback) {
            $callback($data);
        });
        
        return $this;
    }
    
    /**
     * Habilita/desabilita revisão de código
     */
    public function setEnableReview(bool $enable): self
    {
        $this->enableReview = $enable;
        return $this;
    }
    
    /**
     * Habilita/desabilita testes
     */
    public function setEnableTesting(bool $enable): self
    {
        $this->enableTesting = $enable;
        return $this;
    }
    
    /**
     * Executa uma tarefa completa de desenvolvimento
     */
    public function executeTask(Conversation $conversation, string $task): array
    {
        $this->conversation = $conversation;
        
        // Obtém ou cria container para o projeto
        $project = $conversation->project();
        $this->containerName = $this->sandbox->getOrCreateContainer($project->uuid);
        $this->toolExecutor->setContainer($this->containerName);
        
        // Marca conversa como rodando
        $conversation->update([
            'status' => 'running'
        ]);
        
        $this->broadcast([
            'type' => 'status',
            'status' => 'running',
            'message' => 'Iniciando sistema multi-agente...'
        ]);
        
        // Salva mensagem do usuário
        Message::createUserMessage($conversation->id, $task);
        
        try {
            // Executa orquestrador
            $result = $this->orchestrator->executeTask($task, [
                'skip_review' => !$this->enableReview,
                'skip_tests' => !$this->enableTesting
            ]);
            
            // Salva resultado como mensagem do assistente
            $this->saveResultAsMessage($result);
            
            // Coleta arquivos gerados
            $files = $this->collectAndSaveFiles($result);

            // Persiste tokens e custo agregados de todos os agentes
            $usageStats = $result['usage_stats'] ?? [];
            $totalInput = (int) array_sum(array_column($usageStats, 'input'));
            $totalOutput = (int) array_sum(array_column($usageStats, 'output'));
            $totalCost = (float) ($result['total_cost_usd'] ?? array_sum(array_column($usageStats, 'cost')));
            $conversation->addUsage($totalInput, $totalOutput, $totalCost);

            // Marca como completo
            $conversation->update([
                'status' => 'completed'
            ]);
            
            $this->broadcast([
                'type' => 'status',
                'status' => 'completed',
                'message' => 'Tarefa concluída!'
            ]);
            
            return [
                'success' => $result['success'],
                'result' => $result,
                'files' => $files,
                'execution_log' => $result['execution_log'] ?? []
            ];
            
        } catch (\Exception $e) {
            // Marca como falha
            $conversation->update([
                'status' => 'failed'
            ]);
            
            $this->broadcast([
                'type' => 'error',
                'message' => $e->getMessage()
            ]);
            
            logger()->error('Multi-agent execution failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage()
            ]);
            
            // Salva erro como mensagem
            Message::createAssistantMessage(
                $conversation->id,
                "❌ Erro durante execução: " . $e->getMessage()
            );
            
            throw $e;
        }
    }
    
    /**
     * Salva resultado como mensagem do assistente
     */
    private function saveResultAsMessage(array $result): void
    {
        $content = $this->formatResultAsMarkdown($result);
        
        Message::createAssistantMessage(
            $this->conversation->id,
            $content
        );
    }
    
    /**
     * Formata resultado como Markdown
     */
    private function formatResultAsMarkdown(array $result): string
    {
        $md = "## ✅ Tarefa Concluída\n\n";
        
        // Plano executado
        if (!empty($result['plan'])) {
            $md .= "### 📋 Plano de Execução\n\n";
            $md .= "**Projeto:** " . ($result['plan']['project_name'] ?? 'N/A') . "\n";
            $md .= "**Complexidade:** " . ($result['plan']['estimated_complexity'] ?? 'N/A') . "\n\n";
            
            if (!empty($result['plan']['steps'])) {
                $md .= "**Etapas:**\n";
                foreach ($result['plan']['steps'] as $step) {
                    $md .= "- {$step['name']}\n";
                }
                $md .= "\n";
            }
        }
        
        // Arquivos criados
        if (!empty($result['files_created'])) {
            $md .= "### 📁 Arquivos Criados\n\n";
            foreach ($result['files_created'] as $file) {
                $md .= "- `{$file}`\n";
            }
            $md .= "\n";
        }
        
        // Resultado da revisão
        if (!empty($result['review'])) {
            $reviewResult = $result['review']['result'] ?? [];
            $status = $reviewResult['status'] ?? 'N/A';
            $score = $reviewResult['score'] ?? 'N/A';
            
            $md .= "### 🔍 Revisão de Código\n\n";
            $md .= "**Status:** {$status}\n";
            $md .= "**Score:** {$score}/100\n\n";
            
            if (!empty($reviewResult['issues'])) {
                $md .= "**Problemas encontrados:**\n";
                foreach ($reviewResult['issues'] as $issue) {
                    $severity = strtoupper($issue['severity'] ?? 'INFO');
                    $md .= "- [{$severity}] {$issue['description']}\n";
                }
                $md .= "\n";
            }
        }
        
        // Resultado dos testes
        if (!empty($result['tests'])) {
            $testResult = $result['tests']['result'] ?? [];
            $status = $testResult['status'] ?? 'N/A';
            
            $md .= "### 🧪 Testes\n\n";
            $md .= "**Status:** {$status}\n\n";
            
            if (!empty($testResult['tests'])) {
                $passed = count(array_filter($testResult['tests'], fn($t) => ($t['status'] ?? '') === 'passed'));
                $total = count($testResult['tests']);
                $md .= "**Resultados:** {$passed}/{$total} testes passaram\n\n";
            }
        }
        
        // Tempo de execução
        if (isset($result['duration_seconds'])) {
            $md .= "---\n";
            $md .= "⏱️ *Tempo de execução: {$result['duration_seconds']} segundos*\n";
        }
        
        return $md;
    }
    
    /**
     * Coleta e salva arquivos gerados
     */
    private function collectAndSaveFiles(array $result): array
    {
        $files = [];
        $createdFiles = $result['files_created'] ?? [];
        
        foreach ($createdFiles as $filePath) {
            try {
                // Lê conteúdo do arquivo
                $content = $this->sandbox->readFile($this->containerName, "/workspace/{$filePath}");
                
                // Obtém informações do arquivo
                $info = $this->sandbox->execute(
                    $this->containerName,
                    "stat -c '%s' /workspace/{$filePath} 2>/dev/null || echo '0'"
                );
                $size = (int)trim($info['output'] ?? '0');
                
                // Salva no banco
                $genFile = GeneratedFile::create([
                    'uuid' => uuid(),
                    'conversation_id' => $this->conversation->id,
                    'filename' => basename($filePath),
                    'path' => $filePath,
                    'mime_type' => $this->getMimeType($filePath),
                    'size' => $size,
                    'is_downloadable' => true
                ]);
                
                $files[] = $genFile;
                
            } catch (\Exception $e) {
                logger()->warning("Failed to save generated file", [
                    'file' => $filePath,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $files;
    }
    
    /**
     * Obtém MIME type do arquivo
     */
    private function getMimeType(string $filename): string
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
            'go' => 'text/x-go',
            'rs' => 'text/x-rust',
        ];
        
        return $types[$ext] ?? 'application/octet-stream';
    }
    
    /**
     * Broadcast de atualização
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
            $this->conversation->update([
                'status' => 'cancelled'
            ]);
        }
    }
    
    /**
     * Obtém log de execução do orquestrador
     */
    public function getExecutionLog(): array
    {
        return $this->orchestrator->getExecutionLog();
    }
    
    /**
     * Obtém plano atual
     */
    public function getCurrentPlan(): array
    {
        return $this->orchestrator->getCurrentPlan();
    }
}
