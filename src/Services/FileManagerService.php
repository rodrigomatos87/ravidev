<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Models\GeneratedFile;

/**
 * Serviço de gerenciamento de arquivos
 */
class FileManagerService
{
    private string $storagePath;
    private string $uploadsPath;
    private array $allowedUploadTypes;
    private int $maxUploadSize;
    
    public function __construct()
    {
        $config = config('app.storage');
        
        $this->storagePath = $config['path'] ?? STORAGE_PATH;
        $this->uploadsPath = $config['uploads'] ?? STORAGE_PATH . '/uploads';
        $this->maxUploadSize = $config['max_upload_size'] ?? 10 * 1024 * 1024;
        $this->allowedUploadTypes = [
            'text/plain',
            'application/json',
            'application/pdf',
            'image/png',
            'image/jpeg',
            'image/gif',
            'application/zip',
            'text/html',
            'text/css',
            'text/javascript',
            'application/javascript'
        ];
    }
    
    /**
     * Salva arquivo gerado pelo agente
     */
    public function saveGeneratedFile(
        int $conversationId,
        string $filename,
        string $content,
        ?int $messageId = null
    ): GeneratedFile {
        $path = "sandbox/{$conversationId}/" . $filename;
        $fullPath = $this->storagePath . '/' . $path;
        
        // Cria diretório se não existe
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Determina se é binário
        $isBinary = $this->isBinaryContent($content);
        
        // Salva arquivo
        file_put_contents($fullPath, $content);
        
        // Cria registro no banco
        return GeneratedFile::create([
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'file_path' => $filename,
            'file_name' => basename($filename),
            'file_type' => $this->getMimeType($filename),
            'file_size' => strlen($content),
            'content' => $isBinary ? null : $content,
            'is_binary' => $isBinary,
            'storage_path' => $path
        ]);
    }
    
