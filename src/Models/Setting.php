<?php

declare(strict_types=1);

namespace Ravi\Models;

use Ravi\Core\Database;

/**
 * Store simples de configurações da aplicação (chave/valor), persistido em `app_settings`.
 * Usado pela tela de admin de SMTP. Valores sensíveis (senha SMTP) são gravados já
 * criptografados pela camada que chama set() — aqui é só armazenamento bruto.
 */
class Setting
{
    /** @var array<string,?string>|null cache em memória por request */
    private static ?array $cache = null;

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            $rows = Database::getInstance()->fetchAll('SELECT skey, svalue FROM app_settings');
            foreach ($rows as $r) {
                self::$cache[$r['skey']] = $r['svalue'];
            }
        } catch (\Throwable $e) {
            // Tabela ausente / banco indisponível: trata como vazio (fallback p/ env nos consumidores).
            self::$cache = [];
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $v = self::$cache[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    /** @return array<string,?string> */
    public static function all(): array
    {
        self::load();
        return self::$cache;
    }

    public static function set(string $key, ?string $value): void
    {
        self::load();
        try {
            Database::getInstance()->query(
                'REPLACE INTO app_settings (skey, svalue) VALUES (?, ?)',
                [$key, $value]
            );
            self::$cache[$key] = $value;
        } catch (\Throwable $e) {
            logger()->error('Setting::set falhou para ' . $key . ': ' . $e->getMessage());
        }
    }

    /** Grava vários de uma vez. @param array<string,?string> $pairs */
    public static function setMany(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            self::set($k, $v);
        }
    }
}
