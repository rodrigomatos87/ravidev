<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\{Request, Response, JsonResponse};
use Ravi\Models\{Project, Conversation, Message, GeneratedFile};

/**
 * Conversation Controller
 * Manages conversations/tasks within projects
 */
class ConversationController extends Controller
{
    /**
     * List conversations for a project
     */
    public function index(Request $request, string $projectUuid): Response|JsonResponse
    {
        $this->requireAuth($request);
        
        $project = $this->findProject($projectUuid);
        
        if ($project === null) {
            if ($this->wantsJson($request)) {
                return JsonResponse::notFound('Projeto não encontrado');
            }
            $this->abort(404);
        }
        
        $status = $request->get('status');
        $limit = min((int)$request->get('limit', 20), 100);
        $offset = max((int)$request->get('offset', 0), 0);
        
        $query = Conversation::where('project_id', '=', $project->id);
        
        if ($status && in_array($status, ['pending', 'running', 'completed', 'failed', 'cancelled'])) {
            $query->where('status', '=', $status);
        }
        
        $conversations = $query->orderBy('updated_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
        
        $total = Conversation::where('project_id', '=', $project->id)->count();
        
        if ($this->wantsJson($request)) {
            return $this->json([
                'conversations' => array_map(fn($c) => $this->formatConversation($c), $conversations),
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        }
        
        return $this->view('projects/show', [
            'project' => $project,
            'conversations' => $conversations,
        ]);
    }
    
    /**
     * Create new conversation/task
     */
    public function store(Request $request, string $projectUuid): JsonResponse
    {
        $this->requireAuth($request);
        $this->requireApiKey($request);
        
        $project = $this->findProject($projectUuid);
        
        if ($project === null) {
            return JsonResponse::notFound('Projeto não encontrado');
        }
        
        $validation = $this->validate($request, [
            'title' => 'required|min:2|max:300',
            'message' => 'required|min:1|max:50000',
        ]);
        
        if (!$validation['valid']) {
            return JsonResponse::validationError($validation['errors']);
        }
        
        // Create conversation
        $conversation = Conversation::create([
            'project_id' => $project->id,
            'title' => $validation['data']['title'],
            'status' => 'pending',
            'model_used' => config('claude.model', 'claude-sonnet-5'),
        ]);
        
        if ($conversation === null) {
            return $this->jsonError('Erro ao criar conversa', 500);
        }
        
        // Create initial user message
        $message = Message::createUserMessage(
            $conversation->id,
            $validation['data']['message']
        );
        
        if ($message === null) {
            // Rollback conversation
            $conversation->delete();
            return $this->jsonError('Erro ao criar mensagem', 500);
        }
        
        return $this->json([
            'conversation' => $this->formatConversation($conversation),
            'message' => $this->formatMessage($message),
        ], 'Conversa criada com sucesso', 201);
    }
    
    /**
     * Show conversation details
     */
    public function show(Request $request, string $uuid): Response|JsonResponse
    {
        $this->requireAuth($request);
        
        $conversation = $this->findConversation($uuid);
        
        if ($conversation === null) {
            if ($this->wantsJson($request)) {
                return JsonResponse::notFound('Conversa não encontrada');
            }
            $this->abort(404);
        }
        
        $project = Project::find($conversation->project_id);
        
        // Get messages
        $messages = Message::where('conversation_id', '=', $conversation->id)
            ->orderBy('created_at', 'ASC')
            ->get();
        
        // Get generated files
        $files = GeneratedFile::where('conversation_id', '=', $conversation->id)
            ->orderBy('created_at', 'ASC')
            ->get();
        
        if ($this->wantsJson($request)) {
            return $this->json([
                'conversation' => $this->formatConversation($conversation),
                'project' => [
                    'id' => $project?->uuid,
                    'name' => $project?->name,
                ],
                'messages' => array_map(fn($m) => $this->formatMessage($m), $messages),
                'files' => array_map(fn($f) => $this->formatFile($f), $files),
            ]);
        }
        
        return $this->view('chat/index', [
            'conversation' => $conversation,
            'project' => $project,
            'messages' => $messages,
            'files' => $files,
            'hasApiKey' => $this->user->hasApiKey(),
        ]);
    }
    
    /**
     * Update conversation title
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        
        $conversation = $this->findConversation($uuid);
        
        if ($conversation === null) {
            return JsonResponse::notFound('Conversa não encontrada');
        }
        
        $validation = $this->validate($request, [
            'title' => 'required|min:2|max:300',
        ]);
        
        if (!$validation['valid']) {
            return JsonResponse::validationError($validation['errors']);
        }
        
        $conversation->update(['title' => $validation['data']['title']]);
        
        return $this->json($this->formatConversation($conversation), 'Título atualizado');
    }
    
    /**
     * Cancel running conversation
     */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        
        $conversation = $this->findConversation($uuid);
        
        if ($conversation === null) {
            return JsonResponse::notFound('Conversa não encontrada');
        }
        
        if (!in_array($conversation->status, ['pending', 'running'])) {
            return $this->jsonError('Esta conversa não pode ser cancelada', 400);
        }
        
        $conversation->cancel();
        
        return $this->json($this->formatConversation($conversation), 'Conversa cancelada');
    }
    
    /**
     * Retry failed conversation
     */
    public function retry(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        $this->requireApiKey($request);
        
        $conversation = $this->findConversation($uuid);
        
        if ($conversation === null) {
            return JsonResponse::notFound('Conversa não encontrada');
        }
        
        if (!in_array($conversation->status, ['failed', 'cancelled'])) {
            return $this->jsonError('Apenas conversas com falha podem ser reexecutadas', 400);
        }
        
        // Reset status to pending
        $conversation->update([
            'status' => 'pending',
            'error_message' => null,
        ]);
        
        return $this->json($this->formatConversation($conversation), 'Conversa reagendada');
    }
    
    /**
     * Delete conversation
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        
        $conversation = $this->findConversation($uuid);
        
        if ($conversation === null) {
            return JsonResponse::notFound('Conversa não encontrada');
        }
        
        // Delete associated files from storage
        $files = GeneratedFile::where('conversation_id', '=', $conversation->id)->get();
        foreach ($files as $file) {
            if ($file->storage_path && file_exists($file->storage_path)) {
                @unlink($file->storage_path);
            }
        }
        
        // Delete conversation (cascades to messages and files via FK)
        $conversation->delete();
        
        return $this->json(null, 'Conversa excluída com sucesso');
    }
    
    /**
     * Get messages for conversation
     */
    public function messages(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        
        $conversation = $this->findConversation($uuid);
        
        if ($conversation === null) {
            return JsonResponse::notFound('Conversa não encontrada');
        }
        
        $afterId = $request->get('after');
        
        $query = Message::where('conversation_id', '=', $conversation->id)
            ->orderBy('created_at', 'ASC');
        
        if ($afterId) {
            $afterMessage = Message::where('uuid', '=', $afterId)->first();
            if ($afterMessage) {
                $query->where('id', '>', $afterMessage->id);
            }
        }
        
        $messages = $query->get();
        
        return $this->json([
            'messages' => array_map(fn($m) => $this->formatMessage($m), $messages),
        ]);
    }
    
    /**
     * Get generated files for conversation
     */
    public function files(Request $request, string $uuid): JsonResponse
    {
        $this->requireAuth($request);
        
        $conversation = $this->findConversation($uuid);
        
        if ($conversation === null) {
            return JsonResponse::notFound('Conversa não encontrada');
        }
        
        $files = GeneratedFile::where('conversation_id', '=', $conversation->id)
            ->orderBy('file_path', 'ASC')
            ->get();
        
        return $this->json([
            'files' => array_map(fn($f) => $this->formatFile($f), $files),
        ]);
    }
    
    /**
     * Find project belonging to current user
     */
    private function findProject(string $uuid): ?Project
    {
        $p = Project::findAccessible($uuid, (int) $this->user->id);
        return ($p && ($p->status ?? '') !== 'deleted') ? $p : null;
    }
    
    /**
     * Find conversation belonging to user's projects
     */
    private function findConversation(string $uuid): ?Conversation
    {
        $conversation = Conversation::where('uuid', '=', $uuid)->first();
        
        if ($conversation === null) {
            return null;
        }
        
        // Verifica acesso ao projeto (dono ou membro)
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $this->user->id)) {
            return null;
        }
        
        return $conversation;
    }
    
