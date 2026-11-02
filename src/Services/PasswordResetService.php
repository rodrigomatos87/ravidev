<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;
use Ravi\Models\User;

/**
 * Geração e validação de tokens de redefinição de senha.
 *
 * Tabela `password_resets` (email é a chave natural — 1 token ativo por e-mail):
 *   email, token (HASH sha256 do token bruto), expires_at, used_at, created_at.
 * O token BRUTO vai só no link enviado por e-mail; no banco guardamos o hash
 * (vazamento do banco não expõe tokens utilizáveis).
 */
class PasswordResetService
{
    /**
     * Emite um token para o e-mail (se houver usuário). Retorna o token BRUTO
     * para montar o link, ou null se o usuário não existir.
     */
    public function issue(string $email, int $hours = 24): ?string
    {
        $user = User::findByEmail($email);
        if (!$user) {
            return null;
        }
        $raw     = bin2hex(random_bytes(32));
        $hash    = hash('sha256', $raw);
        $expires = date('Y-m-d H:i:s', time() + $hours * 3600);

        Database::getInstance()->query(
            'REPLACE INTO password_resets (email, token, expires_at, used_at, created_at) VALUES (?, ?, ?, NULL, NOW())',
            [$email, $hash, $expires]
        );
        return $raw;
    }

    /** Valida o token bruto contra o hash guardado (não usado, não expirado). */
    public function verify(string $email, string $token): bool
    {
        if ($email === '' || $token === '') {
            return false;
        }
        $row = Database::getInstance()->fetch(
            'SELECT token, expires_at, used_at FROM password_resets WHERE email = ?',
            [$email]
        );
        if (!$row) {
            return false;
        }
        if (!empty($row['used_at'])) {
            return false;
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return false;
        }
        return hash_equals((string) $row['token'], hash('sha256', $token));
    }

    /** Remove o token após uso (consumido). */
    public function consume(string $email): void
    {
        Database::getInstance()->query('DELETE FROM password_resets WHERE email = ?', [$email]);
    }

    /**
     * Emite o token e envia o e-mail (recuperação ou convite). Retorna [ok, error].
     * @return array{ok: bool, error?: string}
     */
    public function sendLink(string $email, bool $invite = false, int $hours = 24): array
    {
        $raw = $this->issue($email, $hours);
        if ($raw === null) {
            return ['ok' => false, 'error' => 'Usuário não encontrado.'];
        }
        $user = User::findByEmail($email);
        $url  = rtrim((string) env('APP_URL', ''), '/')
              . '/reset-password?token=' . $raw . '&email=' . urlencode($email);

        $mail = new EmailService();
        if (!$mail->isConfigured()) {
            return ['ok' => false, 'error' => 'SMTP não configurado.'];
        }
        $ok = $invite
            ? $mail->sendInvite($email, $user->name ?? $email, $url)
            : $mail->sendPasswordReset($email, $user->name ?? $email, $url);

        return $ok ? ['ok' => true] : ['ok' => false, 'error' => $mail->getLastError() ?: 'Falha ao enviar e-mail.'];
    }
}
