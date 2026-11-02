<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;

/**
 * Preview ao vivo: sobe a app ENTREGUE num container PERSISTENTE com porta, para o usuário
 * abrir o sistema rodando por um link (sem baixar e instalar). Vários previews simultâneos
 * (um por projeto), cada um com seu container e — se a app usa banco — um database próprio
 * no MariaDB persistente do preview.
 *
 * Diferente do sandbox de BUILD (efêmero, read-only, sem rede), aqui o container:
 *   - é persistente (--restart unless-stopped) e fica servindo a app;
 *   - entra na rede ravi-preview-net (para falar com o MariaDB do preview);
 *   - publica a porta em 127.0.0.1 (o nginx expõe via /p/<token>/ — ver regenNginx + Fase C).
 *
 * Decide pelo /workspace/.maestro/app.json (gravado pelo Arquiteto na entrega); se ausente,
 * detecta defaults razoáveis. has_web_ui=false ⇒ sem preview (nada visual para mostrar).
 */
class PreviewService
{
    private string $image;
    private string $imageMobile;
    private string $workspaceBase;
    private string $dbHost;
    private string $dbRootPass;
    private string $net;
    private int $portMin;
    private int $portMax;
    private string $baseUrl;

    public function __construct()
    {
        $cfg = config('sandbox');
        $this->image         = $cfg['image'] ?? 'ravi-sandbox:latest';
        $this->imageMobile   = $cfg['image_mobile'] ?? 'ravi-sandbox-mobile:latest';
        $this->workspaceBase = $cfg['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
        $this->dbHost     = (string) env('PREVIEW_DB_HOST', 'ravi-preview-db');
        $this->dbRootPass = (string) env('PREVIEW_DB_ROOT_PASS', '');
        $this->net        = (string) env('PREVIEW_NET', 'ravi-preview-net');
        $this->portMin    = (int) env('PREVIEW_PORT_MIN', 21000);
        $this->portMax    = (int) env('PREVIEW_PORT_MAX', 21099);
        $this->baseUrl    = (string) config('urls.preview');
    }

    private function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * Sobe (ou reinicia) o preview de um projeto. Retorna os dados do preview ou um erro.
     *
     * @return array{ok:bool, error?:string, token?:string, url?:string, port?:int,
     *               requires_login?:bool, demo?:array, db?:string}
     */
    public function start(string $projectUuid, ?int $conversationId = null): array
    {
        $ws = rtrim($this->workspaceBase, '/') . '/' . $projectUuid;
        if (!is_dir($ws)) {
            return ['ok' => false, 'error' => 'Workspace do projeto não encontrado.'];
        }

        $meta = $this->readMeta($ws);

        // MOBILE (RN/Expo): serve o export web (dist) dentro de molduras. Caminho próprio,
        // isolado do fluxo web abaixo (que fica INALTERADO).
        if (($meta['type'] ?? '') === 'mobile') {
            return $this->startMobile($ws, $projectUuid, $conversationId, $meta);
        }

        if (empty($meta['has_web_ui'])) {
            return ['ok' => false, 'error' => 'Este projeto não tem interface visual para abrir em um link.'];
        }

        // Apps que leem .env precisam de um para subir; usa o .env.example como base se faltar.
        // (Para db=mysql, as variáveis de ambiente do preview têm prioridade sobre o .env.)
        if (!is_file($ws . '/.env') && is_file($ws . '/.env.example')) {
            @copy($ws . '/.env.example', $ws . '/.env');
        }

        // Um preview por projeto: derruba o anterior antes de subir um novo.
        foreach ($this->db()->fetchAll("SELECT token FROM previews WHERE project_uuid = ? AND status = 'running'", [$projectUuid]) as $row) {
            $this->stop($row['token']);
        }

        $token     = bin2hex(random_bytes(8));
        $container = 'ravi-prev-' . $token;
        $port      = $this->allocatePort();
        if ($port === null) {
            return ['ok' => false, 'error' => 'Sem portas livres para o preview no momento.'];
        }

        $dbName = null;
        $dbUser = null;
        $dbPass = null;
        $envFlags = '';

        if (($meta['db'] ?? 'none') === 'mysql') {
            $dbName = 'prev_' . $token;
            $dbUser = 'u_' . substr($token, 0, 12);
            $dbPass = bin2hex(random_bytes(8));
            if (!$this->provisionDb($dbName, $dbUser, $dbPass)) {
                return ['ok' => false, 'error' => 'Falha ao provisionar o banco do preview.'];
            }
            $map = $meta['db_env'] ?? [];
            $values = [
                'driver'   => 'mysql',
                'host'     => $this->dbHost,
                'port'     => '3306',
                'database' => $dbName,
                'user'     => $dbUser,
                'password' => $dbPass,
            ];
            foreach ($values as $k => $v) {
                $envKey = $map[$k] ?? null;
                if ($envKey) {
                    $envFlags .= ' -e ' . escapeshellarg($envKey . '=' . $v);
                }
            }
        }

        $docroot = trim((string) ($meta['docroot'] ?? 'public'), '/');
        $startTpl = (string) ($meta['start_command'] ?? 'php -S 0.0.0.0:$PORT -t ' . ($docroot ?: 'public'));
        // GARANTIA do preview: se o front-controller é usado como roteador do php -S, ele precisa
        // servir os estáticos (CSS/JS) — senão a página abre sem estilo. Injetamos o guard de
        // cli-server de forma idempotente, independentemente do que o Arquiteto fez (rede de segurança).
        $this->ensureCliServerGuard($ws, $docroot);
        $start   = str_replace('$PORT', (string) $port, $startTpl);
        $setup   = trim((string) ($meta['setup_command'] ?? ''));
        $inner   = ($setup !== '' ? str_replace('$PORT', (string) $port, $setup) . ' ; ' : '') . 'exec ' . $start;

        $cmd = 'docker run -d'
            . ' --name ' . escapeshellarg($container)
            . ' --restart unless-stopped'
            . ' --network ' . escapeshellarg($this->net)
            . ' -p ' . escapeshellarg('127.0.0.1:' . $port . ':' . $port)
            . ' --user sandbox'
            . $envFlags
            . ' -v ' . escapeshellarg($ws . ':/workspace:rw')
            . ' -w /workspace'
            . ' ' . escapeshellarg($this->image)
            . ' bash -lc ' . escapeshellarg($inner);

        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            $this->dropDb($dbName, $dbUser);
            return ['ok' => false, 'error' => 'Falha ao subir o container do preview: ' . implode(' ', array_slice($out, -3))];
        }

        $url = $this->baseUrl . '/' . $token . '/';
        $demoUser = $meta['demo_credentials']['user'] ?? null;
        $demoPass = $meta['demo_credentials']['password'] ?? null;
        $requiresLogin = (bool) ($meta['requires_login'] ?? false);

        $this->db()->insert('previews', [
            'token'           => $token,
            'project_uuid'    => $projectUuid,
            'conversation_id' => $conversationId,
            'container_name'  => $container,
            'port'            => $port,
            'db_name'         => $dbName,
            'db_user'         => $dbUser,
            'url'             => $url,
            'status'          => 'running',
            'requires_login'  => $requiresLogin ? 1 : 0,
            'demo_user'       => $demoUser,
            'demo_password'   => $demoPass,
        ]);

        $this->regenNginx();
        $this->waitHttp($port, 12);

        return [
            'ok'             => true,
            'token'          => $token,
            'url'            => $url,
            'port'           => $port,
            'requires_login' => $requiresLogin,
            // Mostra as credenciais demo SEMPRE que existirem (uma loja tem home pública mas
            // também tem login de cliente/admin — o usuário precisa delas para testar).
            'demo'           => $demoUser ? ['user' => $demoUser, 'password' => $demoPass] : null,
            'db'             => $meta['db'] ?? 'none',
        ];
    }

