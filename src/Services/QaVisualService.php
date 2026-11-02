<?php

declare(strict_types=1);

namespace Ravi\Services;

/**
 * QA Visual — abre um NAVEGADOR REAL (Chromium/Playwright) contra a app entregue, faz login
 * de verdade, navega/clica e GRAVA um screencast ao vivo. Enquanto navega, o harness escreve
 * o frame mais recente em <ws>/.maestro/screencast/frame.jpg (o chat transmite ~4fps) e, ao
 * final, um report.json (erros de console/página/rede) + stills de página inteira em shots/.
 *
 * É o PRODUTOR do recurso: o MaestroService chama run() no loop de polimento/portão de entrega;
 * o agente de QA então LÊ os stills (visão) + o report e corrige a UI até ficar limpa.
 *
 * Roda seu PRÓPRIO `docker run` (NÃO o sandbox de build): precisa do Chromium do Playwright,
 * que mora em /root/.cache (invisível ao --user sandbox). Por isso usa --user root +
 * PLAYWRIGHT_BROWSERS_PATH + NODE_PATH, com a app servida em localhost dentro do container.
 *
 * Transporte sem entupir WS/DB: só os eventos leves screencast_start/stop passam pelo canal de
 * eventos; os frames trafegam por HTTP (ChatController::screencast), lidos direto do disco.
 */
class QaVisualService
{
    /** @var callable|null */
    private $onEvent = null;

    private string $image;
    private string $workspaceBase;
    private int $port;
    private int $timeout;

