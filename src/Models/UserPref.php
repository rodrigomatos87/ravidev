<?php

declare(strict_types=1);

namespace Ravi\Models;

use Ravi\Core\Database;

/**
 * Preferências por usuário (chave/valor), em `user_prefs`.
 * Usado pelos toggles de notificação. Booleanos: ausência = default (ligado).
 */
class UserPref
{
    /** Chaves de notificação conhecidas e seus rótulos (default = ligado). */
    public const NOTIFY = [
        'notify_task_done'   => 'Tarefa concluída / entrega pronta',
        'notify_task_failed' => 'Falha em uma tarefa',
        'notify_shared'      => 'Projeto compartilhado comigo',
        'notify_new_user'    => 'Novo usuário criado (admin)',
        'notify_ide_long'    => 'IDE: avisar no WhatsApp quando um trabalho longo terminar ou pausar',
    ];

    /** Limite (minutos) padrão do alerta de trabalho longo na IDE. */
    public const IDE_ALERT_DEFAULT_MIN = 10;
    /** Chave (não-booleana) que guarda o limite em minutos por usuário. */
    public const IDE_ALERT_MIN_KEY = 'ide_alert_minutes';

    /** @var array<int,array<string,string>> cache por usuário */
    private static array $cache = [];

    /** @return array<string,string> */
    public static function allFor(int $userId): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }
        $out = [];
        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT pref_key, pref_value FROM user_prefs WHERE user_id = ?',
                [$userId]
            );
            foreach ($rows as $r) {
                $out[$r['pref_key']] = $r['pref_value'];
            }
        } catch (\Throwable $e) {
            // tabela ausente → tudo no default
        }
        return self::$cache[$userId] = $out;
    }

    /** Booleano com default LIGADO (só desliga se gravado explicitamente '0'). */
    public static function enabled(int $userId, string $key, bool $default = true): bool
    {
        $all = self::allFor($userId);
        if (!array_key_exists($key, $all)) {
            return $default;
        }
        return $all[$key] === '1';
    }

    public static function set(int $userId, string $key, bool $on): void
    {
        self::putRaw($userId, $key, $on ? '1' : '0');
    }

    /** Lê um valor cru (não-booleano), com default. */
    public static function getRaw(int $userId, string $key, string $default = ''): string
    {
        $all = self::allFor($userId);
        return array_key_exists($key, $all) ? (string) $all[$key] : $default;
    }

    /** Grava um valor cru (string). Usado por prefs não-booleanas (ex.: minutos). */
    public static function putRaw(int $userId, string $key, string $value): void
    {
        try {
            Database::getInstance()->query(
                'REPLACE INTO user_prefs (user_id, pref_key, pref_value) VALUES (?, ?, ?)',
                [$userId, $key, $value]
            );
            unset(self::$cache[$userId]);
        } catch (\Throwable $e) {
            logger()->error('UserPref::putRaw falhou: ' . $e->getMessage());
        }
    }

    /** Limite (minutos) do alerta de trabalho longo na IDE para o usuário (default 10, faixa 1..240). */
    public static function ideAlertMinutes(int $userId): int
    {
        $v = (int) self::getRaw($userId, self::IDE_ALERT_MIN_KEY, (string) self::IDE_ALERT_DEFAULT_MIN);
        if ($v < 1) { $v = self::IDE_ALERT_DEFAULT_MIN; }
        return min(240, max(1, $v));
    }
}
