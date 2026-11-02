<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

/**
 * Ferramenta para ler arquivos no sandbox
 */
class FileReadTool extends BaseTool
{
    public function getName(): string
    {
        return 'file_read';
    }
    
    public function getDescription(): string
    {
        return 'Lê o conteúdo de um arquivo no sandbox.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Caminho do arquivo a ser lido'
                ],
                'encoding' => [
                    'type' => 'string',
                    'description' => 'Encoding do arquivo (padrão: utf-8)'
                ]
            ],
            'required' => ['path']
        ];
    }
    
    public function execute(array $input): string
    {
        $path = $input['path'];
        
        // Normaliza caminho
        if (!str_starts_with($path, '/')) {
            $path = '/workspace/' . $path;
        }
        
        if (!$this->sandbox || !$this->containerName) {
            return $this->error('Sandbox não configurado');
        }
        
        // Verifica se arquivo existe
        if (!$this->sandbox->fileExists($this->containerName, $path)) {
            return $this->error("Arquivo não encontrado: {$path}");
        }
        
        $content = $this->sandbox->readFile($this->containerName, $path);
        
        if ($content === null) {
            return $this->error("Erro ao ler arquivo: {$path}");
        }
        
        return "📄 Conteúdo de {$path}:\n\n" . $this->truncate($content);
    }
}
