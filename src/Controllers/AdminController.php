<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\{Request, Response, JsonResponse, Session, Encryption};
use Ravi\Models\{User, Project, Conversation, UsageLedger, Setting};
use Ravi\Services\{EmailService, PasswordResetService, LicenseService, LicenseUsageService, ChatgptLicenseService};

/**
 * Área de administração (somente role=admin).
 * - Lista todos os usuários com o GASTO de cada um (tokens/custo real, do histórico/ledger).
 * - Permite o admin "entrar como" qualquer usuário (impersonação) e voltar.
 */
class AdminController extends Controller
{
    /** Lista de usuários + uso. */
    public function users(Request $request): Response
    {
        $this->requireAuth($request);

        $users = User::orderBy('id', 'ASC')->get();
        $usage = UsageLedger::summaryByUser(); // [user_id => {tokens, cost, ...}]

        $rows = [];
        $totalCost = 0.0; $totalTokens = 0;
        foreach ($users as $u) {
            $usg = $usage[(int) $u->id] ?? ['tokens' => 0, 'cost' => 0.0, 'input' => 0, 'output' => 0];
            $projects = Project::where('user_id', '=', $u->id)->where('status', '!=', 'deleted')->count();
            $rows[] = [
                'id'        => (int) $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'role'      => $u->role ?? 'user',
                'is_active' => (bool) $u->is_active,
                'approval'  => $u->approval_status ?? 'approved',
                'projects'  => $projects,
                'tokens'    => (int) $usg['tokens'],
                'cost'      => (float) $usg['cost'],
                'license_choice' => LicenseService::selfChoiceEnabled((int) $u->id),
            ];
            $totalCost += (float) $usg['cost'];
            $totalTokens += (int) $usg['tokens'];
        }

        return $this->view('admin/users', [
            'title'        => 'Usuários',
            'rows'         => $rows,
            'total_cost'   => $totalCost,
            'total_tokens' => $totalTokens,
            'currentId'    => (int) ($this->user->id ?? 0),
            'impersonating'=> Session::get('impersonator'),
            'smtpConfigured' => (new EmailService())->isConfigured(),
        ]);
    }

    /** Tela de monitoramento do servidor (host + containers), atualizada a cada 1s via polling. */
    public function monitoring(Request $request): Response
    {
        $this->requireAuth($request);
        return $this->view('admin/monitoring', ['title' => 'Monitoramento']);
    }

    /** Dados do monitoramento em JSON (chamado a cada 1s pelo front). */
    public function monitoringData(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        try {
            return $this->json((new \Ravi\Services\MonitoringService())->sample());
        } catch (\Throwable $e) {
            return $this->jsonError('Falha ao coletar métricas: ' . $e->getMessage(), 500);
        }
    }

