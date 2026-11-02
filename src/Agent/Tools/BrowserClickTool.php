<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

use Ravi\Services\BrowserService;

/**
 * Ferramenta para clicar em elementos de páginas web
 */
class BrowserClickTool extends BaseTool
{
    public function getName(): string
    {
        return 'browser_click';
    }
    
    public function getDescription(): string
    {
        return 'Clica em um elemento de uma página web usando navegador headless. Útil para interagir com botões, links, menus, ou qualquer elemento clicável. Pode aguardar navegação após o clique.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'URL da página onde realizar o clique',
                ],
                'selector' => [
                    'type' => 'string',
                    'description' => 'Seletor CSS do elemento para clicar (ex: "#submit-btn", ".nav-link", "button[type=submit]")',
                ],
                'wait_for_navigation' => [
                    'type' => 'boolean',
                    'description' => 'Se true, aguarda navegação/redirecionamento após o clique (padrão: false)',
                ],
            ],
            'required' => ['url', 'selector'],
        ];
    }
    
    public function execute(array $input): string
    {
        $url = $input['url'] ?? '';
        $selector = $input['selector'] ?? '';
        $waitForNavigation = $input['wait_for_navigation'] ?? false;
        
        // Validação
        if (empty($url)) {
            return $this->error('URL é obrigatória');
        }
        
        if (empty($selector)) {
            return $this->error('Seletor CSS é obrigatório');
        }
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->error('URL inválida: ' . $url);
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

            $result = $browser->click($url, $selector, $waitForNavigation);

            if (!$result['success']) {
                return $this->error($result['error'] ?? 'Falha ao clicar no elemento');
            }

            return $this->success(
                "Clicou em '{$selector}'" . (isset($result['url']) && $result['url'] !== $url ? " → Navegou para: {$result['url']}" : ""),
                [
                    'selector' => $selector,
                    'url_before' => $url,
                    'url_after' => $result['url'] ?? $url,
                    'navigated' => ($result['url'] ?? $url) !== $url,
                ]
            );

        } catch (\Exception $e) {
            return $this->error('Erro ao clicar: ' . $e->getMessage());
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
