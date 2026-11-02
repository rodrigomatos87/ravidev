<?php

declare(strict_types=1);

use Ravi\Core\Application;
use Ravi\Core\Session;

if (!function_exists('env')) {
    /**
     * Obtém valor de variável de ambiente
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('config')) {
    /**
     * Obtém valor de configuração usando notação de ponto
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $configs = [];
        
        $parts = explode('.', $key);
        $file = array_shift($parts);
        
        if (!isset($configs[$file])) {
            $path = __DIR__ . "/../../config/{$file}.php";
            if (file_exists($path)) {
                $configs[$file] = require $path;
            } else {
                return $default;
            }
        }
        
        $value = $configs[$file];
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        
        return $value;
    }
}

if (!function_exists('app')) {
    /**
     * Obtém instância da aplicação ou serviço do container
     */
    function app(?string $service = null): mixed
    {
        $app = Application::getInstance();
        
        if ($service === null) {
            return $app;
        }
        
        return $app->get($service);
    }
}

if (!function_exists('view')) {
    /**
     * Renderiza uma view
     */
    function view(string $name, array $data = []): string
    {
        return app('view')->render($name, $data);
    }
}

if (!function_exists('redirect')) {
    /**
     * Redireciona para uma URL
     */
    function redirect(string $url, int $status = 302): never
    {
        header("Location: {$url}", true, $status);
        exit;
    }
}

if (!function_exists('url')) {
    /**
     * Gera URL absoluta
     */
    function url(string $path = ''): string
    {
        $baseUrl = rtrim(config('app.url', ''), '/');
        $path = ltrim($path, '/');
        
        return $path ? "{$baseUrl}/{$path}" : $baseUrl;
    }
}

if (!function_exists('asset')) {
    /**
     * Gera URL para asset
     */
    function asset(string $path): string
    {
        return url("assets/{$path}");
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Obtém token CSRF atual
     */
    function csrf_token(): string
    {
        return Session::get('_csrf_token', '');
    }
}

if (!function_exists('ws_token')) {
    /**
     * Token de autenticação do WebSocket desta sessão. Vai para a página (meta ws-token) no lugar do
     * session_id(): quem rouba o token via XSS consegue falar com o chat, mas não assume a sessão HTTP.
     * Gravado em user_sessions.ws_token pelo AuthMiddleware e pelo login.
     */
    function ws_token(): string
    {
        if (!Session::has('_ws_token')) {
            Session::set('_ws_token', bin2hex(random_bytes(32)));
        }
        return (string) Session::get('_ws_token');
    }
}

if (!function_exists('d2icone')) {
    /** Ícone do sprite do d2 (public/assets/d2/icones/sprite.svg) para as views do painel. */
    function d2icone(string $nome, string $classe = ''): string
    {
        $cls = trim('d2-icone ' . $classe);
        return '<svg class="' . e($cls) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="/assets/d2/icones/sprite.svg#' . e($nome) . '"/></svg>';
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Gera campo hidden com token CSRF
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('e')) {
    /**
     * Escape HTML
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and die
     */
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $var) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }
        exit(1);
    }
}

if (!function_exists('dump')) {
    /**
     * Dump sem die
     */
    function dump(mixed ...$vars): void
    {
        foreach ($vars as $var) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }
    }
}

if (!function_exists('now')) {
    /**
     * Obtém data/hora atual
     */
    function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(config('app.timezone', 'UTC')));
    }
}

if (!function_exists('uuid')) {
    /**
     * Gera UUID v4
     */
    function uuid(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->toString();
    }
}

if (!function_exists('flash')) {
    /**
     * Define ou obtém mensagem flash
     */
    function flash(?string $key = null, mixed $value = null): mixed
    {
        if ($key === null) {
            return Session::getFlash();
        }
        
        if ($value === null) {
            return Session::getFlash($key);
        }
        
        Session::flash($key, $value);
        return null;
    }
}

if (!function_exists('old')) {
    /**
     * Obtém valor antigo de input
     */
    function old(string $key, mixed $default = null): mixed
    {
        return Session::getFlash("_old.{$key}", $default);
    }
}

if (!function_exists('auth')) {
    /**
     * Obtém helper de autenticação
     */
    function auth(): AuthHelper
    {
        return new AuthHelper();
    }
}

/**
 * Helper class para autenticação
 */
class AuthHelper
{
    private ?array $userData;
    
    public function __construct()
    {
        $this->userData = Session::get('user');
    }
    
    public function check(): bool
    {
        return $this->userData !== null;
    }
    
    public function guest(): bool
    {
        return !$this->check();
    }
    
    public function id(): ?int
    {
        return $this->userData['id'] ?? null;
    }
    
    public function user(): ?object
    {
        if (!$this->userData) {
            return null;
        }
        return (object) $this->userData;
    }
    
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->userData[$key] ?? $default;
    }
}

