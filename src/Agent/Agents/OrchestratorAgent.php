<?php

declare(strict_types=1);

namespace Ravi\Agent\Agents;

use Ravi\Services\ClaudeService;
use Ravi\Agent\ToolExecutor;
use Ravi\Agent\ProjectAnalyzer;
use Ravi\Agent\ContextManager;

/**
 * Agente Orquestrador
 * 
 * O "cérebro" do sistema multi-agente.
 * RODA NO CLAUDE OPUS 4.5 - o modelo mais inteligente
 * 
 * Responsável por:
 * - Receber tarefas do usuário
 * - Coordenar outros agentes (que rodam em Sonnet)
 * - Gerenciar o fluxo de trabalho
 * - Resolver conflitos
 * - Garantir conclusão da tarefa
 * - Preparar entrega final (ZIP + documentação)
 */
class OrchestratorAgent extends BaseAgent
{
    private PlannerAgent $planner;
    private CoderAgent $coder;
    private ReviewerAgent $reviewer;
    private TesterAgent $tester;
    
    private array $executionLog = [];
    private array $currentPlan = [];
    private array $createdFiles = [];
    private array $modifiedFiles = [];
    private array $usageStats = [];
    
    public function __construct(
        ClaudeService $claude, 
        ToolExecutor $toolExecutor
    ) {
        parent::__construct($claude, $toolExecutor);
        
        // ORCHESTRATOR USA OPUS 4.5 (cérebro estratégico)
        $this->model = self::MODEL_OPUS;
        
        // Inicializa agentes especializados (usam SONNET por padrão)
        $this->planner = new PlannerAgent($claude, $toolExecutor);
        $this->coder = new CoderAgent($claude, $toolExecutor);
        $this->reviewer = new ReviewerAgent($claude, $toolExecutor);
        $this->tester = new TesterAgent($claude, $toolExecutor);
        
        // Workers usam Sonnet (mais barato, ainda muito bom)
        $this->planner->setModel(self::MODEL_SONNET);
        $this->coder->setModel(self::MODEL_SONNET);
        $this->reviewer->setModel(self::MODEL_SONNET);
        $this->tester->setModel(self::MODEL_SONNET);
        
        // Propaga callback de updates
        $this->planner->onUpdate(fn($data) => $this->broadcast($data));
        $this->coder->onUpdate(fn($data) => $this->broadcast($data));
        $this->reviewer->onUpdate(fn($data) => $this->broadcast($data));
        $this->tester->onUpdate(fn($data) => $this->broadcast($data));
    }
    
    public function getName(): string
    {
        return 'orchestrator';
    }
    
    public function getDescription(): string
    {
        return 'Coordena todos os agentes para completar tarefas complexas de desenvolvimento';
    }
    
    public function getSystemPrompt(): string
    {
        return <<<PROMPT
Você é o AGENTE ORQUESTRADOR de um sistema de desenvolvimento de software autônomo.
Você roda no Claude Opus 4.5, o modelo mais avançado, para tomar decisões estratégicas.

## Seu Papel
Você coordena uma equipe de agentes especializados para completar tarefas de desenvolvimento.
Os agentes workers rodam em Claude Sonnet para otimizar custos.

## Sua Equipe
1. **Planner** (Sonnet): Analisa tarefas e cria planos de execução
2. **Coder** (Sonnet): Implementa código seguindo especificações
3. **Reviewer** (Sonnet): Revisa código em busca de problemas
4. **Tester** (Sonnet): Cria e executa testes

## Tipos de Entrada que Você Recebe
1. **Novo projeto do zero**: Usuário descreve o que quer criar
2. **Projeto existente (ZIP)**: Usuário envia código para otimizar/modificar

## Suas Capacidades no Sandbox
Você tem AUTONOMIA TOTAL no ambiente controlado:
- Executar qualquer comando bash
- Instalar/desinstalar pacotes
- Criar/modificar/deletar arquivos
- Compilar e testar código
- Gerenciar estrutura de diretórios
- Executar testes automatizados

## Fluxo de Trabalho
1. Se recebeu ZIP: descompactar e analisar
2. Delegar ao Planner para criar plano
3. Para cada etapa do plano:
   - Delegar ao Coder para implementar
   - Delegar ao Reviewer para revisar
   - Se houver problemas críticos, iterar
   - Delegar ao Tester para testar
4. Preparar entrega final

## Entrega Final (CRÍTICO)
Ao concluir, você DEVE preparar:
1. **ZIP do projeto**: Todo código organizado e funcional
2. **README.md**: Documentação completa com:
   - Descrição do projeto
   - Requisitos de instalação
   - Instruções de uso
   - Estrutura de arquivos
3. **INSTALL.md**: Tutorial de instalação passo-a-passo

Use a ferramenta `prepare_delivery` ao finalizar.

Você NÃO implementa código diretamente - você DELEGA para os agentes especializados.
PROMPT;
    }
    
