<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;
use Ravi\Core\Encryption;
use Ravi\Models\Setting;

/**
 * Notificações por WhatsApp. Conversa com o servidor Go (whatsmeow) via HTTP.
 * Portado do Ravi Mail e adaptado ao framework do Ravi Developer.
 *
 * Config (app_settings, tela de admin):
 *   - whatsapp_api_url      (ex.: http://127.0.0.1:8090)
 *   - whatsapp_api_key_enc  (chave da API do servidor Go, criptografada)
 */
class WhatsAppService
{
    private function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * Inicia uma conexão de WhatsApp para o usuário: cria/atualiza o registro e
     * pede o QR Code ao servidor Go.
     *
     * @return array{success:bool, qr_code?:string, connection_id?:int, already_connected?:bool, error?:string}
     */
    public function connect(int $userId): array
    {
        $db = $this->db();

        $existing = $db->fetch(
            "SELECT id FROM user_whatsapp_connections WHERE user_id = ? AND status = 'connected'",
            [$userId]
        );
        if ($existing) {
            return ['success' => false, 'error' => 'Você já tem uma conexão ativa. Desconecte primeiro.'];
        }

        $sessionId = 'ravidev_' . $userId . '_' . time();

        $conn = $db->fetch(
            "SELECT id FROM user_whatsapp_connections WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$userId]
        );

        if ($conn) {
            $connectionId = (int) $conn['id'];
            $db->update('user_whatsapp_connections', [
                'session_id'   => $sessionId,
                'status'       => 'qr_pending',
                'qr_code_data' => null,
                'phone_number' => null,
                'connected_at' => null,
            ], 'id = :_id AND user_id = :_uid', ['_id' => $connectionId, '_uid' => $userId]);
        } else {
            $connectionId = (int) $db->insert('user_whatsapp_connections', [
                'user_id'    => $userId,
                'session_id' => $sessionId,
                'status'     => 'qr_pending',
            ]);
        }

        $response = $this->callGoServer('POST', '/conectar', [
            'usuario_id' => $userId,
            'conexao_id' => $connectionId,
        ]);

        if ($response === null || !($response['success'] ?? false)) {
            $db->update('user_whatsapp_connections', ['status' => 'disconnected'], 'id = :_id', ['_id' => $connectionId]);
            return ['success' => false, 'error' => 'Erro ao falar com o servidor de WhatsApp. Tente novamente.'];
        }

        if (($response['status'] ?? '') === 'conectado') {
            $db->update('user_whatsapp_connections', [
                'status'       => 'connected',
                'connected_at' => date('Y-m-d H:i:s'),
                'last_seen'    => date('Y-m-d H:i:s'),
            ], 'id = :_id', ['_id' => $connectionId]);
            return ['success' => true, 'connection_id' => $connectionId, 'already_connected' => true];
        }

        $qrCode = (string) ($response['qr_code'] ?? '');
        if ($qrCode !== '') {
            $db->update('user_whatsapp_connections', ['qr_code_data' => $qrCode], 'id = :_id', ['_id' => $connectionId]);
        }

        return ['success' => true, 'qr_code' => $qrCode, 'connection_id' => $connectionId];
    }

    /**
     * Consulta o status da conexão no servidor Go e sincroniza no banco.
     *
     * @return array{status:string, phone_number?:string|null}
     */
    public function checkStatus(int $connectionId, int $userId): array
    {
        $db = $this->db();
        $conn = $db->fetch(
            "SELECT id, status FROM user_whatsapp_connections WHERE id = ? AND user_id = ?",
            [$connectionId, $userId]
        );
        if (!$conn) {
            return ['status' => 'not_found'];
        }

        $response = $this->callGoServer('GET', '/status/' . $connectionId);
        if ($response === null) {
            return ['status' => $conn['status']];
        }

        $statusMap = [
            'conectado'    => 'connected',
            'conectando'   => 'qr_pending',
            'desconectado' => 'disconnected',
        ];
        $dbStatus = $statusMap[$response['status'] ?? 'desconectado'] ?? 'disconnected';

        $update = ['status' => $dbStatus, 'last_seen' => date('Y-m-d H:i:s')];
        if ($dbStatus === 'connected') {
            if (!empty($response['numero'])) {
                $update['phone_number'] = $response['numero'];
            }
            if (!empty($response['nome'])) {
                $update['name'] = $response['nome'];
            }
            if ($conn['status'] !== 'connected') {
                $update['connected_at'] = date('Y-m-d H:i:s');
                $update['qr_code_data'] = null;
            }
        }
        $db->update('user_whatsapp_connections', $update, 'id = :_id AND user_id = :_uid', ['_id' => $connectionId, '_uid' => $userId]);

        return ['status' => $dbStatus, 'phone_number' => $response['numero'] ?? null];
    }

