<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;
use Ravi\Models\Project;
use Ravi\Models\User;

/**
 * Monitoramento do servidor (somente admin): métricas do host (CPU, RAM, disco, tráfego de rede)
 * e dos containers Docker em execução (projeto, usuário, uptime, CPU, RAM, disco).
 *
 * Leituras instantâneas via /proc e cgroup v2 (driver systemd); CPU e rede usam uma janela curta
 * de amostragem (~200ms) na própria requisição — sem estado, sem daemon. O front faz polling a
 * cada 1s e atualiza a tela toda sem refresh.
 */
class MonitoringService
{
    private const SAMPLE_US = 200000; // 200ms
    private const CGROUP_BASE = '/sys/fs/cgroup/system.slice';

    /** Coleta tudo numa única passada (host + containers). */
    public function sample(): array
    {
        $ncpu  = max(1, $this->ncpu());
        $iface = $this->primaryIface();

        // ── Primeira amostra ─────────────────────────────────────────────
        $cpu1  = $this->readCpuTotals();
        $net1  = $this->readNet($iface);
        $ids   = $this->runningIds();
        $use1  = [];
        foreach ($ids as $id) {
            $use1[$id] = $this->readCgroupUsageUsec($id);
        }
        $t1 = microtime(true);

        usleep(self::SAMPLE_US);

        // ── Segunda amostra ──────────────────────────────────────────────
        $t2   = microtime(true);
        $cpu2 = $this->readCpuTotals();
        $net2 = $this->readNet($iface);
        $use2 = [];
        foreach ($ids as $id) {
            $use2[$id] = $this->readCgroupUsageUsec($id);
        }
        $dt = max(0.05, $t2 - $t1);

        // ── Host CPU ─────────────────────────────────────────────────────
        $cpuPercent = 0.0;
        if ($cpu1 && $cpu2) {
            $dTotal = $cpu2['total'] - $cpu1['total'];
            $dIdle  = $cpu2['idle'] - $cpu1['idle'];
            if ($dTotal > 0) {
                $cpuPercent = max(0.0, min(100.0, (1 - $dIdle / $dTotal) * 100));
            }
        }

        // ── Rede (bytes/s) ───────────────────────────────────────────────
        $rxBps = $txBps = 0.0;
        if ($net1 && $net2) {
            $rxBps = max(0.0, ($net2['rx'] - $net1['rx']) / $dt);
            $txBps = max(0.0, ($net2['tx'] - $net1['tx']) / $dt);
        }

        $mem  = $this->readMem();
        $disk = $this->readDisk('/');

        // ── Containers ───────────────────────────────────────────────────
        $meta = $this->inspectMeta($ids);
        $map  = $this->buildMapping();
        $containers = [];
        foreach ($ids as $id) {
            $m    = $meta[$id] ?? ['name' => $id, 'started' => null, 'rw' => 0, 'rootfs' => 0, 'status' => 'running'];
            $name = $m['name'];

            // CPU% do container relativo à máquina toda (0–100).
            $cpuC = 0.0;
            if (isset($use1[$id], $use2[$id]) && $use1[$id] >= 0 && $use2[$id] >= 0) {
                $dUse = $use2[$id] - $use1[$id]; // microssegundos de CPU
                $wall = $dt * 1_000_000 * $ncpu; // microssegundos de CPU disponíveis na janela
                if ($wall > 0) {
                    $cpuC = max(0.0, min(100.0, $dUse / $wall * 100));
                }
            }

            $memCur = $this->readCgroupMemory($id);
            $memMax = $this->readCgroupMemoryMax($id);
            $memLimit = ($memMax > 0) ? $memMax : (int) $mem['total'];
            $memPct = $memLimit > 0 ? min(100.0, $memCur / $memLimit * 100) : 0.0;

            $info = $this->resolve($name, $map);
            $containers[] = [
                'id'            => substr($id, 0, 12),
                'name'          => $name,
                'type'          => $info['type'],
                // Containers essenciais (Infra, ex.: banco do preview) não podem ser mortos pela tela.
                'killable'      => $this->isKillable($name, $info['type']),
                'project'       => $info['project'],
                'user'          => $info['user'],
                'state'         => (string) ($m['status'] ?? 'running'), // 'paused' = IDE em standby
                'uptime_seconds' => $m['started'] ? max(0, time() - $m['started']) : 0,
                'cpu_percent'   => round($cpuC, 1),
                'mem_bytes'     => $memCur,
                'mem_limit_bytes' => ($memMax > 0) ? $memMax : 0,
                'mem_percent'   => round($memPct, 1),
                'disk_rw_bytes' => (int) $m['rw'],
                'disk_total_bytes' => (int) $m['rootfs'],
            ];
        }

        // Ordem ESTÁVEL (tipo, projeto, nome): a lista não pode se reordenar entre as
        // amostras de 1s, senão o admin erra o clique ao encerrar/reiniciar um container.
        // "Outro" por último: são os efêmeros (nascem/morrem toda hora) e não podem
        // empurrar as linhas dos containers duradouros.
        $rank = ['IDE' => 0, 'Preview' => 1, 'Dev autônomo' => 2, 'Terminal' => 3, 'Infra' => 4, 'Outro' => 5];
        usort($containers, fn ($a, $b) =>
            [$rank[$a['type']] ?? 5, $a['project'], $a['name']] <=> [$rank[$b['type']] ?? 5, $b['project'], $b['name']]);

        return [
            'host' => [
                'cpu_percent'  => round($cpuPercent, 1),
                'ncpu'         => $ncpu,
                'ram_used'     => (int) $mem['used'],
                'ram_total'    => (int) $mem['total'],
                'ram_percent'  => $mem['total'] > 0 ? round($mem['used'] / $mem['total'] * 100, 1) : 0,
                'disk_used'    => (int) $disk['used'],
                'disk_total'   => (int) $disk['total'],
                'disk_percent' => $disk['total'] > 0 ? round($disk['used'] / $disk['total'] * 100, 1) : 0,
                'net_iface'    => $iface,
                'net_rx_bps'   => round($rxBps),
                'net_tx_bps'   => round($txBps),
                'uptime_seconds' => $this->hostUptime(),
                'load'         => $this->loadAvg(),
            ],
            'containers' => $containers,
            'count'      => count($containers),
            'ts'         => time(),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Host
    // ──────────────────────────────────────────────────────────────────────

    /** Soma total e idle de /proc/stat (linha "cpu"). */
    private function readCpuTotals(): ?array
    {
        $line = @file('/proc/stat')[0] ?? '';
        if (strncmp($line, 'cpu ', 4) !== 0) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($line));
        array_shift($parts); // "cpu"
        $vals = array_map('floatval', $parts);
        $total = array_sum($vals);
        $idle  = ($vals[3] ?? 0) + ($vals[4] ?? 0); // idle + iowait
        return ['total' => $total, 'idle' => $idle];
    }

    /** rx/tx bytes de uma interface a partir de /proc/net/dev. */
    private function readNet(string $iface): ?array
    {
        foreach (@file('/proc/net/dev') ?: [] as $line) {
            if (strpos($line, $iface . ':') === false) {
                continue;
            }
            $after = trim(substr($line, strpos($line, ':') + 1));
            $f = preg_split('/\s+/', $after);
            return ['rx' => (float) ($f[0] ?? 0), 'tx' => (float) ($f[8] ?? 0)];
        }
        return null;
    }

    private function readMem(): array
    {
        $info = [];
        foreach (@file('/proc/meminfo') ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
                $info[$m[1]] = (int) $m[2] * 1024;
            }
        }
        $total = $info['MemTotal'] ?? 0;
        $avail = $info['MemAvailable'] ?? ($info['MemFree'] ?? 0);
        return ['total' => $total, 'used' => max(0, $total - $avail)];
    }

