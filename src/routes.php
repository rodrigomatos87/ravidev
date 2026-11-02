<?php

declare(strict_types=1);

use Ravi\Core\Router;

/** @var Router $router */

// ============================================================================
// PUBLIC ROUTES
// ============================================================================

$router->get('/', function() {
    if (isset($_SESSION['user'])) {
        header('Location: /dashboard');
        exit;
    }
    // #5 — o admin pode escolher mostrar a homepage ou ir direto ao login.
    if (\Ravi\Models\Setting::get('show_homepage', '1') !== '1') {
        header('Location: /login');
        exit;
    }
    return view('landing');
});

$router->get('/modulos/{modulo}/{arquivo}', function(\Ravi\Core\Request $request, string $modulo, string $arquivo) {
    return \Ravi\Core\Modulos::servirEstatico($modulo, $arquivo);
});

$router->get('/health', function() {
    return json_encode([
        'status' => 'ok',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0.0'
    ]);
});

// ============================================================================
// AUTHENTICATION ROUTES
// ============================================================================

$router->get('/login', 'AuthController@showLogin');
$router->get('/login/2fa', 'AuthController@show2fa');
$router->get('/register', 'AuthController@showRegister');
$router->get('/forgot-password', 'AuthController@showForgotPassword');
$router->get('/reset-password', 'AuthController@showResetPassword');
$router->get('/verify-email/{token}', 'AuthController@verifyEmail');
// Os POST de autenticação também exigem o token CSRF (o bootstrap semeia o token antes do login;
// os formulários usam csrf_field()). Sem isto, login, logout e reset podiam ser forjados de outro site.
$router->group(['middleware' => ['csrf']], function(Router $router) {
    $router->post('/login', 'AuthController@login');
    $router->post('/login/2fa', 'AuthController@verify2fa');
    $router->post('/login/2fa/resend', 'AuthController@resend2fa');
    $router->post('/register', 'AuthController@register');
    $router->post('/logout', 'AuthController@logout');
    $router->post('/forgot-password', 'AuthController@forgotPassword');
    $router->post('/reset-password', 'AuthController@resetPassword');
    $router->post('/resend-verification', 'AuthController@resendVerification');
});

// ============================================================================
// AUTHENTICATED ROUTES
// ============================================================================

