<?php

declare(strict_types=1);

namespace Ravi\Agent\Agents;

/**
 * Agente Codificador
 * 
 * Responsável por:
 * - Escrever código de alta qualidade
 * - Implementar features conforme especificação
 * - Criar estrutura de arquivos
 * - Seguir padrões e boas práticas
 */
class CoderAgent extends BaseAgent
{
    public function getName(): string
    {
        return 'coder';
    }
    
    public function getDescription(): string
    {
        return 'Escreve código de alta qualidade seguindo especificações';
    }
    
    public function getSystemPrompt(): string
    {
        return <<<PROMPT
Você é o AGENTE CODIFICADOR de um sistema de desenvolvimento de software autônomo.

## Seu Papel
Você implementa código de alta qualidade seguindo especificações e boas práticas.

## Suas Capacidades
- Escrever código limpo e bem documentado
- Implementar múltiplas linguagens (PHP, Python, JavaScript, TypeScript, Go, Rust, etc)
- Criar estruturas de projeto organizadas
- Seguir padrões de design (MVC, SOLID, DRY, etc)
- Implementar APIs REST, CLIs, bibliotecas

## Ferramentas Disponíveis
- file_write: Criar e modificar arquivos
- file_read: Ler arquivos existentes
- file_list: Listar diretório
- bash_execute: Executar comandos (criar diretórios, git, etc)
- python_execute: Testar código Python
- node_execute: Testar código Node.js
- web_search: Pesquisar documentação

## Regras de Codificação
1. **Qualidade**: Código limpo, legível, bem comentado
2. **Estrutura**: Organize em arquivos/pastas lógicas
3. **Tratamento de Erros**: Sempre trate exceções
4. **Validação**: Valide inputs
5. **Segurança**: Evite vulnerabilidades comuns
6. **Documentação**: Docstrings/PHPDoc em funções públicas

## Fluxo de Trabalho
1. Entenda a especificação
2. Planeje a estrutura de arquivos
3. Crie diretórios necessários (bash_execute: mkdir -p)
4. Implemente arquivos um a um (file_write)
5. Verifique sintaxe quando possível
6. Use task_complete quando terminar

## Formato de Conclusão
Ao usar task_complete, inclua:
```json
{
    "summary": "O que foi implementado",
    "files_created": ["lista", "de", "arquivos"],
    "files_modified": ["lista", "de", "arquivos"],
    "notes": "Observações importantes",
    "next_steps": ["Sugestões para próximos passos"]
}
```

Seja metódico e implemente completamente antes de marcar como concluído.
PROMPT;
    }
    
    public function getAllowedTools(): array
    {
        return [
            'file_write',
            'file_read',
            'file_list',
            'bash_execute',
            'python_execute',
            'node_execute',
            'web_search',
            'task_complete'
        ];
    }
    
    /**
     * Implementa uma etapa do plano
     */
    public function implementStep(array $step, array $context = []): array
    {
        $prompt = $this->buildStepPrompt($step);
        
        return $this->execute($prompt, $context);
    }
    
    /**
     * Implementa código com base em especificação
     */
    public function implement(string $specification, array $context = []): array
    {
        return $this->execute($specification, $context);
    }
    
    /**
     * Constrói prompt para uma etapa
     */
    private function buildStepPrompt(array $step): string
    {
        $prompt = "## Tarefa: {$step['name']}\n\n";
        $prompt .= "### Descrição\n{$step['description']}\n\n";
        
        if (!empty($step['files_to_create'])) {
            $prompt .= "### Arquivos a Criar\n";
            foreach ($step['files_to_create'] as $file) {
                $prompt .= "- {$file}\n";
            }
            $prompt .= "\n";
        }
        
        if (!empty($step['requirements'])) {
            $prompt .= "### Requisitos\n";
            foreach ($step['requirements'] as $req) {
                $prompt .= "- {$req}\n";
            }
            $prompt .= "\n";
        }
        
        $prompt .= "Implemente esta tarefa completamente. ";
        $prompt .= "Use file_write para criar cada arquivo. ";
        $prompt .= "Ao finalizar, use task_complete com o resumo.";
        
        return $prompt;
    }
}
