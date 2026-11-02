<?php

declare(strict_types=1);

namespace Ravi\Services;

/**
 * Build de APK Android SOB DEMANDA para projetos mobile (RN/Expo).
 *
 * O build (expo prebuild + gradle assembleRelease + assinatura) leva minutos, então NÃO pode ser síncrono num
 * request HTTP: roda num container DESTACADO (docker run -d --rm) que escreve o resultado no
 * workspace (delivery/app-debug.apk) e o estado em .maestro/apk.status (building|ready|error).
 * O chat dispara start() e faz polling de status() até "ready", quando aparece o botão de baixar.
 *
 * Isolado do caminho web: só é acionado para projetos mobile, pelos endpoints /chat/.../apk/*.
 */
class ApkBuildService
{
    private string $imageMobile;
    private string $workspaceBase;
    private string $memory;
    private string $cpu;

    public function __construct()
    {
        $cfg = config('sandbox');
        $this->imageMobile   = $cfg['image_mobile'] ?? 'ravi-sandbox-mobile:latest';
        $this->workspaceBase = $cfg['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
        $this->memory = (string) env('APK_BUILD_MEMORY', '10g');
        $this->cpu    = (string) env('APK_BUILD_CPU', '4');
    }

    private function ws(string $projectUuid): string
    {
        return rtrim($this->workspaceBase, '/') . '/' . $projectUuid;
    }

    private function container(string $projectUuid): string
    {
        return 'ravi-apk-' . substr(sha1($projectUuid), 0, 12);
    }

    /** Caminho relativo do APK gerado, se existir (delivery/*.apk). */
    private function findApk(string $ws): ?string
    {
        foreach (glob($ws . '/delivery/*.apk') ?: [] as $f) {
            return 'delivery/' . basename($f);
        }
        return null;
    }

    private function containerRunning(string $name): bool
    {
        $out = [];
        exec('docker ps --filter ' . escapeshellarg('name=' . $name) . ' --format ' . escapeshellarg('{{.Names}}') . ' 2>/dev/null', $out);
        return !empty(array_filter($out));
    }

    /**
     * Estado do build do APK.
     *
     * @return array{status:string, apk?:string, error?:string}  status: idle|building|ready|error
     */
    public function status(string $projectUuid): array
    {
        $ws = $this->ws($projectUuid);
        if (!is_dir($ws)) {
            return ['status' => 'idle'];
        }
        $apk = $this->findApk($ws);
        if ($apk !== null) {
            return ['status' => 'ready', 'apk' => $apk];
        }
        $st = is_file($ws . '/.maestro/apk.status') ? trim((string) @file_get_contents($ws . '/.maestro/apk.status')) : '';
        if ($st === 'building') {
            if ($this->containerRunning($this->container($projectUuid))) {
                return ['status' => 'building'];
            }
            // Marcado como building mas o container sumiu e não há APK → falhou.
            return ['status' => 'error', 'error' => 'O build terminou sem gerar o APK.'];
        }
        if ($st === 'error') {
            return ['status' => 'error', 'error' => 'O build do APK falhou. Veja se o app compila e tente de novo.'];
        }
        return ['status' => 'idle'];
    }

    /**
     * Inicia o build do APK em segundo plano (idempotente: não duplica se já está rodando/pronto).
     *
     * @return array{ok:bool, status?:string, apk?:string, error?:string}
     */
    public function start(string $projectUuid): array
    {
        $ws = $this->ws($projectUuid);
        if (!is_dir($ws)) {
            return ['ok' => false, 'error' => 'Workspace do projeto não encontrado.'];
        }
        if (!is_dir($ws . '/node_modules')) {
            return ['ok' => false, 'error' => 'O app ainda não está pronto para empacotar (dependências ausentes).'];
        }

        $cur = $this->status($projectUuid);
        if ($cur['status'] === 'building') {
            return ['ok' => true, 'status' => 'building'];
        }
        if ($cur['status'] === 'ready') {
            return ['ok' => true, 'status' => 'ready', 'apk' => $cur['apk']];
        }

        $container = $this->container($projectUuid);
        exec('docker rm -f ' . escapeshellarg($container) . ' 2>&1'); // limpa container morto/antigo
        @mkdir($ws . '/.maestro', 0777, true);

        // Script do build: marca building, roda prebuild + assembleRelease, ASSINA com uma keystore
        // própria (gerada 1x por projeto, persistida) via apksigner, copia para delivery/ e marca status.
        // O resultado é um APK RELEASE ASSINADO (distribuível/sideload), não o debug.
        $script = 'mkdir -p /workspace/.maestro /workspace/delivery; '
            . 'echo building > /workspace/.maestro/apk.status; '
            . 'export HOME=/tmp/h CI=1; mkdir -p /tmp/h; '
            . 'if ( set -e; cd /workspace; '
            . 'npx --yes expo prebuild --platform android --no-install; '
            . 'KS=/workspace/.maestro/release.keystore; PWF=/workspace/.maestro/keystore.pass; '
            . 'if [ ! -f "$KS" ]; then '
            .   'PW=$(head -c 24 /dev/urandom | base64 | tr -dc "A-Za-z0-9" | head -c 24); echo "$PW" > "$PWF"; '
            .   'keytool -genkeypair -v -keystore "$KS" -alias ravi -keyalg RSA -keysize 2048 -validity 10000 '
            .     '-storepass "$PW" -keypass "$PW" -dname "CN=Ravi Developer, OU=Mobile, O=Ravi, L=NA, ST=NA, C=BR"; '
            . 'fi; '
            . 'PW=$(cat "$PWF"); '
            // Compila só arm64-v8a (cobre celulares modernos) e dá mais memória ao JVM do Gradle —
            // o release com código nativo p/ 4 ABIs estoura a RAM (daemon morre por Metaspace).
            . 'printf "\norg.gradle.jvmargs=-Xmx6144m -XX:MaxMetaspaceSize=2048m\nreactNativeArchitectures=arm64-v8a\n" >> android/gradle.properties; '
            . 'cd android; ./gradlew assembleRelease --no-daemon; cd ..; '
            . 'IN=$(ls android/app/build/outputs/apk/release/*.apk | head -1); '
            . 'APKSIGNER=$(ls "$ANDROID_HOME"/build-tools/*/apksigner | sort -V | tail -1); '
            . '"$APKSIGNER" sign --ks "$KS" --ks-key-alias ravi --ks-pass pass:"$PW" --key-pass pass:"$PW" '
            .   '--out /workspace/delivery/app-release.apk "$IN" ); '
            . 'then echo ready > /workspace/.maestro/apk.status; '
            . 'else echo error > /workspace/.maestro/apk.status; fi';

        $cmd = 'docker run -d --rm'
            . ' --name ' . escapeshellarg($container)
            . ' --memory=' . escapeshellarg($this->memory)
            . ' --cpus=' . escapeshellarg($this->cpu)
            . ' --user sandbox'
            . ' -v ' . escapeshellarg($ws . ':/workspace:rw')
            . ' -w /workspace'
            . ' ' . escapeshellarg($this->imageMobile)
            . ' bash -lc ' . escapeshellarg($script);

        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            return ['ok' => false, 'error' => 'Não foi possível iniciar o build: ' . implode(' ', array_slice($out, -2))];
        }
        return ['ok' => true, 'status' => 'building'];
    }
}