$router->group(['middleware' => ['auth', 'csrf']], function(Router $router) {
    $router->get('/dashboard', 'DashboardController@index');
    $router->get('/dashboard/stats', 'DashboardController@stats');
    $router->get('/dashboard/server', 'DashboardController@serverStats');
    $router->get('/dashboard/search', 'DashboardController@search');
    $router->get('/dashboard/notifications', 'DashboardController@notifications');
    // #6 — Agendamentos do usuário
    $router->get('/schedules', 'ScheduleController@index');
    $router->post('/schedules', 'ScheduleController@store');
    $router->post('/schedules/{id}/toggle', 'ScheduleController@toggle');
    $router->post('/schedules/{id}/delete', 'ScheduleController@destroy');
    $router->get('/schedules/{id}/runs', 'ScheduleController@runs');
    $router->post('/schedules/{id}/run', 'ScheduleController@runNow');

    // Administração (gestão de usuários + impersonação) — só role=admin
    $router->group(['prefix' => '/admin', 'middleware' => ['admin']], function(Router $router) {
        $router->get('/', 'AdminController@users');
        $router->get('/users', 'AdminController@users');
        // Monitoramento do servidor (host + containers), atualizado a cada 1s
        $router->get('/monitoring', 'AdminController@monitoring');
        $router->get('/monitoring/data', 'AdminController@monitoringData');
        $router->post('/monitoring/kill', 'AdminController@killContainer');
        $router->post('/monitoring/restart', 'AdminController@restartContainer');
        $router->post('/impersonate/{id}', 'AdminController@impersonate');
        // Gestão de usuários
        $router->post('/users/create', 'AdminController@createUser');
        $router->post('/users/{id}/approve', 'AdminController@approveUser');
        $router->post('/users/{id}/reject', 'AdminController@rejectUser');
        $router->post('/users/{id}/update', 'AdminController@updateUser');
        $router->post('/users/{id}/password', 'AdminController@setUserPassword');
        $router->post('/users/{id}/toggle', 'AdminController@toggleUser');
        $router->post('/users/{id}/license-choice', 'AdminController@toggleUserLicenseChoice');
        $router->post('/users/{id}/delete', 'AdminController@deleteUser');
        // Configuração de e-mail (SMTP) — movida para /settings/email; redirect de compatibilidade
        $router->get('/smtp', 'AdminController@smtp');
        // Licenças do Claude Code (Max): cadastrar várias + escolher qual cada consumidor usa
        $router->get('/licenses', 'AdminController@licenses');
        $router->get('/licenses/usage', 'AdminController@licensesUsage');
        $router->post('/licenses/create', 'AdminController@createLicense');
        $router->post('/licenses/assign', 'AdminController@assignLicense');
        $router->post('/licenses/api-routing', 'AdminController@saveApiRouting');
        $router->post('/licenses/model-backups', 'AdminController@saveModelBackups');
        $router->post('/licenses/autonomous-routing', 'AdminController@saveAutonomousRouting');
        $router->post('/licenses/assign-user', 'AdminController@assignUserLicense');
        $router->post('/licenses/{id}/update', 'AdminController@updateLicense');
        $router->post('/licenses/{id}/toggle', 'AdminController@toggleLicense');
        $router->post('/licenses/{id}/delete', 'AdminController@deleteLicense');
        $router->post('/licenses/{id}/test', 'AdminController@testLicense');
        // Licenças do ChatGPT (Codex na IDE): auth.json por conta + escolha por usuário
        $router->get('/licenses/chatgpt', 'AdminController@chatgptLicenses');
        $router->post('/licenses/chatgpt/create', 'AdminController@createChatgptLicense');
        $router->post('/licenses/chatgpt/assign', 'AdminController@assignChatgptLicense');
        $router->post('/licenses/chatgpt/assign-user', 'AdminController@assignUserChatgptLicense');
        $router->post('/licenses/chatgpt/{id}/update', 'AdminController@updateChatgptLicense');
        $router->post('/licenses/chatgpt/{id}/toggle', 'AdminController@toggleChatgptLicense');
        $router->post('/licenses/chatgpt/{id}/delete', 'AdminController@deleteChatgptLicense');
        $router->post('/licenses/chatgpt/{id}/test', 'AdminController@testChatgptLicense');
        // Gateway de API (compatível Anthropic) — tokens + consumo
        $router->get('/api', 'AdminController@apiGateway');
        $router->get('/api/tokens/{id}/history', 'AdminController@apiGatewayHistory');
        $router->post('/api/tokens', 'AdminController@createApiToken');
        $router->post('/api/tokens/{id}/revoke', 'AdminController@revokeApiToken');
        $router->post('/api/tokens/{id}/license', 'AdminController@assignApiTokenLicense');
        $router->post('/api/tokens/{id}/identity', 'AdminController@toggleApiTokenIdentity');
        $router->post('/api/tokens/{id}/backend', 'AdminController@setApiTokenBackend');
        $router->post('/api/deepseek/key', 'AdminController@saveDeepseekKey');
        $router->post('/api/deepseek/test', 'AdminController@testDeepseek');
        $router->get('/api/tokens/{id}/suspeitas', 'AdminController@apiGatewaySuspicious');
        $router->get('/api/tokens/{id}/models', 'AdminController@apiGatewayModels');
        $router->post('/api/tokens/{id}/models', 'AdminController@saveApiTokenModels');
        $router->get('/api/tokens/{id}/fields', 'AdminController@apiGatewayFields');
        $router->post('/api/tokens/{id}/fields', 'AdminController@addApiTokenField');
        $router->post('/api/fields/{fieldId}/required', 'AdminController@toggleApiTokenField');
        $router->post('/api/fields/{fieldId}/delete', 'AdminController@deleteApiTokenField');
        $router->post('/api/tokens/{id}/delete', 'AdminController@deleteApiToken');
        $router->post('/api/endereco', 'AdminController@salvarEnderecoGateway');
        $router->get('/api/endereco/verificar', 'AdminController@verificarEnderecoGateway');
        // Rotas administrativas dos módulos opcionais (modulos/<nome>/modulo.php -> 'rotas_admin')
        \Ravi\Core\Modulos::rotasAdmin($router);
    });
    // Encerrar impersonação: precisa funcionar mesmo agindo como o usuário-alvo (não é admin agora),
    // então fica fora do middleware 'admin' (só 'auth'); o controller valida o 'impersonator'.
    $router->post('/impersonation/stop', 'AdminController@stopImpersonation');
    
    $router->group(['prefix' => '/projects'], function(Router $router) {
        $router->get('/', 'ProjectController@index');
        $router->get('/create', 'ProjectController@create');
        $router->post('/', 'ProjectController@store');
        // Somente administrador: liga/desliga "ver projetos de todos os usuários" (barra lateral + lista)
        $router->post('/all-users', 'ProjectController@toggleAllUsers');
        $router->get('/{uuid}', 'ProjectController@show');
        $router->post('/{uuid}/upload', 'ProjectController@upload');
        $router->get('/{uuid}/share/users', 'ProjectController@shareUsers');
        $router->post('/{uuid}/share', 'ProjectController@share');
        $router->post('/{uuid}/unshare', 'ProjectController@unshare');
        $router->post('/{uuid}/transfer', 'ProjectController@transferOwnership');
        $router->post('/{uuid}/leave', 'ProjectController@leave');
        $router->post('/{uuid}/clone', 'ProjectController@cloneProject');
        $router->get('/{uuid}/import/status', 'ProjectController@importStatus');
        // Git/GitHub por projeto (configuração só do dono)
        $router->get('/{uuid}/git/repos', 'ProjectController@gitRepos');
        $router->post('/{uuid}/git/link', 'ProjectController@gitLink');
        $router->post('/{uuid}/git/unlink', 'ProjectController@gitUnlink');
        $router->post('/{uuid}/git/auto-sync', 'ProjectController@gitAutoSync');
        $router->post('/{uuid}/git/sync', 'ProjectController@gitSync');
        $router->get('/{uuid}/git/status', 'ProjectController@gitStatus');
        $router->get('/{uuid}/git/commits', 'ProjectController@gitCommits');
        $router->post('/{uuid}/git/revert', 'ProjectController@gitRevert');
        // Contexto permanente: memórias + fontes externas (SSH) + acesso por membro
        $router->get('/{uuid}/memories', 'ProjectController@memories');
        $router->post('/{uuid}/memories', 'ProjectController@memorySave');
        $router->post('/{uuid}/memories/delete', 'ProjectController@memoryDelete');
        $router->get('/{uuid}/servers', 'ProjectController@servers');
        $router->post('/{uuid}/servers', 'ProjectController@serverSave');
        $router->post('/{uuid}/servers/delete', 'ProjectController@serverDelete');
        $router->post('/{uuid}/servers/toggle', 'ProjectController@serverToggle');
        $router->post('/{uuid}/servers/test', 'ProjectController@serverTest');
        $router->post('/{uuid}/context/member-access', 'ProjectController@contextMemberAccess');
        // #3 — Ligações entre projetos
        $router->get('/{uuid}/links', 'ProjectController@projectLinks');
        $router->post('/{uuid}/links', 'ProjectController@linkSave');
        $router->post('/{uuid}/links/toggle', 'ProjectController@linkToggle');
        $router->post('/{uuid}/links/delete', 'ProjectController@linkDelete');
        // IDE no navegador (code-server por projeto)
        $router->post('/{uuid}/ide/start', 'ProjectController@ideStart');
        $router->post('/{uuid}/ide/stop', 'ProjectController@ideStop');
        $router->get('/{uuid}/ide/status', 'ProjectController@ideStatus');
        $router->post('/{uuid}/ide/theme', 'ProjectController@ideTheme');
        $router->get('/{uuid}/terminal', 'ProjectController@terminal');
        $router->put('/{uuid}', 'ProjectController@update');
        $router->delete('/{uuid}', 'ProjectController@destroy');
        $router->post('/{uuid}/archive', 'ProjectController@archive');
        $router->post('/{uuid}/restore', 'ProjectController@restore');
    });

    // Área de IDEs com abas (uma por projeto aberto). /ide/<token>/ é proxy do nginx (não bate aqui).
    $router->get('/ide', 'IdeWorkspaceController@index');
    $router->get('/ide/sessions', 'IdeWorkspaceController@sessions');
    $router->get('/ide/projects', 'IdeWorkspaceController@projects');
    $router->get('/ide/ready', 'IdeWorkspaceController@ready');
    $router->post('/ide/resume', 'IdeWorkspaceController@resume');
    $router->post('/ide/worktime', 'IdeWorkspaceController@worktime');

    $router->group(['prefix' => '/conversations'], function(Router $router) {
        // index/store exigem o projeto (ConversationController@index/@store recebem $projectUuid)
        $router->get('/project/{projectUuid}', 'ConversationController@index');
        $router->post('/project/{projectUuid}', 'ConversationController@store');
        $router->get('/{uuid}', 'ConversationController@show');
        $router->put('/{uuid}', 'ConversationController@update');
        $router->delete('/{uuid}', 'ConversationController@destroy');
        $router->post('/{uuid}/cancel', 'ConversationController@cancel');
        $router->post('/{uuid}/retry', 'ConversationController@retry');
        $router->get('/{uuid}/messages', 'ConversationController@messages');
        $router->get('/{uuid}/files', 'ConversationController@files');
    });

    $router->group(['prefix' => '/chat'], function(Router $router) {
        // Esquema project-based (as views linkam /chat/{projectUuid} e /chat/{projectUuid}/{conversationUuid})
        $router->post('/new', 'ChatController@startNew');
        $router->get('/{projectUuid}', 'ChatController@project');
        // Arquivos do workspace (devem vir ANTES da rota de conversa p/ casar o literal)
        $router->get('/{projectUuid}/files', 'ChatController@files');
        $router->get('/{projectUuid}/ls', 'ChatController@lsDir');
        $router->get('/{projectUuid}/download', 'ChatController@download');
        $router->get('/{projectUuid}/view', 'ChatController@viewFile');
        $router->post('/{projectUuid}/save', 'ChatController@saveFile');
        $router->post('/{projectUuid}/delete', 'ChatController@deleteFile');
        $router->post('/{projectUuid}/attach', 'ChatController@attach');
        $router->post('/{projectUuid}/upload-file', 'ChatController@uploadFile');
        // Preview ao vivo (abrir o sistema rodando num link)
        $router->post('/{projectUuid}/preview/start', 'ChatController@previewStart');
        $router->post('/{projectUuid}/preview/stop', 'ChatController@previewStop');
        $router->get('/{projectUuid}/preview/status', 'ChatController@previewStatus');
        // Build de APK Android sob demanda (apps mobile)
        $router->post('/{projectUuid}/apk/build', 'ChatController@apkBuild');
        $router->get('/{projectUuid}/apk/status', 'ChatController@apkStatus');
        // Expo Go (testar no celular real via QR)
        $router->post('/{projectUuid}/expo/start', 'ChatController@expoStart');
        $router->get('/{projectUuid}/expo/status', 'ChatController@expoStatus');
        $router->post('/{projectUuid}/expo/stop', 'ChatController@expoStop');
        $router->get('/{projectUuid}/maestro/status', 'ChatController@maestroStatus');
        $router->get('/{projectUuid}/live', 'ChatController@liveNarration');
        // QA Visual ao vivo: frame.jpg corrente do navegador + status (url/legenda) — polling leve.
        $router->get('/{projectUuid}/screencast', 'ChatController@screencast');
        $router->get('/{projectUuid}/screencast/status', 'ChatController@screencastStatus');
        $router->get('/{projectUuid}/{conversationUuid}', 'ChatController@conversation');
    });

    // Página de molduras do preview mobile (Android + iPhone), aberta a partir do chat.
    $router->get('/m/{token}', 'ChatController@mobileFrames');
    
    $router->group(['prefix' => '/files'], function(Router $router) {
        $router->get('/conversation/{uuid}', 'FileController@listByConversation');
        $router->get('/download/{uuid}', 'FileController@download');
        $router->get('/download-all/{conversationUuid}', 'FileController@downloadAll');
        $router->get('/preview/{uuid}', 'FileController@preview');
    });
    
    $router->group(['prefix' => '/whatsapp'], function(Router $router) {
        $router->get('/', 'WhatsAppController@index');
        $router->post('/connect', 'WhatsAppController@connect');
        $router->get('/status/{id}', 'WhatsAppController@status');
        $router->post('/disconnect', 'WhatsAppController@disconnect');
        $router->post('/phones', 'WhatsAppController@addPhone');
        $router->post('/phones/{id}/delete', 'WhatsAppController@deletePhone');
        $router->post('/preferences', 'WhatsAppController@savePreferences');
        $router->post('/test', 'WhatsAppController@test');
    });

    $router->group(['prefix' => '/settings'], function(Router $router) {
        $router->get('/', 'SettingsController@index');
        // Configuração de e-mail (SMTP) — admin (o controller faz o self-check de admin)
        $router->get('/email', 'SettingsController@email');
        $router->post('/email', 'SettingsController@updateEmail');
        $router->post('/email/test', 'SettingsController@testEmail');
        $router->get('/notifications', 'SettingsController@notifications');
        $router->post('/notifications', 'SettingsController@updateNotifications');
        // #2/#8 — seletores de custo + zerar contadores + visibilidade p/ membros (admin)
        $router->post('/cost-flag', 'SettingsController@updateCostFlag');
        $router->post('/cost-reset', 'SettingsController@resetCost');
        // Recursos (CPU/RAM) dos containers de Dev autônomo e IDE (admin)
        $router->post('/resources', 'SettingsController@updateResources');
        // Limiar de alerta de uso do servidor (disco/RAM) — admin
        $router->post('/resource-alerts', 'SettingsController@updateResourceAlerts');
        // Modelo de IA (Opus/Sonnet) p/ IDE + Dev Autônomo: padrão global (admin) + override por usuário
        $router->post('/model', 'SettingsController@updateModel');
        $router->post('/user-model', 'SettingsController@updateUserModel');
        // #3 — idioma do sistema (selecionar / baixar / subir novo)
        $router->post('/language', 'SettingsController@updateLanguage');
        $router->get('/language/download', 'SettingsController@downloadLanguage');
        $router->post('/language/upload', 'SettingsController@uploadLanguage');
        $router->get('/profile', 'SettingsController@profile');
        $router->put('/profile', 'SettingsController@updateProfile');
        // Conta do Claude escolhida pelo próprio usuário (só quando o admin liberou para ele)
        $router->post('/license', 'SettingsController@updateUserLicense');
        $router->get('/licenses/usage', 'SettingsController@licensesUsage');
        // #7 — foto de perfil, 2FA (TOTP) e gestão de sessões/dispositivos
        $router->post('/avatar', 'SettingsController@uploadAvatar');
        $router->post('/avatar/remove', 'SettingsController@removeAvatar');
        $router->post('/2fa/setup', 'SettingsController@setup2fa');
        $router->post('/2fa/enable', 'SettingsController@enable2fa');
        $router->post('/2fa/disable', 'SettingsController@disable2fa');
        $router->get('/sessions', 'SettingsController@sessions');
        $router->post('/sessions/revoke', 'SettingsController@revokeSession');
        $router->post('/sessions/trust', 'SettingsController@trustDevice');
        // GitHub (OAuth App por usuário)
        $router->get('/github', 'GitHubController@page');
        $router->get('/github/repos', 'GitHubController@repos');
        $router->get('/github/connect', 'GitHubController@connect');
        $router->get('/github/callback', 'GitHubController@callback');
        $router->post('/github/disconnect', 'GitHubController@disconnect');
        $router->put('/password', 'SettingsController@updatePassword');
        $router->get('/usage', 'SettingsController@usage');
        $router->delete('/account', 'SettingsController@deleteAccount');
    });

    // Rotas autenticadas dos módulos opcionais ('rotas_auth')
    \Ravi\Core\Modulos::rotasAuth($router);
});