    /** Desconecta a sessão. */
    public function disconnect(int $connectionId, int $userId): array
    {
        $db = $this->db();
        $conn = $db->fetch("SELECT id FROM user_whatsapp_connections WHERE id = ? AND user_id = ?", [$connectionId, $userId]);
        if (!$conn) {
            return ['success' => false, 'error' => 'Conexão não encontrada.'];
        }

        $this->callGoServer('POST', '/desconectar', ['conexao_id' => $connectionId]);
        $db->update('user_whatsapp_connections', [
            'status' => 'disconnected', 'qr_code_data' => null,
        ], 'id = :_id AND user_id = :_uid', ['_id' => $connectionId, '_uid' => $userId]);

        return ['success' => true];
    }

    /**
     * Envia um alerta de WhatsApp para todos os destinos do usuário, se o tipo de alerta
     * estiver habilitado e houver conexão ativa. Retorna true se ao menos um foi enviado.
     */
    public function sendNotification(int $userId, string $alertType, string $message): bool
    {
        $db = $this->db();

        $conn = $db->fetch("SELECT id FROM user_whatsapp_connections WHERE user_id = ? AND status = 'connected'", [$userId]);
        if (!$conn) {
            return false;
        }
        $connectionId = (int) $conn['id'];

        // Preferência: ausência = habilitado; 'test' sempre passa.
        if ($alertType !== 'test') {
            $pref = $db->fetch(
                "SELECT enabled FROM user_whatsapp_alert_preferences WHERE user_id = ? AND alert_type = ?",
                [$userId, $alertType]
            );
            if ($pref !== null && (int) $pref['enabled'] === 0) {
                return false;
            }
        }

        $phones = $db->fetchAll(
            "SELECT id, phone FROM user_whatsapp_alert_phones WHERE user_id = ? AND whatsapp_connection_id = ?",
            [$userId, $connectionId]
        );
        if (empty($phones)) {
            return false;
        }

        $sentAny = false;
        foreach ($phones as $row) {
            $response = $this->callGoServer('POST', '/enviar', [
                'conexao_id' => $connectionId,
                'telefone'   => $row['phone'],
                'mensagem'   => $message,
            ]);
            $sent = ($response !== null && ($response['success'] ?? false));

            try {
                $db->insert('whatsapp_notifications', [
                    'user_id'                => $userId,
                    'whatsapp_connection_id' => $connectionId,
                    'trigger_event'          => $alertType,
                    'phone'                  => $row['phone'],
                    'message'                => mb_substr($message, 0, 2000),
                    'status'                 => $sent ? 'sent' : 'failed',
                    'external_message_id'    => $sent ? ($response['message_id'] ?? null) : null,
                ]);
            } catch (\Throwable $e) { /* log não pode atrapalhar o envio */ }

            if ($sent) {
                $sentAny = true;
            }
        }

        return $sentAny;
    }

    /** O usuário tem conexão ativa? */
    public function isConnected(int $userId): bool
    {
        $v = $this->db()->fetchColumn(
            "SELECT status FROM user_whatsapp_connections WHERE user_id = ? AND status = 'connected' LIMIT 1",
            [$userId]
        );
        return $v === 'connected';
    }

    /** Conexão mais recente do usuário (ou null). */
    public function getConnection(int $userId): ?array
    {
        return $this->db()->fetch(
            "SELECT * FROM user_whatsapp_connections WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$userId]
        );
    }

    /** Chama o servidor Go via HTTP (auth X-API-Key). Retorna o JSON decodificado ou null. */
    private function callGoServer(string $method, string $endpoint, array $data = []): ?array
    {
        $baseUrl = Setting::get('whatsapp_api_url', 'http://127.0.0.1:8090');
        $keyEnc  = Setting::get('whatsapp_api_key_enc');
        if (empty($keyEnc)) {
            logger()->error('[WhatsApp] whatsapp_api_key_enc não configurada');
            return null;
        }

        try {
            $apiKey = (new Encryption())->decrypt($keyEnc);
        } catch (\Throwable $e) {
            $apiKey = $keyEnc; // fallback (não criptografada)
        }

        $url = rtrim((string) $baseUrl, '/') . $endpoint;
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $apiKey, 'Content-Type: application/json'],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            logger()->error("[WhatsApp] curl {$method} {$endpoint}: {$err}");
            return null;
        }
        if ($code < 200 || $code >= 300) {
            logger()->error("[WhatsApp] HTTP {$code} em {$method} {$endpoint}");
            return null;
        }

        $decoded = json_decode((string) $resp, true);
        return is_array($decoded) ? $decoded : null;
    }
}
