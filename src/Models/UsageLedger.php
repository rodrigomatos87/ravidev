<?php

declare(strict_types=1);

namespace Ravi\Models;

use Ravi\Core\Database;

/**
 * Histórico (ledger) de uso de tokens/custo — uma linha por TAREFA processada.
 *
 * Atribui o consumo ao USUÁRIO QUE EXECUTOU a tarefa (não ao dono do projeto), o que é o que
 * importa quando um projeto é compartilhado entre vários usuários (ver gestão de equipe).
 * Guarda o detalhamento de tokens (input/cache_write/cache_read/output) e o custo REAL estimado,
 * para relatórios por usuário, por projeto e por período.
 *
 * Tabela criada por database/migrations/002_usage_ledger.sql (CREATE TABLE IF NOT EXISTS).
 */
class UsageLedger
{
    /** Registra uma linha de uso. Tolerante a falha (nunca derruba a tarefa). */
    public static function record(
        int $userId,
        ?int $projectId,
        ?int $conversationId,
        ?string $model,
        int $input,
        int $cacheWrite,
        int $cacheRead,
        int $output,
        float $cost
    ): void {
        try {
            Database::getInstance()->query(
                "INSERT INTO usage_ledger
                    (user_id, project_id, conversation_id, model,
                     input_tokens, cache_write_tokens, cache_read_tokens, output_tokens, cost_usd, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$userId, $projectId, $conversationId, $model,
                 $input, $cacheWrite, $cacheRead, $output, round($cost, 6)]
            );
        } catch (\Throwable $e) {
            // Histórico é best-effort.
        }
    }

    /**
     * Baseline do "zerar contadores" (Configurações). Quando setado, os totais por usuário contam
     * só o que veio DEPOIS do reset (não apaga o histórico). Mesma chave usada pelo UsageService.
     */
    private static function resetBaseline(): string
    {
        try {
            return trim((string) Setting::get('cost_reset_at', ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Totais de uso de UM usuário (tokens enviados/recebidos e custo real), desde o último "zerar"
     * (ou todo o histórico, se nunca zerado) — incluindo projetos compartilhados onde ele executou.
     *
     * @return array{input:int,output:int,tokens:int,cost:float}
     */
    public static function totalsForUser(int $userId): array
    {
        $since = self::resetBaseline();
        $sql = "SELECT COALESCE(SUM(input_tokens + cache_write_tokens + cache_read_tokens), 0) AS input,
                       COALESCE(SUM(output_tokens), 0) AS output,
                       COALESCE(SUM(cost_usd), 0)      AS cost
                FROM usage_ledger WHERE user_id = ?";
        $params = [$userId];
        if ($since !== '') {
            $sql .= " AND created_at >= ?";
            $params[] = $since;
        }
        $row = Database::getInstance()->fetch($sql, $params) ?: [];
        $in  = (int) ($row['input'] ?? 0);
        $out = (int) ($row['output'] ?? 0);
        return ['input' => $in, 'output' => $out, 'tokens' => $in + $out, 'cost' => round((float) ($row['cost'] ?? 0), 6)];
    }

    /**
     * Resumo de uso por usuário (para o painel de admin): id, tokens, custo. Respeita o baseline
     * do "zerar contadores" (conta só desde o último reset).
     * @return array<int,array{user_id:int,input:int,output:int,tokens:int,cost:float}>
     */
    public static function summaryByUser(): array
    {
        $since = self::resetBaseline();
        $where = '';
        $params = [];
        if ($since !== '') {
            $where = "WHERE created_at >= ?";
            $params[] = $since;
        }
        $rows = Database::getInstance()->fetchAll(
            "SELECT user_id,
                    COALESCE(SUM(input_tokens + cache_write_tokens + cache_read_tokens), 0) AS input,
                    COALESCE(SUM(output_tokens), 0) AS output,
                    COALESCE(SUM(cost_usd), 0)      AS cost
             FROM usage_ledger $where GROUP BY user_id",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $in = (int) $r['input']; $ou = (int) $r['output'];
            $out[(int) $r['user_id']] = [
                'user_id' => (int) $r['user_id'],
                'input'   => $in,
                'output'  => $ou,
                'tokens'  => $in + $ou,
                'cost'    => round((float) $r['cost'], 6),
            ];
        }
        return $out;
    }
}
