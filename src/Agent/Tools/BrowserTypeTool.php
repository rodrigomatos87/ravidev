<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

use Ravi\Services\BrowserService;

/**
 * Ferramenta para digitar texto em campos de formulários
 */
class BrowserTypeTool extends BaseTool
{
    public function getName(): string
    {
        return 'browser_type';
    }
    
    public function getDescription(): string
    {
        return 'Digita texto em um campo de input/textarea de uma página web. Útil para preencher formulários, campos de busca, ou qualquer área de texto. Simula digitação humana com pequeno delay entre teclas.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'URL da página com o formulário',
                ],
                'selector' => [
                    'type' => 'string',
                    'description' => 'Seletor CSS do campo de input (ex: "#email", "input[name=search]", ".form-control")',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Texto a ser digitado no campo',
                ],
                'clear_first' => [
                    'type' => 'boolean',
                    'description' => 'Se true, limpa o campo antes de digitar (padrão: true)',
                ],
            ],
            'required' => ['url', 'selector', 'text'],
        ];
    }
    
    public function execute(array $input): string
    {
        $url = $input['url'] ?? '';
        $selector = $input['selector'] ?? '';
        $text = $input['text'] ?? '';
        $clearFirst = $input['clear_first'] ?? true;
        
        // Validação
        if (empty($url)) {
            return $this->error('URL é obrigatória');
        }
        
        if (empty($selector)) {
            return $this->error('Seletor CSS é obrigatório');
        }
        
        if ($text === '') {
            return $this->error('Texto é obrigatório (use string vazia explicitamente se quiser limpar)');
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

            $result = $browser->type($url, $selector, $text, $clearFirst);

            if (!$result['success']) {
                return $this->error($result['error'] ?? 'Falha ao digitar no campo');
            }

            $displayText = strlen($text) > 50 ? substr($text, 0, 50) . '...' : $text;

            return $this->success("Digitou '{$displayText}' em '{$selector}'", [
                'selector' => $selector,
                'text_length' => strlen($text),
                'value' => $result['value'] ?? $text,
                'cleared_first' => $clearFirst,
            ]);

        } catch (\Exception $e) {
            return $this->error('Erro ao digitar: ' . $e->getMessage());
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
