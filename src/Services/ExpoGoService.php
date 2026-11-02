<?php

declare(strict_types=1);

namespace Ravi\Services;

/**
 * Expo Go via QR: roda o app no CELULAR REAL do usuário (Android ou iPhone), sem instalar nada
 * além do app "Expo Go". Sobe um Metro dev server com TÚNEL (ngrok do Expo, sem conta) num
 * container persistente; o Expo imprime a URL "exp://...exp.direct" que vira um QR no chat.
 *
 * O Expo só imprime a URL quando tem um TERMINAL (PTY); por isso roda sob `script` (mesmo truque
 * do terminal do projeto). A URL é lida do log do PTY. O container tem TTL (timeout) para não
 * deixar túnel/Metro vivos para sempre; há também o botão Parar.
 *
 * Isolado: só é acionado para projetos mobile, pelos endpoints /chat/.../expo/*.
 */
class ExpoGoService
{
    private string $imageMobile;
    private string $workspaceBase;
    private int $ttl;

    public function __construct()
    {
        $cfg = config('sandbox');
        $this->imageMobile   = $cfg['image_mobile'] ?? 'ravi-sandbox-mobile:latest';
        $this->workspaceBase = $cfg['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
        $this->ttl = (int) env('EXPO_GO_TTL', 3600); // segundos que o túnel fica vivo (default 1h)
    }

    private function ws(string $projectUuid): string
    {
        return rtrim($this->workspaceBase, '/') . '/' . $projectUuid;
    }

    private function container(string $projectUuid): string
    {
        return 'ravi-expo-' . substr(sha1($projectUuid), 0, 12);
    }

    private function logFile(string $ws): string
    {
        return $ws . '/.maestro/expo-pty.log';
    }

    private function containerRunning(string $name): bool
    {
        $out = [];
        exec('docker ps --filter ' . escapeshellarg('name=' . $name) . ' --format ' . escapeshellarg('{{.Names}}') . ' 2>/dev/null', $out);
        return !empty(array_filter($out));
    }

    /** Extrai a URL exp://...exp.direct do log do PTY (limpando códigos ANSI). */
    private function parseUrl(string $ws): ?string
    {
        $log = $this->logFile($ws);
        if (!is_file($log)) {
            return null;
        }
        $raw = (string) @file_get_contents($log);
        if ($raw === '') {
            return null;
        }
        $clean = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $raw) ?? $raw;
        if (preg_match('#exp://[a-zA-Z0-9._-]+\.exp\.direct#', $clean, $m)) {
            return $m[0];
        }
        return null;
    }

    /**
     * Estado da sessão Expo Go.
     *
     * @return array{status:string, url?:string, error?:string}  status: idle|starting|ready|error
     */
    public function status(string $projectUuid): array
    {
        $ws = $this->ws($projectUuid);
        if (!is_dir($ws)) {
            return ['status' => 'idle'];
        }
        $running = $this->containerRunning($this->container($projectUuid));
        $url = $this->parseUrl($ws);
        if ($url !== null && $running) {
            return ['status' => 'ready', 'url' => $url];
        }
        if ($running) {
            return ['status' => 'starting'];
        }
        return ['status' => 'idle'];
    }

    /**
     * Inicia (ou reusa) a sessão Expo Go com túnel. Idempotente.
     *
     * @return array{ok:bool, status?:string, url?:string, error?:string}
     */
    public function start(string $projectUuid): array
    {
        $ws = $this->ws($projectUuid);
        if (!is_dir($ws)) {
            return ['ok' => false, 'error' => 'Workspace do projeto não encontrado.'];
        }
        if (!is_dir($ws . '/node_modules')) {
            return ['ok' => false, 'error' => 'O app ainda não está pronto (dependências ausentes).'];
        }

        $cur = $this->status($projectUuid);
        if ($cur['status'] === 'ready') {
            return ['ok' => true, 'status' => 'ready', 'url' => $cur['url']];
        }
        if ($cur['status'] === 'starting') {
            return ['ok' => true, 'status' => 'starting'];
        }

        $container = $this->container($projectUuid);
        exec('docker rm -f ' . escapeshellarg($container) . ' 2>&1');
        @mkdir($ws . '/.maestro', 0777, true);
        @unlink($this->logFile($ws));

        // PTY (script) → o Expo imprime a URL do túnel no log. TTL via timeout.
        $inner = 'export HOME=/tmp/h; mkdir -p /tmp/h; rm -f /workspace/.maestro/expo-pty.log; '
            . 'exec timeout ' . $this->ttl . ' script -qfc '
            . escapeshellarg('npx --yes expo start --tunnel --no-dev')
            . ' /workspace/.maestro/expo-pty.log';

        $cmd = 'docker run -d --rm'
            . ' --name ' . escapeshellarg($container)
            . ' --memory=2g --cpus=2'
            . ' --user sandbox'
            . ' -v ' . escapeshellarg($ws . ':/workspace:rw')
            . ' -w /workspace'
            . ' ' . escapeshellarg($this->imageMobile)
            . ' bash -lc ' . escapeshellarg($inner);

        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            return ['ok' => false, 'error' => 'Não foi possível iniciar o Expo Go: ' . implode(' ', array_slice($out, -2))];
        }
        return ['ok' => true, 'status' => 'starting'];
    }

    /** Para a sessão Expo Go (remove o container). */
    public function stop(string $projectUuid): bool
    {
        exec('docker rm -f ' . escapeshellarg($this->container($projectUuid)) . ' 2>&1');
        return true;
    }
}