    public function getAllowedTools(): array
    {
        return [
            'file_list',
            'file_read',
            'file_write',
            'bash_execute',
            'task_complete',
            'prepare_delivery'
        ];
    }
    
    /**
     * Executa uma tarefa completa de desenvolvimento
     */
    public function executeTask(string $task, array $options = []): array
    {
        $this->executionLog = [];
        $this->createdFiles = [];
        $this->modifiedFiles = [];
        $this->usageStats = [
            'orchestrator' => ['input' => 0, 'output' => 0, 'cost' => 0],
            'planner' => ['input' => 0, 'output' => 0, 'cost' => 0],
            'coder' => ['input' => 0, 'output' => 0, 'cost' => 0],
            'reviewer' => ['input' => 0, 'output' => 0, 'cost' => 0],
            'tester' => ['input' => 0, 'output' => 0, 'cost' => 0],
        ];
        
        $startTime = microtime(true);
        
        $this->broadcast([
            'type' => 'orchestrator_start',
            'task' => $task,
            'model' => $this->model
        ]);
        
        $this->log('orchestrator', "🧠 Iniciando (Opus 4.5) - Tarefa: " . substr($task, 0, 100) . "...");
        
        try {
            // Fase 0: Verifica se há ZIP para extrair
            if ($options['has_upload'] ?? false) {
                $this->broadcast(['type' => 'phase', 'phase' => 'extraction', 'status' => 'started']);
                $this->extractUploadedProject($options['upload_path'] ?? '');
                $this->broadcast(['type' => 'phase', 'phase' => 'extraction', 'status' => 'completed']);
            }
            
            // Fase 0.5: Análise do projeto existente
            $existingFiles = $this->listExistingFiles();
            $projectContext = '';
            
            if (!empty($existingFiles)) {
                $this->broadcast(['type' => 'phase', 'phase' => 'analysis', 'status' => 'started']);
                $projectContext = $this->analyzeExistingProject();
                $this->broadcast(['type' => 'phase', 'phase' => 'analysis', 'status' => 'completed']);
            }
            
            // Fase 1: Planejamento
            $this->broadcast(['type' => 'phase', 'phase' => 'planning', 'status' => 'started']);
            $plan = $this->planTask($task, $projectContext);
            $this->currentPlan = $plan;
            $this->broadcast(['type' => 'phase', 'phase' => 'planning', 'status' => 'completed', 'plan' => $plan]);
            
            // Fase 2: Implementação
            $this->broadcast(['type' => 'phase', 'phase' => 'implementation', 'status' => 'started']);
            $implementationResult = $this->implementPlan($plan);
            $this->broadcast(['type' => 'phase', 'phase' => 'implementation', 'status' => 'completed']);
            
            // Fase 3: Revisão (se habilitada)
            $reviewResult = null;
            if (!($options['skip_review'] ?? false)) {
                $this->broadcast(['type' => 'phase', 'phase' => 'review', 'status' => 'started']);
                $reviewResult = $this->reviewImplementation($implementationResult);
                $this->broadcast(['type' => 'phase', 'phase' => 'review', 'status' => 'completed']);
                
                // Se revisão encontrou problemas críticos, corrige
                if ($this->hasReviewIssues($reviewResult)) {
                    $this->broadcast(['type' => 'phase', 'phase' => 'fixing', 'status' => 'started']);
                    $implementationResult = $this->fixIssues($reviewResult, $implementationResult);
                    $this->broadcast(['type' => 'phase', 'phase' => 'fixing', 'status' => 'completed']);
                }
            }
            
            // Fase 4: Testes (se habilitados)
            $testResult = null;
            if (!($options['skip_tests'] ?? false)) {
                $this->broadcast(['type' => 'phase', 'phase' => 'testing', 'status' => 'started']);
                $testResult = $this->testImplementation($implementationResult);
                $this->broadcast(['type' => 'phase', 'phase' => 'testing', 'status' => 'completed']);
            }
            
            // Fase 5: Preparação da entrega
            $this->broadcast(['type' => 'phase', 'phase' => 'delivery', 'status' => 'started']);
            $delivery = $this->prepareDelivery($plan, $implementationResult, $reviewResult, $testResult);
            $this->broadcast(['type' => 'phase', 'phase' => 'delivery', 'status' => 'completed', 'delivery' => $delivery]);
            
            // Compila resultado final
            $duration = round(microtime(true) - $startTime, 2);
            
            // Calcula custo total
            $totalCost = array_sum(array_column($this->usageStats, 'cost'));
            
            $result = [
                'success' => true,
                'task' => $task,
                'plan' => $plan,
                'implementation' => $implementationResult,
                'review' => $reviewResult,
                'tests' => $testResult,
                'delivery' => $delivery,
                'execution_log' => $this->executionLog,
                'duration_seconds' => $duration,
                'files_created' => $this->createdFiles,
                'files_modified' => $this->modifiedFiles,
                'usage_stats' => $this->usageStats,
                'total_cost_usd' => round($totalCost, 4)
            ];
            
            $this->broadcast([
                'type' => 'orchestrator_complete',
                'success' => true,
                'duration' => $duration,
                'delivery' => $delivery,
                'cost' => round($totalCost, 4)
            ]);
            
            $this->log('orchestrator', "✅ Tarefa concluída em {$duration}s | Custo: \${$totalCost}");
            
            return $result;
            
        } catch (\Exception $e) {
            $this->log('error', "❌ Erro: " . $e->getMessage());
            
            $this->broadcast([
                'type' => 'orchestrator_error',
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'task' => $task,
                'error' => $e->getMessage(),
                'execution_log' => $this->executionLog,
                'usage_stats' => $this->usageStats
            ];
        }
    }
    
    /**
     * Extrai projeto enviado pelo usuário
     */
    private function extractUploadedProject(string $uploadPath): void
    {
        if (empty($uploadPath)) return;
        
        $this->log('orchestrator', "📦 Extraindo projeto enviado...");
        
        // Detecta tipo de arquivo
        $ext = strtolower(pathinfo($uploadPath, PATHINFO_EXTENSION));
        
        $extractCmd = match($ext) {
            'zip' => "unzip -o '{$uploadPath}' -d /workspace",
            'tar' => "tar -xf '{$uploadPath}' -C /workspace",
            'gz', 'tgz' => "tar -xzf '{$uploadPath}' -C /workspace",
            default => null
        };
        
        if ($extractCmd) {
            $result = $this->toolExecutor->execute('bash_execute', [
                'command' => $extractCmd,
                'timeout' => 60
            ]);
            
            $this->log('orchestrator', "📦 Projeto extraído com sucesso");
        }
    }
    
    /**
     * Analisa projeto existente no workspace
     */
    private function analyzeExistingProject(): string
    {
        $this->log('orchestrator', "🔍 Analisando projeto existente...");
        
        try {
            $sandbox = $this->toolExecutor->getSandbox();
            $containerName = $this->toolExecutor->getContainerName();
            
            $analyzer = new ProjectAnalyzer($sandbox, $containerName);
            $analysis = $analyzer->analyze('/workspace');
            
            $context = $analyzer->toContext();
            
            $this->log('orchestrator', "🔍 Análise concluída: " . ($analysis['primary_language'] ?? 'N/A'));
            
            return $context;
            
        } catch (\Exception $e) {
            $this->log('orchestrator', "⚠️ Análise falhou: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Fase 1: Planejamento
     */
    private function planTask(string $task, string $projectContext = ''): array
    {
        $this->log('planner', "📋 Criando plano de execução... (Sonnet)");
        
        // Lista arquivos existentes
        $existingFiles = $this->listExistingFiles();
        
        // Adiciona contexto do projeto se disponível
        $taskWithContext = $task;
        if ($projectContext) {
            $taskWithContext = "{$projectContext}\n\n## Tarefa Solicitada\n{$task}";
        }
        
        // Delega ao Planner
        $result = $this->planner->createPlan($taskWithContext, $existingFiles);
        
        // Registra uso
        $this->updateUsageStats('planner', $this->planner);
        
        $this->log('planner', "📋 Plano criado com " . count($result['steps'] ?? []) . " etapas");
        
        return $result;
    }
    
    /**
     * Fase 2: Implementação
     */
    private function implementPlan(array $plan): array
    {
        $results = [];
        $steps = $plan['steps'] ?? [];
        
        // Ordena por dependências
        $steps = $this->sortByDependencies($steps);
        
        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;
            $totalSteps = count($steps);
            
            $this->log('coder', "💻 Implementando etapa {$stepNum}/{$totalSteps}: {$step['name']} (Sonnet)");
            
            $this->broadcast([
                'type' => 'step_start',
                'step' => $stepNum,
                'total' => $totalSteps,
                'name' => $step['name']
            ]);
            
            // Contexto inclui resultados anteriores
            $context = [
                'plan' => $plan,
                'current_step' => $step,
                'previous_results' => $results,
                'step_number' => $stepNum,
                'total_steps' => $totalSteps
            ];
            
            // Delega ao Coder
            $result = $this->coder->implementStep($step, $context);
            
            // Registra uso
            $this->updateUsageStats('coder', $this->coder);
            
            // Coleta arquivos criados
            $this->collectFilesFromResult($result);
            
            $results[] = [
                'step' => $step,
                'result' => $result
            ];
            
            $this->broadcast([
                'type' => 'step_complete',
                'step' => $stepNum,
                'total' => $totalSteps,
                'name' => $step['name']
            ]);
        }
        
        return [
            'steps_completed' => count($results),
            'results' => $results
        ];
    }
    
    /**
     * Fase 3: Revisão
     */
    private function reviewImplementation(array $implementation): ?array
    {
        $this->log('reviewer', "🔍 Iniciando revisão de código... (Sonnet)");
        
        // Coleta arquivos criados
        $files = $this->getAllFiles();
        
        if (empty($files)) {
            $this->log('reviewer', "🔍 Nenhum arquivo para revisar");
            return null;
        }
        
        // Delega ao Reviewer (sem o resultado bruto da implementação — ele lê os arquivos via tools).
        // Passar o $implementation completo aqui gera um payload enorme e estoura a API (HTTP 400).
        $reviewResult = $this->reviewer->reviewFiles($files, []);
        
        // Registra uso
        $this->updateUsageStats('reviewer', $this->reviewer);
        
        $this->log('reviewer', "🔍 Revisão concluída");
        
        return $reviewResult;
    }
    
    /**
     * Fase 4: Testes
     */
    private function testImplementation(array $implementation): ?array
    {
        $this->log('tester', "🧪 Iniciando testes... (Sonnet)");
        
        // Coleta arquivos criados
        $files = $this->getAllFiles();
        
        if (empty($files)) {
            $this->log('tester', "🧪 Nenhum arquivo para testar");
            return null;
        }
        
        // Delega ao Tester (sem o resultado bruto da implementação — ele lê os arquivos via tools).
        $testResult = $this->tester->testFiles($files, []);
        
        // Registra uso
        $this->updateUsageStats('tester', $this->tester);
        
        $this->log('tester', "🧪 Testes concluídos");
        
        return $testResult;
    }
    
    /**
     * Corrige problemas encontrados na revisão
     */
    private function fixIssues(array $reviewResult, array $implementation): array
    {
        $issues = $reviewResult['result']['issues'] ?? [];
        
        if (empty($issues)) {
            return $implementation;
        }
        
        $this->log('coder', "🔧 Corrigindo " . count($issues) . " problemas... (Sonnet)");
        
        // Filtra apenas issues críticas e altas
        $criticalIssues = array_filter($issues, fn($i) => in_array($i['severity'] ?? '', ['critical', 'high']));
        
        if (empty($criticalIssues)) {
            $this->log('coder', "🔧 Nenhum problema crítico para corrigir");
            return $implementation;
        }
        
        // Cria tarefa de correção
        $fixTask = "Corrija os seguintes problemas encontrados na revisão:\n\n";
        foreach ($criticalIssues as $issue) {
            $fixTask .= "- [{$issue['severity']}] {$issue['file']}: {$issue['description']}\n";
            if (!empty($issue['suggestion'])) {
                $fixTask .= "  Sugestão: {$issue['suggestion']}\n";
            }
        }
        
        // Delega ao Coder
        $fixResult = $this->coder->implement($fixTask, [
            'review_result' => $reviewResult,
            'original_implementation' => $implementation
        ]);
        
        // Registra uso
        $this->updateUsageStats('coder', $this->coder);
        
        // Merge resultados
        $implementation['fixes'] = $fixResult;
        
        return $implementation;
    }
    
    /**
     * Prepara entrega final (ZIP + documentação)
     */
    private function prepareDelivery(array $plan, array $implementation, ?array $review, ?array $tests): array
    {
        $this->log('orchestrator', "📦 Preparando entrega final...");
        
        $projectName = $plan['project_name'] ?? 'projeto';
        $projectNameSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($projectName));
        $timestamp = date('Ymd-His');
        
        // 1. Cria README.md
        $readme = $this->generateReadme($plan, $implementation, $review, $tests);
        $this->toolExecutor->execute('file_write', [
            'path' => '/workspace/README.md',
            'content' => $readme
        ]);
        $this->createdFiles[] = 'README.md';
        
        // 2. Cria INSTALL.md (tutorial de instalação)
        $install = $this->generateInstallGuide($plan);
        $this->toolExecutor->execute('file_write', [
            'path' => '/workspace/INSTALL.md',
            'content' => $install
        ]);
        $this->createdFiles[] = 'INSTALL.md';
        
        // 3. Cria CHANGELOG.md
        $changelog = $this->generateChangelog($implementation);
        $this->toolExecutor->execute('file_write', [
            'path' => '/workspace/CHANGELOG.md',
            'content' => $changelog
        ]);
        $this->createdFiles[] = 'CHANGELOG.md';
        
        // 4. Cria ZIP do projeto
        $zipName = "{$projectNameSlug}-{$timestamp}.zip";
        $zipPath = "/workspace/{$zipName}";
        
        $this->toolExecutor->execute('bash_execute', [
            'command' => "cd /workspace && zip -r '{$zipName}' . -x '*.zip' -x '__MACOSX/*' -x '.DS_Store'",
            'timeout' => 120
        ]);
        
        // Move ZIP para diretório de outputs
        $this->toolExecutor->execute('bash_execute', [
            'command' => "cp '{$zipPath}' /outputs/{$zipName} 2>/dev/null || true"
        ]);
        
        $this->log('orchestrator', "📦 Entrega preparada: {$zipName}");
        
        return [
            'zip_file' => $zipName,
            'zip_path' => $zipPath,
            'readme' => 'README.md',
            'install_guide' => 'INSTALL.md',
            'changelog' => 'CHANGELOG.md',
            'files_count' => count($this->getAllFiles()),
            'project_name' => $projectName
        ];
    }
    
    /**
     * Gera README.md
     */
    private function generateReadme(array $plan, array $implementation, ?array $review, ?array $tests): string
    {
        $projectName = $plan['project_name'] ?? 'Projeto';
        $description = $plan['description'] ?? '';
        
        $md = "# {$projectName}\n\n";
        $md .= "{$description}\n\n";
        $md .= "---\n\n";
        
        $md .= "## 📋 Sobre\n\n";
        $md .= "Este projeto foi desenvolvido autonomamente pelo **Ravi Developer**, ";
        $md .= "um sistema de desenvolvimento de software baseado em inteligência artificial.\n\n";
        
        // Características
        $md .= "## ✨ Características\n\n";
        foreach ($plan['steps'] ?? [] as $step) {
            $md .= "- {$step['name']}\n";
        }
        $md .= "\n";
        
        // Estrutura de arquivos
        $files = $this->getAllFiles();
        if (!empty($files)) {
            $md .= "## 📁 Estrutura de Arquivos\n\n";
            $md .= "```\n";
            foreach ($files as $file) {
                $md .= "{$file}\n";
            }
            $md .= "```\n\n";
        }
        
        // Instalação
        $md .= "## 🚀 Instalação\n\n";
        $md .= "Consulte o arquivo [INSTALL.md](INSTALL.md) para instruções detalhadas.\n\n";
        
        // Uso básico
        $md .= "## 💻 Uso\n\n";
        $md .= "```bash\n# Consulte INSTALL.md para instruções específicas\n```\n\n";
        
        // Tecnologias
        $analysis = $plan['architecture'] ?? [];
        if (!empty($analysis)) {
            $md .= "## 🛠️ Tecnologias\n\n";
            if (isset($analysis['language'])) $md .= "- **Linguagem:** {$analysis['language']}\n";
            if (isset($analysis['type'])) $md .= "- **Tipo:** {$analysis['type']}\n";
            $md .= "\n";
        }
        
        // Testes
        if ($tests) {
            $testStatus = $tests['result']['status'] ?? 'N/A';
            $md .= "## 🧪 Testes\n\n";
            $md .= "Status: **{$testStatus}**\n\n";
        }
        
        // Licença
        $md .= "## 📄 Licença\n\n";
        $md .= "Este projeto está sob a licença MIT.\n\n";
        
        // Créditos
        $md .= "---\n\n";
        $md .= "*Desenvolvido autonomamente por [" . (string) config('app.name', 'Ravi Developer') . "]("
             . rtrim((string) config('app.url', 'http://localhost'), '/') . ") - Powered by Claude AI*\n";
        
        return $md;
    }
    
    /**
     * Gera guia de instalação
     */
    private function generateInstallGuide(array $plan): string
    {
        $projectName = $plan['project_name'] ?? 'Projeto';
        $language = $plan['architecture']['language'] ?? 'php';
        
        $md = "# Guia de Instalação - {$projectName}\n\n";
        $md .= "Este guia fornece instruções passo-a-passo para instalar e configurar o projeto.\n\n";
        
        // Requisitos
        $md .= "## 📋 Requisitos\n\n";
        
        switch ($language) {
            case 'php':
                $md .= "- PHP 8.1 ou superior\n";
                $md .= "- Composer\n";
                $md .= "- MySQL/MariaDB ou SQLite\n";
                $md .= "- Servidor web (Apache/Nginx)\n";
                break;
            case 'python':
                $md .= "- Python 3.9 ou superior\n";
                $md .= "- pip\n";
                $md .= "- virtualenv (recomendado)\n";
                break;
            case 'javascript':
            case 'typescript':
                $md .= "- Node.js 18 ou superior\n";
                $md .= "- npm ou yarn\n";
                break;
            default:
                $md .= "- Verifique os arquivos do projeto para requisitos específicos\n";
        }
        $md .= "\n";
        
        // Instalação
        $md .= "## 🚀 Instalação\n\n";
        
        $md .= "### 1. Extrair Arquivos\n\n";
        $md .= "```bash\nunzip {$plan['project_name']}.zip -d /caminho/destino\ncd /caminho/destino\n```\n\n";
        
        $md .= "### 2. Instalar Dependências\n\n";
        
        switch ($language) {
            case 'php':
                $md .= "```bash\ncomposer install\n```\n\n";
                break;
            case 'python':
                $md .= "```bash\npython -m venv venv\nsource venv/bin/activate  # Linux/Mac\n# ou: venv\\Scripts\\activate  # Windows\npip install -r requirements.txt\n```\n\n";
                break;
            case 'javascript':
            case 'typescript':
                $md .= "```bash\nnpm install\n# ou: yarn install\n```\n\n";
                break;
        }
        
        $md .= "### 3. Configurar Ambiente\n\n";
        $md .= "```bash\ncp .env.example .env\n# Edite .env com suas configurações\n```\n\n";
        
        $md .= "### 4. Executar\n\n";
        
        switch ($language) {
            case 'php':
                $md .= "```bash\nphp -S localhost:8000 -t public\n```\n\n";
                break;
            case 'python':
                $md .= "```bash\npython main.py\n# ou: python -m flask run\n```\n\n";
                break;
            case 'javascript':
            case 'typescript':
                $md .= "```bash\nnpm start\n# ou: npm run dev\n```\n\n";
                break;
        }
        
        // Suporte
        $md .= "## 🆘 Suporte\n\n";
        $md .= "Em caso de problemas, verifique:\n";
        $md .= "1. Se todos os requisitos estão instalados\n";
        $md .= "2. Se as configurações do .env estão corretas\n";
        $md .= "3. Os logs de erro do sistema\n\n";
        
        $md .= "---\n\n";
        $md .= "*Gerado automaticamente por Ravi Developer*\n";
        
        return $md;
    }
    
    /**
     * Gera changelog
     */
    private function generateChangelog(array $implementation): string
    {
        $md = "# Changelog\n\n";
        $md .= "## [1.0.0] - " . date('Y-m-d') . "\n\n";
        $md .= "### Adicionado\n\n";
        
        foreach ($implementation['results'] ?? [] as $stepResult) {
            $stepName = $stepResult['step']['name'] ?? 'Etapa';
            $md .= "- {$stepName}\n";
        }
        
        $md .= "\n### Arquivos Criados\n\n";
        foreach ($this->createdFiles as $file) {
            $md .= "- `{$file}`\n";
        }
        
        if (!empty($this->modifiedFiles)) {
            $md .= "\n### Arquivos Modificados\n\n";
            foreach ($this->modifiedFiles as $file) {
                $md .= "- `{$file}`\n";
            }
        }
        
        return $md;
    }
    
    /**
     * Verifica se revisão tem issues críticas
     */
    private function hasReviewIssues(array $reviewResult): bool
    {
        $result = $reviewResult['result'] ?? [];
        
        if (($result['status'] ?? '') === 'rejected') {
            return true;
        }
        
        $issues = $result['issues'] ?? [];
        
        foreach ($issues as $issue) {
            if (in_array($issue['severity'] ?? '', ['critical', 'high'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Lista arquivos existentes no workspace
     */
    private function listExistingFiles(): array
    {
        try {
            $result = $this->toolExecutor->execute('file_list', [
                'path' => '/workspace',
                'recursive' => true
            ]);
            
            // Parse result para extrair nomes de arquivos
            $files = [];
            $lines = explode("\n", $result);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && !str_starts_with($line, '✅') && !str_starts_with($line, '📁')) {
                    if (preg_match('/^[📄🖼️📦]?\s*(.+)/', $line, $matches)) {
                        $files[] = trim($matches[1]);
                    }
                }
            }
            
            return $files;
            
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Obtém todos os arquivos do workspace
     */
    private function getAllFiles(): array
    {
        return array_unique(array_merge($this->createdFiles, $this->listExistingFiles()));
    }
    
    /**
     * Coleta arquivos de um resultado
     */
    private function collectFilesFromResult(array $result): void
    {
        $data = $result['result'] ?? [];
        
        if (is_array($data)) {
            $this->createdFiles = array_merge($this->createdFiles, $data['files_created'] ?? []);
            $this->modifiedFiles = array_merge($this->modifiedFiles, $data['files_modified'] ?? []);
        }
    }
    
    /**
     * Ordena etapas por dependências
     */
    private function sortByDependencies(array $steps): array
    {
        $sorted = [];
        $visited = [];
        
        $visit = function($step) use (&$visit, &$sorted, &$visited, $steps) {
            $id = $step['id'] ?? 0;
            
            if (isset($visited[$id])) {
                return;
            }
            
            $visited[$id] = true;
            
            foreach ($step['depends_on'] ?? [] as $depId) {
                foreach ($steps as $s) {
                    if (($s['id'] ?? 0) === $depId) {
                        $visit($s);
                        break;
                    }
                }
            }
            
            $sorted[] = $step;
        };
        
        foreach ($steps as $step) {
            $visit($step);
        }
        
        return $sorted;
    }
    
    /**
     * Atualiza estatísticas de uso por agente
     */
    private function updateUsageStats(string $agent, BaseAgent $agentInstance): void
    {
        if (!isset($this->usageStats[$agent])) {
            $this->usageStats[$agent] = ['input' => 0, 'output' => 0, 'cost' => 0];
        }

        $usage = $agentInstance->getLastUsage();

        $this->usageStats[$agent]['input']  += $usage['input_tokens'] ?? 0;
        $this->usageStats[$agent]['output'] += $usage['output_tokens'] ?? 0;
        $this->usageStats[$agent]['cost']   += $usage['estimated_cost'] ?? 0;
    }
    
    /**
     * Adiciona entrada ao log de execução
     */
    private function log(string $agent, string $message): void
    {
        $this->executionLog[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'agent' => $agent,
            'message' => $message
        ];
        
        $this->broadcast([
            'type' => 'log',
            'agent' => $agent,
            'message' => $message
        ]);
    }
    
    /**
     * Obtém log de execução
     */
    public function getExecutionLog(): array
    {
        return $this->executionLog;
    }
    
    /**
     * Obtém plano atual
     */
    public function getCurrentPlan(): array
    {
        return $this->currentPlan;
    }
    
    /**
     * Obtém estatísticas de uso
     */
    public function getUsageStats(): array
    {
        return $this->usageStats;
    }
}