    public function __construct()
    {
        $cfg = config('sandbox');
        $this->image         = $cfg['image'] ?? 'ravi-sandbox:latest';
        $this->workspaceBase = $cfg['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
        // Porta interna do container (namespace isolado por container → nunca colide).
        $this->port    = (int) env('QA_VISUAL_PORT', 8080);
        $this->timeout = (int) env('QA_VISUAL_TIMEOUT', 240);
    }

    public function onUpdate(callable $cb): void
    {
        $this->onEvent = $cb;
    }

    private function emit(array $data): void
    {
        if ($this->onEvent) {
            ($this->onEvent)($data);
        }
    }

    /** Diretório do screencast no host (servido pelo ChatController e lido pelo agente). */
    public function screencastDir(string $ws): string
    {
        return rtrim($ws, '/') . '/.maestro/screencast';
    }

    /** Remove todo o screencast (frame + stills + report). Chamado ao fechar o ciclo de QA. */
    public function cleanup(string $ws): void
    {
        $dir = $this->screencastDir($ws);
        if (is_dir($dir)) {
            $this->rrmdir($dir);
        }
    }

    /**
     * Há interface web para testar no navegador? (lê app.json; senão detecta public/index.*).
     * Mobile (type=mobile) NÃO entra aqui — o preview mobile é tratado à parte.
     */
    public function shouldRun(string $ws): bool
    {
        $meta = $this->readMeta($ws);
        if (($meta['type'] ?? 'web') === 'mobile') {
            return false;
        }
        return !empty($meta['has_web_ui']);
    }

    /**
     * Roda o QA visual e devolve o resultado. NÃO lança — em falha de infra, retorna ok=false
     * com um motivo (o Maestro segue; o portão de entrega real continua sendo o verificador).
     *
     * @return array{ok:bool, skipped?:bool, reason?:string, report?:array, shots?:array,
     *                shots_dir?:string, error_count?:int, summary?:string}
     */
    public function run(string $ws, string $projectUuid): array
    {
        $ws = rtrim($ws, '/');
        if (!is_dir($ws)) {
            return ['ok' => false, 'reason' => 'workspace inexistente'];
        }
        $meta = $this->readMeta($ws);
        if (!$this->shouldRun($ws)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'sem interface web'];
        }

        // Screencast limpo a cada run (um conjunto por vez — nada acumula).
        $scDir = $this->screencastDir($ws);
        $this->rrmdir($scDir);
        @mkdir($scDir, 0777, true);
        @chmod($scDir, 0777);

        // .env mínimo para a app subir (mesma regra do preview).
        if (!is_file($ws . '/.env') && is_file($ws . '/.env.example')) {
            @copy($ws . '/.env.example', $ws . '/.env');
        }

        $docroot  = trim((string) ($meta['docroot'] ?? 'public'), '/');
        $startTpl = (string) ($meta['start_command'] ?? ('php -S 0.0.0.0:$PORT -t ' . ($docroot ?: '.')));
        $setupTpl = trim((string) ($meta['setup_command'] ?? ''));
        // Garante que o roteador sirva estáticos (CSS/JS) — senão a UI aparece sem estilo no QA.
        $this->ensureCliServerGuard($ws, $docroot);

        $requiresLogin = (bool) ($meta['requires_login'] ?? false);
        $demoUser = (string) ($meta['demo_credentials']['user'] ?? '');
        $demoPass = (string) ($meta['demo_credentials']['password'] ?? '');

        // Config do harness (rotas opcionais, login, fps).
        $config = [
            'base_url'       => 'http://127.0.0.1:' . $this->port,
            'requires_login' => $requiresLogin,
            'login'          => ['user' => $demoUser, 'password' => $demoPass],
            'fps'            => (int) env('QA_VISUAL_FPS', 4),
        ];
        @file_put_contents($ws . '/.maestro/qa-config.json', json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $start = str_replace('$PORT', (string) $this->port, $startTpl);
        $setup = $setupTpl !== '' ? str_replace('$PORT', (string) $this->port, $setupTpl) : '';

        // Anuncia o card "Navegador ao vivo" ANTES de bloquear no docker (a UI já começa a buscar frames).
        $this->emit(['type' => 'screencast_start', 'label' => 'Testando a interface no navegador']);

        $cmd = $this->buildDockerCmd($ws, $setup, $start, $requiresLogin, $demoUser, $demoPass);

        $out = [];
        $rc  = 0;
        @exec($cmd . ' 2>&1', $out, $rc);

        $report = $this->readReport($scDir);

        // Encerra o card (efêmero) e apaga os artefatos AO VIVO (frame/status/captions);
        // mantém shots/ + report.json para o agente de QA ler logo em seguida.
        $this->emit(['type' => 'screencast_stop']);
        @unlink($scDir . '/frame.jpg');
        @unlink($scDir . '/status.json');
        @unlink($scDir . '/captions.jsonl');

        if ($report === null) {
            return [
                'ok'      => false,
                'reason'  => 'harness não produziu report (rc=' . $rc . '): ' . mb_substr(implode(' ', array_slice($out, -4)), 0, 400),
                'summary' => 'QA visual não pôde rodar.',
            ];
        }

        $errorCount = (int) ($report['error_count'] ?? 0);
        $shots = array_values(array_filter(array_map(
            fn($s) => is_array($s) ? (string) ($s['path'] ?? '') : '',
            $report['shots'] ?? []
        )));

        return [
            'ok'          => (bool) ($report['ok'] ?? false),
            'report'      => $report,
            'shots'       => $shots,
            'shots_dir'   => $scDir . '/shots',
            'error_count' => $errorCount,
            'summary'     => (string) ($report['summary'] ?? ''),
        ];
    }

    /** Monta o `docker run` do container de QA (root + Playwright), subindo a app e rodando o harness. */
    private function buildDockerCmd(string $ws, string $setup, string $start, bool $requiresLogin, string $user, string $pass): string
    {
        $port  = $this->port;
        $qaDir = __DIR__ . '/qa';

        // Shell interno: prepara, sobe a app em SEGUNDO PLANO, espera o HTTP, roda o harness, mata o servidor.
        $inner = 'umask 000; export HOME=/root; cd /workspace; '
            . '{ [ -f .env ] || { [ -f .env.example ] && cp -n .env.example .env; }; } 2>/dev/null; '
            . ($setup !== '' ? '( ' . $setup . ' ) >/tmp/qa-setup.log 2>&1; ' : '')
            . '( ' . $start . ' ) >/tmp/qa-srv.log 2>&1 & SRV=$!; '
            . 'for i in $(seq 1 30); do curl -sf -o /dev/null "http://127.0.0.1:' . $port . '/" && break; sleep 0.5; done; '
            . 'node /qa/browser-qa.js; RC=$?; '
            . 'kill $SRV 2>/dev/null; '
            . 'exit $RC';

        $env = [
            'PLAYWRIGHT_BROWSERS_PATH' => '/root/.cache/ms-playwright',
            'NODE_PATH'                => '/usr/lib/node_modules',
            'HOME'                     => '/root',
            'QA_BASE_URL'              => 'http://127.0.0.1:' . $port,
            'QA_OUT_DIR'               => '/workspace/.maestro/screencast',
            'QA_CONFIG'                => '/workspace/.maestro/qa-config.json',
            'QA_REQUIRES_LOGIN'        => $requiresLogin ? '1' : '0',
            'QA_USER'                  => $user,
            'QA_PASS'                  => $pass,
        ];
        $envFlags = '';
        foreach ($env as $k => $v) {
            $envFlags .= ' -e ' . escapeshellarg($k . '=' . $v);
        }

        return 'timeout ' . $this->timeout . ' docker run --rm'
            . ' --user root'
            . ' --memory=2g --cpus=2 --shm-size=512m'
            . $envFlags
            . ' -v ' . escapeshellarg($ws . ':/workspace:rw')
            . ' -v ' . escapeshellarg($qaDir . ':/qa:ro')
            . ' -w /workspace'
            . ' ' . escapeshellarg($this->image)
            . ' bash -lc ' . escapeshellarg($inner);
    }

    private function readReport(string $scDir): ?array
    {
        $f = $scDir . '/report.json';
        if (!is_file($f)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($f), true);
        return is_array($data) ? $data : null;
    }

    /** Lê /workspace/.maestro/app.json; se ausente, detecta defaults razoáveis (igual ao preview). */
    private function readMeta(string $ws): array
    {
        $file = $ws . '/.maestro/app.json';
        if (is_file($file)) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        // Rede de segurança: app.json na RAIZ (gravado no lugar errado), se for metadata de preview web.
        $root = $ws . '/app.json';
        if (is_file($root)) {
            $data = json_decode((string) @file_get_contents($root), true);
            if (is_array($data) && empty($data['expo'])
                && (isset($data['start_command']) || isset($data['demo_credentials']) || array_key_exists('has_web_ui', $data))) {
                return $data;
            }
        }
        $docroot = is_dir($ws . '/public') ? 'public' : '.';
        $hasUi   = is_file($ws . '/' . $docroot . '/index.php') || is_file($ws . '/index.html') || is_file($ws . '/' . $docroot . '/index.html');
        return [
            'type'           => 'web',
            'has_web_ui'     => $hasUi,
            'docroot'        => $docroot,
            'start_command'  => 'php -S 0.0.0.0:$PORT -t ' . $docroot,
            'setup_command'  => '',
            'requires_login' => false,
        ];
    }

    /**
     * Injeta o guard de cli-server no front-controller para servir estáticos sob php -S
     * (idêntico ao PreviewService — idempotente; nunca intercepta .php).
     */
    private function ensureCliServerGuard(string $ws, string $docroot): void
    {
        $idx = rtrim($ws, '/') . '/' . ($docroot !== '' && $docroot !== '.' ? $docroot . '/' : '') . 'index.php';
        if (!is_file($idx) || !is_writable($idx)) {
            return;
        }
        $src = (string) file_get_contents($idx);
        if ($src === '' || strpos($src, 'cli-server') !== false) {
            return;
        }
        $pos = strpos($src, '<?php');
        if ($pos === false) {
            return;
        }
        $insertAt = $pos + 5;
        if (preg_match('/\G\s*declare\s*\([^;]*\)\s*;/', $src, $m, 0, $insertAt)) {
            $insertAt += strlen($m[0]);
        }
        $guard = "\n// [qa] serve estáticos existentes diretamente sob php -S (nunca .php).\n"
            . "if (PHP_SAPI === 'cli-server') {\n"
            . "    \$__p = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);\n"
            . "    if (\$__p !== '/' && is_file(__DIR__ . \$__p) && substr(\$__p, -4) !== '.php') { return false; }\n"
            . "}\n";
        @file_put_contents($idx, substr($src, 0, $insertAt) . $guard . substr($src, $insertAt));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            $p = $dir . '/' . $it;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
