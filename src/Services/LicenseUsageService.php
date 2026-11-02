<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Models\Setting;

/**
 * Uso do plano (assinatura Max) por licença, para os gráficos da tela Admin → Licenças.
 *
 * De onde vem o dado: a Messages API da Anthropic, quando autenticada com o token OAuth da
 * assinatura, devolve em TODA resposta os headers `anthropic-ratelimit-unified-*` com a
 * utilização das janelas do plano (0.0–1.0), horário de reset e status:
 *   - unified-5h-utilization / unified-5h-reset / unified-5h-status  → sessão de 5 horas
 *   - unified-7d-utilization / unified-7d-reset / unified-7d-status  → limite semanal
 *   - unified-status → status geral (allowed | allowed_warning | rejected…)
 *
 * O endpoint "oficioso" de uso do Claude Code (GET /api/oauth/usage) NÃO serve aqui: exige o
 * escopo OAuth `user:profile`, e os tokens de licença (`claude setup-token`) só têm
 * `user:inference` (testado em 17/09/2026 → 403 oauth_scope_insufficient).
 *
 * Como coletamos: uma SONDA mínima por licença — 1 chamada com max_tokens=1 no Haiku (~10
 * tokens, custo desprezível na cota) — só para ler os headers. As sondas rodam em paralelo
 * (curl_multi) e o resultado fica em cache (app_settings) por TTL; a tela busca o cache e o
 * botão "Atualizar" força sondagem nova.
 *
 * Janela do FABLE: a Anthropic só informa a janela semanal do Fable (headers `unified-7d_oi-*`,
 * "seven_day_overage_included") em respostas de requests Fable REAIS do Claude Code — Opus e
 * Fable pela API crua com o token da assinatura dão 429 seco, sem header (testado 17/09/2026 nas
 * 4 licenças, mesmo imitando os headers do CLI). Por isso a sonda não a alcança; ela vem de
 * PASSAGEM: o proxy da IDE (bin/ide-proxy.py) anota por licença os headers de uso de cada
 * resposta em storage/cache/license-usage-observed.json, e aqui mesclamos a última leitura.
 */
class LicenseUsageService
{
    /** Validade do cache de uso (segundos). */
    private const TTL = 600;

    /** Chave do cache em app_settings. */
    private const CACHE_KEY = 'license_usage_snapshots';

    /** Observações de passagem gravadas pelo proxy da IDE (por licença). */
    private const OBSERVED_FILE = STORAGE_PATH . '/cache/license-usage-observed.json';

    /** Modelo barato usado na sonda (só para provocar os headers). */
    private const PROBE_MODEL = 'claude-haiku-4-5';

    /** Timeout de cada sonda (a chamada gera 1 token; mais que isso é rede ruim). */
    private const PROBE_TIMEOUT = 20;

