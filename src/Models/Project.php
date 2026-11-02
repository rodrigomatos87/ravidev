<?php

declare(strict_types=1);

namespace Ravi\Models;

/**
 * Model de projeto
 */
class Project extends Model
{
    protected static string $table = 'projects';
    
    protected static array $fillable = [
        'uuid',
        'user_id',
        'name',
        'description',
        'type',
        'icon',
        'color',
        'status',
        'settings',
        'git_enabled',
        'git_repo_full_name',
        'git_branch',
        'git_auto_sync',
        'git_last_synced_sha',
        'git_last_synced_at',
        'git_pending',
        'ssh_private_key_encrypted',
        'ssh_public_key',
    ];

    protected static array $casts = [
        'user_id' => 'integer',
        'settings' => 'json',
        'git_enabled' => 'boolean',
        'git_auto_sync' => 'boolean',
        'git_pending' => 'boolean',
    ];
    
    /**
     * Busca por UUID
     */
    public static function findByUuid(string $uuid): ?self
    {
        return static::findBy('uuid', $uuid);
    }

    /**
     * Lista projetos de um usuário (exclui deletados), opcionalmente por status
     */
    public static function findByUser(int $userId, ?string $status = null): array
    {
        // Acessíveis = dono OU membro compartilhado (compartilhamento total).
        $ids = static::accessibleIdsFor($userId);
        if (empty($ids)) {
            return [];
        }
        $query = static::whereIn('id', $ids)->where('status', '!=', 'deleted');
        if ($status !== null && $status !== '') {
            $query->where('status', '=', $status);
        }
        return $query->orderBy('updated_at', 'DESC')->get();
    }

    /** IDs de todos os projetos que o usuário acessa (dono + compartilhados), exceto deletados. */
    public static function accessibleIdsFor(int $userId): array
    {
        $owned = static::where('user_id', '=', $userId)
            ->where('status', '!=', 'deleted')
            ->pluck('id');
        $ids = array_map('intval', $owned);

        $shared = static::db()->fetchAll(
            "SELECT project_id FROM project_members WHERE user_id = ?",
            [$userId]
        );
        foreach ($shared as $r) {
            $ids[] = (int) $r['project_id'];
        }
        return array_values(array_unique($ids));
    }

    /** Carrega um projeto por UUID SE o usuário tiver acesso (dono ou membro); senão null. */
    public static function findAccessible(string $uuid, int $userId): ?self
    {
        $p = static::findByUuid($uuid);
        if (!$p) {
            return null;
        }
        return $p->isAccessibleBy($userId) ? $p : null;
    }

    /**
     * O usuário acessa este projeto? (dono ou membro). O ADMINISTRADOR acessa qualquer projeto
     * do sistema (pode ver e abrir projetos de todos os usuários) — a checagem de admin fica por
     * último para não custar uma consulta a mais no caminho comum (dono/membro).
     */
    public function isAccessibleBy(int $userId): bool
    {
        if ((int) $this->user_id === $userId) {
            return true;
        }
        $row = static::db()->fetch(
            "SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1",
            [$this->id, $userId]
        );
        if ($row !== null) {
            return true;
        }
        return static::userIsAdmin($userId);
    }

