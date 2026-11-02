<?php

declare(strict_types=1);

namespace Ravi\Models;

use Ravi\Core\Encryption;

/**
 * Model de usuário
 */
class User extends Model
{
    protected static string $table = 'users';
    
    protected static array $fillable = [
        'uuid',
        'name',
        'email',
        'password_hash',
        'api_key_encrypted',
        'avatar_url',
        'plan',
        'tokens_used',
        'tokens_limit',
        'is_active',
        'role',
        'approval_status',
        'email_verified_at',
        'email_verify_token',
        'github_token_encrypted',
        'github_login',
        'github_avatar_url',
        'github_scope',
        'github_connected_at',
        'totp_secret',
        'totp_enabled',
    ];

    protected static array $hidden = [
        'password_hash',
        'api_key_encrypted',
        'github_token_encrypted',
    ];
    
    protected static array $casts = [
        'tokens_used' => 'integer',
        'tokens_limit' => 'integer',
        'is_active' => 'boolean',
    ];
    
    /**
     * Busca por email
     */
    public static function findByEmail(string $email): ?self
    {
        return static::findBy('email', $email);
    }
    
    /**
     * Busca por UUID
     */
    public static function findByUuid(string $uuid): ?self
    {
        return static::findBy('uuid', $uuid);
    }
    
