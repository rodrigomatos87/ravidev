#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ravi Developer - WebSocket Server
 * 
 * This script starts the WebSocket server for real-time communication.
 * 
 * Usage:
 *   php bin/websocket-server.php
 *   php bin/websocket-server.php --host=0.0.0.0 --port=8081
 */

// ============================================================================
// BOOTSTRAP
// ============================================================================

require __DIR__ . '/../vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ravi\WebSocket\WebSocketServer;
use Ravi\WebSocket\ConnectionManager;

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// ============================================================================
// CONFIGURATION
// ============================================================================

// Parse command line arguments
$options = getopt('', ['host:', 'port:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Ravi Developer WebSocket Server

Usage:
  php bin/websocket-server.php [options]

Options:
  --host=HOST    Host to bind to (default: 0.0.0.0)
  --port=PORT    Port to listen on (default: 8081)
  --help         Show this help message

Examples:
  php bin/websocket-server.php
  php bin/websocket-server.php --port=8082
  php bin/websocket-server.php --host=127.0.0.1 --port=8081

HELP;
    exit(0);
}

$host = $options['host'] ?? $_ENV['WEBSOCKET_HOST'] ?? '0.0.0.0';
$port = (int) ($options['port'] ?? $_ENV['WEBSOCKET_PORT'] ?? 8081);

// ============================================================================
// CONSTANTS
// ============================================================================

define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// ============================================================================
// LOAD HELPERS
// ============================================================================

require_once SRC_PATH . '/Helpers/helpers.php';
require_once SRC_PATH . '/Helpers/uuid.php';
require_once SRC_PATH . '/Helpers/formatting.php';

// ============================================================================
// TIMEZONE
// ============================================================================

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

// ============================================================================
// LOGGING
// ============================================================================

// Set up logging directory
$logPath = STORAGE_PATH . '/logs';
if (!is_dir($logPath)) {
    mkdir($logPath, 0755, true);
}

// Simple console logger function
function wsLog(string $level, string $message, array $context = []): void
{
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = empty($context) ? '' : ' ' . json_encode($context);
    $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";
    
    echo $line;
    
    // Also write to file
    $logFile = STORAGE_PATH . '/logs/websocket.log';
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// ============================================================================
// SIGNAL HANDLERS
// ============================================================================

// Handle graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () {
        wsLog('info', 'Received SIGTERM, shutting down gracefully...');
        exit(0);
    });
    
    pcntl_signal(SIGINT, function () {
        wsLog('info', 'Received SIGINT, shutting down gracefully...');
        exit(0);
    });
}

// ============================================================================
// BANNER
// ============================================================================

echo <<<BANNER

  ╔═══════════════════════════════════════════════════════════════╗
  ║                                                               ║
  ║   🌟  RAVI DEVELOPER - WebSocket Server                       ║
  ║                                                               ║
  ║   Illuminating the path of development                        ║
  ║                                                               ║
  ╚═══════════════════════════════════════════════════════════════╝

BANNER;

wsLog('info', 'Starting WebSocket server...', [
    'host' => $host,
    'port' => $port
]);

// ============================================================================
// DATABASE CONNECTION
// ============================================================================

try {
    $db = \Ravi\Core\Database::getInstance();
    wsLog('info', 'Database connection established');
} catch (\Exception $e) {
    wsLog('error', 'Database connection failed', ['error' => $e->getMessage()]);
    exit(1);
}

// ============================================================================
// SERVICES INITIALIZATION
// ============================================================================

try {
    // Initialize connection manager
    $connectionManager = new ConnectionManager();
    wsLog('info', 'Connection manager initialized');
    
    // Initialize WebSocket handler
    $webSocketServer = new WebSocketServer($connectionManager);
    wsLog('info', 'WebSocket handler initialized');
    
} catch (\Exception $e) {
    wsLog('error', 'Failed to initialize services', ['error' => $e->getMessage()]);
    exit(1);
}

// ============================================================================
// START SERVER
// ============================================================================

try {
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                $webSocketServer
            )
        ),
        $port,
        $host
    );
    
    // Disponibiliza o event loop ao handler (necessário p/ o terminal web via addReadStream
    // e p/ o futureTick do agente). Sem isto, o terminal fica "indisponível (loop ausente)".
    $webSocketServer->setLoop($server->loop);

    wsLog('info', "WebSocket server running on ws://{$host}:{$port}");
    wsLog('info', 'Press Ctrl+C to stop');
    echo "\n";

    // Timer: entrega os eventos do agente (gravados pelo queue-worker em agent_events)
    // aos clientes conectados, sem bloquear o event loop.
    $server->loop->addPeriodicTimer(0.4, function () use ($webSocketServer) {
        $webSocketServer->drainAgentEvents();
    });
    // Tempo do Dev autonomo: 1x por segundo, conta e persiste o tempo das conversas em andamento.
    $server->loop->addPeriodicTimer(1.0, function () use ($webSocketServer) {
        $webSocketServer->tickWorkSeconds();
    });

    // Run the server
    $server->run();
    
} catch (\Exception $e) {
    wsLog('error', 'Failed to start server', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit(1);
}
