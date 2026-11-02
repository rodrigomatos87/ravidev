<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\Request;
use Ravi\Core\Response;
use Ravi\Core\JsonResponse;
use Ravi\Core\Session;
use Ravi\Core\Validator;
use Ravi\Core\Encryption;
use Ravi\Models\User;
use Ravi\Models\UserPref;
use Ravi\Models\Setting;
use Ravi\Services\EmailService;

/**
 * Settings Controller
 * Handles user settings, profile, API keys, and account management
 */
class SettingsController extends Controller
{
    /**
     * Configurações do sistema — SOMENTE ADMIN. Usuários comuns vão para a área pessoal.
     */
    public function index(Request $request): Response
    {
        $uid     = (int) ($this->user->id ?? 0);
        $isAdmin = $this->user && $this->user->isAdmin();
        if (!$isAdmin) {
            return $this->redirect('/settings/profile');
        }

        // Preferências de notificação de SISTEMA (admin). Mostra as marcadas como admin-only.
        $adminPrefKeys = ['notify_new_user'];
        $prefs = [];
        foreach (UserPref::NOTIFY as $key => $label) {
            if (in_array($key, $adminPrefKeys, true)) {
                $prefs[$key] = ['label' => $label, 'on' => UserPref::enabled($uid, $key)];
            }
        }

        $usageSvc = new \Ravi\Services\UsageService();

        return $this->view('settings/index', [
            'title'     => 'Configurações do sistema',
            'activeTab' => 'config',
            'prefs'     => $prefs,
            'smtp'      => [
                'configured' => (new EmailService())->isConfigured(),
                'host'       => Setting::get('mail_host', (string) env('MAIL_HOST', '')),
                'from'       => Setting::get('mail_from_address', (string) env('MAIL_FROM_ADDRESS', '')),
            ],
            'usage'     => $usageSvc->breakdown(),       // global (inclui IDE)
            'usageFlags' => $usageSvc->flags(),
            'usageReset' => $usageSvc->resetAt(),
            'showHomepage' => Setting::get('show_homepage', '1') === '1',
            'models'       => \Ravi\Services\ModelService::MODELS,
            'globalModelIde'        => \Ravi\Services\ModelService::globalDefault('ide'),
            'globalModelAutonomous' => \Ravi\Services\ModelService::globalDefault('autonomous'),
            'globalModelAgents'     => \Ravi\Services\ModelService::globalPref(\Ravi\Services\ModelService::SCOPE_AGENTS), // '' = herdar
            'sonnet1m'   => \Ravi\Services\ModelService::sonnet1mEnabled(),
            'languages'  => \Ravi\Core\Lang::available(),
            'activeLang' => \Ravi\Core\Lang::active(),
            'resources'  => [
                'sandbox_mem_mb' => $this->memToMb(Setting::get('sandbox_memory', (string) config('sandbox.memory_limit', '2g')), 2048),
                'sandbox_cpu'    => max(1, (int) Setting::get('sandbox_cpu', (string) config('sandbox.cpu_limit', '2'))),
                'ide_mem_mb'     => $this->memToMb(Setting::get('ide_memory', (string) env('IDE_MEMORY', '2g')), 2048),
                'ide_cpu'        => max(1, (int) Setting::get('ide_cpu', (string) env('IDE_CPU', '2'))),
                'host_mem_mb'    => $this->hostMemMb(),
                'host_cpu'       => $this->hostCpu(),
            ],
            'resourceAlerts' => [
                'enabled'  => Setting::get('resource_alert_enabled', '1') === '1',
                'disk_pct' => max(50, min(99, (int) Setting::get('resource_alert_disk_pct', '90'))),
                'ram_pct'  => max(50, min(99, (int) Setting::get('resource_alert_ram_pct', '90'))),
            ],
        ]);
    }

