<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Ravi Developer'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'key' => env('APP_KEY'),
    
    'timezone' => 'America/Sao_Paulo',
    'locale' => 'pt_BR',
    
    'session' => [
        'lifetime' => 120, // minutos
        'secure' => env('APP_ENV') === 'production',
        'http_only' => true,
        'same_site' => 'lax',
    ],
    
    'security' => [
        'password_cost' => 12,
        'csrf_token_lifetime' => 3600,
    ],
    
    'storage' => [
        'logs' => __DIR__ . '/../storage/logs',
        'cache' => __DIR__ . '/../storage/cache',
        'sessions' => __DIR__ . '/../storage/sessions',
        'sandbox' => __DIR__ . '/../storage/sandbox',
        'uploads' => __DIR__ . '/../storage/uploads',
    ],
];
