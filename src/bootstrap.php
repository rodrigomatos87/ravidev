<?php

declare(strict_types=1);

/**
 * Ravi Developer - Application Bootstrap
 * 
 * This file initializes all application components and sets up the environment.
 */

// ============================================================================
// ERROR REPORTING AND DISPLAY
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ============================================================================
// CONSTANTS
// ============================================================================

define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('VIEWS_PATH', ROOT_PATH . '/views');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('RAVI_VERSION', '1.0.0');
define('RAVI_START_TIME', microtime(true));

// ============================================================================
// AUTOLOADER
// ============================================================================

require ROOT_PATH . '/vendor/autoload.php';

// ============================================================================
// ENVIRONMENT VARIABLES
// ============================================================================

$dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
$dotenv->safeLoad();

// Validação das variáveis obrigatórias. Falta = recusa a subida com mensagem clara
// (no log e, em CLI, no STDERR), nunca stack trace para o visitante.
// Os front controllers isolados (gateway-public, modulos/vpn/public, public/ide-auth.php)
// e os scripts de bin/ que não passam por aqui carregam o .env por conta própria; para
// eles a chave é validada quando Encryption é usada (Encryption::chaveDoAmbiente()).
(static function (): void {
    $faltando = [];
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME'] as $obrigatoria) {
        $valor = $_ENV[$obrigatoria] ?? $_SERVER[$obrigatoria] ?? getenv($obrigatoria);
        if (!is_string($valor) || trim($valor) === '') {
            $faltando[] = $obrigatoria;
        }
    }
    // DB_PASSWORD pode ser vazia (dev com usuário sem senha).

    $erros = $faltando ? ['Variáveis obrigatórias ausentes no .env: ' . implode(', ', $faltando) . '.'] : [];
    try {
        \Ravi\Core\Encryption::chaveDoAmbiente();
    } catch (\RuntimeException $e) {
        $erros[] = $e->getMessage();
    }

    if (!$erros) {
        return;
    }

    $mensagem = 'Configuração inválida do RaviDeveloper: ' . implode(' ', $erros);
    error_log('[bootstrap] ' . $mensagem);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $mensagem . PHP_EOL);
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $debug = in_array(strtolower((string) ($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '')), ['true', '1'], true);
    echo '<!doctype html><meta charset="utf-8"><title>Serviço indisponível</title>'
        . '<h1>Serviço temporariamente indisponível</h1>'
        . '<p>O sistema não pôde iniciar por um problema de configuração do servidor. '
        . 'O administrador já tem o detalhe no log.</p>';
    if ($debug) {
        echo '<p><strong>' . htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') . '</strong></p>';
    }
    exit(1);
})();

// Set error display based on environment
if (env('APP_DEBUG', false)) {
    ini_set('display_errors', '1');
}

// ============================================================================
// TIMEZONE
// ============================================================================

date_default_timezone_set(env('APP_TIMEZONE', 'America/Sao_Paulo'));

// ============================================================================
// ERROR AND EXCEPTION HANDLERS
// ============================================================================

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) {
    // Log the exception
    $logger = \Ravi\Core\Logger::getInstance();
    $logger->error($e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Display error page
    if (env('APP_DEBUG', false)) {
        // Show detailed error in debug mode
        echo '<h1>Error</h1>';
        echo '<p><strong>' . htmlspecialchars($e->getMessage()) . '</strong></p>';
        echo '<p>File: ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    } else {
        // Show generic error page in production
        http_response_code(500);
        if (file_exists(VIEWS_PATH . '/errors/500.php')) {
            include VIEWS_PATH . '/errors/500.php';
        } else {
            echo '<h1>500 - Internal Server Error</h1>';
            echo '<p>Something went wrong. Please try again later.</p>';
        }
    }
    exit(1);
});

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

require_once SRC_PATH . '/Helpers/helpers.php';
require_once SRC_PATH . '/Helpers/uuid.php';
require_once SRC_PATH . '/Helpers/formatting.php';

// ============================================================================
// MÓDULOS OPCIONAIS (modulos/<nome>/modulo.php) — ausentes = sistema sobe sem eles
// ============================================================================

\Ravi\Core\Modulos::carregar(ROOT_PATH . '/modulos');

// ============================================================================
// CONFIGURATION
// ============================================================================

$config = [];

// Load configuration files
$configFiles = ['app', 'database', 'claude', 'sandbox', 'tools', 'github', 'urls', 'websocket'];
foreach ($configFiles as $file) {
    $configPath = CONFIG_PATH . '/' . $file . '.php';
    if (file_exists($configPath)) {
        $config[$file] = require $configPath;
    }
}

// Make config globally available
$GLOBALS['config'] = $config;

// ============================================================================
// SESSION
// ============================================================================

