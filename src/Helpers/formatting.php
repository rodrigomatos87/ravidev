<?php

declare(strict_types=1);

/**
 * Formatting Helper Functions
 */

if (!function_exists('formatTokens')) {
    /**
     * Format token count (e.g., 1500 -> "1.5k")
     */
    function formatTokens(int $tokens): string
    {
        if ($tokens >= 1000000) {
            return round($tokens / 1000000, 1) . 'M';
        }
        if ($tokens >= 1000) {
            return round($tokens / 1000, 1) . 'k';
        }
        return (string) $tokens;
    }
}

if (!function_exists('realApiCost')) {
    /**
     * Custo REAL estimado (USD) com o modelo de preços da Anthropic, considerando cache:
     *   input (cheio) + cache_write (1.25× input) + cache_read (0.10× input) + output (cheio).
     * Tarifas em config('claude.pricing'). É o valor fiel que a API cobraria usando prompt caching.
     */
    function realApiCost(int $input, int $cacheWrite, int $cacheRead, int $output): float
    {
        $in  = (float) (config('claude.pricing.input_per_mtok') ?? 15.0);
        $out = (float) (config('claude.pricing.output_per_mtok') ?? 75.0);
        $cwM = (float) (config('claude.pricing.cache_write_mult') ?? 1.25);
        $crM = (float) (config('claude.pricing.cache_read_mult') ?? 0.10);
        return (
            $input      * $in
            + $cacheWrite * $in * $cwM
            + $cacheRead  * $in * $crM
            + $output     * $out
        ) / 1_000_000.0;
    }
}

if (!function_exists('estimateApiCost')) {
    /**
     * Estima, em USD, quanto teriam custado estes tokens SE usássemos a API da Anthropic.
     * cost = (tokens_entrada × tarifa_entrada + tokens_saida × tarifa_saida) / 1.000.000.
     * As tarifas (por 1M de tokens) vêm de config('claude.pricing'). Computado na exibição,
     * a partir dos tokens guardados — mudar a tarifa atualiza retroativamente todos os valores.
     */
    function estimateApiCost(int $inputTokens, int $outputTokens): float
    {
        $in  = (float) (config('claude.pricing.input_per_mtok') ?? 15.0);
        $out = (float) (config('claude.pricing.output_per_mtok') ?? 75.0);
        return (($inputTokens * $in) + ($outputTokens * $out)) / 1_000_000.0;
    }
}

if (!function_exists('formatCost')) {
    /**
     * Formata um custo ESTIMADO em USD para exibição (ex.: 0.0 -> "$0.00",
     * 0.004 -> "<$0.01", 1.2345 -> "$1.23"). Custos pequenos não somem.
     */
    function formatCost(float $usd): string
    {
        if ($usd <= 0) {
            return '$0.00';
        }
        if ($usd < 0.01) {
            return '<$0.01';
        }
        return '$' . number_format($usd, 2);
    }
}

if (!function_exists('formatCurrency')) {
    /**
     * Format currency value
     */
    function formatCurrency(float $amount, string $currency = 'USD'): string
    {
        $symbols = [
            'USD' => '$',
            'BRL' => 'R$',
            'EUR' => '€',
            'GBP' => '£',
        ];
        
        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($amount, 2, '.', ',');
    }
}

if (!function_exists('formatDate')) {
    /**
     * Format date to localized string
     */
    function formatDate(?string $date, string $format = 'd/m/Y H:i'): string
    {
        if (empty($date)) {
            return '-';
        }
        
        $timestamp = strtotime($date);
        return date($format, $timestamp);
    }
}

if (!function_exists('formatRelativeTime')) {
    /**
     * Format date as relative time (e.g., "2 hours ago")
     */
    function formatRelativeTime(?string $date): string
    {
        if (empty($date)) {
            return '-';
        }
        
        $timestamp = strtotime($date);
        $diff = time() - $timestamp;
        
        if ($diff < 60) {
            return 'agora mesmo';
        }
        
        if ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minuto' . ($minutes > 1 ? 's' : '') . ' atrás';
        }
        
        if ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hora' . ($hours > 1 ? 's' : '') . ' atrás';
        }
        
        if ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' dia' . ($days > 1 ? 's' : '') . ' atrás';
        }
        
        if ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks . ' semana' . ($weeks > 1 ? 's' : '') . ' atrás';
        }
        
        if ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return $months . ' mês' . ($months > 1 ? 'es' : '') . ' atrás';
        }
        
        $years = floor($diff / 31536000);
        return $years . ' ano' . ($years > 1 ? 's' : '') . ' atrás';
    }
}

if (!function_exists('formatDuration')) {
    /**
     * Format duration in seconds to human readable
     */
    function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        
        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return $minutes . 'm ' . $secs . 's';
        }
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}

