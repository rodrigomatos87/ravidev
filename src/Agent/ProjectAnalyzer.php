<?php

declare(strict_types=1);

namespace Ravi\Agent;

use Ravi\Services\SandboxService;

/**
 * Analisador de Projeto
 * 
 * Analisa um projeto existente para criar um "mapa mental" que permite
 * ao Claude entender completamente a estrutura, padrões e convenções
 * antes de fazer qualquer modificação.
 * 
 * Isso é crucial para projetos grandes - sem análise prévia, o agente
 * pode criar código inconsistente ou duplicado.
 */
class ProjectAnalyzer
{
    private SandboxService $sandbox;
    private string $containerName;
    private array $analysis = [];
    
    // Extensões de código por linguagem
    private array $languageExtensions = [
        'php' => ['php', 'phtml'],
        'javascript' => ['js', 'jsx', 'mjs'],
        'typescript' => ['ts', 'tsx'],
        'python' => ['py', 'pyw'],
        'ruby' => ['rb'],
        'go' => ['go'],
        'rust' => ['rs'],
        'java' => ['java'],
        'csharp' => ['cs'],
        'cpp' => ['cpp', 'cc', 'cxx', 'hpp', 'h'],
        'html' => ['html', 'htm'],
        'css' => ['css', 'scss', 'sass', 'less'],
        'sql' => ['sql'],
        'shell' => ['sh', 'bash', 'zsh'],
        'yaml' => ['yaml', 'yml'],
        'json' => ['json'],
        'markdown' => ['md', 'markdown'],
        'xml' => ['xml'],
    ];
    
    public function __construct(SandboxService $sandbox, string $containerName)
    {
        $this->sandbox = $sandbox;
        $this->containerName = $containerName;
    }
    
    /**
     * Executa análise completa do projeto
     */
    public function analyze(string $workspacePath = '/workspace'): array
    {
        $this->analysis = [
            'timestamp' => date('Y-m-d H:i:s'),
            'workspace' => $workspacePath,
            'structure' => [],
            'statistics' => [],
            'languages' => [],
            'frameworks' => [],
            'patterns' => [],
            'dependencies' => [],
            'entry_points' => [],
            'config_files' => [],
            'documentation' => [],
            'tests' => [],
            'important_files' => [],
            'conventions' => [],
            'summary' => ''
        ];
        
        // 1. Análise de estrutura de diretórios
        $this->analyzeStructure($workspacePath);
        
        // 2. Detecta linguagens e frameworks
        $this->detectLanguagesAndFrameworks($workspacePath);
        
        // 3. Analisa dependências
        $this->analyzeDependencies($workspacePath);
        
        // 4. Identifica arquivos importantes
        $this->identifyImportantFiles($workspacePath);
        
        // 5. Detecta padrões arquiteturais
        $this->detectPatterns();
        
        // 6. Analisa convenções de código
        $this->analyzeConventions($workspacePath);
        
        // 7. Gera estatísticas
        $this->generateStatistics($workspacePath);
        
        // 8. Cria resumo executivo
        $this->generateSummary();
        
        return $this->analysis;
    }
    