// ============================================================================
// API ROUTES
// ============================================================================

$router->group(['prefix' => '/api', 'middleware' => ['auth', 'csrf']], function(Router $router) {
    $router->get('/projects', 'ApiController@listProjects');
    $router->post('/projects', 'ApiController@createProject');
    $router->get('/projects/{uuid}', 'ApiController@getProject');
    $router->put('/projects/{uuid}', 'ApiController@updateProject');
    $router->delete('/projects/{uuid}', 'ApiController@deleteProject');
    $router->post('/projects/{uuid}/archive', 'ApiController@archiveProject');
    $router->get('/projects/{projectUuid}/conversations', 'ApiController@listConversations');
    $router->post('/projects/{projectUuid}/conversations', 'ApiController@createConversation');
    $router->get('/conversations/{uuid}', 'ApiController@getConversation');
    $router->delete('/conversations/{uuid}', 'ApiController@deleteConversation');
    $router->post('/conversations/{uuid}/cancel', 'ApiController@cancelConversation');
    $router->get('/conversations/{uuid}/messages', 'ApiController@listMessages');
    $router->post('/conversations/{uuid}/messages', 'ApiController@sendMessage');
    $router->get('/conversations/{uuid}/files', 'ApiController@listFiles');
    $router->get('/files/{uuid}/download', 'ApiController@downloadFile');
    $router->get('/conversations/{uuid}/files/download-all', 'ApiController@downloadAllFiles');
    $router->get('/user/profile', 'ApiController@getProfile');
    $router->put('/user/profile', 'ApiController@updateProfile');
    $router->put('/user/password', 'ApiController@updatePassword');
    $router->put('/user/api-key', 'ApiController@updateApiKey');
    $router->get('/user/usage', 'ApiController@getUsage');
    $router->get('/stats/dashboard', 'ApiController@dashboardStats');
    $router->get('/stats/usage', 'ApiController@usageStats');
});

