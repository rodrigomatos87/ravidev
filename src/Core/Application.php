<?php

declare(strict_types=1);

namespace Ravi\Core;

use RuntimeException;

/**
 * Container de aplicação e bootstrap
 */
class Application
{
    private static ?self $instance = null;
    
    /** @var array<string, mixed> */
    private array $bindings = [];
    
    /** @var array<string, mixed> */
    private array $instances = [];
    
    private string $basePath;
    
    private function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Application not initialized. Call Application::create() first.');
        }
        
        return self::$instance;
    }
    
    public static function create(string $basePath): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        
        self::$instance = new self($basePath);
        self::$instance->bootstrap();
        
        return self::$instance;
    }
    
    private function bootstrap(): void
    {
        // Carrega variáveis de ambiente
        $this->loadEnvironment();
        
        // Configura error handling
        $this->configureErrorHandling();
        
        // Configura timezone
        date_default_timezone_set(config('app.timezone', 'UTC'));
        
        // Registra bindings padrão
        $this->registerCoreBindings();
        
        // Inicia sessão
        Session::start();
        
        // Gera CSRF token se não existir
        if (!Session::has('_csrf_token')) {
            Session::set('_csrf_token', bin2hex(random_bytes(32)));
        }
    }
    
    private function loadEnvironment(): void
    {
        $envFile = $this->basePath . '/.env';
        
        if (file_exists($envFile)) {
            $dotenv = \Dotenv\Dotenv::createImmutable($this->basePath);
            $dotenv->load();
        }
    }
    
    private function configureErrorHandling(): void
    {
        $debug = env('APP_DEBUG', false);
        
        if ($debug) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }
        
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
    }
    
    public function handleException(\Throwable $e): void
    {
        $this->get('logger')->error($e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
        
        if (env('APP_DEBUG', false)) {
            http_response_code(500);
            echo '<h1>Error</h1>';
            echo '<p><strong>' . e(get_class($e)) . '</strong>: ' . e($e->getMessage()) . '</p>';
            echo '<pre>' . e($e->getTraceAsString()) . '</pre>';
        } else {
            http_response_code(500);
            echo '<h1>500 - Internal Server Error</h1>';
            echo '<p>Ocorreu um erro inesperado. Por favor, tente novamente.</p>';
        }
    }
    
    public function handleError(int $level, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $level)) {
            return false;
        }
        
        throw new \ErrorException($message, 0, $level, $file, $line);
    }
    
    private function registerCoreBindings(): void
    {
        // Database
        $this->singleton('db', function () {
            return Database::getInstance();
        });
        
        // Logger
        $this->singleton('logger', function () {
            return new Logger(config('storage.logs'));
        });
        
        // View
        $this->singleton('view', function () {
            return new View($this->basePath . '/views');
        });
        
        // Router
        $this->singleton('router', function () {
            return new Router();
        });
    }
    
    /**
     * Registra um binding simples
     */
    public function bind(string $abstract, callable $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }
    
    /**
     * Registra um singleton
     */
    public function singleton(string $abstract, callable $concrete): void
    {
        $this->bind($abstract, function () use ($abstract, $concrete) {
            if (!isset($this->instances[$abstract])) {
                $this->instances[$abstract] = $concrete($this);
            }
            
            return $this->instances[$abstract];
        });
    }
    
    /**
     * Resolve um binding
     */
    public function get(string $abstract): mixed
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]($this);
        }
        
        throw new RuntimeException("Binding not found: {$abstract}");
    }
    
    /**
     * Verifica se binding existe
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }
    
    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? '/' . ltrim($path, '/') : '');
    }
    
    public function storagePath(string $path = ''): string
    {
        return $this->basePath('storage' . ($path ? '/' . ltrim($path, '/') : ''));
    }
    
    public function publicPath(string $path = ''): string
    {
        return $this->basePath('public' . ($path ? '/' . ltrim($path, '/') : ''));
    }
    
    /**
     * Executa a aplicação
     */
    public function run(): void
    {
        $request = Request::capture();
        $router = $this->get('router');
        
        // Carrega rotas
        require $this->basePath . '/src/routes.php';
        
        // Dispatch
        $response = $router->dispatch($request);
        $response->send();
    }
}
