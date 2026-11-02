<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\{Request, Response, JsonResponse, Session};
use Ravi\Models\User;

/**
 * Base Controller
 */
abstract class Controller
{
    protected ?User $user = null;
    
    public function __construct()
    {
        $this->loadUser();
    }
    
    /**
     * Carrega usuário da sessão
     */
    protected function loadUser(): void
    {
        $userData = Session::get('user');
        
        if ($userData && isset($userData['id'])) {
            $this->user = User::find($userData['id']);
        }
    }
    
    /**
     * Retorna dados do usuário atual da sessão
     */
    protected function getCurrentUser(): ?array
    {
        return Session::get('user');
    }
    
    /**
     * Renderiza view
     */
    protected function view(string $name, array $data = []): Response
    {
        // Adiciona dados globais
        $data['user'] = $this->user?->toArray();
        $data['flash'] = Session::getFlash();

        // Projetos do usuário para a sidebar global (se a página ainda não forneceu)
        if (!isset($data['projects']) && $this->user) {
            $data['projects'] = \Ravi\Models\Project::findByUser($this->user->id);
        }

        // Limpa flash após uso
        Session::clearFlash();

        return Response::view($name, $data);
    }
    
    /**
     * Resposta JSON de sucesso
     */
    protected function json(mixed $data = null, string $message = 'Success', int $status = 200): JsonResponse
    {
        return JsonResponse::success($data, $message, $status);
    }
    
    /**
     * Resposta JSON de erro
     */
    protected function jsonError(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        return JsonResponse::error($message, $status, $errors);
    }
    
    /**
     * Redireciona
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
    
    /**
     * Redireciona para a página anterior
     */
    protected function back(): Response
    {
        return Response::back();
    }
    
    /**
     * Redireciona com mensagem de sucesso
     */
    protected function redirectWithSuccess(string $url, string $message): Response
    {
        Session::flash('success', $message);
        return $this->redirect($url);
    }
    
    /**
     * Redireciona com mensagem de erro
     */
    protected function redirectWithError(string $url, string $message): Response
    {
        Session::flash('error', $message);
        return $this->redirect($url);
    }
    
    /**
     * Valida request
     * 
     * @return array{valid: bool, errors: array, data: array}
     */
    protected function validate(Request $request, array $rules): array
    {
        return $request->validate($rules);
    }
    
    /**
     * Aborta com erro
     */
    protected function abort(int $code, string $message = ''): never
    {
        http_response_code($code);
        
        $messages = [
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ];
        
        $message = $message ?: ($messages[$code] ?? 'Error');
        
        echo "<h1>{$code} - {$message}</h1>";
        exit;
    }
    
    /**
     * Verifica se request é AJAX/API
     */
    protected function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->isAjax();
    }
    
    /**
     * Verifica CSRF token
     */
    protected function verifyCsrf(Request $request): bool
    {
        $token = $request->post('_csrf_token') ?? $request->header('x-csrf-token');
        $sessionToken = Session::get('_csrf_token');
        
        if (empty($token) || empty($sessionToken)) {
            return false;
        }
        
        return hash_equals($sessionToken, $token);
    }
    
    /**
     * Requer autenticação
     */
    protected function requireAuth(Request $request): void
    {
        if ($this->user === null) {
            if ($this->wantsJson($request)) {
                echo JsonResponse::unauthorized()->getContent();
                exit;
            }
            
            Session::flash('error', 'Você precisa fazer login para continuar.');
            redirect('/login');
        }
    }
    
    /**
     * Requer API key configurada
     */
    protected function requireApiKey(Request $request): void
    {
        // O motor usa o token da plataforma (Claude Code / assinatura Max) — não exige
        // chave de API por usuário. Mantido como alias de autenticação por compatibilidade.
        $this->requireAuth($request);
    }
}