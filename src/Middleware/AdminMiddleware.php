<?php

declare(strict_types=1);

namespace Ravi\Middleware;

use Ravi\Core\Request;
use Ravi\Core\Response;
use Ravi\Core\JsonResponse;
use Ravi\Core\Session;
use Ravi\Models\User;

/**
 * Middleware de administrador.
 *
 * Só permite quem tem role='admin'. Durante uma impersonação (admin "entrou como" outro
 * usuário), o usuário efetivo da sessão NÃO é admin — então consideramos o IMPERSONADOR
 * (o admin real, guardado em 'impersonator') para liberar as rotas de admin.
 */
class AdminMiddleware
{
    public function handle(Request $request): ?Response
    {
        $id = (Session::get('impersonator')['id'] ?? null)
            ?: (Session::get('user')['id'] ?? null);

        $user = $id ? User::find((int) $id) : null;

        if (!$user || !$user->isAdmin()) {
            if ($request->isAjax() || $request->expectsJson()) {
                return JsonResponse::error('Acesso restrito a administradores', 403);
            }
            http_response_code(403);
            header('Location: /dashboard');
            exit;
        }

        return null;
    }
}
