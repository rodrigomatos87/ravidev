<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

/**
 * Ferramenta de Preparação de Entrega
 * 
 * Prepara o pacote final de entrega para o usuário:
 * - ZIP com todo o projeto
 * - README.md com documentação completa
 * - INSTALL.md com guia de instalação
 * - QUICKSTART.md com guia rápido
 * - Lista de todos os arquivos criados
 * 
 * Similar à entrega do Manus AI
 */
class PrepareDeliveryTool extends BaseTool
{
    public function getName(): string
    {
        return 'prepare_delivery';
    }
    
    public function getDescription(): string
    {
        return 'Prepara o pacote final de entrega com ZIP do projeto, documentação e guias de instalação';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_name' => [
                    'type' => 'string',
                    'description' => 'Nome do projeto para o arquivo ZIP'
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Descrição breve do projeto'
                ],
                'features' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Lista de funcionalidades implementadas'
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Linguagem principal (php, python, javascript, etc)',
                    'default' => 'php'
                ],
                'framework' => [
                    'type' => 'string',
                    'description' => 'Framework utilizado (se houver)'
                ],
                'requirements' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Requisitos de sistema'
                ],
                'install_commands' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Comandos de instalação'
                ],
                'run_commands' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Comandos para executar o projeto'
                ],
                'env_variables' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'Variáveis de ambiente necessárias'
                ],
                'additional_notes' => [
                    'type' => 'string',
                    'description' => 'Notas adicionais para o usuário'
                ]
            ],
            'required' => ['project_name', 'description']
        ];
    }
    
    public function execute(array $input): string
    {
        $projectName = $input['project_name'];
        $projectSlug = $this->slugify($projectName);
        $timestamp = date('Ymd-His');
        $description = $input['description'];
        $features = $input['features'] ?? [];
        $language = $input['language'] ?? 'php';
        $framework = $input['framework'] ?? '';
        $requirements = $input['requirements'] ?? [];
        $installCommands = $input['install_commands'] ?? [];
        $runCommands = $input['run_commands'] ?? [];
        $envVariables = $input['env_variables'] ?? [];
        $additionalNotes = $input['additional_notes'] ?? '';
        
        $deliveryFiles = [];
        
        // 1. Gera README.md completo
        $readme = $this->generateReadme(
            $projectName, 
            $description, 
            $features, 
            $language, 
            $framework
        );
        $this->writeFile('/workspace/README.md', $readme);
        $deliveryFiles[] = 'README.md';
        
        // 2. Gera INSTALL.md com tutorial detalhado
        $install = $this->generateInstallGuide(
            $projectName,
            $language,
            $framework,
            $requirements,
            $installCommands,
            $runCommands,
            $envVariables
        );
        $this->writeFile('/workspace/INSTALL.md', $install);
        $deliveryFiles[] = 'INSTALL.md';
        
        // 3. Gera QUICKSTART.md (guia rápido)
        $quickstart = $this->generateQuickstart(
            $projectName,
            $language,
            $installCommands,
            $runCommands
        );
        $this->writeFile('/workspace/QUICKSTART.md', $quickstart);
        $deliveryFiles[] = 'QUICKSTART.md';
        
        // 4. Gera .env.example se houver variáveis
        if (!empty($envVariables)) {
            $envExample = $this->generateEnvExample($envVariables);
            $this->writeFile('/workspace/.env.example', $envExample);
            $deliveryFiles[] = '.env.example';
        }
        
        // 5. Gera CHANGELOG.md
        $changelog = $this->generateChangelog($projectName, $features);
        $this->writeFile('/workspace/CHANGELOG.md', $changelog);
        $deliveryFiles[] = 'CHANGELOG.md';
        
        // 6. Gera LICENSE (MIT)
        $license = $this->generateLicense();
        $this->writeFile('/workspace/LICENSE', $license);
        $deliveryFiles[] = 'LICENSE';
        
        // 7. Gera CLAUDE.md (CRÍTICO - documentação para IA)
        $claudeMd = $this->generateClaudeMd(
            $projectName,
            $description,
            $features,
            $language,
            $framework,
            $requirements,
            $installCommands,
            $runCommands,
            $envVariables
        );
        $this->writeFile('/workspace/CLAUDE.md', $claudeMd);
        $deliveryFiles[] = 'CLAUDE.md';
        
        // 8. Gera arquivos do GitHub
        $this->generateGitHubFiles($projectName, $description, $language);
        $deliveryFiles[] = '.gitignore';
        $deliveryFiles[] = '.github/';
        
        // 9. Lista todos os arquivos do projeto
        $allFiles = $this->listAllFiles('/workspace');
        
        // 10. Cria arquivo de manifesto
        $manifest = $this->generateManifest($projectName, $description, $allFiles, $deliveryFiles);
        $this->writeFile('/workspace/MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $deliveryFiles[] = 'MANIFEST.json';
        
        // 11. Cria o ZIP final
        $zipName = "{$projectSlug}-{$timestamp}.zip";
        $zipPath = "/workspace/{$zipName}";
        
        $this->sandbox->execute(
            $this->containerName,
            "cd /workspace && zip -r '{$zipName}' . -x '*.zip' -x '__MACOSX/*' -x '.DS_Store' -x '*.git/*'"
        );
        
        // 12. Move para outputs
        $this->sandbox->execute(
            $this->containerName,
            "mkdir -p /outputs && cp '{$zipPath}' /outputs/{$zipName}"
        );
        
        // 13. Calcula tamanho do ZIP
        $sizeResult = $this->sandbox->execute(
            $this->containerName,
            "du -h '{$zipPath}' | cut -f1"
        );
        $zipSize = trim($sizeResult['output'] ?? 'N/A');
        
        // 14. Conta arquivos
        $fileCount = count($allFiles);
        
        // Retorno formatado similar ao Manus
        $output = "✅ **Projeto Entregue com Sucesso!**\n\n";
        $output .= "## 📦 {$projectName}\n\n";
        $output .= "{$description}\n\n";
        $output .= "---\n\n";
        
        $output .= "### 📁 Arquivos de Entrega\n\n";
        $output .= "| Arquivo | Descrição |\n";
        $output .= "|---------|----------|\n";
        $output .= "| **{$zipName}** | 📦 Projeto completo ({$zipSize}) |\n";
        $output .= "| CLAUDE.md | 🤖 **Documentação para IA** (leia primeiro!) |\n";
        $output .= "| README.md | 📖 Documentação principal |\n";
        $output .= "| INSTALL.md | 🔧 Guia de instalação detalhado |\n";
        $output .= "| QUICKSTART.md | ⚡ Guia rápido (3 passos) |\n";
        $output .= "| CHANGELOG.md | 📋 Histórico de mudanças |\n";
        $output .= "| .gitignore | 🚫 Arquivos ignorados pelo Git |\n";
        $output .= "| .github/ | 🐙 CI/CD e templates GitHub |\n";
        $output .= "\n";
        
        $output .= "### ✨ Funcionalidades\n\n";
        foreach ($features as $feature) {
            $output .= "- {$feature}\n";
        }
        $output .= "\n";
        
        $output .= "### 📊 Estatísticas\n\n";
        $output .= "- **Total de arquivos:** {$fileCount}\n";
        $output .= "- **Linguagem:** {$language}\n";
        if ($framework) {
            $output .= "- **Framework:** {$framework}\n";
        }
        $output .= "- **Tamanho do pacote:** {$zipSize}\n";
        $output .= "\n";
        
        $output .= "### 🚀 Próximos Passos\n\n";
        $output .= "1. Faça download do arquivo **{$zipName}**\n";
        $output .= "2. Extraia em seu servidor\n";
        $output .= "3. Siga as instruções em **INSTALL.md**\n";
        $output .= "4. Configure as variáveis de ambiente\n";
        $output .= "5. Execute o projeto!\n";
        $output .= "\n";
        $output .= "### 🤖 Para Modificações Futuras\n\n";
        $output .= "Envie o ZIP para o Claude e peça para ler o **CLAUDE.md** primeiro.\n";
        $output .= "Ele conterá toda a documentação necessária para entender o projeto.\n";
        
        if ($additionalNotes) {
            $output .= "\n### 📝 Notas\n\n{$additionalNotes}\n";
        }
        
        $output .= "\n---\n";
        $output .= "*Desenvolvido por Ravi Developer - Powered by Claude AI*\n";
        
        return $output;
    }
    
    /**
     * Gera README.md completo
     */
    private function generateReadme(
        string $projectName,
        string $description,
        array $features,
        string $language,
        string $framework
    ): string {
        $md = "# {$projectName}\n\n";
        $md .= "![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)\n";
        $md .= "![Language](https://img.shields.io/badge/language-{$language}-green.svg)\n";
        if ($framework) {
            $md .= "![Framework](https://img.shields.io/badge/framework-{$framework}-purple.svg)\n";
        }
        $md .= "\n";
        
        $md .= "{$description}\n\n";
        
        $md .= "## ✨ Funcionalidades\n\n";
        foreach ($features as $feature) {
            $md .= "- ✅ {$feature}\n";
        }
        $md .= "\n";
        
        $md .= "## 🚀 Instalação Rápida\n\n";
        $md .= "```bash\n";
        $md .= "# Clone ou extraia o projeto\n";
        $md .= "unzip {$this->slugify($projectName)}.zip\n";
        $md .= "cd {$this->slugify($projectName)}\n\n";
        $md .= "# Siga as instruções em INSTALL.md\n";
        $md .= "```\n\n";
        
        $md .= "## 📖 Documentação\n\n";
        $md .= "- [Guia de Instalação](INSTALL.md) - Instruções detalhadas de instalação\n";
        $md .= "- [Guia Rápido](QUICKSTART.md) - Começar rapidamente\n";
        $md .= "- [Changelog](CHANGELOG.md) - Histórico de mudanças\n\n";
        
        $md .= "## 🏗️ Estrutura do Projeto\n\n";
        $md .= "```\n";
        $md .= $this->generateTreeStructure('/workspace');
        $md .= "```\n\n";
        
        $md .= "## 🛠️ Tecnologias\n\n";
        $md .= "- **Linguagem:** " . ucfirst($language) . "\n";
        if ($framework) {
            $md .= "- **Framework:** {$framework}\n";
        }
        $md .= "\n";
        
        $md .= "## 📄 Licença\n\n";
        $md .= "Este projeto está sob a licença MIT. Veja o arquivo [LICENSE](LICENSE) para mais detalhes.\n\n";
        
        $md .= "---\n\n";
        $md .= "*Desenvolvido autonomamente por [" . (string) config('app.name', 'Ravi Developer') . "]("
             . rtrim((string) config('app.url', 'http://localhost'), '/') . ") - Powered by Claude AI*\n";
        
        return $md;
    }
    
    /**
     * Gera guia de instalação detalhado
     */
    private function generateInstallGuide(
        string $projectName,
        string $language,
        string $framework,
        array $requirements,
        array $installCommands,
        array $runCommands,
        array $envVariables
    ): string {
        $md = "# 📦 Guia de Instalação - {$projectName}\n\n";
        $md .= "Este guia fornece instruções passo-a-passo para instalar e configurar o projeto.\n\n";
        
        // Requisitos
        $md .= "## 📋 Requisitos do Sistema\n\n";
        
        if (!empty($requirements)) {
            foreach ($requirements as $req) {
                $md .= "- {$req}\n";
            }
        } else {
            // Requisitos padrão baseados na linguagem
            $md .= $this->getDefaultRequirements($language, $framework);
        }
        $md .= "\n";
        
        // Instalação
        $md .= "## 🚀 Instalação\n\n";
        
        $md .= "### Passo 1: Extrair Arquivos\n\n";
        $md .= "```bash\n";
        $md .= "# Extraia o arquivo ZIP\n";
        $md .= "unzip {$this->slugify($projectName)}.zip -d /caminho/destino\n";
        $md .= "cd /caminho/destino\n";
        $md .= "```\n\n";
        
        $md .= "### Passo 2: Instalar Dependências\n\n";
        $md .= "```bash\n";
        if (!empty($installCommands)) {
            foreach ($installCommands as $cmd) {
                $md .= "{$cmd}\n";
            }
        } else {
            $md .= $this->getDefaultInstallCommands($language);
        }
        $md .= "```\n\n";
        
        // Configuração de ambiente
        if (!empty($envVariables)) {
            $md .= "### Passo 3: Configurar Ambiente\n\n";
            $md .= "```bash\n";
            $md .= "# Copie o arquivo de exemplo\n";
            $md .= "cp .env.example .env\n\n";
            $md .= "# Edite com suas configurações\n";
            $md .= "nano .env  # ou use seu editor preferido\n";
            $md .= "```\n\n";
            
            $md .= "**Variáveis de Ambiente:**\n\n";
            $md .= "| Variável | Descrição | Obrigatória |\n";
            $md .= "|----------|-----------|-------------|\n";
            foreach ($envVariables as $env) {
                $name = $env['name'] ?? 'N/A';
                $desc = $env['description'] ?? '-';
                $required = ($env['required'] ?? false) ? 'Sim' : 'Não';
                $md .= "| `{$name}` | {$desc} | {$required} |\n";
            }
            $md .= "\n";
        }
        
        // Execução
        $md .= "### Passo " . (empty($envVariables) ? '3' : '4') . ": Executar\n\n";
        $md .= "```bash\n";
        if (!empty($runCommands)) {
            foreach ($runCommands as $cmd) {
                $md .= "{$cmd}\n";
            }
        } else {
            $md .= $this->getDefaultRunCommands($language, $framework);
        }
        $md .= "```\n\n";
        
        // Verificação
        $md .= "## ✅ Verificação\n\n";
        $md .= "Após a instalação, verifique se tudo está funcionando:\n\n";
        $md .= "1. Acesse a aplicação no navegador (se aplicável)\n";
        $md .= "2. Verifique os logs em busca de erros\n";
        $md .= "3. Execute os testes (se disponíveis)\n\n";
        
        // Troubleshooting
        $md .= "## 🔧 Resolução de Problemas\n\n";
        $md .= "### Problemas Comuns\n\n";
        $md .= "| Problema | Solução |\n";
        $md .= "|----------|--------|\n";
        $md .= "| Permissão negada | Execute `chmod -R 755 .` |\n";
        $md .= "| Dependência não encontrada | Verifique os requisitos |\n";
        $md .= "| Porta em uso | Altere a porta nas configurações |\n";
        $md .= "| Erro de conexão BD | Verifique credenciais no .env |\n\n";
        
        $md .= "## 🆘 Suporte\n\n";
        $md .= "Se encontrar problemas, verifique:\n";
        $md .= "1. Se todos os requisitos estão instalados\n";
        $md .= "2. Se as configurações do .env estão corretas\n";
        $md .= "3. Os logs de erro do sistema\n\n";
        
        $md .= "---\n\n";
        $md .= "*Gerado por Ravi Developer*\n";
        
        return $md;
    }
    
    /**
     * Gera guia rápido
     */
    private function generateQuickstart(
        string $projectName,
        string $language,
        array $installCommands,
        array $runCommands
    ): string {
        $md = "# ⚡ Guia Rápido - {$projectName}\n\n";
        $md .= "Comece a usar em 3 passos:\n\n";
        
        $md .= "## 1️⃣ Extrair\n\n";
        $md .= "```bash\n";
        $md .= "unzip {$this->slugify($projectName)}.zip && cd {$this->slugify($projectName)}\n";
        $md .= "```\n\n";
        
        $md .= "## 2️⃣ Instalar\n\n";
        $md .= "```bash\n";
        if (!empty($installCommands)) {
            $md .= implode("\n", array_slice($installCommands, 0, 3)) . "\n";
        } else {
            $md .= $this->getDefaultInstallCommands($language);
        }
        $md .= "```\n\n";
        
        $md .= "## 3️⃣ Executar\n\n";
        $md .= "```bash\n";
        if (!empty($runCommands)) {
            $md .= $runCommands[0] . "\n";
        } else {
            $md .= $this->getDefaultRunCommands($language, '');
        }
        $md .= "```\n\n";
        
        $md .= "---\n\n";
        $md .= "📖 Para instruções detalhadas, consulte [INSTALL.md](INSTALL.md)\n";
        
        return $md;
    }
    
    /**
     * Gera .env.example
     */
    private function generateEnvExample(array $envVariables): string
    {
        $content = "# Configuração de Ambiente\n";
        $content .= "# Copie este arquivo para .env e configure os valores\n\n";
        
        foreach ($envVariables as $env) {
            $name = $env['name'] ?? 'VAR';
            $default = $env['default'] ?? '';
            $description = $env['description'] ?? '';
            
            if ($description) {
                $content .= "# {$description}\n";
            }
            $content .= "{$name}={$default}\n\n";
        }
        
        return $content;
    }
    
    /**
     * Gera changelog
     */
    private function generateChangelog(string $projectName, array $features): string
    {
        $md = "# Changelog\n\n";
        $md .= "Todas as mudanças notáveis neste projeto serão documentadas aqui.\n\n";
        
        $md .= "## [1.0.0] - " . date('Y-m-d') . "\n\n";
        $md .= "### Adicionado\n\n";
        
        foreach ($features as $feature) {
            $md .= "- {$feature}\n";
        }
        
        $md .= "\n### Notas\n\n";
        $md .= "- Versão inicial do projeto\n";
        $md .= "- Desenvolvido autonomamente por Ravi Developer\n";
        
        return $md;
    }
    
    /**
     * Gera licença MIT
     */
    private function generateLicense(): string
    {
        $year = date('Y');
        
        return <<<LICENSE
MIT License

Copyright (c) {$year}

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
LICENSE;
    }
    
    /**
     * Gera manifesto do projeto
     */
    private function generateManifest(
        string $projectName,
        string $description,
        array $files,
        array $deliveryFiles
    ): array {
        return [
            'name' => $projectName,
            'description' => $description,
            'version' => '1.0.0',
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => 'Ravi Developer',
            'powered_by' => 'Claude AI (Anthropic)',
            'files_count' => count($files),
            'delivery_files' => $deliveryFiles,
            'all_files' => $files
        ];
    }
    
    /**
     * Lista todos os arquivos
     */
    private function listAllFiles(string $path): array
    {
        $result = $this->sandbox->execute(
            $this->containerName,
            "find {$path} -type f ! -path '*.git/*' ! -name '*.zip' 2>/dev/null | sort"
        );
        
        if (!$result['success']) {
            return [];
        }
        
        $files = array_filter(explode("\n", $result['output']));
        
        return array_map(fn($f) => str_replace("{$path}/", '', $f), $files);
    }
    
    /**
     * Gera estrutura de árvore
     */
    private function generateTreeStructure(string $path): string
    {
        $result = $this->sandbox->execute(
            $this->containerName,
            "cd {$path} && find . -type f ! -path '*.git/*' ! -name '*.zip' | head -30 | sort"
        );
        
        if (!$result['success']) {
            return ".\n";
        }
        
        return $result['output'];
    }
    
    /**
     * Converte para slug
     */
    private function slugify(string $text): string
    {
        return preg_replace('/[^a-z0-9]+/', '-', strtolower($text));
    }
    
    /**
     * Escreve arquivo
     */
    private function writeFile(string $path, string $content): void
    {
        $this->sandbox->writeFile($this->containerName, $path, $content);
    }
    
    /**
     * Requisitos padrão por linguagem
     */
    private function getDefaultRequirements(string $language, string $framework): string
    {
        return match($language) {
            'php' => "- PHP 8.1 ou superior\n- Composer\n- Extensões: mbstring, json, pdo\n" .
                     ($framework === 'laravel' ? "- MySQL/PostgreSQL/SQLite\n- Redis (opcional)\n" : ""),
            'python' => "- Python 3.9 ou superior\n- pip\n- virtualenv (recomendado)\n",
            'javascript', 'typescript' => "- Node.js 18 ou superior\n- npm ou yarn\n",
            'go' => "- Go 1.21 ou superior\n",
            'rust' => "- Rust 1.70 ou superior\n- Cargo\n",
            default => "- Verifique os arquivos do projeto\n"
        };
    }
    
    /**
     * Comandos de instalação padrão
     */
    private function getDefaultInstallCommands(string $language): string
    {
        return match($language) {
            'php' => "composer install",
            'python' => "python -m venv venv\nsource venv/bin/activate\npip install -r requirements.txt",
            'javascript', 'typescript' => "npm install\n# ou: yarn install",
            'go' => "go mod download",
            'rust' => "cargo build",
            default => "# Verifique os arquivos do projeto"
        };
    }
    
    /**
     * Comandos de execução padrão
     */
    private function getDefaultRunCommands(string $language, string $framework): string
    {
        return match($language) {
            'php' => $framework === 'laravel' 
                ? "php artisan serve" 
                : "php -S localhost:8000 -t public",
            'python' => "python main.py\n# ou: flask run\n# ou: uvicorn main:app",
            'javascript', 'typescript' => "npm start\n# ou: npm run dev",
            'go' => "go run .",
            'rust' => "cargo run",
            default => "# Verifique os arquivos do projeto"
        };
    }
    
    /**
     * Gera CLAUDE.md - Documentação completa para IA
     * 
     * Este arquivo é CRÍTICO para que Claude entenda o projeto
     * em conversas futuras quando o usuário enviar o ZIP
     */
    private function generateClaudeMd(
        string $projectName,
        string $description,
        array $features,
        string $language,
        string $framework,
        array $requirements,
        array $installCommands,
        array $runCommands,
        array $envVariables
    ): string {
        $date = date('d/m/Y');
        $allFiles = $this->listAllFiles('/workspace');
        
        $md = "# CLAUDE.md - {$projectName}\n\n";
        $md .= "> **Gerado em:** {$date}\n";
        $md .= "> **Por:** Ravi Developer (Claude AI)\n";
        $md .= "> **Versão:** 1.0.0\n\n";
        
        $md .= "---\n\n";
        $md .= "## 🎯 LEIA ISTO PRIMEIRO\n\n";
        $md .= "Este arquivo contém toda a documentação necessária para entender este projeto.\n";
        $md .= "**Claude:** Ao receber este projeto, leia este arquivo COMPLETAMENTE antes de fazer qualquer modificação.\n\n";
        
        $md .= "---\n\n";
        
        // 1. Visão Geral
        $md .= "## 1. VISÃO GERAL\n\n";
        $md .= "### O que é este projeto?\n\n";
        $md .= "{$description}\n\n";
        
        $md .= "### Funcionalidades\n\n";
        foreach ($features as $feature) {
            $md .= "- ✅ {$feature}\n";
        }
        $md .= "\n";
        
        $md .= "### Stack Tecnológica\n\n";
        $md .= "| Componente | Tecnologia |\n";
        $md .= "|------------|------------|\n";
        $md .= "| Linguagem | " . ucfirst($language) . " |\n";
        if ($framework) {
            $md .= "| Framework | {$framework} |\n";
        }
        $md .= "\n";
        
        // 2. Arquitetura
        $md .= "## 2. ARQUITETURA\n\n";
        $md .= "### Estrutura de Diretórios\n\n";
        $md .= "```\n";
        $md .= $this->generateTreeStructure('/workspace');
        $md .= "```\n\n";
        
        // 3. Arquivos Principais
        $md .= "## 3. ARQUIVOS PRINCIPAIS\n\n";
        $md .= "### Lista Completa de Arquivos\n\n";
        $md .= "| Arquivo | Descrição |\n";
        $md .= "|---------|----------|\n";
        
        // Identifica arquivos importantes baseado na extensão/nome
        foreach (array_slice($allFiles, 0, 30) as $file) {
            $desc = $this->guessFileDescription($file, $language);
            $md .= "| `{$file}` | {$desc} |\n";
        }
        if (count($allFiles) > 30) {
            $md .= "| ... | +" . (count($allFiles) - 30) . " arquivos adicionais |\n";
        }
        $md .= "\n";
        
        // 4. Instalação
        $md .= "## 4. INSTALAÇÃO\n\n";
        $md .= "### Requisitos\n\n";
        if (!empty($requirements)) {
            foreach ($requirements as $req) {
                $md .= "- {$req}\n";
            }
        } else {
            $md .= $this->getDefaultRequirements($language, $framework);
        }
        $md .= "\n";
        
        $md .= "### Comandos de Instalação\n\n";
        $md .= "```bash\n";
        if (!empty($installCommands)) {
            foreach ($installCommands as $cmd) {
                $md .= "{$cmd}\n";
            }
        } else {
            $md .= $this->getDefaultInstallCommands($language);
        }
        $md .= "```\n\n";
        
        // 5. Configuração
        if (!empty($envVariables)) {
            $md .= "## 5. CONFIGURAÇÃO\n\n";
            $md .= "### Variáveis de Ambiente\n\n";
            $md .= "| Variável | Descrição | Obrigatória | Padrão |\n";
            $md .= "|----------|-----------|-------------|--------|\n";
            foreach ($envVariables as $env) {
                $name = $env['name'] ?? 'N/A';
                $desc = $env['description'] ?? '-';
                $required = ($env['required'] ?? false) ? 'Sim' : 'Não';
                $default = $env['default'] ?? '-';
                $md .= "| `{$name}` | {$desc} | {$required} | {$default} |\n";
            }
            $md .= "\n";
        }
        
        // 6. Execução
        $md .= "## 6. EXECUÇÃO\n\n";
        $md .= "```bash\n";
        if (!empty($runCommands)) {
            foreach ($runCommands as $cmd) {
                $md .= "{$cmd}\n";
            }
        } else {
            $md .= $this->getDefaultRunCommands($language, $framework);
        }
        $md .= "```\n\n";
        
        // 7. Padrões do Projeto
        $md .= "## 7. PADRÕES DO PROJETO\n\n";
        $md .= "### Convenções de Código\n\n";
        $md .= $this->getCodeConventions($language, $framework);
        $md .= "\n";
        
        // 8. Pontos de Extensão
        $md .= "## 8. PONTOS DE EXTENSÃO\n\n";
        $md .= "### Onde adicionar novas funcionalidades\n\n";
        $md .= $this->getExtensionPoints($language, $framework);
        $md .= "\n";
        
        // 9. Troubleshooting
        $md .= "## 9. TROUBLESHOOTING\n\n";
        $md .= "### Problemas Comuns\n\n";
        $md .= "| Problema | Solução |\n";
        $md .= "|----------|--------|\n";
        $md .= "| Erro de permissão | `chmod -R 755 .` |\n";
        $md .= "| Dependência faltando | Reinstale com os comandos de instalação |\n";
        $md .= "| Porta em uso | Altere a porta nas configurações |\n";
        $md .= "| Erro de conexão BD | Verifique .env |\n\n";
        
        // 10. Notas para Claude
        $md .= "## 10. NOTAS PARA CLAUDE\n\n";
        $md .= "### Ao modificar este projeto:\n\n";
        $md .= "1. **Mantenha a estrutura** - Não reorganize pastas sem necessidade\n";
        $md .= "2. **Siga os padrões** - Use as mesmas convenções já estabelecidas\n";
        $md .= "3. **Atualize este arquivo** - Se fizer mudanças significativas, atualize este CLAUDE.md\n";
        $md .= "4. **Teste antes de entregar** - Use as ferramentas de teste disponíveis\n";
        $md .= "5. **Documente mudanças** - Atualize o CHANGELOG.md\n\n";
        
        $md .= "### Arquivos que NÃO devem ser modificados sem cuidado:\n\n";
        $md .= "- Arquivos de configuração (.env, config/)\n";
        $md .= "- Migrations de banco de dados\n";
        $md .= "- Arquivos de lock (composer.lock, package-lock.json)\n\n";
        
        $md .= "---\n\n";
        $md .= "*Documentação gerada automaticamente por Ravi Developer*\n";
        $md .= "*Powered by Claude AI (Anthropic)*\n";
        
        return $md;
    }
    
    /**
     * Gera arquivos do GitHub
     */
    private function generateGitHubFiles(string $projectName, string $description, string $language): void
    {
        // .gitignore
        $gitignore = $this->generateGitignore($language);
        $this->writeFile('/workspace/.gitignore', $gitignore);
        
        // Cria diretório .github
        $this->sandbox->execute($this->containerName, "mkdir -p /workspace/.github/workflows");
        
        // .github/README.md (para o repositório)
        $ghReadme = "# {$projectName}\n\n";
        $ghReadme .= "{$description}\n\n";
        $ghReadme .= "## 📖 Documentação\n\n";
        $ghReadme .= "Veja o arquivo [CLAUDE.md](../CLAUDE.md) para documentação completa.\n\n";
        $ghReadme .= "## 🚀 Quick Start\n\n";
        $ghReadme .= "Veja o arquivo [QUICKSTART.md](../QUICKSTART.md) para começar rapidamente.\n";
        $this->writeFile('/workspace/.github/README.md', $ghReadme);
        
        // .github/CONTRIBUTING.md
        $contributing = "# Contribuindo para {$projectName}\n\n";
        $contributing .= "## Como Contribuir\n\n";
        $contributing .= "1. Faça um fork do projeto\n";
        $contributing .= "2. Crie uma branch para sua feature (`git checkout -b feature/nova-feature`)\n";
        $contributing .= "3. Commit suas mudanças (`git commit -m 'Adiciona nova feature'`)\n";
        $contributing .= "4. Push para a branch (`git push origin feature/nova-feature`)\n";
        $contributing .= "5. Abra um Pull Request\n\n";
        $contributing .= "## Padrões de Código\n\n";
        $contributing .= "- Siga as convenções existentes no projeto\n";
        $contributing .= "- Documente novas funcionalidades\n";
        $contributing .= "- Adicione testes quando possível\n";
        $this->writeFile('/workspace/.github/CONTRIBUTING.md', $contributing);
        
        // .github/workflows/ci.yml (CI básico)
        $ciYml = $this->generateCIWorkflow($language);
        $this->writeFile('/workspace/.github/workflows/ci.yml', $ciYml);
    }
    
    /**
     * Gera .gitignore baseado na linguagem
     */
    private function generateGitignore(string $language): string
    {
        $common = "# IDE\n.idea/\n.vscode/\n*.swp\n*.swo\n\n";
        $common .= "# OS\n.DS_Store\nThumbs.db\n\n";
        $common .= "# Logs\n*.log\nlogs/\n\n";
        $common .= "# Environment\n.env\n.env.local\n.env.*.local\n\n";
        
        $specific = match($language) {
            'php' => "# PHP\n/vendor/\ncomposer.phar\n*.cache\n/storage/logs/*\n/storage/cache/*\n",
            'python' => "# Python\n__pycache__/\n*.py[cod]\n*\$py.class\n.Python\nvenv/\nenv/\n.venv/\n*.egg-info/\ndist/\nbuild/\n",
            'javascript', 'typescript' => "# Node\nnode_modules/\nnpm-debug.log\nyarn-error.log\n.npm\ndist/\nbuild/\n",
            'go' => "# Go\n*.exe\n*.exe~\n*.dll\n*.so\n*.dylib\n*.test\n*.out\nvendor/\n",
            'rust' => "# Rust\n/target/\nCargo.lock\n**/*.rs.bk\n",
            default => ""
        };
        
        return $common . $specific;
    }
    
    /**
     * Gera workflow de CI baseado na linguagem
     */
    private function generateCIWorkflow(string $language): string
    {
        $yml = "name: CI\n\n";
        $yml .= "on:\n";
        $yml .= "  push:\n";
        $yml .= "    branches: [ main, master ]\n";
        $yml .= "  pull_request:\n";
        $yml .= "    branches: [ main, master ]\n\n";
        $yml .= "jobs:\n";
        $yml .= "  build:\n";
        $yml .= "    runs-on: ubuntu-latest\n\n";
        $yml .= "    steps:\n";
        $yml .= "    - uses: actions/checkout@v4\n\n";
        
        $yml .= match($language) {
            'php' => "    - name: Setup PHP\n" .
                     "      uses: shivammathur/setup-php@v2\n" .
                     "      with:\n" .
                     "        php-version: '8.1'\n\n" .
                     "    - name: Install dependencies\n" .
                     "      run: composer install --prefer-dist --no-progress\n\n" .
                     "    - name: Run tests\n" .
                     "      run: vendor/bin/phpunit --testdox\n",
                     
            'python' => "    - name: Setup Python\n" .
                        "      uses: actions/setup-python@v4\n" .
                        "      with:\n" .
                        "        python-version: '3.11'\n\n" .
                        "    - name: Install dependencies\n" .
                        "      run: |\n" .
                        "        pip install -r requirements.txt\n\n" .
                        "    - name: Run tests\n" .
                        "      run: pytest\n",
                        
            'javascript', 'typescript' => "    - name: Setup Node\n" .
                                          "      uses: actions/setup-node@v4\n" .
                                          "      with:\n" .
                                          "        node-version: '20'\n\n" .
                                          "    - name: Install dependencies\n" .
                                          "      run: npm ci\n\n" .
                                          "    - name: Run tests\n" .
                                          "      run: npm test\n",
                                          
            'go' => "    - name: Setup Go\n" .
                    "      uses: actions/setup-go@v4\n" .
                    "      with:\n" .
                    "        go-version: '1.21'\n\n" .
                    "    - name: Build\n" .
                    "      run: go build -v ./...\n\n" .
                    "    - name: Test\n" .
                    "      run: go test -v ./...\n",
                    
            default => "    - name: Build\n" .
                       "      run: echo 'Add build steps'\n"
        };
        
        return $yml;
    }
    
    /**
     * Adivinha descrição do arquivo baseado no nome
     */
    private function guessFileDescription(string $file, string $language): string
    {
        $basename = basename($file);
        $dir = dirname($file);
        
        // Arquivos especiais
        $specialFiles = [
            'README.md' => 'Documentação principal',
            'CLAUDE.md' => 'Documentação para IA (Claude)',
            'INSTALL.md' => 'Guia de instalação',
            'QUICKSTART.md' => 'Guia rápido',
            'CHANGELOG.md' => 'Histórico de mudanças',
            'LICENSE' => 'Licença do projeto',
            'composer.json' => 'Dependências PHP',
            'package.json' => 'Dependências Node.js',
            'requirements.txt' => 'Dependências Python',
            'go.mod' => 'Módulo Go',
            'Cargo.toml' => 'Configuração Rust',
            '.env.example' => 'Template de variáveis de ambiente',
            '.gitignore' => 'Arquivos ignorados pelo Git',
            'Makefile' => 'Comandos de build',
            'Dockerfile' => 'Configuração Docker',
            'docker-compose.yml' => 'Orquestração Docker',
        ];
        
        if (isset($specialFiles[$basename])) {
            return $specialFiles[$basename];
        }
        
        // Baseado na extensão
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $extDescriptions = [
            'php' => 'Código PHP',
            'py' => 'Código Python',
            'js' => 'Código JavaScript',
            'ts' => 'Código TypeScript',
            'go' => 'Código Go',
            'rs' => 'Código Rust',
            'html' => 'Template HTML',
            'css' => 'Estilos CSS',
            'sql' => 'Script SQL',
            'json' => 'Configuração JSON',
            'yml' => 'Configuração YAML',
            'yaml' => 'Configuração YAML',
            'md' => 'Documentação Markdown',
            'sh' => 'Script Shell',
        ];
        
        // Baseado no diretório
        if (str_contains($dir, 'controller') || str_contains($dir, 'Controller')) {
            return 'Controller';
        }
        if (str_contains($dir, 'model') || str_contains($dir, 'Model')) {
            return 'Model';
        }
        if (str_contains($dir, 'view') || str_contains($dir, 'View')) {
            return 'View/Template';
        }
        if (str_contains($dir, 'service') || str_contains($dir, 'Service')) {
            return 'Service';
        }
        if (str_contains($dir, 'test') || str_contains($dir, 'Test')) {
            return 'Teste';
        }
        if (str_contains($dir, 'config')) {
            return 'Configuração';
        }
        if (str_contains($dir, 'migration')) {
            return 'Migration de banco';
        }
        
        return $extDescriptions[$ext] ?? 'Arquivo do projeto';
    }
    
    /**
     * Convenções de código por linguagem
     */
    private function getCodeConventions(string $language, string $framework): string
    {
        return match($language) {
            'php' => "- PSR-12 para estilo de código\n" .
                     "- PSR-4 para autoloading\n" .
                     "- Namespaces seguem estrutura de diretórios\n" .
                     "- Tipagem estrita (`declare(strict_types=1)`)\n",
                     
            'python' => "- PEP 8 para estilo de código\n" .
                        "- Type hints quando possível\n" .
                        "- Docstrings para funções e classes\n",
                        
            'javascript', 'typescript' => "- ESLint para linting\n" .
                                          "- Prettier para formatação\n" .
                                          "- camelCase para variáveis e funções\n" .
                                          "- PascalCase para classes e componentes\n",
                                          
            'go' => "- gofmt para formatação\n" .
                    "- golint para linting\n" .
                    "- Comentários para funções exportadas\n",
                    
            default => "- Siga os padrões existentes no projeto\n"
        };
    }
    
    /**
     * Pontos de extensão por linguagem/framework
     */
    private function getExtensionPoints(string $language, string $framework): string
    {
        if ($language === 'php' && $framework === 'laravel') {
            return "- **Controllers:** `app/Http/Controllers/`\n" .
                   "- **Models:** `app/Models/`\n" .
                   "- **Migrations:** `database/migrations/`\n" .
                   "- **Routes:** `routes/web.php` ou `routes/api.php`\n" .
                   "- **Views:** `resources/views/`\n";
        }
        
        return match($language) {
            'php' => "- **Controllers:** `src/Controllers/`\n" .
                     "- **Models:** `src/Models/`\n" .
                     "- **Services:** `src/Services/`\n" .
                     "- **Routes:** `src/routes.php`\n",
                     
            'python' => "- **Rotas/Views:** Arquivo principal ou `routes/`\n" .
                        "- **Models:** `models/`\n" .
                        "- **Services:** `services/`\n",
                        
            'javascript', 'typescript' => "- **Componentes:** `src/components/`\n" .
                                          "- **Pages:** `src/pages/` ou `pages/`\n" .
                                          "- **Services:** `src/services/`\n" .
                                          "- **Utils:** `src/utils/`\n",
                                          
            default => "- Verifique a estrutura de diretórios do projeto\n"
        };
    }
}
