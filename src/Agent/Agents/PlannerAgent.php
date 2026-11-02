<?php

declare(strict_types=1);

namespace Ravi\Agent\Agents;

/**
 * Agente Planejador
 * 
 * Responsável por:
 * - Analisar a tarefa do usuário
 * - Dividir em etapas menores
 * - Definir ordem de execução
 * - Identificar dependências entre etapas
 */
class PlannerAgent extends BaseAgent
{
    public function getName(): string
    {
        return 'planner';
    }
    
    public function getDescription(): string
    {
        return 'Analisa tarefas complexas e cria planos de execução detalhados';
    }
    
    public function getSystemPrompt(): string
    {
        return <<<PROMPT
Você é o AGENTE PLANEJADOR de um sistema de desenvolvimento de software autônomo.

## Seu Papel
Você recebe tarefas complexas de desenvolvimento e as divide em etapas menores, 
organizadas e executáveis.

## Suas Capacidades
- Analisar requisitos de software
- Identificar componentes necessários
- Criar planos de execução passo-a-passo
- Definir dependências entre tarefas
- Estimar complexidade

## Formato de Saída
Sempre retorne seu plano no formato JSON:
```json
{
    "project_name": "Nome do projeto",
    "description": "Descrição breve",
    "estimated_complexity": "low|medium|high",
    "steps": [
        {
            "id": 1,
            "name": "Nome da etapa",
            "description": "O que fazer",
            "agent": "coder|tester|reviewer",
            "depends_on": [],
            "files_to_create": ["arquivo1.php", "arquivo2.js"],
            "priority": "high|medium|low"
        }
    ],
    "architecture": {
        "type": "mvc|api|cli|library",
        "language": "php|python|node|etc",
        "structure": {}
    }
}
```

## Regras
1. Seja específico - cada etapa deve ser clara e executável
2. Ordene por dependências - tarefas dependentes vêm depois
3. Agrupe tarefas relacionadas
4. Considere testes desde o início
5. Pense em manutenibilidade

## Ferramentas
Use file_list e file_read para entender código existente antes de planejar.
Use web_search para pesquisar padrões e melhores práticas quando necessário.

Ao finalizar, use task_complete com o plano JSON completo.
PROMPT;
    }
    
    public function getAllowedTools(): array
    {
        return [
            'file_list',
            'file_read',
            'web_search',
            'task_complete'
        ];
    }
    
    /**
     * Cria plano de execução para uma tarefa
     */
    public function createPlan(string $task, array $existingFiles = []): array
    {
        $context = [
            'existing_files' => $existingFiles,
            'workspace' => '/workspace'
        ];
        
        $prompt = "Crie um plano de execução detalhado para a seguinte tarefa:\n\n{$task}";
        
        $result = $this->execute($prompt, $context);
        
        // Tenta extrair JSON do resultado
        if (isset($result['result'])) {
            $plan = $this->extractPlanFromResult($result['result']);
            if ($plan) {
                return $plan;
            }
        }
        
        // Fallback - plano simples
        return [
            'project_name' => 'Projeto',
            'description' => $task,
            'estimated_complexity' => 'medium',
            'steps' => [
                [
                    'id' => 1,
                    'name' => 'Implementar solução',
                    'description' => $task,
                    'agent' => 'coder',
                    'depends_on' => [],
                    'files_to_create' => [],
                    'priority' => 'high'
                ]
            ]
        ];
    }
    
    /**
     * Extrai plano JSON do resultado
     */
    private function extractPlanFromResult(mixed $result): ?array
    {
        // Já é um plano estruturado
        if (is_array($result) && isset($result['steps'])) {
            return $result;
        }

        // É o input do task_complete ({summary, files_created, ...}) — procura o JSON do plano no texto
        if (is_array($result)) {
            $text = '';
            foreach (['summary', 'plan', 'description'] as $k) {
                if (isset($result[$k]) && is_string($result[$k])) {
                    $text .= "\n" . $result[$k];
                }
            }
            $result = $text;
        }

        if (is_string($result) && $result !== '') {
            // Tenta encontrar JSON com "steps" no texto
            if (preg_match('/\{[\s\S]*"steps"[\s\S]*\}/m', $result, $matches)) {
                $plan = json_decode($matches[0], true);
                if ($plan && isset($plan['steps'])) {
                    return $plan;
                }
            }
        }

        return null;
    }
}
