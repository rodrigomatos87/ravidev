<?php

declare(strict_types=1);

namespace Ravi\Gateway;

/**
 * Backend PRIMÁRIO do gateway (v2): proxy FINO para a Messages API oficial da Anthropic,
 * autenticando com o token OAuth da assinatura Max — o MESMO direito de uso que o Claude Code
 * consome por dentro (custo flat, sem cobrança de API por token).
 *
 * Por que existe: o backend legado (GatewayClaudeService) sobe um container Docker rodando o
 * Claude Code CLI a cada requisição. Isso (a) injeta ~80–120k tokens de scaffolding agêntico
 * ("infla o contexto"), (b) paga cold start de container + init do CLI a cada chamada, e
 * (c) ACHATA o system/messages em texto puro, descartando o `cache_control` que o cliente manda.
 * Resultado medido: 14–50s por chamada (e timeouts de 50s).
 *
 * Este proxy repassa o corpo Messages API INTACTO (system em blocos, `cache_control`, histórico),
 * então o prompt caching que o cliente já pede funciona de verdade e o contexto é só o real.
 * Medido na validação: ~1s por chamada, com cache_read do system estável.
 *
 * Bucket da ASSINATURA, não da API: até 02/11/2026 o proxy mandava só `Bearer <oauth>` +
 * `anthropic-beta: oauth-2025-04-20`, e a Anthropic respondia 429 imediato no Opus/Sonnet
 * (só o Haiku passava). O que libera Sonnet/Opus/Fable é um PRIMEIRO bloco de `system` com a
 * identidade do agente (comIdentidade). Provado em A/B no servidor da API em 02/11/2026: só o
 * bloco já dá 200; só os cabeçalhos do CLI continuam em 429. Os cabeçalhos (user-agent, x-app,
 * betas, x-stainless-*) vão junto mesmo assim, para o request ficar igual ao do Claude Code.
 * Isolado do produto — só o GatewayService o chama.
 */
class GatewayHttpService
{
    /** Versão do CLI que o user-agent anuncia (acompanha a sandbox: ver GW_CLI_VERSION). */
    public const CLI_VERSION_PADRAO = '2.1.280';

    /** Betas que o Claude Code envia em toda chamada da assinatura. */
    public const BETAS_PADRAO = 'claude-code-20250219,oauth-2025-04-20,context-management-2025-06-27,effort-2025-11-24';

    /** Primeiro bloco de system exigido pela assinatura para liberar Sonnet/Opus. */
    public const IDENTIDADE = "You are a Claude agent, built on Anthropic's Claude Agent SDK.";

    private string $oauthToken;
    private int $timeout;
    private string $baseUrl;
    private string $beta;
    private string $cliVersion;

    /** @param ?int $timeout teto desta chamada em s (faixa longa: o tempo pedido pelo cliente); null/0 = GW_HTTP_TIMEOUT */
    public function __construct(?string $oauthToken = null, ?int $timeout = null)
    {
        // Licença: quando o chamador resolve por token de API, passa aqui; senão usa o padrão
        // global do escopo API (→ fallback do .env), igual ao backend legado.
        $this->oauthToken = $oauthToken !== null ? $oauthToken : \Ravi\Services\LicenseService::tokenForScope('api');
        $this->timeout    = ($timeout !== null && $timeout > 0) ? $timeout : (int) env('GW_HTTP_TIMEOUT', 120);
        $this->baseUrl    = rtrim((string) env('GW_ANTHROPIC_BASE', 'https://api.anthropic.com'), '/');
        $this->beta       = (string) env('GW_OAUTH_BETA', self::BETAS_PADRAO);
        $this->cliVersion = (string) env('GW_CLI_VERSION', self::CLI_VERSION_PADRAO);
    }

    public function isAvailable(): bool
    {
        return $this->oauthToken !== '';
    }

