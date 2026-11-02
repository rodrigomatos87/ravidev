<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;

/**
 * #8 — "Manter conectado" = confiar neste navegador. Login persistente por cookie
 * `selector.validator` (o validator nunca é guardado em claro: só o sha256). Um dispositivo
 * confiável também pula o 2FA. Lista/encerra em "Sessões e dispositivos" (#7).
 */
class AuthDeviceService
{
    public const COOKIE = 'ravi_device';
    private const DAYS = 60;

    /** Cria um dispositivo confiável e seta o cookie. Retorna o id. */
    public function remember(int $userId, bool $trust2fa = true): int
    {
        $selector  = bin2hex(random_bytes(12));      // 24 chars
        $validator = bin2hex(random_bytes(32));
        $expires   = time() + self::DAYS * 86400;
        Database::getInstance()->query(
            "INSERT INTO auth_devices (user_id, selector, validator_hash, label, ip, user_agent, trusted_2fa, last_used_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
            [
                $userId, $selector, hash('sha256', $validator),
                $this->deviceLabel(), $this->ip(),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                $trust2fa ? 1 : 0, date('Y-m-d H:i:s', $expires),
            ]
        );
        $this->setCookie($selector . '.' . $validator, $expires);
        return (int) Database::getInstance()->getPdo()->lastInsertId();
    }

    /** Valida o cookie do dispositivo. @return array{user_id:int,id:int,trusted_2fa:bool}|null */
    public function fromCookie(): ?array
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '' || !str_contains($raw, '.')) {
            return null;
        }
        [$selector, $validator] = explode('.', $raw, 2);
        $row = Database::getInstance()->fetch(
            "SELECT * FROM auth_devices WHERE selector = ? LIMIT 1", [$selector]
        );
        if (!$row) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            $this->revokeBySelector($selector);
            return null;
        }
        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            return null;
        }
        // Validade DESLIZANTE: cada uso estende +60 dias (banco + cookie). Assim você fica logado
        // por muito tempo enquanto usar o aparelho; parar de usar (ou revogar) encerra o acesso.
        $newExp = time() + self::DAYS * 86400;
        try {
            Database::getInstance()->query(
                "UPDATE auth_devices SET last_used_at = NOW(), ip = ?, expires_at = ? WHERE id = ?",
                [$this->ip(), date('Y-m-d H:i:s', $newExp), (int) $row['id']]
            );
            $this->setCookie($raw, $newExp);
        } catch (\Throwable $e) {}
        return ['user_id' => (int) $row['user_id'], 'id' => (int) $row['id'], 'trusted_2fa' => (int) $row['trusted_2fa'] === 1];
    }

    public function list(int $userId): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT id, label, ip, user_agent, trusted_2fa, last_used_at, created_at FROM auth_devices
             WHERE user_id = ? ORDER BY last_used_at DESC", [$userId]
        );
        $current = $this->currentSelector();
        foreach ($rows as &$r) {
            $r['is_current'] = false; // marcado abaixo via selector separado
        }
        return $rows;
    }

    /** O id do dispositivo correspondente ao cookie atual (para marcar "este aparelho"). */
    public function currentDeviceId(): ?int
    {
        $sel = $this->currentSelector();
        if ($sel === null) {
            return null;
        }
        $row = Database::getInstance()->fetch("SELECT id FROM auth_devices WHERE selector = ?", [$sel]);
        return $row ? (int) $row['id'] : null;
    }

    /** Este navegador já é um aparelho confiável (tem cookie válido)? */
    public function isCurrentTrusted(): bool
    {
        return $this->currentDeviceId() !== null;
    }

    /** Rótulo + IP do navegador ATUAL (para o card "confiar neste navegador"). */
    public function currentInfo(): array
    {
        return ['label' => $this->deviceLabel(), 'ip' => $this->ip()];
    }

    /** Confia neste navegador (cria o aparelho se ainda não for). Retorna o id do aparelho. */
    public function trustCurrent(int $userId): int
    {
        $id = $this->currentDeviceId();
        if ($id !== null) {
            return $id; // já é confiável
        }
        return $this->remember($userId, true);
    }

    public function revoke(int $userId, int $deviceId): void
    {
        Database::getInstance()->query("DELETE FROM auth_devices WHERE id = ? AND user_id = ?", [$deviceId, $userId]);
    }

    /** Esquece este navegador (logout): remove o registro e o cookie. */
    public function forget(): void
    {
        $sel = $this->currentSelector();
        if ($sel !== null) {
            $this->revokeBySelector($sel);
        }
        $this->setCookie('', time() - 3600);
    }

    private function currentSelector(): ?string
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '' || !str_contains($raw, '.')) {
            return null;
        }
        return explode('.', $raw, 2)[0];
    }

    private function revokeBySelector(string $selector): void
    {
        try {
            Database::getInstance()->query("DELETE FROM auth_devices WHERE selector = ?", [$selector]);
        } catch (\Throwable $e) {}
    }

    private function setCookie(string $value, int $expires): void
    {
        $secure = (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        @setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function ip(): string
    {
        return client_ip() ?? '';
    }

    /** Rótulo amigável do navegador/SO a partir do User-Agent. */
    private function deviceLabel(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $os = 'Desconhecido';
        foreach (['Windows' => 'Windows', 'Mac OS' => 'macOS', 'Macintosh' => 'macOS', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Linux' => 'Linux'] as $needle => $name) {
            if (stripos($ua, $needle) !== false) { $os = $name; break; }
        }
        $br = 'Navegador';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $name) {
            if (stripos($ua, $needle) !== false) { $br = $name; break; }
        }
        return $br . ' · ' . $os;
    }
}