    private function readDisk(string $path): array
    {
        $total = @disk_total_space($path) ?: 0;
        $free  = @disk_free_space($path) ?: 0;
        return ['total' => (int) $total, 'used' => (int) max(0, $total - $free)];
    }

    private function hostUptime(): int
    {
        $u = @file_get_contents('/proc/uptime');
        return $u ? (int) floatval(explode(' ', trim($u))[0]) : 0;
    }

    private function loadAvg(): array
    {
        $l = @sys_getloadavg();
        return $l ? array_map(fn ($x) => round($x, 2), $l) : [0, 0, 0];
    }

    private function ncpu(): int
    {
        $n = @substr_count((string) @file_get_contents('/proc/cpuinfo'), "processor\t");
        if ($n > 0) {
            return $n;
        }
        $n = (int) @shell_exec('nproc 2>/dev/null');
        return $n > 0 ? $n : 1;
    }

    private function primaryIface(): string
    {
        $r = trim((string) @shell_exec("ip route get 8.8.8.8 2>/dev/null | sed -n 's/.* dev \\([^ ]*\\).*/\\1/p' | head -1"));
        return $r !== '' ? $r : 'eth0';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Containers (cgroup v2 + docker)
    // ──────────────────────────────────────────────────────────────────────

    /** @return string[] IDs completos dos containers em execução. */
    private function runningIds(): array
    {
        $out = [];
        @exec('docker ps -q --no-trunc 2>/dev/null', $out);
        return array_values(array_filter(array_map('trim', $out)));
    }

    private function cgroupPath(string $id): string
    {
        return self::CGROUP_BASE . '/docker-' . $id . '.scope';
    }

    /** usage_usec de cpu.stat (microssegundos de CPU acumulados). -1 se indisponível. */
    private function readCgroupUsageUsec(string $id): int
    {
        $raw = @file_get_contents($this->cgroupPath($id) . '/cpu.stat');
        if ($raw === false) {
            return -1;
        }
        if (preg_match('/usage_usec\s+(\d+)/', $raw, $m)) {
            return (int) $m[1];
        }
        return -1;
    }

    private function readCgroupMemory(string $id): int
    {
        $raw = @file_get_contents($this->cgroupPath($id) . '/memory.current');
        return $raw !== false ? (int) trim($raw) : 0;
    }

    /** memory.max em bytes; 0 se "max" (sem limite). */
    private function readCgroupMemoryMax(string $id): int
    {
        $raw = @file_get_contents($this->cgroupPath($id) . '/memory.max');
        if ($raw === false) {
            return 0;
        }
        $raw = trim($raw);
        return ($raw === 'max' || $raw === '') ? 0 : (int) $raw;
    }

    /**
     * Metadados dos containers numa única chamada: nome, início (epoch), SizeRw, SizeRootFs.
     * @return array<string,array{name:string,started:?int,rw:int,rootfs:int}>
     */
    private function inspectMeta(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $fmt = '{{.Id}}|{{.Name}}|{{.State.StartedAt}}|{{.SizeRw}}|{{.SizeRootFs}}|{{.State.Status}}';
        $cmd = 'docker inspect --size --format ' . escapeshellarg($fmt) . ' '
             . implode(' ', array_map('escapeshellarg', $ids)) . ' 2>/dev/null';
        $out = [];
        @exec($cmd, $out);
        $meta = [];
        foreach ($out as $line) {
            $p = explode('|', $line);
            if (count($p) < 5) {
                continue;
            }
            $id = $p[0];
            $started = strtotime($p[2]);
            $meta[$id] = [
                'name'    => ltrim($p[1], '/'),
                'started' => $started !== false ? $started : null,
                'rw'      => (int) $p[3],
                'rootfs'  => (int) $p[4],
                'status'  => $p[5] ?? 'running', // running | paused (IDE em standby) | ...
            ];
        }
        return $meta;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Mapeamento container → tipo / projeto / usuário
    // ──────────────────────────────────────────────────────────────────────

    private function buildMapping(): array
    {
        $db = Database::getInstance();
        $ide = [];   // container => [project_id, user_id]
        $prev = [];  // container_name => project_uuid
        try {
            // Inclui as PAUSADAS (status='stopped' = em standby): o container ainda existe (docker
            // pause) e aparece no `docker ps`, então precisa do rótulo IDE/projeto/usuário também.
            foreach ($db->fetchAll("SELECT container, project_id, user_id FROM ide_sessions WHERE status IN ('running','stopped')") as $r) {
                $ide[(string) $r['container']] = ['project_id' => (int) $r['project_id'], 'user_id' => (int) ($r['user_id'] ?? 0)];
            }
        } catch (\Throwable $e) {}
        try {
            foreach ($db->fetchAll("SELECT container_name, project_uuid FROM previews WHERE status = 'running'") as $r) {
                $prev[(string) $r['container_name']] = (string) $r['project_uuid'];
            }
        } catch (\Throwable $e) {}
        return ['ide' => $ide, 'prev' => $prev, 'projCache' => [], 'userCache' => []];
    }

    /** @return array{type:string,project:string,user:string} */
    private function resolve(string $name, array &$map): array
    {
        $none = ['type' => 'Outro', 'project' => '—', 'user' => 'sistema'];

        if ($name === 'ravi-preview-db') {
            return ['type' => 'Infra', 'project' => 'Banco do preview', 'user' => 'sistema'];
        }
        if (isset($map['ide'][$name])) {
            $e = $map['ide'][$name];
            return ['type' => 'IDE', 'project' => $this->projName($e['project_id'], $map), 'user' => $this->userName($e['user_id'], $map)];
        }
        if (isset($map['prev'][$name])) {
            $proj = $this->projByUuid($map['prev'][$name], $map);
            return ['type' => 'Preview', 'project' => $proj['name'], 'user' => $proj['owner']];
        }
        if (strncmp($name, 'ravi-sandbox-', 13) === 0) {
            $proj = $this->projByUuid(substr($name, 13), $map);
            return ['type' => 'Dev autônomo', 'project' => $proj['name'], 'user' => $proj['owner']];
        }
        if (strncmp($name, 'ravi-term-', 10) === 0) {
            // ravi-term-<uuid8>-<rand> → tenta casar o projeto pelo prefixo do uuid.
            $prefix = explode('-', substr($name, 10))[0] ?? '';
            $proj = $prefix !== '' ? $this->projByUuidPrefix($prefix, $map) : ['name' => '—', 'owner' => 'sistema'];
            return ['type' => 'Terminal', 'project' => $proj['name'], 'user' => $proj['owner']];
        }
        return $none;
    }

    /** Containers essenciais que NÃO podem ser encerrados pela tela (ex.: banco do preview). */
    public static function isProtected(string $name): bool
    {
        return strncmp($name, 'ravi-preview-db', 15) === 0;
    }

    /** Pode ser morto pela tela? Tudo, menos infraestrutura essencial. */
    private function isKillable(string $name, string $type): bool
    {
        return !self::isProtected($name) && $type !== 'Infra';
    }

    private function projName(int $id, array &$map): string
    {
        if ($id <= 0) {
            return '—';
        }
        if (!isset($map['projCache']['id' . $id])) {
            $p = Project::find($id);
            $map['projCache']['id' . $id] = $p ? (string) $p->name : '—';
        }
        return $map['projCache']['id' . $id];
    }

    /** @return array{name:string,owner:string} */
    private function projByUuid(string $uuid, array &$map): array
    {
        $key = 'uuid' . $uuid;
        if (!isset($map['projCache'][$key])) {
            $p = Project::findByUuid($uuid);
            $map['projCache'][$key] = $p
                ? ['name' => (string) $p->name, 'owner' => $this->userName((int) $p->user_id, $map)]
                : ['name' => '—', 'owner' => 'sistema'];
        }
        return $map['projCache'][$key];
    }

    /** @return array{name:string,owner:string} */
    private function projByUuidPrefix(string $prefix, array &$map): array
    {
        $key = 'pfx' . $prefix;
        if (!isset($map['projCache'][$key])) {
            try {
                $row = Database::getInstance()->fetchAll(
                    "SELECT id, name, user_id FROM projects WHERE uuid LIKE ? LIMIT 1",
                    [$prefix . '%']
                );
                $map['projCache'][$key] = !empty($row)
                    ? ['name' => (string) $row[0]['name'], 'owner' => $this->userName((int) $row[0]['user_id'], $map)]
                    : ['name' => '—', 'owner' => 'sistema'];
            } catch (\Throwable $e) {
                $map['projCache'][$key] = ['name' => '—', 'owner' => 'sistema'];
            }
        }
        return $map['projCache'][$key];
    }

    private function userName(int $id, array &$map): string
    {
        if ($id <= 0) {
            return '—';
        }
        if (!isset($map['userCache'][$id])) {
            $u = User::find($id);
            $map['userCache'][$id] = $u ? (string) ($u->name ?: $u->email) : ('Usuário #' . $id);
        }
        return $map['userCache'][$id];
    }
}
