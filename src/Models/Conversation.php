<?php

declare(strict_types=1);

namespace Ravi\Models;

/**
 * Model de conversa/tarefa
 */
class Conversation extends Model
{
    protected static string $table = 'conversations';
    
    protected static array $fillable = [
        'uuid',
        'project_id',
        'title',
        'status',
        'model_used',
        'total_tokens_input',
        'total_tokens_output',
        'total_cost',
        'started_at',
        'completed_at',
        'error_message',
        'work_seconds',
    ];

    protected static array $casts = [
        'project_id' => 'integer',
        'total_tokens_input' => 'integer',
        'total_tokens_output' => 'integer',
        'total_cost' => 'float',
    ];
    
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    
    /**
     * Busca por UUID
     */
    public static function findByUuid(string $uuid): ?self
    {
        return static::findBy('uuid', $uuid);
    }

    /**
     * Lista conversas de um projeto (mais recentes primeiro)
     */
    public static function findByProject(int $projectId): array
    {
        return static::where('project_id', '=', $projectId)
            ->orderBy('updated_at', 'DESC')
            ->get();
    }

    /**
     * Conversa ativa mais recente de um projeto (ou null)
     */
    public static function getActiveForProject(int $projectId): ?object
    {
        return static::where('project_id', '=', $projectId)
            ->where('status', '!=', 'archived')
            ->orderBy('updated_at', 'DESC')
            ->first();
    }
    
    /**
     * Cria conversa para projeto
     */
    public static function createForProject(int $projectId, string $title): self
    {
        return static::create([
            'uuid' => uuid(),
            'project_id' => $projectId,
            'title' => $title,
            'status' => self::STATUS_PENDING,
            'model_used' => config('claude.model'),
        ]);
    }
    
    /**
     * Projeto da conversa
     */
    public function project(): ?Project
    {
        return Project::find($this->project_id);
    }
    
    /**
     * Mensagens da conversa
     */
    public function messages(): array
    {
        return Message::where('conversation_id', '=', $this->id)
            ->orderBy('created_at', 'ASC')
            ->get();
    }
    
    /**
     * Última mensagem
     */
    public function lastMessage(): ?Message
    {
        return Message::where('conversation_id', '=', $this->id)
            ->orderBy('created_at', 'DESC')
            ->first();
    }
    
    /**
     * Arquivos gerados
     */
    public function generatedFiles(): array
    {
        return GeneratedFile::where('conversation_id', '=', $this->id)
            ->orderBy('created_at', 'ASC')
            ->get();
    }
    
    /**
     * Inicia execução
     */
    public function start(): bool
    {
        $this->status = self::STATUS_RUNNING;
        $this->started_at = date('Y-m-d H:i:s');
        
        return $this->save();
    }
    
    /**
     * Marca como completa
     */
    public function complete(): bool
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = date('Y-m-d H:i:s');
        
        return $this->save();
    }
    
    /**
     * Marca como falha
     */
    public function fail(string $errorMessage): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->completed_at = date('Y-m-d H:i:s');
        $this->error_message = $errorMessage;
        
        return $this->save();
    }
    
    /**
     * Cancela execução
     */
    public function cancel(): bool
    {
        $this->status = self::STATUS_CANCELLED;
        $this->completed_at = date('Y-m-d H:i:s');
        
        return $this->save();
    }
    
    /**
     * Adiciona tokens consumidos
     */
    public function addTokens(int $input, int $output): bool
    {
        $this->total_tokens_input = ($this->total_tokens_input ?? 0) + $input;
        $this->total_tokens_output = ($this->total_tokens_output ?? 0) + $output;

        return $this->save();
    }

    /**
     * Acumula tokens e custo estimado (USD) em uma única gravação
     */
    public function addUsage(int $input, int $output, float $cost): bool
    {
        $this->total_tokens_input = ($this->total_tokens_input ?? 0) + $input;
        $this->total_tokens_output = ($this->total_tokens_output ?? 0) + $output;
        $this->total_cost = round((float)($this->total_cost ?? 0) + $cost, 6);

        return $this->save();
    }
    
    /**
     * Total de tokens
     */
    public function totalTokens(): int
    {
        return ($this->total_tokens_input ?? 0) + ($this->total_tokens_output ?? 0);
    }

    /** Duração da tarefa em SEGUNDOS (início→conclusão), ou null se ainda não há ambos. */
    public function getDuration(): ?int
    {
        $start = $this->started_at ?: $this->created_at;
        $end   = $this->completed_at;
        if (!$start || !$end) {
            return null;
        }
        $d = strtotime((string) $end) - strtotime((string) $start);
        return $d >= 0 ? $d : null;
    }
    
    /**
     * Verifica status
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
    
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }
    
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
    
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
    
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
    
    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ]);
    }
    
    /**
     * Duração da execução em segundos
     */
    public function duration(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }
        
        $start = new \DateTimeImmutable($this->started_at);
        $end = $this->completed_at 
            ? new \DateTimeImmutable($this->completed_at)
            : new \DateTimeImmutable();
        
        return $end->getTimestamp() - $start->getTimestamp();
    }
    
    /**
     * Formata duração
     */
    public function durationFormatted(): string
    {
        $seconds = $this->duration();
        
        if ($seconds === null) {
            return '-';
        }
        
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        
        $minutes = (int) floor($seconds / 60);
        $secs = $seconds % 60;
        
        return "{$minutes}m {$secs}s";
    }
    
    /**
     * Histórico de mensagens para API Claude
     */
    public function getMessagesForApi(): array
    {
        $messages = $this->messages();
        $apiMessages = [];
        
        foreach ($messages as $message) {
            if ($message->role === 'system') {
                continue; // System prompt vai separado
            }
            
            $apiMessages[] = $message->toApiFormat();
        }
        
        return $apiMessages;
    }
}
