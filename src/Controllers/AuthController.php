<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\{Request, Response, JsonResponse, Session};
use Ravi\Models\User;
use Ravi\Services\PasswordResetService;
use Ravi\Services\{EmailService, NotificationService};

/**
 * Authentication Controller - Versão Simplificada
 */
class AuthController extends Controller
{
    /**
     * Show login form
     */
    public function showLogin(Request $request): Response
    {
        if (Session::get('user') !== null) {
            return $this->redirect('/dashboard');
        }
        
        return $this->view('auth/login');
    }
    
    /**
     * Process login
     */
    public function login(Request $request): Response|JsonResponse
    {
        $email = $request->post('email');
        $password = $request->post('password');
        
        // Validação básica
        if (empty($email) || empty($password)) {
            Session::flash('error', 'E-mail e senha são obrigatórios.');
            return $this->redirect('/login');
        }

        // Freio de força bruta: bloqueia o par (IP, e-mail) após muitas falhas seguidas.
        // Fail-open (nunca tranca por erro interno) e conservador para não pegar quem só
        // errou a senha uma ou duas vezes.
        if (\Ravi\Services\LoginThrottle::blocked($email)) {
            // error_log (não logger()->) para escapar do LOG_LEVEL=error de produção —
            // é a trilha de auditoria do login, mesmo convenção do gateway.
            error_log('[auth] login BLOQUEADO por freio email=' . $email . ' ip=' . (client_ip() ?? '?'));
            Session::flash('error', 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.');
            return $this->redirect('/login');
        }

        // Buscar usuário
        $user = User::findByEmail($email);

        if ($user === null) {
            \Ravi\Services\LoginThrottle::fail($email);
            error_log('[auth] login FALHOU (e-mail inexistente) email=' . $email . ' ip=' . (client_ip() ?? '?'));
            Session::flash('error', 'E-mail ou senha incorretos.');
            return $this->redirect('/login');
        }

        // Verificar senha
        if (!$user->verifyPassword($password)) {
            \Ravi\Services\LoginThrottle::fail($email);
            error_log('[auth] login FALHOU (senha incorreta) user=' . (int) $user->id . ' ip=' . (client_ip() ?? '?'));
            Session::flash('error', 'E-mail ou senha incorretos.');
            return $this->redirect('/login');
        }
        
        // Conta aguardando aprovação / rejeitada / desativada
        $approval = $user->approval_status ?? 'approved';
        if ($approval === 'pending') {
            if (empty($user->email_verified_at)) {
                Session::flash('error', 'Confirme seu e-mail para concluir o cadastro. Verifique sua caixa de entrada (e o spam).');
                return $this->redirect('/login');
            }
            Session::flash('error', 'Sua conta está aguardando aprovação de um administrador.');
            return $this->redirect('/login');
        }
        if ($approval === 'rejected') {
            Session::flash('error', 'Seu cadastro não foi aprovado. Entre em contato com o administrador.');
            return $this->redirect('/login');
        }
        if (!$user->is_active) {
            Session::flash('error', 'Sua conta está desativada.');
            return $this->redirect('/login');
        }
        
        $remember = (string) $request->post('remember') === '1';

        // Segunda etapa. Um navegador só PULA o 2º fator se for confiável — e confiável
        // agora significa "já passou pelo 2º fator neste navegador" (trusted_2fa), não
        // apenas "manter conectado". Quem tem TOTP usa TOTP; senão, código por e-mail
        // (desde que o SMTP esteja configurado, para nunca trancar o painel).
        $dev = (new \Ravi\Services\AuthDeviceService())->fromCookie();
        $trusted = $dev !== null && (int) $dev['user_id'] === (int) $user->id && $dev['trusted_2fa'];
        if ($trusted) {
            \Ravi\Services\LoginThrottle::clear($email);
            return $this->completeLogin($user, $remember, true);
        }
        if (!empty($user->totp_enabled)) {
            Session::set('pending_2fa', ['user_id' => (int) $user->id, 'remember' => $remember, 'method' => 'totp']);
            return $this->redirect('/login/2fa');
        }
        if ((new EmailService())->isConfigured()) {
            return $this->startEmailChallenge($user, $remember);
        }

        // Conta sem 2º fator disponível (sem TOTP e SMTP desligado): entra direto.
        \Ravi\Services\LoginThrottle::clear($email);
        return $this->completeLogin($user, $remember, false);
    }

    /** A conta tem um segundo fator disponível hoje (TOTP ativo ou e-mail configurado)? */
    private function hasSecondFactor(User $user): bool
    {
        if (!empty($user->totp_enabled)) {
            return true;
        }
        try {
            return (new EmailService())->isConfigured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Gera e envia o código de login por e-mail e arma a etapa 2 na sessão.
     * A tela seguinte NÃO revela o e-mail de destino (anti-enumeração: quem chegou
     * aqui digitou a senha certa, mas ainda assim não ganha nenhuma informação).
     */
    private function startEmailChallenge(User $user, bool $remember): Response
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $sent = false;
        try {
            $sent = (new EmailService())->sendLoginCode((string) $user->email, (string) $user->name, $code, 10);
        } catch (\Throwable $e) {
            logger()->error('2FA por e-mail: envio falhou: ' . $e->getMessage());
        }
        if (!$sent) {
            // Fail-closed: sem código entregue não há login — melhor negar do que
            // abrir uma exceção de segurança quando o SMTP soluça.
            logger()->error('2FA por e-mail: código não enviado para user ' . $user->id);
            Session::flash('error', 'Não foi possível enviar o código de verificação. Tente novamente em instantes.');
            return $this->redirect('/login');
        }
        Session::set('pending_2fa', [
            'user_id'    => (int) $user->id,
            'remember'   => $remember,
            'method'     => 'email',
            'code_hash'  => hash('sha256', $code),
            'expires_at' => time() + 600,
            'attempts'   => 0,
            'last_sent'  => time(),
        ]);
        return $this->redirect('/login/2fa');
    }

    /**
     * Finaliza o login: sessão + registro p/ WebSocket + (opcional) dispositivo confiável.
     *
     * $passed2fa = o 2º fator foi CUMPRIDO neste login (TOTP/e-mail conferido agora, ou
     * navegador já confiável). Só nesse caso o "manter conectado" marca o dispositivo como
     * trusted_2fa — senão o cookie viraria um bypass permanente do 2FA (era o furo). Se a
     * conta não tem 2º fator disponível, o "manter conectado" funciona normalmente.
     */
    private function completeLogin(User $user, bool $remember, bool $passed2fa = false): Response
    {
        \Ravi\Services\LoginThrottle::clear((string) $user->email);
        error_log('[auth] login OK user=' . (int) $user->id . ' ip=' . (client_ip() ?? '?') . ' 2fa=' . ($passed2fa ? 'sim' : 'nao'));

        Session::regenerate();
        Session::set('user', $user->toSessionData());
        Session::remove('pending_2fa');

        try {
            \Ravi\Core\Database::getInstance()->query(
                "REPLACE INTO user_sessions (id, user_id, ip_address, user_agent, payload, last_activity, ws_token) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    session_id(), $user->id,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
                    serialize(['user' => ['id' => $user->id]]), time(), ws_token(),
                ]
            );
        } catch (\Throwable $e) {
            logger()->warning('Falha ao registrar user_session: ' . $e->getMessage());
        }

        if ($remember) {
            // Confia neste navegador (pular 2FA no futuro) SÓ se o 2º fator foi cumprido
            // agora, ou se a conta não tem 2º fator para pular.
            $trust = $passed2fa || !$this->hasSecondFactor($user);
            try { (new \Ravi\Services\AuthDeviceService())->remember((int) $user->id, $trust); } catch (\Throwable $e) {}
        }
        return $this->redirect('/dashboard');
    }

    /** Tela do segundo fator (após senha correta). */
    public function show2fa(Request $request): Response
    {
        $pending = Session::get('pending_2fa');
        if (!is_array($pending)) {
            return $this->redirect('/login');
        }
        return $this->view('auth/two-factor', [
            'title'  => 'Verificação em duas etapas',
            'method' => (string) ($pending['method'] ?? 'totp'),
        ]);
    }

    /** Verifica o código da etapa 2 (TOTP ou e-mail) e finaliza o login. */
    public function verify2fa(Request $request): Response
    {
        $pending = Session::get('pending_2fa');
        if (!is_array($pending)) {
            return $this->redirect('/login');
        }
        $user = User::find((int) ($pending['user_id'] ?? 0));
        if (!$user) {
            Session::remove('pending_2fa');
            return $this->redirect('/login');
        }
        $code = trim((string) $request->post('code'));

        if (($pending['method'] ?? 'totp') === 'email') {
            // Código por e-mail: expira em 10 min e aguenta 5 erros — depois, senha de novo.
            if (time() > (int) ($pending['expires_at'] ?? 0)) {
                Session::remove('pending_2fa');
                Session::flash('error', 'Código expirado. Faça login novamente.');
                return $this->redirect('/login');
            }
            $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
            if ($pending['attempts'] > 5) {
                Session::remove('pending_2fa');
                Session::flash('error', 'Muitas tentativas. Faça login novamente.');
                return $this->redirect('/login');
            }
            Session::set('pending_2fa', $pending);
            if ($code === '' || !hash_equals((string) ($pending['code_hash'] ?? ''), hash('sha256', $code))) {
                error_log('[auth] 2FA e-mail INVALIDO user=' . (int) $user->id . ' tentativa=' . $pending['attempts'] . ' ip=' . (client_ip() ?? '?'));
                Session::flash('error', 'Código inválido. Tente novamente.');
                return $this->redirect('/login/2fa');
            }
            // 2º fator (e-mail) cumprido agora → pode confiar no navegador.
            return $this->completeLogin($user, (bool) ($pending['remember'] ?? false), true);
        }

        if (empty($user->totp_enabled)) {
            Session::remove('pending_2fa');
            return $this->redirect('/login');
        }
        $secret = '';
        try { $secret = (string) (new \Ravi\Core\Encryption())->decrypt((string) $user->totp_secret); } catch (\Throwable $e) {}
        if ($secret === '' || !(new \Ravi\Services\TotpService())->verify($secret, $code)) {
            error_log('[auth] 2FA TOTP INVALIDO user=' . (int) $user->id . ' ip=' . (client_ip() ?? '?'));
            Session::flash('error', 'Código inválido. Tente novamente.');
            return $this->redirect('/login/2fa');
        }
        // 2º fator (TOTP) cumprido agora → pode confiar no navegador.
        return $this->completeLogin($user, (bool) ($pending['remember'] ?? false), true);
    }

    /** Reenvia o código por e-mail (throttle de 60s; não cria etapa nova). */
    public function resend2fa(Request $request): Response
    {
        $pending = Session::get('pending_2fa');
        if (!is_array($pending) || ($pending['method'] ?? '') !== 'email') {
            return $this->redirect('/login');
        }
        if (time() - (int) ($pending['last_sent'] ?? 0) < 60) {
            Session::flash('error', 'Aguarde um minuto para reenviar o código.');
            return $this->redirect('/login/2fa');
        }
        $user = User::find((int) ($pending['user_id'] ?? 0));
        if (!$user) {
            Session::remove('pending_2fa');
            return $this->redirect('/login');
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $sent = false;
        try {
            $sent = (new EmailService())->sendLoginCode((string) $user->email, (string) $user->name, $code, 10);
        } catch (\Throwable $e) {
            logger()->error('2FA por e-mail: reenvio falhou: ' . $e->getMessage());
        }
        if ($sent) {
            $pending['code_hash']  = hash('sha256', $code);
            $pending['expires_at'] = time() + 600;
            $pending['last_sent']  = time();
            Session::set('pending_2fa', $pending);
            Session::flash('success', 'Enviamos um novo código.');
        } else {
            Session::flash('error', 'Não foi possível reenviar o código. Tente novamente em instantes.');
        }
        return $this->redirect('/login/2fa');
    }
    
    /**
     * Show registration form
     */
    public function showRegister(Request $request): Response
    {
        if (Session::get('user') !== null) {
            return $this->redirect('/dashboard');
        }
        
        return $this->view('auth/register');
    }
    
    /**
     * Process registration
     */
    public function register(Request $request): Response|JsonResponse
    {
        $name = trim((string) $request->post('name'));
        $email = strtolower(trim((string) $request->post('email')));
        $password = (string) $request->post('password');

        // Validação básica
        if ($name === '' || $email === '' || $password === '') {
            Session::flash('error', 'Todos os campos são obrigatórios.');
            return $this->redirect('/register');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Informe um e-mail válido.');
            return $this->redirect('/register');
        }
        if (strlen($password) < 8) {
            Session::flash('error', 'A senha deve ter pelo menos 8 caracteres.');
            return $this->redirect('/register');
        }
        if (User::findByEmail($email) !== null) {
            Session::flash('error', 'Este e-mail já está cadastrado.');
            return $this->redirect('/register');
        }

        // Cria a conta como PEDIDO DE ACESSO: pendente de aprovação, NÃO ativa e SEM login.
        $user = User::register($name, $email, $password);
        if ($user === null) {
            Session::flash('error', 'Erro ao criar conta. Tente novamente.');
            return $this->redirect('/register');
        }
        $rawToken = bin2hex(random_bytes(32));
        $user->update([
            'is_active'          => 0,
            'approval_status'    => 'pending',
            'email_verify_token' => hash('sha256', $rawToken),
        ]);

        // 1º gate: confirmar o e-mail. Os admins só são avisados DEPOIS da confirmação
        // (evita fila de cadastros com e-mail falso). best-effort.
        $verifyUrl = rtrim((string) env('APP_URL', ''), '/') . '/verify-email/' . $rawToken;
        try { (new EmailService())->sendEmailVerification($email, $name, $verifyUrl); } catch (\Throwable $e) {}

        Session::flash('success', 'Cadastro recebido! Enviamos um e-mail de confirmação para ' . $email . '. Confirme o e-mail para concluir — depois um administrador analisa o acesso.');
        return $this->redirect('/login');
    }
    
    /**
     * Logout
     */
    public function logout(Request $request): Response|JsonResponse
    {
        // Remove a sessão do banco e esquece este navegador (cookie de dispositivo confiável).
        try { \Ravi\Core\Database::getInstance()->query("DELETE FROM user_sessions WHERE id = ?", [session_id()]); } catch (\Throwable $e) {}
        try { (new \Ravi\Services\AuthDeviceService())->forget(); } catch (\Throwable $e) {}
        Session::destroy();
        return $this->redirect('/login');
    }
    
    /**
     * Show forgot password form
     */
    public function showForgotPassword(Request $request): Response
    {
        return $this->view('auth/forgot-password');
    }
    
    /**
     * Process forgot password
     */
    public function forgotPassword(Request $request): Response|JsonResponse
    {
        $email = strtolower(trim((string) $request->post('email')));

        // Sempre responde igual (não revela se o e-mail existe — anti-enumeração).
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                (new PasswordResetService())->sendLink($email, false, 24);
            } catch (\Throwable $e) {
                logger()->error('forgotPassword falhou: ' . $e->getMessage());
            }
        }

        Session::flash('success', 'Se o e-mail existir, você receberá um link de redefinição.');
        return $this->redirect('/login');
    }
    
