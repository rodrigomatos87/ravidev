<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\Request;
use Ravi\Core\JsonResponse;
use Ravi\Core\Validator;
use Ravi\Core\Encryption;
use Ravi\Models\User;
use Ravi\Models\Project;
use Ravi\Models\Conversation;
use Ravi\Models\Message;
use Ravi\Models\GeneratedFile;

/**
 * API Controller
 * Handles all JSON API endpoints
 */
class ApiController extends Controller
{
    /**
     * Override do helper json() para o padrão deste controller: json($data, $statusHttp).
     * O ApiController já monta o envelope ({success, error/data, ...}) dentro do próprio $data,
     * então quando o 2º argumento é um INTEIRO o tratamos como STATUS HTTP e devolvemos o $data
     * CRU (sem re-embrulhar). Mantém compatibilidade com a forma base json($data, $message, $status)
     * quando o 2º arg é string. PHP 8 permite alargar o tipo do parâmetro (string -> string|int).
     */
    protected function json(mixed $data = null, string|int $message = 'Success', int $status = 200): JsonResponse
    {
        if (is_int($message)) {
            return new JsonResponse($data, $message);
        }
        return parent::json($data, $message, $status);
    }

    // =========================================================================
    // PROJECTS API
    // =========================================================================

    /**
     * List all projects for current user
     */
    public function listProjects(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $status = $request->get('status', 'active');
        
        $projects = Project::findByUser($user['id'], $status);
        
        return $this->json([
            'success' => true,
            'projects' => array_map(fn($p) => $p->toArray(), $projects)
        ]);
    }

    /**
     * Create a new project
     */
    public function createProject(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $request->all();
        
        $rules = [
            'name' => 'required|min:2|max:200',
            'description' => 'max:1000',
            'icon' => 'max:50',
            'color' => 'max:7',
        ];
        
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return $this->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        
        $project = Project::create([
            'uuid' => generateUuid(),
            'user_id' => $user['id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => projectIcon($data['icon'] ?? null),
            'color' => $data['color'] ?? '#6366f1',
            'status' => 'active',
            'settings' => json_encode($data['settings'] ?? []),
        ]);
        
        return $this->json([
            'success' => true,
            'project' => $project->toArray(),
            'message' => 'Projeto criado com sucesso!'
        ], 201);
    }

    /**
     * Get project details
     */
    public function getProject(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        $project = Project::findByUuid($uuid);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Projeto não encontrado'], 404);
        }
        
        $stats = [
            'tokens' => $project->getTokensUsed(),
            'conversations' => Conversation::where('project_id', '=', $project->id)->count(),
        ];

        return $this->json([
            'success' => true,
            'project' => array_merge($project->toArray(), ['stats' => $stats])
        ]);
    }

    /**
     * Update project
     */
    public function updateProject(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $request->all();
        
        $project = Project::findByUuid($uuid);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Projeto não encontrado'], 404);
        }
        
