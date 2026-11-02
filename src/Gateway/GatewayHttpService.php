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
 * Auth: Authorization: Bearer <oauth> + anthropic-beta: oauth-2025-04-20 (espelha o Claude Code).
 * Isolado do produto — só o GatewayService o chama.
 */
class GatewayHttpService
{
    private string $oauthToken;
    private int $timeout;
    private string $baseUrl;
    private string $beta;

    public function __construct(?string $oauthToken = null)
    {
        // Licença: quando o chamador resolve por token de API, passa aqui; senão usa o padrão
        // global do escopo API (→ fallback do .env), igual ao backend legado.
        $this->oauthToken = $oauthToken !== null ? $oauthToken : \Ravi\Services\LicenseService::tokenForScope('api');
        $this->timeout    = (int) env('GW_HTTP_TIMEOUT', 120);
        $this->baseUrl    = rtrim((string) env('GW_ANTHROPIC_BASE', 'https://api.anthropic.com'), '/');
        $this->beta       = (string) env('GW_OAUTH_BETA', 'oauth-2025-04-20');
    }

    public function isAvailable(): bool
    {
        return $this->oauthToken !== '';
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

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ['ok' => false, 'http' => 0, 'json' => null, 'error' => 'Falha ao serializar o corpo', 'transport_fail' => true];
        }

        $ch = curl_init($this->baseUrl . '/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'authorization: Bearer ' . $this->oauthToken,
                'anthropic-version: 2023-06-01',
                'anthropic-beta: ' . $this->beta,
                'content-type: application/json',
            ],
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
