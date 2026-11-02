<?php

declare(strict_types=1);

namespace Ravi\Agent;

use Ravi\Services\SandboxService;
use Ravi\Models\Conversation;
use Ravi\Core\Database;

/**
 * Gerenciador de Contexto do Projeto
 * 
 * Mantém uma visão global do projeto que persiste entre conversas.
 * Permite ao Claude entender projetos grandes sem precisar reler
 * todos os arquivos a cada interação.
 * 
 * Funcionalidades:
 * - Cache de análise do projeto
 * - Mapa de dependências entre arquivos
 * - Histórico de modificações
 * - Resumos inteligentes de arquivos longos
 */
class ContextManager
{
    private SandboxService $sandbox;
    private string $containerName;
    private int $projectId;
    private array $context = [];
    private ProjectAnalyzer $analyzer;
    
    // Limite de tokens para contexto (deixar espaço para resposta)
    private int $maxContextTokens = 100000;
    
    // Cache de arquivos lidos
    private array $fileCache = [];
    
    public function __construct(
        SandboxService $sandbox, 
        string $containerName,
        int $projectId
    ) {
        $this->sandbox = $sandbox;
        $this->containerName = $containerName;
        $this->projectId = $projectId;
        $this->analyzer = new ProjectAnalyzer($sandbox, $containerName);
    }
    
    /**
     * Inicializa ou carrega contexto do projeto
     */
    public function initialize(): self
    {
        // Tenta carregar contexto existente do banco
        $cached = $this->loadCachedContext();
        
        if ($cached) {
            $this->context = $cached;
            
            // Verifica se precisa atualizar (mais de 1 hora)
            $lastUpdate = strtotime($this->context['last_update'] ?? '2000-01-01');
            if (time() - $lastUpdate > 3600) {
                $this->refreshContext();
            }
        } else {
            // Primeira análise
            $this->buildContext();
        }
        
        return $this;
    }
    
    /**
     * Constrói contexto completo do projeto
     */
    public function buildContext(): void
    {
        $this->context = [
            'project_id' => $this->projectId,
            'last_update' => date('Y-m-d H:i:s'),
            'analysis' => [],
            'file_summaries' => [],
            'dependency_graph' => [],
            'recent_changes' => [],
            'important_code' => [],
            'todos' => [],
            'issues' => []
        ];
        
        // Executa análise do projeto
        $this->context['analysis'] = $this->analyzer->analyze();
        
        // Cria sumários de arquivos importantes
        $this->createFileSummaries();
        
        // Constrói grafo de dependências
        $this->buildDependencyGraph();
        
        // Extrai TODOs e FIXMEs
        $this->extractTodos();
        
        // Salva no banco
        $this->saveContext();
    }
    
    /**
     * Atualiza contexto incrementalmente
     */
    public function refreshContext(): void
    {
        // Verifica arquivos modificados
        $modifiedFiles = $this->getModifiedFiles();
        
        if (!empty($modifiedFiles)) {
            // Atualiza apenas sumários dos modificados
            foreach ($modifiedFiles as $file) {
                $this->updateFileSummary($file);
            }
            
            $this->context['recent_changes'] = array_merge(
                $this->context['recent_changes'],
                array_map(fn($f) => [
                    'file' => $f,
                    'timestamp' => date('Y-m-d H:i:s')
                ], $modifiedFiles)
            );
            
            // Mantém apenas últimas 50 mudanças
            $this->context['recent_changes'] = array_slice(
                $this->context['recent_changes'], 
                -50
            );
        }
        
        $this->context['last_update'] = date('Y-m-d H:i:s');
        $this->saveContext();
    }
    
    /**
     * Cria sumários inteligentes de arquivos importantes
     */
    private function createFileSummaries(): void
    {
        $analysis = $this->context['analysis'];
        $language = $analysis['primary_language'] ?? 'php';
        $ext = $this->getExtensionForLanguage($language);
        
        // Lista arquivos de código
        $result = $this->sandbox->execute(
            $this->containerName,
            "find /workspace -name '*.{$ext}' ! -path '*/vendor/*' ! -path '*/node_modules/*' 2>/dev/null | head -100"
        );
        
        if (!$result['success']) return;
        
        $files = array_filter(explode("\n", $result['output']));
        
        foreach ($files as $file) {
            $this->createFileSummary($file);
        }
    }
    