    /**
     * Show reset password form
     */
    public function showResetPassword(Request $request): Response
    {
        return $this->view('auth/reset-password', [
            'token' => (string) $request->get('token', ''),
            'email' => (string) $request->get('email', ''),
        ]);
    }
    
    /**
     * Process reset password
     */
    public function resetPassword(Request $request): Response|JsonResponse
    {
        $email    = strtolower(trim((string) $request->post('email')));
        $token    = (string) $request->post('token');
        $password = (string) $request->post('password');
        $confirm  = (string) $request->post('password_confirmation');

        $errors = [];
        if (strlen($password) < 8) {
            $errors['password'] = 'A senha deve ter no mínimo 8 caracteres.';
        }
        if ($password !== $confirm) {
            $errors['password_confirmation'] = 'As senhas não conferem.';
        }

        $svc = new PasswordResetService();

        // Token inválido/expirado: manda recomeçar (não revela detalhes).
        if (!$svc->verify($email, $token)) {
            Session::flash('error', 'Link inválido ou expirado. Solicite um novo.');
            return $this->redirect('/forgot-password');
        }

        // Erros de senha: re-renderiza o formulário preservando token/e-mail.
        if (!empty($errors)) {
            return $this->view('auth/reset-password', [
                'token'  => $token,
                'email'  => $email,
                'errors' => $errors,
            ]);
        }

        $user = User::findByEmail($email);
        if ($user) {
            $user->updatePassword($password);
        }
        $svc->consume($email);

        Session::flash('success', 'Senha alterada com sucesso! Faça login.');
        return $this->redirect('/login');
    }
    
