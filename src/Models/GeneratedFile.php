<?php

declare(strict_types=1);

namespace Ravi\Models;

/**
 * Model de arquivo gerado
 */
class GeneratedFile extends Model
{
    protected static string $table = 'generated_files';
    
    protected static array $fillable = [
        'uuid',
        'conversation_id',
        'message_id',
        'file_path',
        'file_name',
        'file_type',
        'mime_type',
        'file_size',
        'content',
        'is_binary',
        'storage_path',
    ];
    
    protected static array $casts = [
        'conversation_id' => 'integer',
        'message_id' => 'integer',
        'file_size' => 'integer',
        'is_binary' => 'boolean',
    ];
    
    /**
     * Busca por UUID
     */
    public static function findByUuid(string $uuid): ?self
    {
        return static::findBy('uuid', $uuid);
    }

    /**
     * Lista arquivos gerados de uma conversa
     */
    public static function findByConversation(int $conversationId): array
    {
        return static::where('conversation_id', '=', $conversationId)
            ->orderBy('created_at', 'DESC')
            ->get();
    }
    
    /**
     * Cria registro de arquivo
     */
    public static function createFromPath(
        int $conversationId,
        string $filePath,
        ?string $content = null,
        ?int $messageId = null
    ): self {
        $fileName = basename($filePath);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        
        return static::create([
            'uuid' => uuid(),
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_type' => self::getMimeType($extension),
            'mime_type' => self::getMimeType($extension),
            'file_size' => $content ? strlen($content) : 0,
            'content' => $content,
            'is_binary' => self::isBinaryExtension($extension),
        ]);
    }
    
    /**
     * Conversa do arquivo
     */
    public function conversation(): ?Conversation
    {
        return Conversation::find($this->conversation_id);
    }
    
    /**
     * Mensagem associada
     */
    public function message(): ?Message
    {
        return $this->message_id ? Message::find($this->message_id) : null;
    }
    
    /**
     * Extensão do arquivo
     */
    public function extension(): string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }
    
    /**
     * Verifica se é texto
     */
    public function isText(): bool
    {
        return !$this->is_binary;
    }
    
    /**
     * Verifica se é imagem
     */
    public function isImage(): bool
    {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
    }
    
    /**
     * Verifica se é código
     */
    public function isCode(): bool
    {
        return in_array($this->extension(), [
            'php', 'js', 'ts', 'jsx', 'tsx', 'py', 'rb', 'java',
            'c', 'cpp', 'h', 'go', 'rs', 'swift', 'kt', 'cs',
            'html', 'css', 'scss', 'less', 'json', 'xml', 'yaml', 'yml',
            'sql', 'sh', 'bash', 'ps1', 'dockerfile', 'md', 'txt',
        ]);
    }
    
    /**
     * Linguagem para syntax highlighting
     */
    public function language(): string
    {
        return match ($this->extension()) {
            'php' => 'php',
            'js', 'mjs' => 'javascript',
            'ts' => 'typescript',
            'jsx' => 'jsx',
            'tsx' => 'tsx',
            'py' => 'python',
            'rb' => 'ruby',
            'java' => 'java',
            'c', 'h' => 'c',
            'cpp' => 'cpp',
            'go' => 'go',
            'rs' => 'rust',
            'swift' => 'swift',
            'kt' => 'kotlin',
            'cs' => 'csharp',
            'html', 'htm' => 'html',
            'css' => 'css',
            'scss' => 'scss',
            'less' => 'less',
            'json' => 'json',
            'xml' => 'xml',
            'yaml', 'yml' => 'yaml',
            'sql' => 'sql',
            'sh', 'bash' => 'bash',
            'ps1' => 'powershell',
            'md' => 'markdown',
            'dockerfile' => 'dockerfile',
            default => 'plaintext',
        };
    }
    
    /**
     * Ícone do arquivo
     */
    public function icon(): string
    {
        if ($this->isImage()) {
            return '🖼️';
        }
        
        return match ($this->extension()) {
            'php' => '🐘',
            'js', 'ts', 'jsx', 'tsx' => '📜',
            'py' => '🐍',
            'html' => '🌐',
            'css', 'scss', 'less' => '🎨',
            'json' => '📋',
            'sql' => '🗃️',
            'md' => '📝',
            'sh', 'bash' => '⚡',
            'dockerfile' => '🐳',
            default => '📄',
        };
    }
    
    /**
     * Tamanho formatado
     */
    public function sizeFormatted(): string
    {
        return formatBytes($this->file_size);
    }
    
    /**
     * Conteúdo com preview limitado
     */
    public function preview(int $lines = 20): ?string
    {
        if ($this->is_binary || empty($this->content)) {
            return null;
        }
        
        $contentLines = explode("\n", $this->content);
        
        if (count($contentLines) <= $lines) {
            return $this->content;
        }
        
        return implode("\n", array_slice($contentLines, 0, $lines)) . "\n// ... (" . (count($contentLines) - $lines) . " more lines)";
    }
    
    /**
     * MIME type por extensão
     */
    private static function getMimeType(string $extension): string
    {
        return match (strtolower($extension)) {
            'php' => 'text/x-php',
            'js' => 'text/javascript',
            'ts' => 'text/typescript',
            'json' => 'application/json',
            'html', 'htm' => 'text/html',
            'css' => 'text/css',
            'xml' => 'application/xml',
            'sql' => 'text/x-sql',
            'md' => 'text/markdown',
            'txt' => 'text/plain',
            'py' => 'text/x-python',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }
    
    /**
     * Verifica se extensão é binária
     */
    private static function isBinaryExtension(string $extension): bool
    {
        return in_array(strtolower($extension), [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'zip', 'rar', 'tar', 'gz', '7z',
            'exe', 'dll', 'so', 'dylib',
            'mp3', 'mp4', 'wav', 'avi', 'mov', 'mkv',
            'ttf', 'otf', 'woff', 'woff2', 'eot',
        ]);
    }
}
