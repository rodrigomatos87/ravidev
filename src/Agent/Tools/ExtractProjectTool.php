<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

/**
 * Ferramenta de Extração de Projeto
 * 
 * Extrai projetos enviados pelo usuário em formatos:
 * - ZIP
 * - TAR
 * - TAR.GZ / TGZ
 * - RAR (se unrar instalado)
 * 
 * Após extração, analisa a estrutura do projeto
 */
class ExtractProjectTool extends BaseTool
{
    public function getName(): string
    {
        return 'extract_project';
    }
    
    public function getDescription(): string
    {
        return 'Extrai projeto compactado (zip, tar, tar.gz) enviado pelo usuário para o workspace';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_path' => [
                    'type' => 'string',
                    'description' => 'Caminho do arquivo compactado'
                ],
                'destination' => [
                    'type' => 'string',
                    'description' => 'Diretório de destino (padrão: /workspace)',
                    'default' => '/workspace'
                ],
                'analyze_after' => [
                    'type' => 'boolean',
                    'description' => 'Analisar projeto após extração',
                    'default' => true
                ]
            ],
            'required' => ['source_path']
        ];
    }
    
    public function execute(array $input): string
    {
        $sourcePath = $input['source_path'];
        $destination = $input['destination'] ?? '/workspace';
        $analyzeAfter = $input['analyze_after'] ?? true;
        
        // Verifica se arquivo existe
        $checkResult = $this->sandbox->execute(
            $this->containerName,
            "test -f '{$sourcePath}' && echo 'exists'"
        );
        
        if (!str_contains($checkResult['output'] ?? '', 'exists')) {
            return "❌ Arquivo não encontrado: {$sourcePath}";
        }
        
        // Detecta tipo de arquivo
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $filename = basename($sourcePath);
        
        // Comando de extração baseado na extensão
        $extractCmd = match($ext) {
            'zip' => "unzip -o '{$sourcePath}' -d '{$destination}'",
            'tar' => "tar -xf '{$sourcePath}' -C '{$destination}'",
            'gz', 'tgz' => "tar -xzf '{$sourcePath}' -C '{$destination}'",
            'bz2' => "tar -xjf '{$sourcePath}' -C '{$destination}'",
            'xz' => "tar -xJf '{$sourcePath}' -C '{$destination}'",
            'rar' => "unrar x '{$sourcePath}' '{$destination}/'",
            '7z' => "7z x '{$sourcePath}' -o'{$destination}'",
            default => null
        };
        
        if (!$extractCmd) {
            return "❌ Formato não suportado: .{$ext}\n\nFormatos suportados: zip, tar, tar.gz, tgz, bz2, xz, rar, 7z";
        }
        
        // Cria diretório de destino
        $this->sandbox->execute($this->containerName, "mkdir -p '{$destination}'");
        
        // Executa extração
        $extractResult = $this->sandbox->execute(
            $this->containerName,
            $extractCmd,
            '/workspace',
            120 // timeout de 2 minutos para arquivos grandes
        );
        
        if (!$extractResult['success']) {
            return "❌ Falha ao extrair: " . ($extractResult['error'] ?? 'Erro desconhecido');
        }
        
        // Conta arquivos extraídos
        $countResult = $this->sandbox->execute(
            $this->containerName,
            "find '{$destination}' -type f | wc -l"
        );
        $fileCount = (int)trim($countResult['output'] ?? '0');
        
        // Conta diretórios
        $dirResult = $this->sandbox->execute(
            $this->containerName,
            "find '{$destination}' -type d | wc -l"
        );
        $dirCount = max(0, (int)trim($dirResult['output'] ?? '1') - 1);
        
        // Calcula tamanho total
        $sizeResult = $this->sandbox->execute(
            $this->containerName,
            "du -sh '{$destination}' | cut -f1"
        );
        $totalSize = trim($sizeResult['output'] ?? 'N/A');
        
        $output = "✅ **Projeto Extraído com Sucesso!**\n\n";
        $output .= "📁 **Origem:** {$filename}\n";
        $output .= "📂 **Destino:** {$destination}\n\n";
        $output .= "### Estatísticas\n\n";
        $output .= "- **Arquivos:** {$fileCount}\n";
        $output .= "- **Diretórios:** {$dirCount}\n";
        $output .= "- **Tamanho total:** {$totalSize}\n\n";
        
        // Análise do projeto
        if ($analyzeAfter) {
            $analysis = $this->analyzeExtractedProject($destination);
            $output .= "### Análise do Projeto\n\n";
            $output .= $analysis;
        }
        
        // Lista estrutura principal
        $output .= "\n### Estrutura Principal\n\n";
        $output .= "```\n";
        $output .= $this->getTreeStructure($destination);
        $output .= "```\n";
        
        return $output;
    }
    
    /**
     * Analisa projeto extraído
     */
    private function analyzeExtractedProject(string $path): string
    {
        $analysis = [];
        
        // Detecta linguagem principal
        $langCheck = [
            'php' => "find '{$path}' -name '*.php' | wc -l",
            'javascript' => "find '{$path}' -name '*.js' -o -name '*.jsx' | wc -l",
            'typescript' => "find '{$path}' -name '*.ts' -o -name '*.tsx' | wc -l",
            'python' => "find '{$path}' -name '*.py' | wc -l",
            'go' => "find '{$path}' -name '*.go' | wc -l",
            'rust' => "find '{$path}' -name '*.rs' | wc -l",
            'java' => "find '{$path}' -name '*.java' | wc -l",
            'csharp' => "find '{$path}' -name '*.cs' | wc -l"
        ];
        
        $langCounts = [];
        foreach ($langCheck as $lang => $cmd) {
            $result = $this->sandbox->execute($this->containerName, $cmd);
            $count = (int)trim($result['output'] ?? '0');
            if ($count > 0) {
                $langCounts[$lang] = $count;
            }
        }
        
        if (!empty($langCounts)) {
            arsort($langCounts);
            $primaryLang = array_key_first($langCounts);
            $analysis[] = "- **Linguagem principal:** " . ucfirst($primaryLang) . " ({$langCounts[$primaryLang]} arquivos)";
        }
        
        // Detecta frameworks
        $frameworks = [];
        
        // PHP
        if ($this->fileExists("{$path}/composer.json")) {
            $composerContent = $this->sandbox->readFile($this->containerName, "{$path}/composer.json");
            if (str_contains($composerContent, 'laravel')) {
                $frameworks[] = 'Laravel';
            } elseif (str_contains($composerContent, 'symfony')) {
                $frameworks[] = 'Symfony';
            }
        }
        
        // JavaScript/Node
        if ($this->fileExists("{$path}/package.json")) {
            $packageContent = $this->sandbox->readFile($this->containerName, "{$path}/package.json");
            if (str_contains($packageContent, '"react"')) {
                $frameworks[] = 'React';
            }
            if (str_contains($packageContent, '"vue"')) {
                $frameworks[] = 'Vue.js';
            }
            if (str_contains($packageContent, '"next"')) {
                $frameworks[] = 'Next.js';
            }
            if (str_contains($packageContent, '"angular"')) {
                $frameworks[] = 'Angular';
            }
            if (str_contains($packageContent, '"express"')) {
                $frameworks[] = 'Express.js';
            }
        }
        
        // Python
        if ($this->fileExists("{$path}/requirements.txt")) {
            $reqContent = $this->sandbox->readFile($this->containerName, "{$path}/requirements.txt");
            if (str_contains($reqContent, 'django')) {
                $frameworks[] = 'Django';
            } elseif (str_contains($reqContent, 'flask')) {
                $frameworks[] = 'Flask';
            } elseif (str_contains($reqContent, 'fastapi')) {
                $frameworks[] = 'FastAPI';
            }
        }
        
        if (!empty($frameworks)) {
            $analysis[] = "- **Frameworks:** " . implode(', ', $frameworks);
        }
        
        // Detecta configurações
        $configs = [];
        if ($this->fileExists("{$path}/.env") || $this->fileExists("{$path}/.env.example")) {
            $configs[] = 'Variáveis de ambiente (.env)';
        }
        if ($this->fileExists("{$path}/docker-compose.yml") || $this->fileExists("{$path}/Dockerfile")) {
            $configs[] = 'Docker';
        }
        if ($this->fileExists("{$path}/.github")) {
            $configs[] = 'GitHub Actions';
        }
        
        if (!empty($configs)) {
            $analysis[] = "- **Configurações:** " . implode(', ', $configs);
        }
        
        // Detecta testes
        $testDirs = ['tests', 'test', 'spec', '__tests__'];
        foreach ($testDirs as $testDir) {
            if ($this->fileExists("{$path}/{$testDir}")) {
                $analysis[] = "- **Testes:** Diretório `{$testDir}` encontrado";
                break;
            }
        }
        
        return empty($analysis) 
            ? "Projeto simples sem framework específico detectado.\n" 
            : implode("\n", $analysis) . "\n";
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
        return str_contains($result['output'] ?? '', 'exists');
    }
    
    /**
     * Obtém estrutura de árvore limitada
     */
    private function getTreeStructure(string $path): string
    {
        // Tenta usar tree se disponível
        $treeResult = $this->sandbox->execute(
            $this->containerName,
            "cd '{$path}' && tree -L 2 --noreport 2>/dev/null || find . -maxdepth 2 -type f | head -30 | sort"
        );
        
        return $treeResult['output'] ?? '.';
    }
}