    /** O usuário é administrador do sistema? (role = 'admin') */
    public static function userIsAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $row = static::db()->fetch("SELECT role FROM users WHERE id = ? LIMIT 1", [$userId]);
        return $row !== null && (string) ($row['role'] ?? 'user') === 'admin';
    }

    /** Preferência (user_prefs) do admin: ver os projetos de todos os usuários na barra lateral e na lista. */
    public const ADMIN_ALL_PREF = 'admin_all_projects';

    /** O admin está com a visão "projetos de todos os usuários" ligada? (default desligada) */
    public static function adminSeesAll(int $userId): bool
    {
        return UserPref::enabled($userId, self::ADMIN_ALL_PREF, false);
    }

    /**
     * SOMENTE ADMINISTRADOR: todos os projetos do sistema (de todos os usuários), opcionalmente
     * por status (exclui deletados). Não passa por accessibleIdsFor() de propósito: a lista
     * "normal" do admin continua sendo só o que é dele/compartilhado; esta é a visão "quando quiser".
     */
    public static function findAllForAdmin(?string $status = null): array
    {
        $query = static::where('status', '!=', 'deleted');
        if ($status !== null && $status !== '') {
            $query = $query->where('status', '=', $status);
        }
        return $query->orderBy('updated_at', 'DESC')->get();
    }

    /** Membros (compartilhados) do projeto, com dados do usuário. */
    public function members(): array
    {
        return static::db()->fetchAll(
            "SELECT pm.user_id, pm.role, pm.created_at, pm.context_access, u.name, u.email
             FROM project_members pm INNER JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = ? ORDER BY u.name ASC",
            [$this->id]
        );
    }

    /** O usuário pode VER/USAR as memórias e fontes externas? (dono sempre; membro só se liberado). */
    public function userHasContextAccess(int $userId): bool
    {
        if ((int) $this->user_id === $userId) {
            return true;
        }
        $row = static::db()->fetch(
            "SELECT context_access FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1",
            [$this->id, $userId]
        );
        return $row !== null && (int) ($row['context_access'] ?? 0) === 1;
    }

    /** Dono liga/desliga o acesso de um membro às memórias/fontes externas. */
    public function setMemberContextAccess(int $userId, bool $on): void
    {
        static::db()->query(
            "UPDATE project_members SET context_access = ? WHERE project_id = ? AND user_id = ?",
            [$on ? 1 : 0, $this->id, $userId]
        );
    }

    /** Gera (uma vez) a chave SSH ÚNICA do projeto (ed25519, sem passphrase). Idempotente. */
    public function ensureSshKey(): bool
    {
        if (!empty($this->ssh_public_key) && !empty($this->ssh_private_key_encrypted)) {
            return true;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rsshk');
        if ($tmp === false) {
            return false;
        }
        @unlink($tmp); // ssh-keygen exige que o arquivo ainda não exista
        $comment = 'ravi-' . substr((string) $this->uuid, 0, 8);
        $out = [];
        $rc = 0;
        @exec('ssh-keygen -t ed25519 -N "" -C ' . escapeshellarg($comment) . ' -f ' . escapeshellarg($tmp) . ' -q 2>&1', $out, $rc);
        if ($rc !== 0 || !is_file($tmp) || !is_file($tmp . '.pub')) {
            @unlink($tmp); @unlink($tmp . '.pub');
            return false;
        }
        $priv = (string) @file_get_contents($tmp);
        $pub  = trim((string) @file_get_contents($tmp . '.pub'));
        @unlink($tmp); @unlink($tmp . '.pub');
        if ($priv === '' || $pub === '') {
            return false;
        }
        $this->ssh_private_key_encrypted = (new \Ravi\Core\Encryption())->encrypt($priv);
        $this->ssh_public_key = $pub;
        $this->save();
        return true;
    }

    public function getSshPublicKey(): ?string
    {
        return $this->ssh_public_key ?: null;
    }

    public function getSshPrivateKey(): ?string
    {
        if (empty($this->ssh_private_key_encrypted)) {
            return null;
        }
        try {
            return (new \Ravi\Core\Encryption())->decrypt((string) $this->ssh_private_key_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Comando para o usuário colar no servidor remoto autorizando a chave do projeto. */
    public function sshInstallCommand(): string
    {
        $pub = (string) ($this->ssh_public_key ?? '');
        if ($pub === '') {
            return '';
        }
        $q = "'" . str_replace("'", "'\\''", $pub) . "'";
        return 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && '
            . 'grep -qxF ' . $q . ' ~/.ssh/authorized_keys 2>/dev/null || echo ' . $q . ' >> ~/.ssh/authorized_keys && '
            . 'chmod 600 ~/.ssh/authorized_keys';
    }

    /** Compartilha com um usuário por e-mail. */
    public function shareWithEmail(string $email): array
    {
        $email = trim(mb_strtolower($email));
        $u = User::findByEmail($email);
        if (!$u) {
            return ['ok' => false, 'error' => 'Nenhum usuário com esse e-mail.'];
        }
        if ((int) $u->id === (int) $this->user_id) {
            return ['ok' => false, 'error' => 'Esse usuário já é o dono do projeto.'];
        }
        static::db()->query(
            "INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'editor')",
            [$this->id, $u->id]
        );
        return ['ok' => true, 'user' => ['id' => (int) $u->id, 'name' => $u->name, 'email' => $u->email]];
    }

    /** Remove um membro do projeto. */
    public function removeMember(int $userId): void
    {
        static::db()->query(
            "DELETE FROM project_members WHERE project_id = ? AND user_id = ?",
            [$this->id, $userId]
        );
    }

    /**
     * Transfere a titularidade do projeto para outro usuário. Ação irreversível pela UI.
     * O novo dono deixa de ser membro (passa a ser dono); o dono antigo vira membro editor,
     * mantendo o acesso ao contexto (memórias/servidores). Tudo numa transação.
     */
    public function transferOwnershipTo(int $newOwnerId): array
    {
        $newOwner = User::find($newOwnerId);
        if (!$newOwner || (int) ($newOwner->is_active ?? 1) !== 1) {
            return ['ok' => false, 'error' => 'Usuário inválido ou inativo.'];
        }
        $oldOwnerId = (int) $this->user_id;
        if ($newOwnerId === $oldOwnerId) {
            return ['ok' => false, 'error' => 'Esse usuário já é o dono do projeto.'];
        }

        $db = static::db();
        $db->beginTransaction();
        try {
            // O novo dono não continua como membro (agora é dono).
            $db->query("DELETE FROM project_members WHERE project_id = ? AND user_id = ?", [$this->id, $newOwnerId]);
            // Troca o dono.
            $db->query("UPDATE projects SET user_id = ? WHERE id = ?", [$newOwnerId, $this->id]);
            // Dono antigo vira membro editor, mantendo o acesso às memórias/servidores.
            $db->query(
                "INSERT INTO project_members (project_id, user_id, role, context_access) VALUES (?, ?, 'editor', 1)
                 ON DUPLICATE KEY UPDATE role = 'editor', context_access = 1",
                [$this->id, $oldOwnerId]
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'Falha ao transferir a titularidade.'];
        }

        $this->user_id = $newOwnerId;
        return ['ok' => true, 'new_owner' => [
            'id' => (int) $newOwner->id, 'name' => (string) $newOwner->name, 'email' => (string) $newOwner->email,
        ]];
    }
    
    /**
     * Cria projeto para usuário
     */
    public static function createForUser(int $userId, array $data): self
    {
        $data['user_id'] = $userId;
        $data['uuid'] = uuid();
        $data['status'] = 'active';
        
        return static::create($data);
    }
    
    /**
     * Usuário dono do projeto
     */
    public function user(): ?User
    {
        return User::find($this->user_id);
    }
    
    /**
     * Conversas do projeto
     */
    public function conversations(): array
    {
        return Conversation::where('project_id', '=', $this->id)
            ->orderBy('updated_at', 'DESC')
            ->get();
    }
    
    /**
     * Conversas ativas
     */
    public function activeConversations(): array
    {
        return Conversation::where('project_id', '=', $this->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('updated_at', 'DESC')
            ->get();
    }
    
    /**
     * Última conversa
     */
    public function lastConversation(): ?Conversation
    {
        return Conversation::where('project_id', '=', $this->id)
            ->orderBy('updated_at', 'DESC')
            ->first();
    }
    
    /**
     * Conta conversas
     */
    public function countConversations(): int
    {
        return Conversation::where('project_id', '=', $this->id)->count();
    }
    
    /**
     * Total de tokens usados no projeto
     */
    public function totalTokensUsed(): int
    {
        $result = static::db()->fetch(
            'SELECT SUM(total_tokens_input + total_tokens_output) as total
             FROM conversations WHERE project_id = :id',
            ['id' => $this->id]
        );

        return (int) ($result['total'] ?? 0);
    }

    /** Configurações do projeto (coluna JSON `settings`) como array. */
    public function getSettings(): array
    {
        $s = $this->settings;
        if (is_string($s)) {
            $s = json_decode($s, true);
        }
        return is_array($s) ? $s : [];
    }

    /** Resumo de tokens/custo real do projeto (somando suas conversas). */
    public function getTokensUsed(): array
    {
        $row = static::db()->fetch(
            "SELECT COALESCE(SUM(total_tokens_input), 0)  AS input,
                    COALESCE(SUM(total_tokens_output), 0) AS output,
                    COALESCE(SUM(total_cost), 0)          AS cost
             FROM conversations WHERE project_id = ?",
            [$this->id]
        ) ?: [];
        $in  = (int) ($row['input'] ?? 0);
        $out = (int) ($row['output'] ?? 0);
        return [
            'input'  => $in,
            'output' => $out,
            'total'  => $in + $out,
            'cost'   => round((float) ($row['cost'] ?? 0), 6),
        ];
    }
    
    /**
     * Arquiva projeto
     */
    public function archive(): bool
    {
        $this->status = 'archived';
        return $this->save();
    }
    
    /**
     * Restaura projeto arquivado
     */
    public function restore(): bool
    {
        $this->status = 'active';
        return $this->save();
    }
    
    /**
     * Soft delete
     */
    public function softDelete(): bool
    {
        $this->status = 'deleted';
        return $this->save();
    }
    
    /**
     * Verifica se está ativo
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
    
    /**
     * Obtém configuração específica
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->settings ?? [];
        return $settings[$key] ?? $default;
    }
    
    /**
     * Define configuração
     */
    public function setSetting(string $key, mixed $value): bool
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = json_encode($settings);

        return $this->save();
    }

    // ----------------------------------------------------------------------
    // Git/GitHub (vínculo opcional por projeto)
    // ----------------------------------------------------------------------

    /** Git está ligado neste projeto? */
    public function gitEnabled(): bool
    {
        return !empty($this->git_enabled) && !empty($this->git_repo_full_name);
    }

    /** Estado do Git para a UI (sem expor token). */
    public function gitState(): array
    {
        return [
            'enabled'      => $this->gitEnabled(),
            'repo'         => $this->git_repo_full_name,
            'branch'       => $this->git_branch ?: 'main',
            'auto_sync'    => !empty($this->git_auto_sync),
            'pending'      => !empty($this->git_pending),
            'last_sha'     => $this->git_last_synced_sha,
            'last_sync_at' => $this->git_last_synced_at,
        ];
    }

    /** Liga o projeto a um repositório do GitHub. */
    public function linkRepo(string $fullName, string $branch = 'main', bool $autoSync = false): bool
    {
        $this->git_enabled = 1;
        $this->git_repo_full_name = $fullName;
        $this->git_branch = $branch ?: 'main';
        $this->git_auto_sync = $autoSync ? 1 : 0;
        $this->git_pending = 0;
        return $this->save();
    }

    /** Desvincula o Git do projeto (não mexe no repo remoto). */
    public function unlinkGit(): bool
    {
        $this->git_enabled = 0;
        $this->git_repo_full_name = null;
        $this->git_auto_sync = 0;
        $this->git_pending = 0;
        $this->git_last_synced_sha = null;
        $this->git_last_synced_at = null;
        return $this->save();
    }

    /** Marca que há mudanças pendentes de sincronizar (entrega do Maestro / edição). */
    public function markGitPending(): bool
    {
        if (!$this->gitEnabled()) {
            return false;
        }
        $this->git_pending = 1;
        return $this->save();
    }

    /** Marca como sincronizado (após push), guardando o SHA. */
    public function markGitSynced(?string $sha = null): bool
    {
        $this->git_pending = 0;
        if ($sha !== null) {
            $this->git_last_synced_sha = $sha;
        }
        $this->git_last_synced_at = date('Y-m-d H:i:s');
        return $this->save();
    }

    /** Liga/desliga auto-sync. */
    public function setAutoSync(bool $on): bool
    {
        $this->git_auto_sync = $on ? 1 : 0;
        return $this->save();
    }
}