    /**
     * Sobe o preview de um app MOBILE (RN/Expo): serve o export web (dist) estático sob /p/<token>/,
     * para ser exibido em molduras (Android + iPhone). Sem banco, sem login.
     *
     * Detalhe crítico: o export padrão do Expo usa caminhos ABSOLUTOS (/_expo/...), que quebram sob
     * subcaminho. Por isso re-exportamos com experiments.baseUrl=/p/<token> (que prefixa os assets);
     * o token é ESTÁVEL por projeto (link reutilizável) e o export fica CACHEADO (só refaz quando o
     * dist falta ou foi gerado para outra baseUrl). O app.json do projeto é restaurado após o export
     * (não polui a entrega).
     *
     * @return array{ok:bool, error?:string, token?:string, url?:string, frames_url?:string, mobile?:bool, port?:int}
     */
    private function startMobile(string $ws, string $projectUuid, ?int $conversationId, array $meta): array
    {
        $exportDir = trim((string) ($meta['web_export'] ?? 'dist'), '/');
        if ($exportDir === '') {
            $exportDir = 'dist';
        }
        if (!is_dir($ws . '/node_modules')) {
            return ['ok' => false, 'error' => 'O app ainda não está pronto para preview (dependências ausentes). Rode o desenvolvimento até a entrega.'];
        }

        // Token estável (hex, seguro para o nginx) → link reutilizável por projeto.
        $token   = substr(sha1('mobile:' . $projectUuid), 0, 16);
        $baseUrl = '/p/' . $token;

        // Garante o export web com a baseUrl certa (cacheado por marcador).
        $marker     = $ws . '/.maestro/preview-baseurl';
        $haveDist   = is_file($ws . '/' . $exportDir . '/index.html');
        $markerOk   = is_file($marker) && trim((string) @file_get_contents($marker)) === $baseUrl;
        if (!$haveDist || !$markerOk) {
            $exp = $this->mobileExport($ws, $exportDir, $baseUrl);
            if (!$exp['ok']) {
                return ['ok' => false, 'error' => 'Falha ao preparar o preview (export web): ' . ($exp['error'] ?? '')];
            }
            @file_put_contents($marker, $baseUrl);
        }

        // Um preview por projeto: derruba o anterior (mesmo token reutilizado) antes de subir.
        foreach ($this->db()->fetchAll("SELECT token FROM previews WHERE project_uuid = ? AND status = 'running'", [$projectUuid]) as $row) {
            $this->stop($row['token']);
        }
        $container = 'ravi-prev-' . $token;
        exec('docker rm -f ' . escapeshellarg($container) . ' 2>&1');

        $port = $this->allocatePort();
        if ($port === null) {
            return ['ok' => false, 'error' => 'Sem portas livres para o preview no momento.'];
        }

        // Serve o dist estático (php -S serve estáticos direto; sem roteador, sem guard).
        $start = 'php -S 0.0.0.0:' . $port . ' -t ' . escapeshellarg($exportDir);
        $cmd = 'docker run -d'
            . ' --name ' . escapeshellarg($container)
            . ' --restart unless-stopped'
            . ' --network ' . escapeshellarg($this->net)
            . ' -p ' . escapeshellarg('127.0.0.1:' . $port . ':' . $port)
            . ' --user sandbox'
            . ' -v ' . escapeshellarg($ws . ':/workspace:rw')
            . ' -w /workspace'
            . ' ' . escapeshellarg($this->imageMobile)
            . ' bash -lc ' . escapeshellarg('exec ' . $start);

        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            return ['ok' => false, 'error' => 'Falha ao subir o container do preview: ' . implode(' ', array_slice($out, -3))];
        }