    /**
     * Format conversation for API response
     */
    private function formatConversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->uuid,
            'title' => $conversation->title,
            'status' => $conversation->status,
            'model' => $conversation->model_used,
            'tokens' => [
                'input' => $conversation->total_tokens_input,
                'output' => $conversation->total_tokens_output,
                'total' => $conversation->totalTokens(),
            ],
            'duration' => $conversation->getDuration(),
            'error_message' => $conversation->error_message,
            'started_at' => $conversation->started_at,
            'completed_at' => $conversation->completed_at,
            'created_at' => $conversation->created_at,
            'updated_at' => $conversation->updated_at,
        ];
    }
    
    /**
     * Format message for API response
     */
    private function formatMessage(Message $message): array
    {
        $data = [
            'id' => $message->uuid,
            'role' => $message->role,
            'content' => $message->content,
            'tokens' => $message->tokens_used,
            'created_at' => $message->created_at,
        ];
        
        if ($message->isToolUse()) {
            $data['tool'] = [
                'name' => $message->tool_name,
                'input' => $message->tool_input,
            ];
        }
        
        if ($message->isToolResult()) {
            $data['tool'] = [
                'name' => $message->tool_name,
                'output' => $message->tool_output,
            ];
        }
        
        if ($message->thinking_content) {
            $data['thinking'] = $message->thinking_content;
        }
        
        return $data;
    }
    
    /**
     * Format file for API response
     */
    private function formatFile(GeneratedFile $file): array
    {
        return [
            'id' => $file->uuid,
            'path' => $file->file_path,
            'name' => $file->file_name,
            'type' => $file->file_type,
            'size' => $file->file_size,
            'is_binary' => $file->is_binary,
            'icon' => $file->icon(),
            'language' => $file->language(),
            'preview' => $file->is_binary ? null : implode("\n", array_slice(explode("\n", (string) $file->content), 0, 50)),
            'download_url' => '/api/files/' . $file->uuid . '/download',
            'created_at' => $file->created_at,
        ];
    }
}
