<?php

declare(strict_types=1);

namespace Ravi\Agent\Agents;

/**
 * Agente Testador
 * 
 * Responsável por:
 * - Criar testes automatizados
 * - Executar testes
 * - Validar funcionalidade
 * - Testar edge cases
 * - Verificar se código funciona como esperado
 */
class TesterAgent extends BaseAgent
{
    public function getName(): string
    {
        return 'tester';
    }
    
    public function getDescription(): string
    {
        return 'Cria e executa testes para validar funcionalidade do código';
    }
    
    public function getSystemPrompt(): string
    {
        return <<<PROMPT
Você é o AGENTE TESTADOR de um sistema de desenvolvimento de software autônomo.

## Seu Papel
Você cria testes automatizados e valida que o código funciona corretamente.

## Suas Capacidades
- Criar testes unitários
- Criar testes de integração
- Executar código para validação
- Testar edge cases
- Verificar tratamento de erros

## Ferramentas Disponíveis
- file_read: Ler código para entender o que testar
- file_write: Criar arquivos de teste
- file_list: Listar estrutura do projeto
- bash_execute: Executar testes (phpunit, pytest, jest, etc)
- python_execute: Executar testes Python
- node_execute: Executar testes Node.js
- browser_navigate: Testar interfaces web
- browser_screenshot: Capturar estado visual
- browser_click: Interagir com elementos
- browser_type: Preencher formulários

## Tipos de Teste
1. **Unitário**: Testar funções/métodos isolados
2. **Integração**: Testar componentes juntos
3. **Funcional**: Testar features completas
4. **Visual**: Verificar aparência (screenshots)
5. **E2E**: Testar fluxos completos

## Estratégia de Testes
1. Leia o código a ser testado
2. Identifique funções/endpoints críticos
3. Crie casos de teste:
   - Caso normal (happy path)
   - Casos de borda (edge cases)
   - Casos de erro (invalid inputs)
4. Execute os testes
5. Reporte resultados

## Formato de Saída
```json
{
    "status": "passed|failed|partial",
    "summary": "Resumo dos testes",
    "tests": [
        {
            "name": "Nome do teste",
            "type": "unit|integration|functional|visual|e2e",
            "status": "passed|failed|skipped",
            "duration_ms": 123,
            "error": null,
            "details": "Detalhes adicionais"
        }
    ],
    "coverage": {
        "files_tested": 5,
        "functions_tested": 20,
        "estimated_coverage": "75%"
    },
    "issues_found": [
        "Lista de problemas encontrados"
    ],
    "test_files_created": ["tests/test_*.php"]
}
```

## Regras
1. Priorize testes de funcionalidades críticas
2. Sempre teste happy path + error cases
3. Use mocks quando necessário
4. Testes devem ser reproduzíveis
5. Documente o que cada teste verifica

Ao finalizar, use task_complete com o relatório de testes.
PROMPT;
    }
    
    public function getAllowedTools(): array
    {
        return [
            'file_read',
            'file_write',
            'file_list',
            'bash_execute',
            'python_execute',
            'node_execute',
            'browser_navigate',
            'browser_screenshot',
            'browser_click',
            'browser_type',
            'task_complete'
        ];
    }
    
    /**
     * Testa arquivos específicos
     */
    public function testFiles(array $files, array $context = []): array
    {
        $fileList = implode("\n", array_map(fn($f) => "- {$f}", $files));
        
        $prompt = "Crie e execute testes para os seguintes arquivos:\n\n{$fileList}\n\n";
        $prompt .= "Inclua testes para casos normais, edge cases e tratamento de erros.";
        
        return $this->execute($prompt, $context);
    }
    
    /**
     * Executa testes existentes
     */
    public function runTests(string $testCommand = '', array $context = []): array
    {
        $prompt = "Execute os testes do projeto";
        
        if ($testCommand) {
            $prompt .= " usando o comando: {$testCommand}";
        }
        
        $prompt .= "\n\nAnalise os resultados e reporte problemas encontrados.";
        
        return $this->execute($prompt, $context);
    }
    
    /**
     * Testa uma feature específica
     */
    public function testFeature(string $feature, string $description, array $context = []): array
    {
        $prompt = "Teste a feature: {$feature}\n\n";
        $prompt .= "Descrição: {$description}\n\n";
        $prompt .= "Crie testes que validem que a feature funciona corretamente.";
        
        return $this->execute($prompt, $context);
    }
    
    /**
     * Testa interface web
     */
    public function testUI(string $url, array $scenarios, array $context = []): array
    {
        $scenarioList = implode("\n", array_map(fn($s, $i) => ($i+1) . ". {$s}", $scenarios, array_keys($scenarios)));
        
        $prompt = "Teste a interface web em: {$url}\n\n";
        $prompt .= "Cenários a testar:\n{$scenarioList}\n\n";
        $prompt .= "Use browser_navigate, browser_click, browser_type e browser_screenshot.";
        
        return $this->execute($prompt, $context);
    }
}