        $rules = [
            'name' => 'min:2|max:200',
            'description' => 'max:1000',
            'type' => 'in:web,app,both',
            'icon' => 'max:50',
            'color' => 'max:7',
        ];

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return $this->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $updateData = [];
        foreach (['name', 'description', 'type', 'icon', 'color'] as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $field === 'icon' ? projectIcon($data[$field]) : $data[$field];
            }
        }
        
        if (isset($data['settings'])) {
            $updateData['settings'] = json_encode($data['settings']);
        }
        
        $project->update($updateData);
        
        return $this->json([
            'success' => true,
            'project' => $project->toArray(),
            'message' => 'Projeto atualizado com sucesso!'
        ]);
    }

    /**
     * Delete project
     */
    public function deleteProject(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        $project = Project::findByUuid($uuid);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Projeto não encontrado'], 404);
        }
        
        $project->delete();
        
        return $this->json([
            'success' => true,
            'message' => 'Projeto excluído com sucesso!'
        ]);
    }

    /**
     * Archive project
     */
    public function archiveProject(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        $project = Project::findByUuid($uuid);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Projeto não encontrado'], 404);
        }
        
        $project->update(['status' => 'archived']);
        
        return $this->json([
            'success' => true,
            'message' => 'Projeto arquivado com sucesso!'
        ]);
    }

    // =========================================================================
    // CONVERSATIONS API
    // =========================================================================

    /**
     * List conversations for a project
     */
    public function listConversations(Request $request, string $projectUuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        $project = Project::findByUuid($projectUuid);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Projeto não encontrado'], 404);
        }
        
        $conversations = Conversation::findByProject($project->id);
        
        return $this->json([
            'success' => true,
            'conversations' => array_map(fn($c) => $c->toArray(), $conversations)
        ]);
    }

    /**
     * Create a new conversation
     */
    public function createConversation(Request $request, string $projectUuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $request->all();
        
        $project = Project::findByUuid($projectUuid);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Projeto não encontrado'], 404);
        }
        
        $rules = [
            'title' => 'required|min:2|max:300',
        ];
        
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return $this->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        
        $conversation = Conversation::create([
            'uuid' => generateUuid(),
            'project_id' => $project->id,
            'title' => $data['title'],
            'status' => 'pending',
            'model_used' => $data['model'] ?? env('CLAUDE_MODEL', 'claude-sonnet-5'),
        ]);
        
        return $this->json([
            'success' => true,
            'conversation' => $conversation->toArray(),
            'message' => 'Conversa criada com sucesso!'
        ], 201);
    }

    /**
     * Get conversation details
     */
    public function getConversation(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        $conversation = Conversation::findByUuid($uuid);
        if (!$conversation) {
            return $this->json(['success' => false, 'error' => 'Conversa não encontrada'], 404);
        }
        
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Acesso negado'], 403);
        }
        
        return $this->json([
            'success' => true,
            'conversation' => $conversation->toArray()
        ]);
    }

    /**
     * Delete conversation
     */
    public function deleteConversation(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        $conversation = Conversation::findByUuid($uuid);
        if (!$conversation) {
            return $this->json(['success' => false, 'error' => 'Conversa não encontrada'], 404);
        }
        
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Acesso negado'], 403);
        }
        
        $conversation->delete();
        
        return $this->json([
            'success' => true,
            'message' => 'Conversa excluída com sucesso!'
        ]);
    }

    /**
     * Cancel running conversation
     */
    public function cancelConversation(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        $conversation = Conversation::findByUuid($uuid);
        if (!$conversation) {
            return $this->json(['success' => false, 'error' => 'Conversa não encontrada'], 404);
        }
        
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Acesso negado'], 403);
        }
        
        if ($conversation->status !== 'running') {
            return $this->json(['success' => false, 'error' => 'Conversa não está em execução'], 400);
        }
        
        $conversation->update([
            'status' => 'cancelled',
            'completed_at' => date('Y-m-d H:i:s')
        ]);
        
        return $this->json([
            'success' => true,
            'message' => 'Conversa cancelada!'
        ]);
    }

    // =========================================================================
    // MESSAGES API
    // =========================================================================

    /**
     * List messages for a conversation
     */
    public function listMessages(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        $conversation = Conversation::findByUuid($uuid);
        if (!$conversation) {
            return $this->json(['success' => false, 'error' => 'Conversa não encontrada'], 404);
        }
        
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Acesso negado'], 403);
        }
        
        $messages = Message::findByConversation($conversation->id);
        
        return $this->json([
            'success' => true,
            'messages' => array_map(fn($m) => $m->toArray(), $messages)
        ]);
    }

    /**
     * Send a message (starts agent)
     */
    public function sendMessage(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $request->all();
        
        $conversation = Conversation::findByUuid($uuid);
        if (!$conversation) {
            return $this->json(['success' => false, 'error' => 'Conversa não encontrada'], 404);
        }
        
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Acesso negado'], 403);
        }
        
        $rules = [
            'content' => 'required|min:1',
        ];
        
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return $this->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        
        // Create user message
        $message = Message::create([
            'uuid' => generateUuid(),
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $data['content'],
        ]);
        
        // Update conversation status
        $conversation->update([
            'status' => 'running',
            'started_at' => $conversation->started_at ?? date('Y-m-d H:i:s')
        ]);
        
        return $this->json([
            'success' => true,
            'message' => $message->toArray(),
            'info' => 'Mensagem enviada. Conecte via WebSocket para acompanhar a execução.'
        ]);
    }

    // =========================================================================
    // FILES API
    // =========================================================================

    /**
     * List files for a conversation
     */
    public function listFiles(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        $conversation = Conversation::findByUuid($uuid);
        if (!$conversation) {
            return $this->json(['success' => false, 'error' => 'Conversa não encontrada'], 404);
        }
        
        $project = Project::find($conversation->project_id);
        if (!$project || !$project->isAccessibleBy((int) $user['id'])) {
            return $this->json(['success' => false, 'error' => 'Acesso negado'], 403);
        }
        
        $files = GeneratedFile::findByConversation($conversation->id);
        
        return $this->json([
            'success' => true,
            'files' => array_map(fn($f) => [
                'uuid' => $f->uuid,
                'file_name' => $f->file_name,
                'file_path' => $f->file_path,
                'file_type' => $f->file_type,
                'file_size' => $f->file_size,
                'is_binary' => (bool) $f->is_binary,
                'created_at' => $f->created_at,
            ], $files)
        ]);
    }

    /**
     * Download file (redirect to FileController)
     */
    public function downloadFile(Request $request, string $uuid): JsonResponse
    {
        return $this->json([
            'success' => true,
            'download_url' => '/files/download/' . $uuid
        ]);
    }

    /**
     * Download all files as ZIP (redirect to FileController)
     */
    public function downloadAllFiles(Request $request, string $uuid): JsonResponse
    {
        return $this->json([
            'success' => true,
            'download_url' => '/files/download-all/' . $uuid
        ]);
    }

    // =========================================================================
    // USER API
    // =========================================================================

    /**
     * Get current user profile
     */
    public function getProfile(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        
        return $this->json([
            'success' => true,
            'user' => [
                'uuid' => $user['uuid'],
                'name' => $user['name'],
                'email' => $user['email'],
                'avatar_url' => $user['avatar_url'],
                'plan' => $user['plan'],
                'tokens_used' => $user['tokens_used'],
                'tokens_limit' => $user['tokens_limit'],
                'has_api_key' => !empty($user['api_key_encrypted']),
                'created_at' => $user['created_at'],
            ]
        ]);
    }

    /**
     * Update profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $request->all();
        
        $rules = [
            'name' => 'min:2|max:100',
            'email' => 'email|max:255',
        ];
        
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return $this->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        
        // Check email uniqueness
        if (isset($data['email']) && $data['email'] !== $user['email']) {
            $existing = User::findByEmail($data['email']);
            if ($existing) {
                return $this->json(['success' => false, 'errors' => ['email' => ['Email já em uso']]], 422);
            }
        }
        
        $userModel = User::find($user['id']);
        $updateData = [];
        
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['email'])) $updateData['email'] = $data['email'];
        
        $userModel->update($updateData);
        
        return $this->json([
            'success' => true,
            'message' => 'Perfil atualizado!'
        ]);
    }

    /**
     * Update password
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $request->all();
        
        $rules = [
            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',
        ];
        
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return $this->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        
        $userModel = User::find($user['id']);
        if (!password_verify($data['current_password'], $userModel->password_hash)) {
            return $this->json(['success' => false, 'errors' => ['current_password' => ['Senha atual incorreta']]], 422);
        }
        
        $userModel->update([
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12])
        ]);
        
        return $this->json([
            'success' => true,
            'message' => 'Senha alterada!'
        ]);
    }

    /**
     * Update API key
     */
    public function updateApiKey(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $request->all();
        
        if (empty($data['api_key'])) {
            return $this->json(['success' => false, 'errors' => ['api_key' => ['Chave de API obrigatória']]], 422);
        }
        
        $apiKey = trim($data['api_key']);
        if (!str_starts_with($apiKey, 'sk-ant-')) {
            return $this->json(['success' => false, 'errors' => ['api_key' => ['Formato inválido']]], 422);
        }
        
        $encryption = new Encryption();
        $encrypted = $encryption->encrypt($apiKey);
        
        $userModel = User::find($user['id']);
        $userModel->update(['api_key_encrypted' => $encrypted]);
        
        return $this->json([
            'success' => true,
            'message' => 'Chave de API atualizada!'
        ]);
    }

    /**
     * Get usage statistics
     */
    public function getUsage(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $userModel = User::find($user['id']);
        
        $stats = $userModel->getUsageStats();
        
        return $this->json([
            'success' => true,
            'usage' => $stats
        ]);
    }

    // =========================================================================
    // STATS API
    // =========================================================================

    /**
     * Get dashboard statistics
     */
    public function dashboardStats(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $userModel = User::find($user['id']);
        
        $stats = $userModel->getUsageStats();

        return $this->json([
            'success' => true,
            'stats' => $stats
        ]);
    }

    /**
     * Get detailed usage statistics
     */
    public function usageStats(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $period = $request->get('period', '30days');
        
        $userModel = User::find($user['id']);
        $stats = $userModel->getUsageStats();
        
        return $this->json([
            'success' => true,
            'period' => $period,
            'stats' => $stats
        ]);
    }
}