if (!function_exists('isAuthenticated')) {
    /**
     * Verifica se usuário está autenticado
     */
    function isAuthenticated(): bool
    {
        return Session::get('user') !== null;
    }
}

if (!function_exists('formatBytes')) {
    /**
     * Formata bytes para unidade legível
     */
    function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

if (!function_exists('projectIcon')) {
    /**
     * Normaliza o ícone de um projeto: aceita apenas emoji/símbolo curto.
     * Texto ASCII puro (ex.: "folder" vindo de clientes de API) cai no padrão 📁.
     */
    function projectIcon(?string $icon): string
    {
        $icon = trim((string) $icon);
        if ($icon === '' || mb_strlen($icon) > 4 || preg_match('/^[\x20-\x7E]+$/', $icon)) {
            return '📁';
        }
        return $icon;
    }
}

if (!function_exists('formatNumber')) {
    /**
     * Formata número com separadores
     */
    function formatNumber(int|float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals, ',', '.');
    }
}

if (!function_exists('time_ago')) {
    /**
     * Alias snake_case de timeAgo() (usado nas views)
     */
    function time_ago(DateTimeInterface|string $datetime): string
    {
        return timeAgo($datetime);
    }
}

if (!function_exists('timeAgo')) {
    /**
     * Formata data como "tempo atrás"
     */
    function timeAgo(DateTimeInterface|string $datetime): string
    {
        if (is_string($datetime)) {
            $datetime = new DateTimeImmutable($datetime);
        }
        
        $now = now();
        $diff = $now->getTimestamp() - $datetime->getTimestamp();
        
        if ($diff < 60) {
            return 'agora mesmo';
        }
        
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return "{$mins} min atrás";
        }
        
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return "{$hours}h atrás";
        }
        
        if ($diff < 604800) {
            $days = (int) floor($diff / 86400);
            return "{$days}d atrás";
        }
        
        return $datetime->format('d/m/Y');
    }
}

if (!function_exists('json')) {
    /**
     * Encode JSON com flags padrão
     */
    function json(mixed $data, int $flags = 0): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $flags);
    }
}

if (!function_exists('logger')) {
    /**
     * Obtém instância do logger
     */
    function logger(): \Ravi\Core\Logger
    {
        return app('logger');
    }
}

if (!function_exists('isAjaxRequest')) {
    /**
     * Verifica se é uma requisição AJAX
     */
    function isAjaxRequest(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    }
}

if (!function_exists('t')) {
    /** #3 — Traduz uma chave de idioma (i18n). Fallback: $default ou a própria chave. */
    function t(string $key, ?string $default = null, array $repl = []): string
    {
        return \Ravi\Core\Lang::get($key, $default, $repl);
    }
}

if (!function_exists('canViewUsage')) {
    /**
     * #8 — Quem pode ver os valores de uso/custo: admins sempre; membros só se o admin liberou
     * (Configurações → Visibilidade do uso). Usado nas telas que mostram custo estimado.
     */
    function canViewUsage(): bool
    {
        try {
            if ((new \Ravi\Services\UsageService())->flags()['members_see']) {
                return true;
            }
            $uid = auth()->id();
            if (!$uid) {
                return false;
            }
            $u = \Ravi\Models\User::find((int) $uid);
            return $u && $u->isAdmin();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('client_ip')) {
    /**
     * IP REAL do cliente para auditoria/forense. O REMOTE_ADDR (socket TCP) é a
     * verdade: X-Forwarded-For é um header que o PRÓPRIO cliente escolhe, então só
     * é aceito quando a conexão vem de um proxy local (loopback) — que é o único
     * cenário em que alguém confiável o preencheu. Sem isso, um atacante assina a
     * trilha de auditoria com o IP que quiser.
     */
    function client_ip(): ?string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (in_array($remote, ['127.0.0.1', '::1'], true)) {
            $xff = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            if ($xff !== '') {
                $first = trim(explode(',', $xff)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return substr($first, 0, 45);
                }
            }
        }
        return $remote !== '' ? substr($remote, 0, 45) : null;
    }
}

if (!function_exists('websocket_url')) {
    /**
     * URL pública do WebSocket para o navegador.
     * WEBSOCKET_URL no .env vence; sem ela, deriva do APP_URL
     * (https -> wss://<host>/ws pelo proxy do nginx; http -> ws://<host>:<WEBSOCKET_PORT>).
     */
    function websocket_url(): string
    {
        $url = trim((string) config('websocket.url', ''));
        if ($url !== '') {
            return $url;
        }

        $appUrl = (string) config('app.url', 'http://localhost');
        $host   = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';

        if (parse_url($appUrl, PHP_URL_SCHEME) === 'https') {
            return "wss://{$host}/ws";
        }

        return "ws://{$host}:" . (int) config('websocket.port', 8081);
    }
}
