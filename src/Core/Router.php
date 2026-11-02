<?php

declare(strict_types=1);

namespace Ravi\Core;

use RuntimeException;

/**
 * Roteador HTTP simples
 */
class Router
{
    /** @var array<string, array<string, array{handler: callable|array, middleware: array}>> */
    private array $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'PATCH' => [],
        'DELETE' => [],
    ];
    
    /** @var array<string> */
    private array $currentMiddleware = [];
    
    private string $currentPrefix = '';
    
    /**
     * Agrupa rotas com prefixo e/ou middleware
     */
    public function group(array $options, callable $callback): void
    {
        $previousPrefix = $this->currentPrefix;
        $previousMiddleware = $this->currentMiddleware;
        
        if (isset($options['prefix']) && $options['prefix'] !== '') {
            $this->currentPrefix .= '/' . trim($options['prefix'], '/');
        }
        
        if (isset($options['middleware'])) {
            $middleware = is_array($options['middleware']) 
                ? $options['middleware'] 
                : [$options['middleware']];
            $this->currentMiddleware = array_merge($this->currentMiddleware, $middleware);
        }
        
        $callback($this);
        
        $this->currentPrefix = $previousPrefix;
        $this->currentMiddleware = $previousMiddleware;
    }
    
    public function get(string $path, callable|array|string $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }
    
    public function post(string $path, callable|array|string $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }
    
    public function put(string $path, callable|array|string $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }
    
    public function patch(string $path, callable|array|string $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }
    
    public function delete(string $path, callable|array|string $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }
    
    /**
     * Registra rotas REST para um resource
     */
    public function resource(string $name, string $controller): void
    {
        $prefix = $this->currentPrefix . '/' . trim($name, '/');
        
        $this->get($prefix, [$controller, 'index']);
        $this->get($prefix . '/create', [$controller, 'create']);
        $this->post($prefix, [$controller, 'store']);
        $this->get($prefix . '/{id}', [$controller, 'show']);
        $this->get($prefix . '/{id}/edit', [$controller, 'edit']);
        $this->put($prefix . '/{id}', [$controller, 'update']);
        $this->delete($prefix . '/{id}', [$controller, 'destroy']);
    }
    
    private function addRoute(string $method, string $path, callable|array|string $handler): self
    {
        // Normaliza o path
        $path = '/' . trim($path, '/');
        
        // Concatena com prefix (se existir)
        if ($this->currentPrefix !== '') {
            $fullPath = rtrim($this->currentPrefix, '/') . $path;
        } else {
            $fullPath = $path;
        }
        
        // Remove barras duplicadas e garante que começa com /
        $fullPath = preg_replace('#/+#', '/', $fullPath);
        $fullPath = '/' . trim($fullPath, '/');
        if ($fullPath === '') $fullPath = '/';
        
        // Converte {param} para regex
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $fullPath);
        $pattern = '#^' . $pattern . '$#';
        
        $this->routes[$method][$pattern] = [
            'handler' => $handler,
            'middleware' => $this->currentMiddleware,
            'path' => $fullPath,
        ];
        
        return $this;
    }
    
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();
        
        // Suporte para _method override
        if ($method === 'POST' && $request->has('_method')) {
            $method = strtoupper($request->input('_method'));
        }
        
        if (!isset($this->routes[$method])) {
            return $this->notFound();
        }
        
        foreach ($this->routes[$method] as $pattern => $route) {
            if (preg_match($pattern, $path, $matches)) {
                // Extrai parâmetros nomeados
                $params = array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);
                
                // Executa middleware
                $response = $this->runMiddleware($route['middleware'], $request);
                if ($response !== null) {
                    return $response;
                }
                
                // Executa handler
                return $this->callHandler($route['handler'], $request, $params);
            }
        }
        
        return $this->notFound();
    }
    
    private function runMiddleware(array $middleware, Request $request): ?Response
    {
        $resolved = $this->resolveMiddleware($middleware);
        
        foreach ($resolved as $class) {
            $instance = new $class();
            $response = $instance->handle($request);
            
            if ($response !== null) {
                return $response;
            }
        }
        
        return null;
    }
    
    private function callHandler(callable|array|string $handler, Request $request, array $params): Response
    {
        // Suporta sintaxe 'Controller@method'
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler);
            $class = 'Ravi\\Controllers\\' . $class;
            $controller = new $class();
            $result = $controller->$method($request, ...array_values($params));
        } elseif (is_array($handler)) {
            [$class, $method] = $handler;
            if (is_string($class)) {
                $class = new $class();
            }
            $result = $class->$method($request, ...array_values($params));
        } else {
            $result = $handler($request, ...array_values($params));
        }
        
        // Se retornou Response, usa direto
        if ($result instanceof Response) {
            return $result;
        }
        
        // Se retornou array, converte para JSON
        if (is_array($result)) {
            return new JsonResponse($result);
        }
        
        // Se retornou string, cria Response HTML
        if (is_string($result)) {
            return new Response($result, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        
        // Null = 204 No Content
        return new Response('', 204);
    }
    
    private function notFound(): Response
    {
        if ($this->fallbackHandler !== null) {
            $result = ($this->fallbackHandler)();
            
            if ($result instanceof Response) {
                return $result;
            }
            
            if (is_string($result)) {
                return new Response($result, 404, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
        }
        
        return new Response(
            '<h1>404 - Not Found</h1><p>A página solicitada não foi encontrada.</p>',
            404,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }
    
    /** @var array<string, string> */
    private array $middlewareAliases = [];
    
    /** @var callable|null */
    private $fallbackHandler = null;
    
    /**
     * Registra um alias para um middleware
     */
    public function registerMiddleware(string $alias, string $class): void
    {
        $this->middlewareAliases[$alias] = $class;
    }
    
    /**
     * Define um handler para rotas não encontradas
     */
    public function fallback(callable $handler): void
    {
        $this->fallbackHandler = $handler;
    }
    
    /**
     * Lista todas as rotas registradas (debug)
     */
    public function getRoutes(): array
    {
        $routes = [];
        
        foreach ($this->routes as $method => $patterns) {
            foreach ($patterns as $route) {
                $routes[] = [
                    'method' => $method,
                    'path' => $route['path'],
                    'middleware' => $route['middleware'],
                ];
            }
        }
        
        return $routes;
    }
    
    /**
     * Resolve middleware aliases para classes
     */
    private function resolveMiddleware(array $middleware): array
    {
        return array_map(function ($m) {
            return $this->middlewareAliases[$m] ?? $m;
        }, $middleware);
    }
}