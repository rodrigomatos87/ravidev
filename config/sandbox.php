<?php

declare(strict_types=1);

return [
    'image' => env('SANDBOX_IMAGE', 'ravi-sandbox:latest'),
    // Imagem para projetos MOBILE (type=app/both): RN/Expo + Android SDK por cima da base.
    // Projetos web (default) continuam na imagem padrão acima — caminho web inalterado.
    'image_mobile' => env('SANDBOX_IMAGE_MOBILE', 'ravi-sandbox-mobile:latest'),
    'timeout' => (int) env('SANDBOX_TIMEOUT', 1800),
    'memory_limit' => env('SANDBOX_MEMORY_LIMIT', '2g'),
    'cpu_limit' => env('SANDBOX_CPU_LIMIT', '2'),
    
    'network' => [
        'enabled' => false, // Sandbox isolado por padrão
        'allowed_hosts' => [], // Hosts permitidos quando network habilitada
    ],
    
    'workspace' => [
        'base_path' => __DIR__ . '/../storage/sandbox',
        'mount_path' => '/workspace',
    ],
    
    'limits' => [
        'max_file_size' => 50 * 1024 * 1024, // 50MB
        'max_files' => 1000,
        'max_output_size' => 1024 * 1024, // 1MB
    ],
    
    'cleanup' => [
        'idle_timeout' => 3600, // 1 hora
        'max_containers' => 10,
    ],
    
    'allowed_commands' => [
        // Lista de comandos permitidos no bash (vazio = todos permitidos)
    ],
    
    'blocked_commands' => [
        'rm -rf /',
        'rm -rf /*',
        'mkfs',
        'dd if=/dev/zero',
        ':(){:|:&};:',
    ],
];
