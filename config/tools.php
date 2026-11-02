<?php

declare(strict_types=1);

/**
 * Configuração das ferramentas disponíveis para o agente AI
 * 
 * Cada ferramenta tem:
 * - Classe de implementação
 * - Schema JSON para API da Anthropic
 * 
 * Total: 16 ferramentas
 */

return [
    'enabled' => [
        // Execução de código e sistema
        'bash_execute' => \Ravi\Agent\Tools\BashExecuteTool::class,
        'python_execute' => \Ravi\Agent\Tools\PythonExecuteTool::class,
        'node_execute' => \Ravi\Agent\Tools\NodeExecuteTool::class,
        'system_execute' => \Ravi\Agent\Tools\SystemTool::class,  // Autonomia total
        
        // Manipulação de arquivos
        'file_read' => \Ravi\Agent\Tools\FileReadTool::class,
        'file_write' => \Ravi\Agent\Tools\FileWriteTool::class,
        'file_list' => \Ravi\Agent\Tools\FileListTool::class,
        'extract_project' => \Ravi\Agent\Tools\ExtractProjectTool::class,  // Extrai ZIPs
        
        // Web
        'web_search' => \Ravi\Agent\Tools\WebSearchTool::class,
        'web_fetch' => \Ravi\Agent\Tools\WebFetchTool::class,
        
        // Browser automation (Puppeteer)
        'browser_navigate' => \Ravi\Agent\Tools\BrowserNavigateTool::class,
        'browser_screenshot' => \Ravi\Agent\Tools\BrowserScreenshotTool::class,
        'browser_click' => \Ravi\Agent\Tools\BrowserClickTool::class,
        'browser_type' => \Ravi\Agent\Tools\BrowserTypeTool::class,
        
        // Controle e entrega
        'task_complete' => \Ravi\Agent\Tools\TaskCompleteTool::class,
        'prepare_delivery' => \Ravi\Agent\Tools\PrepareDeliveryTool::class,  // Entrega final
    ],
    
    'schemas' => [
        'bash_execute' => [
            'name' => 'bash_execute',
            'description' => 'Executa comandos bash/shell no ambiente sandbox. Use para instalar pacotes, compilar código, executar scripts, manipular arquivos via linha de comando.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'command' => [
                        'type' => 'string',
                        'description' => 'Comando bash a ser executado',
                    ],
                    'working_directory' => [
                        'type' => 'string',
                        'description' => 'Diretório de trabalho (padrão: /workspace)',
                    ],
                    'timeout' => [
                        'type' => 'integer',
                        'description' => 'Timeout em segundos (padrão: 60, máximo: 300)',
                    ],
                ],
                'required' => ['command'],
            ],
        ],
        
        'system_execute' => [
            'name' => 'system_execute',
            'description' => 'Executa comandos de sistema com AUTONOMIA TOTAL no sandbox. Use para: instalar/desinstalar pacotes (apt, pip, npm, composer), gerenciar serviços, configurar ambiente, compilar código, executar builds, gerenciar permissões. Você tem liberdade total para fazer o que for necessário.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'command' => [
                        'type' => 'string',
                        'description' => 'Comando de sistema a executar',
                    ],
                    'as_root' => [
                        'type' => 'boolean',
                        'description' => 'Executar como root (sudo) - padrão: false',
                    ],
                    'working_dir' => [
                        'type' => 'string',
                        'description' => 'Diretório de trabalho (padrão: /workspace)',
                    ],
                    'timeout' => [
                        'type' => 'integer',
                        'description' => 'Timeout em segundos (padrão: 300, máximo: 600)',
                    ],
                    'env_vars' => [
                        'type' => 'object',
                        'description' => 'Variáveis de ambiente adicionais',
                    ],
                ],
                'required' => ['command'],
            ],
        ],
        
        'python_execute' => [
            'name' => 'python_execute',
            'description' => 'Executa código Python no sandbox. Bibliotecas comuns estão disponíveis (requests, numpy, pandas, etc).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'code' => [
                        'type' => 'string',
                        'description' => 'Código Python a ser executado',
                    ],
                    'timeout' => [
                        'type' => 'integer',
                        'description' => 'Timeout em segundos (padrão: 60)',
                    ],
                ],
                'required' => ['code'],
            ],
        ],
        
        'node_execute' => [
            'name' => 'node_execute',
            'description' => 'Executa código JavaScript/Node.js no sandbox.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'code' => [
                        'type' => 'string',
                        'description' => 'Código JavaScript a ser executado',
                    ],
                    'timeout' => [
                        'type' => 'integer',
                        'description' => 'Timeout em segundos (padrão: 60)',
                    ],
                ],
                'required' => ['code'],
            ],
        ],
        
        'file_read' => [
            'name' => 'file_read',
            'description' => 'Lê o conteúdo de um arquivo no sandbox.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Caminho do arquivo a ser lido',
                    ],
                    'encoding' => [
                        'type' => 'string',
                        'description' => 'Encoding do arquivo (padrão: utf-8)',
                    ],
                ],
                'required' => ['path'],
            ],
        ],
        
        'file_write' => [
            'name' => 'file_write',
            'description' => 'Cria ou sobrescreve um arquivo no sandbox.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Caminho do arquivo a ser criado/escrito',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Conteúdo a ser escrito no arquivo',
                    ],
                    'append' => [
                        'type' => 'boolean',
                        'description' => 'Se true, adiciona ao final do arquivo ao invés de sobrescrever',
                    ],
                ],
                'required' => ['path', 'content'],
            ],
        ],
        
        'file_list' => [
            'name' => 'file_list',
            'description' => 'Lista arquivos e diretórios em um caminho.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Caminho do diretório a ser listado',
                    ],
                    'recursive' => [
                        'type' => 'boolean',
                        'description' => 'Se true, lista recursivamente (padrão: false)',
                    ],
                    'include_hidden' => [
                        'type' => 'boolean',
                        'description' => 'Se true, inclui arquivos ocultos (padrão: false)',
                    ],
                ],
                'required' => ['path'],
            ],
        ],
        
        'extract_project' => [
            'name' => 'extract_project',
            'description' => 'Extrai projeto compactado enviado pelo usuário (ZIP, TAR, TAR.GZ, RAR, 7Z) para o workspace. Útil para receber projetos existentes para modificar ou otimizar.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'source_path' => [
                        'type' => 'string',
                        'description' => 'Caminho do arquivo compactado',
                    ],
                    'destination' => [
                        'type' => 'string',
                        'description' => 'Diretório de destino (padrão: /workspace)',
                    ],
                    'analyze_after' => [
                        'type' => 'boolean',
                        'description' => 'Analisar projeto após extração (padrão: true)',
                    ],
                ],
                'required' => ['source_path'],
            ],
        ],
        
        'web_search' => [
            'name' => 'web_search',
            'description' => 'Busca informações na web via DuckDuckGo. Use para pesquisar documentação, tutoriais, soluções para problemas.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Query de busca',
                    ],
                    'num_results' => [
                        'type' => 'integer',
                        'description' => 'Número de resultados (padrão: 5, máximo: 10)',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
        
        'web_fetch' => [
            'name' => 'web_fetch',
            'description' => 'Faz requisição HTTP GET para uma URL e retorna o conteúdo. Bom para APIs e páginas estáticas.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'URL para fazer fetch',
                    ],
                    'extract_text' => [
                        'type' => 'boolean',
                        'description' => 'Se true, extrai apenas texto removendo HTML (padrão: false)',
                    ],
                ],
                'required' => ['url'],
            ],
        ],
        
        'browser_navigate' => [
            'name' => 'browser_navigate',
            'description' => 'Navega para uma URL usando navegador headless (Puppeteer). Útil para páginas com JavaScript/SPAs.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'URL para navegar',
                    ],
                    'wait_for' => [
                        'type' => 'string',
                        'description' => 'Seletor CSS para aguardar antes de continuar',
                    ],
                    'timeout' => [
                        'type' => 'integer',
                        'description' => 'Timeout em milissegundos (padrão: 30000)',
                    ],
                ],
                'required' => ['url'],
            ],
        ],
        
        'browser_screenshot' => [
            'name' => 'browser_screenshot',
            'description' => 'Captura screenshot de uma página web. Útil para verificar resultado visual de código HTML/CSS.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'URL da página para capturar',
                    ],
                    'full_page' => [
                        'type' => 'boolean',
                        'description' => 'Se true, captura página inteira (padrão: false)',
                    ],
                    'selector' => [
                        'type' => 'string',
                        'description' => 'Seletor CSS de elemento específico para capturar',
                    ],
                    'output_path' => [
                        'type' => 'string',
                        'description' => 'Caminho para salvar o screenshot (padrão: /workspace/screenshot.png)',
                    ],
                ],
                'required' => ['url'],
            ],
        ],
        
        'browser_click' => [
            'name' => 'browser_click',
            'description' => 'Clica em um elemento na página usando navegador headless.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'URL da página',
                    ],
                    'selector' => [
                        'type' => 'string',
                        'description' => 'Seletor CSS do elemento para clicar',
                    ],
                    'wait_for_navigation' => [
                        'type' => 'boolean',
                        'description' => 'Se true, aguarda navegação após clique (padrão: false)',
                    ],
                ],
                'required' => ['url', 'selector'],
            ],
        ],
        
        'browser_type' => [
            'name' => 'browser_type',
            'description' => 'Digita texto em um campo de input/textarea.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'URL da página com o formulário',
                    ],
                    'selector' => [
                        'type' => 'string',
                        'description' => 'Seletor CSS do campo de input',
                    ],
                    'text' => [
                        'type' => 'string',
                        'description' => 'Texto a ser digitado',
                    ],
                    'clear_first' => [
                        'type' => 'boolean',
                        'description' => 'Se true, limpa o campo antes de digitar (padrão: true)',
                    ],
                ],
                'required' => ['url', 'selector', 'text'],
            ],
        ],
        
        'task_complete' => [
            'name' => 'task_complete',
            'description' => 'Sinaliza que a tarefa foi concluída. Use quando terminar de executar tudo que foi solicitado.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'summary' => [
                        'type' => 'string',
                        'description' => 'Resumo do que foi realizado',
                    ],
                    'files_created' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Lista de arquivos criados/modificados',
                    ],
                    'next_steps' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Sugestões de próximos passos para o usuário',
                    ],
                ],
                'required' => ['summary'],
            ],
        ],
        
        'prepare_delivery' => [
            'name' => 'prepare_delivery',
            'description' => 'Prepara o pacote FINAL de entrega para o usuário. Cria: ZIP com projeto completo, README.md, INSTALL.md (guia de instalação), QUICKSTART.md (guia rápido), CHANGELOG.md, LICENSE. USE ESTA FERRAMENTA ao finalizar uma tarefa de desenvolvimento.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'project_name' => [
                        'type' => 'string',
                        'description' => 'Nome do projeto para o arquivo ZIP',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Descrição breve do projeto',
                    ],
                    'features' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Lista de funcionalidades implementadas',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Linguagem principal (php, python, javascript, etc)',
                    ],
                    'framework' => [
                        'type' => 'string',
                        'description' => 'Framework utilizado (se houver)',
                    ],
                    'requirements' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Requisitos de sistema',
                    ],
                    'install_commands' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Comandos de instalação',
                    ],
                    'run_commands' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Comandos para executar o projeto',
                    ],
                    'env_variables' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'default' => ['type' => 'string'],
                                'required' => ['type' => 'boolean'],
                            ],
                        ],
                        'description' => 'Variáveis de ambiente necessárias',
                    ],
                    'additional_notes' => [
                        'type' => 'string',
                        'description' => 'Notas adicionais para o usuário',
                    ],
                ],
                'required' => ['project_name', 'description'],
            ],
        ],
    ],
];
