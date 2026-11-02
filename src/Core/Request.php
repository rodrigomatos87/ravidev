<?php

declare(strict_types=1);

namespace Ravi\Core;

/**
 * Abstração de HTTP Request
 */
class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $query;
    private array $post;
    private array $server;
    private array $headers;
    private array $files;
    private array $cookies;
    private ?string $body = null;
    
    public function __construct(
        string $method,
        string $uri,
        array $query = [],
        array $post = [],
        array $server = [],
        array $files = [],
        array $cookies = []
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $this->query = $query;
        $this->post = $post;
        $this->server = $server;
        $this->files = $files;
        $this->cookies = $cookies;
        $this->headers = $this->parseHeaders($server);
    }
    
    /**
     * Captura request atual
     */
    public static function capture(): self
    {
        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_SERVER['REQUEST_URI'] ?? '/',
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
            $_COOKIE
        );
    }
    
    private function parseHeaders(array $server): array
    {
        $headers = [];
        
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $name = str_replace('_', '-', $key);
                $headers[strtolower($name)] = $value;
            }
        }
        
        return $headers;
    }
    
    public function method(): string
    {
        return $this->method;
    }
    
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }
    
    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }
    
    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }
    
    public function isAjax(): bool
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest';
    }
    
    public function expectsJson(): bool
    {
        return str_contains($this->header('accept', ''), 'application/json');
    }
    
    public function isJson(): bool
    {
        return str_contains($this->header('content-type', ''), 'application/json');
    }
    
    public function uri(): string
    {
        return $this->uri;
    }
    
    public function path(): string
    {
        return $this->path;
    }
    
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        
        return $this->query[$key] ?? $default;
    }
    
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->post;
        }
        
        return $this->post[$key] ?? $default;
    }
    
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $all = array_merge($this->query, $this->post);

        // Corpo JSON (Content-Type: application/json): os clientes da API enviam JSON,
        // então mesclamos o corpo decodificado — senão all()/input() não enxergam os campos.
        if ($this->isJson()) {
            $json = $this->json();
            if (is_array($json)) {
                $all = array_merge($all, $json);
            }
        }

        if ($key === null) {
            return $all;
        }

        return $all[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input());
    }
    
    /**
     * Retorna apenas os campos especificados
     */
    public function only(array $keys): array
    {
        $all = $this->input();
        $result = [];
        
        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $result[$key] = $all[$key];
            }
        }
        
        return $result;
    }
    
    /**
     * Alias para query() - obtém parâmetro GET
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->input($key, $default);
    }
    
    /**
     * Retorna todos os inputs (query + post)
     */
    public function all(): array
    {
        return $this->input();
    }
    
    /**
     * Alias para header()
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->header($name, $default);
    }
    
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }
    
    public function headers(): array
    {
        return $this->headers;
    }
    
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }
    
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }
    
    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }
    
    public function server(string $key, ?string $default = null): ?string
    {
        return $this->server[$key] ?? $default;
    }
    
    public function ip(): string
    {
        return client_ip() ?? '127.0.0.1';
    }
    
    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }
    
    public function body(): string
    {
        if ($this->body === null) {
            $this->body = file_get_contents('php://input') ?: '';
        }
        
        return $this->body;
    }
    
    public function json(?string $key = null, mixed $default = null): mixed
    {
        $data = json_decode($this->body(), true) ?? [];
        
        if ($key === null) {
            return $data;
        }
        
        return $data[$key] ?? $default;
    }
    
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        
        return null;
    }
    
    /**
     * Valida inputs usando regras
     * 
     * @param array<string, string> $rules
     * @return array{valid: bool, errors: array<string, string>, data: array}
     */
    public function validate(array $rules): array
    {
        $validator = new Validator($this->input(), $rules);
        
        return [
            'valid' => $validator->passes(),
            'errors' => $validator->errors(),
            'data' => $validator->validated(),
        ];
    }
}