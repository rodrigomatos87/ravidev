<?php

declare(strict_types=1);

return [
    'api_key' => env('CLAUDE_API_KEY'),
    'base_url' => 'https://api.anthropic.com/v1',
    'model' => env('CLAUDE_MODEL', 'claude-sonnet-5'),
    'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 16000),
    'temperature' => (float) env('CLAUDE_TEMPERATURE', 0.7),

    // Tarifas para ESTIMAR custo (USD por 1 MILHÃO de tokens) — referência da API da Anthropic.
    // Hoje NÃO há custo real (roda na cota Max), mas computamos para ter noção de gasto caso um dia
    // migremos para a API. 'input' = token de ENVIO (contexto + prompt), 'output' = token de RESPOSTA.
    // Default = Claude Opus 4 (modelo do motor). Ajuste por .env para simular outro modelo.
    // Preço REAL da Anthropic (com cache). cache_write = 1.25× input; cache_read = 0.10× input.
    'pricing' => [
        'input_per_mtok'   => (float) env('CLAUDE_PRICE_INPUT', 15.0),
        'output_per_mtok'  => (float) env('CLAUDE_PRICE_OUTPUT', 75.0),
        'cache_write_mult' => (float) env('CLAUDE_PRICE_CACHE_WRITE_MULT', 1.25),
        'cache_read_mult'  => (float) env('CLAUDE_PRICE_CACHE_READ_MULT', 0.10),
    ],

    // Modelos atuais (jul/2026)
    'models' => [
        'claude-fable-5' => [
            'name' => 'Claude Fable 5',
            'max_tokens' => 32000,
            'context_window' => 1000000,
        ],
        'claude-opus-5-5' => [
            'name' => 'Claude Opus 5.5',
            'max_tokens' => 32000,
            'context_window' => 1000000,
        ],
        'claude-opus-5' => [
            'name' => 'Claude Opus 5',
            'max_tokens' => 32000,
            'context_window' => 1000000,
        ],
        'claude-opus-4-8' => [
            'name' => 'Claude Opus 4.8',
            'max_tokens' => 32000,
            'context_window' => 1000000,
        ],
        'claude-sonnet-5' => [
            'name' => 'Claude Sonnet 5',
            'max_tokens' => 16000,
            'context_window' => 1000000,
        ],
        'claude-sonnet-4-6' => [
            'name' => 'Claude Sonnet 4.6',
            'max_tokens' => 16000,
            'context_window' => 200000,
        ],
        'claude-haiku-4-5-20251001' => [
            'name' => 'Claude Haiku 4.5',
            'max_tokens' => 8096,
            'context_window' => 200000,
        ],
    ],
    
    'agent' => [
        'max_iterations' => (int) env('MAX_AGENT_ITERATIONS', 50),
        'thinking_enabled' => true,
        'streaming' => true,
    ],
    
    'system_prompt' => <<<'PROMPT'
Você é Ravi, um assistente de desenvolvimento de software extremamente capaz. Você foi criado para ajudar desenvolvedores a construir sistemas completos de forma autônoma.

## Suas Capacidades

Você tem acesso às seguintes ferramentas que pode usar livremente:

1. **bash_execute**: Executar comandos no terminal
2. **file_read/file_write/file_list**: Manipular arquivos
3. **web_search**: Pesquisar na web
4. **web_fetch**: Acessar conteúdo de URLs
5. **browser_***: Navegar e interagir com páginas web
6. **python_execute/node_execute**: Executar código
7. **task_complete**: Sinalizar conclusão

## Diretrizes de Comportamento

1. **Seja proativo**: Não pergunte se pode fazer algo - faça.
2. **Pense antes de agir**: Analise a tarefa, planeje os passos, execute.
3. **Mostre progresso**: Use as ferramentas e relate o que está fazendo.
4. **Seja detalhista**: Crie código completo, não apenas snippets.
5. **Trate erros**: Se algo falhar, tente corrigir automaticamente.
6. **Finalize corretamente**: Use task_complete quando terminar.

## Formato de Resposta

Quando executar tarefas:
1. Explique brevemente o que vai fazer
2. Execute as ferramentas necessárias
3. Mostre o progresso
4. Ao concluir, use task_complete com resumo

## Contexto do Projeto

Diretório de trabalho: /workspace
PROMPT,
];
