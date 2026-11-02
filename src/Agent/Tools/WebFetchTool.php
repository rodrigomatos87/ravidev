<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

use Ravi\Services\WebFetchService;

/**
 * Ferramenta para buscar conteúdo de URLs
 */
class WebFetchTool extends BaseTool
{
    private WebFetchService $fetchService;
    
    public function __construct()
    {
        $this->fetchService = new WebFetchService();
    }
    
    public function getName(): string
    {
        return 'web_fetch';
    }
    
    public function getDescription(): string
    {
        return 'Faz requisição HTTP GET para uma URL e retorna o conteúdo.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'URL para fazer fetch'
                ],
                'extract_text' => [
                    'type' => 'boolean',
                    'description' => 'Se true, extrai apenas texto removendo HTML (padrão: false)'
                ]
            ],
            'required' => ['url']
        ];
    }
    
    public function execute(array $input): string
    {
        $url = $input['url'];
        $extractText = $input['extract_text'] ?? false;
        
        try {
            $result = $this->fetchService->fetch($url, $extractText);
            
            if (!$result['success']) {
                return $this->error($result['error']);
            }
            
            $output = "🌐 Conteúdo de {$url}\n";
            $output .= "Tipo: {$result['content_type']}\n";
            $output .= "Tamanho: {$result['size']} bytes\n\n";
            $output .= $this->truncate($result['content'], 30000);
            
            return $output;
            
        } catch (\Exception $e) {
            return $this->error('Erro ao buscar URL', $e->getMessage());
        }
    }
}
