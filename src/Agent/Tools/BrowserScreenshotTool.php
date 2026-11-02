<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

use Ravi\Services\BrowserService;

/**
 * Ferramenta para capturar screenshots de páginas web
 */
class BrowserScreenshotTool extends BaseTool
{
    public function getName(): string
    {
        return 'browser_screenshot';
    }
    
    public function getDescription(): string
    {
        return 'Captura screenshot de uma página web usando navegador headless. Útil para verificar visualmente o resultado de código HTML/CSS, testar layouts, ou documentar páginas. Retorna o caminho do arquivo de imagem salvo.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'URL da página para capturar screenshot',
                ],
                'full_page' => [
                    'type' => 'boolean',
                    'description' => 'Se true, captura a página inteira com scroll (padrão: false, captura apenas viewport)',
                ],
                'selector' => [
                    'type' => 'string',
                    'description' => 'Seletor CSS de um elemento específico para capturar (opcional)',
                ],
                'output_path' => [
                    'type' => 'string',
                    'description' => 'Caminho onde salvar o screenshot (padrão: /workspace/screenshot.png)',
                ],
            ],
            'required' => ['url'],
        ];
    }
    
    public function execute(array $input): string
    {
        $url = $input['url'] ?? '';
        $fullPage = $input['full_page'] ?? false;
        $selector = $input['selector'] ?? null;
        $outputPath = $input['output_path'] ?? '/workspace/screenshot_' . time() . '.png';
        
        // Validação
        if (empty($url)) {
            return $this->error('URL é obrigatória');
        }
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->error('URL inválida: ' . $url);
        }
        
        // Garante extensão .png
        if (!str_ends_with(strtolower($outputPath), '.png')) {
            $outputPath .= '.png';
        }
        
        // Bloqueia URLs locais/privadas
        $host = parse_url($url, PHP_URL_HOST);
        if ($this->isPrivateHost($host)) {
            return $this->error('Acesso a hosts privados não é permitido');
        }
        
        if (!$this->sandbox || !$this->containerName) {
            return $this->error('Container sandbox não disponível');
        }

        try {
            $browser = new BrowserService($this->sandbox);
            $browser->setContainer($this->containerName);

            $result = $browser->screenshot($url, $fullPage, $selector, $outputPath);

            if (!$result['success']) {
                return $this->error($result['error'] ?? 'Falha ao capturar screenshot');
            }

            return $this->success("Screenshot salvo em: {$outputPath}", [
                'path' => $result['path'] ?? $outputPath,
                'url' => $url,
                'full_page' => $fullPage,
                'selector' => $selector,
            ]);

        } catch (\Exception $e) {
            return $this->error('Erro ao capturar screenshot: ' . $e->getMessage());
        }
    }
    
    /**
     * Verifica se host é privado/local
     */
    private function isPrivateHost(string $host): bool
    {
        $privatePatterns = [
            '/^localhost$/i',
            '/^127\./',
            '/^10\./',
            '/^172\.(1[6-9]|2[0-9]|3[0-1])\./',
            '/^192\.168\./',
        ];
        
        foreach ($privatePatterns as $pattern) {
            if (preg_match($pattern, $host)) {
                return true;
            }
        }
        
        return false;
    }
}
