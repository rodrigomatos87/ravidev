<?php

declare(strict_types=1);

namespace Ravi\Middleware;

use Ravi\Core\Request;
use Ravi\Core\Response;
use Ravi\Core\Session;

/**
 * Middleware de autenticação
 */
class AuthMiddleware
{
    /**
     * Verifica se usuário está autenticado
     */
    public function handle(Request $request): ?Response
    {
        // #8 — "Manter conectado": sem sessão, mas com cookie de dispositivo válido,
        // restaura a sessão automaticamente (login persistente). SÓ vale para dispositivo
        // que passou pelo 2º fator (trusted_2fa): senão o cookie de "manter conectado" seria
        // um login sem senha e sem 2FA — o furo que este cookie NÃO pode abrir.
        if (!Session::has('user') && !Session::has('user_id')) {
            try {
                $dev = (new \Ravi\Services\AuthDeviceService())->fromCookie();
                if ($dev !== null && !empty($dev['trusted_2fa'])) {
                    $u = \Ravi\Models\User::find((int) $dev['user_id']);
                    if ($u && $u->is_active && ($u->approval_status ?? 'approved') === 'approved') {
                        Session::set('user', $u->toSessionData());
                    }
                }
            } catch (\Throwable $e) { /* nunca derruba a request */ }
        }

        if (!Session::has('user') && !Session::has('user_id')) {
            // Se for requisição AJAX/API, retorna JSON (Response não tem withStatus — era 500).
            if ($request->isAjax() || $request->expectsJson()) {
                return \Ravi\Core\JsonResponse::error('Não autenticado', 401);
            }
            
            // Salva URL original para redirect após login
            Session::set('intended_url', $request->uri());
            
            // Redirect para login
            header('Location: /login');
            exit;
        }

        // Mantém o registro em `user_sessions` fresco para a autenticação do WebSocket.
        // A linha só era escrita no LOGIN e expira em 24h, enquanto a sessão de arquivo do
        // PHP dura dias — então o chat acusava "sessão inválida ou expirada" toda vez que o
        // login passava de 24h (ou o id da sessão não batia). Renovando a cada request
        // autenticada, a linha sempre existe e corresponde ao session_id() atual.
        try {
            $user   = Session::get('user');
            $userId = is_array($user) ? ($user['id'] ?? null) : null;
            $userId = $userId ?? Session::get('user_id');
            $sid    = session_id();

            if ($userId && $sid) {
                \Ravi\Core\Database::getInstance()->query(
                    "REPLACE INTO user_sessions (id, user_id, ip_address, user_agent, payload, last_activity, ws_token) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $sid,
                        $userId,
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
                        serialize(['user' => ['id' => $userId]]),
                        time(),
                        ws_token(),
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Nunca derruba a request por causa do registro de sessão do WS.
        }

        return null; // Continua para o próximo middleware/handler
    }
}
