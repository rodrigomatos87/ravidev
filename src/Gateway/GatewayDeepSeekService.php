<?php

declare(strict_types=1);

namespace Ravi\Gateway;

use Ravi\Core\Encryption;
use Ravi\Models\Setting;

/**
 * Backend DeepSeek do gateway: encaminha o corpo Messages API ORIGINAL (multi-turno
 * e anexos intactos, ao contrário do achatamento do backend CLI) para o endpoint
 * compatível com a Anthropic da DeepSeek. O mapeamento de modelo é do próprio
 * endpoint deles (claude-haiku/claude-sonnet servem deepseek-flash; claude-opus
 * serve deepseek-v4-pro). Sem fallback para outro backend: falhou, o erro volta
 * claro ao cliente e fica registrado em gw_requests.
 *
 * A chave fica cifrada (libsodium) em app_settings sob deepseek_api_key,
 * cadastrada em Admin → Gateway de API. Sem chave, o backend recusa com
 * mensagem clara — cadastrar a chave sozinho não muda o comportamento de
 * nenhum token (a regra por token é quem liga o roteamento).
 */
class GatewayDeepSeekService
{
    private const URL = 'https://api.deepseek.com/anthropic/v1/messages';
    private const SETTING_KEY = 'deepseek_api_key';

    /** Chave em claro, ou '' quando não cadastrada/indecifrável. */
    public static function apiKey(): string
    {
        $enc = (string) (Setting::get(self::SETTING_KEY, '') ?? '');
        if ($enc === '') {
            return '';
        }
        try {
            return (new Encryption())->decrypt($enc);
        } catch (\Throwable $e) {
            error_log('[gateway] chave DeepSeek indecifrável (ENCRYPTION_KEY mudou?): ' . $e->getMessage());
            return '';
        }
    }

    public static function configured(): bool
    {
        return self::apiKey() !== '';
    }

    /** Grava (cifrada) ou remove (null/vazio) a chave. */
    public static function saveApiKey(?string $key): void
    {
        $key = trim((string) $key);
        Setting::set(self::SETTING_KEY, $key === '' ? null : (new Encryption())->encrypt($key));
    }

    /**
     * Encaminha uma requisição Messages API à DeepSeek.
     *
     * @param array $body corpo original do cliente (model/messages/system/…)
     * @return array{ok:bool, http:int, json:?array, error:?string}
     */
    public function forward(array $body, int $timeout = 120): array
    {
        $key = self::apiKey();
        if ($key === '') {
            return ['ok' => false, 'http' => 0, 'json' => null,
                'error' => 'chave da DeepSeek não configurada (Admin → Gateway de API)'];
        }
        unset($body['stream']); // o gateway sempre responde a mensagem completa

        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'anthropic-version: 2023-06-01',
                'x-api-key: ' . $key,
            ],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
        ]);
        $resposta = curl_exec($ch);
        $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $http === 0) {
            return ['ok' => false, 'http' => 0, 'json' => null,
                'error' => 'transporte: ' . ($curlErr !== '' ? $curlErr : 'sem resposta')];
        }
        $json = json_decode((string) $resposta, true);
        if (!is_array($json)) {
            return ['ok' => false, 'http' => $http, 'json' => null, 'error' => 'HTTP ' . $http . ': resposta não-JSON'];
        }
        if ($http !== 200) {
            $msg = (string) ($json['error']['message'] ?? ('HTTP ' . $http));
            return ['ok' => false, 'http' => $http, 'json' => $json, 'error' => 'HTTP ' . $http . ': ' . mb_substr($msg, 0, 300)];
        }
        return ['ok' => true, 'http' => 200, 'json' => $json, 'error' => null];
    }
}
