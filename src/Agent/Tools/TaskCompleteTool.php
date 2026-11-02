<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

/**
 * Ferramenta para sinalizar que a tarefa foi concluída
 */
class TaskCompleteTool extends BaseTool
{
    public function getName(): string
    {
        return 'task_complete';
    }
    
    public function getDescription(): string
    {
        return 'Sinaliza que a tarefa foi concluída. Use quando terminar de executar tudo que foi solicitado.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'Resumo do que foi realizado'
                ],
                'files_created' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Lista de arquivos criados/modificados'
                ],
                'next_steps' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Sugestões de próximos passos para o usuário'
                ]
            ],
            'required' => ['summary']
        ];
    }
    
    public function execute(array $input): string
    {
        $summary = $input['summary'];
        $filesCreated = $input['files_created'] ?? [];
        $nextSteps = $input['next_steps'] ?? [];
        
        $output = "🎉 Tarefa Concluída!\n\n";
        $output .= "📝 Resumo:\n{$summary}\n";
        
        if (!empty($filesCreated)) {
            $output .= "\n📁 Arquivos criados/modificados:\n";
            foreach ($filesCreated as $file) {
                $output .= "  • {$file}\n";
            }
        }
        
        if (!empty($nextSteps)) {
            $output .= "\n💡 Próximos passos sugeridos:\n";
            foreach ($nextSteps as $i => $step) {
                $num = $i + 1;
                $output .= "  {$num}. {$step}\n";
            }
        }
        
        return $output;
    }
}
