<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\Request;
use Ravi\Core\Response;
use Ravi\Core\JsonResponse;
use Ravi\Core\Database;
use Ravi\Services\WhatsAppService;

/**
 * Configuração de alertas por WhatsApp do usuário (conectar conta via QR,
 * cadastrar contatos de destino, preferências e teste). Portado do Ravi Mail.
 */
class WhatsAppController extends Controller
{
    /** Tipos de alerta do Ravi Developer (sincronizar com savePreferences + NotificationService). */
    private const ALERT_TYPES = [
        'task_completed' => 'Tarefa concluída',
        'task_failed'    => 'Tarefa falhou',
    ];

    /** Tipos de alerta exclusivos de administradores (operação do servidor). */
    private const ADMIN_ALERT_TYPES = [
        'system_resource' => 'Uso de disco/RAM do servidor',
    ];

    private function uid(): int
    {
        return (int) ($this->user->id ?? 0);
    }

    /** Tipos visíveis ao usuário atual: os comuns + os de admin quando ele for admin. */
    private function alertTypes(): array
    {
        $types = self::ALERT_TYPES;
        if ($this->user && $this->user->isAdmin()) {
            $types += self::ADMIN_ALERT_TYPES;
        }
        return $types;
    }

    /** GET /whatsapp — página de configuração. */
    public function index(Request $request): Response
    {
        $uid = $this->uid();
        $service = new WhatsAppService();
        $db = Database::getInstance();

        $connection = $service->getConnection($uid);

        $alertPhones = [];
        if ($connection) {
            $alertPhones = $db->fetchAll(
                "SELECT id, phone, label FROM user_whatsapp_alert_phones WHERE user_id = ? AND whatsapp_connection_id = ? ORDER BY id",
                [$uid, (int) $connection['id']]
            );
        }

        $preferences = [];
        foreach ($this->alertTypes() as $type => $label) {
            $pref = $db->fetch(
                "SELECT enabled FROM user_whatsapp_alert_preferences WHERE user_id = ? AND alert_type = ?",
                [$uid, $type]
            );
            $preferences[$type] = ['label' => $label, 'enabled' => $pref ? (bool) $pref['enabled'] : true];
        }

        return $this->view('whatsapp/index', [
            'title'       => 'Alertas no WhatsApp',
            'pageTitle'   => 'Alertas no WhatsApp',
            'connection'  => $connection,
            'alertPhones' => $alertPhones,
            'preferences' => $preferences,
        ]);
    }

    /** POST /whatsapp/connect — inicia conexão (retorna QR). */
    public function connect(Request $request): JsonResponse
    {
        $result = (new WhatsAppService())->connect($this->uid());
        return $this->json($result, $result['success'] ? 'OK' : 'Erro', $result['success'] ? 200 : 400);
    }

    /** GET /whatsapp/status/{id} — polling do status (AJAX). */
    public function status(Request $request, string $id): JsonResponse
    {
        $connectionId = (int) $id;
        if ($connectionId === 0) {
            return $this->json(['status' => 'invalid'], 'Inválido', 400);
        }
        return $this->json((new WhatsAppService())->checkStatus($connectionId, $this->uid()));
    }

    /** POST /whatsapp/disconnect */
    public function disconnect(Request $request): Response
    {
        $service = new WhatsAppService();
        $conn = $service->getConnection($this->uid());
        if (!$conn) {
            return $this->redirectWithError('/whatsapp', 'Nenhuma conexão encontrada.');
        }
        $res = $service->disconnect((int) $conn['id'], $this->uid());
        return $res['success']
            ? $this->redirectWithSuccess('/whatsapp', 'WhatsApp desconectado.')
            : $this->redirectWithError('/whatsapp', $res['error'] ?? 'Erro ao desconectar.');
    }

    /** POST /whatsapp/phones — adiciona contato de destino. */
    public function addPhone(Request $request): Response
    {
        $uid = $this->uid();
        $phone = trim((string) $request->input('phone'));
        $label = trim((string) $request->input('label'));

        // Normaliza para E.164 de celular BR: 55 + DDD (2) + 9 + 8 dígitos.
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) === 11) {
            $digits = '55' . $digits; // nacional sem código do país
        }
        if (!preg_match('/^55[1-9]{2}9[0-9]{8}$/', $digits)) {
            return $this->redirectWithError('/whatsapp', 'Número de WhatsApp inválido. Use um celular com DDD, ex.: +55 (11) 99999-9999.');
        }
        $clean = '+' . $digits;

        $service = new WhatsAppService();
        $conn = $service->getConnection($uid);
        if (!$conn || ($conn['status'] ?? '') !== 'connected') {
            return $this->redirectWithError('/whatsapp', 'Conecte o WhatsApp antes de adicionar contatos.');
        }

        $db = Database::getInstance();
        $dup = $db->fetch(
            "SELECT id FROM user_whatsapp_alert_phones WHERE user_id = ? AND whatsapp_connection_id = ? AND phone = ?",
            [$uid, (int) $conn['id'], $clean]
        );
        if ($dup) {
            return $this->redirectWithError('/whatsapp', 'Esse contato já está cadastrado.');
        }

        $db->insert('user_whatsapp_alert_phones', [
            'user_id'                => $uid,
            'whatsapp_connection_id' => (int) $conn['id'],
            'phone'                  => $clean,
            'label'                  => mb_substr($label, 0, 100),
        ]);

        return $this->redirectWithSuccess('/whatsapp', 'Contato adicionado.');
    }

    /** POST /whatsapp/phones/{id}/delete */
    public function deletePhone(Request $request, string $id): Response
    {
        $uid = $this->uid();
        $phoneId = (int) $id;
        $db = Database::getInstance();

        $own = $db->fetch("SELECT id FROM user_whatsapp_alert_phones WHERE id = ? AND user_id = ?", [$phoneId, $uid]);
        if (!$own) {
            return $this->redirectWithError('/whatsapp', 'Contato não encontrado.');
        }
        $db->delete('user_whatsapp_alert_phones', 'id = ? AND user_id = ?', [$phoneId, $uid]);
        return $this->redirectWithSuccess('/whatsapp', 'Contato removido.');
    }

    /** POST /whatsapp/preferences */
    public function savePreferences(Request $request): Response
    {
        $uid = $this->uid();
        $db = Database::getInstance();
        foreach (array_keys($this->alertTypes()) as $type) {
            $enabled = $request->input('alert_' . $type) ? 1 : 0;
            $db->execute(
                "INSERT INTO user_whatsapp_alert_preferences (user_id, alert_type, enabled) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)",
                [$uid, $type, $enabled]
            );
        }
        return $this->redirectWithSuccess('/whatsapp', 'Preferências salvas.');
    }

    /** POST /whatsapp/test — envia uma mensagem de teste. */
    public function test(Request $request): JsonResponse
    {
        $sent = (new WhatsAppService())->sendNotification(
            $this->uid(),
            'test',
            'Teste do Ravi Developer — alertas de WhatsApp configurados com sucesso!'
        );
        return $this->json(
            ['success' => $sent],
            $sent ? 'Mensagem de teste enviada!' : 'Falha ao enviar. Verifique a conexão e os contatos.',
            $sent ? 200 : 400
        );
    }
}