// Configure session
$sessionConfig = [
    'name' => 'ravi_session',
    'lifetime' => 7200, // 2 hours
    'path' => '/',
    'domain' => '',
    'secure' => env('APP_ENV') === 'production',
    'httponly' => true,
    'samesite' => 'Lax'
];

// Set session save path
$sessionPath = STORAGE_PATH . '/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}
ini_set('session.save_path', $sessionPath);

// Configure session cookie
session_set_cookie_params([
    'lifetime' => $sessionConfig['lifetime'],
    'path' => $sessionConfig['path'],
    'domain' => $sessionConfig['domain'],
    'secure' => $sessionConfig['secure'],
    'httponly' => $sessionConfig['httponly'],
    'samesite' => $sessionConfig['samesite']
]);

session_name($sessionConfig['name']);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Regenerate session ID periodically for security
if (!isset($_SESSION['_last_regeneration'])) {
    $_SESSION['_last_regeneration'] = time();
} elseif (time() - $_SESSION['_last_regeneration'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['_last_regeneration'] = time();
}

// ============================================================================
// SECURITY HEADERS
// ============================================================================

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // CSP - Allow inline styles/scripts for the app to work
    $csp = "default-src 'self'; ";
    $csp .= "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com; ";
    $csp .= "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; ";
    $csp .= "font-src 'self' https://fonts.gstatic.com; ";
    $csp .= "img-src 'self' data: https:; ";
    $csp .= "connect-src 'self' ws: wss:;";
    header('Content-Security-Policy: ' . $csp);
}

// ============================================================================
// DATABASE CONNECTION
// ============================================================================

try {
    $db = \Ravi\Core\Database::getInstance();
    
    // Test connection
    if (env('APP_DEBUG', false)) {
        $db->query('SELECT 1');
    }
} catch (\Exception $e) {
    if (env('APP_DEBUG', false)) {
        throw $e;
    }
    
    // Log and show friendly error
    $logger = \Ravi\Core\Logger::getInstance();
    $logger->error('Database connection failed: ' . $e->getMessage());
    
    http_response_code(503);
    echo '<h1>503 - Service Unavailable</h1>';
    echo '<p>Database connection failed. Please try again later.</p>';
    exit(1);
}

// ============================================================================
// LOGGER SETUP
// ============================================================================

// Set log file path
$logPath = STORAGE_PATH . '/logs';
if (!is_dir($logPath)) {
    mkdir($logPath, 0755, true);
}

$logger = \Ravi\Core\Logger::getInstance();
$logger->setLogPath($logPath . '/app.log');
$logger->setLevel(env('LOG_LEVEL', 'debug'));

// ============================================================================
// CSRF PROTECTION
// ============================================================================

// Semeia o token CSRF na chave REAL usada por csrf_token()/csrf_field()/CsrfMiddleware
// (antes semeava 'csrf_token' sem underscore — chave morta — deixando o token vazio
//  nas páginas que não passam pelo middleware /api, quebrando o CSRF nos forms web).
if (!isset($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================================================
// APPLICATION CONTAINER
// ============================================================================

$app = \Ravi\Core\Application::create(ROOT_PATH);

// Register core services
$app->bind('config', function() use ($config) {
    return $config;
});

$app->bind('db', function() {
    return \Ravi\Core\Database::getInstance();
});

$app->bind('logger', function() {
    return \Ravi\Core\Logger::getInstance();
});

$app->bind('session', function() {
    return new \Ravi\Core\Session();
});

$app->bind('encryption', function() {
    return new \Ravi\Core\Encryption();
});

$app->bind('validator', function() {
    return new \Ravi\Core\Validator([], []);
});

// Register services
$app->bind('claude', function() use ($app) {
    return new \Ravi\Services\ClaudeService();
});

$app->bind('sandbox', function() {
    return new \Ravi\Services\SandboxService();
});

$app->bind('agent', function() use ($app) {
    return new \Ravi\Services\AgentService(
        $app->get('claude'),
        $app->get('sandbox'),
        \Ravi\Agent\ToolRegistry::getInstance()
    );
});

$app->bind('fileManager', function() {
    return new \Ravi\Services\FileManagerService();
});

$app->bind('webSearch', function() {
    return new \Ravi\Services\WebSearchService();
});

$app->bind('webFetch', function() {
    return new \Ravi\Services\WebFetchService();
});

// ============================================================================
// ROUTER SETUP
// ============================================================================

$router = new \Ravi\Core\Router();

// Register middleware
$router->registerMiddleware('auth', \Ravi\Middleware\AuthMiddleware::class);
$router->registerMiddleware('csrf', \Ravi\Middleware\CsrfMiddleware::class);
$router->registerMiddleware('admin', \Ravi\Middleware\AdminMiddleware::class);

// Load routes
require SRC_PATH . '/routes.php';

// Store router in app container
$app->bind('router', function() use ($router) {
    return $router;
});

// ============================================================================
// RETURN APPLICATION INSTANCE
// ============================================================================

return $app;