    /**
     * Confirma o e-mail pelo link. Só depois disso os admins são avisados para aprovar.
     */
    public function verifyEmail(Request $request, string $token): Response
    {
        $hash = hash('sha256', $token);
        $user = User::findBy('email_verify_token', $hash);
        if (!$user) {
            Session::flash('error', 'Link de confirmação inválido ou já utilizado.');
            return $this->redirect('/login');
        }

        $user->update(['email_verified_at' => date('Y-m-d H:i:s'), 'email_verify_token' => null]);

        // Agora sim: avisa os admins para analisarem o acesso.
        try { (new NotificationService())->registrationPending($user); } catch (\Throwable $e) {}

        Session::flash('success', 'E-mail confirmado! Seu acesso está em análise — você será avisado quando for aprovado.');
        return $this->redirect('/login');
    }

    /**
     * Reenvia o e-mail de confirmação para um cadastro pendente não-verificado.
     */
    public function resendVerification(Request $request): Response|JsonResponse
    {
        $email = strtolower(trim((string) $request->post('email')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = User::findByEmail($email);
            if ($user && empty($user->email_verified_at) && ($user->approval_status ?? 'approved') === 'pending') {
                $raw = bin2hex(random_bytes(32));
                $user->update(['email_verify_token' => hash('sha256', $raw)]);
                $url = rtrim((string) env('APP_URL', ''), '/') . '/verify-email/' . $raw;
                try { (new EmailService())->sendEmailVerification($email, (string) $user->name, $url); } catch (\Throwable $e) {}
            }
        }
        Session::flash('success', 'Se houver um cadastro pendente com esse e-mail, reenviamos a confirmação.');
        return $this->redirect('/login');
    }
}