    /**
     * Uso de todas as licenças ATIVAS, do cache quando fresco.
     *
     * @param bool $force true = ignora o TTL e sonda todas de novo (botão "Atualizar")
     * @return array<int,array<string,mixed>> id da licença => snapshot:
     *   { ok:bool, fetched_at:int, error?:string,
     *     five_hour?: {utilization:float, reset:?int, status:?string},
     *     seven_day?: {utilization:float, reset:?int, status:?string},
     *     fable?: {utilization:float, reset:?int, status:?string, observed_at:int, renewed:bool}|null,
     *     status?: ?string }
     */
    public static function snapshotAll(bool $force = false): array
    {
        $cache = self::readCache();
        $now = time();

        $tokens = [];
        foreach (LicenseService::all() as $l) {
            if (!$l['is_active']) {
                continue;
            }
            $id = (int) $l['id'];
            $fresh = isset($cache[$id]) && ($now - (int) ($cache[$id]['fetched_at'] ?? 0)) < self::TTL;
            if ($force || !$fresh) {
                $tok = LicenseService::rawToken($id);
                if ($tok !== '') {
                    $tokens[$id] = $tok;
                } else {
                    $cache[$id] = ['ok' => false, 'fetched_at' => $now, 'error' => 'token ilegível'];
                }
            }
        }

        if ($tokens !== []) {
            foreach (self::probeMany($tokens) as $id => $snap) {
                $cache[$id] = $snap;
            }
            self::writeCache($cache);
        }

        // Devolve só as licenças ativas atuais (cache pode ter sobras de licenças excluídas),
        // mesclando a janela do Fable observada pelo proxy da IDE.
        $observed = self::readObserved();
        $out = [];
        foreach (LicenseService::all() as $l) {
            if ($l['is_active'] && isset($cache[(int) $l['id']])) {
                $snap = $cache[(int) $l['id']];
                $obs = $observed[(int) $l['id']] ?? null;
                $snap['fable'] = self::fableFrom($obs, $now);
                // 5h/semana também chegam de PASSAGEM (toda resposta via proxy traz os headers):
                // quando a observação é mais nova que a sonda, ela manda — as barras acompanham o
                // uso em tempo real para a conta que está sendo usada, sem esperar o TTL da sonda.
                foreach (['five_hour' => '5h', 'seven_day' => '7d'] as $field => $key) {
                    $w = self::observedWindow($obs, $key, (int) ($snap['fetched_at'] ?? 0), $now);
                    if ($w !== null) {
                        $snap[$field] = $w;
                        $snap['ok'] = true;
                    }
                }
                $out[(int) $l['id']] = $snap;
            }
        }
        return $out;
    }

    /**
     * Janelas 5h/7d ATUAIS de uma licença SEM sondar: último snapshot da sonda (cache) mesclado
     * com a leitura de passagem do proxy (a mais nova vence), zerando janela cujo reset já passou.
     * Para quem está no caminho de um request e não pode esperar sonda (resolver do proxy da IDE).
     *
     * @return array{five_hour:?array,seven_day:?array}
     */
    public static function cachedWindows(int $id): array
    {
        $now = time();
        $snap = self::readCache()[$id] ?? [];
        $obs = self::readObserved()[$id] ?? null;
        $out = [];
        foreach (['five_hour' => '5h', 'seven_day' => '7d'] as $field => $key) {
            $w = (isset($snap[$field]) && is_array($snap[$field])) ? $snap[$field] : null;
            $o = self::observedWindow($obs, $key, (int) ($snap['fetched_at'] ?? 0), $now);
            if ($o !== null) {
                $w = $o;
            } elseif ($w !== null && ($w['reset'] ?? null) !== null && (int) $w['reset'] <= $now) {
                $w = ['utilization' => 0.0, 'reset' => null, 'status' => null]; // renovou desde a sonda
            }
            $out[$field] = $w;
        }
        return $out;
    }

    /**
     * A licença atingiu $limitPct (teto do admin p/ IDE e Dev Autônomo) em alguma janela do plano?
     * $probe=true usa o snapshot completo (sonda com TTL); false lê só cache + passagem (rápido).
     * Sem dado de uso = liberada (fail-open: o teto não pode derrubar a IDE se a sonda falhar).
     *
     * @return array{pct:int,window:string,reset:?int}|null null = abaixo do teto
     */
    public static function overLimit(int $id, int $limitPct, bool $probe = false): ?array
    {
        if ($probe) {
            $snap = self::snapshotAll(false)[$id] ?? [];
            $now = time();
            $win = [];
            foreach (['five_hour', 'seven_day'] as $f) {
                $w = (isset($snap[$f]) && is_array($snap[$f])) ? $snap[$f] : null;
                if ($w !== null && ($w['reset'] ?? null) !== null && (int) $w['reset'] <= $now) {
                    $w = null; // janela renovou dentro do TTL da sonda
                }
                $win[$f] = $w;
            }
        } else {
            $win = self::cachedWindows($id);
        }

        $worst = null;
        foreach (['five_hour' => 'sessão de 5h', 'seven_day' => 'semana'] as $f => $label) {
            $w = $win[$f] ?? null;
            if (!is_array($w)) {
                continue;
            }
            $pct = (int) floor(((float) ($w['utilization'] ?? 0)) * 100);
            if ($pct >= $limitPct && ($worst === null || $pct > $worst['pct'])) {
                $worst = [
                    'pct'    => $pct,
                    'window' => $label,
                    'reset'  => ($w['reset'] ?? null) !== null ? (int) $w['reset'] : null,
                ];
            }
        }
        return $worst;
    }

