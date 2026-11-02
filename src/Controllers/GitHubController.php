<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\Request;
use Ravi\Core\Response;
use Ravi\Core\Session;
use Ravi\Models\User;
use Ravi\Services\GitHubService;

/**
 * Conexão da conta GitHub por usuário (OAuth App clássico).
 *
 * Setup do app (client_id/secret) é uma vez, pelo admin (config/github.php + .env).
 * Depois é self-service: cada usuário conecta a própria conta aqui. O token fica
 * cifrado em users.github_token_encrypted e é persistente (não expira).
 */
class GitHubController extends Controller
{
    /** Página "Minha Conta → GitHub": estado conectado/desconectado. */
    public function page(Request $request): Response
    {
        $gh = new GitHubService();
        return $this->view('settings/github', [
            'title'        => 'GitHub',
            'configured'   => $gh->isConfigured(),
            'connected'    => $this->user && $this->user->hasGithub(),
            'githubLogin'  => $this->user->github_login ?? null,
            'githubAvatar' => $this->user->github_avatar_url ?? null,
            'connectedAt'  => $this->user->github_connected_at ?? null,
        ]);
    }

    /** Inicia o fluxo OAuth: gera `state`, guarda na sessão e redireciona ao GitHub. */
    public function connect(Request $request): Response
    {
        $gh = new GitHubService();
        if (!$gh->isConfigured()) {
            return $this->redirect('/settings/github')
                ->with('error', 'A integração GitHub ainda não foi configurada pelo administrador.');
        }
        $state = bin2hex(random_bytes(16));
        Session::set('github_oauth_state', $state);
        return $this->redirect($gh->authorizeUrl($state));
    }

    /** Callback do GitHub: valida `state`, troca `code` por token e grava cifrado. */
    public function callback(Request $request): Response
    {
        $gh = new GitHubService();

        $error = $request->query('error');
        if ($error) {
            return $this->redirect('/settings/github')
                ->with('error', 'Autorização cancelada no GitHub.');
        }

        $state    = (string) $request->query('state', '');
        $expected = (string) Session::get('github_oauth_state', '');
        Session::remove('github_oauth_state');
        if ($state === '' || !hash_equals($expected, $state)) {
            return $this->redirect('/settings/github')
                ->with('error', 'Falha de validação (state). Tente conectar novamente.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $this->redirect('/settings/github')->with('error', 'Código de autorização ausente.');
        }

        $tok = $gh->exchangeCode($code);
        if (!($tok['ok'] ?? false)) {
            return $this->redirect('/settings/github')
                ->with('error', 'Não foi possível obter o token: ' . ($tok['error'] ?? 'erro'));
        }

        $info = $gh->getUser($tok['token']);
        if (!($info['ok'] ?? false)) {
            return $this->redirect('/settings/github')
                ->with('error', 'Token obtido, mas falhou ao ler a conta: ' . ($info['error'] ?? 'erro'));
        }

        $u = User::find((int) $this->user->id);
        $u->setGithubConnection($tok['token'], $info['login'], $info['avatar'] ?? null, $tok['scope'] ?? '');

        // Atualiza a sessão para a UI refletir o estado conectado imediatamente.
        Session::set('user', $u->toSessionData());

        return $this->redirect('/settings/github')
            ->with('success', 'GitHub conectado como @' . $info['login'] . '.');
    }

    /** Lista os repositórios do usuário (JSON) — usado na criação de projeto a partir do Git. */
    public function repos(Request $request): \Ravi\Core\JsonResponse
    {
        if (!$this->user || !$this->user->hasGithub()) {
            return $this->json(['ok' => false, 'error' => 'Conecte sua conta GitHub primeiro.']);
        }
        $repos = (new GitHubService())->listRepos($this->user->getGithubToken());
        return $this->json(['ok' => true, 'repos' => $repos]);
    }

    /** Desconecta a conta GitHub (apaga o token; não mexe em repositórios). */
    public function disconnect(Request $request): Response
    {
        $u = User::find((int) $this->user->id);
        if ($u) {
            $u->disconnectGithub();
            Session::set('user', $u->toSessionData());
        }
        return $this->redirect('/settings/github')->with('success', 'Conta GitHub desconectada.');
    }
}
