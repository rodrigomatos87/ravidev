<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;

/**
 * IDE no navegador (VS Code real via code-server) por projeto.
 *
 * Espelha o PreviewService: container persistente por projeto, porta publicada só em
 * 127.0.0.1, exposta pelo nginx em /ide/<token>/ (com auth_request validando a sessão Ravi
 * — ver public/ide-auth.php + /usr/local/bin/ravi-ide-nginx). O container usa a imagem
 * `ravi-ide:latest` (sandbox + code-server) com o workspace do projeto montado em /workspace,
 * e recebe o CLAUDE_CODE_OAUTH_TOKEN do sistema → o `claude` (terminal/extensão) já vem logado.
 */
class IdeService
{
    private string $image;
    private string $workspaceBase;
    private int $portMin;
    private int $portMax;
    private string $baseUrl;

    public function __construct()
    {
        $cfg = config('sandbox');
        $this->image         = (string) env('IDE_IMAGE', 'ravi-ide:latest');
        $this->workspaceBase = $cfg['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
        $this->portMin       = (int) env('IDE_PORT_MIN', 21100);
        $this->portMax       = (int) env('IDE_PORT_MAX', 21199);
        $this->baseUrl       = (string) config('urls.ide');
    }

    private function db(): Database
    {
        return Database::getInstance();
    }

    // ── Proxy de credencial da IDE (o token real NUNCA entra no container) ────────────────────
    /** URL do proxy no host (bridge do Docker). O container fala com ele via ANTHROPIC_BASE_URL. */
    private function proxyUrl(): string
    {
        return rtrim((string) env('IDE_PROXY_URL', 'http://172.17.0.1:8788'), '/');
    }

    /** URL do proxy GIT no host (bin/git-proxy.py) — o token do GitHub nunca entra no container. */
    private function gitProxyUrl(): string
    {
        return rtrim((string) env('GIT_PROXY_URL', 'http://172.17.0.1:8789'), '/');
    }

    /** Handle opaco no formato de um oat token (para o CLI aceitar e ficar em modo assinatura). */
    private static function makeHandle(): string
    {
        return 'sk-ant-oat01-' . rtrim(strtr(base64_encode(random_bytes(66)), '+/', '-_'), '=');
    }

    /** Amarra um handle novo a uma licença (na criação do container). */
    private function registerHandle(string $handle, int $licenseId, int $userId, string $container): void
    {
        try {
            $this->db()->execute(
                'INSERT INTO ide_proxy_handles (handle, license_id, user_id, container) VALUES (?, ?, ?, ?)',
                [$handle, $licenseId, $userId, $container]
            );
        } catch (\Throwable $e) {
            logger()->error('IdeService::registerHandle: ' . $e->getMessage());
        }
    }

    /**
     * Re-sincroniza a licença de um handle já existente (reuso/wake) → troca de licença sem recriar.
     * No-op para container ANTIGO (pré-proxy, token real): a linha não existe em ide_proxy_handles.
     */
    private function syncHandleLicense(string $handle, int $licenseId, int $userId, string $container): void
    {
        if (strpos($handle, 'sk-ant-oat01-') !== 0) {
            return;
        }
        try {
            $this->db()->execute(
                'UPDATE ide_proxy_handles SET license_id = ?, user_id = ?, container = ?, revoked_at = NULL WHERE handle = ?',
                [$licenseId, $userId, $container, $handle]
            );
        } catch (\Throwable $e) { /* best-effort */ }
    }

    /** Revoga os handles de um container (ao remover/recriar) → o proxy passa a recusá-los. */
    private function revokeHandlesFor(string $container): void
    {
        try {
            $this->db()->execute(
                'UPDATE ide_proxy_handles SET revoked_at = NOW() WHERE container = ? AND revoked_at IS NULL',
                [$container]
            );
        } catch (\Throwable $e) { /* best-effort */ }
    }

    /**
     * Reconcile do scheduler (1/min): reamarra os handles Claude já vivos (ide_proxy_handles) à
     * licença ATUAL do usuário. Sem isso, trocar de conta em Configurações → Licenças só valia para
     * a PRÓXIMA vez que a IDE fosse aberta (start() é o único outro lugar que re-sincroniza).
     * Não mexe em container nenhum: o proxy (bin/ide-proxy-token.php) resolve o token por
     * requisição a partir desta linha, então só o license_id precisa estar em dia (cache do proxy
     * em memória pega a mudança em até IDE_PROXY_CACHE_TTL segundos). Só IDEs em status running:
     * as pausadas/paradas são re-sincronizadas no wake pelo start().
     * @return array{checked:int,changed:int}
     */
    public function reconcileClaudeHandles(): array
    {
        $stats = ['checked' => 0, 'changed' => 0];
        $rows = $this->db()->fetchAll(
            'SELECT h.handle, h.user_id, h.container, h.license_id
               FROM ide_proxy_handles h
               JOIN ide_sessions s ON s.container = h.container
              WHERE h.revoked_at IS NULL AND s.status = ?',
            ['running']
        );
        foreach ($rows as $r) {
            $stats['checked']++;
            $userId = (int) $r['user_id'];
            $want = LicenseService::licenseIdForUser($userId, 'ide');
            if ($want === null || $want === (int) $r['license_id']) {
                continue; // sem licença configurada (mantém a última válida) ou já em dia
            }
            $this->syncHandleLicense((string) $r['handle'], $want, $userId, (string) $r['container']);
            $stats['changed']++;
        }
        return $stats;
    }

    /**
     * Sobe (ou reaproveita) a IDE de um projeto.
     * @param string $theme 'light'|'dark' — herdado do tema do Ravi (define o tema do VS Code).
     * @param ?array{remote:string, branch:string, name:string, email:string} $git Config git do
     *        ATOR (dono OU membro): remote/branch do projeto + identidade de quem abre. NENHUM
     *        token — o push/pull passa pelo proxy git do host (handle → token do DONO, validado
     *        por dono/membro em bin/git-proxy-token.php).
     * @return array{ok:bool, error?:string, token?:string, url?:string, port?:int}
     */
    public function start(string $projectUuid, int $userId, string $theme = 'dark', ?array $git = null): array
    {
        $theme = $theme === 'light' ? 'light' : 'dark';
        $ws = rtrim($this->workspaceBase, '/') . '/' . $projectUuid;
        $pm = \Ravi\Models\Project::findByUuid($projectUuid);
        $projectId = $pm ? (int) $pm->id : 0;
        if ($projectId === 0) {
            return ['ok' => false, 'error' => 'Projeto não encontrado.'];
        }
        // Projeto criado antes do primeiro chat ainda não tem o diretório: cria aqui
        // (mesmo padrão do chat), em vez de recusar a IDE.
        if (!is_dir($ws)) {
            @mkdir($ws, 0777, true);
            @chmod($ws, 0777);
            if (!is_dir($ws)) {
                return ['ok' => false, 'error' => 'Workspace do projeto não encontrado.'];
            }
        }

        // Uma IDE por (projeto, USUÁRIO): reaproveita a do PRÓPRIO usuário se rodando no mesmo
        // tema (e mesma presença de git); senão recria. Cada usuário tem o seu container.
        $wantGit = ($git && !empty($git['remote'])) ? '1' : '0';
        // Licença do Claude Code para ESTE usuário na IDE (override do usuário → global). O token REAL
        // NUNCA entra no container: resolvemos só o ID da licença e amarramos um HANDLE opaco a ela
        // (ide_proxy_handles). O container fala com o proxy do host (ANTHROPIC_BASE_URL), que troca
        // handle→token. Trocar de licença NÃO exige recriar: basta re-sincronizar o handle no banco.
        $licenseId = \Ravi\Services\LicenseService::licenseIdForUser($userId, 'ide');
        if ($licenseId === null) {
            return ['ok' => false, 'error' => 'Nenhuma licença do Claude configurada para a IDE. Configure em Configurações → Licenças.'];
        }

        // Reaproveita a IDE do PRÓPRIO usuário neste projeto. Pode estar RODANDO (reusa direto) ou
        // EM STANDBY (status='stopped' = pausada/parada, container ainda existe → DESCONGELA o MESMO
        // container, preservando abas/terminais/conversa). Só recria quando o tema/git mudou ou o
        // container sumiu. É isso que faz a IDE "continuar lá" quando o usuário fecha e volta depois.
        $existing = $this->getLatestByProjectUser($projectId, $userId);
        if ($existing) {
            $info = [];
            exec('docker inspect -f "{{.State.Status}}|{{.State.Running}}" ' . escapeshellarg($existing['container']) . ' 2>/dev/null', $info, $irc);
            $exists    = ($irc === 0);
            $parts     = explode('|', trim((string) ($info[0] ?? '')));
            $status    = $parts[0] ?? '';               // running | paused | exited | created
            $trulyRunning = ($status === 'running');    // 'paused' tem State.Running=true → não conta
            // O env (tema/git) existe também em container PARADO → lê sempre que o container existir.
            $curTheme = 'dark';
            $curGit = '0';
            $curModel = '';
            $curToken = '';
            $legacyGitToken = false; // container pré-proxy-git: tem o gho_ CRU no env → RECRIA (purga)
            if ($exists) {
                $envOut = [];
                exec('docker inspect -f "{{range .Config.Env}}{{println .}}{{end}}" ' . escapeshellarg($existing['container']) . ' 2>/dev/null', $envOut);
                foreach ($envOut as $line) {
                    if (strpos($line, 'IDE_THEME=') === 0) {
                        $curTheme = trim(substr($line, 10)) === 'light' ? 'light' : 'dark';
                    }
                    if (strpos($line, 'GIT_REMOTE_URL=') === 0) {
                        $curGit = trim(substr($line, 15)) !== '' ? '1' : '0';
                    }
                    if (strpos($line, 'GITHUB_TOKEN=') === 0 && trim(substr($line, 13)) !== '') {
                        $legacyGitToken = true;
                    }
                    if (strpos($line, 'ANTHROPIC_MODEL=') === 0) {
                        $curModel = trim(substr($line, 16));
                    }
                    if (strpos($line, 'CLAUDE_CODE_OAUTH_TOKEN=') === 0) {
                        $curToken = trim(substr($line, 24));
                    }
                }
            }
            $wantModel = \Ravi\Services\ModelService::cliModel(\Ravi\Services\ModelService::forUser($userId, 'ide'));
            // NÃO recria por imagem diferente: uma sessão VIVA jamais é destruída só porque a imagem
            // foi atualizada (o usuário perderia o trabalho). A imagem nova vale para IDEs novas (ou
            // quando o usuário fecha e reabre). Só recria por mudança de tema/git (config do usuário).
            // Troca de licença NÃO recria mais: o container guarda um HANDLE fixo; a licença é
            // resolvida no proxy por requisição. Recriamos só por tema/git/modelo. $curToken é o handle.
            $configOk = $exists && $curTheme === $theme && $curGit === $wantGit && $curModel === $wantModel
                && !$legacyGitToken; // pré-proxy-git → recria para expulsar o token cru do container
            if ($configOk && $trulyRunning) {
                $this->syncHandleLicense($curToken, $licenseId, $userId, (string) $existing['container']);
                $this->syncChatgpt((string) $existing['container'], $userId);
                $this->ensureSshAgent((string) $existing['container'], $pm, $userId, false);
                $this->touch($existing['token']); // já no ar com a config certa → reusa direto
                return ['ok' => true, 'token' => $existing['token'], 'port' => (int) $existing['port'], 'url' => $this->baseUrl . '/' . $existing['token'] . '/'];
            }
            if ($configOk && !$trulyRunning) {
                // EM STANDBY (pausada) ou parada, com a config certa → DESCONGELA/religa o MESMO
                // container (mesmo token/porta/estado) em vez de recriar. É o "volta do standby".
                if ($this->wake((string) $existing['container'])) {
                    $this->syncHandleLicense($curToken, $licenseId, $userId, (string) $existing['container']);
                    $this->syncChatgpt((string) $existing['container'], $userId);
                    $this->ensureSshAgent((string) $existing['container'], $pm, $userId, true);
                    $this->db()->execute("UPDATE ide_sessions SET status = 'running', last_used_at = CURRENT_TIMESTAMP WHERE token = ?", [$existing['token']]);
                    $this->regenNginx();
                    $this->waitHttp((int) $existing['port'], 30);
                    return ['ok' => true, 'token' => $existing['token'], 'port' => (int) $existing['port'], 'url' => $this->baseUrl . '/' . $existing['token'] . '/'];
                }
                @exec('docker rm -f ' . escapeshellarg($existing['container']) . ' 2>&1'); // falhou (porta/corrompido) → recria
            }
        }
        // Limpa QUALQUER sessão deste usuário neste projeto (running ou pausada/órfã) antes de
        // recriar. ANTES, PRESERVA a memória do Claude copiando do container (funciona mesmo parado)
        // para o diretório do host — nada de memória se perde.
        $memDir = (new \Ravi\Services\ProjectContextService())->memoryDir($projectUuid);
        foreach ($this->db()->fetchAll("SELECT token, container FROM ide_sessions WHERE project_id = ? AND user_id = ?", [$projectId, $userId]) as $r) {
            @exec('docker cp ' . escapeshellarg((string) $r['container'] . ':/home/sandbox' . \Ravi\Services\ProjectContextService::CONTAINER_MEMORY_PATH . '/.')
                . ' ' . escapeshellarg($memDir . '/') . ' 2>/dev/null');
            $this->stop((string) $r['token']); // (também mata o ssh-agent do container)
            $this->revokeHandlesFor((string) $r['container']); // handle do container morto não serve mais
            \Ravi\Core\Modulos::gancho('ide.container.removido', (string) $r['container']);
        }

        $token     = bin2hex(random_bytes(16));
        $container = 'ravi-ide-' . $token;
        $port      = $this->allocatePort();
        if ($port === null) {
            return ['ok' => false, 'error' => 'Sem portas livres para a IDE no momento.'];
        }

        // Permissões do workspace: o code-server roda como root no container, mas garantimos
        // que os arquivos fiquem editáveis (já são 0777 no fluxo do Maestro).
        @exec('chmod -R a+rwX ' . escapeshellarg($ws) . ' 2>/dev/null');

        // PERSISTÊNCIA por (usuário, projeto): o user-data do code-server (extensões instaladas à
        // mão, configurações, abas abertas) fica num volume no host → sobrevive a recriações do
        // container (troca de tema, rebuild, reboot, timeout). As pré-instaladas são semeadas pelo
        // entrypoint a partir de /opt/ide-seed.
        $dataDir = dirname(rtrim($this->workspaceBase, '/')) . '/ide-data/' . $userId . '-' . $projectId;
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0777, true);
        }
        @chmod($dataDir, 0777);

