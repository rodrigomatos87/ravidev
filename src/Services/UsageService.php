<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;
use Ravi\Models\Setting;

/**
 * #2 — Custo estimado de uso por ORIGEM (chat / api / ide), com seletores (admin) para ativar a
 * computação de cada uma e um "zerar contadores" (baseline). O custo é sempre ESTIMADO a partir
 * dos tokens × tarifas de config('claude.pricing') — coerente com o resto do app.
 *
 * Origens:
 *  - chat: usage_ledger (tarefas do chat/Arquiteto).
 *  - api:  api_usage (gateway compatível com a Anthropic).
 *  - ide:  transcripts do Claude Code persistidos (storage/claude-conversations/<uuid>/*.jsonl) —
 *          atribuível só GLOBALMENTE (não por usuário), então entra apenas nas visões globais.
 */
class UsageService
{
    public const KEY_CHAT = 'cost_track_chat';
    public const KEY_API  = 'cost_track_api';
    public const KEY_IDE  = 'cost_track_ide';
    public const KEY_RESET = 'cost_reset_at';
    public const KEY_MEMBERS_SEE = 'usage_visible_members';

    /** Flags atuais (defaults: só API ativo; visível só p/ admin). */
    public function flags(): array
    {
        return [
            'chat' => Setting::get(self::KEY_CHAT, '0') === '1',
            'api'  => Setting::get(self::KEY_API, '1') === '1',
            'ide'  => Setting::get(self::KEY_IDE, '0') === '1',
            'members_see' => Setting::get(self::KEY_MEMBERS_SEE, '0') === '1',
        ];
    }

    /** Baseline de "zerar contadores" (timestamp) ou null. */
    public function resetAt(): ?string
    {
        $v = trim((string) Setting::get(self::KEY_RESET, ''));
        return $v !== '' ? $v : null;
    }

    /** Zera os contadores: tudo passa a contar a partir de agora. */
    public function resetCounters(): void
    {
        Setting::set(self::KEY_RESET, date('Y-m-d H:i:s'));
        // Invalida o cache do custo da IDE para refletir o reset.
        Setting::set('cost_ide_cache_json', null);
    }

    /**
     * Custo estimado por origem desde o baseline. Se $userId for dado, restringe chat/api a esse
     * usuário (a IDE não é atribuível por usuário → 0 no escopo de usuário).
     * @return array{chat:array,api:array,ide:array,enabled:array,total_cost:float,total_tokens:int}
     */
    public function breakdown(?int $userId = null): array
    {
        $f = $this->flags();
        $since = $this->resetAt();

        $chat = $this->chatUsage($userId, $since);
        $api  = $this->apiUsage($userId, $since);
        $ide  = ($userId === null) ? $this->ideUsage($since) : ['tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0];

        $totalCost = 0.0; $totalTokens = 0;
        if ($f['chat']) { $totalCost += $chat['cost']; $totalTokens += $chat['tokens_in'] + $chat['tokens_out']; }
        if ($f['api'])  { $totalCost += $api['cost'];  $totalTokens += $api['tokens_in'] + $api['tokens_out']; }
        if ($f['ide'])  { $totalCost += $ide['cost'];  $totalTokens += $ide['tokens_in'] + $ide['tokens_out']; }

        return [
            'chat' => $chat, 'api' => $api, 'ide' => $ide,
            'enabled' => $f,
            'total_cost' => round($totalCost, 6),
            'total_tokens' => $totalTokens,
        ];
    }

    /** Total de custo das origens ATIVAS (atalho). */
    public function enabledCost(?int $userId = null): float
    {
        return (float) $this->breakdown($userId)['total_cost'];
    }

    private function chatUsage(?int $userId, ?string $since): array
    {
        $where = []; $args = [];
        if ($userId !== null) { $where[] = 'user_id = ?'; $args[] = $userId; }
        if ($since !== null)  { $where[] = 'created_at > ?'; $args[] = $since; }
        $sql = "SELECT COALESCE(SUM(input_tokens + cache_write_tokens + cache_read_tokens),0) AS ti,
                       COALESCE(SUM(output_tokens),0) AS too
                FROM usage_ledger" . ($where ? (' WHERE ' . implode(' AND ', $where)) : '');
        return $this->rowToUsage($sql, $args);
    }

    private function apiUsage(?int $userId, ?string $since): array
    {
        try {
            $where = []; $args = [];
            if ($userId !== null) { $where[] = 'user_id = ?'; $args[] = $userId; }
            if ($since !== null)  { $where[] = 'date >= ?'; $args[] = substr($since, 0, 10); }
            $sql = "SELECT COALESCE(SUM(tokens_input),0) AS ti, COALESCE(SUM(tokens_output),0) AS too
                    FROM api_usage" . ($where ? (' WHERE ' . implode(' AND ', $where)) : '');
            return $this->rowToUsage($sql, $args);
        } catch (\Throwable $e) {
            return ['tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0];
        }
    }

    private function rowToUsage(string $sql, array $args): array
    {
        $r = Database::getInstance()->fetch($sql, $args) ?: ['ti' => 0, 'too' => 0];
        $ti = (int) ($r['ti'] ?? 0); $to = (int) ($r['too'] ?? 0);
        return ['tokens_in' => $ti, 'tokens_out' => $to, 'cost' => estimateApiCost($ti, $to)];
    }

    /**
     * Custo da IDE somando os transcripts do Claude Code persistidos. Caro de parsear → cache de
     * 5 min em app_settings. $since (baseline) filtra por arquivos modificados depois do reset.
     */
    private function ideUsage(?string $since): array
    {
        $cacheAt = (int) Setting::get('cost_ide_cache_at', '0');
        $cached = Setting::get('cost_ide_cache_json', null);
        if ($cached !== null && (time() - $cacheAt) < 300) {
            $d = json_decode((string) $cached, true);
            if (is_array($d)) {
                return ['tokens_in' => (int) ($d['ti'] ?? 0), 'tokens_out' => (int) ($d['to'] ?? 0), 'cost' => estimateApiCost((int) ($d['ti'] ?? 0), (int) ($d['to'] ?? 0))];
            }
        }
        $base = config('sandbox')['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
        $dir = rtrim(dirname(rtrim((string) $base, '/')), '/') . '/claude-conversations';
        $sinceTs = $since !== null ? strtotime($since) : 0;
        $ti = 0; $to = 0;
        foreach (glob($dir . '/*/*.jsonl') ?: [] as $f) {
            if ($sinceTs > 0 && (int) @filemtime($f) < $sinceTs) {
                continue;
            }
            $fh = @fopen($f, 'r');
            if (!$fh) { continue; }
            while (($line = fgets($fh)) !== false) {
                if (strpos($line, '"usage"') === false) { continue; }
                $j = json_decode($line, true);
                if (!is_array($j)) { continue; }
                $u = $j['message']['usage'] ?? $j['usage'] ?? null;
                if (!is_array($u)) { continue; }
                $ti += (int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0);
                $to += (int) ($u['output_tokens'] ?? 0);
            }
            fclose($fh);
        }
        Setting::setMany(['cost_ide_cache_at' => (string) time()]);
        Setting::set('cost_ide_cache_json', json_encode(['ti' => $ti, 'to' => $to]));
        return ['tokens_in' => $ti, 'tokens_out' => $to, 'cost' => estimateApiCost($ti, $to)];
    }
}
