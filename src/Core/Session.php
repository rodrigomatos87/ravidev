<?php

declare(strict_types=1);

namespace Ravi\Core;

/**
 * Gerenciamento de sessão
 */
class Session
{
    private static bool $started = false;
    
    /**
     * Inicia sessão
     */
    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        
        $config = config('app.session', []);
        
        // Configurações de segurança
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', $config['http_only'] ?? true ? '1' : '0');
        ini_set('session.cookie_secure', $config['secure'] ?? false ? '1' : '0');
        ini_set('session.cookie_samesite', $config['same_site'] ?? 'Lax');
        ini_set('session.gc_maxlifetime', (string)(($config['lifetime'] ?? 120) * 60));
        
        // Caminho de armazenamento
        $savePath = config('storage.sessions');
        if ($savePath && is_dir($savePath)) {
            session_save_path($savePath);
        }
        
        session_start();
        self::$started = true;
    }
    
    /**
     * Obtém valor da sessão
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Define valor na sessão
     */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }
    
    /**
     * Verifica se chave existe
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }
    
    /**
     * Remove valor da sessão
     */
    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }
    
    /**
     * Limpa toda a sessão
     */
    public static function clear(): void
    {
        $_SESSION = [];
    }
    
    /**
     * Regenera ID da sessão
     */
    public static function regenerate(bool $deleteOld = true): bool
    {
        return session_regenerate_id($deleteOld);
    }
    
    /**
     * Destrói sessão
     */
    public static function destroy(): void
    {
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        session_destroy();
        self::$started = false;
    }
    
    /**
     * Define mensagem flash (disponível apenas na próxima request)
     */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }
    
    /**
     * Obtém mensagem flash
     */
    public static function getFlash(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_SESSION['_flash'] ?? [];
        }
        
        return $_SESSION['_flash'][$key] ?? $default;
    }
    
    /**
     * Limpa mensagens flash
     */
    public static function clearFlash(): void
    {
        unset($_SESSION['_flash']);
    }
    
    /**
     * Obtém ID da sessão
     */
    public static function id(): string
    {
        return session_id();
    }
    
    /**
     * Pull: obtém e remove valor
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::remove($key);
        
        return $value;
    }
    
    /**
     * Push: adiciona valor a um array na sessão
     */
    public static function push(string $key, mixed $value): void
    {
        $array = self::get($key, []);
        $array[] = $value;
        self::set($key, $array);
    }
    
    /**
     * Incrementa valor numérico
     */
    public static function increment(string $key, int $amount = 1): int
    {
        $value = (int) self::get($key, 0) + $amount;
        self::set($key, $value);
        
        return $value;
    }
    
    /**
     * Decrementa valor numérico
     */
    public static function decrement(string $key, int $amount = 1): int
    {
        return self::increment($key, -$amount);
    }
}