    /**
     * Cabeçalhos que o Claude Code CLI manda numa chamada da assinatura. A Anthropic usa esse
     * conjunto para classificar o request no bucket da assinatura (e não no da API paga).
     *
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            'authorization: Bearer ' . $this->oauthToken,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: ' . $this->beta,
            'anthropic-dangerous-direct-browser-access: true',
            'content-type: application/json',
            'accept: application/json',
            'user-agent: claude-cli/' . $this->cliVersion . ' (external, sdk-cli)',
            'x-app: cli',
            'x-stainless-arch: x64',
            'x-stainless-lang: js',
            'x-stainless-os: Linux',
            'x-stainless-package-version: 0.80.0',
            'x-stainless-retry-count: 0',
            'x-stainless-runtime: node',
            'x-stainless-runtime-version: v24.14.0',
            'x-stainless-timeout: 600',
        ];
    }

    /**
     * Garante o bloco de identidade como PRIMEIRO bloco do `system`, preservando o que o cliente
     * mandou (string ou blocos, com `cache_control`). Idempotente: se o cliente já começa com a
     * identidade (ex.: outro Claude Code atrás do gateway), não duplica.
     */
    public static function comIdentidade(array $body): array
    {
        $system = $body['system'] ?? null;
        $blocos = [];
        if (is_string($system) && $system !== '') {
            $blocos = [['type' => 'text', 'text' => $system]];
        } elseif (is_array($system)) {
            $blocos = array_values($system);
        }
        $primeiro = $blocos[0] ?? null;
        $jaTem = is_array($primeiro)
            && ($primeiro['type'] ?? '') === 'text'
            && str_starts_with(trim((string) ($primeiro['text'] ?? '')), self::IDENTIDADE);
        if (!$jaTem) {
            array_unshift($blocos, ['type' => 'text', 'text' => self::IDENTIDADE]);
        }
        $body['system'] = $blocos;
        return $body;
    }

    /**
     * Encaminha o corpo (formato Messages API) para a Anthropic, preservando blocos e cache_control.
     *
     * @param array $body corpo já decodificado do cliente (model, max_tokens, system, messages…)
     * @return array{ok:bool, http:int, json:?array, error:?string, transport_fail:bool}
     *   - ok=true            → 2xx com resposta válida (json = resposta crua da Anthropic)
     *   - transport_fail=true → não alcançou a API (erro de rede / 5xx sem corpo) → chamador pode
     *                           cair no backend CLI legado para não perder disponibilidade
     *   - ok=false & transport_fail=false → a API respondeu um erro (4xx/5xx com corpo): repasse ao
     *                           cliente, que já trata retry/fallback (ex.: 429, 400)
     */
    public function forward(array $body): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'http' => 0, 'json' => null, 'error' => 'Max indisponível (sem CLAUDE_CODE_OAUTH_TOKEN)', 'transport_fail' => true];
        }

        // O cliente do copiloto é bloqueante (op=claude espera a resposta inteira). Nunca streamar
        // aqui — remove `stream` se vier, para a resposta continuar sendo um JSON único.
        unset($body['stream']);
        $body = self::comIdentidade($body);

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ['ok' => false, 'http' => 0, 'json' => null, 'error' => 'Falha ao serializar o corpo', 'transport_fail' => true];
        }

        // `?beta=true` é como o CLI chama a rota; sem ele a API aceita, mas espelhar é mais seguro.
        $ch = curl_init($this->baseUrl . '/v1/messages?beta=true');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $this->headers(),
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        // Falha de transporte: sem resposta ou sem status HTTP → o chamador decide o fallback.
        if ($resp === false || $code === 0) {
            return ['ok' => false, 'http' => $code, 'json' => null, 'error' => ($cerr !== '' ? $cerr : 'falha de transporte'), 'transport_fail' => true];
        }

        $json = json_decode((string) $resp, true);
        if (!is_array($json)) {
            // 5xx sem JSON = trata como transporte (permite fallback); outros = erro repassável.
            return ['ok' => false, 'http' => $code, 'json' => null, 'error' => 'resposta não-JSON da API (http ' . $code . ')', 'transport_fail' => $code >= 500];
        }

        $ok = $code >= 200 && $code < 300 && ($json['type'] ?? '') !== 'error';
        return [
            'ok'             => $ok,
            'http'           => $code,
            'json'           => $json,
            'error'          => $ok ? null : (string) ($json['error']['message'] ?? ('http ' . $code)),
            'transport_fail' => false,
        ];
    }
}