    /**
     * Cria sumário de um arquivo específico
     */
    private function createFileSummary(string $filePath): void
    {
        $content = $this->sandbox->readFile($this->containerName, $filePath);
        if (empty($content)) return;
        
        $relativePath = str_replace('/workspace/', '', $filePath);
        $lines = explode("\n", $content);
        $lineCount = count($lines);
        
        $summary = [
            'path' => $relativePath,
            'lines' => $lineCount,
            'size' => strlen($content),
            'updated_at' => date('Y-m-d H:i:s'),
            'type' => $this->detectFileType($filePath, $content),
            'exports' => [],
            'imports' => [],
            'classes' => [],
            'functions' => [],
            'description' => ''
        ];
        
        // Extrai estrutura baseado na linguagem
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        
        switch ($ext) {
            case 'php':
                $summary = array_merge($summary, $this->parsePhpFile($content));
                break;
            case 'js':
            case 'jsx':
            case 'ts':
            case 'tsx':
                $summary = array_merge($summary, $this->parseJsFile($content));
                break;
            case 'py':
                $summary = array_merge($summary, $this->parsePythonFile($content));
                break;
        }
        
        // Gera descrição curta
        $summary['description'] = $this->generateFileDescription($summary);
        
        $this->context['file_summaries'][$relativePath] = $summary;
    }
    
    /**
     * Atualiza sumário de arquivo específico
     */
    private function updateFileSummary(string $filePath): void
    {
        $this->createFileSummary("/workspace/{$filePath}");
    }
    
    /**
     * Parse de arquivo PHP
     */
    private function parsePhpFile(string $content): array
    {
        $result = [
            'namespace' => '',
            'classes' => [],
            'traits' => [],
            'interfaces' => [],
            'functions' => [],
            'imports' => []
        ];
        
        // Namespace
        if (preg_match('/namespace\s+([^;]+);/', $content, $m)) {
            $result['namespace'] = $m[1];
        }
        
        // Use statements
        preg_match_all('/use\s+([^;]+);/', $content, $matches);
        $result['imports'] = $matches[1] ?? [];
        
        // Classes
        preg_match_all('/(?:abstract\s+|final\s+)?class\s+(\w+)(?:\s+extends\s+(\w+))?(?:\s+implements\s+([^{]+))?/', $content, $matches);
        foreach ($matches[1] ?? [] as $i => $class) {
            $result['classes'][] = [
                'name' => $class,
                'extends' => $matches[2][$i] ?? null,
                'implements' => $matches[3][$i] ?? null
            ];
        }
        
        // Interfaces
        preg_match_all('/interface\s+(\w+)/', $content, $matches);
        $result['interfaces'] = $matches[1] ?? [];
        
        // Traits
        preg_match_all('/trait\s+(\w+)/', $content, $matches);
        $result['traits'] = $matches[1] ?? [];
        
        // Public methods
        preg_match_all('/public\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)/', $content, $matches);
        foreach ($matches[1] ?? [] as $i => $func) {
            $result['functions'][] = [
                'name' => $func,
                'params' => $matches[2][$i] ?? '',
                'visibility' => 'public'
            ];
        }
        
        return $result;
    }
    
    /**
     * Parse de arquivo JavaScript/TypeScript
     */
    private function parseJsFile(string $content): array
    {
        $result = [
            'exports' => [],
            'imports' => [],
            'classes' => [],
            'functions' => [],
            'components' => []
        ];
        
        // Imports
        preg_match_all('/import\s+(?:{([^}]+)}|(\w+))\s+from\s+[\'"]([^\'"]+)[\'"]/', $content, $matches);
        foreach ($matches[3] ?? [] as $i => $module) {
            $result['imports'][] = [
                'module' => $module,
                'named' => $matches[1][$i] ?? null,
                'default' => $matches[2][$i] ?? null
            ];
        }
        
        // Exports
        preg_match_all('/export\s+(?:default\s+)?(?:const|let|var|function|class)\s+(\w+)/', $content, $matches);
        $result['exports'] = $matches[1] ?? [];
        
        // Classes
        preg_match_all('/class\s+(\w+)(?:\s+extends\s+(\w+))?/', $content, $matches);
        foreach ($matches[1] ?? [] as $i => $class) {
            $result['classes'][] = [
                'name' => $class,
                'extends' => $matches[2][$i] ?? null
            ];
        }
        
        // Functions
        preg_match_all('/(?:async\s+)?function\s+(\w+)\s*\(/', $content, $matches);
        $result['functions'] = array_map(fn($f) => ['name' => $f], $matches[1] ?? []);
        
        // Arrow functions exportadas
        preg_match_all('/(?:export\s+)?const\s+(\w+)\s*=\s*(?:async\s+)?\([^)]*\)\s*=>/', $content, $matches);
        foreach ($matches[1] ?? [] as $func) {
            $result['functions'][] = ['name' => $func, 'type' => 'arrow'];
        }
        
        // React components (heurística)
        preg_match_all('/(?:export\s+)?(?:default\s+)?(?:function|const)\s+([A-Z]\w+)/', $content, $matches);
        $result['components'] = $matches[1] ?? [];
        
        return $result;
    }
    