    /**
     * Processa upload de arquivo do usuário
     */
    public function processUpload(array $file, string $userUuid): array
    {
        // Valida arquivo
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => $this->getUploadError($file['error'])];
        }
        
        if ($file['size'] > $this->maxUploadSize) {
            return ['success' => false, 'error' => 'Arquivo muito grande'];
        }
        
        $mimeType = mime_content_type($file['tmp_name']);
        if (!in_array($mimeType, $this->allowedUploadTypes)) {
            return ['success' => false, 'error' => 'Tipo de arquivo não permitido'];
        }
        
        // Gera nome seguro
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeName = uuid() . '.' . $ext;
        $path = "uploads/{$userUuid}/" . $safeName;
        $fullPath = $this->storagePath . '/' . $path;
        
        // Cria diretório
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Move arquivo
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            return ['success' => false, 'error' => 'Erro ao salvar arquivo'];
        }
        
        return [
            'success' => true,
            'path' => $path,
            'name' => $file['name'],
            'size' => $file['size'],
            'type' => $mimeType
        ];
    }
    
    /**
     * Obtém arquivo para download
     */
    public function getFile(string $path): ?array
    {
        $fullPath = $this->storagePath . '/' . $path;
        
        if (!file_exists($fullPath)) {
            return null;
        }
        
        return [
            'path' => $fullPath,
            'name' => basename($path),
            'size' => filesize($fullPath),
            'type' => $this->getMimeType($path),
            'content' => file_get_contents($fullPath)
        ];
    }
    
    /**
     * Deleta arquivo
     */
    public function deleteFile(string $path): bool
    {
        $fullPath = $this->storagePath . '/' . $path;
        
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        
        return false;
    }
    
    /**
     * Lista arquivos de uma conversa
     */
    public function listConversationFiles(int $conversationId): array
    {
        return GeneratedFile::where('conversation_id', '=', $conversationId)->get();
    }
    
    /**
     * Cria ZIP com todos os arquivos de uma conversa
     */
    public function createZip(int $conversationId): ?string
    {
        $files = $this->listConversationFiles($conversationId);
        
        if (empty($files)) {
            return null;
        }
        
        $zipPath = $this->storagePath . "/temp/conversation_{$conversationId}.zip";
        $dir = dirname($zipPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        
        foreach ($files as $file) {
            if ($file->content) {
                $zip->addFromString($file->file_path, $file->content);
            } elseif ($file->storage_path) {
                $fullPath = $this->storagePath . '/' . $file->storage_path;
                if (file_exists($fullPath)) {
                    $zip->addFile($fullPath, $file->file_path);
                }
            }
        }
        
        $zip->close();
        
        return $zipPath;
    }
    
    /**
     * Limpa arquivos temporários antigos
     */
    public function cleanupTemp(int $maxAgeHours = 24): int
    {
        $tempDir = $this->storagePath . '/temp';
        if (!is_dir($tempDir)) {
            return 0;
        }
        
        $count = 0;
        $maxAge = time() - ($maxAgeHours * 3600);
        
        foreach (glob($tempDir . '/*') as $file) {
            if (is_file($file) && filemtime($file) < $maxAge) {
                unlink($file);
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Obtém MIME type por extensão
     */
    private function getMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'php' => 'application/x-php',
            'js' => 'application/javascript',
            'ts' => 'application/typescript',
            'json' => 'application/json',
            'html' => 'text/html',
            'htm' => 'text/html',
            'css' => 'text/css',
            'md' => 'text/markdown',
            'txt' => 'text/plain',
            'py' => 'text/x-python',
            'sql' => 'application/sql',
            'xml' => 'application/xml',
            'yaml' => 'application/yaml',
            'yml' => 'application/yaml',
            'sh' => 'application/x-sh',
            'bash' => 'application/x-sh',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
            'csv' => 'text/csv',
            'log' => 'text/plain'
        ];
        
        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }
    
    /**
     * Verifica se conteúdo é binário
     */
    private function isBinaryContent(string $content): bool
    {
        // Verifica se tem bytes nulos ou alta proporção de caracteres não-imprimíveis
        if (str_contains($content, "\0")) {
            return true;
        }
        
        $sample = substr($content, 0, 8192);
        $printable = preg_match_all('/[[:print:]\s]/u', $sample);
        $total = strlen($sample);
        
        return $total > 0 && ($printable / $total) < 0.85;
    }
    
    /**
     * Traduz erro de upload
     */
    private function getUploadError(int $error): string
    {
        return match($error) {
            UPLOAD_ERR_INI_SIZE => 'Arquivo excede o tamanho máximo permitido pelo servidor',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho máximo permitido pelo formulário',
            UPLOAD_ERR_PARTIAL => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado',
            UPLOAD_ERR_CANT_WRITE => 'Erro ao gravar arquivo',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão',
            default => 'Erro desconhecido'
        };
    }
    
    /**
     * Gera preview de arquivo de código
     */
    public function getCodePreview(string $content, int $maxLines = 50): string
    {
        $lines = explode("\n", $content);
        
        if (count($lines) <= $maxLines) {
            return $content;
        }
        
        $preview = array_slice($lines, 0, $maxLines);
        $preview[] = "\n... (" . (count($lines) - $maxLines) . " linhas omitidas)";
        
        return implode("\n", $preview);
    }
    
    /**
     * Detecta linguagem de programação
     */
    public function detectLanguage(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $languages = [
            'php' => 'php',
            'js' => 'javascript',
            'ts' => 'typescript',
            'jsx' => 'jsx',
            'tsx' => 'tsx',
            'py' => 'python',
            'rb' => 'ruby',
            'java' => 'java',
            'c' => 'c',
            'cpp' => 'cpp',
            'h' => 'c',
            'hpp' => 'cpp',
            'cs' => 'csharp',
            'go' => 'go',
            'rs' => 'rust',
            'swift' => 'swift',
            'kt' => 'kotlin',
            'scala' => 'scala',
            'html' => 'html',
            'htm' => 'html',
            'css' => 'css',
            'scss' => 'scss',
            'sass' => 'sass',
            'less' => 'less',
            'json' => 'json',
            'xml' => 'xml',
            'yaml' => 'yaml',
            'yml' => 'yaml',
            'toml' => 'toml',
            'md' => 'markdown',
            'sql' => 'sql',
            'sh' => 'bash',
            'bash' => 'bash',
            'zsh' => 'bash',
            'dockerfile' => 'dockerfile',
            'makefile' => 'makefile'
        ];
        
        // Casos especiais por nome de arquivo
        $basename = strtolower(basename($filename));
        if ($basename === 'dockerfile') return 'dockerfile';
        if ($basename === 'makefile') return 'makefile';
        if (str_starts_with($basename, 'docker-compose')) return 'yaml';
        if ($basename === '.env' || str_starts_with($basename, '.env.')) return 'dotenv';
        
        return $languages[$ext] ?? 'plaintext';
    }
}
