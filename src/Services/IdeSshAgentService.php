<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Models\Project;

/**
 * Agente SSH por container de IDE — a CHAVE PRIVADA nunca entra no container.
 *
 * Antes, o entrypoint materializava as chaves privadas decifradas em ~/.ravi/keys/<id> dentro do
 * container (qualquer código lá dentro lia a chave de ROOT dos servidores). Agora o host roda um
 * `ssh-agent` por container, carrega nele as chaves dos projetos a que o ATOR tem direito (este
 * projeto + ligados em escrita) e monta SÓ O SOCKET no container (/home/sandbox/.ravi-ssh/agent.sock).
 *
 * O ssh/sftp de dentro do container usa o agente para ASSINAR (SSH_AUTH_SOCK) — consegue USAR a
 * chave enquanto o container vive, mas nunca LÊ-LA. Remover o container/compartilhamento mata o
 * acesso. Servidores com auth por SENHA seguem como antes (a senha ainda vai no servers.json).
 *
 * Ciclo de vida: criado no start() da IDE (antes do docker run, para o mount existir), conferido no
 * wake/reuso, morto no stop(). O reconcile() (scheduler, 1/min) ressuscita agentes que morreram
 * (ex.: restart do php-fpm/reboot) para as sessões que continuam rodando.
 */
class IdeSshAgentService
{
    /** Onde o socket aparece DENTRO do container (o ravi-ssh e o sftp.json apontam para cá). */
    public const CONTAINER_DIR  = '/home/sandbox/.ravi-ssh';
    public const CONTAINER_SOCK = self::CONTAINER_DIR . '/agent.sock';

    private string $base;

    public function __construct()
    {
        $this->base = rtrim((string) env('IDE_SSH_AGENT_DIR', '/var/lib/ravi/ide-ssh'), '/');
    }

