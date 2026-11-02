<?php

declare(strict_types=1);

namespace Ravi\Core;

/**
 * Abstração de HTTP Response
 */
class Response
{
    protected string $content;
    protected int $statusCode;
    protected array $headers = [];
    
    public function __construct(string $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }
    
    public static function make(string $content = '', int $statusCode = 200, array $headers = []): self
    {
        return new self($content, $statusCode, $headers);
    }
    
    public static function view(string $name, array $data = [], int $statusCode = 200): self
    {
        $content = view($name, $data);
        $tipo = (function_exists('janela_pedida') && janela_pedida() && str_starts_with(ltrim($content), '{'))
            ? 'application/json; charset=UTF-8' : 'text/html; charset=UTF-8';
        return new self($content, $statusCode, ['Content-Type' => $tipo]);
    }
    
    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new self('', $statusCode, ['Location' => $url]);
    }
    
    public static function back(): self
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        return self::redirect($referer);
    }
    
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }
    
    public function getContent(): string
    {
        return $this->content;
    }
    
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }
    
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }
    
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }
    
    public function withCookie(
        string $name,
        string $value,
        int $minutes = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true
    ): self {
        $expires = $minutes > 0 ? time() + ($minutes * 60) : 0;
        
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
        
        return $this;
    }
    
    public function send(): void
    {
        // Status code
        http_response_code($this->statusCode);
        
        // Headers de segurança padrão
        $this->sendSecurityHeaders();
        
        // Headers customizados
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        
        // Conteúdo
        echo $this->content;
    }
    
    protected function sendSecurityHeaders(): void
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }
    
    /**
     * Flash mensagem e redireciona
     */
    public function with(string $key, mixed $value): self
    {
        Session::flash($key, $value);
        return $this;
    }
    
    /**
     * Flash erros de validação
     */
    public function withErrors(array $errors): self
    {
        Session::flash('errors', $errors);
        return $this;
    }
    
    /**
     * Flash dados de input
     */
    public function withInput(array $input = []): self
    {
        foreach ($input as $key => $value) {
            Session::flash("_old.{$key}", $value);
        }
        return $this;
    }
}
