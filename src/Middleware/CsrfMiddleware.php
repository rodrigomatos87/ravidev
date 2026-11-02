<?php

declare(strict_types=1);

namespace Ravi\Middleware;

use Ravi\Core\Request;
use Ravi\Core\Response;
use Ravi\Core\Session;

/**
 * Middleware de proteção CSRF
 */
class CsrfMiddleware
{
    /**
     * Métodos que requerem verificação CSRF
     */
    private array $protectedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
    
    /**
     * Rotas excluídas da verificação. Os webhooks (/webhook/*, /webhooks/*) ficam fora deste
     * middleware por não entrarem no grupo que o aplica; eles validam assinatura HMAC própria.
     */
    private array $excludedPaths = [];
    
    /**
     * Verifica token CSRF
     */
    public function handle(Request $request): ?Response
    {
        // Gera token se não existir
        if (!Session::has('_csrf_token')) {
            Session::set('_csrf_token', bin2hex(random_bytes(32)));
        }
        
        // Verifica apenas métodos protegidos
        if (!in_array($request->method(), $this->protectedMethods)) {
            return null; // Continua
        }
        
        // Verifica se path está excluído
        $path = $request->path();
        foreach ($this->excludedPaths as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return null; // Continua
            }
        }
        
        // Obtém token do request
        $token = $request->input('_csrf_token') 
              ?? $request->post('_csrf_token')
              ?? $request->header('X-CSRF-TOKEN')
              ?? '';
        
        // Valida token
        if (!$this->validateToken($token)) {
            if ($request->isAjax() || $request->expectsJson()) {
                return \Ravi\Core\JsonResponse::error('Token CSRF inválido ou expirado', 419);
            }
            
            Session::flash('error', 'Sessão expirada. Por favor, tente novamente.');
            
            header('Location: ' . ($request->header('Referer') ?? '/'));
            exit;
        }
        
        return null; // Continua
    }
    
    /**
     * Valida token CSRF
     */
    private function validateToken(string $token): bool
    {
        $sessionToken = Session::get('_csrf_token');
        
        if (!$sessionToken || !$token) {
            return false;
        }
        
        return hash_equals($sessionToken, $token);
    }
}
