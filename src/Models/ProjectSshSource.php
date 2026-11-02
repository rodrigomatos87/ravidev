<?php

declare(strict_types=1);

namespace Ravi\Models;

use Ravi\Core\Encryption;

/**
 * Fonte externa (servidor SSH) cadastrada na página do projeto, com NOME amigável
 * ("servidor do pedro"). Quando ATIVA (`enabled`), o Claude acessa via `ravi-ssh "<nome>"`.
 * Quando `auto_deploy`, cada entrega do projeto é sincronizada (rsync) no diretório remoto.
 * A senha fica CIFRADA (AES-256-GCM, igual à API key/token do GitHub).
 */
class ProjectSshSource extends Model
{
    protected static string $table = 'project_ssh_sources';

    protected static array $fillable = [
        'project_id', 'name', 'protocol', 'host', 'port', 'username',
        'password_encrypted', 'remote_dir', 'enabled', 'auto_deploy',
        'auth_type', 'private_key_encrypted', 'key_passphrase_encrypted',
        'discovered_ips',
    ];

    // Nunca serializa segredos para o cliente.
    protected static array $hidden = ['password_encrypted', 'private_key_encrypted', 'key_passphrase_encrypted'];

    /** Protocolo FTP? (senão SSH). FTP é sempre por senha (sem chave, sem rsync/acesso do Claude). */
    public function isFtp(): bool
    {
        return (string) $this->protocol === 'ftp';
    }

    /** Autenticação por chave? (senão senha). FTP nunca usa chave. */
    public function isKeyAuth(): bool
    {
        return !$this->isFtp() && (string) $this->auth_type === 'key';
    }

    public static function forProject(int $projectId): array
    {
        return static::where('project_id', '=', $projectId)->orderBy('id', 'ASC')->get();
    }

    /**
     * Lista de candidatos de conexão: o `host` primário primeiro, seguido dos IPs descobertos
     * (`discovered_ips`, separados por vírgula — inclui o IP da VPN). Deduplicado, sem vazios.
     * O `ravi-ssh`/deploy tentam nessa ordem até conectar.
     * @return string[]
     */
    public function hostCandidates(): array
    {
        $cands = [trim((string) $this->host)];
        foreach (preg_split('/[\s,]+/', (string) ($this->discovered_ips ?? '')) ?: [] as $ip) {
            $ip = trim($ip);
            if ($ip !== '') {
                $cands[] = $ip;
            }
        }
        $out = [];
        foreach ($cands as $c) {
            if ($c !== '' && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    public function setPassword(string $plain): void
    {
        $this->password_encrypted = $plain === '' ? null : (new Encryption())->encrypt($plain);
    }

    public function getPassword(): ?string
    {
        if (empty($this->password_encrypted)) {
            return null;
        }
        try {
            return (new Encryption())->decrypt((string) $this->password_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function setPrivateKey(string $key): void
    {
        $this->private_key_encrypted = trim($key) === '' ? null : (new Encryption())->encrypt($key);
    }

    public function getPrivateKey(): ?string
    {
        if (empty($this->private_key_encrypted)) {
            return null;
        }
        try {
            return (new Encryption())->decrypt((string) $this->private_key_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function setPassphrase(string $pp): void
    {
        $this->key_passphrase_encrypted = $pp === '' ? null : (new Encryption())->encrypt($pp);
    }

    public function getPassphrase(): ?string
    {
        if (empty($this->key_passphrase_encrypted)) {
            return null;
        }
        try {
            return (new Encryption())->decrypt((string) $this->key_passphrase_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
