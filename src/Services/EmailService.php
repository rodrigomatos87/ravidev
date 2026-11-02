<?php

declare(strict_types=1);

namespace Ravi\Services;

/**
 * Serviço de envio de e-mails via SMTP
 */
class EmailService
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private string $encryption;
    private bool $enabled;
    private string $lastError = '';

    public function __construct()
    {
        // As configurações de SMTP definidas pela tela de admin (tabela app_settings via Setting)
        // têm precedência sobre o .env. A senha fica armazenada CRIPTOGRAFADA (mail_password_enc).
        $s = \Ravi\Models\Setting::all();

        $this->host       = $s['mail_host']        ?? env('MAIL_HOST', 'localhost');
        $this->port       = (int) ($s['mail_port'] ?? env('MAIL_PORT', 587));
        $this->username   = $s['mail_username']    ?? env('MAIL_USERNAME', '');
        $this->fromEmail  = $s['mail_from_address']?? (env('MAIL_FROM_ADDRESS', '') ?: (string) config('urls.noreply'));
        $this->fromName   = $s['mail_from_name']   ?? env('MAIL_FROM_NAME', 'Ravi Developer');
        $this->encryption = $s['mail_encryption']  ?? env('MAIL_ENCRYPTION', 'tls');

        // Senha: prioriza a do store (criptografada); senão, .env.
        $this->password = '';
        if (!empty($s['mail_password_enc'])) {
            try {
                $enc = new \Ravi\Core\Encryption();
                $this->password = $enc->decrypt($s['mail_password_enc']);
            } catch (\Throwable $e) {
                logger()->error('Falha ao descriptografar senha SMTP: ' . $e->getMessage());
                $this->password = '';
            }
        } else {
            $this->password = env('MAIL_PASSWORD', '');
        }

        $this->enabled = !empty($this->host) && !empty($this->username) && !empty($this->password);
    }

    /** Endereço "from" atual (para telas de admin). */
    public function getFromAddress(): string
    {
        return $this->fromEmail;
    }
    
    /**
     * Verifica se o serviço de email está configurado
     */
    public function isConfigured(): bool
    {
        return $this->enabled;
    }
    
    /**
     * Envia email
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        if (!$this->enabled) {
            $this->lastError = 'SMTP não configurado (host, usuário e senha são obrigatórios).';
            logger()->warning('Email não enviado - SMTP não configurado', [
                'to' => $to,
                'subject' => $subject
            ]);
            return false;
        }
        $this->lastError = '';

        try {
            $socket = $this->connect();
            
            if (!$socket) {
                throw new \RuntimeException('Não foi possível conectar ao servidor SMTP');
            }
            
            // Lê resposta inicial
            $this->readResponse($socket);
            
            // EHLO
            $this->sendCommand($socket, "EHLO " . gethostname());
            
            // STARTTLS se necessário
            if ($this->encryption === 'tls') {
                $this->sendCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->sendCommand($socket, "EHLO " . gethostname());
            }
            
            // AUTH LOGIN
            $this->sendCommand($socket, "AUTH LOGIN");
            $this->sendCommand($socket, base64_encode($this->username));
            $this->sendCommand($socket, base64_encode($this->password));
            
            // MAIL FROM
            $this->sendCommand($socket, "MAIL FROM:<{$this->fromEmail}>");
            
            // RCPT TO
            $this->sendCommand($socket, "RCPT TO:<{$to}>");
            
            // DATA
            $this->sendCommand($socket, "DATA");
            
            // Constrói mensagem
            $message = $this->buildMessage($to, $subject, $htmlBody, $textBody);
            
            // Envia corpo
            fwrite($socket, $message . "\r\n.\r\n");
            $this->readResponse($socket);
            
            // QUIT
            $this->sendCommand($socket, "QUIT");
            
            fclose($socket);
            
            logger()->info('Email enviado', ['to' => $to, 'subject' => $subject]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            logger()->error('Falha ao enviar email', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /** Última mensagem de erro de envio (para a tela de teste de SMTP). */
    public function getLastError(): string
    {
        return $this->lastError;
    }
    
    /**
     * Conecta ao servidor SMTP
     */
    private function connect()
    {
        $protocol = $this->encryption === 'ssl' ? 'ssl://' : '';
        $address = $protocol . $this->host . ':' . $this->port;
        
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        
        return @stream_socket_client(
            $address,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );
    }
    
    /**
     * Envia comando SMTP
     */
    private function sendCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->readResponse($socket);
    }
    
    /**
     * Lê resposta do servidor
     */
    private function readResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        
        $code = (int) substr($response, 0, 3);
        
        if ($code >= 400) {
            throw new \RuntimeException("Erro SMTP: {$response}");
        }
        
        return $response;
    }
    
    /**
     * Constrói mensagem de email
     */
    private function buildMessage(string $to, string $subject, string $htmlBody, ?string $textBody): string
    {
        $boundary = md5(uniqid((string) time()));
        
        $headers = [
            "MIME-Version: 1.0",
            "From: {$this->fromName} <{$this->fromEmail}>",
            "To: {$to}",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\""
        ];
        
        $body = "";
        
        // Versão texto
        if ($textBody) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($textBody)) . "\r\n";
        }
        
        // Versão HTML
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        
        $body .= "--{$boundary}--";
        
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }
    
    /**
     * Envia email de reset de senha
     */
    public function sendPasswordReset(string $to, string $userName, string $resetUrl): bool
    {
        $subject = 'Redefinição de Senha - Ravi Developer';
        
        $html = $this->renderTemplate('password-reset', [
            'userName' => $userName,
            'resetUrl' => $resetUrl,
            'expireHours' => 24
        ]);
        
        $text = "Olá {$userName},\n\n";
        $text .= "Você solicitou a redefinição de sua senha.\n\n";
        $text .= "Clique no link abaixo para criar uma nova senha:\n";
        $text .= "{$resetUrl}\n\n";
        $text .= "Este link expira em 24 horas.\n\n";
        $text .= "Se você não solicitou esta alteração, ignore este email.\n\n";
        $text .= "Atenciosamente,\nEquipe Ravi Developer";
        
        return $this->send($to, $subject, $html, $text);
    }

    /**
     * Convite para um novo membro definir a própria senha (link de acesso).
     */
    public function sendInvite(string $to, string $userName, string $inviteUrl): bool
    {
        $subject = 'Você foi convidado para o Ravi Developer';
        $appName = $this->fromName ?: 'Ravi Developer';

        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#222">'
              . '<h2 style="color:#7c6cff">Bem-vindo ao ' . htmlspecialchars($appName) . '</h2>'
              . '<p>Olá ' . htmlspecialchars($userName) . ', uma conta foi criada para você.</p>'
              . '<p>Clique no botão abaixo para definir sua senha e acessar a plataforma:</p>'
              . '<p style="margin:24px 0"><a href="' . htmlspecialchars($inviteUrl) . '" '
              . 'style="background:#7c6cff;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Definir minha senha</a></p>'
              . '<p style="color:#666;font-size:13px">Ou copie este link no navegador:<br>' . htmlspecialchars($inviteUrl) . '</p>'
              . '<p style="color:#666;font-size:13px">Este link expira em alguns dias. Se não era esperado, ignore este e-mail.</p>'
              . '</div>';

        $text  = "Ola {$userName},\n\n";
        $text .= "Uma conta foi criada para voce no {$appName}.\n";
        $text .= "Defina sua senha acessando:\n{$inviteUrl}\n\n";
        $text .= "Se nao era esperado, ignore este e-mail.";

        return $this->send($to, $subject, $html, $text);
    }

    /**
     * Código de verificação do login (segunda etapa por e-mail).
     */
    public function sendLoginCode(string $to, string $userName, string $code, int $expireMinutes = 10): bool
    {
        $appName = $this->fromName ?: 'Ravi Developer';
        $subject = 'Seu código de acesso — ' . $appName;
        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#222">'
              . '<h2 style="color:#7c6cff">Código de acesso</h2>'
              . '<p>Olá ' . htmlspecialchars($userName) . ', use o código abaixo para concluir seu login no <strong>' . htmlspecialchars($appName) . '</strong>:</p>'
              . '<p style="margin:24px 0;text-align:center"><span style="display:inline-block;background:#f4f2ff;color:#3b2fa3;'
              . 'padding:14px 26px;border-radius:10px;font-size:28px;font-weight:700;letter-spacing:8px">' . htmlspecialchars($code) . '</span></p>'
              . '<p style="color:#666;font-size:13px">O código expira em ' . (int) $expireMinutes . ' minutos e vale para um único login.</p>'
              . '<p style="color:#888;font-size:12px;margin-top:28px">Se não foi você tentando entrar, <strong>troque sua senha</strong> — ela foi digitada corretamente por alguém.</p></div>';
        $text = "Ola {$userName},\n\nSeu codigo de acesso ao {$appName}: {$code}\n"
              . "Expira em {$expireMinutes} minutos e vale para um unico login.\n\n"
              . "Se nao foi voce tentando entrar, troque sua senha — ela foi digitada corretamente por alguem.";
        return $this->send($to, $subject, $html, $text);
    }

    /**
     * Agradece o cadastro e informa que está em análise para aprovação.
     */
    public function sendRegistrationReceived(string $to, string $userName): bool
    {
        $appName = $this->fromName ?: 'Ravi Developer';
        $subject = 'Recebemos seu cadastro — ' . $appName;
        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#222">'
              . '<h2 style="color:#7c6cff">Obrigado pelo cadastro!</h2>'
              . '<p>Olá ' . htmlspecialchars($userName) . ', recebemos a sua solicitação de acesso ao <strong>' . htmlspecialchars($appName) . '</strong>.</p>'
              . '<p>O acesso é restrito à equipe, então seu cadastro <strong>passará por uma análise</strong>. '
              . 'Assim que for aprovado, você receberá um e-mail e poderá fazer login.</p>'
              . '<p style="color:#888;font-size:12px;margin-top:28px">Se não foi você, ignore este e-mail.</p></div>';
        $text = "Ola {$userName},\n\nRecebemos sua solicitacao de acesso ao {$appName}. "
              . "O acesso e restrito a equipe, entao seu cadastro passara por uma analise. "
              . "Voce recebera um e-mail quando for aprovado.";
        return $this->send($to, $subject, $html, $text);
    }

    /**
     * Avisa o usuário que a conta foi aprovada e ele já pode entrar.
     */
    public function sendAccountApproved(string $to, string $userName, string $loginUrl): bool
    {
        $appName = $this->fromName ?: 'Ravi Developer';
        $subject = 'Seu acesso foi aprovado — ' . $appName;
        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#222">'
              . '<h2 style="color:#7c6cff">Acesso aprovado! 🎉</h2>'
              . '<p>Olá ' . htmlspecialchars($userName) . ', sua conta no <strong>' . htmlspecialchars($appName) . '</strong> foi aprovada.</p>'
              . '<p style="margin:24px 0"><a href="' . htmlspecialchars($loginUrl) . '" style="background:#7c6cff;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Fazer login</a></p>'
              . '</div>';
        $text = "Ola {$userName},\n\nSua conta no {$appName} foi aprovada. Faca login em: {$loginUrl}";
        return $this->send($to, $subject, $html, $text);
    }

    /**
     * Envia email de verificação
     */
    public function sendEmailVerification(string $to, string $userName, string $verifyUrl): bool
    {
        $subject = 'Verifique seu Email - Ravi Developer';
        
        $html = $this->renderTemplate('email-verification', [
            'userName' => $userName,
            'verifyUrl' => $verifyUrl
        ]);
        
        $text = "Olá {$userName},\n\n";
        $text .= "Bem-vindo ao Ravi Developer!\n\n";
        $text .= "Clique no link abaixo para verificar seu email:\n";
        $text .= "{$verifyUrl}\n\n";
        $text .= "Atenciosamente,\nEquipe Ravi Developer";
        
        return $this->send($to, $subject, $html, $text);
    }
    
    /**
     * Envia email de boas-vindas
     */
    public function sendWelcome(string $to, string $userName): bool
    {
        $subject = 'Bem-vindo ao Ravi Developer! 🌟';
        
        $html = $this->renderTemplate('welcome', [
            'userName' => $userName,
            'loginUrl' => env('APP_URL') . '/login'
        ]);
        
        $text = "Olá {$userName},\n\n";
        $text .= "Sua conta no Ravi Developer foi criada com sucesso!\n\n";
        $text .= "Agora você pode desenvolver software 10x mais rápido com IA.\n\n";
        $text .= "Acesse: " . env('APP_URL') . "/login\n\n";
        $text .= "Atenciosamente,\nEquipe Ravi Developer";
        
        return $this->send($to, $subject, $html, $text);
    }
    
    /**
     * Renderiza template de email
     */
    private function renderTemplate(string $template, array $data): string
    {
        $templates = [
            'password-reset' => $this->getPasswordResetTemplate(),
            'email-verification' => $this->getEmailVerificationTemplate(),
            'welcome' => $this->getWelcomeTemplate()
        ];
        
        $html = $templates[$template] ?? '';
        
        foreach ($data as $key => $value) {
            $html = str_replace('{{' . $key . '}}', htmlspecialchars((string) $value), $html);
        }
        
        return $html;
    }
    
    private function getPasswordResetTemplate(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); padding: 30px; text-align: center; }
        .header h1 { color: #fff; margin: 0; font-size: 24px; }
        .content { padding: 40px; }
        .button { display: inline-block; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: #fff !important; text-decoration: none; padding: 14px 30px; border-radius: 6px; font-weight: 600; margin: 20px 0; }
        .footer { padding: 20px 40px; background: #f9f9f9; font-size: 13px; color: #666; }
        .warning { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 6px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🌟 Ravi Developer</h1>
        </div>
        <div class="content">
            <h2>Olá, {{userName}}!</h2>
            <p>Recebemos uma solicitação para redefinir a senha da sua conta.</p>
            <p>Clique no botão abaixo para criar uma nova senha:</p>
            <p style="text-align: center;">
                <a href="{{resetUrl}}" class="button">Redefinir Senha</a>
            </p>
            <div class="warning">
                <strong>⚠️ Este link expira em {{expireHours}} horas.</strong>
            </div>
            <p>Se você não solicitou esta alteração, pode ignorar este email com segurança.</p>
        </div>
        <div class="footer">
            <p>Este email foi enviado por Ravi Developer - Ravi Systems LTDA</p>
            <p>Se você não criou uma conta, por favor ignore este email.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    private function getEmailVerificationTemplate(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); padding: 30px; text-align: center; }
        .header h1 { color: #fff; margin: 0; font-size: 24px; }
        .content { padding: 40px; }
        .button { display: inline-block; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: #fff !important; text-decoration: none; padding: 14px 30px; border-radius: 6px; font-weight: 600; margin: 20px 0; }
        .footer { padding: 20px 40px; background: #f9f9f9; font-size: 13px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🌟 Ravi Developer</h1>
        </div>
        <div class="content">
            <h2>Olá, {{userName}}!</h2>
            <p>Obrigado por se cadastrar no Ravi Developer!</p>
            <p>Por favor, confirme seu endereço de email clicando no botão abaixo:</p>
            <p style="text-align: center;">
                <a href="{{verifyUrl}}" class="button">Verificar Email</a>
            </p>
            <p>Se você não criou esta conta, pode ignorar este email.</p>
        </div>
        <div class="footer">
            <p>Este email foi enviado por Ravi Developer - Ravi Systems LTDA</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    private function getWelcomeTemplate(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); padding: 30px; text-align: center; }
        .header h1 { color: #fff; margin: 0; font-size: 24px; }
        .content { padding: 40px; }
        .button { display: inline-block; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: #fff !important; text-decoration: none; padding: 14px 30px; border-radius: 6px; font-weight: 600; margin: 20px 0; }
        .footer { padding: 20px 40px; background: #f9f9f9; font-size: 13px; color: #666; }
        .features { margin: 20px 0; }
        .features li { margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🌟 Bem-vindo ao Ravi Developer!</h1>
        </div>
        <div class="content">
            <h2>Olá, {{userName}}!</h2>
            <p>Sua conta foi criada com sucesso! Agora você pode desenvolver software 10x mais rápido com IA.</p>
            
            <h3>O que você pode fazer:</h3>
            <ul class="features">
                <li>💬 Conversar com IA para criar código</li>
                <li>🛠️ Usar 13 ferramentas integradas</li>
                <li>🐍 Executar Python, JavaScript, PHP e mais</li>
                <li>📁 Gerenciar múltiplos projetos</li>
            </ul>
            
            <p style="text-align: center;">
                <a href="{{loginUrl}}" class="button">Acessar Agora</a>
            </p>
            
            <p><strong>Próximo passo:</strong> Configure sua chave API do Claude em Configurações → API Keys.</p>
        </div>
        <div class="footer">
            <p>Precisa de ajuda? Responda este email ou acesse nossa documentação.</p>
            <p>Ravi Developer - Ravi Systems LTDA</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