    /**
     * Parse de arquivo Python
     */
    private function parsePythonFile(string $content): array
    {
        $result = [
            'imports' => [],
            'classes' => [],
            'functions' => [],
            'exports' => []
        ];
        
        // Imports
        preg_match_all('/(?:from\s+(\S+)\s+)?import\s+([^\n]+)/', $content, $matches);
        foreach ($matches[2] ?? [] as $i => $imported) {
            $result['imports'][] = [
                'from' => $matches[1][$i] ?? null,
                'import' => trim($imported)
            ];
        }
        
        // Classes
        preg_match_all('/class\s+(\w+)(?:\(([^)]*)\))?:/', $content, $matches);
        foreach ($matches[1] ?? [] as $i => $class) {
            $result['classes'][] = [
                'name' => $class,
                'extends' => $matches[2][$i] ?? null
            ];
        }
        
        // Functions
        preg_match_all('/(?:async\s+)?def\s+(\w+)\s*\(([^)]*)\)/', $content, $matches);
        foreach ($matches[1] ?? [] as $i => $func) {
            $result['functions'][] = [
                'name' => $func,
                'params' => $matches[2][$i] ?? ''
            ];
        }
        
        // __all__ exports
        if (preg_match('/__all__\s*=\s*\[([^\]]+)\]/', $content, $m)) {
            preg_match_all('/[\'"](\w+)[\'"]/', $m[1], $exports);
            $result['exports'] = $exports[1] ?? [];
        }
        
        return $result;
    }
    
    /**
     * Gera descrição curta do arquivo
     */
    private function generateFileDescription(array $summary): string
    {
        $parts = [];
        
        if (!empty($summary['classes'])) {
            $classNames = array_map(fn($c) => $c['name'], $summary['classes']);
            $parts[] = "Classes: " . implode(', ', array_slice($classNames, 0, 3));
        }
        
        if (!empty($summary['components'])) {
            $parts[] = "Components: " . implode(', ', array_slice($summary['components'], 0, 3));
        }
        
        if (!empty($summary['functions'])) {
            $funcCount = count($summary['functions']);
            $parts[] = "{$funcCount} funções";
        }
        
        if (empty($parts)) {
            $parts[] = "{$summary['lines']} linhas";
        }
        
        return implode(' | ', $parts);
    }
    
    /**
     * Constrói grafo de dependências
     */
    private function buildDependencyGraph(): void
    {
        $graph = [];
        
        foreach ($this->context['file_summaries'] as $path => $summary) {
            $imports = $summary['imports'] ?? [];
            $dependencies = [];
            
            foreach ($imports as $import) {
                if (is_array($import)) {
                    $module = $import['module'] ?? $import['from'] ?? $import;
                } else {
                    $module = $import;
                }
                
                // Ignora dependências externas
                if (!str_starts_with($module, '.') && !str_starts_with($module, '/')) {
                    continue;
                }
                
                $dependencies[] = $module;
            }
            
            if (!empty($dependencies)) {
                $graph[$path] = $dependencies;
            }
        }
        
        $this->context['dependency_graph'] = $graph;
    }
    
    /**
     * Extrai TODOs e FIXMEs do código
     */
    private function extractTodos(): void
    {
        $result = $this->sandbox->execute(
            $this->containerName,
            "grep -rn 'TODO\\|FIXME\\|XXX\\|HACK' /workspace --include='*.php' --include='*.js' --include='*.ts' --include='*.py' 2>/dev/null | head -50"
        );
        
        if (!$result['success']) return;
        
        $todos = [];
        $lines = array_filter(explode("\n", $result['output']));
        
        foreach ($lines as $line) {
            if (preg_match('/^([^:]+):(\d+):(.+)$/', $line, $m)) {
                $file = str_replace('/workspace/', '', $m[1]);
                $lineNum = $m[2];
                $text = trim($m[3]);
                
                // Extrai tipo e mensagem
                if (preg_match('/(TODO|FIXME|XXX|HACK)[:\s]*(.*)$/i', $text, $tm)) {
                    $todos[] = [
                        'file' => $file,
                        'line' => (int)$lineNum,
                        'type' => strtoupper($tm[1]),
                        'message' => trim($tm[2])
                    ];
                }
            }
        }
        
        $this->context['todos'] = $todos;
    }
    