    /** Encerra (remove) um container Docker. Só admin; bloqueia os essenciais. */
    public function killContainer(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $name = trim((string) $request->input('container', ''));
        if ($name === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $name)) {
            return $this->jsonError('Container inválido.', 400);
        }
        if (\Ravi\Services\MonitoringService::isProtected($name)) {
            return $this->jsonError('Esse container é essencial e não pode ser encerrado por aqui.', 403);
        }
        // Só encerra o que está realmente em execução (evita comandos contra nomes inexistentes).
        // `timeout` em TODO docker: sob disco cheio / container congelado o comando pendura, e sem
        // timeout ele esgotaria os workers do php-fpm (mesma lição do incidente do 504).
        $running = [];
        @exec('timeout 15 docker ps --format ' . escapeshellarg('{{.Names}}') . ' 2>/dev/null', $running);
        if (!in_array($name, array_map('trim', $running), true)) {
            return $this->jsonError('Container não está em execução.', 404);
        }
        $out = []; $rc = 0;
        @exec('timeout 60 docker rm -f ' . escapeshellarg($name) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            return $this->jsonError('Falha ao encerrar (o comando pode ter estourado o tempo): ' . implode(' ', $out), 500);
        }
        // Best-effort: marca a sessão correspondente como parada (IDE/preview).
        try {
            $db = \Ravi\Core\Database::getInstance();
            $db->query("UPDATE ide_sessions SET status = 'stopped' WHERE container = ?", [$name]);
            $db->query("UPDATE previews SET status = 'stopped' WHERE container_name = ?", [$name]);
        } catch (\Throwable $e) {}
        return $this->json(['ok' => true, 'container' => $name], 'Container encerrado.');
    }

    /**
     * Reinicia um container (docker restart) sem removê-lo: mata processos travados/congelados e sobe
     * o serviço limpo, preservando volume/porta/token. Ideal para IDE presa (ex.: navegador fantasma).
     * Só admin; bloqueia os essenciais; `timeout` em todo docker para não pendurar o php-fpm.
     */
    public function restartContainer(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $name = trim((string) $request->input('container', ''));
        if ($name === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $name)) {
            return $this->jsonError('Container inválido.', 400);
        }
        if (\Ravi\Services\MonitoringService::isProtected($name)) {
            return $this->jsonError('Esse container é essencial e não pode ser reiniciado por aqui.', 403);
        }
        // Existe (rodando, pausado ou parado)?
        $all = [];
        @exec('timeout 15 docker ps -a --format ' . escapeshellarg('{{.Names}}') . ' 2>/dev/null', $all);
        if (!in_array($name, array_map('trim', $all), true)) {
            return $this->jsonError('Container não encontrado.', 404);
        }
        $out = []; $rc = 0;
        @exec('timeout 90 docker restart -t 5 ' . escapeshellarg($name) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            return $this->jsonError('Falha ao reiniciar (o comando pode ter estourado o tempo): ' . implode(' ', $out), 500);
        }
        // Se era uma IDE em standby (paused → status 'stopped' no DB), volta a 'running'.
        try {
            \Ravi\Core\Database::getInstance()->query(
                "UPDATE ide_sessions SET status = 'running' WHERE container = ? AND status = 'stopped'",
                [$name]
            );
        } catch (\Throwable $e) {}
        return $this->json(['ok' => true, 'container' => $name], 'Container reiniciado.');
    }

    /** Inicia a impersonação: passa a agir como o usuário-alvo, guardando o admin. */
    public function impersonate(Request $request, string $id): Response
    {
        $this->requireAuth($request);

        $target = User::find((int) $id);
        if (!$target) {
            Session::flash('error', 'Usuário não encontrado.');
            return $this->redirect('/admin/users');
        }

        // Já impersonando? Mantém o admin original (não empilha).
        if (!Session::has('impersonator')) {
            Session::set('impersonator', Session::get('user'));
        }
        Session::set('user', $target->toSessionData());

        return $this->redirect('/dashboard');
    }

    /** Encerra a impersonação e volta para a conta do admin. */
    public function stopImpersonation(Request $request): Response
    {
        $admin = Session::get('impersonator');
        if ($admin) {
            Session::set('user', $admin);
            Session::remove('impersonator');
        }
        return $this->redirect('/admin/users');
    }

    // ========================================================================
    // GESTÃO DE USUÁRIOS (criar / editar papel / ativar-desativar / senha / excluir)
    // ========================================================================

    /** Cria um novo usuário da equipe. */
    public function createUser(Request $request): Response
    {
        $this->requireAuth($request);

        $name     = trim((string) $request->post('name'));
        $email    = strtolower(trim((string) $request->post('email')));
        $password = (string) $request->post('password');
        $role     = $request->post('role') === 'admin' ? 'admin' : 'user';
        $mode     = $request->post('onboard') === 'invite' ? 'invite' : 'password';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->redirectWithError('/admin/users', 'Preencha nome e um e-mail válido.');
        }
        if ($mode === 'password' && strlen($password) < 8) {
            return $this->redirectWithError('/admin/users', 'A senha inicial deve ter no mínimo 8 caracteres.');
        }
        if (User::findByEmail($email) !== null) {
            return $this->redirectWithError('/admin/users', 'Já existe um usuário com esse e-mail.');
        }

        // No modo convite, cria com uma senha aleatória (a pessoa define a sua pelo link).
        $initialPassword = $mode === 'invite' ? bin2hex(random_bytes(9)) : $password;

        $user = User::register($name, $email, $initialPassword);
        if (!$user) {
            return $this->redirectWithError('/admin/users', 'Falha ao criar o usuário.');
        }
        if ($role === 'admin') {
            $user->update(['role' => 'admin']);
        }

        // Avisa os outros admins (respeita o toggle de cada um + SMTP).
        try {
            (new \Ravi\Services\NotificationService())->userCreated($user, (int) ($this->user->id ?? 0));
        } catch (\Throwable $e) {}

        $msg = "Usuário {$name} criado.";

        if ($mode === 'invite') {
            $res = (new PasswordResetService())->sendLink($email, true, 72);
            $msg .= !empty($res['ok'])
                ? ' Convite enviado por e-mail.'
                : ' (Conta criada, mas o convite NÃO foi enviado: ' . ($res['error'] ?? 'erro') . '. Configure o SMTP ou defina a senha manualmente.)';
        } elseif ($request->post('send_email')) {
            // E-mail de boas-vindas opcional (a senha é passada pelo admin).
            $mail = new EmailService();
            if ($mail->isConfigured()) {
                $ok = $mail->sendWelcome($email, $name);
                $msg .= $ok ? ' E-mail de boas-vindas enviado.' : ' (Não foi possível enviar o e-mail: ' . $mail->getLastError() . ')';
            } else {
                $msg .= ' (E-mail não enviado: SMTP não configurado.)';
            }
        }

        return $this->redirectWithSuccess('/admin/users', $msg);
    }

    /** Edita nome, e-mail e papel de um usuário. */
    public function updateUser(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $target = User::find((int) $id);
        if (!$target) {
            return $this->redirectWithError('/admin/users', 'Usuário não encontrado.');
        }

        $name  = trim((string) $request->post('name'));
        $email = strtolower(trim((string) $request->post('email')));
        $role  = $request->post('role') === 'admin' ? 'admin' : 'user';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->redirectWithError('/admin/users', 'Nome e e-mail válido são obrigatórios.');
        }
        $existing = User::findByEmail($email);
        if ($existing && (int) $existing->id !== (int) $target->id) {
            return $this->redirectWithError('/admin/users', 'Esse e-mail já pertence a outro usuário.');
        }

        // Salvaguarda: não rebaixar o último admin nem a si mesmo para 'user'.
        if (($target->role ?? 'user') === 'admin' && $role !== 'admin') {
            if ((int) $target->id === (int) $this->user->id) {
                return $this->redirectWithError('/admin/users', 'Você não pode remover o seu próprio acesso de administrador.');
            }
            if ($this->isLastAdmin((int) $target->id)) {
                return $this->redirectWithError('/admin/users', 'Não é possível rebaixar o último administrador.');
            }
        }

        $target->update(['name' => $name, 'email' => $email, 'role' => $role]);
        return $this->redirectWithSuccess('/admin/users', 'Usuário atualizado.');
    }

    /** Define uma nova senha para o usuário. */
    public function setUserPassword(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $target = User::find((int) $id);
        if (!$target) {
            return $this->redirectWithError('/admin/users', 'Usuário não encontrado.');
        }
        $password = (string) $request->post('password');
        if (strlen($password) < 8) {
            return $this->redirectWithError('/admin/users', 'A nova senha deve ter no mínimo 8 caracteres.');
        }
        $target->updatePassword($password);
        return $this->redirectWithSuccess('/admin/users', "Senha de {$target->name} redefinida.");
    }

    /** Ativa ou desativa a conta (bloqueia login quando inativa). */
    public function toggleUser(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $target = User::find((int) $id);
        if (!$target) {
            return $this->redirectWithError('/admin/users', 'Usuário não encontrado.');
        }
        if ((int) $target->id === (int) $this->user->id) {
            return $this->redirectWithError('/admin/users', 'Você não pode desativar a sua própria conta.');
        }
        $newActive = !((bool) $target->is_active);
        if (!$newActive && ($target->role ?? 'user') === 'admin' && $this->isLastAdmin((int) $target->id)) {
            return $this->redirectWithError('/admin/users', 'Não é possível desativar o último administrador.');
        }
        $target->update(['is_active' => $newActive ? 1 : 0]);
        return $this->redirectWithSuccess('/admin/users', $newActive ? "{$target->name} reativado." : "{$target->name} desativado.");
    }

    /**
     * Liga/desliga a escolha da própria conta do Claude pelo usuário (interruptor da tabela). Com
     * ela ligada, o perfil do usuário mostra as contas disponíveis (com o uso de cada uma) para
     * ele selecionar; desligada, ele segue a atribuição feita pelo admin. AJAX.
     */
    public function toggleUserLicenseChoice(Request $request, string $id): JsonResponse
    {
        $this->requireAuth($request);
        $target = User::find((int) $id);
        if (!$target) {
            return $this->jsonError('Usuário não encontrado.', 404);
        }
        $on = (string) $request->input('enabled', '') === '1';
        LicenseService::setSelfChoice((int) $target->id, $on);
        return $this->json(['user_id' => (int) $target->id, 'enabled' => $on], $on
            ? "{$target->name} agora pode escolher a conta do Claude no próprio perfil."
            : "{$target->name} volta a usar a conta definida por você.");
    }

    /** Exclui o usuário (cascata remove projetos/conversas próprios). */
    public function deleteUser(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $target = User::find((int) $id);
        if (!$target) {
            return $this->redirectWithError('/admin/users', 'Usuário não encontrado.');
        }
        if ((int) $target->id === (int) $this->user->id) {
            return $this->redirectWithError('/admin/users', 'Você não pode excluir a sua própria conta.');
        }
        if (($target->role ?? 'user') === 'admin' && $this->isLastAdmin((int) $target->id)) {
            return $this->redirectWithError('/admin/users', 'Não é possível excluir o último administrador.');
        }
        $name = $target->name;
        $target->delete();
        return $this->redirectWithSuccess('/admin/users', "Usuário {$name} excluído.");
    }

    /** Aprova um cadastro pendente: ativa a conta e avisa o usuário por e-mail. */
    public function approveUser(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $target = User::find((int) $id);
        if (!$target) {
            return $this->redirectWithError('/admin/users', 'Usuário não encontrado.');
        }
        $target->update(['approval_status' => 'approved', 'is_active' => 1]);
        try { (new \Ravi\Services\NotificationService())->approvalGranted($target); } catch (\Throwable $e) {}
        return $this->redirectWithSuccess('/admin/users', "Cadastro de {$target->name} aprovado.");
    }

    /** Rejeita (e remove) um cadastro pendente. */
    public function rejectUser(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $target = User::find((int) $id);
        if (!$target) {
            return $this->redirectWithError('/admin/users', 'Usuário não encontrado.');
        }
        if (($target->approval_status ?? 'approved') !== 'pending') {
            return $this->redirectWithError('/admin/users', 'Só é possível rejeitar cadastros pendentes.');
        }
        $name = $target->name;
        $target->delete();
        return $this->redirectWithSuccess('/admin/users', "Cadastro de {$name} rejeitado e removido.");
    }

    /** Há outro admin ativo além de $exceptId? Se não, $exceptId é o último admin. */
    private function isLastAdmin(int $exceptId): bool
    {
        $others = User::where('role', '=', 'admin')
            ->where('is_active', '=', 1)
            ->where('id', '!=', $exceptId)
            ->count();
        return $others === 0;
    }

    // ========================================================================
    // CONFIGURAÇÃO DE SMTP (e-mail) — MOVIDA para Configurações (SettingsController).
    // Mantém só um redirect para não quebrar links/bookmarks antigos de /admin/smtp.
    // ========================================================================

    /** Compat: /admin/smtp agora vive em /settings/email. */
    public function smtp(Request $request): Response
    {
        return $this->redirect('/settings/email');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GATEWAY DE API (compatível com a Anthropic) — gestão de tokens + consumo.
    // Isolado: lê/grava só as tabelas gw_*. Painel só do admin.
    // ──────────────────────────────────────────────────────────────────────────

    /** Tarifa por modelo em USD por 1M tokens (para ESTIMAR custo na exibição). */
    private const GW_PRICING = [
        'opus'     => ['in' => 15.0, 'out' => 75.0],
        'sonnet'   => ['in' => 3.0,  'out' => 15.0],
        'haiku'    => ['in' => 0.80, 'out' => 4.0],
        // DeepSeek V4.1 Flash em horário de pico (fora de pico é a metade): aqui é
        // ESTIMATIVA de exibição; o custo real é o da fatura da DeepSeek.
        'deepseek' => ['in' => 0.30, 'out' => 1.20],
        // GPT via Codex sai da COTA do plano ChatGPT (custo zero por token); a tarifa
        // abaixo é só a ESTIMATIVA do que custaria na API da OpenAI.
        'gpt'      => ['in' => 1.25, 'out' => 10.0],
    ];

    private function gwCost(?string $model, int $in, int $out): float
    {
        $m = strtolower((string) $model);
        $p = self::GW_PRICING['sonnet'];
        if (str_contains($m, 'opus'))  $p = self::GW_PRICING['opus'];
        elseif (str_contains($m, 'haiku')) $p = self::GW_PRICING['haiku'];
        elseif (str_contains($m, 'deepseek')) $p = self::GW_PRICING['deepseek'];
        elseif (str_starts_with($m, 'gpt')) $p = self::GW_PRICING['gpt'];
        return ($in * $p['in'] + $out * $p['out']) / 1_000_000;
    }

    /** Painel do gateway: tokens + dashboards de consumo (hoje / 7 dias / 30 dias). */
    public function apiGateway(Request $request): Response
    {
        $this->requireAuth($request);
        $db = \Ravi\Core\Database::getInstance();

        // backend pode não existir antes da migração 035: cai para a lista sem a coluna
        try {
            $tokens = $db->fetchAll(
                "SELECT id, name, token_prefix, is_active, license_id, log_server_identity, allowed_models, backend, last_used_at, revoked_at, created_at
                 FROM gw_tokens ORDER BY id DESC"
            );
        } catch (\Throwable $e) {
            $tokens = $db->fetchAll(
                "SELECT id, name, token_prefix, is_active, license_id, log_server_identity, allowed_models, NULL AS backend, last_used_at, revoked_at, created_at
                 FROM gw_tokens ORDER BY id DESC"
            );
        }

        // Campos personalizados por token (total e obrigatórios) para exibir na lista.
        $fieldCounts = [];
        foreach ($db->fetchAll("SELECT token_id, COUNT(*) AS n, SUM(required) AS req FROM gw_token_fields GROUP BY token_id") as $fc) {
            $fieldCounts[(int) $fc['token_id']] = ['n' => (int) $fc['n'], 'req' => (int) $fc['req']];
        }

        // Agrega consumo por janela de tempo. Custo é calculado por modelo na exibição.
        $windows = ['today' => '1 DAY', 'week' => '7 DAY', 'month' => '30 DAY'];
        // Estrutura: $usage[period] = ['global'=>..., 'by_token'=>[id=>...]]
        $usage = [];
        foreach ($windows as $key => $interval) {
            $since = $key === 'today' ? date('Y-m-d 00:00:00') : date('Y-m-d H:i:s', strtotime('-' . $interval));
            $rows = $db->fetchAll(
                "SELECT token_id, model_served, backend,
                        SUM(input_tokens) AS in_tok, SUM(output_tokens) AS out_tok,
                        SUM(prompt_tokens) AS prompt_tok,
                        COUNT(*) AS calls,
                        SUM(CASE WHEN backend='max' THEN 1 ELSE 0 END) AS max_calls,
                        SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) AS errors
                 FROM gw_requests WHERE created_at >= ? GROUP BY token_id, model_served, backend",
                [$since]
            );
            $global = ['in' => 0, 'out' => 0, 'calls' => 0, 'max_calls' => 0, 'errors' => 0, 'cost' => 0.0];
            $byToken = [];
            foreach ($rows as $r) {
                $tid = (int) ($r['token_id'] ?? 0);
                $in = (int) $r['in_tok']; $out = (int) $r['out_tok'];
                // Custo estimado considera só o PROMPT REAL do cliente (exclui o
                // scaffolding cacheado do Claude Code, que não existe na API oficial).
                $cost = $this->gwCost($r['model_served'] ?? null, (int) $r['prompt_tok'], $out);
                if (!isset($byToken[$tid])) {
                    $byToken[$tid] = ['in' => 0, 'out' => 0, 'calls' => 0, 'max_calls' => 0, 'errors' => 0, 'cost' => 0.0];
                }
                foreach (['in' => $in, 'out' => $out, 'calls' => (int) $r['calls'], 'max_calls' => (int) $r['max_calls'], 'errors' => (int) $r['errors']] as $k => $v) {
                    $byToken[$tid][$k] += $v; $global[$k] += $v;
                }
                $byToken[$tid]['cost'] += $cost; $global['cost'] += $cost;
            }
            $usage[$key] = ['global' => $global, 'by_token' => $byToken];
        }

        // Série diária dos últimos 30 dias (gráfico de consumo): chamadas, custo estimado e erros.
        $serieDia = [];
        for ($i = 29; $i >= 0; $i--) {
            $dia = date('Y-m-d', strtotime("-{$i} day"));
            $serieDia[$dia] = ['dia' => $dia, 'chamadas' => 0, 'custo' => 0.0, 'erros' => 0];
        }
        $linhasDia = $db->fetchAll(
            "SELECT DATE(created_at) AS dia, model_served,
                    SUM(prompt_tokens) AS prompt_tok, SUM(output_tokens) AS out_tok,
                    COUNT(*) AS calls, SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) AS errors
             FROM gw_requests WHERE created_at >= ? GROUP BY DATE(created_at), model_served",
            [date('Y-m-d 00:00:00', strtotime('-29 day'))]
        );
        foreach ($linhasDia as $r) {
            $dia = (string) $r['dia'];
            if (!isset($serieDia[$dia])) continue;
            $serieDia[$dia]['chamadas'] += (int) $r['calls'];
            $serieDia[$dia]['erros']    += (int) $r['errors'];
            $serieDia[$dia]['custo']    += $this->gwCost($r['model_served'] ?? null, (int) $r['prompt_tok'], (int) $r['out_tok']);
        }

        $newToken = Session::get('gw_new_token');
        if ($newToken !== null) {
            Session::remove('gw_new_token'); // mostrado só uma vez
        }

        $enderecoGateway = \Ravi\Gateway\GatewayService::urlPublicaComOrigem();

        return $this->view('admin/api', [
            'serie_dia'    => array_values($serieDia),
            'gateway_url'  => $enderecoGateway['url'],
            'gateway_url_origem' => $enderecoGateway['origem'],
            'gateway_url_painel' => (string) Setting::get(\Ravi\Gateway\GatewayService::CHAVE_URL_PUBLICA, ''),
            'title'        => 'Gateway de API',
            'tokens'       => $tokens,
            'usage'        => $usage,
            'new_token'    => $newToken,
            'licenses'     => LicenseService::all(),
            'field_counts' => $fieldCounts,
            'deepseek_configured' => \Ravi\Gateway\GatewayDeepSeekService::configured(),
            'chatgpt_ativas' => ChatgptLicenseService::activeCount(),
        ]);
    }

    /** Grava o endereço público do Gateway (vazio volta ao padrão da instalação). */
    public function salvarEnderecoGateway(Request $request): Response
    {
        $this->requireAuth($request);
        $validado = \Ravi\Gateway\GatewayService::validarUrlPublica((string) $request->post('url'));
        $querJson = $request->isAjax() || $request->expectsJson();
        if (!$validado['ok']) {
            return $querJson ? $this->jsonError($validado['erro'], 422) : $this->redirectWithError('/admin/api', $validado['erro']);
        }
        Setting::set(\Ravi\Gateway\GatewayService::CHAVE_URL_PUBLICA, $validado['url']);
        $efetivo = \Ravi\Gateway\GatewayService::urlPublicaComOrigem();
        $msg = $validado['url'] === '' ? 'Endereço do Gateway voltou ao padrão da instalação.' : 'Endereço do Gateway salvo.';
        return $querJson
            ? $this->json(['url' => $efetivo['url'], 'origem' => $efetivo['origem'], 'painel' => $validado['url']], $msg)
            : $this->redirectWithSuccess('/admin/api', $msg);
    }

    /** Testa se o endereço público do Gateway responde (GET <url>/v1/health, timeout curto). */
    public function verificarEnderecoGateway(Request $request): Response
    {
        $this->requireAuth($request);
        $url = \Ravi\Gateway\GatewayService::urlPublica();
        $informado = trim((string) $request->query('url'));
        if ($informado !== '') {
            $validado = \Ravi\Gateway\GatewayService::validarUrlPublica($informado);
            if (!$validado['ok']) {
                return $this->jsonError($validado['erro'], 422);
            }
            $url = $validado['url'];
        }
        $ch = curl_init($url . '/v1/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $inicio = microtime(true);
        $corpo = curl_exec($ch);
        $erroNum = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $inicio) * 1000);

        if ($erroNum !== 0) {
            $msg = match (true) {
                $erroNum === 6 => 'O domínio ainda não aponta para nenhum servidor. Confira o DNS (passo 1) e aguarde a propagação.',
                in_array($erroNum, [35, 51, 58, 60, 77, 83], true)
                    => 'O certificado deste domínio não é válido ainda. Gere o certificado (passo 2) e tente de novo.',
                $erroNum === 28 => 'O servidor não respondeu a tempo. Confira se o domínio aponta para o servidor certo.',
                $erroNum === 7 => 'O servidor recusou a conexão. Confira se o site do Gateway foi publicado (passo 2).',
                default => 'Não foi possível falar com o Gateway neste endereço.',
            };
            return $this->jsonError($msg, 422);
        }
        $dados = json_decode((string) $corpo, true);
        if ($status === 200 && is_array($dados) && ($dados['status'] ?? '') === 'ok') {
            return $this->json(['url' => $url, 'ms' => $ms], 'Gateway respondendo em ' . $url . ' (' . $ms . ' ms).');
        }
        return $this->jsonError($status === 404
            ? 'O endereço responde, mas não é o Gateway. Confira se o site do Gateway foi publicado para este domínio (passo 2).'
            : 'O endereço respondeu de forma inesperada (código ' . $status . '). Confira a publicação (passo 2).', 422);
    }

    /** Grava (ou remove, se vazio) a chave da API DeepSeek — cifrada em repouso. */
    public function saveDeepseekKey(Request $request): Response
    {
        $this->requireAuth($request);
        $key = trim((string) $request->post('key'));
        \Ravi\Gateway\GatewayDeepSeekService::saveApiKey($key);
        return $this->redirectWithSuccess('/admin/api', $key === ''
            ? 'Chave da DeepSeek removida. Tokens com regra DeepSeek passarão a falhar com erro claro.'
            : 'Chave da DeepSeek salva. Ela só entra em ação nos tokens com regra de backend DeepSeek.');
    }

    /** Chamada real de teste ao backend DeepSeek (AJAX): prova chave, rota e latência. */
    public function testDeepseek(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        if (!\Ravi\Gateway\GatewayDeepSeekService::configured()) {
            return $this->jsonError('Cadastre a chave da DeepSeek primeiro.', 400);
        }
        $t0 = microtime(true);
        $r = (new \Ravi\Gateway\GatewayDeepSeekService())->forward([
            'model'      => 'deepseek-flash',
            'max_tokens' => 30,
            'thinking'   => ['type' => 'disabled'],
            'messages'   => [['role' => 'user', 'content' => 'Responda apenas: ok']],
        ], 30);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if (!$r['ok']) {
            return $this->jsonError('Teste falhou: ' . (string) $r['error'], 502);
        }
        $texto = '';
        foreach (($r['json']['content'] ?? []) as $blk) {
            if (($blk['type'] ?? '') === 'text') $texto .= (string) ($blk['text'] ?? '');
        }
        return $this->json([
            'modelo'  => (string) ($r['json']['model'] ?? 'deepseek'),
            'ms'      => $ms,
            'resposta' => mb_substr(trim($texto), 0, 80),
        ], 'DeepSeek respondeu em ' . $ms . ' ms.');
    }

    /** Define o backend de execução de um token (Max padrão / DeepSeek). AJAX. */
    public function setApiTokenBackend(Request $request, string $id): JsonResponse
    {
        $this->requireAuth($request);
        $tid = (int) $id;
        $db  = \Ravi\Core\Database::getInstance();
        if (!$db->fetch("SELECT id FROM gw_tokens WHERE id = ?", [$tid])) {
            return $this->jsonError('Token não encontrado.', 404);
        }
        $backend = trim((string) $request->input('backend', ''));
        if (!in_array($backend, ['', 'deepseek_haiku', 'deepseek', 'max_deepseek'], true)) {
            return $this->jsonError('Backend inválido.', 400);
        }
        if (in_array($backend, ['deepseek_haiku', 'deepseek'], true) && !\Ravi\Gateway\GatewayDeepSeekService::configured()) {
            return $this->jsonError('Cadastre a chave da DeepSeek antes de ligar a regra.', 400);
        }
        $db->update('gw_tokens', ['backend' => $backend === '' ? null : $backend], 'id = :_id', ['_id' => $tid]);
        $rotulos = ['' => 'Max (padrão)', 'deepseek_haiku' => 'DeepSeek na família haiku', 'deepseek' => 'DeepSeek em tudo', 'max_deepseek' => 'Max até nos deepseek-*'];
        return $this->json(['token_id' => $tid, 'backend' => $backend], 'Backend do token: ' . $rotulos[$backend] . '.');
    }

    /**
     * Análise anti-fraude de um token: o que está chegando de requisição.
     * Feito para o token VAZADO (honeypot): após migrar a frota para o token novo,
     * tudo que continua batendo aqui é suspeito. Destaca chamadas SEM identidade
     * (usaram o token cru, fora do proxy oficial) e identidades espalhadas por
     * vários IPs de origem / conjuntos de IPs (token copiado para outras máquinas).
     */
    public function apiGatewaySuspicious(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $db  = \Ravi\Core\Database::getInstance();
        $tid = (int) $id;
        $token = $db->fetch("SELECT id, name, token_prefix, is_active, revoked_at FROM gw_tokens WHERE id = ?", [$tid]);
        if (!$token) {
            return $this->redirectWithError('/admin/api', 'Token não encontrado.');
        }

        $resumo = $db->fetch(
            "SELECT COUNT(*) total,
                    SUM(CASE WHEN server_token IS NULL OR server_token='' THEN 1 ELSE 0 END) sem_ident,
                    COUNT(DISTINCT NULLIF(client_ip,'')) origens,
                    COUNT(DISTINCT NULLIF(server_token,'')) identidades
             FROM gw_requests WHERE token_id = ?",
            [$tid]
        ) ?: ['total' => 0, 'sem_ident' => 0, 'origens' => 0, 'identidades' => 0];

        // Chamadas SEM identidade (não passaram pelo proxy oficial) — por IP de origem.
        $semIdent = $db->fetchAll(
            "SELECT COALESCE(NULLIF(client_ip,''),'—') ip, COUNT(*) n, MAX(created_at) ultima
             FROM gw_requests
             WHERE token_id = ? AND (server_token IS NULL OR server_token='')
             GROUP BY ip ORDER BY n DESC LIMIT 100",
            [$tid]
        );

        // Por identidade (tokenravi): quantos IPs de origem e conjuntos de IPs declarados.
        $porIdent = $db->fetchAll(
            "SELECT server_token,
                    COUNT(*) n,
                    COUNT(DISTINCT client_ip) origens,
                    COUNT(DISTINCT server_ips) confs,
                    GROUP_CONCAT(DISTINCT COALESCE(NULLIF(client_ip,''),'—') ORDER BY client_ip SEPARATOR ', ') ips_origem,
                    GROUP_CONCAT(DISTINCT COALESCE(NULLIF(server_ips,''),'—') SEPARATOR ' | ') ips_decl,
                    MAX(created_at) ultima
             FROM gw_requests
             WHERE token_id = ? AND server_token IS NOT NULL AND server_token <> ''
             GROUP BY server_token
             ORDER BY (COUNT(DISTINCT client_ip) > 1 OR COUNT(DISTINCT server_ips) > 1) DESC, n DESC
             LIMIT 200",
            [$tid]
        );

        return $this->view('admin/api-suspicious', [
            'title'     => 'Análise: ' . ($token['name'] ?? 'Token'),
            'token'     => $token,
            'resumo'    => $resumo,
            'sem_ident' => $semIdent,
            'por_ident' => $porIdent,
        ]);
    }

    /** Página de seleção de modelos permitidos de um token. */
    public function apiGatewayModels(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $db  = \Ravi\Core\Database::getInstance();
        $tid = (int) $id;
        try {
            $token = $db->fetch("SELECT id, name, token_prefix, is_active, revoked_at, allowed_models, backend FROM gw_tokens WHERE id = ?", [$tid]);
        } catch (\Throwable $e) {
            $token = $db->fetch("SELECT id, name, token_prefix, is_active, revoked_at, allowed_models, NULL AS backend FROM gw_tokens WHERE id = ?", [$tid]);
        }
        if (!$token) {
            return $this->redirectWithError('/admin/api', 'Token não encontrado.');
        }
        $catalog = (new \Ravi\Gateway\GatewayService())->modelCatalog();
        $allowed = json_decode((string) ($token['allowed_models'] ?? ''), true);
        $allowed = is_array($allowed) ? $allowed : null; // null = sem restrição
        return $this->view('admin/api-models', [
            'title'   => 'Modelos e execução: ' . ($token['name'] ?? 'Token'),
            'token'   => $token,
            'catalog' => $catalog,
            'allowed' => $allowed,
            'deepseek_configured' => \Ravi\Gateway\GatewayDeepSeekService::configured(),
            'chatgpt_ativas' => ChatgptLicenseService::activeCount(),
        ]);
    }

    /** Salva os modelos permitidos de um token (vazio/sem restrição = todos). */
    public function saveApiTokenModels(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $db  = \Ravi\Core\Database::getInstance();
        $tid = (int) $id;
        if (!$db->fetch("SELECT id FROM gw_tokens WHERE id = ?", [$tid])) {
            return $this->redirectWithError('/admin/api', 'Token não encontrado.');
        }
        $restringir = filter_var($request->post('restringir', false), FILTER_VALIDATE_BOOLEAN);
        $value = null; // null = sem restrição (todos)
        if ($restringir) {
            $catalogIds = array_column((new \Ravi\Gateway\GatewayService())->modelCatalog(), 'id');
            $sel = (array) $request->post('models', []);
            $sel = array_values(array_filter($catalogIds, static fn($m) => in_array($m, $sel, true)));
            if ($sel === []) {
                return $this->redirectWithError('/admin/api/tokens/' . $tid . '/models',
                    'Selecione ao menos um modelo, ou desmarque "restringir" para liberar todos.');
            }
            $value = json_encode($sel, JSON_UNESCAPED_SLASHES);
        }
        $db->update('gw_tokens', ['allowed_models' => $value], 'id = :_id', ['_id' => $tid]);
        return $this->redirectWithSuccess('/admin/api/tokens/' . $tid . '/models', 'Modelos permitidos salvos.');
    }

    /** Página de campos de identidade personalizados de um token. */
    public function apiGatewayFields(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $db  = \Ravi\Core\Database::getInstance();
        $tid = (int) $id;
        $token = $db->fetch("SELECT id, name, token_prefix, is_active, revoked_at FROM gw_tokens WHERE id = ?", [$tid]);
        if (!$token) {
            return $this->redirectWithError('/admin/api', 'Token não encontrado.');
        }
        $fields = $db->fetchAll(
            "SELECT id, field_key, label, required, sort_order FROM gw_token_fields WHERE token_id = ? ORDER BY sort_order, id",
            [$tid]
        );
        return $this->view('admin/api-fields', [
            'title'  => 'Campos: ' . ($token['name'] ?? 'Token'),
            'token'  => $token,
            'fields' => $fields,
        ]);
    }

    /** Adiciona um campo de identidade ao token (chave + rótulo + obrigatório). */
    public function addApiTokenField(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $db  = \Ravi\Core\Database::getInstance();
        $tid = (int) $id;
        if (!$db->fetch("SELECT id FROM gw_tokens WHERE id = ?", [$tid])) {
            return $this->redirectWithError('/admin/api', 'Token não encontrado.');
        }
        // Normaliza a chave: minúsculas, snake_case, só [a-z0-9_].
        $key = strtolower(trim((string) $request->post('field_key')));
        $key = preg_replace('/[^a-z0-9_]+/', '_', str_replace('-', '_', $key));
        $key = trim((string) $key, '_');
        $label = trim((string) $request->post('label'));
        $required = (int) filter_var($request->post('required', 0), FILTER_VALIDATE_BOOLEAN);
        if ($key === '' || mb_strlen($key) > 64) {
            return $this->redirectWithError('/admin/api/tokens/' . $tid . '/fields', 'Informe uma chave válida (ex.: server_token).');
        }
        if ($label === '') { $label = $key; }
        if ($db->fetch("SELECT id FROM gw_token_fields WHERE token_id = ? AND field_key = ?", [$tid, $key])) {
            return $this->redirectWithError('/admin/api/tokens/' . $tid . '/fields', 'Esse campo já existe neste token.');
        }
        $ord = (int) ($db->fetch("SELECT COALESCE(MAX(sort_order),0)+1 AS o FROM gw_token_fields WHERE token_id = ?", [$tid])['o'] ?? 1);
        $db->insert('gw_token_fields', [
            'token_id' => $tid, 'field_key' => mb_substr($key, 0, 64),
            'label' => mb_substr($label, 0, 120), 'required' => $required, 'sort_order' => $ord,
        ]);
        return $this->redirectWithSuccess('/admin/api/tokens/' . $tid . '/fields', 'Campo adicionado.');
    }

    /** Liga/desliga a obrigatoriedade de um campo. AJAX. */
    public function toggleApiTokenField(Request $request, string $fieldId): JsonResponse
    {
        $this->requireAuth($request);
        $db  = \Ravi\Core\Database::getInstance();
        $fid = (int) $fieldId;
        if (!$db->fetch("SELECT id FROM gw_token_fields WHERE id = ?", [$fid])) {
            return $this->jsonError('Campo não encontrado.', 404);
        }
        $required = (int) filter_var($request->input('required', 0), FILTER_VALIDATE_BOOLEAN);
        $db->update('gw_token_fields', ['required' => $required], 'id = :_id', ['_id' => $fid]);
        return $this->json(['field_id' => $fid, 'required' => $required],
            $required ? 'Campo marcado como obrigatório.' : 'Campo tornado opcional.');
    }

    /** Remove um campo de identidade do token. */
    public function deleteApiTokenField(Request $request, string $fieldId): Response
    {
        $this->requireAuth($request);
        $db  = \Ravi\Core\Database::getInstance();
        $fid = (int) $fieldId;
        $row = $db->fetch("SELECT token_id FROM gw_token_fields WHERE id = ?", [$fid]);
        if (!$row) {
            return $this->redirectWithError('/admin/api', 'Campo não encontrado.');
        }
        $tid = (int) $row['token_id'];
        $db->delete('gw_token_fields', 'id = ?', [$fid]);
        return $this->redirectWithSuccess('/admin/api/tokens/' . $tid . '/fields', 'Campo removido.');
    }

    /** Histórico de chamadas de um token: origem (IP), horário, chamada e retorno. */
    public function apiGatewayHistory(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $db = \Ravi\Core\Database::getInstance();
        $tid = (int) $id;

        $token = $db->fetch(
            "SELECT id, name, token_prefix, is_active, revoked_at FROM gw_tokens WHERE id = ?",
            [$tid]
        );
        if (!$token) {
            return $this->redirectWithError('/admin/api', 'Token não encontrado.');
        }

        // Últimas 200 chamadas (mais recentes primeiro), com conteúdo completo.
        $rows = $db->fetchAll(
            "SELECT created_at, client_ip, server_token, server_ips, identity_json, model_requested, model_served, backend, status, error,
                    prompt_tokens, output_tokens, cache_read_tokens, cache_write_tokens,
                    duration_ms, request_text, response_text
             FROM gw_requests WHERE token_id = ? ORDER BY id DESC LIMIT 200",
            [$tid]
        );

        // Top 10 origens (IP) por volume, considerando TODO o histórico do token,
        // com a quantidade de chamadas quebrada por modelo servido.
        $originRows = $db->fetchAll(
            "SELECT COALESCE(NULLIF(client_ip, ''), '—') AS origem,
                    COALESCE(NULLIF(model_served, ''), NULLIF(model_requested, ''), '—') AS modelo,
                    COUNT(*) AS chamadas
             FROM gw_requests WHERE token_id = ?
             GROUP BY origem, modelo",
            [$tid]
        );
        $topOrigins = [];
        foreach ($originRows as $r) {
            $ip = $r['origem'];
            if (!isset($topOrigins[$ip])) {
                $topOrigins[$ip] = ['ip' => $ip, 'calls' => 0, 'models' => []];
            }
            $topOrigins[$ip]['calls'] += (int) $r['chamadas'];
            $topOrigins[$ip]['models'][$r['modelo']] = ($topOrigins[$ip]['models'][$r['modelo']] ?? 0) + (int) $r['chamadas'];
        }
        usort($topOrigins, fn($a, $b) => $b['calls'] <=> $a['calls']);
        $topOrigins = array_slice($topOrigins, 0, 10);
        foreach ($topOrigins as &$o) {
            arsort($o['models']);
        }
        unset($o);

        return $this->view('admin/api-history', [
            'title'       => 'Histórico: ' . ($token['name'] ?? 'Token'),
            'token'       => $token,
            'rows'        => $rows,
            'top_origins' => $topOrigins,
        ]);
    }

    /** Gera um novo token (mostra o valor em claro UMA vez). */
    public function createApiToken(Request $request): Response
    {
        $this->requireAuth($request);
        $name = trim((string) $request->post('name'));
        if ($name === '') {
            return $this->redirectWithError('/admin/api', 'Dê um nome ao token (ex.: "Sistema de e-mail").');
        }
        $secret = 'sk-ravidev-' . bin2hex(random_bytes(20));
        \Ravi\Core\Database::getInstance()->insert('gw_tokens', [
            'name'         => mb_substr($name, 0, 120),
            'token_prefix' => substr($secret, 0, 18) . '…',
            'token_hash'   => hash('sha256', $secret),
            'is_active'    => 1,
        ]);
        // Mostrado uma única vez na próxima tela (não é recuperável depois).
        Session::set('gw_new_token', $secret);
        return $this->redirectWithSuccess('/admin/api', 'Token criado. Copie agora: ele não será exibido novamente.');
    }

    /** Revoga (desativa) um token, mantendo o histórico de consumo. */
    public function revokeApiToken(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $tid = (int) $id;
        \Ravi\Core\Database::getInstance()->update(
            'gw_tokens',
            ['is_active' => 0, 'revoked_at' => date('Y-m-d H:i:s')],
            'id = :_id',
            ['_id' => $tid]
        );
        return $this->redirectWithSuccess('/admin/api', 'Token revogado.');
    }

    /** Exclui um token DE VEZ, junto com seus registros de consumo. */
    public function deleteApiToken(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $tid = (int) $id;
        $db = \Ravi\Core\Database::getInstance();
        $db->delete('gw_requests', 'token_id = ?', [$tid]);
        $db->delete('gw_tokens', 'id = ?', [$tid]);
        return $this->redirectWithSuccess('/admin/api', 'Token excluído.');
    }

    /** Define a licença Max que um TOKEN de API usa (0 = herda a licença global da API). AJAX. */
    public function assignApiTokenLicense(Request $request, string $id): JsonResponse
    {
        $this->requireAuth($request);
        $tid = (int) $id;
        $db  = \Ravi\Core\Database::getInstance();
        if (!$db->fetch("SELECT id FROM gw_tokens WHERE id = ?", [$tid])) {
            return $this->jsonError('Token não encontrado.', 404);
        }
        $lid = (int) $request->input('license_id', 0);
        $value = ($lid > 0 && LicenseService::exists($lid)) ? $lid : null;
        $db->update('gw_tokens', ['license_id' => $value], 'id = :_id', ['_id' => $tid]);
        return $this->json(['token_id' => $tid, 'license_id' => (int) $value], 'Licença do token salva.');
    }

    /** Liga/desliga o registro da identidade do servidor (token + IPs) por token de API. AJAX. */
    public function toggleApiTokenIdentity(Request $request, string $id): JsonResponse
    {
        $this->requireAuth($request);
        $tid = (int) $id;
        $db  = \Ravi\Core\Database::getInstance();
        if (!$db->fetch("SELECT id FROM gw_tokens WHERE id = ?", [$tid])) {
            return $this->jsonError('Token não encontrado.', 404);
        }
        $enabled = (int) filter_var($request->input('enabled', 1), FILTER_VALIDATE_BOOLEAN);
        $db->update('gw_tokens', ['log_server_identity' => $enabled], 'id = :_id', ['_id' => $tid]);
        return $this->json(['token_id' => $tid, 'log_server_identity' => $enabled],
            $enabled ? 'Registro de identidade do servidor ativado.' : 'Registro de identidade do servidor desativado.');
    }

    // ========================================================================
    // LICENÇAS DO CLAUDE CODE (assinaturas Max) — cadastrar várias e escolher
    // qual cada consumidor usa: API (global) + IDE/Dev autônomo (global + por usuário).
    // ========================================================================

    /** Página de gerência de licenças + atribuições. */
    public function licenses(Request $request): Response
    {
        $this->requireAuth($request);
        LicenseService::ensureSeeded(); // importa a licença do .env como "Licença atual" na 1ª vez

        $licenses = LicenseService::all();

        $global = [];
        foreach (array_keys(LicenseService::scopes()) as $sc) {
            $global[$sc] = LicenseService::globalId($sc);
        }

        // Atribuição por usuário (só escopos ide|autonomous) + a licença do ChatGPT na IDE (só
        // aparece quando há ao menos uma licença do ChatGPT ativa).
        $userRows = [];
        foreach (User::orderBy('id', 'ASC')->get() as $u) {
            $uid = (int) $u->id;
            $userRows[] = [
                'id'         => $uid,
                'name'       => (string) $u->name,
                'email'      => (string) $u->email,
                'ide'        => LicenseService::userIdFor($uid, 'ide'),
                'autonomous' => LicenseService::userIdFor($uid, 'autonomous'),
                'chatgpt'    => ChatgptLicenseService::userIdFor($uid),
            ];
        }

        // Onde cada licença está em uso (padrões globais, usuários e roteamentos), para a tela
        // mostrar no painel de cada licença sem montar isso na view.
        $usoPorLicenca = [];
        foreach ($global as $sc => $lid) {
            if ($lid) {
                $usoPorLicenca[(int) $lid][] = LicenseService::label($sc);
            }
        }
        $usuariosPorLicenca = [];
        foreach ($userRows as $r) {
            foreach (['ide', 'autonomous'] as $sc) {
                if (!empty($r[$sc])) {
                    $usuariosPorLicenca[(int) $r[$sc]][$r['id']] = true;
                }
            }
        }
        foreach ($usuariosPorLicenca as $lid => $uids) {
            $n = count($uids);
            $usoPorLicenca[$lid][] = $n === 1 ? t('licencas.uso_1_usuario', '1 usuário') : t('licencas.uso_n_usuarios', ':n usuários', ['n' => $n]);
        }
        if (LicenseService::apiMode() === 'failover') {
            foreach (LicenseService::apiFailoverBackups() as $lid) {
                $usoPorLicenca[(int) $lid][] = t('licencas.uso_backup_api', 'Backup da API');
            }
        } elseif (LicenseService::apiMode() === 'balance') {
            foreach (LicenseService::apiBalancePair() as $lid) {
                $usoPorLicenca[(int) $lid][] = t('licencas.uso_balance_api', 'Balance da API');
            }
        }
        if (LicenseService::autonomousMode() === 'balance') {
            foreach (LicenseService::autonomousPool() as $lid) {
                $usoPorLicenca[(int) $lid][] = t('licencas.uso_balance_auto', 'Balance do Dev Autônomo');
            }
        }

        return $this->view('admin/licenses', [
            'opcoesLicencas' => $this->opcoesDeLicenca($licenses),
            'opcoesChatgpt'  => $this->opcoesDeLicenca(ChatgptLicenseService::all()),
            'usoPorLicenca'  => $usoPorLicenca,
            'title'      => 'Licenças',
            'licenses'   => $licenses,
            'scopes'     => LicenseService::scopes(),
            'userScopes' => LicenseService::USER_SCOPES,
            'global'     => $global,
            'userRows'   => $userRows,
            'chatgptLicenses'  => ChatgptLicenseService::all(),
            'chatgptHasActive' => ChatgptLicenseService::hasActive(),
            'hasEnv'     => LicenseService::envFallback() !== '',
            'apiMode'    => LicenseService::apiMode(),
            'apiModes'   => LicenseService::API_MODES,
            'apiBackups' => LicenseService::apiFailoverBackups(),
            'apiPair'    => LicenseService::apiBalancePair(),
            'autoMode'   => LicenseService::autonomousMode(),
            'autoPool'   => LicenseService::autonomousPool(),
            'cooldowns'  => LicenseService::cooldowns(),
            'gwCatalog'      => (new \Ravi\Gateway\GatewayService())->modelCatalog(),
            'modelBackups'   => \Ravi\Gateway\GatewayService::modelBackups(),
            'deepseekConfigured' => \Ravi\Gateway\GatewayDeepSeekService::configured(),
        ]);
    }

    /**
     * Salva o hub de backups de modelo do gateway (irmão do roteamento de licenças:
     * licenças cobrem a CONTA que executa; aqui é o MODELO reserva quando a execução
     * do titular falha, ex.: deepseek-flash caiu -> claude-haiku assume via Max).
     */
    public function saveModelBackups(Request $request): Response
    {
        $this->requireAuth($request);
        $catalogo = array_column((new \Ravi\Gateway\GatewayService())->modelCatalog(), 'id');
        $titulares = (array) $request->post('backup_de', []);
        $primeiras = (array) $request->post('backup_p1', []);
        $segundas  = (array) $request->post('backup_p2', []);
        $mapa = [];
        foreach ($titulares as $i => $de) {
            $de = strtolower(trim((string) $de));
            $fila = [];
            foreach ([(string) ($primeiras[$i] ?? ''), (string) ($segundas[$i] ?? '')] as $p) {
                $p = strtolower(trim($p));
                if ($p !== '' && !in_array($p, $fila, true)) $fila[] = $p;
            }
            if ($de === '' || $fila === []) {
                continue; // linha adicionada e deixada em "— escolha —"
            }
            if (!in_array($de, $catalogo, true) || array_diff($fila, $catalogo) !== []) {
                return $this->redirectWithError('/admin/licenses', 'Backup de modelo: escolha modelos do catálogo.');
            }
            if (in_array($de, $fila, true)) {
                return $this->redirectWithError('/admin/licenses', 'Backup de modelo: "' . $de . '" não pode ser reserva de si mesmo.');
            }
            if (isset($mapa[$de])) {
                return $this->redirectWithError('/admin/licenses', 'Backup de modelo: "' . $de . '" aparece duas vezes como titular.');
            }
            $mapa[$de] = $fila;
        }
        \Ravi\Models\Setting::set('gw_model_backups', $mapa === [] ? null : json_encode($mapa, JSON_UNESCAPED_SLASHES));
        return $this->redirectWithSuccess('/admin/licenses', $mapa === []
            ? 'Backups de modelo removidos: sem reserva, a falha do titular volta como erro claro.'
            : 'Backups de modelo salvos (' . count($mapa) . ' titular(es)).');
    }

    /**
     * Uso do plano (janelas de 5h e semanal) das licenças ativas — alimenta os gráficos da tela
     * de licenças. Busca do cache; ?refresh=1 força sondagem nova em todas.
     */
    public function licensesUsage(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $force = (string) $request->input('refresh', '') === '1';
        return $this->json(['usage' => LicenseUsageService::snapshotAll($force)]);
    }

    /** Salva o roteamento da API: modo off|failover|balance + listas de licenças. */
    public function saveApiRouting(Request $request): Response
    {
        $this->requireAuth($request);
        $mode = (string) $request->post('mode');
        if (!isset(LicenseService::API_MODES[$mode])) {
            return $this->redirectWithError('/admin/licenses', 'Modo de roteamento inválido.');
        }

        if ($mode === 'failover') {
            $principal = LicenseService::globalId('api');
            if ($principal === null) {
                return $this->redirectWithError('/admin/licenses', 'Failover precisa de uma licença principal: defina a licença da API em "Licença por consumidor".');
            }
            $backups = [];
            foreach ((array) $request->post('failover', []) as $v) {
                $id = (int) $v;
                if ($id <= 0) {
                    continue; // linha adicionada mas deixada em "— escolha —"
                }
                if ($id === $principal) {
                    $nome = LicenseService::find($id)['name'] ?? ('#' . $id);
                    return $this->redirectWithError('/admin/licenses', '"' . $nome . '" já é a licença principal da API — ela é sempre tentada primeiro e não pode ser backup. Escolha outra licença.');
                }
                if (LicenseService::exists($id) && !in_array($id, $backups, true)) {
                    $backups[] = $id;
                }
            }
            if ($backups === []) {
                return $this->redirectWithError('/admin/licenses', 'Failover precisa de ao menos 1 licença de backup: clique em "Adicionar backup" e escolha uma licença na lista.');
            }
            LicenseService::setApiFailoverBackups($backups);
        } elseif ($mode === 'balance') {
            $a = (int) $request->post('balance_a', 0);
            $b = (int) $request->post('balance_b', 0);
            if ($a <= 0 || $b <= 0 || !LicenseService::exists($a) || !LicenseService::exists($b)) {
                return $this->redirectWithError('/admin/licenses', 'Balance precisa de 2 licenças escolhidas.');
            }
            if ($a === $b) {
                return $this->redirectWithError('/admin/licenses', 'Balance precisa de 2 licenças DIFERENTES.');
            }
            LicenseService::setApiBalancePair([$a, $b]);
        }

        LicenseService::setApiMode($mode);
        $labels = ['off' => 'desligado (só a licença da API)', 'failover' => 'failover', 'balance' => 'balance'];
        return $this->redirectWithSuccess('/admin/licenses', 'Roteamento da API salvo: ' . $labels[$mode] . '. Vale já para a próxima requisição.');
    }

    /** Salva o roteamento do Dev Autônomo: modo off|balance + licenças participantes (N ≥ 2). */
    public function saveAutonomousRouting(Request $request): Response
    {
        $this->requireAuth($request);
        $mode = (string) $request->post('mode');
        if (!isset(LicenseService::AUTONOMOUS_MODES[$mode])) {
            return $this->redirectWithError('/admin/licenses', 'Modo de roteamento inválido.');
        }

        if ($mode === 'balance') {
            $pool = [];
            foreach ((array) $request->post('pool', []) as $v) {
                $id = (int) $v;
                if ($id > 0 && LicenseService::exists($id) && !in_array($id, $pool, true)) {
                    $pool[] = $id;
                }
            }
            if (count($pool) < 2) {
                return $this->redirectWithError('/admin/licenses', 'Balance do Dev Autônomo precisa de ao menos 2 licenças marcadas.');
            }
            LicenseService::setAutonomousPool($pool);
        }

        LicenseService::setAutonomousMode($mode);
        return $this->redirectWithSuccess('/admin/licenses', $mode === 'balance'
            ? 'Balance do Dev Autônomo ativado: os próximos agentes serão distribuídos entre as licenças marcadas.'
            : 'Roteamento do Dev Autônomo desligado: os agentes voltam à licença do escopo.');
    }

    /**
     * Lê o par dia/hora da renovação semanal do POST. '' = não informado.
     * Retorna [dow, hour] (ints ou null) ou uma string de ERRO quando só um dos dois foi preenchido.
     */
    private function parseWeeklyReset(Request $request): array|string
    {
        $dowRaw  = (string) $request->post('reset_dow', '');
        $hourRaw = (string) $request->post('reset_hour', '');
        $dow  = ($dowRaw !== ''  && ctype_digit($dowRaw)  && (int) $dowRaw  <= 6)  ? (int) $dowRaw  : null;
        $hour = ($hourRaw !== '' && ctype_digit($hourRaw) && (int) $hourRaw <= 23) ? (int) $hourRaw : null;
        if (($dow === null) !== ($hour === null)) {
            return 'Renovação semanal incompleta: informe o dia E a hora (ou deixe os dois em branco).';
        }
        return [$dow, $hour];
    }

    /**
     * Lê o teto de uso (%) para IDE/Dev Autônomo do POST. '' = sem teto (null).
     * Retorna [?int] ou uma string de ERRO quando o valor não é um percentual de 1 a 99.
     */
    private function parseUsageLimit(Request $request): array|string
    {
        $raw = trim((string) $request->post('limit_pct', ''));
        if ($raw === '') {
            return [null];
        }
        if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 99) {
            return 'Teto de uso inválido: use um percentual de 1 a 99 (ou deixe em branco para não limitar).';
        }
        return [(int) $raw];
    }

    /** Cadastra uma nova licença (nome + OAuth token + renovação semanal opcional). */
    public function createLicense(Request $request): Response
    {
        $this->requireAuth($request);
        $name  = trim((string) $request->post('name'));
        $token = trim((string) $request->post('token'));
        $note  = trim((string) $request->post('note'));
        if ($name === '') {
            return $this->redirectWithError('/admin/licenses', 'Dê um nome à licença (ex.: "Max 2 — conta suporte").');
        }
        if (strlen($token) < 20) {
            return $this->redirectWithError('/admin/licenses', 'Cole o token da licença (gerado por "claude setup-token").');
        }
        $reset = $this->parseWeeklyReset($request);
        if (is_string($reset)) {
            return $this->redirectWithError('/admin/licenses', $reset);
        }
        $limit = $this->parseUsageLimit($request);
        if (is_string($limit)) {
            return $this->redirectWithError('/admin/licenses', $limit);
        }
        LicenseService::create($name, $token, $note !== '' ? $note : null, $reset[0], $reset[1], $limit[0]);
        return $this->redirectWithSuccess('/admin/licenses', 'Licença cadastrada. Clique em "Testar" para confirmar que autentica.');
    }

    /** Renomeia / atualiza a nota (e o token, se um novo for colado). */
    public function updateLicense(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $lid = (int) $id;
        if (!LicenseService::exists($lid)) {
            return $this->redirectWithError('/admin/licenses', 'Licença não encontrada.');
        }
        $name  = trim((string) $request->post('name'));
        $note  = trim((string) $request->post('note'));
        $token = trim((string) $request->post('token'));
        if ($name === '') {
            return $this->redirectWithError('/admin/licenses', 'O nome não pode ficar vazio.');
        }
        $reset = $this->parseWeeklyReset($request);
        if (is_string($reset)) {
            return $this->redirectWithError('/admin/licenses', $reset);
        }
        $limit = $this->parseUsageLimit($request);
        if (is_string($limit)) {
            return $this->redirectWithError('/admin/licenses', $limit);
        }
        LicenseService::update($lid, $name, $note !== '' ? $note : null, $token !== '' ? $token : null, $reset[0], $reset[1], $limit[0]);
        return $this->redirectWithSuccess('/admin/licenses', 'Licença atualizada.');
    }

    /** Ativa/desativa uma licença (desativada não é servida a ninguém). */
    public function toggleLicense(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $lid = (int) $id;
        $lic = LicenseService::find($lid);
        if (!$lic) {
            return $this->redirectWithError('/admin/licenses', 'Licença não encontrada.');
        }
        LicenseService::setActive($lid, !$lic['is_active']);
        return $this->redirectWithSuccess('/admin/licenses', $lic['is_active'] ? 'Licença desativada.' : 'Licença ativada.');
    }

    /** Exclui uma licença. As atribuições que a usavam voltam ao fallback (.env). */
    public function deleteLicense(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $lid = (int) $id;
        if (!LicenseService::exists($lid)) {
            return $this->redirectWithError('/admin/licenses', 'Licença não encontrada.');
        }
        LicenseService::delete($lid);
        return $this->redirectWithSuccess('/admin/licenses', 'Licença excluída.');
    }

    /** Define a licença GLOBAL de um escopo (api|ide|autonomous). AJAX. */
    public function assignLicense(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $scope = (string) $request->input('scope', '');
        if (!LicenseService::isScope($scope)) {
            return $this->jsonError('Escopo inválido.', 422);
        }
        $lid = (int) $request->input('license_id', 0);
        LicenseService::setGlobal($scope, $lid > 0 ? $lid : null);
        // Módulos opcionais podem reagir à atribuição (gancho 'licencas.escopo.atribuido'); se um deles
        // devolver uma mensagem, ela vale como resposta.
        foreach (\Ravi\Core\Modulos::gancho('licencas.escopo.atribuido', $scope, $lid) as $msg) {
            if (is_string($msg) && $msg !== '') {
                return $this->json(['scope' => $scope, 'license_id' => $lid], $msg);
            }
        }
        return $this->json(['scope' => $scope, 'license_id' => $lid], 'Atribuição salva. Vale para ambientes abertos a partir de agora.');
    }

    /** Define a licença de um USUÁRIO num escopo (ide|autonomous). '' = herda o global. AJAX. */
    public function assignUserLicense(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $uid   = (int) $request->input('user_id', 0);
        $scope = (string) $request->input('scope', '');
        if (!LicenseService::isUserScope($scope)) {
            return $this->jsonError('Escopo inválido.', 422);
        }
        if ($uid <= 0 || !User::find($uid)) {
            return $this->jsonError('Usuário inválido.', 422);
        }
        $lid = (int) $request->input('license_id', 0);
        LicenseService::setUser($uid, $scope, $lid > 0 ? $lid : null);
        return $this->json(['user_id' => $uid, 'scope' => $scope, 'license_id' => $lid], 'Atribuição salva.');
    }

    /** Testa se uma licença autentica de verdade (roda um `claude -p` efêmero). AJAX. */
    public function testLicense(Request $request, string $id): JsonResponse
    {
        $this->requireAuth($request);
        $lid = (int) $id;
        $tok = LicenseService::rawToken($lid);
        if ($tok === '') {
            return $this->jsonError('Licença não encontrada ou token ilegível.', 404);
        }

        $img   = (string) (config('sandbox')['image'] ?? 'ravi-sandbox:latest');
        $inner = 'mkdir -p "$HOME/.claude" 2>/dev/null; '
               . 'claude -p ' . escapeshellarg('Responda apenas: OK')
               . ' --model sonnet --permission-mode bypassPermissions 2>&1 | head -c 600';
        $cmd = 'timeout 50 docker run --rm'
             . ' -e ' . escapeshellarg('CLAUDE_CODE_OAUTH_TOKEN=' . $tok)
             . ' -e ' . escapeshellarg('HOME=/tmp/cc-home')
             . ' -e ' . escapeshellarg('IS_SANDBOX=1')
             . ' -e DISABLE_AUTOUPDATER=1 -e DISABLE_TELEMETRY=1 -e DISABLE_ERROR_REPORTING=1'
             . ' ' . escapeshellarg($img)
             . ' bash -lc ' . escapeshellarg($inner) . ' 2>&1';
        $out = []; $rc = 0;
        @exec($cmd, $out, $rc);
        $text = trim(implode("\n", $out));
        $lc   = strtolower($text);
        $authErr = str_contains($lc, 'invalid') || str_contains($lc, 'unauthorized')
                 || str_contains($lc, 'authentication') || str_contains($lc, 'expired')
                 || str_contains($lc, 'forbidden') || str_contains($lc, '401');
        if ($rc !== 0 || $text === '' || $authErr) {
            $msg = $text !== '' ? mb_substr($text, 0, 220) : 'sem resposta (timeout ou erro de execução)';
            return $this->json(['ok' => false, 'detail' => $msg], 'Falha ao autenticar a licença.', 200);
        }
        return $this->json(['ok' => true, 'reply' => mb_substr($text, 0, 120)], 'Licença válida e autenticada.');
    }

    // ========================================================================
    // LICENÇAS DO CHATGPT (Codex na IDE) — cadastrar várias (auth.json de cada conta) e
    // escolher qual a IDE de cada usuário usa (padrão global + override por usuário).
    // ========================================================================

    /** Página de licenças do ChatGPT + atribuições (aba "ChatGPT" de Licenças). */
    public function chatgptLicenses(Request $request): Response
    {
        $this->requireAuth($request);
        $licenses = ChatgptLicenseService::all();
        $userRows = [];
        foreach (User::orderBy('id', 'ASC')->get() as $u) {
            $uid = (int) $u->id;
            $userRows[] = [
                'id'      => $uid,
                'name'    => (string) $u->name,
                'email'   => (string) $u->email,
                'chatgpt' => ChatgptLicenseService::userIdFor($uid),
            ];
        }
        // Quantos usuários escolheram cada licença (o padrão global é marcado à parte).
        $usoPorLicenca = [];
        $globalGpt = ChatgptLicenseService::globalId();
        if ($globalGpt) {
            $usoPorLicenca[(int) $globalGpt][] = t('licencas.gpt_uso_padrao', 'Padrão da IDE');
        }
        $contagem = array_count_values(array_filter(array_map(static fn($r) => (int) ($r['chatgpt'] ?? 0), $userRows)));
        foreach ($contagem as $lid => $n) {
            $usoPorLicenca[(int) $lid][] = $n === 1 ? t('licencas.uso_1_usuario', '1 usuário') : t('licencas.uso_n_usuarios', ':n usuários', ['n' => $n]);
        }

        return $this->view('admin/licenses-chatgpt', [
            'opcoesLicencas' => $this->opcoesDeLicenca($licenses),
            'usoPorLicenca'  => $usoPorLicenca,
            'title'     => 'Licenças do ChatGPT',
            'licenses'  => $licenses,
            'hasActive' => ChatgptLicenseService::hasActive(),
            'global'    => ChatgptLicenseService::globalId(),
            'userRows'  => $userRows,
        ]);
    }

    /**
     * Opções prontas para os seletores de licença das telas de licenças (Claude e ChatGPT):
     * id => rótulo, com a marca de inativa. O "nenhuma/herda" de cada seletor fica na view.
     */
    private function opcoesDeLicenca(array $licencas): array
    {
        $opcoes = [];
        foreach ($licencas as $l) {
            $opcoes[(int) $l['id']] = (string) $l['name'] . (!empty($l['is_active']) ? '' : ' ' . t('licencas.inativa_parenteses', '(inativa)'));
        }
        return $opcoes;
    }

    /** Cadastra uma licença do ChatGPT (nome + conteúdo do ~/.codex/auth.json). */
    public function createChatgptLicense(Request $request): Response
    {
        $this->requireAuth($request);
        $name = trim((string) $request->post('name'));
        $auth = (string) $request->post('auth');
        $note = trim((string) $request->post('note'));
        if ($name === '') {
            return $this->redirectWithError('/admin/licenses/chatgpt', 'Dê um nome à licença (ex.: "ChatGPT Pro — conta principal").');
        }
        $parsed = ChatgptLicenseService::parseAuth($auth);
        if ($parsed === null) {
            return $this->redirectWithError('/admin/licenses/chatgpt', 'Cole o conteúdo completo do ~/.codex/auth.json (gerado por "codex login"). O texto colado não é um auth.json válido.');
        }
        ChatgptLicenseService::create($name, $parsed, $note !== '' ? $note : null);
        return $this->redirectWithSuccess('/admin/licenses/chatgpt', 'Licença cadastrada. Clique em "Testar" para confirmar que autentica.');
    }

    /** Renomeia / atualiza a nota (e o auth.json, se um novo for colado). */
    public function updateChatgptLicense(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $lid = (int) $id;
        if (!ChatgptLicenseService::exists($lid)) {
            return $this->redirectWithError('/admin/licenses/chatgpt', 'Licença não encontrada.');
        }
        $name = trim((string) $request->post('name'));
        $note = trim((string) $request->post('note'));
        $auth = trim((string) $request->post('auth'));
        if ($name === '') {
            return $this->redirectWithError('/admin/licenses/chatgpt', 'O nome não pode ficar vazio.');
        }
        $parsed = null;
        if ($auth !== '') {
            $parsed = ChatgptLicenseService::parseAuth($auth);
            if ($parsed === null) {
                return $this->redirectWithError('/admin/licenses/chatgpt', 'O novo auth.json colado não é válido; nada foi alterado.');
            }
        }
        ChatgptLicenseService::update($lid, $name, $note !== '' ? $note : null, $parsed);
        return $this->redirectWithSuccess('/admin/licenses/chatgpt', 'Licença atualizada.');
    }

    /** Ativa/desativa uma licença (desativada sai das IDEs no próximo minuto). */
    public function toggleChatgptLicense(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $lid = (int) $id;
        $lic = ChatgptLicenseService::find($lid);
        if (!$lic) {
            return $this->redirectWithError('/admin/licenses/chatgpt', 'Licença não encontrada.');
        }
        ChatgptLicenseService::setActive($lid, !$lic['is_active']);
        return $this->redirectWithSuccess('/admin/licenses/chatgpt', $lic['is_active'] ? 'Licença desativada.' : 'Licença ativada.');
    }

    public function deleteChatgptLicense(Request $request, string $id): Response
    {
        $this->requireAuth($request);
        $lid = (int) $id;
        if (!ChatgptLicenseService::exists($lid)) {
            return $this->redirectWithError('/admin/licenses/chatgpt', 'Licença não encontrada.');
        }
        ChatgptLicenseService::delete($lid);
        return $this->redirectWithSuccess('/admin/licenses/chatgpt', 'Licença excluída.');
    }

    /** Define a licença do ChatGPT GLOBAL da IDE. AJAX. */
    public function assignChatgptLicense(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $lid = (int) $request->input('license_id', 0);
        ChatgptLicenseService::setGlobal($lid > 0 ? $lid : null);
        return $this->json(['license_id' => $lid], 'Atribuição salva. As IDEs abertas recebem a licença em até 1 minuto.');
    }

    /** Define a licença do ChatGPT de um USUÁRIO na IDE. '' = herda o global. AJAX. */
    public function assignUserChatgptLicense(Request $request): JsonResponse
    {
        $this->requireAuth($request);
        $uid = (int) $request->input('user_id', 0);
        if ($uid <= 0 || !User::find($uid)) {
            return $this->jsonError('Usuário inválido.', 422);
        }
        $lid = (int) $request->input('license_id', 0);
        ChatgptLicenseService::setUser($uid, $lid > 0 ? $lid : null);
        return $this->json(['user_id' => $uid, 'license_id' => $lid], 'Atribuição salva. A IDE do usuário recebe a licença em até 1 minuto.');
    }

    /** Testa se a licença autentica (roda um `codex exec` efêmero). AJAX. */
    public function testChatgptLicense(Request $request, string $id): JsonResponse
    {
        $this->requireAuth($request);
        $res = ChatgptLicenseService::test((int) $id);
        if (!$res['ok']) {
            return $this->json(['ok' => false, 'detail' => $res['detail']], 'Falha ao autenticar a licença.', 200);
        }
        return $this->json(['ok' => true, 'reply' => $res['detail']], 'Licença válida e autenticada.');
    }
}