    /**
     * Analisa estrutura de diretórios
     */
    private function analyzeStructure(string $path): void
    {
        $result = $this->sandbox->execute(
            $this->containerName,
            "find {$path} -type f -o -type d 2>/dev/null | head -500"
        );
        
        if (!$result['success']) {
            return;
        }
        
        $items = array_filter(explode("\n", $result['output']));
        $structure = [];
        
        foreach ($items as $item) {
            $relativePath = str_replace($path . '/', '', $item);
            if ($relativePath === $path) continue;
            
            // Ignora diretórios comuns
            if ($this->shouldIgnore($relativePath)) continue;
            
            $parts = explode('/', $relativePath);
            $current = &$structure;
            
            foreach ($parts as $part) {
                if (!isset($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }
        
        $this->analysis['structure'] = $structure;
    }
    
    /**
     * Detecta linguagens e frameworks
     */
    private function detectLanguagesAndFrameworks(string $path): void
    {
        $languages = [];
        $frameworks = [];
        
        // Conta arquivos por extensão
        $result = $this->sandbox->execute(
            $this->containerName,
            "find {$path} -type f -name '*.*' 2>/dev/null | sed 's/.*\\.//' | sort | uniq -c | sort -rn | head -20"
        );
        
        if ($result['success']) {
            $lines = array_filter(explode("\n", $result['output']));
            foreach ($lines as $line) {
                if (preg_match('/^\s*(\d+)\s+(\w+)$/', trim($line), $matches)) {
                    $count = (int)$matches[1];
                    $ext = strtolower($matches[2]);
                    
                    foreach ($this->languageExtensions as $lang => $exts) {
                        if (in_array($ext, $exts)) {
                            $languages[$lang] = ($languages[$lang] ?? 0) + $count;
                        }
                    }
                }
            }
        }
        
        // Detecta frameworks por arquivos característicos
        $frameworkIndicators = [
            // PHP
            'composer.json' => ['check' => 'laravel/framework', 'framework' => 'Laravel'],
            'artisan' => ['exists' => true, 'framework' => 'Laravel'],
            'symfony.lock' => ['exists' => true, 'framework' => 'Symfony'],
            'wp-config.php' => ['exists' => true, 'framework' => 'WordPress'],
            'craft' => ['exists' => true, 'framework' => 'CraftCMS'],
            
            // JavaScript/Node
            'package.json' => ['check' => 'react', 'framework' => 'React'],
            'next.config.js' => ['exists' => true, 'framework' => 'Next.js'],
            'nuxt.config.js' => ['exists' => true, 'framework' => 'Nuxt.js'],
            'vue.config.js' => ['exists' => true, 'framework' => 'Vue.js'],
            'angular.json' => ['exists' => true, 'framework' => 'Angular'],
            'svelte.config.js' => ['exists' => true, 'framework' => 'Svelte'],
            
            // Python
            'requirements.txt' => ['check' => 'django', 'framework' => 'Django'],
            'manage.py' => ['exists' => true, 'framework' => 'Django'],
            'app.py' => ['check_content' => 'Flask', 'framework' => 'Flask'],
            'fastapi' => ['check' => 'fastapi', 'framework' => 'FastAPI'],
            
            // Ruby
            'Gemfile' => ['check' => 'rails', 'framework' => 'Ruby on Rails'],
            
            // Go
            'go.mod' => ['exists' => true, 'framework' => 'Go Modules'],
            
            // Rust
            'Cargo.toml' => ['exists' => true, 'framework' => 'Cargo (Rust)'],
        ];
        
        foreach ($frameworkIndicators as $file => $config) {
            $checkPath = "{$path}/{$file}";
            $exists = $this->fileExists($checkPath);
            
            if (isset($config['exists']) && $exists) {
                $frameworks[] = $config['framework'];
            } elseif (isset($config['check']) && $exists) {
                $content = $this->sandbox->readFile($this->containerName, $checkPath);
                if (stripos($content, $config['check']) !== false) {
                    $frameworks[] = $config['framework'];
                }
            }
        }
        
        arsort($languages);
        $this->analysis['languages'] = $languages;
        $this->analysis['frameworks'] = array_unique($frameworks);
        
        // Define linguagem principal
        if (!empty($languages)) {
            $this->analysis['primary_language'] = array_key_first($languages);
        }
    }
    
    /**
     * Analisa dependências do projeto
     */
    private function analyzeDependencies(string $path): void
    {
        $dependencies = [
            'production' => [],
            'development' => []
        ];
        
        // Composer (PHP)
        if ($this->fileExists("{$path}/composer.json")) {
            $content = $this->sandbox->readFile($this->containerName, "{$path}/composer.json");
            $composer = json_decode($content, true);
            
            if ($composer) {
                $dependencies['production'] = array_merge(
                    $dependencies['production'],
                    array_keys($composer['require'] ?? [])
                );
                $dependencies['development'] = array_merge(
                    $dependencies['development'],
                    array_keys($composer['require-dev'] ?? [])
                );
            }
        }
        
        // Package.json (Node)
        if ($this->fileExists("{$path}/package.json")) {
            $content = $this->sandbox->readFile($this->containerName, "{$path}/package.json");
            $package = json_decode($content, true);
            
            if ($package) {
                $dependencies['production'] = array_merge(
                    $dependencies['production'],
                    array_keys($package['dependencies'] ?? [])
                );
                $dependencies['development'] = array_merge(
                    $dependencies['development'],
                    array_keys($package['devDependencies'] ?? [])
                );
            }
        }
        
        // Requirements.txt (Python)
        if ($this->fileExists("{$path}/requirements.txt")) {
            $content = $this->sandbox->readFile($this->containerName, "{$path}/requirements.txt");
            $lines = array_filter(explode("\n", $content));
            
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && !str_starts_with($line, '#')) {
                    $dep = preg_replace('/[=<>!~].*/', '', $line);
                    $dependencies['production'][] = $dep;
                }
            }
        }
        
        // go.mod (Go)
        if ($this->fileExists("{$path}/go.mod")) {
            $content = $this->sandbox->readFile($this->containerName, "{$path}/go.mod");
            if (preg_match_all('/require\s+([^\s]+)/', $content, $matches)) {
                $dependencies['production'] = array_merge($dependencies['production'], $matches[1]);
            }
        }
        
        $this->analysis['dependencies'] = $dependencies;
    }
    
    /**
     * Identifica arquivos importantes
     */
    private function identifyImportantFiles(string $path): void
    {
        $important = [
            'config' => [],
            'entry_points' => [],
            'documentation' => [],
            'tests' => []
        ];
        
        // Arquivos de configuração comuns
        $configFiles = [
            '.env', '.env.example', 'config.php', 'config.js', 'config.json',
            'settings.py', 'application.yml', 'application.properties',
            'docker-compose.yml', 'Dockerfile', '.docker', 'Makefile',
            '.gitignore', '.editorconfig', '.prettierrc', '.eslintrc',
            'phpunit.xml', 'jest.config.js', 'pytest.ini', 'tsconfig.json'
        ];
        
        foreach ($configFiles as $file) {
            if ($this->fileExists("{$path}/{$file}")) {
                $important['config'][] = $file;
            }
        }
        
        // Entry points
        $entryPoints = [
            'index.php', 'index.js', 'index.ts', 'main.py', 'app.py',
            'main.go', 'main.rs', 'Program.cs', 'Main.java',
            'public/index.php', 'src/index.js', 'src/main.ts'
        ];
        
        foreach ($entryPoints as $file) {
            if ($this->fileExists("{$path}/{$file}")) {
                $important['entry_points'][] = $file;
            }
        }
        
        // Documentação
        $docs = ['README.md', 'README.txt', 'CHANGELOG.md', 'CONTRIBUTING.md', 'docs/', 'documentation/'];
        foreach ($docs as $doc) {
            if ($this->fileExists("{$path}/{$doc}")) {
                $important['documentation'][] = $doc;
            }
        }
        
        // Diretórios de teste
        $testDirs = ['tests/', 'test/', 'spec/', '__tests__/', 'Tests/'];
        foreach ($testDirs as $dir) {
            if ($this->fileExists("{$path}/{$dir}")) {
                $important['tests'][] = $dir;
            }
        }
        
        $this->analysis['important_files'] = $important;
        $this->analysis['config_files'] = $important['config'];
        $this->analysis['entry_points'] = $important['entry_points'];
        $this->analysis['documentation'] = $important['documentation'];
        $this->analysis['tests'] = $important['tests'];
    }
    
    /**
     * Detecta padrões arquiteturais
     */
    private function detectPatterns(): void
    {
        $patterns = [];
        $structure = $this->analysis['structure'];
        
        // MVC
        $mvcDirs = ['controllers', 'models', 'views', 'Controller', 'Model', 'View'];
        $mvcCount = 0;
        foreach ($mvcDirs as $dir) {
            if ($this->hasInStructure($structure, $dir)) {
                $mvcCount++;
            }
        }
        if ($mvcCount >= 2) {
            $patterns[] = 'MVC (Model-View-Controller)';
        }
        
        // Repository Pattern
        if ($this->hasInStructure($structure, 'repositories') || $this->hasInStructure($structure, 'Repository')) {
            $patterns[] = 'Repository Pattern';
        }
        
        // Service Layer
        if ($this->hasInStructure($structure, 'services') || $this->hasInStructure($structure, 'Services')) {
            $patterns[] = 'Service Layer';
        }
        
        // Domain Driven Design
        $dddDirs = ['domain', 'entities', 'valueobjects', 'aggregates'];
        $dddCount = 0;
        foreach ($dddDirs as $dir) {
            if ($this->hasInStructure($structure, $dir)) {
                $dddCount++;
            }
        }
        if ($dddCount >= 2) {
            $patterns[] = 'Domain Driven Design (DDD)';
        }
        
        // Clean Architecture
        if ($this->hasInStructure($structure, 'usecases') || $this->hasInStructure($structure, 'UseCases')) {
            $patterns[] = 'Clean Architecture';
        }
        
        // Hexagonal Architecture
        if ($this->hasInStructure($structure, 'ports') || $this->hasInStructure($structure, 'adapters')) {
            $patterns[] = 'Hexagonal Architecture';
        }
        
        // Microservices
        if ($this->hasInStructure($structure, 'services') && $this->fileExists('/workspace/docker-compose.yml')) {
            $patterns[] = 'Microservices (possível)';
        }
        
        // Monorepo
        if ($this->hasInStructure($structure, 'packages') || $this->hasInStructure($structure, 'apps')) {
            $patterns[] = 'Monorepo';
        }
        
        // API-first
        if ($this->hasInStructure($structure, 'api') || $this->hasInStructure($structure, 'routes')) {
            $patterns[] = 'API-first Design';
        }
        
        $this->analysis['patterns'] = $patterns;
    }
    
    /**
     * Analisa convenções de código
     */
    private function analyzeConventions(string $path): void
    {
        $conventions = [];
        $language = $this->analysis['primary_language'] ?? 'unknown';
        
        // Detecta padrão de nomenclatura
        $result = $this->sandbox->execute(
            $this->containerName,
            "find {$path} -type f -name '*.php' -o -name '*.js' -o -name '*.py' 2>/dev/null | head -10"
        );
        
        if ($result['success']) {
            $files = array_filter(explode("\n", $result['output']));
            $camelCase = 0;
            $snakeCase = 0;
            $pascalCase = 0;
            
            foreach ($files as $file) {
                $basename = pathinfo($file, PATHINFO_FILENAME);
                
                if (preg_match('/^[a-z][a-zA-Z0-9]*$/', $basename)) {
                    $camelCase++;
                }
                if (preg_match('/^[a-z][a-z0-9_]*$/', $basename)) {
                    $snakeCase++;
                }
                if (preg_match('/^[A-Z][a-zA-Z0-9]*$/', $basename)) {
                    $pascalCase++;
                }
            }
            
            if ($pascalCase > $camelCase && $pascalCase > $snakeCase) {
                $conventions['file_naming'] = 'PascalCase';
            } elseif ($camelCase > $snakeCase) {
                $conventions['file_naming'] = 'camelCase';
            } else {
                $conventions['file_naming'] = 'snake_case';
            }
        }
        
        // Detecta indentação
        if (!empty($files)) {
            $sampleFile = $files[0] ?? null;
            if ($sampleFile) {
                $content = $this->sandbox->readFile($this->containerName, $sampleFile);
                if (preg_match('/^(\t+|\s{2}|\s{4})/m', $content, $matches)) {
                    if ($matches[1] === "\t") {
                        $conventions['indentation'] = 'tabs';
                    } elseif (strlen($matches[1]) === 2) {
                        $conventions['indentation'] = '2 spaces';
                    } else {
                        $conventions['indentation'] = '4 spaces';
                    }
                }
            }
        }
        
        // Detecta uso de TypeScript
        if ($this->fileExists("{$path}/tsconfig.json")) {
            $conventions['typescript'] = true;
        }
        
        // Detecta uso de ESLint/Prettier
        if ($this->fileExists("{$path}/.eslintrc.js") || $this->fileExists("{$path}/.eslintrc.json")) {
            $conventions['linter'] = 'ESLint';
        }
        if ($this->fileExists("{$path}/.prettierrc") || $this->fileExists("{$path}/.prettierrc.json")) {
            $conventions['formatter'] = 'Prettier';
        }
        
        // PHP específico
        if ($language === 'php') {
            if ($this->fileExists("{$path}/phpcs.xml") || $this->fileExists("{$path}/phpcs.xml.dist")) {
                $conventions['linter'] = 'PHP_CodeSniffer';
            }
            if ($this->fileExists("{$path}/phpstan.neon") || $this->fileExists("{$path}/phpstan.neon.dist")) {
                $conventions['static_analysis'] = 'PHPStan';
            }
        }
        
        $this->analysis['conventions'] = $conventions;
    }
    
    /**
     * Gera estatísticas do projeto
     */
    private function generateStatistics(string $path): void
    {
        $stats = [];
        
        // Total de arquivos
        $result = $this->sandbox->execute(
            $this->containerName,
            "find {$path} -type f ! -path '*/vendor/*' ! -path '*/node_modules/*' ! -path '*/.git/*' | wc -l"
        );
        $stats['total_files'] = $result['success'] ? (int)trim($result['output']) : 0;
        
        // Total de diretórios
        $result = $this->sandbox->execute(
            $this->containerName,
            "find {$path} -type d ! -path '*/vendor/*' ! -path '*/node_modules/*' ! -path '*/.git/*' | wc -l"
        );
        $stats['total_directories'] = $result['success'] ? (int)trim($result['output']) : 0;
        
        // Linhas de código (aproximado)
        $language = $this->analysis['primary_language'] ?? 'php';
        $ext = array_key_first($this->languageExtensions[$language] ?? ['php']);
        
        $result = $this->sandbox->execute(
            $this->containerName,
            "find {$path} -name '*.{$ext}' ! -path '*/vendor/*' ! -path '*/node_modules/*' -exec cat {} \\; 2>/dev/null | wc -l"
        );
        $stats['lines_of_code'] = $result['success'] ? (int)trim($result['output']) : 0;
        
        // Tamanho total
        $result = $this->sandbox->execute(
            $this->containerName,
            "du -sh {$path} 2>/dev/null | cut -f1"
        );
        $stats['total_size'] = $result['success'] ? trim($result['output']) : 'N/A';
        
        $this->analysis['statistics'] = $stats;
    }
    
    /**
     * Gera resumo executivo da análise
     */
    private function generateSummary(): void
    {
        $parts = [];
        
        // Linguagem principal
        $lang = $this->analysis['primary_language'] ?? 'não identificada';
        $parts[] = "Linguagem principal: {$lang}";
        
        // Frameworks
        if (!empty($this->analysis['frameworks'])) {
            $frameworks = implode(', ', $this->analysis['frameworks']);
            $parts[] = "Frameworks: {$frameworks}";
        }
        
        // Padrões
        if (!empty($this->analysis['patterns'])) {
            $patterns = implode(', ', $this->analysis['patterns']);
            $parts[] = "Padrões arquiteturais: {$patterns}";
        }
        
        // Estatísticas
        $stats = $this->analysis['statistics'];
        if (!empty($stats)) {
            $parts[] = "Estatísticas: {$stats['total_files']} arquivos, {$stats['lines_of_code']} linhas de código";
        }
        
        // Dependências
        $depsCount = count($this->analysis['dependencies']['production'] ?? []);
        if ($depsCount > 0) {
            $parts[] = "Dependências: {$depsCount} pacotes em produção";
        }
        
        // Convenções
        if (!empty($this->analysis['conventions'])) {
            $conv = $this->analysis['conventions'];
            $convParts = [];
            if (isset($conv['file_naming'])) $convParts[] = "nomenclatura {$conv['file_naming']}";
            if (isset($conv['indentation'])) $convParts[] = "indentação {$conv['indentation']}";
            if (!empty($convParts)) {
                $parts[] = "Convenções: " . implode(', ', $convParts);
            }
        }
        
        $this->analysis['summary'] = implode("\n", $parts);
    }
    
    /**
     * Verifica se arquivo/diretório existe
     */
    private function fileExists(string $path): bool
    {
        $result = $this->sandbox->execute(
            $this->containerName,
            "test -e '{$path}' && echo 'exists'"
        );
        return $result['success'] && str_contains($result['output'], 'exists');
    }
    
    /**
     * Verifica se deve ignorar caminho
     */
    private function shouldIgnore(string $path): bool
    {
        $ignorePaths = [
            'vendor/', 'node_modules/', '.git/', '.idea/', '.vscode/',
            '__pycache__/', '.pytest_cache/', 'dist/', 'build/', 'coverage/',
            '.next/', '.nuxt/', '.cache/'
        ];
        
        foreach ($ignorePaths as $ignore) {
            if (str_contains($path, $ignore)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verifica se diretório existe na estrutura
     */
    private function hasInStructure(array $structure, string $name): bool
    {
        $nameLower = strtolower($name);
        
        foreach ($structure as $key => $value) {
            if (strtolower($key) === $nameLower) {
                return true;
            }
            if (is_array($value) && $this->hasInStructure($value, $name)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Retorna análise como contexto para o Claude
     */
    public function toContext(): string
    {
        if (empty($this->analysis)) {
            return "Projeto vazio ou não analisado.";
        }
        
        $context = "## Análise do Projeto\n\n";
        $context .= $this->analysis['summary'] . "\n\n";
        
        // Estrutura resumida
        $context .= "### Estrutura Principal\n";
        $topLevel = array_keys($this->analysis['structure']);
        $context .= implode(', ', array_slice($topLevel, 0, 15)) . "\n\n";
        
        // Arquivos importantes
        if (!empty($this->analysis['entry_points'])) {
            $context .= "### Entry Points\n";
            $context .= implode(', ', $this->analysis['entry_points']) . "\n\n";
        }
        
        // Convenções a seguir
        if (!empty($this->analysis['conventions'])) {
            $context .= "### Convenções do Projeto\n";
            foreach ($this->analysis['conventions'] as $key => $value) {
                $context .= "- {$key}: {$value}\n";
            }
            $context .= "\n";
        }
        
        return $context;
    }
    
    /**
     * Retorna análise completa
     */
    public function getAnalysis(): array
    {
        return $this->analysis;
    }
}
