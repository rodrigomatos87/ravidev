<?php

declare(strict_types=1);

namespace Ravi\Services;

/**
 * Serviço para buscar conteúdo de URLs
 */
class WebFetchService
{
    private int $timeout;
    private int $maxSize;
    private array $blockedDomains;
    
    public function __construct()
    {
        $this->timeout = 30;
        $this->maxSize = 5 * 1024 * 1024; // 5MB
        $this->blockedDomains = [
            'localhost',
            '127.0.0.1',
            '0.0.0.0',
            '10.',
            '172.16.',
            '192.168.'
        ];
    }
    
    /**
     * Busca conteúdo de uma URL
     */
    public function fetch(string $url, bool $extractText = false): array
    {
        // Valida URL
        if (!$this->isValidUrl($url)) {
            return [
                'success' => false,
                'error' => 'URL inválida ou bloqueada',
                'content' => null
            ];
        }
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            // Limite de tamanho
            CURLOPT_PROGRESSFUNCTION => function($downloadSize, $downloaded) {
                return ($downloaded > $this->maxSize) ? 1 : 0;
            },
            CURLOPT_NOPROGRESS => false
        ]);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => "Erro ao buscar URL: {$error}",
                'content' => null
            ];
        }
        
        if ($httpCode >= 400) {
            return [
                'success' => false,
                'error' => "HTTP Error: {$httpCode}",
                'content' => null
            ];
        }
        
        // Extrai texto se solicitado
        if ($extractText && str_contains($contentType ?? '', 'text/html')) {
            $content = $this->extractTextFromHtml($content);
        }
        
        return [
            'success' => true,
            'url' => $url,
            'content_type' => $contentType,
            'content' => $content,
            'size' => strlen($content)
        ];
    }
    
    /**
     * Valida se URL é segura para acessar
     */
    private function isValidUrl(string $url): bool
    {
        // Valida formato
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Apenas HTTP/HTTPS
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            return false;
        }
        
        // Verifica domínios bloqueados
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        
        foreach ($this->blockedDomains as $blocked) {
            if (str_starts_with($host, $blocked) || $host === $blocked) {
                return false;
            }
        }
        
        // Bloqueia IPs privados
        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Extrai texto de HTML
     */
    private function extractTextFromHtml(string $html): string
    {
        // Remove scripts e estilos
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        
        // Remove tags HTML
        $text = strip_tags($html);
        
        // Decodifica entidades
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        
        // Normaliza espaços em branco
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\n\s*\n/', "\n\n", $text);
        
        return trim($text);
    }
    
    /**
     * Extrai conteúdo principal de uma página (artigo)
     */
    public function extractMainContent(string $html): string
    {
        // Tenta encontrar conteúdo principal por seletores comuns
        $mainSelectors = [
            '/<article[^>]*>(.*?)<\/article>/is',
            '/<main[^>]*>(.*?)<\/main>/is',
            '/<div[^>]*class="[^"]*content[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<div[^>]*id="content"[^>]*>(.*?)<\/div>/is',
            '/<div[^>]*class="[^"]*article[^"]*"[^>]*>(.*?)<\/div>/is'
        ];
        
        foreach ($mainSelectors as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return $this->extractTextFromHtml($matches[1]);
            }
        }
        
        // Fallback para body inteiro
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            return $this->extractTextFromHtml($matches[1]);
        }
        
        return $this->extractTextFromHtml($html);
    }
    
    /**
     * Extrai metadados da página
     */
    public function extractMetadata(string $html): array
    {
        $metadata = [
            'title' => '',
            'description' => '',
            'keywords' => '',
            'author' => ''
        ];
        
        // Título
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $metadata['title'] = html_entity_decode(trim($matches[1]));
        }
        
        // Meta tags
        preg_match_all('/<meta\s+([^>]+)>/i', $html, $metaTags);
        
        foreach ($metaTags[1] as $metaTag) {
            $name = '';
            $content = '';
            
            if (preg_match('/name=["\']([^"\']+)["\']/i', $metaTag, $m)) {
                $name = strtolower($m[1]);
            } elseif (preg_match('/property=["\']([^"\']+)["\']/i', $metaTag, $m)) {
                $name = strtolower($m[1]);
            }
            
            if (preg_match('/content=["\']([^"\']+)["\']/i', $metaTag, $m)) {
                $content = html_entity_decode($m[1]);
            }
            
            if ($name && $content) {
                match($name) {
                    'description', 'og:description' => $metadata['description'] = $metadata['description'] ?: $content,
                    'keywords' => $metadata['keywords'] = $content,
                    'author' => $metadata['author'] = $content,
                    'og:title' => $metadata['title'] = $metadata['title'] ?: $content,
                    default => null
                };
            }
        }
        
        return $metadata;
    }
    
    /**
     * Extrai links da página
     */
    public function extractLinks(string $html, string $baseUrl): array
    {
        $links = [];
        
        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $href = $match[1];
            $text = strip_tags($match[2]);
            
            // Converte URLs relativas
            if (!str_starts_with($href, 'http')) {
                $href = $this->resolveRelativeUrl($baseUrl, $href);
            }
            
            if ($href && filter_var($href, FILTER_VALIDATE_URL)) {
                $links[] = [
                    'url' => $href,
                    'text' => trim($text)
                ];
            }
        }
        
        return $links;
    }
    
    /**
     * Resolve URL relativa
     */
    private function resolveRelativeUrl(string $base, string $relative): string
    {
        if (str_starts_with($relative, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME);
            return "{$scheme}:{$relative}";
        }
        
        if (str_starts_with($relative, '/')) {
            $scheme = parse_url($base, PHP_URL_SCHEME);
            $host = parse_url($base, PHP_URL_HOST);
            $port = parse_url($base, PHP_URL_PORT);
            
            $url = "{$scheme}://{$host}";
            if ($port) {
                $url .= ":{$port}";
            }
            return $url . $relative;
        }
        
        // Relativo ao diretório atual
        $basePath = dirname(parse_url($base, PHP_URL_PATH) ?? '/');
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        
        return "{$scheme}://{$host}" . rtrim($basePath, '/') . '/' . $relative;
    }
}