    /**
     * Detecta tipo de arquivo
     */
    private function detectFileType(string $path, string $content): string
    {
        $basename = basename($path);
        
        // Por nome
        if (str_contains($basename, 'Controller')) return 'controller';
        if (str_contains($basename, 'Service')) return 'service';
        if (str_contains($basename, 'Model')) return 'model';
        if (str_contains($basename, 'Repository')) return 'repository';
        if (str_contains($basename, 'Test')) return 'test';
        if (str_contains($basename, '.test.') || str_contains($basename, '.spec.')) return 'test';
        if (str_contains($basename, 'config')) return 'config';
        if (str_contains($basename, 'middleware')) return 'middleware';
        if (str_contains($basename, 'route')) return 'routes';
        
        // Por conteúdo (heurística)
        if (str_contains($content, 'extends Controller')) return 'controller';
        if (str_contains($content, 'extends Model')) return 'model';
        if (str_contains($content, 'describe(') || str_contains($content, 'it(')) return 'test';
        if (str_contains($content, 'React') || str_contains($content, 'useState')) return 'component';
        
        return 'source';
    }
    
    /**
     * Retorna extensão para linguagem
     */
    private function getExtensionForLanguage(string $language): string
    {
        return match($language) {
            'php' => 'php',
            'javascript' => 'js',
            'typescript' => 'ts',
            'python' => 'py',
            'ruby' => 'rb',
            'go' => 'go',
            'rust' => 'rs',
            default => 'php'
        };
    }
    
    /**
     * Obtém arquivos modificados recentemente
     */
    private function getModifiedFiles(): array
    {
        $lastUpdate = $this->context['last_update'] ?? '2000-01-01';
        
        $result = $this->sandbox->execute(
            $this->containerName,
            "find /workspace -type f -newer /tmp/last_check 2>/dev/null | head -50"
        );
        
        // Cria arquivo de referência para próxima verificação
        $this->sandbox->execute(
            $this->containerName,
            "touch /tmp/last_check"
        );
        
        if (!$result['success']) return [];
        
        return array_filter(
            array_map(
                fn($f) => str_replace('/workspace/', '', $f),
                explode("\n", $result['output'])
            )
        );
    }
    
    /**
     * Salva contexto no banco de dados
     */
    private function saveContext(): void
    {
        $json = json_encode($this->context, JSON_UNESCAPED_UNICODE);
        
        Database::getInstance()->execute(
            "INSERT INTO project_contexts (project_id, context_data, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE context_data = VALUES(context_data), updated_at = NOW()",
            [$this->projectId, $json]
        );
    }
    
    /**
     * Carrega contexto do banco de dados
     */
    private function loadCachedContext(): ?array
    {
        $row = Database::getInstance()->fetch(
            "SELECT context_data FROM project_contexts WHERE project_id = ?",
            [$this->projectId]
        );
        
        if ($row && !empty($row['context_data'])) {
            return json_decode($row['context_data'], true);
        }
        
        return null;
    }
    
