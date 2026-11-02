<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Models\Project;
use Ravi\Models\ProjectSshSource;

/**
 * Deploy automático (rsync) do workspace para um servidor externo (SSH) a cada entrega, e teste de
 * conexão. Roda no HOST. Suporta auth por SENHA (SSHPASS) ou por CHAVE (arquivo temporário no host,
 * chmod 600, com `-i ... -o IdentitiesOnly=yes` → usa SOMENTE essa chave; apagado após o uso).
 * Segredos nunca vão em argv.
 */
class ServerDeployService
{
    private string $workspaceBase;

    public function __construct()
    {
        $cfg = config('sandbox');
        $this->workspaceBase = $cfg['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
    }

    /** @return array{ok:bool, error?:string, output?:string, server?:string} */
    public function deploy(Project $project, int $serverId): array
    {
        $s = ProjectSshSource::find($serverId);
        if (!$s || (int) $s->project_id !== (int) $project->id) {
            return ['ok' => false, 'error' => 'servidor não encontrado'];
        }
        if ((int) $s->enabled !== 1) {
            return ['ok' => false, 'error' => 'servidor inativo'];
        }
        $projKey = '';
        if ($s->isKeyAuth() && $project->ensureSshKey()) {
            $projKey = (string) ($project->getSshPrivateKey() ?? '');
        }
        $cfg = $this->cfgFromSource($s, $projKey);
        $miss = $this->missingCredential($cfg);
        if ($miss !== null) {
            return ['ok' => false, 'error' => $miss, 'server' => (string) $s->name];
        }
        $ws = rtrim($this->workspaceBase, '/') . '/' . $project->uuid;
        if (!is_dir($ws)) {
            return ['ok' => false, 'error' => 'workspace inexistente', 'server' => (string) $s->name];
        }
        $dir = (string) $s->remote_dir !== '' ? (string) $s->remote_dir : '.';

        $excludes = ['.git', '.maestro', '/PLAN.md', '/PROGRESS.md', 'vendor', 'node_modules', '.env',
                     '.expo', 'dist', '*.sqlite', '*.sqlite3', '.DS_Store', '__pycache__', '.vscode/sftp.json', '.ravi',
                     // Diretórios VIVOS do servidor de produção: jamais sobrescrever com cópia do workspace.
                     // 'ram' costuma ser ponto de montagem (ramdisk) e 'bkpRavi' guarda backups.
                     '/ram', '/bkpRavi'];

        // FTP: upload via PHP (sem rsync/ssh). Mesmos excludes.
        if (($cfg['protocol'] ?? 'ssh') === 'ftp') {
            $res = $this->deployFtp($cfg, $ws, $excludes);
            $res['server'] = (string) $s->name;
            return $res;
        }

        $exFlags = '';
        foreach ($excludes as $e) {
            $exFlags .= ' --exclude ' . escapeshellarg($e);
        }
        $remote = escapeshellarg((string) $s->username . '@' . (string) $s->host)
            . ':' . escapeshellarg(rtrim($dir, '/') . '/');

        $t = $this->buildSsh($cfg);
        // SEM --delete: o deploy é ADITIVO (cria/atualiza os arquivos da entrega, NUNCA apaga nada
        // no destino). O alvo é um servidor de produção que tem arquivos de runtime que não existem
        // no workspace (logs, uploads, pontos de montagem como 'ram', backups como 'bkpRavi'); um
        // espelho com --delete os removeria. Remoção em produção jamais pode ser automática.
        $cmd = $t['prefix'] . 'rsync -az --mkpath' . $exFlags
            . ' -e ' . escapeshellarg('ssh ' . $t['sshOpts'])
            . ' ' . escapeshellarg(rtrim($ws, '/') . '/') . ' ' . $remote . ' 2>&1';

        $out = [];
        $rc = 0;
        @exec($cmd, $out, $rc);

        // Fallback: o servidor remoto pode não ter rsync (rsync exige o binário nas DUAS pontas).
        // Nesse caso caímos para um pipe tar-sobre-ssh, que só precisa de `tar` no destino (quase
        // universal). Sem --delete, mas a entrega chega. Só recorremos a isto quando a falha é
        // claramente "rsync ausente no remoto", para não mascarar erros reais (auth, host fora).
        if ($rc !== 0 && $this->remoteRsyncMissing($out, $rc)) {
            $res = $this->deployViaTar($ws, $excludes, (string) $s->username . '@' . (string) $s->host, $dir, $t);
            ($t['cleanup'])();
            $res['server'] = (string) $s->name;
            return $res;
        }
        ($t['cleanup'])();

        if ($rc !== 0) {
            return ['ok' => false, 'error' => 'rsync falhou (rc ' . $rc . ')',
                    'output' => implode("\n", array_slice($out, -8)), 'server' => (string) $s->name];
        }
        return ['ok' => true, 'output' => implode("\n", array_slice($out, -5)), 'server' => (string) $s->name];
    }

    /** A falha do rsync indica ausência do binário no servidor remoto? */
    private function remoteRsyncMissing(array $out, int $rc): bool
    {
        $joined = strtolower(implode("\n", $out));
        if (strpos($joined, 'command not found') !== false || strpos($joined, 'rsync: not found') !== false) {
            return true;
        }
        // rsync sai 12 (erro de protocolo) quando o lado remoto fecha a conexão por não achar o binário.
        return $rc === 127 || ($rc === 12 && strpos($joined, 'not found') !== false);
    }

    /**
     * Deploy alternativo via `tar | ssh 'tar -x'`. Só exige `tar` no destino. Mantém os mesmos
     * excludes (o '/' inicial é removido pois os membros do arquivo são prefixados com './').
     * @param array{sshOpts:string, prefix:string, cleanup:callable} $t
     * @return array{ok:bool, error?:string, output?:string}
     */
    private function deployViaTar(string $ws, array $excludes, string $userHost, string $dir, array $t): array
    {
        $exFlags = '';
        foreach ($excludes as $e) {
            $exFlags .= ' --exclude=' . escapeshellarg(ltrim($e, '/'));
        }
        $remoteDir = rtrim($dir, '/');
        if ($remoteDir === '') {
            $remoteDir = '.';
        }
        $remoteCmd = 'mkdir -p ' . escapeshellarg($remoteDir) . ' && tar -xzf - -C ' . escapeshellarg($remoteDir);
        // O `tar` local NÃO leva o sshpass — só o `ssh` (que faz a autenticação) recebe o prefixo.
        $cmd = 'tar -czf -' . $exFlags
            . ' -C ' . escapeshellarg(rtrim($ws, '/')) . ' .'
            . ' | ' . $t['prefix'] . 'ssh ' . $t['sshOpts']
            . ' ' . escapeshellarg($userHost) . ' ' . escapeshellarg($remoteCmd) . ' 2>&1';

        $out = [];
        $rc = 0;
        @exec($cmd, $out, $rc);
        if ($rc !== 0) {
            return ['ok' => false, 'error' => 'envio via tar falhou (rc ' . $rc . '; servidor sem rsync)',
                    'output' => implode("\n", array_slice($out, -8))];
        }
        return ['ok' => true, 'output' => 'enviado via tar (servidor sem rsync)'];
    }

    /**
     * Testa a conexão SSH (auth) e checa o diretório remoto. Roda no HOST.
     * @param array $cfg host, port, user, dir, auth_type, password, private_key, passphrase
     * @return array{ok:bool, message?:string, error?:string, ips?:list<string>}
     */
    public function testConnection(array $cfg): array
    {
        $host = trim((string) ($cfg['host'] ?? ''));
        $user = trim((string) ($cfg['user'] ?? ''));
        if ($host === '' || $user === '') {
            return ['ok' => false, 'error' => 'Preencha host e usuário.'];
        }
        $miss = $this->missingCredential($cfg);
        if ($miss !== null) {
            return ['ok' => false, 'error' => $miss];
        }
        if (($cfg['protocol'] ?? 'ssh') === 'ftp') {
            return $this->testFtp($cfg);
        }
        $dir = trim((string) ($cfg['dir'] ?? ''));
        $shq = fn (string $v) => "'" . str_replace("'", "'\\''", $v) . "'";
        // Coleta TODOS os IPs do servidor (mesma técnica de sistemas de monitoramento): assim descobrimos o IP
        // da VPN/WireGuard mesmo conectando pela LAN. Linha marcada RAVI_IPS=<ip,ip,...>.
        $ipCollect = 'ip a 2>/dev/null | grep \'inet \' | grep -v 127.0.0.1 | awk \'{print $2}\' | cut -f1 -d/ | tr \'\n\' \',\' | sed \'s/,$//\'';
        $remote = 'echo RAVI_OK; '
            . '( command -v rsync >/dev/null 2>&1 && echo RSYNC_OK || echo RSYNC_MISSING ); '
            . 'printf \'RAVI_IPS=%s\n\' "$(' . $ipCollect . ')"; '
            . ($dir !== ''
                ? '( [ -d ' . $shq($dir) . ' ] && echo DIR_OK || echo DIR_MISSING )'
                : 'echo DIR_SKIP');

        $t = $this->buildSsh($cfg);
        $cmd = $t['prefix'] . 'ssh ' . $t['sshOpts']
            . ' ' . escapeshellarg($user . '@' . $host)
            . ' ' . escapeshellarg($remote) . ' 2>&1';

        $out = [];
        $rc = 0;
        @exec($cmd, $out, $rc);
        ($t['cleanup'])();

        $joined = implode("\n", $out);
        if (strpos($joined, 'RAVI_OK') === false) {
            $why = trim(implode(' ', array_slice($out, -3)));
            return ['ok' => false, 'error' => 'Não conectou: ' . ($why !== '' ? $why : ('falha SSH (rc ' . $rc . ')'))];
        }
        $msg = 'Conexão OK';
        if (strpos($joined, 'DIR_MISSING') !== false) {
            $msg .= ' — o diretório ainda não existe (será criado no deploy).';
        } elseif (strpos($joined, 'DIR_OK') !== false) {
            $msg .= ' — diretório encontrado.';
        }
        if (strpos($joined, 'RSYNC_MISSING') !== false) {
            $msg .= ' (Servidor sem rsync — o deploy usará tar automaticamente, sem remoção de arquivos órfãos.)';
        }
        // Extrai os IPs auto-descobertos do servidor (RAVI_IPS=...), inclui o da VPN.
        $ips = [];
        if (preg_match('/^RAVI_IPS=(.*)$/m', $joined, $mm)) {
            foreach (explode(',', trim($mm[1])) as $ip) {
                $ip = trim($ip);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $ips, true)) {
                    $ips[] = $ip;
                }
            }
        }
        $res = ['ok' => true, 'message' => $msg];
        if ($ips) {
            $res['ips'] = $ips;
        }
        return $res;
    }

    /** Config (com segredos decifrados) de um servidor. Chave = a do PROJETO ($projectKey). */
    private function cfgFromSource(ProjectSshSource $s, string $projectKey): array
    {
        return [
            'protocol'    => $s->isFtp() ? 'ftp' : 'ssh',
            'host'        => (string) $s->host,
            'port'        => (int) $s->port,
            'user'        => (string) $s->username,
            'dir'         => (string) $s->remote_dir,
            'auth_type'   => $s->isKeyAuth() ? 'key' : 'password',
            'password'    => (string) ($s->getPassword() ?? ''),
            'private_key' => $projectKey,
            'passphrase'  => '',
        ];
    }

    /** Mensagem de erro se faltar a credencial necessária; null se ok. */
    private function missingCredential(array $cfg): ?string
    {
        if (($cfg['auth_type'] ?? 'password') === 'key') {
            return trim((string) ($cfg['private_key'] ?? '')) === '' ? 'Cole a chave privada (ou salve o servidor primeiro).' : null;
        }
        return (string) ($cfg['password'] ?? '') === '' ? 'Informe a senha (ou salve o servidor primeiro).' : null;
    }

    /**
     * Monta as opções do ssh + prefixo sshpass + cleanup, conforme o tipo de auth.
     * CHAVE: grava num arquivo temporário (600) e usa -i + IdentitiesOnly=yes (só essa chave).
     * SENHA: PubkeyAuthentication=no (não tenta chave nenhuma) + sshpass.
     * @return array{sshOpts:string, prefix:string, cleanup:callable}
     */
    private function buildSsh(array $cfg): array
    {
        $port = (int) ($cfg['port'] ?? 22);
        $opts = '-p ' . $port
            . ' -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR';
        $prefix = '';
        $keyfile = null;
        $secret = null;

        if (($cfg['auth_type'] ?? 'password') === 'key') {
            $key = rtrim((string) ($cfg['private_key'] ?? ''), "\n") . "\n";
            $keyfile = tempnam(sys_get_temp_dir(), 'ravikey');
            @file_put_contents($keyfile, $key);
            @chmod($keyfile, 0600);
            $opts .= ' -i ' . escapeshellarg((string) $keyfile) . ' -o IdentitiesOnly=yes';
            $pp = (string) ($cfg['passphrase'] ?? '');
            if ($pp !== '') {
                $prefix = 'sshpass -P passphrase -e ';
                $secret = $pp;
            } else {
                $opts .= ' -o BatchMode=yes';
            }
        } else {
            $opts .= ' -o PubkeyAuthentication=no -o PreferredAuthentications=password';
            $prefix = 'sshpass -e ';
            $secret = (string) ($cfg['password'] ?? '');
        }

        if ($secret !== null) {
            putenv('SSHPASS=' . $secret);
        }
        $cleanup = function () use ($keyfile) {
            putenv('SSHPASS');
            if ($keyfile !== null) {
                @unlink($keyfile);
            }
        };
        return ['sshOpts' => $opts, 'prefix' => $prefix, 'cleanup' => $cleanup];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FTP (#14) — para hospedagens que só oferecem FTP. Sempre por senha, sem rsync/ssh,
    // sem acesso interativo do Claude (apenas upload das entregas). Usa a extensão ftp do PHP.
    // ──────────────────────────────────────────────────────────────────────────

    /** Conecta e autentica no FTP (modo passivo). @return array{ok:bool, conn?:mixed, error?:string} */
    private function ftpConnect(array $cfg): array
    {
        $host = trim((string) ($cfg['host'] ?? ''));
        $port = (int) ($cfg['port'] ?? 21) ?: 21;
        $user = trim((string) ($cfg['user'] ?? ''));
        $pass = (string) ($cfg['password'] ?? '');
        if (!function_exists('ftp_connect')) {
            return ['ok' => false, 'error' => 'Suporte a FTP indisponível no servidor.'];
        }
        $conn = @ftp_connect($host, $port, 15);
        if ($conn === false) {
            return ['ok' => false, 'error' => 'Não conectou ao FTP ' . $host . ':' . $port . '.'];
        }
        if (!@ftp_login($conn, $user, $pass)) {
            @ftp_close($conn);
            return ['ok' => false, 'error' => 'Login FTP falhou (usuário ou senha incorretos).'];
        }
        @ftp_pasv($conn, true);
        return ['ok' => true, 'conn' => $conn];
    }

    /** Testa a conexão FTP e o diretório remoto. */
    private function testFtp(array $cfg): array
    {
        $c = $this->ftpConnect($cfg);
        if (!$c['ok']) {
            return ['ok' => false, 'error' => $c['error']];
        }
        $conn = $c['conn'];
        $msg = 'Conexão FTP OK';
        $dir = trim((string) ($cfg['dir'] ?? ''));
        if ($dir !== '') {
            $msg .= @ftp_chdir($conn, $dir) ? ' — diretório encontrado.' : ' — o diretório ainda não existe (será criado no deploy).';
        }
        @ftp_close($conn);
        return ['ok' => true, 'message' => $msg];
    }

    /** Upload recursivo do workspace via FTP, honrando os mesmos excludes (poda diretórios). */
    private function deployFtp(array $cfg, string $ws, array $excludes): array
    {
        $c = $this->ftpConnect($cfg);
        if (!$c['ok']) {
            return ['ok' => false, 'error' => $c['error']];
        }
        $conn = $c['conn'];
        $base = trim((string) ($cfg['dir'] ?? ''));
        if ($base !== '') {
            $this->ftpEnsureDir($conn, rtrim($base, '/'));
        }
        $root = rtrim($ws, '/');
        $self = $this;
        $dirIt = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($dirIt, function ($current) use ($root, $excludes, $self) {
            $rel = ltrim(substr($current->getPathname(), strlen($root)), '/');
            return !$self->ftpIsExcluded($rel, $excludes);
        });
        $it = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
        $count = 0; $errors = 0;
        foreach ($it as $path => $info) {
            $rel = ltrim(substr((string) $path, strlen($root)), '/');
            if ($rel === '') {
                continue;
            }
            $remote = $base !== '' ? (rtrim($base, '/') . '/' . $rel) : $rel;
            if ($info->isDir()) {
                $this->ftpEnsureDir($conn, $remote);
            } elseif (@ftp_put($conn, $remote, (string) $path, FTP_BINARY)) {
                $count++;
            } else {
                $errors++;
            }
        }
        @ftp_close($conn);
        if ($count === 0 && $errors > 0) {
            return ['ok' => false, 'error' => 'Falha ao enviar arquivos via FTP (verifique permissões/diretório).'];
        }
        return ['ok' => true, 'output' => 'enviado via FTP: ' . $count . ' arquivo(s)' . ($errors ? (', ' . $errors . ' falha(s)') : '')];
    }

    /** Cria recursivamente um diretório remoto no FTP (nível a nível; ignora os que já existem). */
    private function ftpEnsureDir($conn, string $dir): void
    {
        $dir = rtrim(trim($dir), '/');
        if ($dir === '' || $dir === '.') {
            return;
        }
        $abs = strncmp($dir, '/', 1) === 0;
        $path = '';
        foreach (explode('/', ltrim($dir, '/')) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            $path = $path === '' ? ($abs ? '/' . $p : $p) : ($path . '/' . $p);
            @ftp_mkdir($conn, $path); // se já existe, falha silenciosa
        }
    }

    /** O caminho relativo casa com algum exclude? (versão simplificada dos padrões do rsync). public p/ closure. */
    public function ftpIsExcluded(string $rel, array $excludes): bool
    {
        if ($rel === '') {
            return false;
        }
        $name = basename($rel);
        foreach ($excludes as $e) {
            $e = ltrim((string) $e, '/');
            if ($e === '') {
                continue;
            }
            if (strpos($e, '*') !== false) {
                if (fnmatch($e, $name) || fnmatch($e, $rel)) {
                    return true;
                }
            } elseif ($name === $e || $rel === $e || strpos($rel . '/', $e . '/') === 0 || strpos('/' . $rel, '/' . $e . '/') !== false) {
                return true;
            }
        }
        return false;
    }
}