    /**
     * Janela (5h|7d) da última observação de passagem, SÓ quando mais nova que a sonda
     * ($newerThan = fetched_at do snapshot). Reset já vencido = janela renovou → 0%.
     */
    private static function observedWindow(?array $entry, string $key, int $newerThan, int $now): ?array
    {
        $h = $entry['headers'] ?? null;
        if (!is_array($h)) {
            return null;
        }
        $util = $h['anthropic-ratelimit-unified-' . $key . '-utilization'] ?? null;
        if (!is_array($util) || !isset($util[0]) || !is_numeric($util[0])) {
            return null;
        }
        if ((int) ($util[1] ?? 0) <= $newerThan) {
            return null; // a sonda é mais recente
        }
        $resetRaw = $h['anthropic-ratelimit-unified-' . $key . '-reset'][0] ?? null;
        $reset = ($resetRaw !== null && ctype_digit((string) $resetRaw)) ? (int) $resetRaw : null;
        if ($reset !== null && $reset <= $now) {
            return ['utilization' => 0.0, 'reset' => null, 'status' => null];
        }
        $status = $h['anthropic-ratelimit-unified-' . $key . '-status'][0] ?? null;
        return [
            'utilization' => max(0.0, min(1.0, (float) $util[0])),
            'reset'       => $reset,
            'status'      => is_string($status) ? $status : null,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Fable: observação de passagem (proxy da IDE)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Lê o arquivo do proxy: licença => { headers: { nome => [valor, quando, modelo] }, last, model }.
     * @return array<int,array<string,mixed>>
     */
    private static function readObserved(): array
    {
        if (!is_file(self::OBSERVED_FILE)) {
            return [];
        }
        $arr = json_decode((string) @file_get_contents(self::OBSERVED_FILE), true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $k => $v) {
            if (is_array($v) && isset($v['headers']) && is_array($v['headers'])) {
                $out[(int) $k] = $v;
            }
        }
        return $out;
    }

    /**
     * Janela do Fable (7d_oi) a partir da última observação. null = nunca vista para esta licença.
     * Se o reset já passou, a janela renovou: mostramos 0% até uma request Fable nova ser observada.
     */
    private static function fableFrom(?array $entry, int $now): ?array
    {
        $h = $entry['headers'] ?? null;
        if (!is_array($h)) {
            return null;
        }
        $util = $h['anthropic-ratelimit-unified-7d_oi-utilization'] ?? null;
        if (!is_array($util) || !isset($util[0]) || !is_numeric($util[0])) {
            return null;
        }
        $observedAt = (int) ($util[1] ?? 0);
        $resetRaw = $h['anthropic-ratelimit-unified-7d_oi-reset'][0] ?? null;
        $reset = ($resetRaw !== null && ctype_digit((string) $resetRaw)) ? (int) $resetRaw : null;
        $status = $h['anthropic-ratelimit-unified-7d_oi-status'][0] ?? null;

        if ($reset !== null && $reset <= $now) {
            return ['utilization' => 0.0, 'reset' => null, 'status' => null, 'observed_at' => $observedAt, 'renewed' => true];
        }
        return [
            'utilization' => max(0.0, min(1.0, (float) $util[0])),
            'reset'       => $reset,
            'status'      => is_string($status) ? $status : null,
            'observed_at' => $observedAt,
            'renewed'     => false,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Sonda (curl_multi: todas as licenças em paralelo)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param array<int,string> $tokens id da licença => token OAuth cru
     * @return array<int,array<string,mixed>> id => snapshot
     */
    private static function probeMany(array $tokens): array
    {
        $mh = curl_multi_init();
        $handles = [];
        $headerBags = [];

        foreach ($tokens as $id => $tok) {
            $headerBags[$id] = [];
            $ch = curl_init(rtrim((string) env('GW_ANTHROPIC_BASE', 'https://api.anthropic.com'), '/') . '/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'model'      => self::PROBE_MODEL,
                    'max_tokens' => 1,
                    'messages'   => [['role' => 'user', 'content' => '.']],
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::PROBE_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => [
                    'authorization: Bearer ' . $tok,
                    'anthropic-version: 2023-06-01',
                    'anthropic-beta: ' . (string) env('GW_OAUTH_BETA', 'oauth-2025-04-20'),
                    'content-type: application/json',
                ],
                // Coleta os headers da resposta sem misturá-los no corpo.
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headerBags, $id): int {
                    $p = strpos($line, ':');
                    if ($p !== false) {
                        $headerBags[$id][strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                    }
                    return strlen($line);
                },
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $now = time();
        $out = [];
        foreach ($handles as $id => $ch) {
            $body = curl_multi_getcontent($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $out[$id] = self::snapshotFrom($headerBags[$id], $http, (string) $body, $cerr, $now);
        }
        curl_multi_close($mh);
        return $out;
    }

    /** Monta o snapshot de uma licença a partir dos headers/da resposta da sonda. */
    private static function snapshotFrom(array $headers, int $http, string $body, string $curlErr, int $now): array
    {
        $five = self::window($headers, '5h');
        $week = self::window($headers, '7d');

        // Mesmo um 429 (limite estourado) traz os headers unified — o dado continua bom.
        if ($five !== null || $week !== null) {
            return [
                'ok'         => true,
                'fetched_at' => $now,
                'http'       => $http,
                'status'     => $headers['anthropic-ratelimit-unified-status'] ?? null,
                'five_hour'  => $five,
                'seven_day'  => $week,
            ];
        }

        if ($http === 0) {
            $err = 'sem resposta da Anthropic' . ($curlErr !== '' ? ' (' . $curlErr . ')' : '');
        } elseif ($http === 401 || $http === 403) {
            $err = 'token não autenticou (HTTP ' . $http . ')';
        } else {
            $j = json_decode($body, true);
            $err = 'HTTP ' . $http . (is_array($j) && isset($j['error']['message']) ? ': ' . mb_substr((string) $j['error']['message'], 0, 120) : '');
        }
        return ['ok' => false, 'fetched_at' => $now, 'http' => $http, 'error' => $err];
    }

    /** Lê uma janela (5h|7d) dos headers unified; null quando os headers não vieram. */
    private static function window(array $headers, string $key): ?array
    {
        $util = $headers['anthropic-ratelimit-unified-' . $key . '-utilization'] ?? null;
        if ($util === null || !is_numeric($util)) {
            return null;
        }
        $reset = $headers['anthropic-ratelimit-unified-' . $key . '-reset'] ?? null;
        return [
            'utilization' => max(0.0, min(1.0, (float) $util)),
            'reset'       => ($reset !== null && ctype_digit($reset)) ? (int) $reset : null,
            'status'      => $headers['anthropic-ratelimit-unified-' . $key . '-status'] ?? null,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Cache (app_settings)
    // ──────────────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private static function readCache(): array
    {
        $arr = json_decode((string) (Setting::get(self::CACHE_KEY, '') ?? ''), true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $out[(int) $k] = $v;
            }
        }
        return $out;
    }

    private static function writeCache(array $cache): void
    {
        try {
            Setting::set(self::CACHE_KEY, json_encode($cache, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) { /* best-effort: sem cache, a próxima chamada sonda de novo */ }
    }
}
