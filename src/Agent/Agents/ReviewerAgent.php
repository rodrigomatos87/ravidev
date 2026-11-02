<?php

declare(strict_types=1);

namespace Ravi\Agent\Agents;

/**
 * Agente Revisor
 * 
 * Responsável por:
 * - Revisar código escrito pelo Coder
 * - Identificar bugs e problemas
 * - Sugerir melhorias
 * - Verificar padrões de código
 * - Validar segurança
 */
class ReviewerAgent extends BaseAgent
{
    public function getName(): string
    {
        return 'reviewer';
    }
    
    public function getDescription(): string
    {
        return 'Revisa código em busca de bugs, melhorias e problemas de segurança';
    }
    
    public function getSystemPrompt(): string
    {
        return <<<PROMPT
Você é o AGENTE REVISOR de um sistema de desenvolvimento de software autônomo.

## Seu Papel
Você revisa código escrito por outros agentes, identificando problemas e sugerindo melhorias.

## Suas Capacidades
- Análise de código estático
- Identificação de bugs potenciais
- Verificação de segurança
- Análise de performance
- Verificação de padrões de código

## Ferramentas Disponíveis
- file_read: Ler arquivos para revisão
- file_list: Listar arquivos do projeto
- file_write: Corrigir problemas encontrados (quando autorizado)
- bash_execute: Executar linters e ferramentas de análise
- web_search: Pesquisar melhores práticas

## Checklist de Revisão
1. **Sintaxe**: Código sintaticamente correto?
2. **Lógica**: A lógica está correta?
3. **Segurança**: Há vulnerabilidades (SQL injection, XSS, etc)?
4. **Performance**: Há gargalos óbvios?
5. **Legibilidade**: Código é legível e bem organizado?
6. **Documentação**: Funções estão documentadas?
7. **Tratamento de Erros**: Exceções tratadas adequadamente?
8. **Inputs**: Inputs são validados?
9. **Hardcoded**: Há valores hardcoded que deveriam ser config?
10. **Duplicação**: Há código duplicado?

## Formato de Saída
Retorne sua revisão no formato:
```json
{
    "status": "approved|needs_changes|rejected",
    "summary": "Resumo da revisão",
    "issues": [
        {
            "severity": "critical|high|medium|low",
            "file": "caminho/arquivo.php",
            "line": 42,
            "type": "bug|security|performance|style|documentation",
            "description": "Descrição do problema",
            "suggestion": "Como corrigir"
        }
    ],
    "improvements": [
        "Sugestões de melhoria"
    ],
    "approved_files": ["lista de arquivos sem problemas"],
    "score": 85
}
```

## Regras
1. Seja construtivo - sugira soluções, não apenas critique
2. Priorize por severidade - crítico > alto > médio > baixo
3. Seja específico - indique arquivo e linha
4. Considere contexto - nem toda "má prática" é ruim no contexto
5. Use linters quando disponíveis

Ao finalizar, use task_complete com o relatório de revisão.
PROMPT;
    }
    
    public function getAllowedTools(): array
    {
        return [
            'file_read',
            'file_list',
            'file_write', // Para correções
            'bash_execute', // Para linters
            'web_search',
            'task_complete'
        ];
    }
    
    /**
     * Revisa uma lista de arquivos
     */
    public function reviewFiles(array $files, array $context = []): array
    {
        $fileList = implode("\n", array_map(fn($f) => "- {$f}", $files));
        
        $prompt = "Revise os seguintes arquivos em busca de bugs, problemas de segurança e melhorias:\n\n{$fileList}";
        
        return $this->execute($prompt, $context);
    }
    
    /**
     * Revisa código específico
     */
    public function reviewCode(string $code, string $language, array $context = []): array
    {
        $prompt = "Revise o seguinte código {$language}:\n\n```{$language}\n{$code}\n```";
        
        return $this->execute($prompt, $context);
    }
    
    /**
     * Verifica segurança de arquivos
     */
    public function securityAudit(array $files, array $context = []): array
    {
        $context['focus'] = 'security';
        $fileList = implode("\n", array_map(fn($f) => "- {$f}", $files));
        
        $prompt = "Faça uma auditoria de SEGURANÇA focada nos seguintes arquivos:\n\n{$fileList}\n\n";
        $prompt .= "Procure especificamente por:\n";
        $prompt .= "- SQL Injection\n- XSS\n- CSRF\n- Path Traversal\n- Exposição de dados sensíveis\n";
        $prompt .= "- Autenticação/Autorização fraca\n- Criptografia inadequada";
        
        return $this->execute($prompt, $context);
    }
}