        // $claudeToken já foi resolvido no topo (licença deste usuário para a IDE).
        // Limites configuráveis pelo admin (Configurações → Recursos dos containers), com fallback
        // para o .env. Aplica-se a IDEs abertas a partir de agora (não recria os containers ativos).
        $mem = \Ravi\Models\Setting::get('ide_memory', (string) env('IDE_MEMORY', '2g'));
        $cpu = \Ravi\Models\Setting::get('ide_cpu', (string) env('IDE_CPU', '2'));
        // Modelo padrão da IDE: resolvido por usuário (pref própria → padrão global → .env). O usuário
        // pode trocar ao vivo na sessão com /model; mudar AQUI redefine o que a IDE ABRE configurado.
        $rawModel = \Ravi\Services\ModelService::forUser($userId, 'ide');
        $model = \Ravi\Services\ModelService::cliModel($rawModel);
        // Lineup COMPLETA do Ravi no /model picker (extensão + terminal): chave `modelPicker` do
        // ~/.claude/settings.json (CLI >= 2.1.258, honrada em user settings), semeada pelo entrypoint
        // a cada boot a partir deste env (base64 p/ atravessar o docker run sem dor de quoting).
        // Substitui o ANTHROPIC_CUSTOM_MODEL_OPTION antigo (uma entrada só, e suprimida quando o
        // padrão era o próprio Fable).
        $pickerB64 = base64_encode(json_encode(\Ravi\Services\ModelService::pickerOptions()));

