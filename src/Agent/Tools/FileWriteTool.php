<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

/**
 * Ferramenta para escrever arquivos no sandbox
 */
class FileWriteTool extends BaseTool
{
    public function getName(): string
    {
        return 'file_write';
    }
    
    public function getDescription(): string
    {
        return 'Cria ou sobrescreve um arquivo no sandbox.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Caminho do arquivo a ser criado/escrito'
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Conteúdo a ser escrito no arquivo'
                ],
                'append' => [
                    'type' => 'boolean',
                    'description' => 'Se true, adiciona ao final do arquivo ao invés de sobrescrever'
                ]
            ],
            'required' => ['path', 'content']
        ];
    }
    
    public function execute(array $input): string
    {
        $path = $input['path'];
        $content = $input['content'];
        $append = $input['append'] ?? false;
        
        // Normaliza caminho
        if (!str_starts_with($path, '/')) {
            $path = '/workspace/' . $path;
        }
        
        if (!$this->sandbox || !$this->containerName) {
            return $this->error('Sandbox não configurado');
        }
        
        try {
            $success = $this->sandbox->writeFile(
                $this->containerName,
                $path,
                $content,
                $append
            );
            
            if ($success) {
                $action = $append ? 'atualizado' : 'criado';
                $size = strlen($content);
                return $this->success("Arquivo {$action}: {$path} ({$size} bytes)");
            } else {
                return $this->error("Falha ao escrever arquivo: {$path}");
            }
            
        } catch (\Exception $e) {
            return $this->error('Erro ao escrever arquivo', $e->getMessage());
        }
    }
}
