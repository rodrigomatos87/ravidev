<?php

declare(strict_types=1);

namespace Ravi\Gateway;

use Ravi\Core\Database;

/**
 * Front do gateway (isolado do app — sem sessão/CSRF/middleware).
 * Autentica por x-api-key contra gw_tokens e despacha para o GatewayService.
 * Sempre responde no formato da API da Anthropic (sucesso ou erro).
 */
class GatewayController
{
    /**
     * Processa uma requisição já parseada pelo front controller do subdomínio.
     *
     * @param string $method   HTTP method
     * @param string $path     caminho (ex.: /v1/messages)
     * @param string $apiKey   valor do header x-api-key (ou Authorization Bearer)
     * @param string $rawBody  corpo cru
     * @return array{http:int, json:array}
     */
    public function dispatch(string $method, string $path, string $apiKey, string $rawBody): array
    {
        $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: $path, '/');

        // Health check é público (não exige token). HEAD também: sondas de alcance
        // (ex.: telas de capacidade de sistemas clientes) usam HEAD.
        if (($method === 'GET' || $method === 'HEAD') && $path === '/v1/health') {
            return ['http' => 200, 'json' => ['status' => 'ok', 'service' => 'ravidev-api-gateway']];
        }

        // Autenticação por token do gateway (vale para todos os endpoints abaixo).
        $tokenId = $this->authenticate($apiKey);
        if ($tokenId === null) {
            // Sondagem de token era um ponto cego forense: registra a tentativa
            // (token_id NULL, como a migration já previa) ANTES de recusar.
            // Exceção: pedido SEM chave fora de /v1/ (scanner de wp-json, ping na raiz)
            // não é tentativa de token e só enchia o ledger (420 mil linhas em 2 meses,
            // 53% da tabela). Chave presente, ou qualquer caminho em /v1/, continua logado.
            if (trim($apiKey) !== '' || str_starts_with($path, '/v1/')) {
                $this->logAuthFailure($path, $apiKey);
            }
            return $this->error(401, 'authentication_error', 'Token de API inválido ou revogado.');
        }

        $svc = new GatewayService();

        // GET /v1/models — lista os modelos disponíveis (formato Anthropic).
        if ($method === 'GET' && $path === '/v1/models') {
            return $svc->listModels();
        }
        // GET /v1/models/{id} — detalhe de um modelo.
        if ($method === 'GET' && preg_match('#^/v1/models/([^/]+)$#', $path, $m)) {
            return $svc->getModel(urldecode($m[1]));
        }

        // POST /v1/messages e POST /v1/messages/count_tokens precisam de corpo JSON.
        if ($path === '/v1/messages' || $path === '/v1/messages/count_tokens') {
            if ($method !== 'POST') {
                return $this->error(405, 'invalid_request_error', 'Método não suportado. Use POST.');
            }
            $body = json_decode($rawBody, true);
            if (!is_array($body)) {
                return $this->error(400, 'invalid_request_error', 'Corpo JSON inválido.');
            }
            return $path === '/v1/messages'
                ? $svc->createMessage($body, $tokenId, $this->clientIp(), $this->raviFields(), $this->tempoPedido())
                : $svc->countTokens($body);
        }

        return $this->error(404, 'not_found_error', 'Endpoint não encontrado.');
    }

    /**
     * Campos de identidade enviados pelo cliente: todo header "x-ravi-<chave>".
     * Ex.: x-ravi-server-token -> ['server_token' => '...'], x-ravi-server-ips,
     * e quaisquer campos personalizados definidos por token no admin.
     * @return array<string,string>  [field_key => valor]
     */
    private function raviFields(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            // x-ravi-tempo-limite é controle (tempoPedido), não identidade do chamador.
            if (strncmp((string) $k, 'HTTP_X_RAVI_', 12) === 0 && $k !== 'HTTP_X_RAVI_TEMPO_LIMITE') {
                $key = strtolower(substr((string) $k, 12)); // SERVER_TOKEN -> server_token
                $val = trim((string) $v);
                if ($key !== '' && $val !== '') {
                    $out[$key] = mb_substr($val, 0, 255);
                }
            }
        }
        return $out;
    }

    /**
     * Tempo pedido pelo cliente (s) no cabeçalho x-ravi-tempo-limite: chamada longa, até
     * GW_TEMPO_TETO (600). Ausente ou inválido = null (vale o GW_MAX_TIMEOUT de sempre).
     * O cliente deve esperar ALÉM disso no próprio curl (sugestão: pedido + 30 s), senão
     * desiste antes e o container segue rodando até o fim, ocupando a vaga à toa.
     */
    private function tempoPedido(): ?int
    {
        $v = trim((string) ($_SERVER['HTTP_X_RAVI_TEMPO_LIMITE'] ?? ''));
        return ctype_digit($v) && $v !== '' ? (int) $v : null;
    }

    /** IP de origem da chamada. REMOTE_ADDR manda; XFF só é aceito vindo de loopback. */
    private function clientIp(): ?string
    {
        return client_ip();
    }

    /** Valida o token (sha256) e marca último uso. Retorna o id ou null. */
    private function authenticate(string $apiKey): ?int
    {
        $apiKey = trim($apiKey);
        if (str_starts_with($apiKey, 'Bearer ')) {
            $apiKey = trim(substr($apiKey, 7));
        }
        if ($apiKey === '') {
            return null;
        }
        $hash = hash('sha256', $apiKey);
        $db = Database::getInstance();
        $row = $db->fetch(
            "SELECT id FROM gw_tokens WHERE token_hash = ? AND is_active = 1 AND revoked_at IS NULL",
            [$hash]
        );
        if (!$row) {
            return null;
        }
        $id = (int) $row['id'];
        try {
            $db->update('gw_tokens', ['last_used_at' => date('Y-m-d H:i:s')], 'id = :_id', ['_id' => $id]);
        } catch (\Throwable $e) {
            // não crítico
        }
        return $id;
    }

    /**
     * Persiste uma tentativa de autenticação inválida em gw_requests (token_id NULL).
     * Nunca grava a chave tentada — pode ser um token válido com typo. Só um hint:
     * prefixo curto quando parece um token nosso, ou o tamanho quando não parece.
     */
    private function logAuthFailure(string $path, string $apiKey): void
    {
        $key = trim($apiKey);
        if (str_starts_with($key, 'Bearer ')) {
            $key = trim(substr($key, 7));
        }
        $hint = $key === ''
            ? 'sem chave'
            : (str_starts_with($key, 'sk-')
                ? 'chave ' . substr($key, 0, 13) . '… (' . strlen($key) . ' chars)'
                : 'chave com ' . strlen($key) . ' chars');
        try {
            Database::getInstance()->insert('gw_requests', [
                'token_id'  => null,
                'client_ip' => client_ip(),
                'status'    => 'error',
                'error'     => mb_substr('auth inválida em ' . $path . ' (' . $hint . ')', 0, 255),
            ]);
        } catch (\Throwable $e) {
            error_log('[gateway] falha ao registrar auth inválida: ' . $e->getMessage());
        }
    }

    private function error(int $http, string $type, string $message): array
    {
        return ['http' => $http, 'json' => ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]];
    }
}
