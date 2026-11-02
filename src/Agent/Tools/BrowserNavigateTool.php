<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

use Ravi\Services\BrowserService;

/**
 * Ferramenta para navegar em páginas web usando navegador headless
 */
class BrowserNavigateTool extends BaseTool
{
    public function getName(): string
    {
        return 'browser_navigate';
    }
    
    public function getDescription(): string
    {
        return 'Navega para uma URL usando navegador headless (Puppeteer). Útil para acessar páginas com JavaScript, SPAs, ou páginas que requerem renderização completa. Retorna título, URL final e status.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'URL completa para navegar (deve começar com http:// ou https://)',
                ],
                'wait_for' => [
                    'type' => 'string',
                    'description' => 'Seletor CSS de um elemento para aguardar antes de continuar (opcional)',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout em milissegundos (padrão: 30000)',
                ],
            ],
            'required' => ['url'],
        ];
    }
    
    public function execute(array $input): string
    {
        $url = $input['url'] ?? '';
        $waitFor = $input['wait_for'] ?? null;
        $timeout = $input['timeout'] ?? 30000;

        // Validação
        if (empty($url)) {
            return $this->error('URL é obrigatória');
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

            $result = $browser->navigate($url, $waitFor, $timeout);

            if (!$result['success']) {
                return $this->error($result['error'] ?? 'Falha ao navegar para URL');
            }

            return $this->success("Navegou para: {$result['title']} ({$result['url']})", [
                'url' => $result['url'] ?? $url,
                'title' => $result['title'] ?? '',
                'status' => $result['status'] ?? 200,
                'content_length' => $result['contentLength'] ?? 0,
            ]);

        } catch (\Exception $e) {
            return $this->error('Erro ao navegar: ' . $e->getMessage());
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
            '/^0\./',
            '/^169\.254\./',
        ];
        
        foreach ($privatePatterns as $pattern) {
            if (preg_match($pattern, $host)) {
                return true;
            }
        }
        
        return false;
    }
}
