<?php

declare(strict_types=1);

namespace Ravi\Agent\Tools;

use Ravi\Services\WebSearchService;

/**
 * Ferramenta para buscar na web
 */
class WebSearchTool extends BaseTool
{
    private WebSearchService $searchService;
    
    public function __construct()
    {
        $this->searchService = new WebSearchService();
    }
    
    public function getName(): string
    {
        return 'web_search';
    }
    
    public function getDescription(): string
    {
        return 'Busca informações na web. Use para pesquisar documentação, tutoriais, soluções para problemas, informações atualizadas.';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Query de busca'
                ],
                'num_results' => [
                    'type' => 'integer',
                    'description' => 'Número de resultados (padrão: 5, máximo: 10)'
                ]
            ],
            'required' => ['query']
        ];
    }
    
    public function execute(array $input): string
    {
        $query = $input['query'];
        $numResults = min($input['num_results'] ?? 5, 10);
        
        try {
            $results = $this->searchService->search($query, $numResults);
            return $this->searchService->formatResults($results);
        } catch (\Exception $e) {
            return $this->error('Erro na busca', $e->getMessage());
        }
    }
}