    /**
     * Salva os limiares de alerta de uso do servidor (disco/RAM). Quando o uso passa do limite,
     * o cron bin/resource-alert-monitor.php avisa os admins por WhatsApp/e-mail. Admin, AJAX.
     */
    public function updateResourceAlerts(Request $request): JsonResponse
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->jsonError('Sem permissão.', 403);
        }
        $clampPct = fn ($v) => max(50, min(99, (int) $v));
        $enabled  = (string) $request->input('enabled', '1') === '1';
        $disk     = $clampPct($request->input('disk_pct', 90));
        $ram      = $clampPct($request->input('ram_pct', 90));

        Setting::setMany([
            'resource_alert_enabled'  => $enabled ? '1' : '0',
            'resource_alert_disk_pct' => (string) $disk,
            'resource_alert_ram_pct'  => (string) $ram,
        ]);

        return $this->json(
            ['enabled' => $enabled, 'disk_pct' => $disk, 'ram_pct' => $ram],
            'Alertas de uso do servidor salvos.'
        );
    }

    /** Salva os limites de CPU/RAM dos containers (Dev autônomo + IDE). Admin, AJAX. */
    public function updateResources(Request $request): JsonResponse
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->jsonError('Sem permissão.', 403);
        }
        $hostCpu = $this->hostCpu();
        $hostMem = $this->hostMemMb();
        $clampMem = fn ($v) => max(512, min($hostMem, (int) $v));   // MB
        $clampCpu = fn ($v) => max(1, min($hostCpu, (int) $v));     // cores inteiros

        Setting::setMany([
            'sandbox_memory' => $clampMem($request->input('sandbox_mem', 2048)) . 'm',
            'sandbox_cpu'    => (string) $clampCpu($request->input('sandbox_cpu', 2)),
            'ide_memory'     => $clampMem($request->input('ide_mem', 2048)) . 'm',
            'ide_cpu'        => (string) $clampCpu($request->input('ide_cpu', 2)),
        ]);

        return $this->json([
            'sandbox_mem_mb' => $clampMem($request->input('sandbox_mem', 2048)),
            'sandbox_cpu'    => $clampCpu($request->input('sandbox_cpu', 2)),
            'ide_mem_mb'     => $clampMem($request->input('ide_mem', 2048)),
            'ide_cpu'        => $clampCpu($request->input('ide_cpu', 2)),
        ], 'Recursos salvos — valem para containers abertos a partir de agora.');
    }

    /** Núcleos de CPU do host (teto dos sliders). */
    private function hostCpu(): int
    {
        $n = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        return $n > 0 ? $n : 8;
    }

    /** RAM total do host em MB (teto dos sliders). */
    private function hostMemMb(): int
    {
        $info = @file_get_contents('/proc/meminfo');
        if ($info && preg_match('/MemTotal:\s+(\d+)\s*kB/', $info, $m)) {
            $mb = (int) round(((int) $m[1]) / 1024);
            if ($mb > 0) {
                return $mb;
            }
        }
        return 8192;
    }

    /** Converte um limite de memória do Docker ("2g", "4096m", "512k") para MB. */
    private function memToMb(?string $v, int $default): int
    {
        if (!$v) {
            return $default;
        }
        $v = strtolower(trim($v));
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([gmk]?)/', $v, $m)) {
            $n = (float) $m[1];
            $u = $m[2];
            if ($u === 'g') {
                return (int) round($n * 1024);
            }
            if ($u === 'k') {
                return (int) round($n / 1024);
            }
            return (int) round($n); // 'm' ou sem unidade → já em MB (nosso domínio sempre usa unidade)
        }
        return $default;
    }

    /** Liga/desliga um seletor de custo (#2) ou a visibilidade p/ membros (#8). Admin, AJAX. */
    public function updateCostFlag(Request $request): JsonResponse
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->jsonError('Sem permissão.', 403);
        }
        $allowed = [
            \Ravi\Services\UsageService::KEY_CHAT, \Ravi\Services\UsageService::KEY_API,
            \Ravi\Services\UsageService::KEY_IDE, \Ravi\Services\UsageService::KEY_MEMBERS_SEE,
            'show_homepage', \Ravi\Services\ModelService::SONNET_1M_KEY,
        ];
        $key = (string) $request->input('key', '');
        if (!in_array($key, $allowed, true)) {
            return $this->jsonError('Chave inválida.', 422);
        }
        $on = (string) $request->input('enabled', '0') === '1';
        Setting::set($key, $on ? '1' : '0');
        return $this->json(['key' => $key, 'enabled' => $on], 'Preferência salva');
    }

    /** Zera os contadores de custo estimado (baseline = agora). Admin, AJAX. */
    public function resetCost(Request $request): JsonResponse
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->jsonError('Sem permissão.', 403);
        }
        (new \Ravi\Services\UsageService())->resetCounters();
        return $this->json((new \Ravi\Services\UsageService())->breakdown(), 'Contadores zerados');
    }

    /** #3 — Define o idioma ativo do sistema. Admin, AJAX. */
    public function updateLanguage(Request $request): JsonResponse
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->jsonError('Sem permissão.', 403);
        }
        $lang = preg_replace('/[^a-z0-9_-]/i', '', (string) $request->input('lang', '')) ?: '';
        if (!in_array($lang, \Ravi\Core\Lang::available(), true)) {
            return $this->jsonError('Idioma inválido.', 422);
        }
        Setting::set('system_language', $lang);
        return $this->json(['lang' => $lang], 'Idioma alterado.');
    }

    /** Modelo PADRÃO do sistema por ESCOPO (ide|autonomous). Admin, AJAX. */
    public function updateModel(Request $request): JsonResponse
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->jsonError('Sem permissão.', 403);
        }
        $scope = (string) $request->input('scope', '');
        $m     = (string) $request->input('model', '');
        if (!\Ravi\Services\ModelService::isScope($scope)) {
            return $this->jsonError('Escopo inválido.', 422);
        }
        // No escopo dos AGENTES, vazio é válido: herdar o modelo do Dev Autônomo.
        $herdavel = $scope === \Ravi\Services\ModelService::SCOPE_AGENTS;
        if (!\Ravi\Services\ModelService::isValid($m) && !($m === '' && $herdavel)) {
            return $this->jsonError('Modelo inválido.', 422);
        }
        Setting::set(\Ravi\Services\ModelService::globalKey($scope), $m);
        $label = $m === '' ? 'Herdar do Dev Autônomo' : \Ravi\Services\ModelService::label($m);
        return $this->json(['scope' => $scope, 'model' => $m, 'label' => $label], 'Modelo padrão salvo. Vale para containers abertos a partir de agora.');
    }

    /** Override do modelo POR USUÁRIO e ESCOPO (vazio = herda o global). Qualquer usuário, AJAX. */
    public function updateUserModel(Request $request): JsonResponse
    {
        $uid   = (int) ($this->user->id ?? 0);
        $scope = (string) $request->input('scope', '');
        $m     = (string) $request->input('model', '');
        if (!\Ravi\Services\ModelService::isScope($scope)) {
            return $this->jsonError('Escopo inválido.', 422);
        }
        if ($m !== '' && !\Ravi\Services\ModelService::isValid($m)) {
            return $this->jsonError('Modelo inválido.', 422);
        }
        UserPref::putRaw($uid, \Ravi\Services\ModelService::userKey($scope), $m); // '' = herdar global
        return $this->json([
            'scope'     => $scope,
            'model'     => $m,
            'effective' => \Ravi\Services\ModelService::forUser($uid, $scope),
        ], 'Modelo salvo. Vale a partir da próxima abertura.');
    }

    /**
     * Conta do Claude escolhida pelo PRÓPRIO usuário no perfil (cards). Só funciona para quem o
     * admin liberou (interruptor na gestão de usuários). A escolha vale para a IDE e o Dev
     * Autônomo dele (os dois escopos com override por usuário); 0 = voltar ao padrão do sistema.
     */
    public function updateUserLicense(Request $request): JsonResponse
    {
        $uid = (int) ($this->user->id ?? 0);
        if (!\Ravi\Services\LicenseService::selfChoiceEnabled($uid)) {
            return $this->jsonError('A escolha de conta não está liberada para o seu usuário.', 403);
        }
        $lid = (int) $request->input('license_id', 0);
        if ($lid > 0) {
            $lic = \Ravi\Services\LicenseService::find($lid);
            if (!$lic || !$lic['is_active']) {
                return $this->jsonError('Conta inválida ou indisponível.', 422);
            }
            // Conta NO TETO de uso do admin não pode ser escolhida (a UI já bloqueia o card;
            // aqui é a trava de verdade). Sem sonda: leitura do cache, não atrasa o clique.
            $blk = \Ravi\Services\LicenseService::usageBlocked($lid);
            if ($blk !== null) {
                return $this->jsonError('Esta conta está no teto de uso definido pelo administrador ('
                    . $blk['pct'] . '% de ' . $blk['limit'] . '%)'
                    . ($blk['reset'] !== null ? '; libera ' . date('d/m H:i', (int) $blk['reset']) : '')
                    . '. Escolha outra conta.', 422);
            }
        }
        foreach (\Ravi\Services\LicenseService::USER_SCOPES as $scope) {
            \Ravi\Services\LicenseService::setUser($uid, $scope, $lid > 0 ? $lid : null);
        }
        // Repassa JÁ para as IDEs abertas (reamarra os handles no banco); o scheduler (1/min) é a
        // retaguarda. Só resta o cache do proxy (≤45s) — por isso a UI promete "até 60 segundos".
        try {
            (new \Ravi\Services\IdeService())->reconcileClaudeHandles();
        } catch (\Throwable $e) { /* best-effort; o scheduler cobre */ }
        return $this->json(['license_id' => $lid], $lid > 0
            ? 'Conta selecionada. Nas IDEs já abertas a troca é aplicada em até 60 segundos.'
            : 'Você voltou ao padrão do sistema. Nas IDEs já abertas a troca é aplicada em até 60 segundos.');
    }

    /**
     * Uso do plano das contas disponíveis, para os cards do perfil. Mesma fonte da tela do admin,
     * mas SÓ o essencial (nome + janelas de uso) — sem hint de token, nota ou detalhe de erro.
     * Sem ?refresh: usuário comum não força sondagem nova (o cache de 10 min basta).
     */
    public function licensesUsage(Request $request): JsonResponse
    {
        $uid = (int) ($this->user->id ?? 0);
        // Sem escolha de conta liberada, o usuário ainda pode ACOMPANHAR a própria conta efetiva
        // da IDE quando o admin configurou um teto de uso nela (engrenagem da IDE) — só ela.
        $only = null;
        if (!\Ravi\Services\LicenseService::selfChoiceEnabled($uid)) {
            $only = \Ravi\Services\LicenseService::licenseIdForUser($uid, 'ide');
            if ($only === null || \Ravi\Services\LicenseService::usageLimitPct($only) === null) {
                return $this->jsonError('A escolha de conta não está liberada para o seu usuário.', 403);
            }
        }
        $out = [];
        foreach (\Ravi\Services\LicenseUsageService::snapshotAll(false) as $id => $snap) {
            if ($only !== null && $id !== $only) {
                continue;
            }
            $out[$id] = [
                'ok'        => (bool) ($snap['ok'] ?? false),
                'five_hour' => $snap['five_hour'] ?? null,
                'seven_day' => $snap['seven_day'] ?? null,
                'fable'     => $snap['fable'] ?? null,
                'limit'     => \Ravi\Services\LicenseService::usageLimitPct($id),
            ];
        }
        return $this->json(['usage' => $out]);
    }

    /** #3 — Baixa o arquivo .json de um idioma (para traduzir). Admin. */
    public function downloadLanguage(Request $request): Response
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->redirect('/settings/profile');
        }
        $lang = preg_replace('/[^a-z0-9_-]/i', '', (string) $request->input('lang', 'pt')) ?: 'pt';
        $file = ROOT_PATH . '/lang/' . $lang . '.json';
        if (!is_file($file)) {
            return $this->redirectWithError('/settings', 'Idioma não encontrado.');
        }
        return (new Response((string) file_get_contents($file)))
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $lang . '.json"');
    }

    /** #3 — Sobe um novo arquivo de idioma (JSON de chave→texto). Admin. */
    public function uploadLanguage(Request $request): JsonResponse
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->jsonError('Sem permissão.', 403);
        }
        $name = preg_replace('/[^a-z0-9_-]/i', '', strtolower((string) $request->input('name', ''))) ?: '';
        if ($name === '' || $name === 'pt') {
            return $this->jsonError('Informe um nome (ex.: en, es). "pt" é o base e não pode ser substituído.', 422);
        }
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== 0 || empty($f['tmp_name'])) {
            return $this->jsonError('Envie um arquivo .json.', 422);
        }
        $data = json_decode((string) @file_get_contents($f['tmp_name']), true);
        if (!is_array($data) || $data === []) {
            return $this->jsonError('Arquivo inválido: precisa ser um JSON de chave→texto.', 422);
        }
        // Mantém só pares string→string (segurança/limpeza).
        $clean = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $clean[$k] = (string) $v;
            }
        }
        if ($clean === []) {
            return $this->jsonError('Nenhuma tradução válida encontrada no arquivo.', 422);
        }
        @file_put_contents(ROOT_PATH . '/lang/' . $name . '.json', json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $this->json(['lang' => $name], 'Idioma "' . $name . '" adicionado. Selecione-o para ativar.');
    }

    /**
     * Minha Conta — Notificações por e-mail (por usuário). Exclui prefs de admin.
     */
    public function notifications(Request $request): Response
    {
        $uid = (int) ($this->user->id ?? 0);
        // Estas não entram na lista de e-mail: admin-only, ou vão na seção WhatsApp.
        $skipFromEmail = ['notify_new_user', 'notify_ide_long'];

        $prefs = [];
        foreach (UserPref::NOTIFY as $key => $label) {
            if (in_array($key, $skipFromEmail, true)) {
                continue;
            }
            $prefs[$key] = ['label' => $label, 'on' => UserPref::enabled($uid, $key)];
        }

        // WhatsApp: alerta de trabalho longo na IDE (toggle + limite em minutos).
        $waConnected = false;
        try {
            $waConnected = (bool) \Ravi\Core\Database::getInstance()->fetch(
                "SELECT id FROM user_whatsapp_connections WHERE user_id = ? AND status = 'connected'",
                [$uid]
            );
        } catch (\Throwable $e) {
            // tabela ausente → trata como não conectado
        }

        return $this->view('settings/notifications', [
            'title'          => 'Notificações',
            'prefs'          => $prefs,
            'smtpConfigured' => (new EmailService())->isConfigured(),
            'ideLongOn'      => UserPref::enabled($uid, 'notify_ide_long'),
            'ideLongLabel'   => UserPref::NOTIFY['notify_ide_long'],
            'ideAlertMin'    => UserPref::ideAlertMinutes($uid),
            'waConnected'    => $waConnected,
        ]);
    }

    /** Salva um toggle de notificação OU o campo de minutos do alerta de IDE (AJAX). */
    public function updateNotifications(Request $request): JsonResponse
    {
        $uid = (int) ($this->user->id ?? 0);
        $key = (string) $request->input('key');

        // Campo numérico: limite (minutos) do alerta de trabalho longo na IDE.
        if ($key === UserPref::IDE_ALERT_MIN_KEY) {
            $min = (int) $request->input('value');
            if ($min < 1) { $min = UserPref::IDE_ALERT_DEFAULT_MIN; }
            $min = min(240, max(1, $min));
            UserPref::putRaw($uid, UserPref::IDE_ALERT_MIN_KEY, (string) $min);
            return JsonResponse::success(['key' => $key, 'value' => $min], 'Preferência salva');
        }

        $on = (string) $request->input('enabled') === '1';
        if (!array_key_exists($key, UserPref::NOTIFY)) {
            return JsonResponse::error('Preferência inválida.', 422);
        }
        if ($key === 'notify_new_user' && !($this->user && $this->user->isAdmin())) {
            return JsonResponse::error('Sem permissão.', 403);
        }
        UserPref::set($uid, $key, $on);
        return JsonResponse::success(['key' => $key, 'enabled' => $on], 'Preferência salva');
    }

    /**
     * Configuração de e-mail (SMTP) — dentro de Configurações (somente admin).
     * As configurações têm precedência sobre o .env.
     */
    public function email(Request $request): Response
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->redirect('/settings/profile');
        }
        return $this->view('settings/email', [
            'title'     => 'Configuração de E-mail (SMTP)',
            'activeTab' => 'config',
            'cfg'       => [
                'host'         => Setting::get('mail_host', (string) env('MAIL_HOST', '')),
                'port'         => Setting::get('mail_port', (string) env('MAIL_PORT', '587')),
                'username'     => Setting::get('mail_username', (string) env('MAIL_USERNAME', '')),
                'encryption'   => Setting::get('mail_encryption', (string) env('MAIL_ENCRYPTION', 'tls')),
                'from_address' => Setting::get('mail_from_address', (string) env('MAIL_FROM_ADDRESS', '')),
                'from_name'    => Setting::get('mail_from_name', (string) env('MAIL_FROM_NAME', 'Ravi Developer')),
                'has_password' => Setting::get('mail_password_enc') !== null,
            ],
            'configured' => (new EmailService())->isConfigured(),
            'adminEmail' => $this->user->email ?? '',
        ]);
    }

    /** Salva a configuração de SMTP (senha criptografada; mantém a atual se em branco). */
    public function updateEmail(Request $request): Response
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->redirect('/settings/profile');
        }
        $host       = trim((string) $request->input('host'));
        $port       = (string) (int) $request->input('port');
        $username   = trim((string) $request->input('username'));
        $encryption = in_array($request->input('encryption'), ['tls', 'ssl', 'none'], true) ? $request->input('encryption') : 'tls';
        $fromAddr   = trim((string) $request->input('from_address'));
        $fromName   = trim((string) $request->input('from_name'));
        $password   = (string) $request->input('password');

        if ($host === '' || $username === '' || $fromAddr === '' || !filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
            return $this->redirectWithError('/settings/email', 'Host, usuário e um endereço "de" válido são obrigatórios.');
        }

        Setting::setMany([
            'mail_host'         => $host,
            'mail_port'         => $port,
            'mail_username'     => $username,
            'mail_encryption'   => $encryption,
            'mail_from_address' => $fromAddr,
            'mail_from_name'    => $fromName !== '' ? $fromName : 'Ravi Developer',
        ]);
        if ($password !== '') {
            $enc = new Encryption();
            Setting::set('mail_password_enc', $enc->encrypt($password));
        }
        return $this->redirectWithSuccess('/settings/email', 'Configuração de e-mail salva.');
    }

    /** Envia um e-mail de teste e reporta o resultado (JSON). */
    public function testEmail(Request $request): JsonResponse
    {
        if (!($this->user && $this->user->isAdmin())) {
            return $this->jsonError('Sem permissão.', 403);
        }
        $to = trim((string) $request->input('to')) ?: ($this->user->email ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError('Informe um e-mail de destino válido.', 422);
        }
        $mail = new EmailService();
        if (!$mail->isConfigured()) {
            return $this->jsonError('SMTP ainda não está configurado (faltam host, usuário e/ou senha).', 422);
        }
        $ok = $mail->send(
            $to,
            'Teste de e-mail — Ravi Developer',
            '<p>Este é um e-mail de teste do <strong>Ravi Developer</strong>. Se você recebeu, o SMTP está funcionando. ✅</p>',
            'Este e um e-mail de teste do Ravi Developer. Se voce recebeu, o SMTP esta funcionando.'
        );
        if ($ok) {
            return $this->json(['to' => $to], 'E-mail de teste enviado para ' . $to . '.');
        }
        return $this->jsonError('Falha ao enviar: ' . ($mail->getLastError() ?: 'erro desconhecido'), 502);
    }

    /**
     * Show profile settings page
     */
    public function profile(Request $request): Response
    {
        $user = $this->getCurrentUser();
        $userModel = User::find((int) $user['id']);

        $uid = (int) $user['id'];

        // Conta do Claude escolhida pelo usuário — só quando o admin liberou para ele.
        $licenseChoice  = \Ravi\Services\LicenseService::selfChoiceEnabled($uid);
        $licenseOptions = [];
        $globalLicense  = null;
        if ($licenseChoice) {
            foreach (\Ravi\Services\LicenseService::all() as $l) {
                if ($l['is_active']) {
                    $licenseOptions[] = ['id' => (int) $l['id'], 'name' => (string) $l['name']];
                }
            }
            $gid = \Ravi\Services\LicenseService::globalId('ide');
            if ($gid !== null) {
                $g = \Ravi\Services\LicenseService::find($gid);
                if ($g && $g['is_active']) {
                    $globalLicense = ['id' => (int) $g['id'], 'name' => (string) $g['name']];
                }
            }
        }

        return $this->view('settings/profile', [
            'title' => 'Perfil',
            'user' => $user,
            'twoFactorEnabled' => $userModel && !empty($userModel->totp_enabled),
            'avatarUrl' => $userModel ? $userModel->avatar_url : null,
            'activeTab' => 'profile',
            'licenseChoice'  => $licenseChoice,
            'licenseOptions' => $licenseOptions,
            'globalLicense'  => $globalLicense,
            'userLicenseId'  => $licenseChoice ? (\Ravi\Services\LicenseService::userIdFor($uid, 'ide') ?? 0) : 0,
            'models'        => \Ravi\Services\ModelService::MODELS,
            'userModelIde'        => \Ravi\Services\ModelService::userPref($uid, 'ide'),         // '' = herda global
            'userModelAutonomous' => \Ravi\Services\ModelService::userPref($uid, 'autonomous'),
            'userModelAgents'     => \Ravi\Services\ModelService::userPref($uid, \Ravi\Services\ModelService::SCOPE_AGENTS),
            'globalModelIde'        => \Ravi\Services\ModelService::globalDefault('ide'),
            'globalModelAutonomous' => \Ravi\Services\ModelService::globalDefault('autonomous'),
            'globalModelAgents'     => \Ravi\Services\ModelService::globalPref(\Ravi\Services\ModelService::SCOPE_AGENTS), // '' = herdar
        ]);
    }

    /** #7 — Upload da foto de perfil (JPG/PNG/WEBP/GIF, máx 4MB). */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $userModel = User::find((int) $this->user->id);
        if (!$userModel) {
            return $this->jsonError('Não autenticado.', 401);
        }
        $f = $_FILES['avatar'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== 0 || empty($f['tmp_name'])) {
            return $this->jsonError('Nenhum arquivo enviado.', 422);
        }
        if (($f['size'] ?? 0) > 4 * 1024 * 1024) {
            return $this->jsonError('Imagem muito grande (máx 4MB).', 422);
        }
        $info = @getimagesize($f['tmp_name']);
        $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
        $type = $info[2] ?? 0;
        if (!isset($map[$type])) {
            return $this->jsonError('Formato inválido (use JPG, PNG, WEBP ou GIF).', 422);
        }
        $dir = PUBLIC_PATH . '/uploads/avatars';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        foreach (glob($dir . '/' . $userModel->uuid . '.*') ?: [] as $old) {
            @unlink($old);
        }
        $dest = $dir . '/' . $userModel->uuid . '.' . $map[$type];
        if (!@move_uploaded_file($f['tmp_name'], $dest)) {
            return $this->jsonError('Falha ao salvar a imagem.', 500);
        }
        @chmod($dest, 0644);
        $url = '/uploads/avatars/' . $userModel->uuid . '.' . $map[$type] . '?v=' . time();
        $userModel->update(['avatar_url' => $url]);
        Session::set('user', $userModel->toSessionData());
        return $this->json(['avatar_url' => $url], 'Foto atualizada.');
    }

    /** #7 — Remove a foto de perfil. */
    public function removeAvatar(Request $request): JsonResponse
    {
        $userModel = User::find((int) $this->user->id);
        if (!$userModel) {
            return $this->jsonError('Não autenticado.', 401);
        }
        foreach (glob(PUBLIC_PATH . '/uploads/avatars/' . $userModel->uuid . '.*') ?: [] as $old) {
            @unlink($old);
        }
        $userModel->update(['avatar_url' => null]);
        Session::set('user', $userModel->toSessionData());
        return $this->json([], 'Foto removida.');
    }

    /** #7 — 2FA: gera segredo + otpauth URI (QR no cliente). */
    public function setup2fa(Request $request): JsonResponse
    {
        $userModel = User::find((int) $this->user->id);
        if (!$userModel) {
            return $this->jsonError('Não autenticado.', 401);
        }
        $svc = new \Ravi\Services\TotpService();
        $secret = $svc->generateSecret();
        Session::set('totp_setup_secret', $secret);
        return $this->json(['secret' => $secret, 'uri' => $svc->otpauthUri($secret, (string) $userModel->email)], 'Escaneie o QR e confirme.');
    }

    /** #7 — Confirma o código e ativa o 2FA. */
    public function enable2fa(Request $request): JsonResponse
    {
        $userModel = User::find((int) $this->user->id);
        if (!$userModel) {
            return $this->jsonError('Não autenticado.', 401);
        }
        $secret = (string) Session::get('totp_setup_secret');
        if ($secret === '') {
            return $this->jsonError('Inicie a configuração novamente.', 422);
        }
        if (!(new \Ravi\Services\TotpService())->verify($secret, (string) $request->input('code'))) {
            return $this->jsonError('Código inválido. Tente de novo.', 422);
        }
        $userModel->update(['totp_secret' => (new Encryption())->encrypt($secret), 'totp_enabled' => 1]);
        Session::remove('totp_setup_secret');
        return $this->json(['enabled' => true], '2FA ativado.');
    }

    /** #7 — Desativa o 2FA (exige a senha). */
    public function disable2fa(Request $request): JsonResponse
    {
        $userModel = User::find((int) $this->user->id);
        if (!$userModel) {
            return $this->jsonError('Não autenticado.', 401);
        }
        if (!password_verify((string) $request->input('password'), (string) $userModel->password_hash)) {
            return $this->jsonError('Senha incorreta.', 422);
        }
        $userModel->update(['totp_secret' => null, 'totp_enabled' => 0]);
        return $this->json(['enabled' => false], '2FA desativado.');
    }

    /** #7 — Lista sessões ativas + dispositivos confiáveis. */
    public function sessions(Request $request): JsonResponse
    {
        $uid = (int) $this->user->id;
        $db = \Ravi\Core\Database::getInstance();

        // O log de sessões PHP (user_sessions) é encanamento INTERNO e não é exibido aqui — só
        // limpamos o que expirou pra não crescer sem limite (o PHP regenera o session_id a cada login).
        try {
            $maxlife = (int) (ini_get('session.gc_maxlifetime') ?: 7200);
            $db->query("DELETE FROM user_sessions WHERE user_id = ? AND last_activity < ?", [$uid, time() - max($maxlife, 86400)]);
        } catch (\Throwable $e) {
        }

        // O painel mostra os APARELHOS CONFIÁVEIS (auth_devices): um por aparelho onde você escolheu
        // ficar conectado. Revogar um faz aquele aparelho pedir login+senha de novo no próximo acesso.
        $devSvc = new \Ravi\Services\AuthDeviceService();
        $curDev = $devSvc->currentDeviceId();
        $devices = array_map(function ($d) use ($curDev) {
            $last = $d['last_used_at'] ?? null;
            return [
                'type' => 'device', 'id' => (int) $d['id'],
                'label' => (string) ($d['label'] ?: 'Aparelho'),
                'ip' => (string) ($d['ip'] ?? ''),
                'last' => $last ? date('d/m/Y H:i', strtotime((string) $last)) : '',
                'current' => (int) $d['id'] === (int) $curDev,
            ];
        }, $devSvc->list($uid));

        return $this->json([
            'devices' => $devices,
            'current_trusted' => $curDev !== null,
            'current' => $devSvc->currentInfo(),
        ]);
    }

    /** #7 — Encerra uma sessão ou esquece um dispositivo. */
    public function revokeSession(Request $request): JsonResponse
    {
        $uid = (int) $this->user->id;
        $id  = (string) $request->input('id');
        if ((string) $request->input('type') === 'device') {
            (new \Ravi\Services\AuthDeviceService())->revoke($uid, (int) $id);
        } else {
            \Ravi\Core\Database::getInstance()->query("DELETE FROM user_sessions WHERE id = ? AND user_id = ?", [$id, $uid]);
        }
        return $this->json([], 'Desconectado.');
    }

    /** Confia neste navegador: passa a ficar conectado por muito tempo (sem re-logar). */
    public function trustDevice(Request $request): JsonResponse
    {
        (new \Ravi\Services\AuthDeviceService())->trustCurrent((int) $this->user->id);
        return $this->json([], 'Navegador confiável.');
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request): Response|JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $request->all();
        
        $validator = Validator::make($data, [
            'name' => 'required|min:2|max:100',
            'email' => 'required|email|max:255',
        ]);
        
        if ($validator->fails()) {
            if ($request->isAjax()) {
                return new JsonResponse(['success' => false, 'errors' => $validator->errors()], 422);
            }
            return $this->back()->withErrors($validator->errors());
        }
        
        // Check if email is already taken
        if ($data['email'] !== $user['email']) {
            $existingUser = User::findByEmail($data['email']);
            if ($existingUser) {
                $error = ['email' => ['Este email já está em uso.']];
                if ($request->isAjax()) {
                    return new JsonResponse(['success' => false, 'errors' => $error], 422);
                }
                return $this->back()->withErrors($error);
            }
        }
        
        // Update user
        $user = User::find($user['id']);
        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);
        
        // Update session
        Session::set('user', $user->toArray());
        
        if ($request->isAjax()) {
            return $this->json([
                'success' => true,
                'message' => 'Perfil atualizado com sucesso!'
            ]);
        }
        
        return $this->redirect('/settings/profile')->with('success', 'Perfil atualizado com sucesso!');
    }

    /**
     * Update password
     */
    public function updatePassword(Request $request): Response|JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $request->all();
        
        $validator = Validator::make($data, [
            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);
        
        if ($validator->fails()) {
            if ($request->isAjax()) {
                return new JsonResponse(['success' => false, 'errors' => $validator->errors()], 422);
            }
            return $this->back()->withErrors($validator->errors());
        }
        
        // Verify current password
        $userModel = User::find($user['id']);
        if (!password_verify($data['current_password'], $userModel->password_hash)) {
            $error = ['current_password' => ['Senha atual incorreta.']];
            if ($request->isAjax()) {
                return new JsonResponse(['success' => false, 'errors' => $error], 422);
            }
            return $this->back()->withErrors($error);
        }
        
        // Update password
        $userModel->update([
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12])
        ]);
        
        if ($request->isAjax()) {
            return $this->json([
                'success' => true,
                'message' => 'Senha alterada com sucesso!'
            ]);
        }
        
        return $this->redirect('/settings/profile')->with('success', 'Senha alterada com sucesso!');
    }

    /**
     * Show usage statistics
     */
    public function usage(Request $request): Response
    {
        $user = $this->getCurrentUser();
        
        // Get usage statistics
        $userModel = User::find($user['id']);
        $stats = $userModel->getUsageStats();

        // #2/#8 — custo por origem (origens ATIVAS) deste usuário + visibilidade.
        $svc = new \Ravi\Services\UsageService();
        return $this->view('settings/usage', [
            'title' => 'Uso',
            'user' => $user,
            'stats' => $stats,
            'usage' => $svc->breakdown((int) $user['id']),
            'usageFlags' => $svc->flags(),
            'canView' => canViewUsage(),
            'activeTab' => 'usage'
        ]);
    }

    /**
     * Delete account
     */
    public function deleteAccount(Request $request): Response|JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $request->all();
        
        // Verify password
        if (empty($data['password'])) {
            $error = ['password' => ['Confirme sua senha para excluir a conta.']];
            if ($request->isAjax()) {
                return new JsonResponse(['success' => false, 'errors' => $error], 422);
            }
            return $this->back()->withErrors($error);
        }
        
        $userModel = User::find($user['id']);
        if (!password_verify($data['password'], $userModel->password_hash)) {
            $error = ['password' => ['Senha incorreta.']];
            if ($request->isAjax()) {
                return new JsonResponse(['success' => false, 'errors' => $error], 422);
            }
            return $this->back()->withErrors($error);
        }
        
        // Delete user (cascade will handle related data)
        $userModel->delete();
        
        // Logout
        Session::destroy();
        
        if ($request->isAjax()) {
            return $this->json([
                'success' => true,
                'message' => 'Conta excluída com sucesso.',
                'redirect' => '/login'
            ]);
        }
        
        return $this->redirect('/login')->with('info', 'Sua conta foi excluída.');
    }
}