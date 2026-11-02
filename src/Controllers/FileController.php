<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\Request;
use Ravi\Core\Response;
use Ravi\Core\JsonResponse;
use Ravi\Models\GeneratedFile;
use Ravi\Models\Conversation;
use Ravi\Models\Project;
use ZipArchive;

/**
 * File Controller
 * Handles file downloads, previews, and management
 */
class FileController extends Controller
{
    /**
     * List files by conversation
     */
    public function listByConversation(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        // Find conversation and verify ownership
        $conversation = Conversation::findByUuid($uuid);
        if (!$conversation) {
            return new JsonResponse(['success' => false, 'error' => 'Conversa não encontrada'], 404);
        }
        
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return new JsonResponse(['success' => false, 'error' => 'Acesso negado'], 403);
        }
        
        // Get files
        $files = GeneratedFile::findByConversation($conversation->id);
        
        return $this->json([
            'success' => true,
            'files' => array_map(function ($file) {
                return [
                    'uuid' => $file->uuid,
                    'file_name' => $file->file_name,
                    'file_path' => $file->file_path,
                    'file_type' => $file->file_type,
                    'mime_type' => $file->mime_type,
                    'file_size' => $file->file_size,
                    'is_binary' => (bool) $file->is_binary,
                    'created_at' => $file->created_at,
                ];
            }, $files)
        ]);
    }

    /**
     * Download a single file
     */
    public function download(Request $request, string $uuid): Response
    {
        $user = $this->getCurrentUser();
        
        // Find file
        $file = GeneratedFile::findByUuid($uuid);
        if (!$file) {
            return new Response('Arquivo não encontrado', 404);
        }
        
        // Verify ownership
        $conversation = Conversation::find($file->conversation_id);
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return new Response('Acesso negado', 403);
        }
        
        // Get file content
        $content = $file->content;
        
        // If file is stored on disk, read from there
        if (!empty($file->storage_path) && file_exists($file->storage_path)) {
            $content = file_get_contents($file->storage_path);
        }
        
        if ($content === null || $content === false) {
            return new Response('Conteúdo do arquivo não disponível', 404);
        }
        
        // Determine MIME type
        $mimeType = $file->mime_type ?? 'application/octet-stream';
        if (!$file->is_binary && empty($file->mime_type)) {
            $mimeType = 'text/plain; charset=utf-8';
        }
        
        // Send file
        $response = new Response($content);
        $response->header('Content-Type', $mimeType);
        $response->header('Content-Disposition', 'attachment; filename="' . $file->file_name . '"');
        $response->header('Content-Length', (string) strlen($content));
        $response->header('Cache-Control', 'no-cache, must-revalidate');
        
        return $response;
    }

    /**
     * Download all files as ZIP
     */
    public function downloadAll(Request $request, string $conversationUuid): Response
    {
        $user = $this->getCurrentUser();
        
        // Find conversation and verify ownership
        $conversation = Conversation::findByUuid($conversationUuid);
        if (!$conversation) {
            return new Response('Conversa não encontrada', 404);
        }
        
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return new Response('Acesso negado', 403);
        }
        
        // Get files
        $files = GeneratedFile::findByConversation($conversation->id);
        
        if (empty($files)) {
            return new Response('Nenhum arquivo para baixar', 404);
        }
        
        // Create ZIP file
        $zipPath = STORAGE_PATH . '/cache/download_' . $conversationUuid . '_' . time() . '.zip';
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new Response('Erro ao criar arquivo ZIP', 500);
        }
        
        foreach ($files as $file) {
            $content = $file->content;
            
            // If file is stored on disk, read from there
            if (!empty($file->storage_path) && file_exists($file->storage_path)) {
                $content = file_get_contents($file->storage_path);
            }
            
            if ($content !== null && $content !== false) {
                $zip->addFromString($file->file_path, $content);
            }
        }
        
        $zip->close();
        
        // Read ZIP content
        $zipContent = file_get_contents($zipPath);
        
        // Delete temporary ZIP
        unlink($zipPath);
        
        // Generate filename
        $filename = slugify($conversation->title) . '_files.zip';
        
        // Send ZIP
        $response = new Response($zipContent);
        $response->header('Content-Type', 'application/zip');
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->header('Content-Length', (string) strlen($zipContent));
        $response->header('Cache-Control', 'no-cache, must-revalidate');
        
        return $response;
    }

    /**
     * Preview file content
     */
    public function preview(Request $request, string $uuid): Response|JsonResponse
    {
        $user = $this->getCurrentUser();
        
        // Find file
        $file = GeneratedFile::findByUuid($uuid);
        if (!$file) {
            return new JsonResponse(['success' => false, 'error' => 'Arquivo não encontrado'], 404);
        }
        
        // Verify ownership
        $conversation = Conversation::find($file->conversation_id);
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return new JsonResponse(['success' => false, 'error' => 'Acesso negado'], 403);
        }
        
        // Check if binary
        if ($file->is_binary) {
            return $this->json([
                'success' => true,
                'is_binary' => true,
                'file_name' => $file->file_name,
                'file_size' => $file->file_size,
                'message' => 'Arquivo binário - faça download para visualizar'
            ]);
        }
        
        // Get content
        $content = $file->content;
        
        if (!empty($file->storage_path) && file_exists($file->storage_path)) {
            $content = file_get_contents($file->storage_path);
        }
        
        // Limit preview size
        $maxPreviewSize = 100000; // 100KB
        $truncated = false;
        
        if (strlen($content) > $maxPreviewSize) {
            $content = substr($content, 0, $maxPreviewSize);
            $truncated = true;
        }
        
        return $this->json([
            'success' => true,
            'is_binary' => false,
            'file_name' => $file->file_name,
            'file_path' => $file->file_path,
            'file_type' => $file->file_type,
            'file_size' => $file->file_size,
            'content' => $content,
            'truncated' => $truncated,
            'language' => getLanguageFromExtension($file->file_name)
        ]);
    }
}