if (!function_exists('truncate')) {
    /**
     * Truncate string to specified length
     */
    function truncate(string $string, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($string) <= $length) {
            return $string;
        }
        
        return mb_substr($string, 0, $length - mb_strlen($suffix)) . $suffix;
    }
}

if (!function_exists('slugify')) {
    /**
     * Convert string to URL-friendly slug
     */
    function slugify(string $string): string
    {
        // Convert to lowercase
        $string = mb_strtolower($string, 'UTF-8');
        
        // Replace accented characters
        $string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
        
        // Replace non-alphanumeric with hyphens
        $string = preg_replace('/[^a-z0-9]+/', '-', $string);
        
        // Remove leading/trailing hyphens
        return trim($string, '-');
    }
}

if (!function_exists('excerpt')) {
    /**
     * Extract excerpt from text (removes HTML, truncates)
     */
    function excerpt(string $text, int $length = 200): string
    {
        // Remove HTML tags
        $text = strip_tags($text);
        
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        return truncate(trim($text), $length);
    }
}

if (!function_exists('getLanguageFromExtension')) {
    /**
     * Get language for syntax highlighting based on file extension
     */
    function getLanguageFromExtension(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $languages = [
            'php' => 'php',
            'js' => 'javascript',
            'ts' => 'typescript',
            'jsx' => 'jsx',
            'tsx' => 'tsx',
            'py' => 'python',
            'rb' => 'ruby',
            'go' => 'go',
            'rs' => 'rust',
            'java' => 'java',
            'kt' => 'kotlin',
            'swift' => 'swift',
            'c' => 'c',
            'cpp' => 'cpp',
            'h' => 'c',
            'hpp' => 'cpp',
            'cs' => 'csharp',
            'html' => 'html',
            'htm' => 'html',
            'css' => 'css',
            'scss' => 'scss',
            'sass' => 'sass',
            'less' => 'less',
            'json' => 'json',
            'xml' => 'xml',
            'yaml' => 'yaml',
            'yml' => 'yaml',
            'md' => 'markdown',
            'sql' => 'sql',
            'sh' => 'bash',
            'bash' => 'bash',
            'zsh' => 'bash',
            'dockerfile' => 'dockerfile',
            'vue' => 'vue',
            'svelte' => 'svelte',
        ];
        
        return $languages[$extension] ?? 'plaintext';
    }
}

if (!function_exists('maskEmail')) {
    /**
     * Mask email for privacy (e.g., "j***@example.com")
     */
    function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }
        
        $name = $parts[0];
        $domain = $parts[1];
        
        if (strlen($name) <= 2) {
            $masked = $name[0] . '***';
        } else {
            $masked = $name[0] . str_repeat('*', strlen($name) - 2) . $name[strlen($name) - 1];
        }
        
        return $masked . '@' . $domain;
    }
}

if (!function_exists('parse_markdown')) {
    /**
     * Converte um Markdown simples para HTML (seguro: escapa o HTML primeiro).
     * Usado para renderizar mensagens do assistente no servidor.
     */
    function parse_markdown(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }
        // 1. Escapa qualquer HTML (anti-XSS) ANTES de aplicar o markdown.
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // 2. Blocos de código ``` ```
        $text = preg_replace_callback('/```[a-zA-Z0-9]*\n?(.*?)```/s', function ($m) {
            return '<pre><code>' . trim($m[1], "\n") . '</code></pre>';
        }, $text);

        // 3. Código inline `code`
        $text = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $text);
        // 4. Negrito **texto**
        $text = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $text);
        // 5. Itálico *texto*
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text);
        // 6. Links [texto](url) — só http(s)
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);

        // 7. Quebras de linha (fora dos <pre>)
        $parts = preg_split('/(<pre>.*?<\/pre>)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $text = '';
        foreach ($parts as $part) {
            $text .= (strpos($part, '<pre>') === 0) ? $part : nl2br($part);
        }

        return $text;
    }
}

if (!function_exists('get_tool_icon')) {
    /** Ícone (sóbrio) de uma ferramenta — mantido neutro para não poluir. */
    function get_tool_icon(?string $toolName): string
    {
        return '';
    }
}

if (!function_exists('format_tool_name')) {
    /** Nome amigável de uma ferramenta (ex.: "file_write" -> "File write"). */
    function format_tool_name(?string $toolName): string
    {
        $name = trim((string) $toolName);
        if ($name === '') {
            return 'Ferramenta';
        }
        return ucfirst(str_replace(['_', '-'], ' ', $name));
    }
}

if (!function_exists('format_tool_input')) {
    /** Representação curta e segura do input de uma ferramenta. */
    function format_tool_input($input): string
    {
        if (is_array($input)) {
            $input = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $input = (string) $input;
        return htmlspecialchars(mb_substr($input, 0, 1000), ENT_QUOTES, 'UTF-8');
    }
}