        $rawUrl    = $this->baseUrl . '/' . $token . '/';
        $framesUrl = url('/m/' . $token);

        // Token é ESTÁVEL por projeto: remove qualquer registro anterior desse token (inclusive
        // os já parados) antes de inserir, senão a chave única 'token' colide na reabertura.
        $this->db()->execute("DELETE FROM previews WHERE token = ?", [$token]);

        $this->db()->insert('previews', [
            'token'           => $token,
            'project_uuid'    => $projectUuid,
            'conversation_id' => $conversationId,
            'container_name'  => $container,
            'port'            => $port,
            'db_name'         => null,
            'db_user'         => null,
            'url'             => $framesUrl,
            'status'          => 'running',
            'requires_login'  => 0,
            'demo_user'       => null,
            'demo_password'   => null,
        ]);

        $this->regenNginx();
        $this->waitHttp($port, 12);

        return [
            'ok'         => true,
            'token'      => $token,
            'url'        => $framesUrl, // a UI abre a página de molduras
            'frames_url' => $framesUrl,
            'app_url'    => $rawUrl,    // o app cru (usado pelos iframes das molduras)
            'mobile'     => true,
            'port'       => $port,
        ];
    }

    /**
     * Re-exporta o web do Expo com experiments.baseUrl=$baseUrl (para os assets funcionarem sob
     * subcaminho). Roda na imagem MOBILE, usando o node_modules do workspace. Restaura o app.json
     * original ao final (sucesso ou falha), para não poluir a entrega.
     *
     * @return array{ok:bool, error?:string}
     */
    private function mobileExport(string $ws, string $exportDir, string $baseUrl): array
    {
        $appJson = $ws . '/app.json';
        if (!is_file($appJson)) {
            return ['ok' => false, 'error' => 'app.json do Expo não encontrado.'];
        }
        $orig = (string) file_get_contents($appJson);
        $data = json_decode($orig, true);
        if (!is_array($data) || !isset($data['expo'])) {
            return ['ok' => false, 'error' => 'app.json do Expo inválido.'];
        }
        $data['expo']['experiments'] = array_merge(
            $data['expo']['experiments'] ?? [],
            ['baseUrl' => $baseUrl]
        );
        @file_put_contents($appJson, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Export numa imagem mobile efêmera (com rede; bundling offline, mas evita surpresas).
        $inner = 'set -e; export CI=1; rm -rf ' . escapeshellarg($exportDir) . '; '
            . 'npx --yes expo export --platform web';
        $cmd = 'timeout 300 docker run --rm'
            . ' --user sandbox'
            . ' -e HOME=/tmp/cc-home -e CI=1'
            . ' -v ' . escapeshellarg($ws . ':/workspace:rw')
            . ' -w /workspace'
            . ' ' . escapeshellarg($this->imageMobile)
            . ' bash -lc ' . escapeshellarg($inner);

        exec($cmd . ' 2>&1', $out, $rc);

        // Restaura o app.json original SEMPRE (não vaza a baseUrl do preview para a entrega).
        @file_put_contents($appJson, $orig);

        if ($rc !== 0 || !is_file($ws . '/' . $exportDir . '/index.html')) {
            return ['ok' => false, 'error' => implode(' ', array_slice($out, -4))];
        }
        return ['ok' => true];
    }

    /** Para e remove um preview (container + database + registro), e atualiza o nginx. */
    public function stop(string $token): bool
    {
        $row = $this->db()->fetch("SELECT * FROM previews WHERE token = ?", [$token]);
        if (!$row) {
            return false;
        }
        exec('docker rm -f ' . escapeshellarg($row['container_name']) . ' 2>&1');
        $this->dropDb($row['db_name'] ?? null, $row['db_user'] ?? null);
        $this->db()->execute("UPDATE previews SET status = 'stopped' WHERE token = ?", [$token]);
        $this->regenNginx();
        return true;
    }

    /** Marca atividade (evita expirar enquanto está em uso). */
    public function touch(string $token): void
    {
        $this->db()->execute("UPDATE previews SET last_seen_at = CURRENT_TIMESTAMP WHERE token = ?", [$token]);
    }

    /** Previews ativos (para a UI e o cleanup). */
    public function listActive(): array
    {
        return $this->db()->fetchAll("SELECT * FROM previews WHERE status = 'running' ORDER BY started_at DESC");
    }

    public function getByProject(string $projectUuid): ?array
    {
        return $this->db()->fetch("SELECT * FROM previews WHERE project_uuid = ? AND status = 'running' ORDER BY id DESC LIMIT 1", [$projectUuid]);
    }

    /** Derruba previews ociosos há mais de N minutos. Retorna quantos parou. */
    public function cleanupIdle(int $minutes = 60): int
    {
        $rows = $this->db()->fetchAll(
            "SELECT token FROM previews WHERE status = 'running' AND last_seen_at < (NOW() - INTERVAL ? MINUTE)",
            [$minutes]
        );
        foreach ($rows as $r) {
            $this->stop($r['token']);
        }
        return count($rows);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internos
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Garante que o front-controller (docroot/index.php) sirva arquivos estáticos quando usado
     * como ROTEADOR do php -S. Sem isso, pedidos de CSS/JS caem no roteador e voltam como HTML
     * (o navegador recusa o CSS → página sem estilo). Idempotente e seguro: o guard só age sob
     * cli-server e nunca intercepta .php (senão o próprio index.php seria servido vazio = branco).
     */
    private function ensureCliServerGuard(string $ws, string $docroot): void
    {
        $idx = rtrim($ws, '/') . '/' . ($docroot !== '' && $docroot !== '.' ? $docroot . '/' : '') . 'index.php';
        if (!is_file($idx) || !is_writable($idx)) {
            return;
        }
        $src = (string) file_get_contents($idx);
        if ($src === '' || strpos($src, 'cli-server') !== false) {
            return; // já trata, ou arquivo vazio
        }
        $pos = strpos($src, '<?php');
        if ($pos === false) {
            return;
        }
        $insertAt = $pos + 5;
        // Preserva uma eventual linha declare(strict_types=1); logo após o <?php.
        if (preg_match('/\G\s*declare\s*\([^;]*\)\s*;/', $src, $m, 0, $insertAt)) {
            $insertAt += strlen($m[0]);
        }
        $guard = "\n// [preview] serve estáticos existentes diretamente sob php -S (nunca .php).\n"
            . "if (PHP_SAPI === 'cli-server') {\n"
            . "    \$__p = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);\n"
            . "    if (\$__p !== '/' && is_file(__DIR__ . \$__p) && substr(\$__p, -4) !== '.php') { return false; }\n"
            . "}\n";
        $patched = substr($src, 0, $insertAt) . $guard . substr($src, $insertAt);
        @file_put_contents($idx, $patched);
    }

    /** Lê /workspace/.maestro/app.json; se ausente, detecta defaults razoáveis. */
    private function readMeta(string $ws): array
    {
        $file = $ws . '/.maestro/app.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        // Rede de segurança: app.json na RAIZ (arquiteto às vezes grava no lugar errado), desde que
        // seja metadata de preview web e não um app.config do Expo (chave 'expo').
        $root = $ws . '/app.json';
        if (is_file($root)) {
            $data = json_decode((string) file_get_contents($root), true);
            if (is_array($data) && empty($data['expo'])
                && (isset($data['start_command']) || isset($data['demo_credentials']) || array_key_exists('has_web_ui', $data))) {
                return $data;
            }
        }
        // Detecção de fallback (projetos entregues antes do app.json).
        $docroot = is_dir($ws . '/public') ? 'public' : '.';
        $hasUi   = is_file($ws . '/' . $docroot . '/index.php') || is_file($ws . '/index.html') || is_file($ws . '/' . $docroot . '/index.html');
        $db      = (glob($ws . '/**/*.sqlite') || is_file($ws . '/database/schema.sql')) ? 'sqlite' : 'none';
        return [
            'type'          => 'web',
            'has_web_ui'    => $hasUi,
            'docroot'       => $docroot,
            'start_command' => 'php -S 0.0.0.0:$PORT -t ' . $docroot,
            'setup_command' => '',
            'requires_login'=> false,
            'db'            => $db,
        ];
    }

    /** Primeira porta livre no range (sem registro de preview rodando nela). */
    private function allocatePort(): ?int
    {
        $used = array_column($this->db()->fetchAll("SELECT DISTINCT port FROM previews WHERE status = 'running'"), 'port');
        $used = array_map('intval', $used);
        for ($p = $this->portMin; $p <= $this->portMax; $p++) {
            if (!in_array($p, $used, true)) {
                return $p;
            }
        }
        return null;
    }

    /** Cria database + usuário dedicados no MariaDB do preview. */
    private function provisionDb(string $dbName, string $dbUser, string $dbPass): bool
    {
        $sql = sprintf(
            "CREATE DATABASE IF NOT EXISTS `%s`; CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s'; GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%%'; FLUSH PRIVILEGES;",
            $dbName, $dbUser, $dbPass, $dbName, $dbUser
        );
        $cmd = 'docker exec ' . escapeshellarg($this->dbHost)
            . ' mariadb -uroot -p' . escapeshellarg($this->dbRootPass)
            . ' -e ' . escapeshellarg($sql) . ' 2>&1';
        exec($cmd, $out, $rc);
        return $rc === 0;
    }

    private function dropDb(?string $dbName, ?string $dbUser): void
    {
        if (!$dbName) {
            return;
        }
        $sql = sprintf("DROP DATABASE IF EXISTS `%s`;", $dbName);
        if ($dbUser) {
            $sql .= sprintf(" DROP USER IF EXISTS '%s'@'%%';", $dbUser);
        }
        $sql .= ' FLUSH PRIVILEGES;';
        exec('docker exec ' . escapeshellarg($this->dbHost)
            . ' mariadb -uroot -p' . escapeshellarg($this->dbRootPass)
            . ' -e ' . escapeshellarg($sql) . ' 2>&1');
    }

    /** Espera a app responder na porta (HTTP) por até $tries segundos. */
    private function waitHttp(int $port, int $tries): void
    {
        for ($i = 0; $i < $tries; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if ($fp) {
                fclose($fp);
                return;
            }
            sleep(1);
        }
    }

    /**
     * Escreve a lista de previews ativos (token+porta) num JSON e dispara o script root
     * que VALIDA e renderiza o snippet do nginx + reload. O www-data nunca escreve config
     * do nginx direto — só fornece dados validados pelo script (ver /usr/local/bin/ravi-preview-nginx).
     */
    private function regenNginx(): void
    {
        $storage = dirname($this->workspaceBase);
        $data = [];
        foreach ($this->listActive() as $p) {
            $data[] = ['token' => (string) $p['token'], 'port' => (int) $p['port']];
        }
        @file_put_contents($storage . '/previews-nginx.json', json_encode($data));

        $trigger = (string) env('PREVIEW_NGINX_TRIGGER', 'sudo /usr/local/bin/ravi-preview-nginx');
        if ($trigger !== '') {
            exec($trigger . ' 2>&1');
        }
    }
}