    /**
     * Gera contexto otimizado para o Claude
     * 
     * Prioriza informações mais relevantes dentro do limite de tokens
     */
    public function getContextForClaude(string $task = ''): string
    {
        $parts = [];
        
        // 1. Resumo do projeto (sempre inclui)
        $parts[] = "# Contexto do Projeto\n";
        $parts[] = $this->context['analysis']['summary'] ?? 'Projeto não analisado';
        $parts[] = "\n";
        
        // 2. Linguagem e frameworks
        $analysis = $this->context['analysis'] ?? [];
        if (!empty($analysis['frameworks'])) {
            $parts[] = "**Frameworks:** " . implode(', ', $analysis['frameworks']);
        }
        if (!empty($analysis['patterns'])) {
            $parts[] = "**Padrões:** " . implode(', ', $analysis['patterns']);
        }
        $parts[] = "\n";
        
        // 3. Estrutura principal (resumida)
        $parts[] = "## Estrutura Principal\n";
        $structure = array_keys($analysis['structure'] ?? []);
        $parts[] = "```\n" . implode("\n", array_slice($structure, 0, 20)) . "\n```\n";
        
        // 4. Arquivos relevantes para a tarefa
        if ($task) {
            $relevantFiles = $this->findRelevantFiles($task);
            if (!empty($relevantFiles)) {
                $parts[] = "## Arquivos Relevantes para Esta Tarefa\n";
                foreach ($relevantFiles as $file => $summary) {
                    $parts[] = "- **{$file}**: {$summary['description']}\n";
                }
                $parts[] = "\n";
            }
        }
        
        // 5. Convenções a seguir
        $conventions = $analysis['conventions'] ?? [];
        if (!empty($conventions)) {
            $parts[] = "## Convenções do Projeto\n";
            foreach ($conventions as $key => $value) {
                $parts[] = "- {$key}: {$value}\n";
            }
            $parts[] = "\n";
        }
        
        // 6. TODOs pendentes (se relevante)
        if ($task && str_contains(strtolower($task), 'todo') || str_contains(strtolower($task), 'fixme')) {
            $todos = array_slice($this->context['todos'] ?? [], 0, 10);
            if (!empty($todos)) {
                $parts[] = "## TODOs Pendentes\n";
                foreach ($todos as $todo) {
                    $parts[] = "- [{$todo['type']}] {$todo['file']}:{$todo['line']} - {$todo['message']}\n";
                }
                $parts[] = "\n";
            }
        }
        
        // 7. Mudanças recentes
        $recentChanges = array_slice($this->context['recent_changes'] ?? [], -5);
        if (!empty($recentChanges)) {
            $parts[] = "## Mudanças Recentes\n";
            foreach ($recentChanges as $change) {
                $parts[] = "- {$change['file']} ({$change['timestamp']})\n";
            }
        }
        
        return implode('', $parts);
    }
    
    /**
     * Encontra arquivos relevantes para uma tarefa
     */
    private function findRelevantFiles(string $task): array
    {
        $relevant = [];
        $taskLower = strtolower($task);
        $summaries = $this->context['file_summaries'] ?? [];
        
        // Palavras-chave da tarefa
        $keywords = preg_split('/\s+/', $taskLower);
        $keywords = array_filter($keywords, fn($k) => strlen($k) > 3);
        
        foreach ($summaries as $path => $summary) {
            $score = 0;
            $pathLower = strtolower($path);
            
            // Match no caminho do arquivo
            foreach ($keywords as $keyword) {
                if (str_contains($pathLower, $keyword)) {
                    $score += 10;
                }
            }
            
            // Match em classes/funções
            foreach ($summary['classes'] ?? [] as $class) {
                $className = strtolower($class['name']);
                foreach ($keywords as $keyword) {
                    if (str_contains($className, $keyword)) {
                        $score += 5;
                    }
                }
            }
            
            foreach ($summary['functions'] ?? [] as $func) {
                $funcName = strtolower($func['name']);
                foreach ($keywords as $keyword) {
                    if (str_contains($funcName, $keyword)) {
                        $score += 3;
                    }
                }
            }
            
            if ($score > 0) {
                $relevant[$path] = $summary;
                $relevant[$path]['relevance_score'] = $score;
            }
        }
        
        // Ordena por relevância
        uasort($relevant, fn($a, $b) => ($b['relevance_score'] ?? 0) - ($a['relevance_score'] ?? 0));
        
        // Retorna top 10
        return array_slice($relevant, 0, 10, true);
    }
    
    /**
     * Lê arquivo com cache
     */
    public function readFile(string $path): string
    {
        if (!isset($this->fileCache[$path])) {
            $this->fileCache[$path] = $this->sandbox->readFile(
                $this->containerName,
                "/workspace/{$path}"
            );
        }
        
        return $this->fileCache[$path];
    }
    
    /**
     * Limpa cache de arquivos
     */
    public function clearFileCache(): void
    {
        $this->fileCache = [];
    }
    
    /**
     * Retorna análise completa
     */
    public function getAnalysis(): array
    {
        return $this->context['analysis'] ?? [];
    }
    
    /**
     * Retorna sumários de arquivos
     */
    public function getFileSummaries(): array
    {
        return $this->context['file_summaries'] ?? [];
    }
    
    /**
     * Retorna grafo de dependências
     */
    public function getDependencyGraph(): array
    {
        return $this->context['dependency_graph'] ?? [];
    }
}