    /**
     * Cria usuário com senha hasheada
     */
    public static function register(string $name, string $email, string $password): ?self
    {
        $data = [
            'uuid' => uuid(),
            'name' => $name,
            'email' => $email,
            'password_hash' => Encryption::hashPassword($password),
            'plan' => 'free',
            'tokens_limit' => 100000,
            'is_active' => true,
        ];
        
        try {
            return static::create($data);
        } catch (\Exception $e) {
            logger()->error('Failed to register user', ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    /**
     * Verifica senha
     */
    public function verifyPassword(string $password): bool
    {
        return Encryption::verifyPassword($password, $this->password_hash);
    }
    
    /**
     * Atualiza senha
     */
    public function updatePassword(string $password): bool
    {
        $this->password_hash = Encryption::hashPassword($password);
        return $this->save();
    }
    
    /**
     * Salva API key criptografada
     */
    public function setApiKey(string $apiKey): bool
    {
        $encryption = new Encryption();
        $this->api_key_encrypted = $encryption->encrypt($apiKey);
        
        return $this->save();
    }
    
    /**
     * Obtém API key descriptografada
     */
    public function getApiKey(): ?string
    {
        if (empty($this->api_key_encrypted)) {
            return null;
        }
        
        $encryption = new Encryption();
        return $encryption->decrypt($this->api_key_encrypted);
    }
    
    /**
     * Verifica se tem API key configurada
     */
    public function hasApiKey(): bool
    {
        return !empty($this->api_key_encrypted);
    }

    // ----------------------------------------------------------------------
    // GitHub (OAuth App por usuário — token cifrado e persistente)
    // ----------------------------------------------------------------------

    /** Salva o token do GitHub (cifrado) + dados públicos da conta. */
    public function setGithubConnection(string $token, string $login, ?string $avatarUrl, string $scope): bool
    {
        $encryption = new Encryption();
        $this->github_token_encrypted = $encryption->encrypt($token);
        $this->github_login = $login;
        $this->github_avatar_url = $avatarUrl;
        $this->github_scope = $scope;
        $this->github_connected_at = date('Y-m-d H:i:s');
        return $this->save();
    }

    /** Token do GitHub em claro (ou null). Usado só no host para git push/clone. */
    public function getGithubToken(): ?string
    {
        if (empty($this->github_token_encrypted)) {
            return null;
        }
        $encryption = new Encryption();
        try {
            return $encryption->decrypt($this->github_token_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Tem conta GitHub conectada? */
    public function hasGithub(): bool
    {
        return !empty($this->github_token_encrypted);
    }

    /** Desconecta a conta GitHub (limpa token + dados). */
    public function disconnectGithub(): bool
    {
        $this->github_token_encrypted = null;
        $this->github_login = null;
        $this->github_avatar_url = null;
        $this->github_scope = null;
        $this->github_connected_at = null;
        return $this->save();
    }
    
    /**
     * Projetos do usuário
     */
    public function projects(): array
    {
        return Project::where('user_id', '=', $this->id)
            ->where('status', '!=', 'deleted')
            ->orderBy('updated_at', 'DESC')
            ->get();
    }
    
    /**
     * Conta projetos ativos
     */
    public function countProjects(): int
    {
        return Project::where('user_id', '=', $this->id)
            ->where('status', '=', 'active')
            ->count();
    }
    
    /**
     * Adiciona tokens consumidos
     */
    public function addTokensUsed(int $tokens): bool
    {
        $this->tokens_used = ($this->tokens_used ?? 0) + $tokens;
        return $this->save();
    }
    
    /**
     * Verifica se atingiu limite de tokens
     */
    public function hasReachedTokenLimit(): bool
    {
        // tokens_limit <= 0 significa ILIMITADO (ex.: cota Max, sem custo por token).
        // Necessário porque o tracking de uso agora acumula tokens de verdade — incluindo
        // replay de cache, que pode passar de 100k num único build — e não pode travar o usuário.
        $limit = (int) ($this->tokens_limit ?? 0);
        if ($limit <= 0) {
            return false;
        }
        return (int) ($this->tokens_used ?? 0) >= $limit;
    }
    
    /**
     * Tokens restantes
     */
    public function tokensRemaining(): int
    {
        return max(0, $this->tokens_limit - $this->tokens_used);
    }

    /**
     * Totais de uso REAIS, somados das conversas do usuário (fonte de verdade).
     * O contador users.tokens_used não é mais usado para acompanhamento — os tokens e o
     * custo estimado vivem nas colunas total_tokens_input/output e total_cost de conversations
     * (gravadas pelo Maestro).
     *
     * @return array{input:int,output:int,tokens:int,cost:float}
     */
    public function getUsageTotals(): array
    {
        // Uso REAL do usuário vem do histórico (ledger): atribuído a quem EXECUTOU a tarefa,
        // então cobre também projetos compartilhados onde ele trabalhou.
        return \Ravi\Models\UsageLedger::totalsForUser((int) $this->id);
    }
    
    /**
     * Verifica se email está verificado
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }
    
    /**
     * Marca email como verificado
     */
    public function markEmailAsVerified(): bool
    {
        $this->email_verified_at = date('Y-m-d H:i:s');
        return $this->save();
    }
    
    /**
     * Dados para sessão
     */
    public function toSessionData(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'plan' => $this->plan,
            'role' => $this->role ?? 'user',
            'api_key_encrypted' => !empty($this->api_key_encrypted),
            'github_connected' => !empty($this->github_token_encrypted),
            'github_login' => $this->github_login,
        ];

    }

    /** É administrador? */
    public function isAdmin(): bool
    {
        return ($this->role ?? 'user') === 'admin';
    }
    
    /**
     * Obtém estatísticas de uso
     */
    public function getUsageStats(): array
    {
        $db = static::db();
        
        // Total de projetos
        $projectCount = $this->countProjects();
        
        // Total de conversas
        $conversationCount = $db->fetch(
            "SELECT COUNT(*) as total FROM conversations c 
             INNER JOIN projects p ON p.id = c.project_id 
             WHERE p.user_id = ?",
            [$this->id]
        )['total'] ?? 0;
        
        // Total de mensagens
        $messageCount = $db->fetch(
            "SELECT COUNT(*) as total FROM messages m 
             INNER JOIN conversations c ON c.id = m.conversation_id
             INNER JOIN projects p ON p.id = c.project_id 
             WHERE p.user_id = ?",
            [$this->id]
        )['total'] ?? 0;
        
        // Tokens por período
        $tokensThisMonth = $db->fetch(
            "SELECT COALESCE(SUM(c.total_tokens_input + c.total_tokens_output), 0) as total 
             FROM conversations c 
             INNER JOIN projects p ON p.id = c.project_id 
             WHERE p.user_id = ? AND c.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
            [$this->id]
        )['total'] ?? 0;
        
        // Arquivos gerados
        $filesGenerated = $db->fetch(
            "SELECT COUNT(*) as total FROM generated_files gf
             INNER JOIN conversations c ON c.id = gf.conversation_id
             INNER JOIN projects p ON p.id = c.project_id 
             WHERE p.user_id = ?",
            [$this->id]
        )['total'] ?? 0;
        
        // Uso diário dos últimos 30 dias
        $dailyUsage = $db->fetchAll(
            "SELECT DATE(c.created_at) as date, 
                    SUM(c.total_tokens_input + c.total_tokens_output) as tokens
             FROM conversations c 
             INNER JOIN projects p ON p.id = c.project_id 
             WHERE p.user_id = ? AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(c.created_at)
             ORDER BY date ASC",
            [$this->id]
        );
        
        // Totais REAIS somados das conversas (sem limite — só acompanhamento).
        $totals = $this->getUsageTotals();

        return [
            'projects' => $projectCount,
            'conversations' => $conversationCount,
            'messages' => $messageCount,
            'tokens_used' => $totals['tokens'],
            'tokens_input' => $totals['input'],
            'tokens_output' => $totals['output'],
            'cost_total' => $totals['cost'],
            'tokens_this_month' => (int) $tokensThisMonth,
            'files_generated' => $filesGenerated,
            'plan' => $this->plan,
            'daily_usage' => $dailyUsage,
        ];
    }
}