<?php

declare(strict_types=1);

namespace Ravi\Core;

/**
 * #3 — i18n simples por arquivos JSON em /lang. O idioma ativo vem de app_settings
 * (system_language, padrão 'pt'). Chaves ausentes no idioma escolhido caem no PT base.
 * Arquivos são JSON (dados, nunca PHP) → admins traduzem e sobem com segurança.
 */
class Lang
{
    private static ?array $strings = null;
    private static string $active = 'pt';

    private static function dir(): string
    {
        return (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/lang';
    }

    /** Código do idioma ativo (ex.: pt, en, es). */
    public static function active(): string
    {
        self::ensure();
        return self::$active;
    }

    /** Traduz uma chave; usa $default (ou a própria chave) se não houver; aplica :placeholders. */
    public static function get(string $key, ?string $default = null, array $repl = []): string
    {
        self::ensure();
        $val = self::$strings[$key] ?? $default ?? $key;
        foreach ($repl as $k => $v) {
            $val = str_replace(':' . $k, (string) $v, $val);
        }
        return $val;
    }

    /** Idiomas disponíveis (nomes dos arquivos .json em /lang). */
    public static function available(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            $out[] = basename($f, '.json');
        }
        return $out ?: ['pt'];
    }

    /** Recarrega (após trocar de idioma na mesma requisição). */
    public static function reload(): void
    {
        self::$strings = null;
        self::ensure();
    }

    private static function ensure(): void
    {
        if (self::$strings !== null) {
            return;
        }
        try {
            self::$active = \Ravi\Models\Setting::get('system_language', 'pt') ?: 'pt';
        } catch (\Throwable $e) {
            self::$active = 'pt';
        }
        self::$active = preg_replace('/[^a-z0-9_-]/i', '', self::$active) ?: 'pt';

        $dir = self::dir();
        $base = $dir . '/pt.json';
        $file = $dir . '/' . self::$active . '.json';

        $data = [];
        if (self::$active !== 'pt' && is_file($base)) {
            $data = json_decode((string) @file_get_contents($base), true) ?: [];
        }
        if (is_file($file)) {
            $data = array_merge($data, json_decode((string) @file_get_contents($file), true) ?: []);
        }
        self::$strings = is_array($data) ? $data : [];
    }
}