// ============================================================================
// WEBHOOK ROUTES
// ============================================================================

$router->group(['prefix' => '/webhook'], function(Router $router) {
    $router->post('/github', 'WebhookController@github');
});

// Webhooks do servidor Go de WhatsApp (sem auth/csrf). O Ravi Developer só ENVIA alertas,
// então mensagens recebidas/recibos são ignorados (no-op) — apenas evitam erro 404 no Go.
$router->group(['prefix' => '/webhooks'], function(Router $router) {
    $router->post('/whatsapp', function() { return new \Ravi\Core\JsonResponse(['ok' => true]); });
    $router->post('/whatsapp-status', function() { return new \Ravi\Core\JsonResponse(['ok' => true]); });
});

// ============================================================================
// ERROR ROUTES
// ============================================================================

$router->get('/404', function() {
    http_response_code(404);
    return view('errors/404');
});

$router->get('/500', function() {
    http_response_code(500);
    return view('errors/500');
});

$router->get('/403', function() {
    http_response_code(403);
    return view('errors/403');
});

// Rotas públicas dos módulos opcionais ('rotas'): sem auth, fora de qualquer grupo
\Ravi\Core\Modulos::rotas($router);

$router->fallback(function() {
    if (isAjaxRequest()) {
        http_response_code(404);
        header('Content-Type: application/json');
        return json_encode(['success' => false, 'error' => 'Route not found']);
    }
    http_response_code(404);
    return view('errors/404');
});