    /** Diretório no HOST montado no container (contém o agent.sock). */
    public function hostDir(string $container): string
    {
        return $this->base . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $container);
    }

    private function sock(string $container): string
    {
        return $this->hostDir($container) . '/agent.sock';
    }

    private function pidFile(string $container): string
    {
        return $this->hostDir($container) . '/agent.pid';
    }

    /**
     * Garante o diretório + agente vivo + chaves carregadas para este container/ator.
     * Sempre devolve o diretório do host a montar (existe mesmo sem servidores key-auth —
     * o mount precisa existir; o agente só sobe quando há chave para servir).
     * @param bool $reloadKeys true = recarrega as chaves mesmo com o agente vivo (start/refresh);
     *                         false = só ressuscita se morreu (reconcile barato de 1/min).
     */
    public function ensure(string $container, Project $project, int $actorUserId, bool $reloadKeys = true): string
    {
        $dir = $this->hostDir($container);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $keys = $this->privateKeys($project, $actorUserId);
        if (!$keys) {
            $this->kill($container, false); // sem servidores key-auth → sem agente (dir fica p/ o mount)
            return $dir;
        }

        $alive = $this->alive($container);
        if ($alive && !$reloadKeys) {
            return $dir;
        }
        if (!$alive && !$this->spawn($container)) {
            logger()->error('IdeSshAgentService: falha ao subir ssh-agent para ' . $container);
            return $dir;
        }

        // Recarrega o conjunto inteiro (idempotente; reflete inclusão/remoção de servidor/ligação).
        $this->run(['ssh-add', '-D'], $container);
        foreach ($keys as $priv) {
            $this->addKey($container, $priv);
        }
        return $dir;
    }

    /** Mata o agente do container (e opcionalmente remove o diretório). Chamado no stop/recreate. */
    public function kill(string $container, bool $removeDir = true): void
    {
        $pf = $this->pidFile($container);
        $pid = is_file($pf) ? (int) trim((string) @file_get_contents($pf)) : 0;
        if ($pid > 1) {
            @exec('kill ' . $pid . ' 2>/dev/null');
        }
        // Cinto e suspensório: mata pelo caminho do socket (sobrevive a pidfile perdido).
        @exec('pkill -f ' . escapeshellarg('ssh-agent -a ' . $this->sock($container)) . ' 2>/dev/null');
        @unlink($this->sock($container));
        @unlink($pf);
        if ($removeDir) {
            @exec('rm -rf ' . escapeshellarg($this->hostDir($container)) . ' 2>/dev/null');
        }
    }

    /**
     * Ressuscita agentes de sessões RODANDO cujo agente morreu (reboot/restart do serviço) e
     * mata agentes órfãos (diretório sem sessão viva). Barato: 1 `ssh-add -l` por sessão.
     * @return array{revived:int, orphans:int}
     */
    public function reconcile(): array
    {
        $db = \Ravi\Core\Database::getInstance();
        $revived = 0;
        $valid = [];
        foreach ($db->fetchAll(
            "SELECT i.container, i.user_id, i.project_id FROM ide_sessions i WHERE i.status = 'running'"
        ) as $r) {
            $container = (string) $r['container'];
            $valid[$this->hostDir($container)] = true;
            try {
                $project = Project::find((int) $r['project_id']);
                if (!$project) {
                    continue;
                }
                $keys = $this->privateKeys($project, (int) $r['user_id']);
                if (!$keys) {
                    // Perdeu o direito (context_access/servidor removido) → agente não pode ficar.
                    $this->kill($container, false);
                    continue;
                }
                if (!$this->alive($container)) {
                    $this->ensure($container, $project, (int) $r['user_id'], true);
                    $revived++;
                }
            } catch (\Throwable $e) { /* best-effort por sessão */ }
        }
        $orphans = 0;
        foreach ((array) @glob($this->base . '/*', GLOB_ONLYDIR) as $dir) {
            if (isset($valid[$dir])) {
                continue;
            }
            $container = basename((string) $dir);
            // Só é órfão de verdade quando o CONTAINER NÃO EXISTE MAIS. Um container em STANDBY
            // (docker pause → status='stopped') ou apenas parado AINDA tem este diretório
            // bind-montado em /home/sandbox/.ravi-ssh. Fazer `rm -rf` aqui corrompe o mount (o
            // container fica preso ao inode deletado) e o SSH morre dentro dele até RECRIAR o
            // container — era exatamente o "para de funcionar e só reiniciar a IDE resolve".
            // Enquanto o container existir, preservamos o diretório; o resume (wake→ensure)
            // respawna o agente no MESMO mount. Removemos só quando o container sumiu de vez.
            if ($this->containerExists($container)) {
                continue;
            }
            $this->kill($container, true);
            $orphans++;
        }
        return ['revived' => $revived, 'orphans' => $orphans];
    }

    // ── internos ───────────────────────────────────────────────────────────────────────────────

    /** Chaves privadas (decifradas, só em memória) que o ATOR pode usar neste projeto. @return string[] */
    private function privateKeys(Project $project, int $actorUserId): array
    {
        $out = [];
        foreach ((new ProjectContextService())->sshKeyProjects($project, $actorUserId) as $p) {
            $p->ensureSshKey();
            $priv = (string) ($p->getSshPrivateKey() ?? '');
            if ($priv !== '') {
                $out[] = $priv;
            }
        }
        return $out;
    }

    /** O container ainda existe no Docker (rodando, pausado ou parado)? Só some quando removido. */
    private function containerExists(string $container): bool
    {
        $out = [];
        $rc = 0;
        @exec('docker inspect -f "{{.Id}}" ' . escapeshellarg($container) . ' 2>/dev/null', $out, $rc);
        return $rc === 0;
    }

    /** O agente deste container está de pé? (0=com chaves, 1=vivo sem chaves, 2=morto) */
    private function alive(string $container): bool
    {
        [$rc] = $this->run(['ssh-add', '-l'], $container);
        return $rc === 0 || $rc === 1;
    }

    private function spawn(string $container): bool
    {
        $sock = $this->sock($container);
        @unlink($sock); // socket velho de um agente morto impede o bind
        $out = [];
        $rc = 0;
        @exec('ssh-agent -a ' . escapeshellarg($sock) . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            return false;
        }
        foreach ($out as $line) {
            if (preg_match('/SSH_AGENT_PID=(\d+)/', (string) $line, $m)) {
                @file_put_contents($this->pidFile($container), $m[1]);
                break;
            }
        }
        // O container roda como root (bypass de DAC), mas deixamos o socket legível só pelo dono.
        @chmod($sock, 0600);
        return is_file($sock) || file_exists($sock);
    }

    private function addKey(string $container, string $privateKey): bool
    {
        $key = rtrim($privateKey) . "\n";
        [$rc] = $this->run(['ssh-add', '-'], $container, $key);
        return $rc === 0;
    }

    /** Roda um comando com SSH_AUTH_SOCK do container; segredo só via stdin. @return array{0:int,1:string} */
    private function run(array $cmd, string $container, ?string $stdin = null): array
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [
            'PATH'          => (string) (getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
            'SSH_AUTH_SOCK' => $this->sock($container),
        ];
        $proc = @proc_open($cmd, $spec, $pipes, null, $env);
        if (!is_resource($proc)) {
            return [255, ''];
        }
        if ($stdin !== null) {
            @fwrite($pipes[0], $stdin);
        }
        @fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $rc = (int) proc_close($proc);
        return [$rc, $out . $err];
    }
}
