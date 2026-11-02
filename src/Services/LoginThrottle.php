<?php

declare(strict_types=1);

namespace Ravi\Services;

/**
 * Freio de força bruta do login do painel. Conta falhas por par (IP, e-mail) e
 * bloqueia esse par por alguns minutos após muitas seguidas.
 *
 * Decisões:
 * - Chave = IP + e-mail (não só e-mail): um atacante de um IP não consegue trancar
 *   a conta de uma vítima que entra de outro lugar.
 * - FAIL-OPEN: qualquer erro de I/O nunca impede o login (o freio é defesa em
 *   profundidade, não o portão — o painel já é LAN/VPN-only).
 * - Sem dependências: um arquivo por par sob storage/cache/login-throttle, com flock.
 */
class LoginThrottle
{
    private const MAX_FAILS   = 8;    // falhas toleradas dentro da janela
    private const WINDOW_SEC  = 900;  // 15 min: janela em que as falhas somam
    private const LOCK_SEC    = 900;  // 15 min: bloqueio após estourar

    private static function dir(): string
    {
        $base = defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 2) . '/storage';
        return $base . '/cache/login-throttle';
    }

    private static function file(string $email): string
    {
        $key = hash('sha256', (client_ip() ?? '') . '|' . strtolower(trim($email)));
        return self::dir() . '/' . $key;
    }

    /** @return array{fails:int, first:int, until:int} */
    private static function read(string $path): array
    {
        $d = @json_decode((string) @file_get_contents($path), true);
        return is_array($d)
            ? ['fails' => (int) ($d['fails'] ?? 0), 'first' => (int) ($d['first'] ?? 0), 'until' => (int) ($d['until'] ?? 0)]
            : ['fails' => 0, 'first' => 0, 'until' => 0];
    }

    /** Este par (IP, e-mail) está bloqueado agora? */
    public static function blocked(string $email): bool
    {
        try {
            $s = self::read(self::file($email));
            return $s['until'] > time();
        } catch (\Throwable $e) {
            return false; // fail-open
        }
    }

    /** Registra uma falha de login; arma o bloqueio ao estourar MAX_FAILS na janela. */
    public static function fail(string $email): void
    {
        try {
            $dir = self::dir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $path = self::file($email);
            $h = @fopen($path, 'c+');
            if ($h === false) {
                return;
            }
            if (flock($h, LOCK_EX)) {
                $raw = stream_get_contents($h);
                $s = is_string($raw) && $raw !== '' ? self::read($path) : ['fails' => 0, 'first' => 0, 'until' => 0];
                $now = time();
                // Janela expirada → recomeça a contagem.
                if ($s['first'] === 0 || ($now - $s['first']) > self::WINDOW_SEC) {
                    $s = ['fails' => 0, 'first' => $now, 'until' => 0];
                }
                $s['fails']++;
                if ($s['fails'] >= self::MAX_FAILS) {
                    $s['until'] = $now + self::LOCK_SEC;
                }
                ftruncate($h, 0);
                rewind($h);
                fwrite($h, (string) json_encode($s));
                fflush($h);
                flock($h, LOCK_UN);
            }
            fclose($h);
        } catch (\Throwable $e) {
            // fail-open: nunca deixa o freio derrubar um login legítimo.
        }
    }

    /** Zera o freio após um login bem-sucedido. */
    public static function clear(string $email): void
    {
        try {
            @unlink(self::file($email));
        } catch (\Throwable $e) {
        }
    }
}