        // HANDLE opaco amarrado à licença resolvida. O token REAL fica no host; o proxy troca
        // handle→token. Formato sk-ant-oat01-… para o CLI aceitar e ficar em modo assinatura.
        $handle = self::makeHandle();
        $this->registerHandle($handle, $licenseId, $userId, $container);

        // Módulos opcionais (ex.: ionCube): cada um devolve env e volumes extras para este container.
        // Sem módulos, nada entra. Os fragmentos de entrypoint montados em /etc/ravi/entrypoint.d
        // rodam no boot do container (docker/ide-entrypoint.sh).
        $extrasModulos = \Ravi\Core\Modulos::gancho('ide.container.env', [
            'workspace'    => $ws,
            'user_id'      => $userId,
            'container'    => $container,
            'project_uuid' => $projectUuid,
        ]);

        // ssh-agent no HOST para este container (chave privada NUNCA entra no container): o
        // diretório com o agent.sock é montado em /home/sandbox/.ravi-ssh (criado ANTES do run).
        $sshAgentDir = $this->ensureSshAgent($container, $pm, $userId, true);

        $cmd = 'docker run -d'
            . ' --name ' . escapeshellarg($container)
            . ' --restart unless-stopped'
            . ' -p 127.0.0.1:' . $port . ':8080'
            . ' -v ' . escapeshellarg($ws) . ':/workspace'
            . ' -v ' . escapeshellarg($dataDir) . ':/home/sandbox/.local/share/code-server'
            // Só o HANDLE entra no container (nunca o token). O `claude` fala com o proxy do host.
            . ' -e ' . escapeshellarg('CLAUDE_CODE_OAUTH_TOKEN=' . $handle)
            . ' -e ' . escapeshellarg('ANTHROPIC_BASE_URL=' . $this->proxyUrl())
            . ' -e ' . escapeshellarg('CLAUDE_CODE_MODEL=' . $model)
            // Modelo PADRÃO do Claude Code (extensão + terminal): ANTHROPIC_MODEL é o que o claude lê
            // como default → a IDE abre no modelo escolhido (Opus 4.8 mais capaz; Sonnet 4.6 mais
            // econômico — ambos com até 1M de contexto nos planos pagos, Sonnet exige usage credits).
            . ' -e ' . escapeshellarg('ANTHROPIC_MODEL=' . $model)
            // Lineup completa do seletor no /model picker (o entrypoint escreve `modelPicker` no
            // ~/.claude/settings.json a partir deste env).
            . ' -e ' . escapeshellarg('RAVI_MODEL_PICKER=' . $pickerB64)
            // Ambiente é um sandbox isolado e versionado por git → o Claude tem permissão TOTAL.
            // IS_SANDBOX=1 libera o bypassPermissions mesmo rodando como root (senão o claude bloqueia
            // "--dangerously-skip-permissions cannot be used with root"). Pareado com
            // claudeCode.initialPermissionMode=bypassPermissions no settings.json (entrypoint).
            . ' -e ' . escapeshellarg('IS_SANDBOX=1')
            // #3 — Idioma da IDE: português quando o sistema está em PT; senão inglês (padrão do
            // VS Code; o usuário pode instalar um pacote de idioma). code-server só traz pt-br/en nativos.
            . ' -e ' . escapeshellarg('IDE_LOCALE=' . (\Ravi\Core\Lang::active() === 'pt' ? 'pt-br' : 'en'))
            . ' -e ' . escapeshellarg('IDE_THEME=' . $theme)
            // Socket do ssh-agent do host (ravi-ssh/SFTP assinam por aqui; a chave fica no host).
            . ' -v ' . escapeshellarg($sshAgentDir . ':' . \Ravi\Services\IdeSshAgentService::CONTAINER_DIR);
        foreach ($extrasModulos as $extra) {
            foreach ((array) ($extra['env'] ?? []) as $k => $v) {
                $cmd .= ' -e ' . escapeshellarg($k . '=' . $v);
            }
            foreach ((array) ($extra['volumes'] ?? []) as $host => $destino) {
                $cmd .= ' -v ' . escapeshellarg($host . ':' . $destino);
            }
        }
        // Git para QUALQUER ator do projeto (dono e membros) — SEM token: o entrypoint aponta
        // github.com para o proxy git do host, que troca handle→token do dono (validando membro).
        // A identidade (autor dos commits) é a do ATOR — membro aparece como ele mesmo no histórico.
        if ($wantGit === '1') {
            $cmd .= ' -e ' . escapeshellarg('GIT_USER_NAME=' . (string) (($git['name'] ?? '') ?: 'Ravi Developer'))
                 .  ' -e ' . escapeshellarg('GIT_USER_EMAIL=' . (string) (($git['email'] ?? '') ?: (string) config('urls.noreply')))
                 .  ' -e ' . escapeshellarg('GIT_REMOTE_URL=' . (string) $git['remote'])
                 .  ' -e ' . escapeshellarg('GIT_BRANCH=' . (string) (($git['branch'] ?? '') ?: 'main'))
                 .  ' -e ' . escapeshellarg('RAVI_GIT_PROXY=' . $this->gitProxyUrl());
        }
        // Contexto permanente do projeto (memórias + fontes externas SSH) para o ATOR (este usuário).
        // O entrypoint decodifica em ~/.claude/CLAUDE.md e ~/.ravi/servers.json. Sftp.json no workspace.
        try {
            $ctx = new \Ravi\Services\ProjectContextService();
            if ($pm) {
                // IDE: servers.json SEM chave privada (o ssh-agent do host assina; só a pública vai).
                foreach ($ctx->injectionEnv($pm, $userId, true) as $k => $v) {
                    $cmd .= ' -e ' . escapeshellarg($k . '=' . $v);
                }
                $ctx->writeSftpConfig($pm, $ws);
            }
            // CONVERSAS do Claude Code PERSISTENTES: monta o diretório do host em
            // ~/.claude/projects/-workspace (transcripts *.jsonl do `claude` rodando em /workspace).
            // Sem isto, o histórico do Claude Code some quando o container da IDE é recriado.
            $convDir = $ctx->conversationsDir($projectUuid, $userId);
            $cmd .= ' -v ' . escapeshellarg($convDir . ':/home/sandbox' . \Ravi\Services\ProjectContextService::CONTAINER_PROJECT_PATH);
            // Memória do Claude Code COMPARTILHADA (mesma do chat) + PERSISTENTE: monta o diretório
            // do host em ~/.claude/projects/-workspace/memory (mount ANINHADO dentro do de conversas
            // acima). Assim o que o Claude salva na IDE não se perde e é a MESMA memória usada no chat.
            $memDir = $ctx->memoryDir($projectUuid);
            $cmd .= ' -v ' . escapeshellarg($memDir . ':/home/sandbox' . \Ravi\Services\ProjectContextService::CONTAINER_MEMORY_PATH);
            // #3 — Projetos LIGADOS: monta o workspace de cada projeto-alvo (ativo e acessível ao
            // ator) em /linked/<slug>, FORA do /workspace (não aparece na árvore da IDE). :ro = leitura.
            if ($pm) {
                foreach ($ctx->linkedMounts($pm, $userId) as $m) {
                    $cmd .= ' -v ' . escapeshellarg($m['host'] . ':' . $m['container'] . ($m['ro'] ? ':ro' : ':rw'));
                }
            }
        } catch (\Throwable $e) {
            // contexto nunca impede abrir a IDE
        }

