<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

/**
 * Ferramenta para listar arquivos no sandbox
 */
class FileListTool extends BaseTool
{
    public function getName(): string
    {
        return 'file_list';
    }
    
    public function getDescription(): string
    {
        return 'Lista arquivos e diretórios em um caminho.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Caminho do diretório a ser listado'
                ],
                'recursive' => [
                    'type' => 'boolean',
                    'description' => 'Se true, lista recursivamente (padrão: false)'
                ],
                'include_hidden' => [
                    'type' => 'boolean',
                    'description' => 'Se true, inclui arquivos ocultos (padrão: false)'
                ]
            ],
            'required' => ['path']
        ];
    }
    
    public function execute(array $input): string
    {
        $path = $input['path'];
        $recursive = $input['recursive'] ?? false;
        $includeHidden = $input['include_hidden'] ?? false;
        
        // Normaliza caminho
        if (!str_starts_with($path, '/')) {
            $path = '/workspace/' . $path;
        }
        
        if (!$this->sandbox || !$this->containerName) {
            return $this->error('Sandbox não configurado');
        }
        
        $files = $this->sandbox->listFiles(
            $this->containerName,
            $path,
            $recursive,
            $includeHidden
        );
        
        if (empty($files)) {
            return "📁 {$path}: (vazio ou não encontrado)";
        }
        
        $output = "📁 {$path}:\n\n";
        
        foreach ($files as $file) {
            $icon = $file['type'] === 'directory' ? '📁' : '📄';
            $size = $file['type'] === 'file' ? $this->formatSize($file['size']) : '';
            $output .= "{$icon} {$file['name']}";
            if ($size) {
                $output .= " ({$size})";
            }
            $output .= "\n";
        }
        
        return $output;
    }
    
    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
