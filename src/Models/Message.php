<?php

declare(strict_types=1);

namespace Ravi\Models;

/**
 * Model de mensagem
 */
class Message extends Model
{
    protected static string $table = 'messages';

    /** A tabela messages é append-only e não possui coluna updated_at */
    protected static bool $usesUpdatedAt = false;
    
    protected static array $fillable = [
        'uuid',
        'conversation_id',
        'role',
        'content',
        'tool_name',
        'tool_input',
        'tool_output',
        'tokens_used',
        'thinking_content',
        'metadata',
    ];
    
    protected static array $casts = [
        'conversation_id' => 'integer',
        'tokens_used' => 'integer',
        'tool_input' => 'json',
        'metadata' => 'json',
    ];
    
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';
    public const ROLE_TOOL_USE = 'tool_use';
    public const ROLE_TOOL_RESULT = 'tool_result';
    
    /**
     * Busca por UUID
     */
    public static function findByUuid(string $uuid): ?self
    {
        return static::findBy('uuid', $uuid);
    }

    /**
     * Lista mensagens de uma conversa (ordem cronológica)
     */
    public static function findByConversation(int $conversationId): array
    {
        return static::where('conversation_id', '=', $conversationId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }
    
    /**
     * Cria mensagem do usuário
     */
    public static function createUserMessage(int $conversationId, string $content): self
    {
        return static::create([
            'uuid' => uuid(),
            'conversation_id' => $conversationId,
            'role' => self::ROLE_USER,
            'content' => $content,
        ]);
    }
    
    /**
     * Cria mensagem do assistente
     */
    public static function createAssistantMessage(
        int $conversationId,
        string $content,
        ?string $thinkingContent = null,
        int $tokensUsed = 0,
        ?array $metadata = null
    ): self {
        $data = [
            'uuid' => uuid(),
            'conversation_id' => $conversationId,
            'role' => self::ROLE_ASSISTANT,
            'content' => $content,
            'thinking_content' => $thinkingContent,
            'tokens_used' => $tokensUsed,
        ];
        if (!empty($metadata)) {
            $data['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return static::create($data);
    }
    
    /**
     * Cria mensagem de tool use
     */
    public static function createToolUse(
        int $conversationId,
        string $toolName,
        array $toolInput,
        string $toolId
    ): self {
        return static::create([
            'uuid' => uuid(),
            'conversation_id' => $conversationId,
            'role' => self::ROLE_TOOL_USE,
            'content' => "Executando: {$toolName}",
            'tool_name' => $toolName,
            'tool_input' => json_encode($toolInput),
            'metadata' => json_encode(['tool_use_id' => $toolId]),
        ]);
    }
    
    /**
     * Cria mensagem de tool result
     */
    public static function createToolResult(
        int $conversationId,
        string $toolName,
        string $toolOutput,
        string $toolId,
        bool $isError = false
    ): self {
        return static::create([
            'uuid' => uuid(),
            'conversation_id' => $conversationId,
            'role' => self::ROLE_TOOL_RESULT,
            'content' => $isError ? "Erro: {$toolOutput}" : $toolOutput,
            'tool_name' => $toolName,
            'tool_output' => $toolOutput,
            'metadata' => json_encode([
                'tool_use_id' => $toolId,
                'is_error' => $isError,
            ]),
        ]);
    }
    
    /**
     * Conversa da mensagem
     */
    public function conversation(): ?Conversation
    {
        return Conversation::find($this->conversation_id);
    }
    
    /**
     * Verifica se é mensagem do usuário
     */
    public function isUserMessage(): bool
    {
        return $this->role === self::ROLE_USER;
    }
    
    /**
     * Verifica se é mensagem do assistente
     */
    public function isAssistantMessage(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }
    
    /**
     * Verifica se é tool use
     */
    public function isToolUse(): bool
    {
        return $this->role === self::ROLE_TOOL_USE;
    }
    
    /**
     * Verifica se é tool result
     */
    public function isToolResult(): bool
    {
        return $this->role === self::ROLE_TOOL_RESULT;
    }
    
    /**
     * Verifica se é erro
     */
    public function isError(): bool
    {
        $metadata = $this->metadata ?? [];
        return $metadata['is_error'] ?? false;
    }
    
    /**
     * Obtém tool use ID
     */
    public function getToolUseId(): ?string
    {
        $metadata = $this->metadata ?? [];
        return $metadata['tool_use_id'] ?? null;
    }
    
    /**
     * Formata para API Claude
     */
    public function toApiFormat(): array
    {
        if ($this->role === self::ROLE_USER) {
            return [
                'role' => 'user',
                'content' => $this->content,
            ];
        }
        
        if ($this->role === self::ROLE_ASSISTANT) {
            return [
                'role' => 'assistant',
                'content' => $this->content,
            ];
        }
        
        if ($this->role === self::ROLE_TOOL_USE) {
            return [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => $this->getToolUseId(),
                        'name' => $this->tool_name,
                        'input' => $this->tool_input,
                    ],
                ],
            ];
        }
        
        if ($this->role === self::ROLE_TOOL_RESULT) {
            return [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $this->getToolUseId(),
                        'content' => $this->tool_output,
                        'is_error' => $this->isError(),
                    ],
                ],
            ];
        }
        
        return [
            'role' => $this->role,
            'content' => $this->content,
        ];
    }
    
    /**
     * Preview do conteúdo (truncado)
     */
    public function preview(int $length = 100): string
    {
        $content = strip_tags($this->content);
        
        if (mb_strlen($content) <= $length) {
            return $content;
        }
        
        return mb_substr($content, 0, $length) . '...';
    }
}