        $cmd .= ' --memory ' . escapeshellarg($mem)
            . ' --cpus ' . escapeshellarg($cpu)
            . ' ' . escapeshellarg($this->image)
            . ' 2>&1';
        exec($cmd, $runOut, $rc);
        if ($rc !== 0) {
            return ['ok' => false, 'error' => 'Falha ao iniciar o container da IDE: ' . trim(implode("\n", $runOut))];
        }

        $this->db()->execute(
            "INSERT INTO ide_sessions (project_id, user_id, token, container, port, status, last_used_at)
             VALUES (?, ?, ?, ?, ?, 'running', CURRENT_TIMESTAMP)",
            [$projectId, $userId, $token, $container, $port]
        );

        // Licença do ChatGPT (Codex) deste usuário: o auth.json entra por docker exec, NUNCA pelo
        // env do container (não aparece no inspect). O scheduler mantém em dia depois.
        $this->syncChatgpt($container, $userId);

        $this->regenNginx();
        $this->waitHttp($port, 30);

        return ['ok' => true, 'token' => $token, 'port' => $port, 'url' => $this->baseUrl . '/' . $token . '/'];
    }

    /**
     * Coloca na IDE (rodando) a licença do ChatGPT resolvida para o usuário (override → global), ou
     * remove a que nós pusemos quando não há licença. Best-effort: nunca impede abrir a IDE.
     */
    private function syncChatgpt(string $container, int $userId): void
    {
        try {
            \Ravi\Services\ChatgptLicenseService::pushToContainer(
                $container,
                \Ravi\Services\ChatgptLicenseService::licenseIdForUser($userId)
            );
        } catch (\Throwable $e) {
            logger()->warning('IdeService::syncChatgpt: ' . $e->getMessage());
        }
    }

    /**
     * DESTRÓI a IDE (remove o container) e APAGA o registro. Caminho de remoção REAL: botão × de
     * fechar, recriação interna, exclusão de projeto e o reap de pausadas abandonadas. Um container
     * destruído nunca pode parecer retomável, por isso apagamos a linha (vs. pausar, ver pauseIdle).
     */
    public function stop(string $token): bool
    {
        $row = $this->db()->fetch("SELECT * FROM ide_sessions WHERE token = ?", [$token]);
        if (!$row) {
            return false;
        }
        exec('docker rm -f ' . escapeshellarg($row['container']) . ' 2>&1');
        try {
            (new \Ravi\Services\IdeSshAgentService())->kill((string) $row['container']);
        } catch (\Throwable $e) { /* best-effort */ }
        $this->db()->execute("DELETE FROM ide_sessions WHERE token = ?", [$token]);
        $this->regenNginx();
        return true;
    }

    /** Garante o ssh-agent do container (chaves no HOST). Devolve o dir a montar; nunca lança. */
    private function ensureSshAgent(string $container, ?\Ravi\Models\Project $project, int $userId, bool $reloadKeys): string
    {
        $svc = new \Ravi\Services\IdeSshAgentService();
        try {
            if ($project) {
                return $svc->ensure($container, $project, $userId, $reloadKeys);
            }
        } catch (\Throwable $e) {
            logger()->error('IdeService::ensureSshAgent: ' . $e->getMessage());
        }
        $dir = $svc->hostDir($container);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * STANDBY da IDE ociosa: CONGELA o container (docker pause) — como suspender o computador. Todos
     * os processos (Claude no terminal, builds, code-server) ficam parados na MEMÓRIA, sem gastar
     * CPU; ao voltar (unpause no resume), descongelam EXATAMENTE onde estavam: mesmas abas, mesmos
     * terminais, mesma conversa, bit a bit. NÃO mata processo nem remove o container.
     * (Trade-off: o pausado segura a RAM dele; um reboot do host perde o estado congelado, mas as
     * abas voltam do volume ide-data e a conversa do transcript — degradação suave, não perda.)
     */
    public function pauseIdle(string $token): bool
    {
        $row = $this->db()->fetch("SELECT container, project_id FROM ide_sessions WHERE token = ?", [$token]);
        if (!$row) {
            return false;
        }
        exec('docker pause ' . escapeshellarg($row['container']) . ' 2>&1', $o, $rc);
        if ($rc !== 0) {
            // Não deu pra congelar (ex.: já parado) → tenta parar de vez, ainda resumível via start.
            @exec('docker stop -t 10 ' . escapeshellarg($row['container']) . ' 2>&1');
        }
        $this->db()->execute("UPDATE ide_sessions SET status = 'stopped', paused_at = CURRENT_TIMESTAMP WHERE token = ?", [$token]);
        $this->regenNginx();
        // Auto-sync ao PAUSAR (standby): empurra o trabalho feito na IDE antes de congelar, se ligado.
        try {
            if (!empty($row['project_id'])) {
                $project = \Ravi\Models\Project::find((int) $row['project_id']);
                if ($project && $project->git_auto_sync) {
                    \Ravi\Services\GitSyncService::enqueueSync($project, null, 'Sincronização automática ao pausar a IDE');
                }
            }
        } catch (\Throwable $e) {
            // best-effort: nunca falha a pausa por causa do sync
        }
        return true;
    }

    /**
     * Tira a IDE do STANDBY: descongela (docker unpause) se estava pausada, ou religa (docker start)
     * se estava parada (ex.: caiu para 'stopped' por reboot/fallback). Devolve true se ficou no ar.
     */
    private function wake(string $container): bool
    {
        $status = trim((string) shell_exec('docker inspect -f "{{.State.Status}}" ' . escapeshellarg($container) . ' 2>/dev/null'));
        if ($status === 'running') {
            return true;
        }
        if ($status === 'paused') {
            exec('docker unpause ' . escapeshellarg($container) . ' 2>&1', $o, $rc);
            return $rc === 0;
        }
        // exited / created → religa (perde os processos congelados, mas restaura abas do disco)
        exec('docker start ' . escapeshellarg($container) . ' 2>&1', $o, $rc);
        return $rc === 0;
    }

    /** Marca atividade (evita expirar enquanto em uso). */
    public function touch(string $token): void
    {
        $this->db()->execute("UPDATE ide_sessions SET last_used_at = CURRENT_TIMESTAMP WHERE token = ?", [$token]);
    }

    /**
     * Marca atividade em TODAS as IDEs rodando do usuário. Chamado pelo poll da página /ide enquanto
     * ela está VISÍVEL: página aberta na frente do usuário = IDEs em uso, mesmo sem o Claude rodando.
     * Sem isso, só "abrir" e "Claude trabalhando" contavam como atividade, e o standby (cleanupIdle)
     * congelava as abas NÃO ativas depois de IDE_IDLE_MINUTES com a página aberta — e a aba sumia.
     */
    public function touchUser(int $userId): void
    {
        $this->db()->execute(
            "UPDATE ide_sessions SET last_used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND status = 'running'",
            [$userId]
        );
    }

    /** IDE ativa de um (projeto, usuário) — cada usuário tem a sua. */
    public function getByProjectUser(int $projectId, int $userId): ?array
    {
        return $this->db()->fetch(
            "SELECT * FROM ide_sessions WHERE project_id = ? AND user_id = ? AND status = 'running' ORDER BY id DESC LIMIT 1",
            [$projectId, $userId]
        );
    }

    /** Última IDE de um (projeto, usuário) INDEPENDENTE de status — pega também as PAUSADAS p/ religar. */
    public function getLatestByProjectUser(int $projectId, int $userId): ?array
    {
        return $this->db()->fetch(
            "SELECT * FROM ide_sessions WHERE project_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1",
            [$projectId, $userId]
        );
    }

    /** Todas as IDEs ativas (para o nginx e o cleanup). */
    public function listActive(): array
    {
        return $this->db()->fetchAll("SELECT * FROM ide_sessions WHERE status = 'running'");
    }

    /**
     * Tira do STANDBY todas as IDEs pausadas do usuário (descongela/religa o MESMO container). Usado
     * ao abrir/voltar para o /ide: o usuário que deixou IDEs abertas reencontra-as ativas. Container
     * sumido → apaga a linha. @return string[] uuids dos PROJETOS que foram retomados (p/ a UI recarregar só esses).
     */
    public function resumeUserPaused(int $userId): array
    {
        $resumed = [];
        $changed = false;
        foreach ($this->db()->fetchAll(
            "SELECT i.token, i.container, i.project_id, p.uuid FROM ide_sessions i INNER JOIN projects p ON p.id = i.project_id
             WHERE i.user_id = ? AND i.status = 'stopped'",
            [$userId]
        ) as $r) {
            if ($this->wake((string) $r['container'])) {
                $this->db()->execute("UPDATE ide_sessions SET status = 'running', last_used_at = CURRENT_TIMESTAMP WHERE token = ?", [$r['token']]);
                $this->ensureSshAgent((string) $r['container'], \Ravi\Models\Project::find((int) $r['project_id']), $userId, false);
                $resumed[] = (string) $r['uuid'];
            } else {
                @exec('docker rm -f ' . escapeshellarg((string) $r['container']) . ' 2>&1');
                try {
                    (new \Ravi\Services\IdeSshAgentService())->kill((string) $r['container']);
                } catch (\Throwable $e) { /* best-effort */ }
                $this->db()->execute("DELETE FROM ide_sessions WHERE token = ?", [$r['token']]);
            }
            $changed = true;
        }
        if ($changed) {
            $this->regenNginx();
        }
        return $resumed;
    }

    /**
     * Soma TEMPO TRABALHADO reportado pela página da IDE (cliente). Enquanto a bolinha verde
     * (working) aparece, a IDE manda incrementos (~1s) por projeto; aqui validamos que é uma sessão
     * rodando DESTE usuário e somamos ao acumulado (ide_work_seconds). Chave = uuid do PROJETO.
     * @param array<string,int|string> $deltas projectUuid => segundos
     * @return array<string,int> projectUuid => total atualizado (segundos)
     */
    public function addWorkSeconds(int $userId, array $deltas): array
    {
        $out = [];
        foreach ($deltas as $uuid => $secs) {
            $secs = (int) $secs;
            if ($secs < 1) {
                continue;
            }
            if ($secs > 15) {
                $secs = 15; // sanidade: o cliente manda ~1/s; ignora saltos absurdos
            }
            $row = $this->db()->fetch(
                "SELECT i.project_id FROM ide_sessions i INNER JOIN projects p ON p.id = i.project_id
                 WHERE p.uuid = ? AND i.user_id = ? AND i.status = 'running' LIMIT 1",
                [(string) $uuid, $userId]
            );
            if (!$row) {
                continue;
            }
            $pid = (int) $row['project_id'];
            $this->db()->execute(
                "INSERT INTO ide_work_seconds (project_id, user_id, seconds) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE seconds = seconds + VALUES(seconds)",
                [$pid, $userId, $secs]
            );
            $tot = $this->db()->fetch(
                "SELECT seconds FROM ide_work_seconds WHERE project_id = ? AND user_id = ?",
                [$pid, $userId]
            );
            $out[(string) $uuid] = (int) ($tot['seconds'] ?? 0);
        }
        return $out;
    }

    /** Tempo trabalhado (segundos) por usuário num projeto. @return array<int,int> user_id => segundos */
    public function workSecondsByUser(int $projectId): array
    {
        $out = [];
        try {
            foreach ($this->db()->fetchAll(
                "SELECT user_id, seconds FROM ide_work_seconds WHERE project_id = ?",
                [$projectId]
            ) as $r) {
                $out[(int) $r['user_id']] = (int) $r['seconds'];
            }
        } catch (\Throwable $e) {
            // tabela ausente / banco indisponível → sem dados de tempo
        }
        return $out;
    }

    /**
     * IDEs abertas do usuário (para a página /ide com abas): nome do projeto, uuid, url e se está
     * "processando" (Claude/build rodando → bolinha verde).
     * @return array<int,array{uuid:string,name:string,token:string,url:string,busy:bool}>
     */
    public function userSessions(int $userId): array
    {
        $rows = $this->db()->fetchAll(
            "SELECT i.token, i.container, i.project_id, p.uuid, p.name
             FROM ide_sessions i INNER JOIN projects p ON p.id = i.project_id
             WHERE i.user_id = ? AND i.status = 'running' ORDER BY i.id ASC",
            [$userId]
        );
        // Estado de TODOS os containers num único lote (working/waiting/idle).
        $states = $this->containerStates(array_map(fn($r) => (string) $r['container'], $rows));
        // Tempo trabalhado já acumulado por projeto (para o contador no topo começar do total salvo).
        $work = [];
        try {
            foreach ($this->db()->fetchAll("SELECT project_id, seconds FROM ide_work_seconds WHERE user_id = ?", [$userId]) as $w) {
                $work[(int) $w['project_id']] = (int) $w['seconds'];
            }
        } catch (\Throwable $e) {
            // tabela ausente → contador começa do zero
        }
        $out = [];
        foreach ($rows as $r) {
            $state = $states[(string) $r['container']] ?? 'idle';
            $out[] = [
                'uuid' => (string) $r['uuid'],
                'name' => (string) $r['name'],
                'token' => (string) $r['token'],
                'url'  => $this->baseUrl . '/' . $r['token'] . '/',
                'state' => $state,                  // 'working'|'waiting'|'idle' → bolinha verde/azul/cinza
                'busy' => $state !== 'idle',         // compat: aceso quando working OU waiting
                'work_seconds' => (int) ($work[(int) $r['project_id']] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Troca o tema do VS Code AO VIVO (sem recriar o container): reescreve o colorTheme no
     * settings.json dentro do container. O VS Code observa o arquivo e aplica na hora (a UI só
     * recarrega o iframe para garantir). Não perde processos (Claude/terminais seguem rodando).
     */
    public function setThemeLive(string $token, string $theme): bool
    {
        $row = $this->db()->fetch("SELECT container FROM ide_sessions WHERE token = ? AND status = 'running'", [$token]);
        if (!$row) {
            return false;
        }
        $color = $theme === 'light' ? 'Default Light Modern' : 'Dracula Theme';
        // Atualiza só a chave colorTheme via node (preserva o resto do settings.json).
        $node = 'const fs=require("fs"); const f=process.env.HOME+"/.local/share/code-server/User/settings.json"; '
              . 'let s={}; try{ s=JSON.parse(fs.readFileSync(f,"utf8")); }catch(e){} '
              . 's["workbench.colorTheme"]=process.argv[1]; fs.writeFileSync(f, JSON.stringify(s,null,2));';
        $cmd = 'timeout 8 docker exec ' . escapeshellarg($row['container'])
            . ' bash -lc ' . escapeshellarg('node -e ' . escapeshellarg($node) . ' ' . escapeshellarg($color))
            . ' 2>&1';
        exec($cmd, $o, $rc);
        return $rc === 0;
    }

    /**
     * Gerencia IDEs ociosas em DOIS estágios, sem destruir trabalho:
     *  1) PAUSA (docker pause) as rodando ociosas há mais de $minutes — MAS NÃO as que estão
     *     TRABALHANDO (Claude/build). "Ociosa" = sem página /ide visível (o poll dela marca uso via
     *     touchUser) e sem Claude rodando. Pausar preserva tudo; reabrir religa o mesmo container.
     *  2) REMOVE de vez (libera disco) as PAUSADAS há mais de $reapDays. Os volumes do host
     *     (workspace, ide-data, conversas, memória) são do PROJETO e NÃO são apagados aqui.
     * @return array{paused:int,reaped:int}
     */
    public function cleanupIdle(int $minutes = 120, int $reapDays = 7): array
    {
        $paused = 0;
        foreach ($this->db()->fetchAll(
            "SELECT token, container FROM ide_sessions WHERE status = 'running' AND last_used_at < (NOW() - INTERVAL ? MINUTE)",
            [$minutes]
        ) as $r) {
            if ($this->isContainerBusy((string) $r['container'])) {
                $this->touch((string) $r['token']); // está trabalhando → conta como atividade, adia
                continue;
            }
            $this->pauseIdle((string) $r['token']);
            $paused++;
        }
        $reaped = 0;
        foreach ($this->db()->fetchAll(
            "SELECT token FROM ide_sessions WHERE status = 'stopped' AND paused_at < (NOW() - INTERVAL ? DAY)",
            [$reapDays]
        ) as $r) {
            $this->stop((string) $r['token']); // destrói container abandonado + apaga linha
            $reaped++;
        }
        return ['paused' => $paused, 'reaped' => $reaped];
    }

    /** O container está TRABALHANDO? (atalho de 1 container; usa a medição em lote.) */
    private function isContainerBusy(string $container): bool
    {
        if ($container === '') {
            return false;
        }
        $r = $this->busyContainers([$container]);
        return (bool) ($r[$container] ?? false);
    }

    /**
     * Estado de TRABALHO de cada container — TRÊS valores p/ a bolinha do /ide:
     *   'working' (verde, processando) | 'waiting' (AZUL, pausado aguardando VOCÊ) | 'idle' (cinza).
     *
     * Sinal PRINCIPAL = PRECISO (Claude): o wrapper do binário `claude` (docker/claude-wrapper.js,
     * via /tmp/claude-state/<pid>, instalado por wrap-claude.sh) marca "working" ao receber input
     * stream-json, "waiting" quando o claude pede permissão/pergunta (control_request can_use_tool)
     * e "idle" no evento {"type":"result"}. Lemos esse arquivo — então a bolinha NÃO pisca durante
     * a espera da API (CPU ~0, mas o estado segue "working") e fica AZUL quando o claude precisa de
     * você (mesmo com a aba fechada / em outra IDE).
     *
     * FALLBACK (só p/ o que NÃO passa pelo claude — builds npm/webpack/etc.): delta de TEMPO DE
     * CPU dos processos de build, lendo /proc no HOST. Só medimos CPU p/ containers que o sinal do
     * Claude não marcou — evita o sleep de 0,7s quando já se sabe o estado.
     *
     * @param string[] $containers
     * @return array<string,string> container => 'working'|'waiting'|'idle'
     */
    public function containerStates(array $containers): array
    {
        $result = [];
        foreach ($containers as $c) {
            $result[$c] = 'idle';
        }
        if (empty($containers)) {
            return $result;
        }

        // CRÍTICO: só processa containers REALMENTE rodando. `docker exec`/`docker top` num container
        // PAUSADO (standby) ou parado PENDURA o processo PARA SEMPRE — e como /ide/sessions faz poll a
        // cada 4s, isso esgota os workers do php-fpm e derruba o site inteiro com 504. Pausado/parado
        // = 'idle' por definição (não está trabalhando). Filtra ANTES de qualquer exec.
        $running = $this->runningOnly($containers);
        if (empty($running)) {
            return $result;
        }

        // 1) Sinal PRECISO do Claude (waiting tem prioridade sobre working).
        $needBuild = [];
        foreach ($running as $c) {
            $s = $this->claudeState($c);
            if ($s === 'waiting' || $s === 'working') {
                $result[$c] = $s;
            } else {
                $needBuild[] = $c;
            }
        }

        // 2) FALLBACK p/ builds (npm/webpack/etc.): delta de CPU, só p/ quem o Claude não marcou.
        foreach ($this->buildCpuBusy($needBuild) as $c => $busy) {
            if ($busy) {
                $result[$c] = 'working';
            }
        }
        return $result;
    }

    /**
     * Mede, EM LOTE, quais containers estão OCUPADOS (working OU waiting — ambos contam como
     * "não pode matar"/"bolinha acesa"). Mantido para cleanupIdle e compatibilidade.
     * @param string[] $containers
     * @return array<string,bool>
     */
    public function busyContainers(array $containers): array
    {
        $result = [];
        foreach ($this->containerStates($containers) as $c => $state) {
            $result[$c] = ($state !== 'idle');
        }
        return $result;
    }

    /**
     * Dentre os containers dados, retorna SÓ os que estão com State.Status=running — os únicos em
     * que `docker exec`/`docker top` é seguro (num PAUSADO o exec pendura pra sempre). Batch + timeout.
     * @param string[] $containers
     * @return string[]
     */
    private function runningOnly(array $containers): array
    {
        if (empty($containers)) {
            return [];
        }
        $args = implode(' ', array_map('escapeshellarg', $containers));
        $out = [];
        @exec('timeout 6 docker inspect -f "{{.Name}} {{.State.Status}}" ' . $args . ' 2>/dev/null', $out);
        $running = [];
        foreach ($out as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 2 && $parts[1] === 'running') {
                $running[ltrim($parts[0], '/')] = true;
            }
        }
        // Preserva só os pedidos que estão rodando (o nome do inspect vem com '/' à frente).
        return array_values(array_filter($containers, fn ($c) => isset($running[$c])));
    }

    /**
     * Estado do Claude no container, lido de /tmp/claude-state/<pid> (escrito pelo wrapper).
     * Retorna 'waiting' (prioridade — pausado aguardando você), 'working', ou '' (nenhum ativo).
     * Considera só arquivos cujo processo (pid = nome do arquivo) ainda está vivo em /proc
     * (ignora órfãos de um wrapper morto por SIGKILL).
     */
    private function claudeState(string $container): string
    {
        if ($container === '') {
            return '';
        }
        $script = 'w=0; for f in /tmp/claude-state/*; do [ -e "$f" ] || continue; '
                . 'p=$(basename "$f"); [ -d "/proc/$p" ] || continue; '
                . 'c=$(cat "$f" 2>/dev/null); '
                . 'if [ "$c" = waiting ]; then echo waiting; exit 0; fi; '
                . 'if [ "$c" = working ]; then w=1; fi; done; '
                . '[ "$w" = 1 ] && echo working || true';
        $out = [];
        // `timeout 4` é rede de segurança: mesmo em container rodando, um exec lento NUNCA pendura
        // o worker do php-fpm pra sempre (a causa do 504 foi exec num container pausado).
        @exec('timeout 4 docker exec ' . escapeshellarg($container) . ' sh -c ' . escapeshellarg($script) . ' 2>/dev/null', $out);
        foreach ($out as $line) {
            $t = trim($line);
            if ($t === 'waiting' || $t === 'working') {
                return $t;
            }
        }
        return '';
    }

    /**
     * FALLBACK por delta de CPU — APENAS para processos de BUILD (claude é coberto pelo wrapper).
     * @param string[] $containers
     * @return array<string,bool>
     */
    private function buildCpuBusy(array $containers): array
    {
        $result = [];
        foreach ($containers as $c) {
            $result[$c] = false;
        }
        if (empty($containers)) {
            return $result;
        }

        $pidToContainer = []; // hostPid => container (processos de build + claude TUI)
        // O claude em stream-json/-p é PRECISO via wrapper (state file, prioridade em
        // containerStates) — só caímos aqui quando NÃO há esse estado. Mantemos 'claude' na lista
        // como fallback para o `claude` INTERATIVO do terminal (TUI), que não passa pelo state file;
        // e os demais são builds (npm/webpack/etc.) que nunca passam pelo claude.
        $needles = ['claude', 'npm ', 'npx ', 'yarn ', 'pnpm ', 'webpack', 'vite', 'esbuild', 'rollup',
                    'composer ', 'cargo ', 'go build', 'go test', 'gradle', 'mvn ', 'pytest', 'jest',
                    'phpunit', 'artisan ', 'cmake', 'pip install', 'tsc '];

        foreach ($containers as $c) {
            $rows = [];
            @exec('timeout 4 docker top ' . escapeshellarg($c) . ' -eo pid,stat,args 2>/dev/null', $rows);
            foreach ($rows as $i => $line) {
                if ($i === 0) { // cabeçalho (PID STAT COMMAND)
                    continue;
                }
                $parts = preg_split('/\s+/', trim($line), 3);
                $pid  = (int) ($parts[0] ?? 0);
                $stat = (string) ($parts[1] ?? '');
                $args = strtolower((string) ($parts[2] ?? ''));
                if ($pid <= 0 || strpos($stat, 'Z') !== false || strpos($args, '<defunct>') !== false) {
                    continue; // zumbi/morto não conta
                }
                foreach ($needles as $n) {
                    if (strpos($args, $n) !== false) {
                        $pidToContainer[$pid] = $c;
                        break;
                    }
                }
            }
        }
        if (empty($pidToContainer)) {
            return $result;
        }

        // 2 amostras do tempo de CPU (jiffies) dos processos de build, com um pequeno intervalo.
        $t0 = $this->cpuJiffies(array_keys($pidToContainer));
        usleep(700000); // 0,7s
        $t1 = $this->cpuJiffies(array_keys($pidToContainer));

        foreach ($pidToContainer as $pid => $c) {
            $delta = ($t1[$pid] ?? 0) - ($t0[$pid] ?? 0);
            if ($delta >= 2) { // ~20ms de CPU em 0,7s → trabalhando (ocioso acumula 0)
                $result[$c] = true;
            }
        }
        return $result;
    }

    /** Lê utime+stime (jiffies) de /proc/<pid>/stat no host. @return array<int,int> pid=>jiffies */
    private function cpuJiffies(array $pids): array
    {
        $out = [];
        foreach ($pids as $pid) {
            $s = @file_get_contents('/proc/' . (int) $pid . '/stat');
            if ($s === false) {
                continue;
            }
            // os campos podem conter espaços no comm (entre parênteses); corta após ')'.
            $rp = strrpos($s, ') ');
            if ($rp === false) {
                continue;
            }
            $fields = preg_split('/\s+/', substr($s, $rp + 2));
            // após o ')': campo[0]=state(3º) … utime é o 14º campo do stat → índice 11 aqui; stime 12.
            $utime = (int) ($fields[11] ?? 0);
            $stime = (int) ($fields[12] ?? 0);
            $out[(int) $pid] = $utime + $stime;
        }
        return $out;
    }

    private function allocatePort(): ?int
    {
        // Exclui as portas de TODAS as sessões (running E pausadas) — uma IDE pausada reserva
        // logicamente a porta para o resume religar no mesmo lugar. Isso torna o pool de portas o
        // limite de IDEs vivas+pausadas.
        $used = array_map('intval', array_column($this->db()->fetchAll("SELECT port FROM ide_sessions"), 'port'));
        for ($p = $this->portMin; $p <= $this->portMax; $p++) {
            if (!in_array($p, $used, true)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * O code-server JÁ serve HTTP nesta porta? (sonda ÚNICA e rápida, não bloqueia). Confere que
     * veio uma linha de status HTTP (code-server responde 302 em "/"), não só o socket aberto.
     */
    private function httpReady(int $port): bool
    {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if (!$fp) {
            return false;
        }
        @fwrite($fp, "GET / HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        stream_set_timeout($fp, 2);
        $line = (string) @fgets($fp, 128);
        fclose($fp);
        return stripos($line, 'HTTP/') === 0;
    }

    /** Espera o code-server servir HTTP (até $tries segundos) — usado ao criar/religar a IDE. */
    private function waitHttp(int $port, int $tries): void
    {
        for ($i = 0; $i < $tries; $i++) {
            if ($this->httpReady($port)) {
                return;
            }
            sleep(1);
        }
    }

    /**
     * PRONTIDÃO das IDEs do usuário para o preloader da página /ide: container no ar E code-server
     * já servindo HTTP. Uma IDE que acabou de sair do standby (unpause) ou de religar (start) leva
     * um instante; o cliente sonda isto e só revela o iframe quando true (sem refresh manual).
     * @return array<string,bool> projectUuid => pronto?
     */
    public function readiness(int $userId): array
    {
        $out = [];
        foreach ($this->db()->fetchAll(
            "SELECT i.port, p.uuid FROM ide_sessions i INNER JOIN projects p ON p.id = i.project_id
             WHERE i.user_id = ? AND i.status = 'running'",
            [$userId]
        ) as $r) {
            $out[(string) $r['uuid']] = $this->httpReady((int) $r['port']);
        }
        return $out;
    }

    /**
     * Escreve a lista de IDEs ativas (token+porta) e dispara o script root que VALIDA e
     * renderiza o snippet do nginx + reload. O www-data nunca escreve config do nginx direto.
     */
    private function regenNginx(): void
    {
        $storage = dirname($this->workspaceBase);
        $data = [];
        foreach ($this->listActive() as $s) {
            $data[] = ['token' => (string) $s['token'], 'port' => (int) $s['port']];
        }
        @file_put_contents($storage . '/ide-nginx.json', json_encode($data));

        $trigger = (string) env('IDE_NGINX_TRIGGER', 'sudo /usr/local/bin/ravi-ide-nginx');
        if ($trigger !== '') {
            exec($trigger . ' 2>&1');
        }
    }
}
