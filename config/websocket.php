<?php

declare(strict_types=1);

/**
 * WebSocket do chat (bin/websocket-server.php atrás do proxy /ws do nginx).
 *
 * host/port: onde o servidor ESCUTA (bind). Lidos também direto por bin/websocket-server.php.
 * url: endereço PÚBLICO que o navegador usa para conectar (WEBSOCKET_URL).
 *      Vazio = derivado do APP_URL (https vira wss://<host>/ws; http vira ws://<host>:<port>).
 *      Use websocket_url() em vez de montar a URL na mão.
 */
return [
    'host' => env('WEBSOCKET_HOST', '127.0.0.1'),
    'port' => (int) env('WEBSOCKET_PORT', 8081),
    'url'  => (string) (env('WEBSOCKET_URL', '') ?? ''),
];
