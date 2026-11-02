<?php

declare(strict_types=1);

namespace Ravi\Services;

/**
 * Serviço de busca na web
 */
class WebSearchService
{
    private string $provider;
    private ?string $apiKey;
    
    public function __construct()
    {
        $this->provider = env('SEARCH_PROVIDER', 'duckduckgo');
        $this->apiKey = env('SERPAPI_KEY');
    }
    
    /**
     * Realiza busca na web
     */
    public function search(string $query, int $numResults = 5): array
    {
        $numResults = min(max($numResults, 1), 10);
        
        return match($this->provider) {
            'serpapi' => $this->searchSerpApi($query, $numResults),
            default => $this->searchDuckDuckGo($query, $numResults)
        };
    }
    
    /**
     * Busca via DuckDuckGo (sem API key necessária)
     */
    private function searchDuckDuckGo(string $query, int $numResults): array
    {
        // Usa DuckDuckGo HTML para scraping (API oficial é limitada)
        $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $html = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            logger()->error('DuckDuckGo search failed', ['error' => $error]);
            return [];
        }
        
        return $this->parseDuckDuckGoResults($html, $numResults);
    }
    
    /**
     * Parse dos resultados do DuckDuckGo
     */
    private function parseDuckDuckGoResults(string $html, int $limit): array
    {
        $results = [];
        
        // Extrai resultados usando regex (simples)
        preg_match_all('/<a class="result__a" href="([^"]+)"[^>]*>([^<]+)<\/a>/', $html, $titleMatches, PREG_SET_ORDER);
        preg_match_all('/<a class="result__snippet"[^>]*>(.+?)<\/a>/s', $html, $snippetMatches, PREG_SET_ORDER);
        
        $count = min(count($titleMatches), $limit);
        
        for ($i = 0; $i < $count; $i++) {
            $url = $this->cleanDuckDuckGoUrl($titleMatches[$i][1] ?? '');
            $title = strip_tags($titleMatches[$i][2] ?? '');
            $snippet = strip_tags($snippetMatches[$i][1] ?? '');
            
            if ($url && $title) {
                $results[] = [
                    'title' => html_entity_decode($title, ENT_QUOTES | ENT_HTML5),
                    'url' => $url,
                    'snippet' => html_entity_decode($snippet, ENT_QUOTES | ENT_HTML5)
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Limpa URL redirecionada do DuckDuckGo
     */
    private function cleanDuckDuckGoUrl(string $url): string
    {
        // DuckDuckGo usa URLs de redirecionamento
        if (str_contains($url, 'uddg=')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
            return urldecode($params['uddg'] ?? $url);
        }
        
        return $url;
    }
    
    /**
     * Busca via SerpAPI (requer API key)
     */
    private function searchSerpApi(string $query, int $numResults): array
    {
        if (!$this->apiKey) {
            logger()->warning('SerpAPI key not configured, falling back to DuckDuckGo');
            return $this->searchDuckDuckGo($query, $numResults);
        }
        
        $url = 'https://serpapi.com/search?' . http_build_query([
            'q' => $query,
            'api_key' => $this->apiKey,
            'num' => $numResults,
            'engine' => 'google'
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            logger()->error('SerpAPI search failed', ['error' => $error]);
            return [];
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['organic_results'])) {
            return [];
        }
        
        $results = [];
        foreach (array_slice($data['organic_results'], 0, $numResults) as $item) {
            $results[] = [
                'title' => $item['title'] ?? '',
                'url' => $item['link'] ?? '',
                'snippet' => $item['snippet'] ?? ''
            ];
        }
        
        return $results;
    }
    
    /**
     * Formata resultados para exibição
     */
    public function formatResults(array $results): string
    {
        if (empty($results)) {
            return "Nenhum resultado encontrado.";
        }
        
        $output = "Resultados da busca:\n\n";
        
        foreach ($results as $i => $result) {
            $num = $i + 1;
            $output .= "{$num}. {$result['title']}\n";
            $output .= "   URL: {$result['url']}\n";
            if (!empty($result['snippet'])) {
                $output .= "   {$result['snippet']}\n";
            }
            $output .= "\n";
        }
        
        return $output;
    }
}
